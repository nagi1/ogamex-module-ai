//! Scripted human-like players on the world (plan/rl/WORLD-SIM-PLAN.md, W7): a miner (the economy's player rule plus
//! the odd cargo ship or probe), a raider (spy, raid, recycle) and a fleet saver (leaves before an attack lands).
//! Roles come from the host's tables: a ship is movable when its speed is above zero, a cargo ship is the movable
//! non-probe non-harvester with the most capacity per price, probes and harvesters are what the mission tables name.
//! No object is named; targets are the nearest accounts by coordinate.

use std::collections::{HashMap, HashSet};
use std::sync::Arc;

use crate::combat::{self, Battle};
use crate::economy::{Baseline, Catalogue, WEIGHTS};
use crate::fleet::{self, SystemState};
use crate::world::{Fleet, Mission, Rules, SpyReport, World};

const SLOTS: usize = 4;
const NEIGHBOURS: usize = 5;
const REPORT_HOURS: f64 = 24.0;
const RAID_COOLDOWN: f64 = 3.0 * 3600.0;
/// A target is worth the trip when the loot pays this many times its fuel.
const PAYS: f64 = 3.0;
/// A target whose guns do more than this share of our hull in three rounds is left alone.
const SAFE_SHARE: f64 = 0.3;

fn weighted(r: [f64; 3]) -> f64 {
    (0..3).map(|i| WEIGHTS[i] * r[i]).sum()
}

/// What the players did, for the table and the checks.
#[derive(Default)]
pub struct Ledger {
    pub raids: Vec<Raid>,
    /// Weighted deuterium paid at dispatch by every fleet.
    pub fuel: f64,
    pub recycled: [f64; 3],
    pub recycles: usize,
    pub spies: usize,
    pub saves_sent: usize,
    pub saves_refused: usize,
    seen: HashSet<(usize, usize, u64)>,
}

pub struct Raid {
    pub attacker: usize,
    pub target: usize,
    pub arrive: f64,
    /// Seen when the fleet turned home: what it brought back, fuel refund taken out.
    pub loot: Option<[f64; 3]>,
    /// The target held ships that can fly when the raid left.
    pub target_had_fleet: bool,
    /// The target's owner sent them away before the arrival.
    pub saved: bool,
}

impl Ledger {
    /// Every returning fleet seen for the first time is booked: loot of an attack, harvest of a recycler.
    fn scan(&mut self, w: &World) {
        for f in w.fleets.iter().filter(|f| f.returning) {
            if !self.seen.insert((f.owner, f.target, f.home.to_bits())) { continue }
            let brought = [f.cargo[0], f.cargo[1], f.cargo[2] - if f.mission == Mission::Attack { f.fuel / 2.0 } else { 0.0 }];
            match f.mission {
                Mission::Attack => {
                    if let Some(r) = self.raids.iter_mut().find(|r| r.attacker == f.owner && r.target == f.target && r.arrive == f.arrive) { r.loot = Some(brought) }
                }
                Mission::Recycle => {
                    for i in 0..3 { self.recycled[i] += brought[i] }
                }
                _ => {}
            }
        }
    }

    pub fn loot(&self) -> [f64; 3] {
        self.raids.iter().filter_map(|r| r.loot).fold([0.0; 3], |t, l| [t[0] + l[0], t[1] + l[1], t[2] + l[2]])
    }
}

/// Dispatches at the account's login, books the fuel and returns the arrival second.
fn send(w: &mut World, cat: &Catalogue, a: usize, target: usize, mission: Mission, ships: &[(u32, i64)], speed: f64, ledger: &mut Ledger) -> Result<f64, &'static str> {
    let before = w.games[a].planet.res[2];
    w.dispatch(cat, a, target, mission, ships, [0.0; 3], speed)?;
    ledger.fuel += WEIGHTS[2] * (before - w.games[a].planet.res[2]);
    Ok(w.fleets.last().map_or(0.0, |f| f.arrive))
}

fn movable(rules: &Rules, id: u32, research: &HashMap<u32, u32>) -> bool {
    !rules.combat.is_defense(id) && rules.fleet.speed(id, research) > 0
}

/// The ships the roles are made of, found in the tables.
#[derive(Clone, Copy)]
pub struct Kit {
    pub cargo: u32,
    pub probe: u32,
    pub harvester: u32,
    /// The cheapest movable ship that shoots, among those the account holds or can build now: the hull a raider starts with.
    pub fighter: u32,
}

impl Kit {
    pub fn of(w: &World, cat: &Catalogue, a: usize) -> Option<Kit> {
        let rules = &w.rules[a];
        let research = w.research(cat, a);
        let missions = &rules.combat.missions;
        let probe = *missions.espionage_units.first()?;
        let harvester = *missions.harvesters.values().next()?;
        let cargo = rules.units.units.iter()
            .map(|u| u.id as u32)
            .filter(|&id| id != probe && !missions.harvesters.values().any(|&h| h == id) && movable(rules, id, &research))
            .map(|id| (id, rules.combat.capacity(&[(id, 1)].into_iter().collect(), &research) as f64 / rules.units.units[rules.units.index(id as i64).unwrap()].price.iter().sum::<f64>().max(1.0)))
            .filter(|c| c.1 > 0.0)
            .max_by(|x, y| x.1.total_cmp(&y.1))?.0;
        // The cheapest shooter among ships the account holds or can build; failing those, among all movable ones.
        let best = |usable: &dyn Fn(usize, u32) -> bool| rules.units.units.iter().enumerate()
            .map(|(u, unit)| (u, unit.id as u32))
            .filter(|&(u, id)| id != probe && id != cargo && !missions.harvesters.values().any(|&h| h == id) && movable(rules, id, &research) && usable(u, id))
            .filter(|&(_, id)| strength(rules, &research, &[(id, 1)]).0 > 1.0)
            .min_by(|x, y| weighted(rules.units.units[x.0].price).total_cmp(&weighted(rules.units.units[y.0].price))).map(|c| c.1);
        let fighter = best(&|u, id| owned(w, a, id) > 0 || rules.units.max_amount(&w.yards[a], &w.games[a].planet, u) > 0).or_else(|| best(&|_, _| true))?;
        Some(Kit { cargo, probe, harvester, fighter })
    }
}

/// Ships of one kind the account owns: on the shelf, in the yard's queue, or flying.
fn owned(w: &World, a: usize, id: u32) -> i64 {
    let Some(u) = w.rules[a].units.index(id as i64) else { return 0 };
    let queued: u64 = w.yards[a].orders.iter().filter(|o| o.unit == u).map(|o| o.amount - o.done).sum();
    let flying: i64 = w.fleets.iter().filter(|f| f.owner == a).flat_map(|f| f.ships.iter()).filter(|s| s.0 == id).map(|s| s.1).sum();
    (w.yards[a].counts[u] + queued) as i64 + flying
}

fn on_shelf(w: &World, a: usize, id: u32) -> i64 {
    w.rules[a].units.index(id as i64).map_or(0, |u| w.yards[a].counts[u] as i64)
}

fn account_distance(w: &World, a: usize, b: usize) -> i64 {
    fleet::distance(&w.rules[a].settings, w.coords[a], w.coords[b], &|_, _| SystemState::Active)
}

/// The nearest other accounts by coordinate, closest first.
fn nearest(w: &World, a: usize, n: usize) -> Vec<usize> {
    let mut others: Vec<usize> = (0..w.games.len()).filter(|&b| b != a).collect();
    others.sort_by_key(|&b| (account_distance(w, a, b), b));
    others.truncate(n);
    others
}

/// Value in metal equivalent: the economy's value, ships on the shelf, in the queue and in flight at price, and what flies.
pub fn account_value(w: &World, a: usize) -> f64 {
    let rules = &w.rules[a];
    let price = |id: u32| rules.units.index(id as i64).map_or(0.0, |u| weighted(rules.units.units[u].price));
    let shelf: f64 = rules.units.units.iter().zip(&w.yards[a].counts).map(|(u, &n)| weighted(u.price) * n as f64).sum();
    let queued: f64 = w.yards[a].orders.iter().map(|o| weighted(rules.units.units[o.unit].price) * (o.amount - o.done) as f64).sum();
    let flying: f64 = w.fleets.iter().filter(|f| f.owner == a).map(|f| f.ships.iter().map(|s| price(s.0) * s.1 as f64).sum::<f64>() + weighted(f.cargo) + WEIGHTS[2] * f.fuel / 2.0).sum();
    w.games[a].value() + shelf + queued + flying
}

/// Attack and hull of a stack of units at its owner's research, as the host's engine input states them.
fn strength(rules: &Rules, research: &HashMap<u32, u32>, units: &[(u32, i64)]) -> (f64, f64) {
    let battle = Battle { attacker: units, attacker_research: research, attacker_cargo: [0.0; 3], defender: &[], defender_research: research, defender_stock: [0.0; 3], defender_engineer: false, moon_exists: false, seed: 0 };
    let input = combat::engine_input(&rules.combat, &rules.combat, &battle);
    let Some(stacks) = input["attacker_fleets"][0]["units"].as_object() else { return (0.0, 0.0) };
    stacks.values().fold((0.0, 0.0), |t, s| {
        let n = s["amount"].as_f64().unwrap_or(0.0);
        (t.0 + n * s["attack_power"].as_f64().unwrap_or(0.0), t.1 + n * s["hull_plating"].as_f64().unwrap_or(0.0))
    })
}

fn stack(rules: &Rules, counts: &[u64]) -> Vec<(u32, i64)> {
    rules.units.units.iter().zip(counts).filter(|(_, &n)| n > 0).map(|(u, &n)| (u.id as u32, n as i64)).collect()
}

/// Today's player rule, and the odd ship bought with stock that would otherwise sit idle or overflow.
pub struct Miner {
    rng: u64,
    /// Hold wanted, as a share of the stock's weight in cargo ships.
    cargo_share: f64,
    probes: i64,
    harvesters: i64,
    fighters: i64,
    /// Buys ships at every login it can pay for them, not only with an idle or overflowing store.
    eager: bool,
}

impl Miner {
    pub fn new(seed: u64) -> Miner {
        Miner { rng: seed, cargo_share: 0.1, probes: 1, harvesters: 0, fighters: 0, eager: false }
    }

    /// The economy's pick for the deciding queue (None lets the login pass), after any ship order.
    pub fn act(&mut self, w: &mut World, cat: &Catalogue, a: usize) -> Option<usize> {
        let (queue, planet) = (w.games[a].queue, &w.games[a].planet);
        let pick = cat.baseline(planet, queue, Baseline::Player, &mut self.rng);
        let overflow = (0..3).any(|r| planet.res[r] >= 0.8 * planet.cap[r]);
        if pick.is_none() || overflow || self.eager {
            self.order_ships(w, cat, a);
        }
        pick
    }

    fn order_ships(&self, w: &mut World, cat: &Catalogue, a: usize) {
        let Some(kit) = Kit::of(w, cat, a) else { return };
        let rules = Arc::clone(&w.rules[a]);
        let research = w.research(cat, a);
        let hold = rules.combat.capacity(&[(kit.cargo, 1)].into_iter().collect(), &research).max(1);
        let stock: f64 = w.games[a].planet.res.iter().sum();
        let wants = [(kit.probe, self.probes), (kit.cargo, (stock * self.cargo_share / hold as f64).ceil() as i64), (kit.harvester, self.harvesters), (kit.fighter, self.fighters)];
        for (id, want) in wants {
            let missing = want - owned(w, a, id);
            let Some(u) = rules.units.index(id as i64) else { continue };
            if missing > 0 { w.order_units(cat, a, u, missing as u64); }
        }
    }
}

/// Keeps probes, spies the nearest accounts, raids the ones whose loot pays for the trip and whose guns are small,
/// and sends recyclers to the debris.
pub struct Raider {
    pub miner: Miner,
    /// A twin world turns raiding off to show what the same accounts do in peace.
    pub hostile: bool,
    last: f64,
    spied: HashMap<usize, f64>,
    hit: HashMap<usize, f64>,
}

impl Raider {
    pub fn new(seed: u64) -> Raider {
        let miner = Miner { rng: seed, cargo_share: 0.2, probes: 4, harvesters: 3, fighters: 30, eager: true };
        Raider { miner, hostile: true, last: -1.0, spied: HashMap::new(), hit: HashMap::new() }
    }

    pub fn act(&mut self, w: &mut World, cat: &Catalogue, a: usize, ledger: &mut Ledger) -> Option<usize> {
        let pick = self.miner.act(w, cat, a);
        let now = w.games[a].t;
        if !self.hostile || now == self.last { return pick }
        self.last = now;
        let Some(kit) = Kit::of(w, cat, a) else { return pick };
        for target in nearest(w, a, NEIGHBOURS) {
            if w.fleets.iter().filter(|f| f.owner == a).count() >= SLOTS { break }
            self.recycle(w, cat, a, target, ledger);
            if self.raid(w, cat, a, target, kit, ledger) { continue }
            self.spy(w, cat, a, target, kit, ledger);
        }
        pick
    }

    fn last_report<'a>(w: &'a World, a: usize, target: usize) -> Option<&'a SpyReport> {
        w.reports.iter().rev().find(|r| r.owner == a && r.target == target)
    }

    fn spy(&mut self, w: &mut World, cat: &Catalogue, a: usize, target: usize, kit: Kit, ledger: &mut Ledger) {
        let now = w.games[a].t;
        let fresh = Self::last_report(w, a, target).is_some_and(|r| now - r.time < REPORT_HOURS * 3600.0);
        let recent = self.spied.get(&target).is_some_and(|&t| now - t < 2.0 * 3600.0);
        let probes = on_shelf(w, a, kit.probe).min(4);
        if fresh || recent || probes < 1 { return }
        if send(w, cat, a, target, Mission::Espionage, &[(kit.probe, probes)], 10.0, ledger).is_ok() {
            self.spied.insert(target, now);
            ledger.spies += 1;
        }
    }

    /// Sends fighters and cargo ships with an attack when the last full report says the loot pays for the fuel and the
    /// target cannot hurt.
    fn raid(&mut self, w: &mut World, cat: &Catalogue, a: usize, target: usize, kit: Kit, ledger: &mut Ledger) -> bool {
        let now = w.games[a].t;
        let Some(report) = Self::last_report(w, a, target) else { return false };
        if now - report.time > REPORT_HOURS * 3600.0 || self.hit.get(&target).is_some_and(|&t| now - t < RAID_COOLDOWN) { return false }
        let (Some(ships), Some(defence)) = (&report.ships, &report.defence) else { return false };
        let rules = Arc::clone(&w.rules[a]);
        let research = w.research(cat, a);
        let hold = rules.combat.capacity(&[(kit.cargo, 1)].into_iter().collect(), &research).max(1);
        let loot = report.resources.map(|r| r.max(0) as f64 * rules.combat.rules.loot_percentage / 100.0);
        let mut guns = stack(&w.rules[target], ships);
        guns.extend(stack(&w.rules[target], defence));
        let (attack, defending_hull) = strength(&w.rules[target], &w.research(cat, target), &guns);
        // Enough fighters to take the defenders' hull down in about three rounds, then hold for the loot.
        let (each_attack, _) = strength(&rules, &research, &[(kit.fighter, 1)]);
        let fighters = if defending_hull > 0.0 { (defending_hull * 1.5 / (3.0 * each_attack.max(1e-9))).ceil() as i64 } else { 0 };
        if fighters > on_shelf(w, a, kit.fighter) { return false }
        let fighter_hold = rules.combat.capacity(&[(kit.fighter, 1)].into_iter().collect(), &research);
        let cargo = (((loot.iter().sum::<f64>() - (fighters * fighter_hold) as f64) / hold as f64).ceil() as i64).clamp(0, on_shelf(w, a, kit.cargo));
        let fleet: Vec<(u32, i64)> = [(kit.fighter, fighters), (kit.cargo, cargo)].into_iter().filter(|s| s.1 > 0).collect();
        if fleet.is_empty() { return false }
        let capacity = fighters * fighter_hold + cargo * hold;
        let expected = combat::distribute_loot(loot, capacity);
        let fuel = fleet::consumption(&rules.settings, &rules.fleet, &fleet, &research, account_distance(w, a, target), 0, 10.0) as f64;
        if weighted(expected) < PAYS * WEIGHTS[2] * fuel { return false }
        let (_, hull) = strength(&rules, &research, &fleet);
        if attack * 3.0 > SAFE_SHARE * hull { return false }
        let had_fleet = stack(&w.rules[target], &w.yards[target].counts).iter().any(|&(id, _)| movable(&w.rules[target], id, &w.research(cat, target)));
        let Ok(arrive) = send(w, cat, a, target, Mission::Attack, &fleet, 10.0, ledger) else { return false };
        self.hit.insert(target, now);
        ledger.raids.push(Raid { attacker: a, target, arrive, loot: None, target_had_fleet: had_fleet, saved: false });
        true
    }

    fn recycle(&mut self, w: &mut World, cat: &Catalogue, a: usize, target: usize, ledger: &mut Ledger) {
        let field = w.debris[target];
        let rules = Arc::clone(&w.rules[a]);
        let Some(&harvester) = rules.combat.missions.harvesters.get(&w.coords[target].position.to_string()) else { return };
        let research = w.research(cat, a);
        let hold = rules.combat.capacity(&[(harvester, 1)].into_iter().collect(), &research).max(1);
        let total = field.iter().sum::<i64>();
        let already = w.fleets.iter().any(|f| f.owner == a && f.target == target && f.mission == Mission::Recycle);
        let n = ((total as f64 / hold as f64).ceil() as i64).min(on_shelf(w, a, harvester));
        if !w.fields[target] || total < hold / 2 || n < 1 || already { return }
        let fuel = fleet::consumption(&rules.settings, &rules.fleet, &[(harvester, n)], &research, account_distance(w, a, target), 0, 10.0) as f64;
        if weighted(field.map(|r| r as f64)).min((n * hold) as f64) < PAYS * WEIGHTS[2] * fuel { return }
        if send(w, cat, a, target, Mission::Recycle, &[(harvester, n)], 10.0, ledger).is_ok() { ledger.recycles += 1 }
    }
}

/// Watches for hostile fleets bound for its planet and sends its ships out of reach, to come home after the attack.
pub struct FleetSaver {
    pub miner: Miner,
    /// Attacks (attacker, arrival bits) already answered.
    answered: HashSet<(usize, u64)>,
}

impl FleetSaver {
    pub fn new(seed: u64) -> FleetSaver {
        FleetSaver { miner: Miner::new(seed), answered: HashSet::new() }
    }

    pub fn act(&mut self, w: &mut World, cat: &Catalogue, a: usize, ledger: &mut Ledger) -> Option<usize> {
        let pick = self.miner.act(w, cat, a);
        let now = w.games[a].t;
        let coming: Vec<Fleet> = w.fleets.iter().filter(|f| f.target == a && f.mission == Mission::Attack && !f.returning && f.arrive > now).cloned().collect();
        let fresh: Vec<&Fleet> = coming.iter().filter(|f| self.answered.insert((f.owner, f.arrive.to_bits()))).collect();
        for attack in fresh {
            let saved = self.leave(w, cat, a, attack.arrive - now, ledger);
            ledger.saves_sent += usize::from(saved);
            ledger.saves_refused += usize::from(!saved);
            if let Some(r) = ledger.raids.iter_mut().find(|r| r.attacker == attack.owner && r.target == a && r.arrive == attack.arrive) { r.saved = saved }
        }
        pick
    }

    /// Everything that flies goes to the nearest account it cannot be back from before the attack lands, at the
    /// fastest speed that is enough; the mission is a transport with an empty hold, which only keeps the ships away.
    fn leave(&self, w: &mut World, cat: &Catalogue, a: usize, remaining: f64, ledger: &mut Ledger) -> bool {
        let rules = Arc::clone(&w.rules[a]);
        let research = w.research(cat, a);
        let ships: Vec<(u32, i64)> = stack(&rules, &w.yards[a].counts).into_iter().filter(|&(id, _)| movable(&rules, id, &research)).collect();
        if ships.is_empty() { return false }
        let slowest = rules.fleet.slowest_speed(&ships, &research);
        let mut others = nearest(w, a, w.games.len());
        others.reverse();
        others.sort_by_key(|&b| account_distance(w, a, b));
        for speed in [10.0, 5.0, 2.0, 1.0] {
            for &b in &others {
                let away = 2 * fleet::flight_seconds(&rules.settings, Mission::Transport as u32, account_distance(w, a, b), slowest, speed);
                if (away as f64) > remaining + 120.0 && send(w, cat, a, b, Mission::Transport, &ships, speed, ledger).is_ok() { return true }
            }
        }
        false
    }
}

/// One of the three, for a world that mixes them.
pub enum Player {
    Miner(Miner),
    Raider(Raider),
    Saver(FleetSaver),
}

impl Player {
    /// What the account does at its deciding login: the economy's pick for the queue.
    pub fn act(&mut self, w: &mut World, cat: &Catalogue, a: usize, ledger: &mut Ledger) -> Option<usize> {
        ledger.scan(w);
        match self {
            Player::Miner(p) => p.act(w, cat, a),
            Player::Raider(p) => p.act(w, cat, a, ledger),
            Player::Saver(p) => p.act(w, cat, a, ledger),
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::fleet::Coord;

    const DATA: &str = "../../../../storage/rl/ogsim";
    const ACCOUNTS: usize = 12;
    const HOURS: f64 = 240.0;

    struct Run {
        world: World,
        ledger: Ledger,
        seconds: f64,
    }

    fn seed() -> u64 {
        std::env::var("OGSIM_SEED").ok().and_then(|s| s.parse().ok()).unwrap_or(5)
    }

    fn role(a: usize) -> usize {
        a % 3
    }

    fn run(hostile: bool, seed: u64) -> Option<(Catalogue, Run)> {
        let mut cat = Catalogue::load(&format!("{DATA}/units-parity/econ-u1.json")).ok()?;
        cat.login_gaps = vec![600.0, 1800.0, 3600.0, 7200.0, 21600.0];
        let rules = Arc::new(Rules::load(&cat, &format!("{DATA}/units-parity/units-u1.json"), &format!("{DATA}/fleet-settings-u1.json"), &format!("{DATA}/combat-u1.json")).ok()?);
        let configs: Vec<usize> = (0..ACCOUNTS).collect();
        let mut world = World::new(&cat, &configs, HOURS, 0.0, seed);
        let coords = (0..ACCOUNTS).map(|a| Coord { galaxy: 1, system: 10 + 2 * a as i64, position: 4 + (a % 8) as i64 }).collect();
        world.arm(vec![Arc::clone(&rules); ACCOUNTS], coords);
        // Starting stock of ships: raiders a hold and probes, savers a fleet to protect, miners a probe.
        for a in 0..ACCOUNTS {
            let kit = Kit::of(&world, &cat, a)?;
            let (cargo, probes, harvesters, fighters) = match role(a) {
                0 => (20, 6, 4, 80),
                1 => (6, 2, 0, 0),
                _ => (3, 1, 0, 0),
            };
            for (id, n) in [(kit.cargo, cargo), (kit.probe, probes), (kit.harvester, harvesters), (kit.fighter, fighters)] {
                let u = world.rules[a].units.index(id as i64)?;
                world.yards[a].counts[u] = n;
            }
            world.games[a].planet.res[2] += 30_000.0;
        }
        let mut players: Vec<Player> = (0..ACCOUNTS).map(|a| {
            let s = 100 + a as u64;
            match role(a) {
                0 => Player::Raider(Raider { hostile, ..Raider::new(s) }),
                1 => Player::Saver(FleetSaver::new(s)),
                _ => Player::Miner(Miner::new(s)),
            }
        }).collect();
        let mut ledger = Ledger::default();
        let start = std::time::Instant::now();
        let mut lastd = vec![0.0; ACCOUNTS];
        let mut n = 0;
        while let Some(a) = world.next_decision(&cat) {
            lastd[a] = world.games[a].t; n += 1;
            match players[a].act(&mut world, &cat, a, &mut ledger) {
                Some(k) => world.commit(&cat, a, k),
                None => world.wait(&cat, a),
            }
        }
        ledger.scan(&world);
        println!("decisions {n}, last per account (h) {:?}", lastd.iter().map(|t| (t / 3600.0) as i64).collect::<Vec<_>>());
        Some((cat, Run { world, ledger, seconds: start.elapsed().as_secs_f64() }))
    }

    #[test]
    fn miners_raiders_and_savers_play_ten_days() {
        let Some((_, raided)) = run(true, seed()) else { return eprintln!("no u1 catalogues; skipped") };
        let (_, quiet) = run(false, seed()).unwrap();
        let (w, l) = (&raided.world, &raided.ledger);
        let values: Vec<f64> = (0..ACCOUNTS).map(|a| account_value(w, a)).collect();
        let quiet_values: Vec<f64> = (0..ACCOUNTS).map(|a| account_value(&quiet.world, a)).collect();
        let had: Vec<&Raid> = l.raids.iter().filter(|r| role(r.target) == 1 && r.target_had_fleet).collect();
        let (ok, failed) = (had.iter().filter(|r| r.saved).count(), had.iter().filter(|r| !r.saved).count());
        let loot = l.loot();
        println!("\nraids {} (resolved {}), spies {}, recycles {}, saves ok {} / late {} (refused sends {})", l.raids.len(), l.raids.iter().filter(|r| r.loot.is_some()).count(), l.spies, l.recycles, ok, failed, l.saves_refused);
        println!("loot m/c/d {:.0}/{:.0}/{:.0} = {:.0} weighted, fuel {:.0}, recycled {:.0}", loot[0], loot[1], loot[2], weighted(loot), l.fuel, weighted(l.recycled));
        println!("{:<8} {:>14} {:>14} {:>9}", "role", "value (raid)", "value (quiet)", "delta %");
        for (r, name) in ["raider", "saver", "miner"].iter().enumerate() {
            let pick = |v: &[f64]| (0..ACCOUNTS).filter(|&a| role(a) == r).map(|a| v[a]).sum::<f64>() / 4.0;
            let (x, y) = (pick(&values), pick(&quiet_values));
            println!("{name:<8} {x:>14.0} {y:>14.0} {:>8.1}%", 100.0 * (x - y) / y);
        }
        let hours = ACCOUNTS as f64 * HOURS;
        println!("throughput: {:.0} account-hours/s ({:.2}s for both runs: {:.2}s raided, {:.2}s quiet)", hours / raided.seconds, raided.seconds + quiet.seconds, raided.seconds, quiet.seconds);

        assert!(!l.raids.is_empty(), "no raid was flown");
        assert!(weighted(loot) > 0.0, "no loot was brought home");
        assert!(ok >= 1, "no fleet save succeeded");
        assert!(failed >= 1, "no fleet save failed");
        for a in 0..ACCOUNTS {
            assert!(w.games[a].planet.res.iter().all(|&r| r >= 0.0), "account {a} went negative: {:?}", w.games[a].planet.res);
            assert!(w.yards[a].counts.iter().all(|&n| n < u64::MAX / 2));
        }
        // What raiding moved: the loot, the fuel and what was lost; the rest of the gap would be a leak.
        let (v, q): (f64, f64) = (values.iter().sum(), quiet_values.iter().sum());
        let moved = weighted(loot) + l.fuel + weighted(l.recycled);
        assert!((v - q).abs() <= moved + 0.03 * q, "world value {v:.0} vs quiet {q:.0}: gap {:.0} is more than raids moved ({moved:.0})", (v - q).abs());
    }
}
