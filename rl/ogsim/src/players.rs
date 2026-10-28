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
use crate::economy::{Baseline, Catalogue, Planet, WEIGHTS};
use crate::fleet::{self, Coord, SystemState};
use crate::score;
use crate::units::UnitKind;
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
                    // Both sides: a raid destroys the defender's ships and defence, and the world-value accounting
                    // has to see everything a fight took (row 10c).
                    self.lost += e.ships_lost.iter().map(|&(id, n)| price(e.owner, id) * n as f64).sum::<f64>()
                        + e.target_lost.iter().map(|&(id, n)| price(e.target, id) * n as f64).sum::<f64>();
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
/// How many units one login buys while an account is under its military share.
const DEFENCE_BATCH: u64 = 10;

/// The account's military share of its own host score, the number a persona's target is compared with (row 10).
pub(crate) fn military_share(w: &World, a: usize) -> f64 {
    let score = score::of(w, w.account_of(a));
    if score.total > 0 { score.military as f64 / score.total as f64 } else { 0.0 }
}

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
    // The dispatch pays the fuel out of the stock it finds, so a raid the account cannot fuel is not a candidate: the
    // menu must not offer an action the world refuses (measured: fuel 102 against 92.8 deuterium, row 10b).
    let distance = fleet::distance(&rules.settings, w.coords[a], w.coords[target], &|_, _| SystemState::Active);
    let held: combat::Units = fleet.iter().copied().collect();
    let fuel = fleet::consumption(&rules.settings, &rules.fleet, &fleet, &research, distance, 0, 10.0) as f64;
    if fuel > w.games[a].planet.res[2] || rules.combat.fuel_capacity(&held, &research) < fuel as i64 { return None }
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
    /// The share of the host's own military score this persona aims for (row 10, S4): from the real mil_share medians
    /// (top10 0.058, mid 0.16, bottom 0.066), a taste over host data that names no object.
    military_share: f64,
    /// Row 45 C: brings each colony's surplus home at every login, with cargo ships stationed on the colonies.
    pub consolidate: bool,
    /// Row 33: what the queues do not need goes to the yard at every login, in the persona's taste.
    pub yard: bool,
    /// Row 45 FR: keeps the fields a planet's field-adding chain still needs, and builds that chain when they run out.
    pub reserve: bool,
    /// The cargo ships the colonies lacked at the last consolidation: the yard's want grows by them.
    colony_cargo: i64,
    /// N6b arm: at each capital login the account fills its free expedition slots (`expedition_trips`).
    pub expeditions: bool,
    /// Row 70 arm: with every planet slot taken, the next research level that adds a slot comes before other research.
    pub colonies_first: bool,
    /// Row 71 arm: the military share is met with the kit's fighter, as a player's military score is ships; defence only
    /// while the yard cannot build one yet.
    pub ships: bool,
    /// Row 72 arm: with nothing to unlock, the lab works towards production and colonisation research; anything else is
    /// paid only from what the next building leaves.
    pub lab: bool,
    /// Row 73 arm: the yard's surplus spending stops at the persona's military share.
    pub cap: bool,
    /// Row 75 arm: the capital sends a colony what its next building lacks, from what the capital does not need.
    pub feed: bool,
}

impl Miner {
    pub fn new(seed: u64) -> Miner {
        Miner { rng: seed, cargo_share: 0.1, probes: 1, harvesters: 0, fighters: 0, eager: false, military_share: 0.06, consolidate: false, reserve: false, yard: false, colony_cargo: 0, expeditions: false, colonies_first: false, ships: false, lab: false, cap: false, feed: false }
    }

    /// The economy's pick for the deciding queue (None lets the login pass; at a login with nothing to build there is no
    /// pick), after any ship order. Only a capital orders ships: a colony only builds.
    pub fn act(&mut self, w: &mut World, cat: &Catalogue, a: usize) -> Option<usize> {
        let pick = match w.decision_kind(a) {
            Decision::Build => self.pick(w, cat, a),
            Decision::Idle => None,
        };
        let planet = &w.games[a].planet;
        let overflow = (0..3).any(|r| planet.res[r] >= 0.8 * planet.cap[r]);
        if self.consolidate && w.account_of(a) == a {
            self.bring_home(w, cat, a);
        }
        if self.feed && w.account_of(a) == a {
            feed_colonies(w, cat, a, pick);
        }
        // Under its military share the persona buys before the economy: the share is the top giveaway (row 10, S4).
        let short = military_share(w, a) < self.military_share;
        // Row 65: a capital waiting on a building the busy yard blocks lets the yard run dry so the building can start.
        let orders = w.account_of(a) == a && !(self.reserve && yard_blocks_reserve(w, cat, a));
        if orders && (pick.is_none() || overflow || self.eager || short || self.colony_cargo > 0) {
            self.order_ships(w, cat, a, pick);
        }
        if short && orders {
            self.order_defence(w, cat, a, pick);
        }
        // Row 73 arm: the surplus goes to the military only while the account is under its share (real mid accounts hold
        // a military share near 0.2; spending every surplus on it held 0.5, e74).
        if self.yard && orders && (short || !self.cap) {
            self.spend_surplus(w, cat, a, pick);
        }
        if self.expeditions && w.account_of(a) == a {
            expedition_trips(w, cat, a);
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

    /// The persona's build for the deciding queue, with no side effect: the field reserve's chain when it binds, a missing
    /// resource's producer, a wanted ship's prerequisite, then the player rule. Also the teacher a learner clones.
    pub fn pick(&mut self, w: &World, cat: &Catalogue, a: usize) -> Option<usize> {
        if let Some(chain) = self.reserve.then(|| field_reserve(w, cat, a)).flatten() {
            return chain;
        }
        let (queue, planet) = (w.games[a].queue, &w.games[a].planet);
        let fallback = match self.lab && queue == 1 {
            true => purpose(w, cat, a).or_else(|| cat.baseline(planet, queue, Baseline::Player, &mut self.rng).filter(|&k| spare(w, cat, a, k))),
            false => cat.baseline(planet, queue, Baseline::Player, &mut self.rng),
        };
        self.short(w, cat, a).or_else(|| self.unlock(w, cat, a)).or_else(|| fallback)
    }

    /// Row 29: why this login's yard order did or did not happen, asked of the same rule `act` uses: under its military
    /// share the persona buys, over it and with a store overflowing it still buys, and over it with a store that is not
    /// overflowing it buys nothing at all - the share stands as a ceiling while the surplus sits in the store.
    fn yard_stall(&self, w: &World, cat: &Catalogue, a: usize) -> YardStall {
        if military_share(w, a) < self.military_share { return YardStall::Order }
        let planet = &w.games[a].planet;
        let overflow = (0..3).any(|r| planet.cap[r] > 0.0 && planet.res[r] >= 0.8 * planet.cap[r]);
        if !overflow && !self.eager { return YardStall::ShareMet }
        let payable = |u: usize| w.rules[a].units.max_amount(&w.yards[a], planet, u) > 0;
        let ships = self.order_plans(w, cat, a).into_iter().any(|(u, _)| payable(u));
        let defence = (0..w.rules[a].units.units.len()).any(|u| w.rules[a].units.kind.get(u) == Some(&UnitKind::Defence) && payable(u));
        match ships || defence {
            true => YardStall::Order,
            false => YardStall::NothingPayable,
        }
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
                let held = w.planets_of(w.account_of(a)).len();
                if self.colonies_first && held >= t.max_planets(levels[k]) && cat.wait_seconds(&w.games[a].planet, k) == 0.0 {
                    // A colonising player researches the next slot whenever the slots are full and it can pay for it; it
                    // does not stop the lab to save for it (e73: saving starved every other research, gate 3).
                    if let Some(next) = (levels[k] + 1..t.max_planets.len() as u32).find(|&l| t.max_planets(l) > held) {
                        need.push((k, next));
                    }
                }
            }
        }
        need.into_iter().find_map(|(k, level)| {
            (levels[k] < level && cat.objects[k].queue == w.games[a].queue && w.legal(cat, a, k)).then_some(k)
        })
    }

    /// While an account is under its persona's military share it buys its military hull before economy, in a small batch.
    fn order_defence(&self, w: &mut World, cat: &Catalogue, a: usize, pick: Option<usize>) {
        let keep = saving(w, cat, a, pick);
        let Some(u) = self.hull(w, cat, a, self.ships) else { return };
        let amount = within(w, a, u, DEFENCE_BATCH, keep);
        if amount > 0 { w.order_units(cat, a, u, amount); }
    }

    /// The unit the persona buys for military: the kit's fighter when `fighter` and the yard can start one (a raider's
    /// always), else the cheapest unit the host marks as defence that the yard can start now. No object is named (gate 1).
    fn hull(&self, w: &World, cat: &Catalogue, a: usize, fighter: bool) -> Option<usize> {
        let rules = &w.rules[a];
        let buildable = |u: usize| rules.units.max_amount(&w.yards[a], &w.games[a].planet, u) > 0;
        let ship = fighter.then(|| Kit::of(w, cat, a)).flatten().and_then(|kit| rules.units.index(kit.fighter as i64));
        if let Some(u) = ship.filter(|&u| buildable(u) || self.fighters > 0) { return Some(u) }
        (0..rules.units.units.len())
            .filter(|&u| rules.units.kind.get(u) == Some(&UnitKind::Defence))
            .filter(|&u| buildable(u))
            .min_by(|&x, &y| weighted(rules.units.units[x].price).total_cmp(&weighted(rules.units.units[y].price)))
    }

    /// A player brings colony resources home to pay for research and the big builds (gate 3): each colony sends its
    /// surplus with the cargo ships stationed there, and the capital sends spare cargo ships to a colony short of them.
    fn bring_home(&mut self, w: &mut World, cat: &Catalogue, a: usize) {
        let Some(kit) = Kit::of(w, cat, a) else { return };
        self.colony_cargo = 0;
        for colony in w.planets_of(a).into_iter().skip(1) {
            let missing = w.consolidate(cat, colony, kit.cargo);
            let spare = missing.min(on_shelf(w, a, kit.cargo));
            if spare > 0 && w.dispatch(cat, a, colony, Mission::Deploy, &[(kit.cargo, spare)], [0.0; 3], 10.0).is_ok() {
                self.colony_cargo += missing - spare;
                continue;
            }
            self.colony_cargo += missing.max(0);
        }
    }

    /// Row 33: one spending order instead of share caps. The building queue's pick, any build a queue is saving for and
    /// two hours of production stay; the rest buys the persona's hull - the fighter a raider or saver flies, the cheapest
    /// defence for a miner - as many as it pays for (gate 3: a player does not sit on a full store; gate 1: `Kit` and
    /// the host's unit kinds say which unit, nothing is named).
    fn spend_surplus(&self, w: &mut World, cat: &Catalogue, a: usize, pick: Option<usize>) {
        let game = &w.games[a];
        let mut keep = saving(w, cat, a, pick);
        for r in 0..3 { keep[r] += 2.0 * game.planet.rate[r].max(0.0) }
        let rules = &w.rules[a];
        // The ships the persona already plans (the colony ship among them) are paid before the surplus (row 63: without
        // this the yard spent the colony ship's price and accounts held 6 planets instead of 8).
        for (u, amount) in self.order_plans(w, cat, a) {
            for r in 0..3 { keep[r] += rules.units.units[u].price[r] * amount as f64 }
        }
        let unit = self.hull(w, cat, a, self.fighters > 0 || self.ships);
        let Some(u) = unit else { return };
        let price = rules.units.units[u].price;
        let amount = (0..3).filter(|&r| price[r] > 0.0)
            .map(|r| ((game.planet.res[r] - keep[r]).max(0.0) / price[r]).floor())
            .fold(f64::INFINITY, f64::min);
        if amount.is_finite() && amount >= 1.0 {
            w.order_units(cat, a, u, amount as u64);
        }
    }

    fn order_ships(&self, w: &mut World, cat: &Catalogue, a: usize, pick: Option<usize>) {
        let keep = saving(w, cat, a, pick);
        for (u, amount) in self.order_plans(w, cat, a) {
            let amount = within(w, a, u, amount as u64, keep);
            if amount > 0 { w.order_units(cat, a, u, amount); }
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
        let trips = if self.expeditions { expedition_cargo(w, cat, a).map_or(0, |(n, slots)| n * slots as i64) } else { 0 };
        let wants = [(kit.probe, self.probes), (kit.cargo, (stock * self.cargo_share / hold as f64).ceil() as i64 + self.colony_cargo + trips), (kit.harvester, self.harvesters), (kit.fighter, self.fighters)];
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

/// Row 64: the moon shot a planet of account a would receive or send: the kit's fighters whose debris reaches the host's
/// maximum moon chance, and their hull. The sizing reads the host's combat rules and the kit, nothing named (gate 1).
fn shot_size(w: &World, cat: &Catalogue, a: usize) -> Option<(u32, i64, f64)> {
    let kit = Kit::of(w, cat, a)?;
    let rules = &w.rules[a];
    let debris = &rules.combat.rules;
    let price = rules.units.units[rules.units.index(kit.fighter as i64)?].price;
    let each: f64 = (0..if debris.debris_deuterium { 3 } else { 2 }).map(|r| (price[r] * debris.debris_ships_percentage / 100.0).floor()).sum();
    if each <= 0.0 || debris.maximum_moon_chance <= 0.0 { return None }
    let n = (debris.maximum_moon_chance * 100_000.0 / each).ceil() as i64;
    Some((kit.fighter, n, strength(rules, &w.research(cat, a), &[(kit.fighter, n)]).1))
}

/// Attack per round of everything on planet p that fights.
fn guns(w: &World, cat: &Catalogue, p: usize) -> f64 {
    strength(&w.rules[p], &w.research(cat, p), &stack(&w.rules[p], &w.yards[p].counts)).0
}

/// What planet a keeps from the yard: the builds it saves for and two hours of production.
fn kept(w: &World, cat: &Catalogue, a: usize) -> [f64; 3] {
    let mut keep = saving(w, cat, a, None);
    for r in 0..3 { keep[r] += 2.0 * w.games[a].planet.rate[r].max(0.0) }
    keep
}

/// Row 64: a moon shot for an alliance mate, the way players make moons: the shot `shot_size` gives, sent at the nearest
/// mate's planet that has no moon and guns to shoot it all down in three rounds (the moon forms at the receiver). Short
/// of fighters, the shooter orders them from what its queues do not need. Returns whether a shot left.
pub fn moon_shot(w: &mut World, cat: &Catalogue, a: usize, mate: impl Fn(usize) -> bool, ledger: &mut Ledger) -> bool {
    let Some((fighter, n, hull)) = shot_size(w, cat, a) else { return false };
    let receives = |p: usize| !w.moons[p] && !w.fleets.iter().any(|f| f.target == p && f.mission == Mission::Attack && !f.returning) && guns(w, cat, p) * 3.0 >= hull;
    let Some(target) = others_by_distance(w, a).into_iter().filter(|&b| mate(b)).flat_map(|b| w.planets_of(b)).find(|&p| receives(p)) else { return false };
    if on_shelf(w, a, fighter) >= n {
        return send(w, cat, a, target, Mission::Attack, &[(fighter, n)], 10.0, ledger).is_ok();
    }
    let missing = n - owned(w, a, fighter);
    let Some(u) = w.rules[a].units.index(fighter as i64) else { return false };
    if missing <= 0 || yard_blocks_reserve(w, cat, a) { return false }
    let amount = within(w, a, u, missing as u64, kept(w, cat, a));
    if amount > 0 { w.order_units(cat, a, u, amount); }
    false
}

/// Row 67: an alliance member readies its first planet with no moon to receive a mate's shot: the cheapest defence the
/// host has, enough to down the shot in three rounds, paid from that planet's own stock above what it keeps.
pub fn ready_for_moon(w: &mut World, cat: &Catalogue, a: usize) {
    let Some((_, _, hull)) = shot_size(w, cat, a) else { return };
    let Some(p) = w.planets_of(a).into_iter().find(|&p| !w.moons[p] && guns(w, cat, p) * 3.0 < hull) else { return };
    let rules = Arc::clone(&w.rules[p]);
    let Some(u) = (0..rules.units.units.len())
        .filter(|&u| rules.units.kind.get(u) == Some(&UnitKind::Defence) && rules.units.requirements_met(&w.games[p].planet, u))
        .min_by(|&x, &y| weighted(rules.units.units[x].price).total_cmp(&weighted(rules.units.units[y].price)))
    else { return };
    let each = strength(&rules, &w.research(cat, p), &[(rules.units.units[u].id as u32, 1)]).0;
    if each <= 0.0 { return }
    let missing = ((hull / 3.0 - guns(w, cat, p)) / each).ceil().max(0.0) as u64;
    let amount = within(w, p, u, missing, kept(w, cat, p));
    if amount > 0 { w.order_units(cat, p, u, amount); }
}

/// N6b: the cargo ships one expedition takes and the account's expedition slots: a hold for the largest plain find of
/// the host's bracket at the world's rank-1 score (class and reward multipliers included), and the host's slot limit
/// at the account's research level. `None` before the account may fly one. Read from the host's tables (gate 1).
fn expedition_cargo(w: &World, cat: &Catalogue, a: usize) -> Option<(i64, usize)> {
    let rules = &w.rules[a];
    let table = rules.expedition.as_ref()?;
    let colonisation = w.colonisation.as_ref()?;
    let research = w.research(cat, a);
    let slots = colonisation.max_expeditions(research.get(&(colonisation.research as u32)).copied().unwrap_or(0))?;
    if slots == 0 { return None }
    let largest = table.find_brackets.iter().find(|(below, _)| w.rank1_points < *below).or(table.find_brackets.last())?.1;
    let find = largest as f64 * table.class_resource_multiplier * table.reward_multiplier.resources;
    let kit = Kit::of(w, cat, a)?;
    let hold = rules.combat.capacity(&[(kit.cargo, 1)].into_iter().collect(), &research).max(1);
    Some(((find / hold as f64).ceil() as i64, slots))
}

/// N6b: a player sends an expedition from its capital whenever a slot is free (gate 3): cargo ships for the largest
/// find, to deep space in its own system, held an hour. The world refuses past the host's slot limit or without fuel.
fn expedition_trips(w: &mut World, cat: &Catalogue, a: usize) {
    let Some((n, slots)) = expedition_cargo(w, cat, a) else { return };
    let Some(kit) = Kit::of(w, cat, a) else { return };
    let deep = Coord { position: 16, ..w.coords[a] };
    for _ in 0..slots {
        let ships = n.min(on_shelf(w, a, kit.cargo));
        if ships < 1 || w.expedition(cat, a, deep, &[(kit.cargo, ships)], [0.0; 3], 10.0, 1).is_err() { return }
    }
}

const RESEARCH_LEAD: u32 = 3;

/// What planet a is saving for: the builds its queues committed and wait to afford, and this login's pick. A player
/// saving for a build does not spend that money in the yard (row 34: the persona's ship orders spent a committed build's
/// price at every login, so the build never started and the learner's queue asked again 13,876 times in one season).
fn saving(w: &World, cat: &Catalogue, a: usize, pick: Option<usize>) -> [f64; 3] {
    let game = &w.games[a];
    let mut keep = [0.0; 3];
    let mut seen: Vec<usize> = Vec::new();
    for &k in game.commit.iter().flatten().chain(pick.iter()) {
        if seen.contains(&k) { continue }
        seen.push(k);
        let price = cat.objects[k].price[game.planet.levels[k] as usize];
        for r in 0..3 { keep[r] += price[r] }
    }
    keep
}

/// Row 75: a player builds its colonies up with the capital's surplus (gate 3): a colony that cannot pay its next
/// building (the one it saves for, else the player rule's) gets what it lacks by transport, when the capital holds that
/// beyond what it saves for itself and has the cargo ships at home.
fn feed_colonies(w: &mut World, cat: &Catalogue, a: usize, pick: Option<usize>) {
    let Some(kit) = Kit::of(w, cat, a) else { return };
    let hold = w.rules[a].combat.capacity(&[(kit.cargo, 1)].into_iter().collect(), &w.research(cat, a)).max(1) as f64;
    let keep = saving(w, cat, a, pick);
    for colony in w.planets_of(a).into_iter().skip(1) {
        let planet = &w.games[colony].planet;
        let Some(next) = w.games[colony].commit[0].or_else(|| cat.baseline(planet, 0, Baseline::Player, &mut 0)) else { continue };
        let price = cat.objects[next].price[planet.levels[next] as usize];
        let lack: [f64; 3] = std::array::from_fn(|r| (price[r] - planet.res[r]).max(0.0).ceil());
        let total: f64 = lack.iter().sum();
        let home = w.games[a].planet.res;
        if total <= 0.0 || (0..3).any(|r| home[r] - keep[r] < lack[r]) { continue }
        let ships = (total / hold).ceil() as i64;
        if ships > on_shelf(w, a, kit.cargo) { continue }
        let _ = w.dispatch(cat, a, colony, Mission::Transport, &[(kit.cargo, ships)], lack, 10.0);
    }
}

/// Row 72: the research a player works towards when nothing else is wanted, saving for it if need be: what raises the
/// planet's output and the colonisation research (planets and expedition slots), the cheapest first. Found by the host's
/// production and colonisation tables, nothing named (gate 1).
fn purpose(w: &World, cat: &Catalogue, a: usize) -> Option<usize> {
    let planet = &w.games[a].planet;
    let output = |p: &Planet| weighted(cat.production(p, true).0);
    let now = output(planet);
    let colonise = w.colonisation.as_ref().map(|t| t.research);
    let raises = |k: usize| { let mut next = planet.clone(); next.levels[k] += 1; cat.refresh(&mut next); output(&next) > now };
    let price = |k: usize| weighted(cat.objects[k].price[planet.levels[k] as usize]);
    (0..cat.objects.len())
        .filter(|&k| cat.objects[k].queue == 1 && w.legal(cat, a, k))
        .filter(|&k| Some(cat.objects[k].id) == colonise || raises(k))
        .min_by(|&x, &y| price(x).total_cmp(&price(y)))
}

/// Whether planet a pays for research k and still holds the price of its next building (the one it saves for, else the
/// player rule's): a player feeds the mines first and researches from the rest (row 72, gate 3).
fn spare(w: &World, cat: &Catalogue, a: usize, k: usize) -> bool {
    let planet = &w.games[a].planet;
    let next = w.games[a].commit[0].or_else(|| cat.baseline(planet, 0, Baseline::Player, &mut 0));
    let keep = next.map_or([0.0; 3], |b| cat.objects[b].price[planet.levels[b] as usize]);
    let price = cat.objects[k].price[planet.levels[k] as usize];
    (0..3).all(|r| planet.res[r] - keep[r] >= price[r])
}

/// How many of unit u, up to `amount`, planet a pays for while keeping `keep` in its store.
fn within(w: &World, a: usize, u: usize, amount: u64, keep: [f64; 3]) -> u64 {
    let price = w.rules[a].units.units[u].price;
    let res = w.games[a].planet.res;
    (0..3).filter(|&r| price[r] > 0.0)
        .map(|r| ((res[r] - keep[r]).max(0.0) / price[r]).floor() as u64)
        .fold(amount, u64::min)
}

/// Whether planet a's field reserve binds on a building the host refuses while the yard is busy (the shipyard or the
/// nanite factory under the terraformer): with the yard never idle the building queue waited the whole season (row 65).
fn yard_blocks_reserve(w: &World, cat: &Catalogue, a: usize) -> bool {
    let planet = &w.games[a].planet;
    let Some((chain, fields)) = field_chain(cat, planet) else { return false };
    if cat.field_cap(planet).saturating_sub(cat.fields_used(planet)) > fields { return false }
    chain.iter().any(|&(k, level)| planet.levels[k] < level && cat.objects[k].queue == 0 && cat.legal(planet, k) && w.rules[a].units.blocked.contains(&k))
}

/// A planet's field-adding chain: the next level of the cheapest object that adds fields, and every unmet requirement
/// under it, prerequisites first (gate 1: `fields_added` and `requires` say which objects, nothing is named). Returns
/// the chain and the fields its unbuilt levels still consume.
fn field_chain(cat: &Catalogue, planet: &Planet) -> Option<(Vec<(usize, u32)>, u32)> {
    let adds = |k: usize, l: usize| cat.objects[k].fields_added.get(l).copied().unwrap_or(0);
    let adder = (0..cat.objects.len())
        .filter(|&k| adds(k, planet.levels[k] as usize + 1) > adds(k, planet.levels[k] as usize))
        .min_by(|&x, &y| weighted(cat.objects[x].price[planet.levels[x] as usize]).total_cmp(&weighted(cat.objects[y].price[planet.levels[y] as usize])))?;
    let mut chain = vec![(adder, planet.levels[adder] + 1)];
    let mut at = 0;
    while at < chain.len() && chain.len() < 256 {
        let k = chain[at].0;
        at += 1;
        for &(need, level) in &cat.objects[k].requires {
            let Some(need) = need else { continue };
            if planet.levels[need] >= level { continue }
            match chain.iter_mut().find(|c| c.0 == need) {
                Some(c) => c.1 = c.1.max(level),
                None => chain.push((need, level)),
            }
        }
    }
    chain.reverse();
    let fields = chain.iter().filter(|&&(k, _)| cat.objects[k].consumes_field).map(|&(k, l)| l - planet.levels[k]).sum();
    Some((chain, fields))
}

/// Row 45 FR: a player keeps a planet's last fields for the field-adding chain (gate 3). When a planet's free fields are
/// down to what its chain still consumes, its building queue builds the chain's next startable object (or waits for
/// one), and the capital's research queue researches the chain of whichever of its planets is short. None leaves the
/// pick to the ordinary rule.
fn field_reserve(w: &World, cat: &Catalogue, a: usize) -> Option<Option<usize>> {
    let queue = w.games[a].queue;
    // The lab starts the chain while a planet still has RESEARCH_LEAD times its reserve free: research takes weeks, and a
    // building queue that waits on it at the last fields idles for them (row 46: the stack lost C's growth that way).
    let lead = if queue == 0 { 1 } else { RESEARCH_LEAD };
    let short = |slot: usize| {
        let planet = &w.games[slot].planet;
        let (chain, fields) = field_chain(cat, planet)?;
        (cat.field_cap(planet).saturating_sub(cat.fields_used(planet)) <= lead * fields).then_some(chain)
    };
    let slots = match queue {
        0 => vec![a],
        _ => w.planets_of(w.account_of(a)),
    };
    let chains: Vec<Vec<(usize, u32)>> = slots.into_iter().filter_map(short).collect();
    if chains.is_empty() { return None }
    let levels = &w.games[a].planet.levels;
    let next = chains.iter().flatten()
        .find(|&&(k, level)| levels[k] < level && cat.objects[k].queue == queue && w.legal(cat, a, k))
        .map(|&(k, _)| k);
    // The research queue keeps its own rule when no chain needs research now; the building queue waits rather than
    // spend a reserved field.
    match (queue, next) {
        (0, _) => Some(next),
        (_, Some(k)) => Some(Some(k)),
        _ => None,
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
        let miner = Miner { rng: seed, cargo_share: 0.2, probes: 4, harvesters: 3, fighters: 30, eager: true, military_share: 0.30, consolidate: false, reserve: false, yard: false, colony_cargo: 0, expeditions: false, colonies_first: false, ships: false, lab: false, cap: false, feed: false };
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
        let mut miner = Miner::new(seed);
        miner.military_share = 0.20;
        FleetSaver { miner, answered: HashSet::new(), saving: Vec::new() }
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
    /// The economy every persona shares.
    pub fn miner(&mut self) -> &mut Miner {
        match self {
            Player::Miner(m) => m,
            Player::Raider(r) => &mut r.miner,
            Player::Saver(s) => &mut s.miner,
        }
    }

    /// What the account does at its deciding login: the economy's pick for the queue.
    pub fn act(&mut self, w: &mut World, cat: &Catalogue, a: usize, ledger: &mut Ledger) -> Option<usize> {
        ledger.scan(w, cat);
        match self {
            Player::Miner(p) => p.act(w, cat, a),
            Player::Raider(p) => p.act(w, cat, a, ledger),
            Player::Saver(p) => p.act(w, cat, a, ledger),
        }
    }

    /// Row 29: why the yard does or does not order at this login, in the persona's own terms.
    pub fn yard_stall(&self, w: &World, cat: &Catalogue, a: usize) -> YardStall {
        match self {
            Player::Miner(p) => p.yard_stall(w, cat, a),
            Player::Raider(p) => p.miner.yard_stall(w, cat, a),
            Player::Saver(p) => p.miner.yard_stall(w, cat, a),
        }
    }
}

/// Row 29: what stops a login's yard order. `ShareMet` is row 10's `military_share` standing as a ceiling - the account
/// holds its share, so it buys nothing however full the store is; `NothingPayable` is a want the stock cannot pay for.
#[derive(Clone, Copy, PartialEq, Eq)]
pub enum YardStall {
    Order,
    ShareMet,
    NothingPayable,
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
        // Printed, not asserted (row 10c, planner decision): with defence in both worlds the hostile twin rebuilds what
        // raids destroy, so its compounding diverges from the quiet twin by more than any fixed share of the base. Exact
        // conservation is `world::tests`' account-that-never-builds check, which is untouched.
        println!("seed {seed}: world {v:.0} vs quiet {q:.0}: gap {:.0}, raids moved {moved:.0}", (v - q).abs());
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

    /// Row 45 FR: a planet whose free fields are down to what its field-adding chain still consumes builds that chain,
    /// prerequisite first, and spends no reserved field on anything else; with fields to spare the ordinary rule picks.
    #[test]
    fn a_planet_at_its_field_reserve_builds_the_field_adding_chain() {
        let Some((cat, mut world)) = bare(5) else { return eprintln!("no catalogue; skipped") };
        world.games[0].queue = 0;
        assert!(field_reserve(&world, &cat, 0).is_none(), "a fresh planet has fields to spare");
        let (chain, fields) = field_chain(&cat, &world.games[0].planet).expect("an object that adds fields");
        let planet = &mut world.games[0].planet;
        let mut free = cat.field_cap(planet) - cat.fields_used(planet) - fields;
        for k in (0..cat.objects.len()).filter(|&k| cat.objects[k].consumes_field && cat.objects[k].queue == 0 && !chain.iter().any(|c| c.0 == k)) {
            let room = (cat.objects[k].price.len() as u32 - 1).saturating_sub(planet.levels[k]).min(free);
            planet.levels[k] += room;
            free -= room;
        }
        assert_eq!(free, 0, "the catalogue cannot fill the planet off the chain");
        cat.refresh(planet);
        planet.res = [1e12; 3];
        let pick = field_reserve(&world, &cat, 0).expect("the reserve binds").expect("a chain object is startable");
        assert!(chain.iter().any(|c| c.0 == pick), "object {pick} is off the chain");
        assert!(cat.objects[pick].requires.iter().all(|&(k, l)| k.is_none_or(|k| world.games[0].planet.levels[k] >= l)), "a prerequisite comes first");
    }

    /// Row 66: a capital with a reach of one colonises in its home galaxy; with a wider reach its next colony goes to the
    /// least held galaxy within it, nearest first.
    #[test]
    fn a_wider_reach_spreads_colonies_over_galaxies() {
        let Ok(mut cat) = Catalogue::load(&format!("{DATA}/units-parity/econ-u1-colonies.json")) else { return eprintln!("no catalogue; skipped") };
        cat.login_gaps = vec![600.0, 3600.0];
        let Ok(rules) = Rules::load(&cat, &format!("{DATA}/units-parity/units-u1.json"), &format!("{DATA}/fleet-settings-u1.json"), &format!("{DATA}/combat-u1.json")) else { return eprintln!("no rules; skipped") };
        let mut world = World::new(&cat, &[0], HOURS, 0.0, 5);
        world.arm(vec![Arc::new(rules)], vec![Coord { galaxy: 3, system: 100, position: 8 }]);
        let table = Arc::new(crate::world::Colonisation::load(&format!("{DATA}/units-parity/econ-u1-colonies.json")).expect("a colonisation table"));
        let k = cat.objects.iter().position(|o| o.id == table.research).expect("the colonisation research");
        world.games[0].planet.levels[k] = 10;
        world.colonisation = Some(table);
        assert_eq!(world.colony_site(&cat, 0).expect("a site").galaxy, 3, "a reach of one stays home");
        world.reach = vec![3];
        // The home galaxy fills to three planets (the capital among them) before the nearest neighbour opens.
        let mut taken = Vec::new();
        for system in 0..4 {
            let to = world.colony_site(&cat, 0).expect("a site");
            taken.push(to.galaxy);
            world.add_planet(&cat, 0, 0, Coord { system: to.system + 200 + system, ..to }, 0.0);
        }
        assert_eq!(taken, vec![3, 3, 4, 4], "a galaxy fills before the next one opens");
    }

    /// Row 70: with every planet slot taken, a colonising player's lab takes the next level that adds a slot; with a slot
    /// still free, or without the arm, the research waits its turn.
    #[test]
    fn a_player_with_every_slot_taken_researches_the_next_slot_first() {
        let Ok(mut cat) = Catalogue::load(&format!("{DATA}/units-parity/econ-u1-colonies.json")) else { return eprintln!("no catalogue; skipped") };
        cat.login_gaps = vec![600.0, 3600.0];
        let Ok(rules) = Rules::load(&cat, &format!("{DATA}/units-parity/units-u1.json"), &format!("{DATA}/fleet-settings-u1.json"), &format!("{DATA}/combat-u1.json")) else { return eprintln!("no rules; skipped") };
        let mut world = World::new(&cat, &[0], HOURS, 0.0, 5);
        world.arm(vec![Arc::new(rules)], vec![Coord { galaxy: 3, system: 100, position: 8 }]);
        let table = Arc::new(crate::world::Colonisation::load(&format!("{DATA}/units-parity/econ-u1-colonies.json")).expect("a colonisation table"));
        let k = cat.objects.iter().position(|o| o.id == table.research).expect("the colonisation research");
        // Everything else well past any requirement, so only the slot rule can name the research.
        for (j, level) in world.games[0].planet.levels.iter_mut().enumerate() {
            if j != k { *level = (*level).max(12) }
        }
        let level = (0..table.max_planets.len() as u32).find(|&l| table.max_planets(l) == 2).expect("a level with two planets");
        world.games[0].planet.levels[k] = level;
        world.games[0].planet.res = [1e12; 3];
        world.games[0].queue = cat.objects[k].queue;
        world.colonisation = Some(table);
        cat.refresh(&mut world.games[0].planet);
        assert!(world.legal(&cat, 0, k), "the research can start");
        let mut miner = Miner::new(1);
        miner.colonies_first = true;
        assert_eq!(miner.unlock(&world, &cat, 0), None, "a slot is still free");
        world.add_planet(&cat, 0, 0, Coord { galaxy: 3, system: 101, position: 8 }, 0.0);
        assert_eq!(miner.unlock(&world, &cat, 0), Some(k), "every slot taken: the next slot first");
        world.games[0].planet.res = [0.0; 3];
        assert_eq!(miner.unlock(&world, &cat, 0), None, "the lab does not wait to save for it");
        world.games[0].planet.res = [1e12; 3];
        miner.colonies_first = false;
        assert_eq!(miner.unlock(&world, &cat, 0), None, "without the arm the research waits its turn");
    }

    /// Row 72: the lab's research without a purpose is paid only when the next building's price stays in the store.
    #[test]
    fn research_without_a_purpose_leaves_the_next_buildings_price() {
        let Some((cat, mut world)) = bare(1) else { return };
        // A grown planet, so the lab stands and research is legal.
        for level in world.games[0].planet.levels.iter_mut() { *level = (*level).max(5) }
        cat.refresh(&mut world.games[0].planet);
        let research = (0..cat.objects.len()).find(|&k| cat.objects[k].queue == 1 && cat.legal(&world.games[0].planet, k)).expect("a research");
        let planet = &world.games[0].planet;
        let price = |k: usize| cat.objects[k].price[planet.levels[k] as usize];
        let building = cat.baseline(planet, 0, Baseline::Player, &mut 0).expect("a building");
        let (r, b) = (price(research), price(building));
        world.games[0].planet.res = r;
        assert!(!spare(&world, &cat, 0, research), "research took the next building's price");
        world.games[0].planet.res = [r[0] + b[0], r[1] + b[1], r[2] + b[2]];
        assert!(spare(&world, &cat, 0, research), "research refused with both prices in the store");
    }

    /// Row 75: a colony that cannot pay its next building gets what it lacks from a rich capital; a poor capital sends
    /// nothing.
    #[test]
    fn a_rich_capital_feeds_a_colonys_next_building() {
        let Ok(cat) = Catalogue::load(&format!("{DATA}/units-parity/econ-u1-colonies.json")) else { return eprintln!("no catalogue; skipped") };
        let Ok(rules) = Rules::load(&cat, &format!("{DATA}/units-parity/units-u1.json"), &format!("{DATA}/fleet-settings-u1.json"), &format!("{DATA}/combat-u1.json")) else { return eprintln!("no rules; skipped") };
        let rules = Arc::new(rules);
        let setup = |rich: bool| {
            let mut world = World::new(&cat, &[0], HOURS, 0.0, 5);
            world.arm(vec![Arc::clone(&rules)], vec![Coord { galaxy: 3, system: 100, position: 8 }]);
            let colony = world.add_planet(&cat, 0, 0, Coord { galaxy: 3, system: 101, position: 8 }, 0.0);
            world.games[colony].planet.res = [0.0; 3];
            world.games[0].planet.res = if rich { [1e9; 3] } else { [0.0; 3] };
            let kit = Kit::of(&world, &cat, 0).expect("a kit");
            let u = world.rules[0].units.index(kit.cargo as i64).expect("the cargo ship");
            world.yards[0].counts[u] = 100;
            feed_colonies(&mut world, &cat, 0, None);
            world.fleets.iter().filter(|f| f.owner == 0 && f.target == colony && f.mission == Mission::Transport).map(|f| f.cargo.iter().sum::<f64>()).sum::<f64>()
        };
        assert!(setup(true) > 0.0, "a rich capital sent its colony nothing");
        assert_eq!(setup(false), 0.0, "a poor capital sent its colony resources");
    }

    /// Row 74: every home config carries its owner's class, as the production rows were made with it.
    #[test]
    fn a_home_config_carries_its_owners_class() {
        let Ok(cat) = Catalogue::load(&format!("{DATA}/units-parity/econ-u1-colonies.json")) else { return eprintln!("no catalogue; skipped") };
        let classes: HashSet<Option<u32>> = cat.planets.iter().filter(|c| c.planet_id > 0).map(|c| c.character_class).collect();
        assert!(!classes.contains(&None), "a home without its owner's class");
        assert!(classes.len() > 1, "every home has the same class: {classes:?}");
    }

    /// Row 72: the lab's purpose is the cheapest research that raises output or is the colonisation research; a cheaper
    /// research that does neither (a combat technology) is passed over.
    #[test]
    fn the_lab_works_towards_output_and_colonies() {
        let Ok(cat) = Catalogue::load(&format!("{DATA}/units-parity/econ-u1-colonies.json")) else { return eprintln!("no catalogue; skipped") };
        let Ok(rules) = Rules::load(&cat, &format!("{DATA}/units-parity/units-u1.json"), &format!("{DATA}/fleet-settings-u1.json"), &format!("{DATA}/combat-u1.json")) else { return eprintln!("no rules; skipped") };
        let mut world = World::new(&cat, &[0], HOURS, 0.0, 5);
        world.arm(vec![Arc::new(rules)], vec![Coord { galaxy: 3, system: 100, position: 8 }]);
        world.colonisation = crate::world::Colonisation::load(&format!("{DATA}/units-parity/econ-u1-colonies.json")).ok().map(Arc::new);
        for level in world.games[0].planet.levels.iter_mut() { *level = (*level).max(12) }
        world.games[0].queue = 1;
        cat.refresh(&mut world.games[0].planet);
        let planet = world.games[0].planet.clone();
        let output = |p: &Planet| weighted(cat.production(p, true).0);
        let raises = |k: usize| { let mut next = planet.clone(); next.levels[k] += 1; cat.refresh(&mut next); output(&next) > output(&planet) };
        let colonise = world.colonisation.as_ref().map(|t| t.research);
        let price = |k: usize| weighted(cat.objects[k].price[planet.levels[k] as usize]);
        let k = purpose(&world, &cat, 0).expect("a research with a purpose");
        assert!(raises(k) || Some(cat.objects[k].id) == colonise, "the purpose neither raises output nor colonises");
        let passed = (0..cat.objects.len()).filter(|&j| cat.objects[j].queue == 1 && world.legal(&cat, 0, j) && price(j) < price(k)).count();
        assert!(passed > 0, "no cheaper research was passed over, so the test shows nothing");
    }

    /// N6b: the world refuses an expedition beyond the host's slot limit at the account's research level, counting the
    /// account's expeditions still out (the way home included); without a slot table it sets no limit.
    #[test]
    fn an_expedition_beyond_the_slot_limit_is_refused() {
        let Ok(mut cat) = Catalogue::load(&format!("{DATA}/units-parity/econ-u1-colonies.json")) else { return eprintln!("no catalogue; skipped") };
        cat.login_gaps = vec![600.0, 3600.0];
        let Ok(rules) = Rules::load(&cat, &format!("{DATA}/units-parity/units-u1.json"), &format!("{DATA}/fleet-settings-u1.json"), &format!("{DATA}/combat-u1.json")) else { return eprintln!("no rules; skipped") };
        if rules.expedition.is_none() { return eprintln!("no expedition tables; skipped") }
        let mut world = World::new(&cat, &[0], HOURS, 0.0, 5);
        world.arm(vec![Arc::new(rules)], vec![Coord { galaxy: 3, system: 100, position: 8 }]);
        let mut table = crate::world::Colonisation::load(&format!("{DATA}/units-parity/econ-u1-colonies.json")).expect("a colonisation table");
        table.max_expeditions = vec![0, 1, 1, 1, 2];
        let k = cat.objects.iter().position(|o| o.id == table.research).expect("the colonisation research");
        world.games[0].planet.levels[k] = 1;
        world.colonisation = Some(Arc::new(table));
        let kit = Kit::of(&world, &cat, 0).expect("a cargo hull");
        assert!(world.set_units(&cat, 0, kit.cargo, 100), "cargo ships to send");
        world.games[0].planet.res = [1e7; 3];
        let deep = Coord { galaxy: 3, system: 100, position: 16 };
        assert!(world.expedition(&cat, 0, deep, &[(kit.cargo, 10)], [0.0; 3], 10.0, 1).is_ok(), "one slot at level 1");
        assert_eq!(world.expedition(&cat, 0, deep, &[(kit.cargo, 10)], [0.0; 3], 10.0, 1), Err("too many expeditions"));
    }

    /// N6b: a player fills its expedition slots at a capital login with cargo for the bracket's largest find, and sends
    /// no more while the slot is taken.
    #[test]
    fn a_player_fills_its_expedition_slots_with_cargo_for_the_largest_find() {
        let Ok(mut cat) = Catalogue::load(&format!("{DATA}/units-parity/econ-u1-colonies.json")) else { return eprintln!("no catalogue; skipped") };
        cat.login_gaps = vec![600.0, 3600.0];
        let Ok(rules) = Rules::load(&cat, &format!("{DATA}/units-parity/units-u1.json"), &format!("{DATA}/fleet-settings-u1.json"), &format!("{DATA}/combat-u1.json")) else { return eprintln!("no rules; skipped") };
        if rules.expedition.is_none() { return eprintln!("no expedition tables; skipped") }
        let mut world = World::new(&cat, &[0], HOURS, 0.0, 5);
        world.arm(vec![Arc::new(rules)], vec![Coord { galaxy: 3, system: 100, position: 8 }]);
        let mut table = crate::world::Colonisation::load(&format!("{DATA}/units-parity/econ-u1-colonies.json")).expect("a colonisation table");
        table.max_expeditions = vec![0, 1, 1, 1, 2];
        let k = cat.objects.iter().position(|o| o.id == table.research).expect("the colonisation research");
        world.games[0].planet.levels[k] = 1;
        world.colonisation = Some(Arc::new(table));
        let kit = Kit::of(&world, &cat, 0).expect("a cargo hull");
        assert!(world.set_units(&cat, 0, kit.cargo, 10_000), "cargo ships to send");
        world.games[0].planet.res = [1e7; 3];
        let (n, slots) = expedition_cargo(&world, &cat, 0).expect("an expedition is possible");
        assert_eq!(slots, 1);
        expedition_trips(&mut world, &cat, 0);
        let out: Vec<_> = world.fleets.iter().filter(|f| f.mission == Mission::Expedition).collect();
        assert_eq!(out.len(), 1, "one slot, one expedition");
        assert_eq!(out[0].ships, vec![(kit.cargo, n)], "the hold for the largest find");
        expedition_trips(&mut world, &cat, 0);
        assert_eq!(world.fleets.iter().filter(|f| f.mission == Mission::Expedition).count(), 1, "no second trip while the slot is taken");
    }

    /// Row 64: an alliance member holding the fighters shoots a moon at a mate whose guns can take them down, sized to the
    /// host's maximum moon chance; one shot at a time per planet, and a mate without the guns is not shot at.
    #[test]
    fn a_mate_with_guns_receives_a_moon_shot_sized_to_the_maximum_chance() {
        let Some((cat, mut world)) = bare(5) else { return eprintln!("no catalogue; skipped") };
        let kit = Kit::of(&world, &cat, 0).expect("a fighter hull");
        let rules = Arc::clone(&world.rules[0]);
        let gun = (0..rules.units.units.len()).find(|&u| rules.units.kind.get(u) == Some(&UnitKind::Defence)).expect("a defence");
        let mut ledger = Ledger::default();
        assert!(world.set_units(&cat, 0, kit.fighter, 100_000), "the shooter holds fighters");
        world.games[0].planet.res = [1e9; 3];
        assert!(!moon_shot(&mut world, &cat, 0, |b| b == 1, &mut ledger), "a mate without guns would let the fighters land");
        assert!(world.set_units(&cat, 1, rules.units.units[gun].id as u32, 1_000_000), "the mate holds guns");
        assert!(moon_shot(&mut world, &cat, 0, |b| b == 1, &mut ledger), "the shot left");
        let shot = world.fleets.iter().find(|f| f.owner == 0 && f.target == 1 && f.mission == Mission::Attack).expect("an attack on the mate");
        let price = rules.units.units[rules.units.index(kit.fighter as i64).unwrap()].price;
        let debris = &rules.combat.rules;
        let each: f64 = (0..2).map(|r| (price[r] * debris.debris_ships_percentage / 100.0).floor()).sum();
        let sent = shot.ships.iter().map(|s| s.1).sum::<i64>() as f64;
        assert!(sent * each >= debris.maximum_moon_chance * 100_000.0 && (sent - 1.0) * each < debris.maximum_moon_chance * 100_000.0, "{sent} fighters");
        assert!(!moon_shot(&mut world, &cat, 0, |b| b == 1, &mut ledger), "one shot at a time");
        assert!(!moon_shot(&mut world, &cat, 0, |b| b == 2, &mut ledger), "only a mate is shot at");
    }

    /// Row 65: a capital whose field reserve waits on a building the busy yard blocks orders no more units, so the yard
    /// runs dry and the building can start.
    #[test]
    fn a_reserve_waiting_on_a_yard_blocked_building_stops_the_yard() {
        let Some((cat, mut world)) = bare(5) else { return eprintln!("no catalogue; skipped") };
        world.games[0].queue = 0;
        let (chain, fields) = field_chain(&cat, &world.games[0].planet).expect("an object that adds fields");
        // A yard to keep busy: the blocked buildings off the chain (the shipyard) before the fill takes the fields.
        for k in world.rules[0].units.blocked.clone().into_iter().filter(|k| !chain.iter().any(|c| c.0 == *k)) {
            world.games[0].planet.levels[k] = 2;
        }
        let planet = &mut world.games[0].planet;
        let mut free = cat.field_cap(planet) - cat.fields_used(planet) - fields;
        for k in (0..cat.objects.len()).filter(|&k| cat.objects[k].consumes_field && cat.objects[k].queue == 0 && !chain.iter().any(|c| c.0 == k)) {
            let room = (cat.objects[k].price.len() as u32 - 1).saturating_sub(planet.levels[k]).min(free);
            planet.levels[k] += room;
            free -= room;
        }
        cat.refresh(planet);
        planet.res = [1e12; 3];
        // The chain's prerequisites built, so the building the yard blocks is the one left to start.
        let Some(&(blocked, _)) = chain.iter().find(|c| world.rules[0].units.blocked.contains(&c.0)) else { return eprintln!("no yard-blocked building on the chain; skipped") };
        for &(k, level) in chain.iter().filter(|c| cat.objects[blocked].requires.iter().any(|r| r.0 == Some(c.0))) {
            world.games[0].planet.levels[k] = world.games[0].planet.levels[k].max(level);
        }
        // Research takes no fields: levels that make the yard's units buildable leave the reserve as it was.
        for k in (0..cat.objects.len()).filter(|&k| cat.objects[k].queue == 1) {
            let planet = &mut world.games[0].planet;
            planet.levels[k] = planet.levels[k].max((cat.objects[k].price.len() as u32 - 1).min(10));
        }
        cat.refresh(&mut world.games[0].planet);
        assert!(cat.legal(&world.games[0].planet, blocked), "the blocked building's prerequisites are met");
        let unit = (0..world.rules[0].units.units.len()).find(|&u| world.rules[0].units.max_amount(&world.yards[0], &world.games[0].planet, u) > 0).expect("a payable unit");
        world.order_units(&cat, 0, unit, 1);
        assert!(yard_blocks_reserve(&world, &cat, 0), "the reserve waits on a yard-blocked building");
        let before = world.yards[0].orders.len();
        let mut miner = Miner::new(5);
        (miner.reserve, miner.yard, miner.eager) = (true, true, true);
        miner.act(&mut world, &cat, 0);
        assert_eq!(world.yards[0].orders.len(), before, "the persona kept ordering while its building waited on the yard");
    }

    /// Row 34: a build the queue committed and waits to afford keeps its price; the persona's yard orders spend only the
    /// rest, so the build starts instead of being re-decided at every login.
    #[test]
    fn a_committed_build_keeps_its_price_from_the_yard() {
        let Some((cat, mut world)) = bare(5) else { return eprintln!("no catalogue; skipped") };
        let planet = &mut world.games[0].planet;
        planet.levels = cat.objects.iter().map(|o| (o.price.len() as u32 - 1).min(12)).collect();
        cat.refresh(planet);
        let k = (0..cat.objects.len()).filter(|&k| cat.objects[k].queue == 0)
            .max_by(|&x, &y| weighted(cat.objects[x].price[12]).total_cmp(&weighted(cat.objects[y].price[12]))).unwrap();
        let price = cat.objects[k].price[world.games[0].planet.levels[k] as usize];
        world.games[0].planet.res = std::array::from_fn(|r| price[r] + 1000.0);
        world.games[0].commit[0] = Some(k);
        let miner = Miner::new(5);
        miner.order_defence(&mut world, &cat, 0, None);
        let res = world.games[0].planet.res;
        assert!((0..3).all(|r| res[r] + 1e-6 >= price[r]), "the yard spent the committed build's price: {res:?} against {price:?}");
    }

    /// Row 33: a capital spends what its queues do not need on its persona's unit and keeps two hours of production.
    #[test]
    fn a_capital_spends_its_surplus_in_the_yard() {
        let Some((cat, mut world)) = bare(5) else { return eprintln!("no catalogue; skipped") };
        let planet = &mut world.games[0].planet;
        planet.levels = cat.objects.iter().map(|o| (o.price.len() as u32 - 1).min(12)).collect();
        cat.refresh(planet);
        planet.res = [5e7, 5e7, 5e7];
        let keep: Vec<f64> = (0..3).map(|r| 2.0 * world.games[0].planet.rate[r].max(0.0)).collect();
        let mut miner = Miner::new(5);
        miner.yard = true;
        miner.spend_surplus(&mut world, &cat, 0, None);
        assert!(!world.yards[0].orders.is_empty(), "nothing was ordered");
        let res = world.games[0].planet.res;
        assert!((0..3).all(|r| res[r] + 1e-6 >= keep[r]), "the reserve was spent: {res:?} against {keep:?}");
        assert!((0..3).any(|r| res[r] < 1e7), "most of the surplus is still in the store: {res:?}");
    }

    /// Row 45 C: a colony whose store is filling sends what it holds above half its cap home in the cargo ships stationed on it, and
    /// the delivery lands whole, above the capital's store (the host does not cap it).
    #[test]
    fn a_colony_sends_its_surplus_to_the_capital() {
        let Some((cat, mut world)) = bare(5) else { return eprintln!("no catalogue; skipped") };
        let kit = Kit::of(&world, &cat, 0).expect("a cargo ship");
        let colony = world.add_planet(&cat, 0, 0, Coord { galaxy: 1, system: 10, position: 9 }, 0.0);
        let cap = world.games[colony].planet.cap;
        world.games[colony].planet.res = [cap[0] + 2e6, cap[1], 0.3 * cap[2]];
        assert!(world.set_units(&cat, colony, kit.cargo, 5000), "the colony holds cargo ships");
        let before = world.games[0].planet.res[0];
        assert_eq!(world.consolidate(&cat, colony, kit.cargo), 0, "the stationed ships carry the whole surplus");
        let sent = world.fleets.iter().find(|f| f.owner == colony && f.target == 0 && f.mission == Mission::Transport).expect("a transport home");
        let carried = sent.cargo;
        assert!(carried[0] > 0.5 * cap[0] + 1.9e6 && carried[1] > 0.49 * cap[1], "the part above half the cap is loaded: {carried:?}");
        while world.fleets.iter().any(|f| f.owner == colony && !f.returning) {
            let Some(a) = world.next_decision(&cat) else { break };
            world.wait(&cat, a);
        }
        let capital = &world.games[0].planet;
        assert!(capital.res[0] >= before + carried[0], "the delivery landed whole: {} after {before}", capital.res[0]);
        assert!(capital.res[0] > capital.cap[0], "and above the store cap");
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
        assert!(world.events.iter().any(|event| event.mission == crate::world::Mission::Colonise
            && event.kind == crate::world::EventKind::Arrival
            && event.outcome == crate::world::Outcome::Done
            && world.planets_of(0).contains(&event.target) && event.target != 0),
            "the colonisation delivery does not identify its new planet");
    }

    /// A persona under its military share buys defence before economy; one at its target does not (row 10, S4).
    #[test]
    fn a_miner_under_its_military_share_orders_defence() {
        let defence = |w: &World| -> u64 {
            (0..w.rules[0].units.units.len())
                .filter(|&u| w.rules[0].units.kind.get(u) == Some(&UnitKind::Defence))
                .map(|u| w.yards[0].counts[u] + w.yards[0].orders.iter().filter(|o| o.unit == u).map(|o| o.amount - o.done).sum::<u64>())
                .sum()
        };
        let Some((cat, mut world)) = bare(1) else { return };
        let mut miner = Miner::new(1);
        for _ in 0..60_000 {
            let Some(a) = world.next_decision(&cat) else { break };
            if a != 0 { world.wait(&cat, a); continue }
            match miner.act(&mut world, &cat, a) {
                Some(k) => world.commit(&cat, a, k),
                None => world.wait(&cat, a),
            }
            if defence(&world) > 0 { break }
        }
        assert!(defence(&world) > 0, "a miner under its target bought no defence: {:?}", world.games[0].planet.levels);

        // The same world and seed with a target of zero: the account is never under it, and buys none.
        let Some((cat2, mut quiet)) = bare(1) else { return };
        let mut idle = Miner::new(1);
        idle.military_share = 0.0;
        for _ in 0..60_000 {
            let Some(a) = quiet.next_decision(&cat2) else { break };
            if a != 0 { quiet.wait(&cat2, a); continue }
            match idle.act(&mut quiet, &cat2, a) {
                Some(k) => quiet.commit(&cat2, a, k),
                None => quiet.wait(&cat2, a),
            }
        }
        let bought: u64 = (0..quiet.rules[0].units.units.len())
            .filter(|&u| quiet.rules[0].units.kind.get(u) == Some(&UnitKind::Defence))
            .map(|u| quiet.yards[0].counts[u]).sum();
        assert_eq!(bought, 0, "a miner at its target still bought defence");
    }

    /// Row 71: with the ships arm a miner under its military share buys military ships, which the host scores as military
    /// only; without it a miner never holds one.
    #[test]
    fn a_miner_with_the_ships_arm_meets_its_share_with_ships() {
        let military = |w: &World| -> u64 {
            (0..w.rules[0].units.units.len())
                .filter(|&u| w.rules[0].units.kind.get(u) == Some(&UnitKind::MilitaryShip))
                .map(|u| w.yards[0].counts[u] + w.yards[0].orders.iter().filter(|o| o.unit == u).map(|o| o.amount - o.done).sum::<u64>())
                .sum()
        };
        let play = |ships: bool| -> u64 {
            let Some((cat, mut world)) = bare(1) else { return u64::MAX };
            let mut miner = Miner::new(1);
            miner.ships = ships;
            for _ in 0..60_000 {
                let Some(a) = world.next_decision(&cat) else { break };
                if a != 0 { world.wait(&cat, a); continue }
                match miner.act(&mut world, &cat, a) {
                    Some(k) => world.commit(&cat, a, k),
                    None => world.wait(&cat, a),
                }
                if military(&world) > 0 { break }
            }
            military(&world)
        };
        let (with, without) = (play(true), play(false));
        if with == u64::MAX { return }
        assert!(with > 0, "a miner with the ships arm bought no military ship");
        assert_eq!(without, 0, "a miner without the arm bought military ships");
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
