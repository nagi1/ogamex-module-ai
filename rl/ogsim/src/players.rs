//! Scripted human-like players on the world (plan/rl/WORLD-SIM-PLAN.md, W7): a miner (the economy's player rule plus
//! the odd cargo ship or probe), a raider (spy, raid, recycle) and a fleet saver (leaves before an attack lands and
//! recalls the fleet once it has passed). They play per account (the capital's slot; a colony only builds) and act at
//! every login, including the ones with nothing to build. Roles come from the host's tables: a ship is movable when
//! its speed is above zero, a cargo ship is the movable non-probe non-harvester with the most capacity per price,
//! probes and harvesters are what the mission tables name. No object is named; targets are the nearest accounts by
//! coordinate, the inactive ones first. Results are read from the world's event log.

use std::collections::{HashMap, HashSet};
use std::sync::Arc;

use crate::combat;
use crate::economy::{Baseline, Catalogue, WEIGHTS};
use crate::fleet::{self, SystemState};
use crate::world::{Decision, EventKind, Mission, Outcome, Rules, SpyReport, World};

pub(crate) const SLOTS: usize = 4;
pub(crate) const NEIGHBOURS: usize = 5;
/// The scouted targets a login also weighs up, beyond the nearest ones: the richest reports.
pub(crate) const RICH_TARGETS: usize = 6;
pub(crate) const REPORT_HOURS: f64 = 24.0;
pub(crate) const RAID_COOLDOWN: f64 = 3.0 * 3600.0;
/// A target is worth the trip when the loot pays this many times its fuel.
pub(crate) const PAYS: f64 = 3.0;
/// A target whose guns do more than this share of our hull in three rounds is left alone.
pub(crate) const SAFE_SHARE: f64 = 0.3;
/// The most probes one spy is sent with.
pub(crate) const SPY_PROBES: i64 = 4;

pub(crate) fn weighted(r: [f64; 3]) -> f64 {
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
    /// Fleets recalled after the attack they dodged had passed.
    pub recalls: usize,
    /// Weighted price of the ships the players lost in fights.
    pub lost: f64,
    /// (attacker, target) pairs where the attacker's fleet was beaten: the raider does not go back.
    pub beaten: HashSet<(usize, usize)>,
    /// How many events of the world's log have been read.
    read: usize,
}

pub struct Raid {
    pub attacker: usize,
    pub target: usize,
    pub arrive: f64,
    /// Read from the fight's event: what the target lost to the raid.
    pub loot: Option<[f64; 3]>,
    /// The target held ships that can fly when the raid left.
    pub target_had_fleet: bool,
    /// Read from the fight's event: no ship that can fly stood on the target when the raid arrived.
    pub saved: bool,
}

impl Ledger {
    /// Reads the events the world wrote since the last call: a fight settles a raid (its loot, whether anything that can
    /// fly stood on the target, what the raider lost), a recycler's arrival books the harvest.
    fn scan(&mut self, w: &World, cat: &Catalogue) {
        // A ledger lives as long as an account slot, and a slot whose world finished starts a fresh world whose log begins
        // again at zero, so the cursor can sit past the end of the new log.
        for e in &w.events[self.read.min(w.events.len())..] {
            let price = |slot: usize, id: u32| unit_price(&w.rules[slot], id);
            match (e.kind, e.mission) {
                (EventKind::Fight, Mission::Attack) => {
                    self.lost += e.ships_lost.iter().map(|&(id, n)| price(e.owner, id) * n as f64).sum::<f64>();
                    if e.outcome == Outcome::Defeated { self.beaten.insert((e.owner, e.target)); }
                    let research = w.research(cat, e.target);
                    let stood = e.present.iter().any(|&(id, _)| movable(&w.rules[e.target], id, &research));
                    if let Some(r) = self.raids.iter_mut().find(|r| r.attacker == e.owner && r.target == e.target && r.arrive == e.time) {
                        r.loot = Some(e.gained);
                        r.saved = !stood;
                    }
                }
                (EventKind::Arrival, Mission::Recycle) => {
                    for i in 0..3 { self.recycled[i] += e.gained[i] }
                }
                _ => {}
            }
        }
        self.read = w.events.len();
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
pub(crate) fn owned(w: &World, a: usize, id: u32) -> i64 {
    let Some(u) = w.rules[a].units.index(id as i64) else { return 0 };
    let queued: u64 = w.yards[a].orders.iter().filter(|o| o.unit == u).map(|o| o.amount - o.done).sum();
    let flying: i64 = w.fleets.iter().filter(|f| f.owner == a).flat_map(|f| f.ships.iter()).filter(|s| s.0 == id).map(|s| s.1).sum();
    (w.yards[a].counts[u] + queued) as i64 + flying
}

pub(crate) fn on_shelf(w: &World, a: usize, id: u32) -> i64 {
    w.rules[a].units.index(id as i64).map_or(0, |u| w.yards[a].counts[u] as i64)
}

pub(crate) fn account_distance(w: &World, a: usize, b: usize) -> i64 {
    fleet::distance(&w.rules[a].settings, w.coords[a], w.coords[b], &|_, _| SystemState::Active)
}

/// The other accounts (their capitals) by coordinate, closest first.
fn others_by_distance(w: &World, a: usize) -> Vec<usize> {
    let mut others: Vec<usize> = (0..w.games.len()).filter(|&b| b != a && w.account_of(b) == b).collect();
    others.sort_by_key(|&b| (account_distance(w, a, b), b));
    others
}

/// The nearest other accounts, the inactive ones (OGame's 'i') before the rest, each group closest first.
pub(crate) fn nearest(w: &mut World, cat: &Catalogue, a: usize, n: usize) -> Vec<usize> {
    let now = w.games[a].t;
    let mut others = others_by_distance(w, a);
    let inactive: HashSet<usize> = others.iter().copied().filter(|&b| w.inactive(cat, b, now)).collect();
    others.sort_by_key(|b| !inactive.contains(b));
    others.truncate(n);
    others
}

/// What one of a unit costs in metal equivalent, at the catalogue the account plays.
pub(crate) fn unit_price(rules: &Rules, id: u32) -> f64 {
    rules.units.index(id as i64).map_or(0.0, |u| weighted(rules.units.units[u].price))
}

/// Value in metal equivalent: the economy's value, ships on the shelf, in the queue and in flight at price, and what flies.
pub fn account_value(w: &World, a: usize) -> f64 {
    let rules = &w.rules[a];
    let price = |id: u32| unit_price(rules, id);
    let shelf: f64 = rules.units.units.iter().zip(&w.yards[a].counts).map(|(u, &n)| weighted(u.price) * n as f64).sum();
    let queued: f64 = w.yards[a].orders.iter().map(|o| weighted(rules.units.units[o.unit].price) * (o.amount - o.done) as f64).sum();
    let flying: f64 = w.fleets.iter().filter(|f| f.owner == a).map(|f| f.ships.iter().map(|s| price(s.0) * s.1 as f64).sum::<f64>() + weighted(f.cargo) + WEIGHTS[2] * f.fuel / 2.0).sum();
    w.games[a].value() + shelf + queued + flying
}

/// Attack and hull of a stack of units at its owner's research, as the host's engine reads them; units that stay out
/// of a fight count for nothing.
pub(crate) fn strength(rules: &Rules, research: &HashMap<u32, u32>, units: &[(u32, i64)]) -> (f64, f64) {
    units.iter().filter(|&&(id, _)| rules.combat.fights(id)).fold((0.0, 0.0), |t, &(id, n)| {
        (t.0 + n as f64 * rules.combat.attack(id, research) as f64, t.1 + n as f64 * rules.combat.hull(id, research) as f64)
    })
}

pub(crate) fn stack(rules: &Rules, counts: &[u64]) -> Vec<(u32, i64)> {
    rules.units.units.iter().zip(counts).filter(|(_, &n)| n > 0).map(|(u, &n)| (u.id as u32, n as i64)).collect()
}

/// A raid the scripted raider would send, sized from the newest report on the target.
pub(crate) struct RaidPlan {
    pub fleet: Vec<(u32, i64)>,
    /// What the hold takes of the report's stock, shared out as the host shares loot.
    pub expected: [f64; 3],
    /// The defenders' attack in three rounds, and our hull at home: the raider's safety check.
    pub attack: f64,
    pub hull: f64,
}

pub(crate) fn last_report<'a>(w: &'a World, a: usize, target: usize) -> Option<&'a SpyReport> {
    w.reports.iter().rev().find(|r| r.owner == a && r.target == target)
}

/// The raider's sizing of a raid: fighters enough to take the defenders' hull down in about three rounds, cargo ships
/// for the loot that fits. `None` when the account does not hold that fleet, the report lacks the fleet or defence
/// section, or there is nothing to take. Payoff and safety stay with the caller (the raider's rule, or an errand's
/// mask), so the one sizing serves both.
pub(crate) fn raid_plan(w: &World, cat: &Catalogue, a: usize, target: usize, kit: Kit) -> Option<RaidPlan> {
    let report = last_report(w, a, target)?;
    let (Some(ships), Some(defence)) = (&report.ships, &report.defence) else { return None };
    let rules = &w.rules[a];
    let research = w.research(cat, a);
    let hold = rules.combat.capacity(&[(kit.cargo, 1)].into_iter().collect(), &research).max(1);
    let loot = report.resources.map(|r| r.max(0) as f64 * rules.combat.rules.loot_percentage / 100.0);
    let mut guns = stack(&w.rules[target], ships);
    guns.extend(stack(&w.rules[target], defence));
    let (attack, defending_hull) = strength(&w.rules[target], &w.research(cat, target), &guns);
    let (each_attack, _) = strength(rules, &research, &[(kit.fighter, 1)]);
    let fighters = if defending_hull > 0.0 { (defending_hull * 1.5 / (3.0 * each_attack.max(1e-9))).ceil() as i64 } else { 0 };
    if fighters > on_shelf(w, a, kit.fighter) { return None }
    let fighter_hold = rules.combat.capacity(&[(kit.fighter, 1)].into_iter().collect(), &research);
    let cargo = (((loot.iter().sum::<f64>() - (fighters * fighter_hold) as f64) / hold as f64).ceil() as i64).clamp(0, on_shelf(w, a, kit.cargo));
    let fleet: Vec<(u32, i64)> = [(kit.fighter, fighters), (kit.cargo, cargo)].into_iter().filter(|s| s.1 > 0).collect();
    if fleet.is_empty() { return None }
    let expected = combat::distribute_loot(loot, fighters * fighter_hold + cargo * hold);
    let (_, hull) = strength(rules, &research, &fleet);
    Some(RaidPlan { fleet, expected, attack, hull })
}

/// The harvesters the raider sends for the field over `target`: enough hulls to take it, never more than the account
/// holds. `None` when the slot has no harvester hull, no field, or nothing worth the trip.
pub(crate) fn harvest_plan(w: &World, cat: &Catalogue, a: usize, target: usize) -> Option<(u32, i64)> {
    let rules = &w.rules[a];
    let harvester = *rules.combat.missions.harvesters.get(&w.coords[target].position.to_string())?;
    let research = w.research(cat, a);
    let hold = rules.combat.capacity(&[(harvester, 1)].into_iter().collect(), &research).max(1);
    let total = w.debris[target].iter().sum::<i64>();
    let n = ((total as f64 / hold as f64).ceil() as i64).min(on_shelf(w, a, harvester));
    // Fuel is the dispatch's own refusal: a fleet the planet cannot fill the tanks for never leaves.
    let fuel = fleet::consumption(&rules.settings, &rules.fleet, &[(harvester, n)], &research, account_distance(w, a, target), 0, 10.0) as f64;
    (w.fields[target] && total >= hold / 2 && n >= 1 && fuel <= w.games[a].planet.res[2]).then_some((harvester, n))
}

/// Everything that flies going away for longer than `remaining` seconds: the nearest account whose round trip is
/// longer, at the first speed that makes it so. `None` when nothing can fly or nowhere is far enough.
pub(crate) fn save_plan(w: &World, cat: &Catalogue, a: usize, remaining: f64) -> Option<(usize, Vec<(u32, i64)>, f64)> {
    let rules = &w.rules[a];
    let research = w.research(cat, a);
    let ships: Vec<(u32, i64)> = stack(rules, &w.yards[a].counts).into_iter().filter(|&(id, _)| movable(rules, id, &research)).collect();
    if ships.is_empty() { return None }
    let slowest = rules.fleet.slowest_speed(&ships, &research);
    for speed in [1.0, 2.0, 5.0, 10.0] {
        for b in others_by_distance(w, a) {
            let away = 2 * fleet::flight_seconds(&rules.settings, Mission::Transport as u32, account_distance(w, a, b), slowest, speed);
            if (away as f64) > remaining + 120.0 { return Some((b, ships, speed)) }
        }
    }
    None
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

    /// The economy's pick for the deciding queue (None lets the login pass; at a login with nothing to build there is no
    /// pick), after any ship order. Only a capital orders ships: a colony only builds.
    pub fn act(&mut self, w: &mut World, cat: &Catalogue, a: usize) -> Option<usize> {
        let (queue, planet) = (w.games[a].queue, &w.games[a].planet);
        let pick = match w.decision_kind(a) {
            Decision::Build => self.short(w, cat, a).or_else(|| self.unlock(w, cat, a)).or_else(|| cat.baseline(planet, queue, Baseline::Player, &mut self.rng)),
            Decision::Idle => None,
        };
        let overflow = (0..3).any(|r| planet.res[r] >= 0.8 * planet.cap[r]);
        if w.account_of(a) == a && (pick.is_none() || overflow || self.eager) {
            self.order_ships(w, cat, a);
        }
        // The colony the ship was built for is founded at the same login: one mission, no cargo.
        if w.account_of(a) == a {
            if let (Some(to), Some(ship)) = (w.colony_site(cat, a), w.colonisation.as_ref().map(|t| t.ship)) {
                if let Some(u) = w.rules[a].units.index(ship as i64) {
                    if w.yards[a].counts[u] > 0 {
                        let _ = w.colonise(cat, a, to, &[(ship, 1)], [0.0; 3], 1.0);
                    }
                }
            }
        }
        pick
    }

    /// A resource the planet neither makes nor holds: a player mines while short (gate 3), and nothing here names a mine
    /// — a production row that adds to that resource says which one (gate 1). Without this nothing that costs deuterium
    /// is ever reachable on a fresh planet, ships and the shipyard included.
    fn short(&self, w: &World, cat: &Catalogue, a: usize) -> Option<usize> {
        let planet = &w.games[a].planet;
        (0..3).find(|&r| planet.res[r] <= 0.0 && planet.rate[r] <= 0.0).and_then(|r| {
            (0..cat.objects.len()).find(|&k| {
                cat.objects[k].queue == w.games[a].queue
                    && w.legal(cat, a, k)
                    && cat.production_row(planet, k, planet.levels[k] as usize).is_some_and(|row| row[r] > 0.0)
            })
        })
    }


    /// What to build so the ships this account wants become buildable: the first unmet requirement of a wanted ship, of
    /// what that requirement needs, and so on, that this queue can start now. A player builds the prerequisite before the
    /// thing it unlocks, across queues and up the chain (gate 3), and nothing here knows which object that is — the
    /// requirement lists say so (gate 1).
    fn unlock(&self, w: &World, cat: &Catalogue, a: usize) -> Option<usize> {
        let levels = &w.games[a].planet.levels;
        let mut need: Vec<(usize, u32)> = self.order_plans(w, cat, a).into_iter()
            .flat_map(|(u, _)| w.rules[a].units.units[u].requires.iter().copied())
            .filter_map(|(k, level)| Some((k?, level)))
            .collect();
        let mut at = 0;
        while at < need.len() && need.len() < 256 {
            let k = need[at].0;
            at += 1;
            need.extend(cat.objects[k].requires.iter().filter_map(|&(need_k, need_level)| Some((need_k?, need_level))));
        }
        // A colony needs the host's colonisation research before any site is in range; the catalogue names it (gate 1).
        if let Some(t) = w.colonisation.as_ref() {
            if let Some(k) = cat.objects.iter().position(|o| o.id == t.research) {
                need.push((k, t.position_levels.values().copied().min().unwrap_or(0)));
            }
        }
        need.into_iter().find_map(|(k, level)| {
            (levels[k] < level && cat.objects[k].queue == w.games[a].queue && w.legal(cat, a, k)).then_some(k)
        })
    }

    fn order_ships(&self, w: &mut World, cat: &Catalogue, a: usize) {
        for (u, amount) in self.order_plans(w, cat, a) {
            w.order_units(cat, a, u, amount as u64);
        }
    }

    /// The ships this rule wants at this login that the account does not have, as (catalogue index, amount): what the
    /// yard is told to build. Shared with the errand choice, whose `order` candidate is this rule's answer.
    pub(crate) fn order_plans(&self, w: &World, cat: &Catalogue, a: usize) -> Vec<(usize, i64)> {
        let Some(kit) = Kit::of(w, cat, a) else { return Vec::new() };
        let rules = &w.rules[a];
        let research = w.research(cat, a);
        let hold = rules.combat.capacity(&[(kit.cargo, 1)].into_iter().collect(), &research).max(1);
        let stock: f64 = w.games[a].planet.res.iter().sum();
        let wants = [(kit.probe, self.probes), (kit.cargo, (stock * self.cargo_share / hold as f64).ceil() as i64), (kit.harvester, self.harvesters), (kit.fighter, self.fighters)];
        let mut plans: Vec<(usize, i64)> = wants.into_iter().filter_map(|(id, want)| {
            let missing = want - owned(w, a, id);
            let u = rules.units.index(id as i64)?;
            (missing > 0).then_some((u, missing))
        }).collect();
        // A player takes a new planet as soon as the host allows it: one colony ship, whose id the catalogue gives.
        if let Some(ship) = w.colonisation.as_ref().and_then(|t| w.colony_site(cat, a).map(|_| t.ship)) {
            if let Some(u) = rules.units.index(ship as i64) {
                if owned(w, a, ship) < 1 { plans.push((u, 1)) }
            }
        }
        plans
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
        if !self.hostile || now == self.last || w.account_of(a) != a { return pick }
        self.last = now;
        let Some(kit) = Kit::of(w, cat, a) else { return pick };
        for target in nearest(w, cat, a, NEIGHBOURS) {
            if w.fleets.iter().filter(|f| f.owner == a).count() >= SLOTS { break }
            self.recycle(w, cat, a, target, ledger);
            if self.raid(w, cat, a, target, kit, ledger) { continue }
            self.spy(w, cat, a, target, kit, ledger);
        }
        pick
    }

    fn spy(&mut self, w: &mut World, cat: &Catalogue, a: usize, target: usize, kit: Kit, ledger: &mut Ledger) {
        let now = w.games[a].t;
        let fresh = last_report(w, a, target).is_some_and(|r| now - r.time < REPORT_HOURS * 3600.0);
        let recent = self.spied.get(&target).is_some_and(|&t| now - t < 2.0 * 3600.0);
        let probes = on_shelf(w, a, kit.probe).min(SPY_PROBES);
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
        let Some(report) = last_report(w, a, target) else { return false };
        if now - report.time > REPORT_HOURS * 3600.0 || self.hit.get(&target).is_some_and(|&t| now - t < RAID_COOLDOWN) || ledger.beaten.contains(&(a, target)) { return false }
        let Some(plan) = raid_plan(w, cat, a, target, kit) else { return false };
        let rules = Arc::clone(&w.rules[a]);
        let research = w.research(cat, a);
        let fuel = fleet::consumption(&rules.settings, &rules.fleet, &plan.fleet, &research, account_distance(w, a, target), 0, 10.0) as f64;
        if weighted(plan.expected) < PAYS * WEIGHTS[2] * fuel { return false }
        if plan.attack * 3.0 > SAFE_SHARE * plan.hull { return false }
        let had_fleet = stack(&w.rules[target], &w.yards[target].counts).iter().any(|&(id, _)| movable(&w.rules[target], id, &w.research(cat, target)));
        let Ok(arrive) = send(w, cat, a, target, Mission::Attack, &plan.fleet, 10.0, ledger) else { return false };
        self.hit.insert(target, now);
        ledger.raids.push(Raid { attacker: a, target, arrive, loot: None, target_had_fleet: had_fleet, saved: false });
        true
    }

    fn recycle(&mut self, w: &mut World, cat: &Catalogue, a: usize, target: usize, ledger: &mut Ledger) {
        let Some((harvester, n)) = harvest_plan(w, cat, a, target) else { return };
        let field = w.debris[target];
        let rules = Arc::clone(&w.rules[a]);
        let research = w.research(cat, a);
        let hold = rules.combat.capacity(&[(harvester, 1)].into_iter().collect(), &research).max(1);
        let already = w.fleets.iter().any(|f| f.owner == a && f.target == target && f.mission == Mission::Recycle);
        if already { return }
        let fuel = fleet::consumption(&rules.settings, &rules.fleet, &[(harvester, n)], &research, account_distance(w, a, target), 0, 10.0) as f64;
        if weighted(field.map(|r| r as f64)).min((n * hold) as f64) < PAYS * WEIGHTS[2] * fuel { return }
        if send(w, cat, a, target, Mission::Recycle, &[(harvester, n)], 10.0, ledger).is_ok() { ledger.recycles += 1 }
    }
}

/// Watches for hostile fleets bound for its capital and sends its ships out of reach; once the attack has landed it
/// recalls them, so they are home in the time they had flown instead of after the whole round trip.
pub struct FleetSaver {
    pub miner: Miner,
    /// Attacks (attacker, arrival bits) already answered.
    answered: HashSet<(usize, u64)>,
    /// Fleets sent away (departure bits) with the second of the attack they dodge.
    saving: Vec<(u64, f64)>,
}

impl FleetSaver {
    pub fn new(seed: u64) -> FleetSaver {
        FleetSaver { miner: Miner::new(seed), answered: HashSet::new(), saving: Vec::new() }
    }

    pub fn act(&mut self, w: &mut World, cat: &Catalogue, a: usize, ledger: &mut Ledger) -> Option<usize> {
        let pick = self.miner.act(w, cat, a);
        if w.account_of(a) != a { return pick }
        let now = w.games[a].t;
        self.recall_passed(w, a, now, ledger);
        // Before the night the fleet goes out for it, as a player's does: the next login is hours away and a raid does not
        // wait for it. A save that only answered an attack seen while awake could never happen at all (measured: zero in
        // ten days across four savers).
        let hours_to_night = (w.games[a].sleep_start - (now % 86_400.0) / 3600.0).rem_euclid(24.0);
        if hours_to_night <= 1.0 && self.leave(w, cat, a, w.games[a].sleep_hours * 3600.0, ledger) {
            ledger.saves_sent += 1;
        }
        let coming: Vec<(usize, f64)> = w.fleets.iter().filter(|f| f.target == a && f.mission == Mission::Attack && !f.returning && f.arrive > now).map(|f| (f.owner, f.arrive)).collect();
        let fresh: Vec<f64> = coming.into_iter().filter(|c| self.answered.insert((c.0, c.1.to_bits()))).map(|c| c.1).collect();
        for lands in fresh {
            let saved = self.leave(w, cat, a, lands - now, ledger);
            if saved { self.saving.push((w.fleets.last().map_or(0, |f| f.departed.to_bits()), lands)) }
            ledger.saves_sent += usize::from(saved);
            ledger.saves_refused += usize::from(!saved);
        }
        pick
    }

    /// Turns home every saved fleet whose attack has landed and which is still on its way out.
    fn recall_passed(&mut self, w: &mut World, a: usize, now: f64, ledger: &mut Ledger) {
        let (passed, waiting): (Vec<_>, Vec<_>) = self.saving.iter().copied().partition(|&(_, lands)| lands <= now);
        self.saving = waiting;
        for (departed, _) in passed {
            let Some(f) = w.fleets.iter().position(|f| f.owner == a && !f.returning && f.departed.to_bits() == departed) else { continue };
            if w.recall(a, f).is_ok() { ledger.recalls += 1 }
        }
    }

    /// Everything that flies goes to the nearest account it cannot be back from before the attack lands, at the slowest
    /// speed (the cheapest fuel, and the longest outward leg to recall from at the next login); the mission is a
    /// transport with an empty hold, which only keeps the ships away.
    fn leave(&self, w: &mut World, cat: &Catalogue, a: usize, remaining: f64, ledger: &mut Ledger) -> bool {
        let Some((b, ships, speed)) = save_plan(w, cat, a, remaining) else { return false };
        send(w, cat, a, b, Mission::Transport, &ships, speed, ledger).is_ok()
    }
}

/// One of the three, for a world that mixes them.
pub enum Player {    Miner(Miner),
    Raider(Raider),
    Saver(FleetSaver),
}

impl Player {
    /// What the account does at its deciding login: the economy's pick for the queue.
    pub fn act(&mut self, w: &mut World, cat: &Catalogue, a: usize, ledger: &mut Ledger) -> Option<usize> {
        ledger.scan(w, cat);
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

    /// The seeds the test plays: the one in OGSIM_SEED, or 1 to 8.
    fn seeds() -> Vec<u64> {
        std::env::var("OGSIM_SEED").ok().and_then(|s| s.parse().ok()).map_or_else(|| (1..=8).collect(), |s| vec![s])
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
        // A fully built account still logs in, to raid or to save its fleet.
        world.enable_idle_logins(&cat);
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
        ledger.scan(&world, &cat);
        println!("decisions {n}, last per account (h) {:?}", lastd.iter().map(|t| (t / 3600.0) as i64).collect::<Vec<_>>());
        Some((cat, Run { world, ledger, seconds: start.elapsed().as_secs_f64() }))
    }

    /// One seed: the raided world against its peaceful twin. Returns (saves ok, saves late, fleets recalled) of the
    /// savers' resolved raids.
    fn play_seed(seed: u64) -> Option<(usize, usize, usize)> {
        let (_, raided) = run(true, seed)?;
        let (_, quiet) = run(false, seed)?;
        let (w, l) = (&raided.world, &raided.ledger);
        let values: Vec<f64> = (0..ACCOUNTS).map(|a| account_value(w, a)).collect();
        let quiet_values: Vec<f64> = (0..ACCOUNTS).map(|a| account_value(&quiet.world, a)).collect();
        let had: Vec<&Raid> = l.raids.iter().filter(|r| role(r.target) == 1 && r.target_had_fleet && r.loot.is_some()).collect();
        let (ok, failed) = (had.iter().filter(|r| r.saved).count(), had.iter().filter(|r| !r.saved).count());
        let loot = l.loot();
        println!("\nseed {seed}: raids {} (resolved {}), spies {}, recycles {}, saves ok {} / late {} (refused sends {}, recalled {}), ships lost {:.0}", l.raids.len(), l.raids.iter().filter(|r| r.loot.is_some()).count(), l.spies, l.recycles, ok, failed, l.saves_refused, l.recalls, l.lost);
        if std::env::var("OGSIM_TRACE").is_ok() {
            for r in had.iter() { println!("  raid on saver {} by {}: arrives {:.0}s ({:.1}h) saved {} loot {:?}", r.target, r.attacker, r.arrive, r.arrive / 3600.0, r.saved, r.loot.map(|l| weighted(l) as i64)); }
            for e in w.events.iter().filter(|e| matches!(e.kind, EventKind::Recall) || (e.mission == Mission::Transport && role(e.owner) == 1)) { println!("  event {:?} {:?} owner {} target {} at {:.0}", e.kind, e.mission, e.owner, e.target, e.time); }
        }
        println!("loot m/c/d {:.0}/{:.0}/{:.0} = {:.0} weighted, fuel {:.0}, recycled {:.0}", loot[0], loot[1], loot[2], weighted(loot), l.fuel, weighted(l.recycled));
        println!("{:<8} {:>14} {:>14} {:>9}", "role", "value (raid)", "value (quiet)", "delta %");
        for (r, name) in ["raider", "saver", "miner"].iter().enumerate() {
            let pick = |v: &[f64]| (0..ACCOUNTS).filter(|&a| role(a) == r).map(|a| v[a]).sum::<f64>() / 4.0;
            let (x, y) = (pick(&values), pick(&quiet_values));
            println!("{name:<8} {x:>14.0} {y:>14.0} {:>8.1}%", 100.0 * (x - y) / y);
        }
        let hours = ACCOUNTS as f64 * HOURS;
        println!("throughput: {:.0} account-hours/s ({:.2}s for both runs: {:.2}s raided, {:.2}s quiet)", hours / raided.seconds, raided.seconds + quiet.seconds, raided.seconds, quiet.seconds);

        assert!(!l.raids.is_empty(), "seed {seed}: no raid was flown");
        assert!(weighted(loot) > 0.0, "seed {seed}: no loot was brought home");
        for a in 0..ACCOUNTS {
            assert!(w.games[a].planet.res.iter().all(|&r| r >= 0.0), "seed {seed}: account {a} went negative: {:?}", w.games[a].planet.res);
            assert!(w.yards[a].counts.iter().all(|&n| n < u64::MAX / 2));
        }
        // The loot read from the log is what the targets lost: every logged fight's loot left the target's stock.
        let logged: f64 = w.events.iter().filter(|e| e.kind == EventKind::Fight && e.mission == Mission::Attack).map(|e| weighted(e.gained)).sum();
        assert!((logged - weighted(loot)).abs() < 1e-6, "seed {seed}: the ledger's loot {} is not the log's {logged}", weighted(loot));
        // What raiding moved: the loot, the fuel and what was lost; the rest of the gap would be a leak.
        let (v, q): (f64, f64) = (values.iter().sum(), quiet_values.iter().sum());
        let moved = weighted(loot) + l.fuel + weighted(l.recycled) + l.lost;
        assert!((v - q).abs() <= moved + 0.03 * q, "seed {seed}: world value {v:.0} vs quiet {q:.0}: gap {:.0} is more than raids moved ({moved:.0})", (v - q).abs());
        Some((ok, failed, l.recalls))
    }

    /// A world of accounts with nothing on the shelf: what a run starts from.
    fn bare(seed: u64) -> Option<(Catalogue, World)> {
        let mut cat = Catalogue::load(&format!("{DATA}/units-parity/econ-u1.json")).ok()?;
        cat.login_gaps = vec![600.0, 1800.0, 3600.0, 7200.0, 21600.0];
        let rules = Arc::new(Rules::load(&cat, &format!("{DATA}/units-parity/units-u1.json"), &format!("{DATA}/fleet-settings-u1.json"), &format!("{DATA}/combat-u1.json")).ok()?);
        let configs: Vec<usize> = (0..ACCOUNTS).collect();
        let mut world = World::new(&cat, &configs, HOURS, 0.0, seed);
        world.enable_idle_logins(&cat);
        let coords = (0..ACCOUNTS).map(|a| Coord { galaxy: 1, system: 10 + 2 * a as i64, position: 4 + (a % 8) as i64 }).collect();
        world.arm(vec![Arc::clone(&rules); ACCOUNTS], coords);
        Some((cat, world))
    }

    /// A scripted account founds a second planet as soon as the host allows it: room from `max_planets`, the
    /// colonisation research, one colony ship, one mission (row 8, S3).
    #[test]
    fn a_miner_founds_a_second_planet() {
        let Ok(mut cat) = Catalogue::load(&format!("{DATA}/units-parity/econ-u1-colonies.json")) else { return eprintln!("no colonies catalogue; skipped") };
        cat.login_gaps = vec![600.0, 1800.0, 3600.0, 7200.0, 21600.0];
        let Ok(rules) = Rules::load(&cat, &format!("{DATA}/units-parity/units-u1.json"), &format!("{DATA}/fleet-settings-u1.json"), &format!("{DATA}/combat-u1.json")) else { return };
        let Ok(table) = crate::world::Colonisation::load(&format!("{DATA}/units-parity/econ-u1-colonies.json")) else { return };
        let mut world = World::new(&cat, &[0], HOURS * 4.0, 0.0, 5);
        world.enable_idle_logins(&cat);
        world.colonisation = Some(Arc::new(table));
        world.arm(vec![Arc::new(rules)], vec![Coord { galaxy: 1, system: 10, position: 4 }]);
        let mut miner = Miner::new(5);
        let mut steps = 0;
        while steps < 400_000 {
            let Some(a) = world.next_decision(&cat) else { break };
            steps += 1;
            if a != 0 { world.wait(&cat, a); continue }
            match miner.act(&mut world, &cat, a) {
                Some(k) => world.commit(&cat, a, k),
                None => world.wait(&cat, a),
            }
        }
        assert!(world.planets_of(0).len() >= 2, "no colony in {steps} decisions: levels {:?}", world.games[0].planet.levels);
    }

    /// Both queues get their turn: an account whose building queue always has something payable must still research what its
    /// ships need, or no ship is ever buildable.
    #[test]
    fn both_queues_get_their_turn() {
        let Some((cat, mut world)) = bare(1) else { return };
        let mut miner = Raider::new(1).miner;
        let mut by_queue = [0usize; 2];
        for _ in 0..20_000 {
            let Some(a) = world.next_decision(&cat) else { break };
            if a != 0 {
                world.wait(&cat, a);
                continue;
            }
            by_queue[world.games[0].queue] += 1;
            match miner.act(&mut world, &cat, a) {
                Some(k) => world.commit(&cat, a, k),
                None => world.wait(&cat, a),
            }
        }
        let research: u32 = cat.objects.iter().zip(&world.games[0].planet.levels).filter(|(o, _)| o.queue == 1).map(|(_, &l)| l).sum();
        assert!(by_queue[1] > 0, "the research queue never decided: {by_queue:?}");
        assert!(research > 0, "nothing was ever researched: {by_queue:?}");
    }

    /// Without a fleet there is no fleet play at all, so a fresh account must open the way to ships: what the rule picks at
    /// its build logins is what its wanted ships require, and once that is built the yard can order.
    #[test]
    fn a_fresh_account_opens_the_way_to_ships() {
        let Some((cat, mut world)) = bare(1) else { return };
        let mut miner = Raider::new(1).miner;
        assert!(!miner.order_plans(&world, &cat, 0).is_empty(), "the raider wants no ships at all");
        // A fresh planet makes no deuterium, so nothing that costs it is reachable: the rule must mine it first.
        assert_eq!(world.games[0].planet.rate[2], 0.0, "the start already makes deuterium");
        let mut steps = 0;
        while steps < 6_000 {
            let Some(a) = world.next_decision(&cat) else { break };
            if a != 0 {
                world.wait(&cat, a);
                steps += 1;
                continue;
            }
            match miner.act(&mut world, &cat, a) {
                Some(k) => world.commit(&cat, a, k),
                None => world.wait(&cat, a),
            }
            steps += 1;
            if !world.yards[0].orders.is_empty() || world.yards[0].counts.iter().any(|&n| n > 0) { break }
        }
        assert!(world.games[0].planet.rate[2] > 0.0, "the rule never mined deuterium: {:?}", world.games[0].planet.levels);
        assert!(world.yards[0].counts.iter().any(|&n| n > 0) || !world.yards[0].orders.is_empty(),
            "{steps} decisions, deuterium flowing and still no ship ordered: {:?}", world.games[0].planet.levels);
    }

    #[test]
    fn miners_raiders_and_savers_play_ten_days() {
        let mut saves = Vec::new();
        for seed in seeds() {
            let Some(result) = play_seed(seed) else { return eprintln!("no u1 catalogues; skipped") };
            saves.push((seed, result));
        }
        println!("\nsaves ok / late / recalled by seed: {saves:?}");
        // A save needs a login between the raid leaving and landing, and the flights are short, so a seed may have none;
        // the seed set must show saves, failures and a recall.
        let total = |pick: fn(&(usize, usize, usize)) -> usize| saves.iter().map(|(_, r)| pick(r)).sum::<usize>();
        assert!(total(|r| r.0) >= 1, "no fleet save succeeded on any seed");
        assert!(total(|r| r.1) >= 1, "no fleet save failed on any seed: a save that cannot fail is not a save");
        assert!(total(|r| r.2) >= 1, "no saved fleet was ever recalled");
    }
}
