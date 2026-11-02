//! Scripted human-like players on the world (plan/rl/WORLD-SIM-PLAN.md, W7): a miner (the economy's player rule plus
//! the odd cargo ship or probe), a raider (spy, raid, recycle) and a fleet saver (leaves before an attack lands and
//! recalls the fleet once it has passed). They play per account (the capital's slot; a colony only builds) and act at
//! every login, including the ones with nothing to build. Roles come from the host's tables: a ship is movable when
//! its speed is above zero, a cargo ship is the movable non-probe non-harvester with the most capacity per price,
//! probes and harvesters are what the mission tables name. No object is named; targets are the nearest accounts by
//! coordinate, the inactive ones first. Results are read from the world's event log.

use crate::{HashMap, HashSet};
use std::sync::Arc;

use crate::combat;
use crate::economy::{own_row, Baseline, Catalogue, Planet, WEIGHTS};
use crate::fleet::{self, Coord, SystemState};
use crate::score;
use crate::units::UnitKind;
use crate::world::{Decision, EventKind, Fleet, Mission, Outcome, Rules, SpyReport, World, WreckState, UNIT_QUEUE};

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
/// Phalanx scans a moon keeps the deuterium for.
const SCAN_RESERVE: f64 = 10.0;
/// The most probes one spy is sent with.
pub(crate) const SPY_PROBES: i64 = 4;
/// The interplanetary missiles a raider keeps once a target's defence has turned a raid away: a salvo, as players
/// fire them (gate 3); the silo's capacity, the host's, bounds what the yard takes.
const MISSILE_SALVO: i64 = 20;

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
    /// Missiles fired, and per struck slot the largest salvo that reached it.
    pub missiles: i64,
    pub struck: HashMap<usize, i64>,
    /// L5: holds sent to a mate's planet under attack, and fleets sent to join a mate's raid.
    pub defends: usize,
    pub joins: usize,
    /// Wreck fields: ships repaired at the space dock and taken back.
    pub repaired: i64,
    /// Colonies asked to move next to the home planet.
    pub relocations: usize,
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
                (EventKind::Arrival, Mission::Missile) => {
                    let salvo: i64 = e.ships_lost.iter().map(|s| s.1).sum();
                    let most = self.struck.entry(e.target).or_default();
                    *most = (*most).max(salvo);
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

/// The research share of a winning player's score: real top-10 and top-1% players at speed 8 hold 0.31-0.35 of their points
/// in research once lifeform points are stripped (26 public universes, `plan/rl/TRAINING-RUN.md` 3 Nov). The earlier 0.12 read
/// totals with lifeform points in them. A taste over host data that names no object, as `military_share` is.
const RESEARCH_SHARE: f64 = 0.33;

/// The account's research share of its own host score.
pub(crate) fn research_share(w: &World, a: usize) -> f64 {
    let score = score::of(w, w.account_of(a));
    if score.total > 0 { score.research as f64 / score.total as f64 } else { 0.0 }
}

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
    let total = w.debris[target].iter().sum::<f64>();
    let n = ((total / hold as f64).ceil() as i64).min(on_shelf(w, a, harvester));
    // Fuel is the dispatch's own refusal: a fleet the planet cannot fill the tanks for never leaves.
    let fuel = fleet::consumption(&rules.settings, &rules.fleet, &[(harvester, n)], &research, account_distance(w, a, target), 0, 10.0) as f64;
    (w.fields[target] && total >= (hold / 2) as f64 && n >= 1 && fuel <= w.games[a].planet.res[2]).then_some((harvester, n))
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
            if (away as f64) <= remaining + 120.0 { continue }
            // The hold flies empty, so the store must hold the fuel alone; a save without it fails, as a player's does.
            let fuel = fleet::consumption(&rules.settings, &rules.fleet, &ships, &research, account_distance(w, a, b), 0, speed) as f64;
            if fuel <= w.games[a].planet.res[2] { return Some((b, ships, speed)) }
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
    /// Row 77 arm: a planet waiting on time rather than resources builds the factory that shortens its building queue.
    pub factory: bool,
    /// Row 79 arm: the lab's research from surplus stops at the real players' research share (`RESEARCH_SHARE`).
    pub balance: bool,
    /// Row 83 arm: a planet's energy comes from a production unit when it pays more energy per resource than any
    /// building (`energy_unit`), and the build pick no longer spends fields on energy while those units are coming.
    pub satellites: bool,
    /// Row 84 arm: every expedition takes the host's find doubler when the account's class may build it, with the hold
    /// for the doubled find (`expedition_cargo`).
    pub doubler: bool,
    /// `expofull` probe: every cargo ship on the shelf flies, split over the free slots, not only the plain find's hold.
    pub expo_full: bool,
    /// `exposhield`: every expedition takes one of the account's strongest ships, as the forum's expedition guide advises; the
    /// ships a find can hold are capped by the best ship type in the fleet, and the hull meets pirates and aliens first.
    pub expo_ship: bool,
    /// Row 94 arm: the idle lab takes its research without keeping the next building's price back (a player keeps the
    /// lab running; the building waits). Without it the capital's metal never covered both and the lab sat idle for weeks.
    pub lab_first: bool,
    /// A grown account works towards the research network and raises its colonies' labs to the capital's (`network_step`).
    pub network: bool,
    /// The lab walks the requirements of what it wants, and a rich account keeps the cheapest research moving (`next_research`).
    pub tech_tree: bool,
    /// An account with idle surplus trades its richest resource into its poorest at the merchant (`trade_surplus`).
    pub merchant: bool,
    /// The missiles this account keeps on its capital (a raider's, once defence turned a raid away).
    pub missiles: i64,
    /// Per slot of the account, the interceptors it keeps: as many as the largest salvo that struck it.
    pub interceptors: HashMap<usize, i64>,
    /// The persona's archetype doctrine (`doctrine` arm): the yard's surplus fills its fleet or defence template.
    pub doctrine: Doctrine,
}

/// An archetype's fleet and defence templates as (host unit id, share by count), from `resources/doctrine` through
/// `rl/scripts/doctrine_json.py`: data names the units, the code names none (gate 1).
#[derive(Clone, Default, serde::Deserialize)]
pub struct Doctrine {
    pub fleet: Vec<(u32, f64)>,
    pub defence: Vec<(u32, f64)>,
    /// The defence value kept per unit of warship value: below it, the yard's surplus builds the defence template.
    #[serde(default)]
    pub defence_weight: f64,
}

impl Doctrine {
    /// Whether account a's defences, at price, are short of its weight times its warships, as the host's kinds tell them.
    fn defence_due(&self, w: &World, a: usize) -> bool {
        let units = &w.rules[a].units;
        let value = |kind: UnitKind| (0..units.units.len()).filter(|&u| units.kind.get(u) == Some(&kind))
            .map(|u| owned(w, a, units.units[u].id as u32) as f64 * weighted(units.units[u].price)).sum::<f64>();
        !self.defence.is_empty() && value(UnitKind::Defence) < self.defence_weight * value(UnitKind::MilitaryShip)
    }
}

/// `ArchetypeDoctrine::templateGaps` followed by the planner's pick: among the template's units the yard can start, the one
/// furthest below its share by count, and any not held yet first. Held units of the template are the denominator.
fn template_pick(w: &World, a: usize, template: &[(u32, f64)], buildable: impl Fn(usize) -> bool) -> Option<usize> {
    let rules = &w.rules[a];
    let total = template.iter().map(|&(id, _)| owned(w, a, id)).sum::<i64>().max(1) as f64;
    let weight = template.iter().map(|t| t.1).sum::<f64>().max(1.0);
    let gap = |&(id, share): &(u32, f64)| { let held = owned(w, a, id); share / weight - held as f64 / total + if held == 0 { 1.0 } else { 0.0 } };
    template.iter().filter_map(|t| Some((rules.units.index(t.0 as i64)?, gap(t))))
        .filter(|&(u, _)| buildable(u))
        .max_by(|x, y| x.1.total_cmp(&y.1)).map(|(u, _)| u)
}

/// W14: a lab on a colony before the account's research network reads it shortens no research, and the host holds the
/// account's research for as long as it upgrades (`PlayerService::isBuildingObject`): a player does not build it (gate 3).
/// Which object sets research time and which research is the network, the catalogue says (gate 1).
/// `MerchantService::DARK_MATTER_COST`.
const MERCHANT_COST: i64 = 3500;
/// `MerchantService::getBaseRate`: metal, crystal, deuterium.
const MERCHANT_BASE: [f64; 3] = [3.0, 2.0, 1.0];
/// `generateWeightedRate`: the lowest rate and the step, by resource received.
const MERCHANT_RANGE: [(f64, f64); 3] = [(2.10, 0.03), (1.40, 0.02), (0.70, 0.01)];
/// A trade is worth a call when the richest resource is this many times the poorest, by the merchant's own values.
const MERCHANT_IMBALANCE: f64 = 2.0;

/// `MerchantService::generateWeightedRate`: a triangle over 31 steps, the last step's weight raised to 3 (the code's
/// `round(1 * 3.14)`, whatever its comment says that does), rounded to 2 places.
fn merchant_rate(receive: usize, rng: &mut u64) -> f64 {
    let weight = |i: u64| match i { 30 => 3, 0..=15 => i + 1, _ => 31 - i };
    let mut draw = crate::next_random(rng) % (0..=30).map(weight).sum::<u64>();
    let step = (0..=30).find(|&i| { let w = weight(i); if draw < w { true } else { draw -= w; false } }).unwrap_or(30);
    let (low, increment) = MERCHANT_RANGE[receive];
    ((low + step as f64 * increment) * 100.0).round() / 100.0
}

/// One merchant call by capital a holding idle surplus: its richest resource by the merchant's values into its poorest,
/// up to level with each other and within the store (`MerchantService::executeTrade`). A player's stock of crystal and
/// deuterium buys nothing without metal (gate 3); which resource is short the numbers say.
fn trade_surplus(w: &mut World, cat: &Catalogue, a: usize, rng: &mut u64) {
    if a != w.account_of(a) || w.dark_matter[a] < MERCHANT_COST || !surplus(w, cat, a) { return }
    let planet = &w.games[a].planet;
    let value = |r: usize| planet.res[r] / MERCHANT_BASE[r];
    let order = |x: &usize, y: &usize| value(*x).total_cmp(&value(*y));
    let (Some(give), Some(receive)) = ((0..3).max_by(order), (0..3).min_by(order)) else { return };
    if value(give) < MERCHANT_IMBALANCE * value(receive) { return }
    let budget = (value(give) - value(receive)) / 2.0 * MERCHANT_BASE[give];
    let exchange = merchant_rate(receive, rng) / MERCHANT_BASE[give];
    let got = (budget * exchange).min(planet.cap[receive] - planet.res[receive]).floor();
    if got <= 0.0 { return }
    let paid = (got / exchange).ceil();
    w.dark_matter[a] -= MERCHANT_COST;
    let planet = &mut w.games[a].planet;
    planet.res[give] -= paid;
    planet.res[receive] += got;
    planet.settle();
    let (mut was, mut now) = ([0.0; 3], [0.0; 3]);
    (was[give], now[receive]) = (paid, got);
    w.games[a].planet.books.merchant_net += weighted(now) - weighted(was);
}

/// Whether the account's capital holds more than its mines can use.
fn surplus(w: &World, cat: &Catalogue, a: usize) -> bool {
    let capital = &w.games[w.account_of(a)].planet;
    weighted(capital.res) >= SURPLUS_DAYS * 24.0 * weighted(cat.production(capital, true).0)
}

/// A player with colonies works towards the network, one level per colony, since research points a day are capped by the
/// labs it pools. What this queue does for it: `Some(Some(k))` the next payable step, `Some(None)` wait, as research holds a
/// lab upgrade the chain needs, `None` no step here. Requirements come from the catalogue (gate 1).
fn network_step(w: &World, cat: &Catalogue, a: usize) -> Option<Option<usize>> {
    let network = cat.research_network.filter(|_| surplus(w, cat, a))?;
    let (levels, queue) = (&w.games[a].planet.levels, w.games[a].queue);
    let others = w.planets_of(a).len() as u32 - 1;
    if others < NETWORK_FROM_COLONIES || levels[network] >= others { return None }
    let mut need = vec![(network, others)];
    let mut at = 0;
    while at < need.len() && need.len() < 256 {
        need.extend(cat.objects[need[at].0].requires.iter().filter_map(|&(k, level)| Some((k?, level))));
        at += 1;
    }
    let payable = |k: usize, q: usize| cat.objects[k].queue == q && w.legal(cat, a, k) && cat.wait_seconds(&w.games[a].planet, k) == 0.0;
    let missing = |&&(k, level): &&(usize, u32)| levels[k] < level;
    if let Some(&(k, _)) = need.iter().filter(missing).find(|&&(k, _)| payable(k, queue)) { return Some(Some(k)) }
    (queue == 1 && need.iter().filter(missing).any(|&(k, _)| payable(k, 0))).then_some(None)
}

/// The lab colony slot a raises next: once the network reads them, a colony's lab comes up to the capital's. A colony down
/// to the fields its chain needs raises none, and the research waiting on it must not wait for good.
fn colony_lab(w: &World, cat: &Catalogue, a: usize) -> Option<usize> {
    let &(lab, _) = cat.modifiers[1].first()?;
    let capital = w.account_of(a);
    let planet = &w.games[a].planet;
    let free = cat.field_cap(planet).saturating_sub(cat.fields_used(planet));
    if field_chain(cat, planet).is_some_and(|(_, fields)| free <= fields) { return None }
    (capital != a && surplus(w, cat, a) && !idle_lab(w, cat, a, lab) && planet.levels[lab] < w.games[capital].planet.levels[lab]
        && w.legal(cat, a, lab) && cat.wait_seconds(planet, lab) == 0.0).then_some(lab)
}

fn idle_lab(w: &World, cat: &Catalogue, a: usize, k: usize) -> bool {
    let capital = w.account_of(a);
    capital != a && cat.sets_research_time(k) && cat.research_network.is_none_or(|n| w.games[capital].planet.levels[n] == 0)
}

impl Miner {
    pub fn new(seed: u64) -> Miner {
        Miner { rng: seed, cargo_share: 0.1, probes: 1, harvesters: 0, fighters: 0, eager: false, military_share: 0.06, consolidate: false, reserve: false, yard: false, colony_cargo: 0, expeditions: false, colonies_first: false, ships: false, lab: false, cap: false, feed: false, factory: false, balance: false, satellites: false, doubler: false, expo_full: false, expo_ship: false, lab_first: false, network: false, tech_tree: false, merchant: false, missiles: 0, interceptors: HashMap::default(), doctrine: Doctrine::default() }
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
        if self.satellites {
            power_by_units(w, cat, a, pick);
            boost_by_units(w, cat, a, pick);
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
        // A colony struck by missiles orders its own interceptors; a capital's come with its ship orders.
        if w.account_of(a) != a {
            self.order_interceptors(w, cat, a, pick);
        }
        // Row 73 arm: the surplus goes to the military only while the account is under its share (real mid accounts hold
        // a military share near 0.2; spending every surplus on it held 0.5, e74).
        if self.yard && orders && (short || !self.cap) {
            self.spend_surplus(w, cat, a, pick);
        }
        if self.expeditions && w.account_of(a) == a {
            expedition_trips(w, cat, a, self.doubler, self.expo_full, self.expo_ship);
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
        if w.moon_at[a].is_some() { return moon_next(w, cat, a).filter(|&k| w.legal(cat, a, k)) }
        if let Some(chain) = self.reserve.then(|| field_reserve(w, cat, a)).flatten() {
            return chain;
        }
        let queue = w.games[a].queue;
        if self.network {
            if let Some(lab) = colony_lab(w, cat, a).filter(|_| queue == 0) { return Some(lab) }
            if let Some(step) = network_step(w, cat, a).filter(|_| a == w.account_of(a)) { return step }
        }
        // Units already cover the shortfall (or are on order): the pick reads the planet as powered and builds producers.
        let powered = (self.satellites && w.games[a].planet.factor < 1.0 && energy_unit(w, cat, a, true).is_some())
            .then(|| Planet { factor: 1.0, ..w.games[a].planet.clone() });
        let planet = powered.as_ref().unwrap_or(&w.games[a].planet);
        let fallback = match self.lab && queue == 1 {
            true => self.tech_tree.then(|| next_research(w, cat, a)).flatten()
                .filter(|_| !(self.balance && research_share(w, a) >= RESEARCH_SHARE))
                .or_else(|| purpose(w, cat, a)).or_else(|| cat.baseline(planet, queue, Baseline::Player, &mut self.rng)
                .filter(|&k| (self.lab_first || spare(w, cat, a, k)) && !(self.balance && research_share(w, a) >= RESEARCH_SHARE))),
            false => cat.baseline(planet, queue, Baseline::Player, &mut self.rng)
                .map(|k| self.factory.then(|| time_bound(w, cat, a, k)).flatten().unwrap_or(k))
                .filter(|&k| !idle_lab(w, cat, a, k)),
        };
        self.short(w, cat, a).or_else(|| self.unlock(w, cat, a)).or_else(|| fallback)
            .filter(|&k| !(self.network && queue == 1 && cat.build_seconds(&w.games[a].planet, k) > LONG_RESEARCH
                && w.planets_of(w.account_of(a)).into_iter().any(|slot| colony_lab(w, cat, slot).is_some())))
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
        let buildable = |u: usize| w.yards[a].allowed[u] && rules.units.max_amount(&w.yards[a], &w.games[a].planet, u) > 0;
        let template =if fighter { &self.doctrine.fleet } else { &self.doctrine.defence };
        if let Some(u) = template_pick(w, a, template, buildable) { return Some(u) }
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
        let unit = self.hull(w, cat, a, (self.fighters > 0 || self.ships) && !self.doctrine.defence_due(w, a));
        let Some(u) = unit else { return };
        let price = rules.units.units[u].price;
        let amount = (0..3).filter(|&r| price[r] > 0.0)
            .map(|r| ((game.planet.res[r] - keep[r]).max(0.0) / price[r]).floor())
            .fold(f64::INFINITY, f64::min);
        if amount.is_finite() && amount >= 1.0 {
            w.order_units(cat, a, u, amount as u64);
        }
    }

    fn order_interceptors(&self, w: &mut World, cat: &Catalogue, a: usize, pick: Option<usize>) {
        let keep = saving(w, cat, a, pick);
        for (u, amount) in self.strike_plans(w, a) {
            let amount = within(w, a, u, amount as u64, keep);
            if amount > 0 { w.order_units(cat, a, u, amount); }
        }
    }

    /// The missiles and interceptors slot a keeps and does not have, from the host's strike tables (gate 1).
    fn strike_plans(&self, w: &World, a: usize) -> Vec<(usize, i64)> {
        let rules = &w.rules[a];
        let Some(strike) = rules.strike.as_ref() else { return Vec::new() };
        let missiles = if w.account_of(a) == a { self.missiles } else { 0 };
        [(strike.missile, missiles), (strike.interceptor, self.interceptors.get(&a).copied().unwrap_or(0))].into_iter().filter_map(|(id, want)| {
            let u = rules.units.index(id as i64)?;
            let missing = want - owned(w, a, id);
            (missing > 0).then_some((u, missing))
        }).collect()
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
        let Some(kit) = Kit::of(w, cat, a) else { return self.strike_plans(w, a) };
        let rules = &w.rules[a];
        let research = w.research(cat, a);
        let hold = rules.combat.capacity(&[(kit.cargo, 1)].into_iter().collect(), &research).max(1);
        let stock: f64 = w.games[a].planet.res.iter().sum();
        let (trips, slots) = if self.expeditions { expedition_cargo(w, cat, a, self.doubler).map_or((0, 0), |(n, slots)| (n * slots as i64, slots as i64)) } else { (0, 0) };
        let doubler = doubler_of(w, a, self.doubler).map_or((kit.probe, 0), |id| (id, slots));
        let wants = [(kit.probe, self.probes), (kit.cargo, (stock * self.cargo_share / hold as f64).ceil() as i64 + self.colony_cargo + trips), (kit.harvester, self.harvesters), (kit.fighter, self.fighters), doubler];
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
        plans.extend(self.strike_plans(w, a));
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

/// The warships on a planet that fly.
fn warships(rules: &Rules, research: &HashMap<u32, u32>, counts: &[u64]) -> Vec<(u32, i64)> {
    stack(rules, counts).into_iter()
        .filter(|&(id, _)| movable(rules, id, research) && rules.units.index(id as i64).is_some_and(|u| rules.units.kind.get(u) == Some(&UnitKind::MilitaryShip)))
        .collect()
}

/// L5: an alliance member whose warships reach a mate's planet before a raid lands there holds at it, when the planet
/// alone would lose and the two together destroy the raid in three rounds without being worn down: the help a player
/// in an alliance gives (gate 3). Raids landing soonest go first, one hold per planet.
pub fn defend_mate(w: &mut World, cat: &Catalogue, a: usize, mate: impl Fn(usize) -> bool, ledger: &mut Ledger) -> bool {
    let now = w.games[a].t;
    let rules = Arc::clone(&w.rules[a]);
    let research = w.research(cat, a);
    let ships = warships(&rules, &research, &w.yards[a].counts);
    if ships.is_empty() || !rules.settings.alliance_combat_system { return false }
    let mut raids: Vec<(usize, f64, usize, Vec<(u32, i64)>)> = w.fleets.iter()
        .filter(|f| f.mission == Mission::Attack && !f.returning && !f.moon && f.arrive > now && w.account_of(f.owner) != w.account_of(a))
        .filter(|f| w.mates(a, f.target, now) && mate(w.account_of(f.target)))
        .map(|f| (f.target, f.arrive, f.owner, f.ships.clone())).collect();
    raids.sort_by(|x, y| x.1.total_cmp(&y.1));
    let ours = strength(&rules, &research, &ships);
    for (target, lands, raider, raid) in raids {
        if w.fleets.iter().any(|f| f.owner == a && f.mission == Mission::AcsDefend && f.target == target && !f.returning) { continue }
        let flight = fleet::flight_seconds(&rules.settings, Mission::AcsDefend as u32, account_distance(w, a, target), rules.fleet.slowest_speed(&ships, &research), 10.0) as f64;
        if now + flight >= lands { continue }
        let planet = strength(&w.rules[target], &w.research(cat, target), &stack(&w.rules[target], &w.yards[target].counts));
        let (attack, hull) = strength(&w.rules[raider], &w.research(cat, raider), &raid);
        let alone_loses = planet.0 * 3.0 < hull;
        let together_win = (ours.0 + planet.0) * 3.0 >= hull && attack * 3.0 <= SAFE_SHARE * (ours.1 + planet.1);
        if !(alone_loses && together_win) { continue }
        let hold = ((lands - now - flight) / 3600.0).floor() as i64 + 1;
        if w.defend(cat, a, target, &ships, [0.0; 3], 10.0, hold).is_ok() {
            ledger.defends += 1;
            return true;
        }
    }
    false
}

/// Wreck fields: at a login the owner of a planet with a wreck and a space dock starts the repairs and takes the ships
/// back once they are done, as players do after losing a fleet (gate 3). The hourly cleanup has run in between.
pub fn tend_wrecks(w: &mut World, cat: &Catalogue, a: usize, ledger: &mut Ledger) {
    let now = w.games[a].t;
    for p in w.planets_of(a) {
        if !w.wrecks.contains_key(&p) { continue }
        w.sweep_wrecks(cat, p, (now / 3600.0).floor() * 3600.0);
        if w.wrecks[&p].iter().any(|f| f.state == WreckState::Repairing && now >= f.completes) {
            ledger.repaired += w.collect_wreck(cat, p, now).unwrap_or(0);
        }
        let _ = w.start_repairs(cat, p, now);
    }
}

/// Planet moves: with the dark matter for it, an account moves a colony standing outside its home system into a free
/// slot there, the nearest first, so the colony is supplied and defended from home: why players pay for a move (gate 3).
/// One move at a time; the host refuses it while the colony builds or a fleet is out, and the account asks again later.
pub fn relocate_colony(w: &mut World, a: usize, ledger: &mut Ledger) -> bool {
    let Some(cost) = w.rules[a].settings.relocation.as_ref().map(|r| r.cost) else { return false };
    if w.dark_matter[a] < cost || !w.moves.is_empty() && w.moves.keys().any(|&p| w.account_of(p) == a) { return false }
    let home = w.coords[a];
    let Some(colony) = w.planets_of(a).into_iter().filter(|&p| p != a && w.moon_at[p].is_none()).find(|&p| w.coords[p].galaxy != home.galaxy || w.coords[p].system != home.system) else { return false };
    let mut free: Vec<Coord> = (1..=15).map(|position| Coord { position, ..home }).filter(|&c| !w.coords.contains(&c) && !w.moves.values().any(|m| m.0 == c)).collect();
    free.sort_by_key(|c| (c.position - home.position).abs());
    let Some(&to) = free.first() else { return false };
    let asked = w.relocate(colony, to).is_ok();
    ledger.relocations += usize::from(asked);
    asked
}

/// L5: an alliance member sends cargo ships to join a mate's raid when the mate's report shows more plunder than the
/// raid carries, the extra plunder pays for the fuel, the target's guns cannot wear down the joint fleet and the ships
/// land within the union's window: the ACS a player in an alliance flies for a rich target (gate 3).
pub fn join_raid(w: &mut World, cat: &Catalogue, a: usize, mate: impl Fn(usize) -> bool, ledger: &mut Ledger) -> bool {
    let now = w.games[a].t;
    let Some(kit) = Kit::of(w, cat, a) else { return false };
    let shelf = on_shelf(w, a, kit.cargo);
    if shelf < 1 || !w.rules[a].settings.alliance_combat_system { return false }
    let raids: Vec<usize> = (0..w.fleets.len()).filter(|&f| {
        let lead = &w.fleets[f];
        lead.mission == Mission::Attack && !lead.returning && !lead.moon && lead.arrive > now && lead.union.is_none_or(|u| u == lead.id)
            && w.account_of(lead.owner) != w.account_of(a) && mate(w.account_of(lead.owner)) && w.mates(a, lead.owner, now)
            && !w.mates(a, lead.target, now) && w.account_of(lead.target) != w.account_of(a)
            && !w.fleets.iter().any(|g| g.owner == a && g.union.is_some() && g.union == Some(lead.union.unwrap_or(lead.id)))
    }).collect();
    let rules = Arc::clone(&w.rules[a]);
    let research = w.research(cat, a);
    let hold = rules.combat.capacity(&[(kit.cargo, 1)].into_iter().collect(), &research).max(1);
    for f in raids {
        let (owner, target, key) = (w.fleets[f].owner, w.fleets[f].target, w.fleets[f].union.unwrap_or(w.fleets[f].id));
        let Some(report) = last_report(w, owner, target) else { continue };
        let (Some(ships), Some(defence)) = (&report.ships, &report.defence) else { continue };
        let loot = report.resources.map(|r| r.max(0) as f64 * w.rules[owner].combat.rules.loot_percentage / 100.0);
        let members: Vec<usize> = (0..w.fleets.len()).filter(|&g| g == f || w.fleets[g].union == Some(key)).collect();
        let (capacity, union_hull) = members.iter().fold((0, 0.0), |(c, h), &g| {
            let (r, re) = (&w.rules[w.fleets[g].owner], w.research(cat, w.fleets[g].owner));
            (c + r.combat.capacity(&w.fleets[g].ships.iter().copied().collect(), &re), h + strength(r, &re, &w.fleets[g].ships).1)
        });
        let n = ((loot.iter().sum::<f64>() - capacity as f64) / hold as f64).ceil().min(shelf as f64) as i64;
        if n < 1 { continue }
        let mut guns = stack(&w.rules[target], ships);
        guns.extend(stack(&w.rules[target], defence));
        let (attack, _) = strength(&w.rules[target], &w.research(cat, target), &guns);
        let fleet = [(kit.cargo, n)];
        if attack * 3.0 > SAFE_SHARE * (union_hull + strength(&rules, &research, &fleet).1) { continue }
        let extra = combat::distribute_loot(loot, capacity + n * hold);
        let carried = combat::distribute_loot(loot, capacity);
        let fuel = fleet::consumption(&rules.settings, &rules.fleet, &fleet, &research, account_distance(w, a, target), 0, 10.0) as f64;
        if weighted(extra) - weighted(carried) < PAYS * WEIGHTS[2] * fuel { continue }
        if w.join(cat, a, f, &fleet, [0.0; 3], 10.0).is_ok() {
            ledger.joins += 1;
            return true;
        }
    }
    false
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

/// Row 67: an alliance member readies its first planet with no moon to receive a mate's shot: its doctrine's wall (the
/// unit furthest below its share), else the cheapest defence the host has, enough to down the shot in three rounds,
/// paid from that planet's own stock above what it keeps. With `fed` (row 89) the capital ships a colony the rest of the
/// price: consolidation keeps a colony's store too small to pay it.
pub fn ready_for_moon(w: &mut World, cat: &Catalogue, a: usize, fed: bool, wall: &[(u32, f64)]) {
    let Some((_, _, hull)) = shot_size(w, cat, a) else { return };
    let Some(p) = w.planets_of(a).into_iter().find(|&p| !w.moons[p] && guns(w, cat, p) * 3.0 < hull) else { return };
    let rules = Arc::clone(&w.rules[p]);
    let research = w.research(cat, p);
    let standing = |u: usize| rules.units.kind.get(u) == Some(&UnitKind::Defence) && rules.units.requirements_met(&w.games[p].planet, u)
        && strength(&rules, &research, &[(rules.units.units[u].id as u32, 1)]).0 > 0.0;
    let Some(u) = template_pick(w, p, wall, standing).or_else(|| (0..rules.units.units.len()).filter(|&u| standing(u))
        .min_by(|&x, &y| weighted(rules.units.units[x].price).total_cmp(&weighted(rules.units.units[y].price))))
    else { return };
    let each = strength(&rules, &w.research(cat, p), &[(rules.units.units[u].id as u32, 1)]).0;
    if each <= 0.0 { return }
    let missing = ((hull / 3.0 - guns(w, cat, p)) / each).ceil().max(0.0) as u64;
    let amount = within(w, p, u, missing, kept(w, cat, p));
    if amount > 0 { w.order_units(cat, p, u, amount); }
    let underway = w.fleets.iter().any(|f| f.owner == a && f.target == p && f.mission == Mission::Transport && !f.returning);
    if fed && p != a && amount < missing && !underway {
        let price = rules.units.units[u].price;
        let lack = std::array::from_fn(|r| (price[r] * (missing - amount) as f64).ceil());
        ship_from_home(w, cat, a, p, lack, kept(w, cat, a));
    }
}

/// N6b: the cargo ships one expedition takes and the account's expedition slots: a hold for the largest plain find of
/// the host's bracket at the world's rank-1 score (class and reward multipliers included), and the host's slot limit
/// at the account's research level. `None` before the account may fly one. Read from the host's tables (gate 1).
fn expedition_cargo(w: &World, cat: &Catalogue, a: usize, doubled: bool) -> Option<(i64, usize)> {
    let rules = &w.rules[a];
    let table = rules.expedition.as_ref()?;
    let research = w.research(cat, a);
    // A class's slots stand before the research does, but no expedition flies without it (ExpeditionMission::isMissionPossible).
    if research.get(&(w.colonisation.as_ref()?.research as u32)).copied().unwrap_or(0) == 0 { return None }
    let slots = w.expedition_slots(cat, a)?;
    if slots == 0 { return None }
    let largest = table.find_brackets.iter().find(|(below, _)| w.rank1_points < *below).or(table.find_brackets.last())?.1;
    let twice = if doubler_of(w, a, doubled).is_some() { 2.0 } else { 1.0 };
    let find = twice * largest as f64 * table.class_resource_multiplier * table.reward_multiplier.resources;
    let kit = Kit::of(w, cat, a)?;
    let hold = rules.combat.capacity(&[(kit.cargo, 1)].into_iter().collect(), &research).max(1);
    Some(((find / hold as f64).ceil() as i64, slots))
}

/// N6b: a player sends an expedition from its capital whenever a slot is free (gate 3): cargo ships for the largest
/// find, to deep space in its own system, held an hour. The world refuses past the host's slot limit or without fuel.
fn expedition_trips(w: &mut World, cat: &Catalogue, a: usize, doubled: bool, full: bool, escort: bool) {
    let Some((n, slots)) = expedition_cargo(w, cat, a, doubled) else { return };
    let Some(kit) = Kit::of(w, cat, a) else { return };
    let deep = Coord { position: 16, ..w.coords[a] };
    let doubler = doubler_of(w, a, doubled);
    let flying = w.fleets.iter().filter(|f| f.owner == a && f.mission == Mission::Expedition).count();
    for free in (1..=slots.saturating_sub(flying)).rev() {
        let shelf = on_shelf(w, a, kit.cargo);
        let ships = if full { (shelf / free as i64).max(n.min(shelf)) } else { n.min(shelf) };
        let mut fleet = vec![(kit.cargo, ships)];
        fleet.extend(doubler.filter(|&id| on_shelf(w, a, id) > 0).map(|id| (id, 1)));
        fleet.extend(escort.then(|| strongest(w, cat, a, &fleet)).flatten().map(|id| (id, 1)));
        if ships < 1 || w.expedition(cat, a, deep, &fleet, [0.0; 3], 10.0, 1).is_err() { return }
    }
}

/// The fighting ship with the most hull that account a has on its shelf and is not already in `fleet`.
fn strongest(w: &World, cat: &Catalogue, a: usize, fleet: &[(u32, i64)]) -> Option<u32> {
    let rules = &w.rules[a];
    let research = w.research(cat, a);
    rules.units.units.iter().map(|u| u.id as u32)
        .filter(|&id| on_shelf(w, a, id) > fleet.iter().filter(|s| s.0 == id).map(|s| s.1).sum::<i64>() && rules.combat.fights(id) && movable(rules, id, &research))
        .max_by_key(|&id| rules.combat.hull(id, &research))
}

/// Row 84: the ship whose presence doubles an expedition's find, when the arm is on and the account's class may build
/// it (the host's expedition table names it by id; nothing here does, gate 1).
fn doubler_of(w: &World, a: usize, on: bool) -> Option<u32> {
    let id = w.rules[a].expedition.as_ref()?.find_doubler;
    let u = w.rules[a].units.index(id as i64)?;
    (on && w.yards[a].allowed[u]).then_some(id)
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

/// How many times over a planet's stock must cover its next building before the queue, not the stock, is what it waits
/// on (e78b: comparing build time with the planet's own production rarely fired, since expeditions and transports, not
/// production, fill a rich planet's store).
const TIME_BOUND_STOCK: f64 = 3.0;
/// A player starts on the network once it has this many colonies for it to read.
const NETWORK_FROM_COLONIES: u32 = 2;
/// A research longer than this waits for the colony labs that would shorten it; a shorter one runs.
const LONG_RESEARCH: f64 = 86_400.0;
/// Labs are for surplus: a capital holding this many days of its own production has more than its mines can use.
const SURPLUS_DAYS: f64 = 60.0;

/// Row 77: a planet whose stock covers its next building several times over waits on its queue's time: a player then
/// builds the cheapest of the host's time modifiers for that queue it can start now (gate 3; found by asking, gate 1).
fn time_bound(w: &World, cat: &Catalogue, a: usize, next: usize) -> Option<usize> {
    let planet = &w.games[a].planet;
    let price = |k: usize| weighted(cat.objects[k].price[planet.levels[k] as usize]);
    if weighted(planet.res) < TIME_BOUND_STOCK * price(next) { return None }
    cat.modifiers[0].iter().map(|&(k, _)| k)
        .filter(|&k| w.legal(cat, a, k) && cat.wait_seconds(planet, k) == 0.0)
        .min_by(|&x, &y| price(x).total_cmp(&price(y)))
}

/// Row 75: a player builds its colonies up with the capital's surplus (gate 3): a colony that cannot pay its next
/// building (the one it saves for, else the player rule's) gets what it lacks by transport, when the capital holds that
/// beyond what it saves for itself and has the cargo ships at home.
fn feed_colonies(w: &mut World, cat: &Catalogue, a: usize, pick: Option<usize>) {
    let keep = saving(w, cat, a, pick);
    for colony in w.planets_of(a).into_iter().skip(1) {
        let lack = colony_lack(w, cat, colony);
        ship_from_home(w, cat, a, colony, lack, keep);
    }
    // M4: a moon makes nothing, so everything it builds is carried up from its planet's capital.
    let moons: Vec<usize> = w.planets_of(a).into_iter().filter_map(|p| w.moon_of[p]).collect();
    for moon in moons {
        let res = w.games[moon].planet.res;
        let mut lack: [f64; 3] = moon_next(w, cat, moon).map_or([0.0; 3], |k| {
            let price = cat.objects[k].price[w.games[moon].planet.levels[k] as usize];
            std::array::from_fn(|r| (price[r] - res[r]).max(0.0).ceil())
        });
        // A phalanx is read at a raider's every raid: the moon keeps the deuterium for a day of scans.
        if let Some(gear) = w.rules[moon].settings.moon_gear.as_ref().filter(|g| w.moon_level(cat, moon, g.phalanx_object) > 0) {
            lack[2] = lack[2].max((SCAN_RESERVE * gear.phalanx_cost - res[2]).max(0.0).ceil());
        }
        let underway = w.fleets.iter().any(|f| f.target == moon && f.mission == Mission::Transport && !f.returning);
        if !underway { ship_from_home(w, cat, a, moon, lack, keep); }
    }
}

/// M4: what a moon builds next, as a player builds a moon up (gate 3): the lunar base its fields need when they run out,
/// else the cheapest building only a moon takes (the phalanx, the gate) that it does not hold yet and whose requirements
/// stand; only its stock may be missing. Nothing is named: the host's planet types and field tables say which (gate 1).
fn moon_next(w: &World, cat: &Catalogue, moon: usize) -> Option<usize> {
    let planet = &w.games[moon].planet;
    let startable = |k: usize| matches!(cat.stall_of(planet, k), "ok" | "never affordable");
    if let Some((chain, fields)) = field_chain(cat, planet) {
        if cat.field_cap(planet).saturating_sub(cat.fields_used(planet)) <= fields {
            return chain.into_iter().map(|(k, _)| k).find(|&k| startable(k));
        }
    }
    let price = |k: usize| weighted(cat.objects[k].price[planet.levels[k] as usize]);
    // The phalanx is raised until it reads the account's neighbours: a level-1 phalanx reads only its own system.
    let phalanx = w.rules[moon].settings.moon_gear.as_ref().and_then(|g| {
        let k = cat.objects.iter().position(|o| o.id == g.phalanx_object)?;
        let range = g.phalanx_range[(planet.levels[k] as usize).min(g.phalanx_range.len() - 1)];
        (planet.levels[k] > 0 && range < neighbour_reach(w, moon)).then_some(k)
    });
    (0..cat.objects.len())
        .filter(|&k| cat.objects[k].queue == 0 && cat.objects[k].on_moon && !cat.objects[k].on_planet && (planet.levels[k] == 0 || Some(k) == phalanx) && startable(k))
        .min_by(|&x, &y| price(x).total_cmp(&price(y)))
}

/// The systems between slot s and the farthest of its account's `NEIGHBOURS` nearest accounts in its galaxy.
fn neighbour_reach(w: &World, s: usize) -> i64 {
    let (here, systems) = (w.coords[s], w.rules[s].settings.number_of_systems);
    let mut gaps: Vec<i64> = (0..w.games.len()).filter(|&b| w.account_of(b) == b && b != w.account_of(s) && w.coords[b].galaxy == here.galaxy)
        .map(|b| { let d = (w.coords[b].system - here.system).abs(); d.min(systems - d) }).collect();
    gaps.sort_unstable();
    gaps.into_iter().take(NEIGHBOURS).last().unwrap_or(0)
}

/// Capital a sends `lack` to its planet `to` in its cargo hull, when it holds that above `keep` and has the ships.
fn ship_from_home(w: &mut World, cat: &Catalogue, a: usize, to: usize, lack: [f64; 3], keep: [f64; 3]) -> bool {
    let Some(kit) = Kit::of(w, cat, a) else { return false };
    let hold = w.rules[a].combat.capacity(&[(kit.cargo, 1)].into_iter().collect(), &w.research(cat, a)).max(1) as f64;
    let total: f64 = lack.iter().sum();
    let home = w.games[a].planet.res;
    if total <= 0.0 || (0..3).any(|r| home[r] - keep[r] < lack[r]) { return false }
    let ships = (total / hold).ceil() as i64;
    if ships > on_shelf(w, a, kit.cargo) { return false }
    w.dispatch(cat, a, to, Mission::Transport, &[(kit.cargo, ships)], lack, 10.0).is_ok()
}

/// What a colony's next building (the one it saves for, else the player rule's) lacks in its store.
fn colony_lack(w: &World, cat: &Catalogue, colony: usize) -> [f64; 3] {
    let planet = &w.games[colony].planet;
    let Some(next) = w.games[colony].commit[0].or_else(|| cat.baseline(planet, 0, Baseline::Player, &mut 0)) else { return [0.0; 3] };
    let price = cat.objects[next].price[planet.levels[next] as usize];
    std::array::from_fn(|r| (price[r] - planet.res[r]).max(0.0).ceil())
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

/// The planets a player settles before its lab goes for output: the median real mid account's at 64-128 speed days
/// (`real-bands.json`). A taste over host data, as `RESEARCH_SHARE` is.
const SETTLED_PLANETS: usize = 8;

/// What a player's lab takes next. With idle surplus or under the real research share, the cheapest research it can pay now,
/// which levels the whole tree the way price does for a real account. Otherwise what raises output or the colonisation research, cheapest first, reached
/// through its unmet requirements: a player goes for the laser levels plasma asks for, not the next astrophysics (gate 3).
/// Output, requirements and prices come from the catalogue (gate 1).
fn next_research(w: &World, cat: &Catalogue, a: usize) -> Option<usize> {
    let planet = &w.games[a].planet;
    let price = |k: usize| cat.objects[k].price.get(planet.levels[k] as usize).map_or(f64::INFINITY, |&p| weighted(p));
    let research = || (0..cat.objects.len()).filter(|&k| cat.objects[k].queue == 1);
    let cheapest = |keys: Vec<usize>| keys.into_iter().min_by(|&x, &y| price(x).total_cmp(&price(y)));
    let colonise = w.colonisation.as_ref().map(|t| t.research);
    let settling = colonise.is_some() && w.planets_of(a).len() < SETTLED_PLANETS;
    if surplus(w, cat, a) || (!settling && research_share(w, a) < RESEARCH_SHARE) {
        let payable = cheapest(research().filter(|&k| w.legal(cat, a, k) && cat.wait_seconds(planet, k) == 0.0).collect());
        if payable.is_some() { return payable }
    }
    let output = |p: &Planet| weighted(cat.production(p, true).0);
    let now = output(planet);
    let raises = |k: usize| { let mut next = planet.clone(); next.levels[k] += 1; cat.refresh(&mut next); output(&next) > now };
    let wanted = research().filter(|&k| price(k).is_finite() && (Some(cat.objects[k].id) == colonise || (raises(k) && !settling))).collect::<Vec<_>>();
    let steps = wanted.into_iter().filter_map(|k| {
        let mut need = vec![(k, planet.levels[k] + 1)];
        let mut at = 0;
        while at < need.len() && need.len() < 256 {
            need.extend(cat.objects[need[at].0].requires.iter().filter_map(|&(k, level)| Some((k?, level))));
            at += 1;
        }
        let step = need.iter().find(|&&(k, level)| planet.levels[k] < level && cat.objects[k].queue == 1 && w.legal(cat, a, k))?.0;
        Some((k, step))
    }).collect::<Vec<_>>();
    steps.into_iter().min_by(|x, y| price(x.0).total_cmp(&price(y.0))).map(|(_, step)| step)
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
/// Row 83: the production unit that pays the most energy per resource on planet a, with the energy one more adds, when
/// it beats every energy building the planet can start (gate 3: a grown planet runs on satellites and keeps its fields
/// for mines; gate 1: the catalogue's production units and the yard's prices say which, nothing is named).
fn energy_unit(w: &World, cat: &Catalogue, a: usize, against_buildings: bool) -> Option<(usize, f64)> {
    let planet = &w.games[a].planet;
    let rules = &w.rules[a];
    let config = &cat.planets[planet.config];
    let spare = |p: &Planet| p.energy_max - p.energy_used;
    let building = (0..cat.objects.len())
        .filter(|&k| cat.objects[k].queue == 0 && cat.legal(planet, k))
        .filter_map(|k| {
            let mut next = planet.clone();
            next.levels[k] += 1;
            cat.refresh(&mut next);
            let gain = spare(&next) - spare(planet);
            (gain > 0.0).then(|| weighted(cat.objects[k].price[planet.levels[k] as usize]) / gain)
        })
        .fold(f64::INFINITY, f64::min);
    (0..cat.units.len()).filter_map(|e| {
        let own = config.unit_production.get(e)?.own.as_ref()?;
        let held = planet.units.get(e).copied().unwrap_or(0) as usize;
        let each = own_row(own, held + 1)[3] - own_row(own, held)[3];
        let u = rules.units.index(cat.units[e])?;
        let per_energy = weighted(rules.units.units[u].price) / each;
        (each > 0.0 && (!against_buildings || per_energy < building) && w.yards[a].allowed[u] && rules.units.requirements_met(planet, u)).then_some((u, each, per_energy))
    }).min_by(|x, y| x.2.total_cmp(&y.2)).map(|(u, each, _)| (u, each))
}

/// Row 83: orders the energy unit for the planet's shortfall and a tenth more headroom (the next mine level's draw),
/// less what the yard already has on order, paid around the queue's committed builds.
fn power_by_units(w: &mut World, cat: &Catalogue, a: usize, pick: Option<usize>) {
    let planet = &w.games[a].planet;
    let for_chain = chain_energy(cat, planet);
    // The field chain's energy cannot come from a building: the planet is holding its last fields for the chain.
    let Some((u, each)) = energy_unit(w, cat, a, for_chain <= 0.0) else { return };
    let coming: u64 = w.yards[a].orders.iter().filter(|o| o.unit == u).map(|o| o.amount - o.done).sum();
    let short = (1.1 * planet.energy_used - planet.energy_max).max(for_chain) - coming as f64 * each;
    if short <= 0.0 { return }
    let amount = within(w, a, u, (short / each).ceil() as u64, saving(w, cat, a, pick));
    if amount > 0 { w.order_units(cat, a, u, amount); }
}

/// A unit that adds output, by its own row or by raising the mines' (a collector's crawler), is bought while one pays for itself sooner than the building
/// the queue picked, as a collector does (gate 3), and only to the number the host puts to use; the host's unit tables
/// say which unit (gate 1).
fn boost_by_units(w: &mut World, cat: &Catalogue, a: usize, pick: Option<usize>) {
    let planet = &w.games[a].planet;
    let output = |p: &Planet| weighted(cat.production(p, false).0);
    let now = output(planet);
    let payback = |price: f64, mut next: Planet| {
        cat.refresh(&mut next);
        let gain = output(&next) - now;
        (gain > 0.0).then(|| price / gain)
    };
    let building = pick.filter(|&k| cat.objects[k].queue == 0).and_then(|k| {
        let mut next = planet.clone();
        next.levels[k] += 1;
        payback(weighted(cat.objects[k].price[planet.levels[k] as usize]), next)
    }).unwrap_or(f64::INFINITY);
    let rules = &w.rules[a];
    let best = (0..cat.units.len()).filter_map(|e| {
        let table = cat.planets[planet.config].unit_production.get(e)?;
        if table.own.is_none() && table.producers.is_empty() { return None }
        let u = rules.units.index(cat.units[e])?;
        if !w.yards[a].allowed[u] || !rules.units.requirements_met(planet, u) || w.yards[a].orders.iter().any(|o| o.unit == u) { return None }
        let mut next = planet.clone();
        next.units[e] += 1;
        let per = payback(weighted(rules.units.units[u].price), next)?;
        (per < building).then_some((u, per))
    }).min_by(|x, y| x.1.total_cmp(&y.1));
    let Some((u, _)) = best else { return };
    let amount = within(w, a, u, BOOST_BATCH, saving(w, cat, a, pick));
    if amount > 0 { w.order_units(cat, a, u, amount); }
}

/// Production units ordered at one login.
const BOOST_BATCH: u64 = 10;

/// How long a planet saves towards the energy units of its next field adder before it gives up the field it holds.
const CHAIN_POWER_DAYS: f64 = 30.0;

/// The energy planet is short of for its field chain: a terraformer asks for energy the planet must make, not spend.
fn chain_energy(cat: &Catalogue, planet: &Planet) -> f64 {
    let Some((chain, _)) = field_chain(cat, planet) else { return 0.0 };
    chain.iter().filter(|&&(k, level)| planet.levels[k] < level)
        .filter_map(|&(k, _)| cat.objects[k].energy_price.get(planet.levels[k] as usize))
        .fold(0.0, |short: f64, &need| short.max(need - planet.energy_max))
}

/// Whether planet a can come by the energy its field chain asks for: units it can pay for within `CHAIN_POWER_DAYS` of
/// production. A player who cannot makes do with the fields it has rather than hold the last ones for good (gate 3).
fn chain_powered(w: &World, cat: &Catalogue, a: usize) -> bool {
    let planet = &w.games[a].planet;
    let short = chain_energy(cat, planet);
    if short <= 0.0 { return true }
    let Some((u, each)) = energy_unit(w, cat, a, false) else { return false };
    let coming: u64 = w.yards[a].orders.iter().filter(|o| o.unit == u).map(|o| o.amount - o.done).sum();
    let needed = ((short / each).ceil() - coming as f64).max(0.0);
    let price = w.rules[a].units.units[u].price;
    (0..3).all(|r| price[r] * needed <= planet.res[r] + CHAIN_POWER_DAYS * 24.0 * planet.rate[r].max(0.0))
}

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
        .filter(|&k| cat.stands_on(planet, k) && adds(k, planet.levels[k] as usize + 1) > adds(k, planet.levels[k] as usize))
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
        (cat.field_cap(planet).saturating_sub(cat.fields_used(planet)) <= lead * fields && chain_powered(w, cat, slot)).then_some(chain)
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
    /// A neighbour's moon is spied and raided like its planet: the fleet parked there and the stock it holds.
    pub moon_raids: bool,
    last: f64,
    spied: HashMap<usize, f64>,
    hit: HashMap<usize, f64>,
    /// Per target, the second the last salvo left.
    fired: HashMap<usize, f64>,
}

impl Raider {
    pub fn new(seed: u64) -> Raider {
        let miner = Miner { rng: seed, cargo_share: 0.2, probes: 4, harvesters: 3, fighters: 30, eager: true, military_share: 0.30, consolidate: false, reserve: false, yard: false, colony_cargo: 0, expeditions: false, colonies_first: false, ships: false, lab: false, cap: false, feed: false, factory: false, balance: false, satellites: false, doubler: false, expo_full: false, expo_ship: false, lab_first: false, network: false, tech_tree: false, merchant: false, missiles: 0, interceptors: HashMap::default(), doctrine: Doctrine::default() };
        Raider { miner, hostile: true, moon_raids: false, last: -1.0, spied: HashMap::default(), hit: HashMap::default(), fired: HashMap::default() }
    }

    pub fn act(&mut self, w: &mut World, cat: &Catalogue, a: usize, ledger: &mut Ledger) -> Option<usize> {
        let pick = self.miner.act(w, cat, a);
        let now = w.games[a].t;
        if !self.hostile || now == self.last || w.account_of(a) != a { return pick }
        self.last = now;
        let Some(kit) = Kit::of(w, cat, a) else { return pick };
        let mut targets = nearest(w, cat, a, NEIGHBOURS);
        if self.moon_raids { targets.extend(targets.clone().into_iter().filter_map(|t| w.moon_of[t])) }
        for target in targets {
            if w.fleets.iter().filter(|f| f.owner == a).count() >= SLOTS { break }
            self.recycle(w, cat, a, target, ledger);
            if self.destroy_moon(w, cat, a, target, ledger) { continue }
            if self.raid(w, cat, a, target, kit, ledger) { continue }
            self.spy(w, cat, a, target, kit, ledger);
        }
        pick
    }

    fn spy(&mut self, w: &mut World, cat: &Catalogue, a: usize, target: usize, kit: Kit, ledger: &mut Ledger) {
        let now = w.games[a].t;
        // A salvo since the report has changed the defence it read.
        let fresh = last_report(w, a, target).is_some_and(|r| now - r.time < REPORT_HOURS * 3600.0 && self.fired.get(&target).is_none_or(|&t| t < r.time));
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
        let Some(plan) = raid_plan(w, cat, a, target, kit) else {
            // More guns than the raider's fighters can take on: the missiles go first when the loot pays for the salvo.
            if defended_loot(w, cat, a, target) { self.strike(w, cat, a, target, ledger) }
            return false;
        };
        let rules = Arc::clone(&w.rules[a]);
        let research = w.research(cat, a);
        let fuel = fleet::consumption(&rules.settings, &rules.fleet, &plan.fleet, &research, account_distance(w, a, target), 0, 10.0) as f64;
        if weighted(plan.expected) < PAYS * WEIGHTS[2] * fuel { return false }
        if plan.attack * 3.0 > SAFE_SHARE * plan.hull {
            self.strike(w, cat, a, target, ledger);
            return false;
        }
        let lands = now + fleet::flight_seconds(&rules.settings, Mission::Attack as u32, account_distance(w, a, target), rules.fleet.slowest_speed(&plan.fleet, &research), 10.0) as f64;
        if fleet_home_first(w, cat, a, target, lands) { return false }
        let had_fleet = stack(&w.rules[target], &w.yards[target].counts).iter().any(|&(id, _)| movable(&w.rules[target], id, &w.research(cat, target)));
        let Ok(arrive) = send(w, cat, a, target, Mission::Attack, &plan.fleet, 10.0, ledger) else { return false };
        self.hit.insert(target, now);
        ledger.raids.push(Raid { attacker: a, target, arrive, loot: None, target_had_fleet: had_fleet, saved: false });
        true
    }

    /// A rich target whose defence turns the raid away is softened with missiles first, as a raider with a silo does
    /// (gate 3): the account keeps a salvo from then on, its prerequisites built through `unlock`, and fires what it holds.
    fn strike(&mut self, w: &mut World, cat: &Catalogue, a: usize, target: usize, ledger: &mut Ledger) {
        let Some(missile) = w.rules[a].strike.as_ref().map(|s| s.missile) else { return };
        self.miner.missiles = MISSILE_SALVO;
        let now = w.games[a].t;
        let held = on_shelf(w, a, missile);
        if held < 1 || self.fired.get(&target).is_some_and(|&t| now - t < RAID_COOLDOWN) { return }
        if w.launch_missiles(cat, a, target, false, held, 0).is_ok() {
            self.fired.insert(target, now);
            ledger.missiles += held;
        }
    }

    /// M4: a raider holding the host's moon destroyer goes for a neighbour's moon that keeps a fleet or whose phalanx reads
    /// the raider's planet, when the moon's guns
    /// cannot hurt its warships: the fleet is caught and the moon (its phalanx, its gate) may fall, as a deathstar
    /// player does (gate 3). The strike tables name the destroyer (gate 1).
    fn destroy_moon(&mut self, w: &mut World, cat: &Catalogue, a: usize, target: usize, ledger: &mut Ledger) -> bool {
        let Some(moon) = w.moon_of[target] else { return false };
        let Some(destroyer) = w.rules[a].strike.as_ref().map(|s| s.moon_destroyer) else { return false };
        let now = w.games[a].t;
        if on_shelf(w, a, destroyer) < 1 || self.hit.get(&moon).is_some_and(|&t| now - t < RAID_COOLDOWN) { return false }
        let research = w.research(cat, a);
        let kept = stack(&w.rules[moon], &w.yards[moon].counts);
        let hides_fleet = kept.iter().any(|&(id, _)| movable(&w.rules[moon], id, &w.research(cat, moon)));
        if !hides_fleet && !watches(w, cat, moon, a) { return false }
        let rules = Arc::clone(&w.rules[a]);
        let fleet: Vec<(u32, i64)> = stack(&rules, &w.yards[a].counts).into_iter()
            .filter(|&(id, _)| movable(&rules, id, &research) && rules.combat.fights(id))
            .collect();
        let (_, hull) = strength(&rules, &research, &fleet);
        if guns(w, cat, moon) * 3.0 > SAFE_SHARE * hull { return false }
        if send(w, cat, a, target, Mission::MoonDestroy, &fleet, 10.0, ledger).is_err() { return false }
        self.hit.insert(moon, now);
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
        if weighted(field).min((n * hold) as f64) < PAYS * WEIGHTS[2] * fuel { return }
        if send(w, cat, a, target, Mission::Recycle, &[(harvester, n)], 10.0, ledger).is_ok() { ledger.recycles += 1 }
    }
}

/// Whether moon slot `moon` has a phalanx whose range reaches planet `p` (same galaxy, systems around the donut).
fn watches(w: &World, cat: &Catalogue, moon: usize, p: usize) -> bool {
    let Some(g) = w.rules[moon].settings.moon_gear.as_ref() else { return false };
    let level = w.moon_level(cat, moon, g.phalanx_object);
    let range = g.phalanx_range[level.min(g.phalanx_range.len() - 1)];
    let (from, to, systems) = (w.coords[moon], w.coords[p], w.rules[moon].settings.number_of_systems);
    let d = (from.system - to.system).abs();
    level > 0 && from.galaxy == to.galaxy && d.min(systems - d) <= range
}

/// Whether the last full report on `target` shows defence that shoots and loot worth more than a missile salvo.
fn defended_loot(w: &World, cat: &Catalogue, a: usize, target: usize) -> bool {
    let Some(report) = last_report(w, a, target) else { return false };
    let Some(defence) = &report.defence else { return false };
    let Some(missile) = w.rules[a].strike.as_ref().map(|s| s.missile) else { return false };
    let shoots = strength(&w.rules[target], &w.research(cat, target), &stack(&w.rules[target], defence)).0 > 0.0;
    let loot = weighted(report.resources.map(|r| r.max(0) as f64 * w.rules[a].combat.rules.loot_percentage / 100.0));
    shoots && loot > MISSILE_SALVO as f64 * unit_price(&w.rules[a], missile)
}

/// M4: a raider with a phalanx in range reads the target before it attacks and holds back when the target's own fleet
/// lands there before the raid would (gate 3: a phalanx is how a player times a hit). Without a phalanx it raids blind.
fn fleet_home_first(w: &mut World, cat: &Catalogue, a: usize, target: usize, lands: f64) -> bool {
    let account = w.account_of(target);
    let moons: Vec<usize> = w.planets_of(a).into_iter().filter_map(|p| w.moon_of[p]).collect();
    let Some(seen) = moons.into_iter().find_map(|m| { w.catch_up(cat, m, w.games[a].t); w.phalanx(cat, m, target).ok() }) else { return false };
    seen.into_iter().any(|f| {
        let fleet = &w.fleets[f];
        let home = if fleet.returning { fleet.home } else if fleet.mission == Mission::Deploy { fleet.arrive } else { fleet.home };
        w.account_of(fleet.owner) == account && home <= lands && fleet.ships.iter().any(|&(id, _)| w.rules[target].combat.fights(id))
    })
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
        FleetSaver { miner, answered: HashSet::default(), saving: Vec::new() }
    }

    pub fn act(&mut self, w: &mut World, cat: &Catalogue, a: usize, ledger: &mut Ledger) -> Option<usize> {
        let pick = self.miner.act(w, cat, a);
        if w.account_of(a) != a { return pick }
        let now = w.games[a].t;
        // M4: a fleeter keeps its fleet on its capital's moon, where no raid on the planet finds it.
        let post = w.moon_of[a].unwrap_or(a);
        if post != a {
            w.catch_up(cat, post, now);
            station(w, cat, a, post, ledger);
            gate_home(w, cat, post);
        }
        self.recall_passed(w, post, now, ledger);
        // Before the night the fleet goes out for it, as a player's does: the next login is hours away and a raid does not
        // wait for it. A save that only answered an attack seen while awake could never happen at all (measured: zero in
        // ten days across four savers).
        let hours_to_night = (w.games[a].sleep_start - (now % 86_400.0) / 3600.0).rem_euclid(24.0);
        // A fleeter with two gates leaves from the other moon, so the phalanx that read its fleet here times nothing.
        let from = if hours_to_night <= 1.0 { gate_escape(w, cat, post).unwrap_or(post) } else { post };
        if hours_to_night <= 1.0 && self.leave(w, cat, from, w.games[a].sleep_hours * 3600.0, ledger) {
            ledger.saves_sent += 1;
        }
        let aimed = |f: &Fleet| f.target == a && f.moon == (post != a) && matches!(f.mission, Mission::Attack | Mission::MoonDestroy);
        let coming: Vec<(usize, f64)> = w.fleets.iter().filter(|f| aimed(f) && !f.returning && f.arrive > now).map(|f| (f.owner, f.arrive)).collect();
        let fresh: Vec<f64> = coming.into_iter().filter(|c| self.answered.insert((c.0, c.1.to_bits()))).map(|c| c.1).collect();
        for lands in fresh {
            let saved = gate_escape(w, cat, post).is_some() || self.leave(w, cat, post, lands - now, ledger);
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

/// M4: the capital's warships deploy to its moon with a hold of deuterium for the moon's own save, up to a hold the
/// moon does not have yet and half of what the capital holds.
fn station(w: &mut World, cat: &Catalogue, a: usize, moon: usize, ledger: &mut Ledger) {
    let rules = Arc::clone(&w.rules[a]);
    let research = w.research(cat, a);
    let ships = warships(&rules, &research, &w.yards[a].counts);
    if ships.is_empty() { return }
    let hold = rules.combat.capacity(&ships.iter().copied().collect(), &research) as f64;
    let fuel = fleet::consumption(&rules.settings, &rules.fleet, &ships, &research, account_distance(w, a, moon), 0, 10.0) as f64;
    let carried = (hold - w.games[moon].planet.res[2]).min(0.5 * w.games[a].planet.res[2] - fuel).max(0.0).floor();
    let before = w.games[a].planet.res[2];
    if w.dispatch(cat, a, moon, Mission::Deploy, &ships, [0.0, 0.0, carried], 10.0).is_ok() {
        ledger.fuel += WEIGHTS[2] * (before - carried - w.games[a].planet.res[2]);
    }
}

/// M4: a fleet on a moon with a jump gate meets a seen attack by jumping to another of the account's gated moons: no
/// fuel, no flight, the move a fleeter with two gates makes (gate 3). The host's gate rules decide which jump stands.
fn gate_escape(w: &mut World, cat: &Catalogue, moon: usize) -> Option<usize> {
    if w.moon_at[moon].is_none() { return None }
    let gear = w.rules[moon].settings.moon_gear.clone()?;
    let ships = gate_ships(w, moon, &gear.gate_ships);
    if ships.is_empty() || w.moon_level(cat, moon, gear.gate_object) == 0 { return None }
    let others: Vec<usize> = w.planets_of(w.account_of(moon)).into_iter().filter_map(|p| w.moon_of[p]).filter(|&m| m != moon).collect();
    others.into_iter().find(|&to| w.jump(cat, moon, to, &ships).is_ok())
}

/// M4: a fleet left on another moon after a gate save jumps back to the moon the fleeter keeps it on.
fn gate_home(w: &mut World, cat: &Catalogue, post: usize) {
    let Some(gear) = w.rules[post].settings.moon_gear.clone() else { return };
    let away: Vec<usize> = w.planets_of(w.account_of(post)).into_iter().filter_map(|p| w.moon_of[p]).filter(|&m| m != post).collect();
    for moon in away {
        w.catch_up(cat, moon, w.games[w.account_of(post)].t);
        let ships = gate_ships(w, moon, &gear.gate_ships);
        if !ships.is_empty() { let _ = w.jump(cat, moon, post, &ships); }
    }
}

/// The ships on slot s a jump gate carries.
fn gate_ships(w: &World, s: usize, carried: &[u32]) -> Vec<(u32, i64)> {
    stack(&w.rules[s], &w.yards[s].counts).into_iter().filter(|(id, _)| carried.contains(id)).collect()
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
        // A planet struck by missiles keeps interceptors for the next salvo (gate 3).
        if let Some(&salvo) = ledger.struck.get(&a) { self.miner().interceptors.insert(a, salvo); }
        let slots = !matches!(self, Player::Miner(_));
        hire_officer(w, a, slots);
        // Only an officer the account has hired before is kept up; one it never could afford is no reason to sit on dark matter.
        let reserve = wanted_officer(w, a, slots).filter(|o| w.officers[a].contains_key(&o.0)).map_or(0, |o| o.2);
        halve_long_build(w, cat, a, reserve);
        let miner = self.miner();
        if miner.merchant { trade_surplus(w, cat, a, &mut miner.rng) }
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

/// W15: a player spends its dark matter on the officer that serves its play, a week at a time (gate 3): one whose fleet
/// flies (`slots`) the officer that adds fleet slots, any other the one that shortens research. Officers are read by
/// what the host says they do, never by name (gate 1); one whose effect the host does not report here is never hired.
fn hire_officer(w: &mut World, a: usize, slots: bool) {
    if w.account_of(a) != a { return }
    let Some((key, days, _)) = wanted_officer(w, a, slots) else { return };
    if w.officers[a].get(&key).is_some_and(|&until| until > w.games[a].t) { return }
    let _ = w.hire(a, &key, days);
}

/// The officer `hire_officer` keeps, its shortest hire in days and that hire's dark matter.
fn wanted_officer(w: &World, a: usize, slots: bool) -> Option<(String, i64, i64)> {
    let worth = |o: &crate::fleet::Officer| if slots { o.fleet_slots as f64 } else { 1.0 - o.research_multiplier };
    let (key, o) = w.rules[a].settings.officers.iter().filter(|(_, o)| !o.bundle && worth(o) > 0.0)
        .max_by(|x, y| worth(x.1).total_cmp(&worth(y.1)))?;
    let days = o.costs.keys().filter_map(|d| d.parse::<i64>().ok()).min()?;
    Some((key.clone(), days, o.costs[&days.to_string()]))
}

/// L6: a player puts the dark matter its officer does not need into the longest build that dark matter pays for, when
/// an hour or more is left (gate 3: a halving is priced on the time left, so a short build is not worth one); the next
/// hire of the officer it keeps (`reserve`) stays.
fn halve_long_build(w: &mut World, cat: &Catalogue, a: usize, reserve: i64) {
    if w.account_of(a) != a { return }
    let now = w.games[a].t;
    let spare = w.dark_matter[a] - reserve;
    let longest = w.planets_of(a).into_iter().flat_map(|s| [(s, 0), (s, 1), (s, UNIT_QUEUE)])
        .filter_map(|(s, q)| w.halving_price(cat, s, q).map(|(end, _, cost)| (s, q, end - now, cost)))
        .filter(|&(_, _, left, cost)| left >= HALVE_SECONDS && cost <= spare)
        .max_by(|x, y| x.2.total_cmp(&y.2));
    if let Some((s, q, _, _)) = longest { let _ = w.halve(cat, s, q); }
}

/// The time left from which a player halves a build.
const HALVE_SECONDS: f64 = 3_600.0;

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
        let mut cat = Catalogue::load(&format!("{DATA}/units-parity/econ-u1-merged.json")).ok()?;
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
    /// A planet that cannot make the energy its next field adder asks for spends the field: waiting froze its queue for good.
    #[test]
    fn a_planet_that_cannot_power_its_field_chain_gives_up_the_reserve() {
        let Some((mut cat, mut world)) = bare(5) else { return eprintln!("no catalogue; skipped") };
        world.games[0].queue = 0;
        let (chain, fields) = field_chain(&cat, &world.games[0].planet).expect("an object that adds fields");
        let planet = &mut world.games[0].planet;
        let mut free = cat.field_cap(planet) - cat.fields_used(planet) - fields;
        for k in (0..cat.objects.len()).filter(|&k| cat.objects[k].consumes_field && cat.objects[k].queue == 0 && !chain.iter().any(|c| c.0 == k)) {
            let room = (cat.objects[k].price.len() as u32 - 1).saturating_sub(planet.levels[k]).min(free);
            planet.levels[k] += room;
            free -= room;
        }
        cat.refresh(planet);
        assert!(field_reserve(&world, &cat, 0).is_some(), "the reserve binds while the planet has the energy");
        let adder = chain.iter().find(|c| cat.objects[c.0].fields_added.iter().any(|&f| f > 0)).expect("the adder").0;
        cat.objects[adder].energy_price = vec![1e15; cat.objects[adder].price.len()];
        assert!(chain_energy(&cat, &world.games[0].planet) > 0.0);
        assert!(field_reserve(&world, &cat, 0).is_none(), "a planet that can never pay held its last field");
    }

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

    /// Row 77: a planet whose stock covers its next building many times over builds a host time modifier; one that just
    /// covers it builds the building.
    #[test]
    fn the_merchant_draws_the_hosts_rates() {
        let mut rng = 7;
        for (receive, (low, high)) in [(0, (2.10, 3.00)), (1, (1.40, 2.00)), (2, (0.70, 1.00))] {
            let rates: Vec<f64> = (0..20_000).map(|_| merchant_rate(receive, &mut rng)).collect();
            assert!(rates.iter().all(|&r| r >= low - 1e-9 && r <= high + 1e-9), "a rate outside the host's range");
            // 258 weights in all, 16 on the middle step, 3 on the top one.
            let middle = (low + high) / 2.0;
            let share = |x: f64| rates.iter().filter(|&&r| (r - x).abs() < 1e-6).count() as f64 / 20_000.0;
            assert!((share(middle) - 16.0 / 258.0).abs() < 0.01, "middle step: {}", share(middle));
            assert!((share(high) - 3.0 / 258.0).abs() < 0.005, "top step: {}", share(high));
        }
    }

    #[test]
    fn a_rich_capital_short_of_one_resource_calls_the_merchant_and_stays_balanced() {
        let Some((cat, mut world)) = bare(1) else { return };
        cat.refresh(&mut world.games[0].planet);
        world.dark_matter[0] = MERCHANT_COST;
        world.games[0].planet.cap = [1e15; 3];
        world.games[0].planet.res = [0.0, 1e13, 0.0];
        let before = weighted(world.games[0].planet.res);
        trade_surplus(&mut world, &cat, 0, &mut 3);
        let planet = &world.games[0].planet;
        assert!(planet.res[1] < 1e13, "the crystal went nowhere");
        assert!(planet.res[0] > 0.0 || planet.res[2] > 0.0, "nothing came back");
        assert_eq!(world.dark_matter[0], 0, "the call costs its dark matter");
        assert!((weighted(planet.res) - before - world.games[0].planet.books.merchant_net).abs() < 1.0, "the books do not explain the trade");
        let traded = planet.res;
        world.games[0].planet.deliver([0.0; 3]);
        assert_eq!(world.games[0].planet.res, traded, "the next delivery undid the trade");
        trade_surplus(&mut world, &cat, 0, &mut 3);
        assert_eq!(world.dark_matter[0], 0, "no dark matter, no call");
    }

    #[test]
    fn only_a_capital_holding_surplus_works_towards_the_network() {
        let Some((cat, mut world)) = bare(1) else { return };
        cat.refresh(&mut world.games[0].planet);
        world.games[0].planet.res = [0.0; 3];
        assert!(!surplus(&world, &cat, 0), "an empty store is no surplus");
        world.games[0].planet.res = [1e15; 3];
        assert!(surplus(&world, &cat, 0), "a store of far more than its mines make is");
        assert_eq!(network_step(&world, &cat, 0), None, "an account with no colonies has no network to read them");
    }

    #[test]
    fn an_object_priced_in_energy_waits_for_the_planet_to_make_it() {
        let Some((mut cat, mut world)) = bare(1) else { return };
        let Some(k) = (0..cat.objects.len()).find(|&k| cat.objects[k].queue == 1 && cat.objects[k].price[0] == [0.0; 3]) else { return };
        cat.objects[k].requires.clear();
        cat.objects[k].energy_price = vec![300000.0];
        let planet = &mut world.games[0].planet;
        (planet.energy_max, planet.energy_used) = (299999.0, 0.0);
        assert_eq!(cat.stall_of(planet, k), "no energy");
        planet.energy_max = 300000.0;
        assert_eq!(cat.stall_of(planet, k), "ok");
    }

    #[test]
    fn a_commit_the_login_spent_away_logs_off_instead_of_running_the_clock_out() {
        let Some((cat, mut world)) = bare(5) else { return };
        let game = &mut world.games[0];
        let k = (0..cat.objects.len()).find(|&k| cat.objects[k].queue == 1 && cat.objects[k].price[0][2] > 0.0).expect("a research priced in deuterium");
        game.commit = [None, Some(k)];
        game.planet.res = [0.0; 3];
        game.planet.rate = [0.0, 0.0, 1e-9];
        let t = game.t;
        cat.tick(game);
        assert_eq!(game.commit[1], None, "the commit stands with nothing to pay it");
        assert!(game.t < t + 86400.0 * 30.0, "the clock ran to the horizon on an unpayable commit");
    }

    #[test]
    fn a_producing_unit_keeps_producing_past_the_exports_last_row() {
        let Some((cat, mut world)) = bare(5) else { return };
        let config = world.games[0].planet.config;
        let Some(u) = cat.planets[config].unit_production.iter().position(|t| t.own.is_some()) else { return };
        let rows = cat.planets[config].unit_production[u].own.clone().unwrap();
        assert_eq!(own_row(&rows, rows.len() - 1), rows[rows.len() - 1]);
        let planet = &mut world.games[0].planet;
        planet.units[u] = (rows.len() as u64 - 1) * 2;
        cat.refresh(planet);
        let doubled = planet.energy_max;
        planet.units[u] = rows.len() as u64 - 1;
        cat.refresh(planet);
        assert!(doubled >= planet.energy_max + rows[rows.len() - 1][3] - 1.0, "energy stopped at the table's end");
    }

    #[test]
    fn research_held_by_a_lab_on_another_planet_wakes_when_the_lab_lands() {
        let Some((cat, mut world)) = bare(1) else { return };
        let &(lab, _) = cat.modifiers[1].first().expect("a lab");
        let game = &mut world.games[0];
        let t = game.t;
        game.account_pending = vec![(t + 100.0, lab)];
        game.free = [t + 1e6, t];
        game.commit = [None, None];
        cat.tick(game);
        assert_eq!(game.free[1], t + 100.0, "the research queue slept through the hold");
    }

    #[test]
    fn the_lab_climbs_requirements_to_what_raises_output_and_a_rich_account_takes_the_cheapest() {
        let Some((cat, mut world)) = bare(1) else { return };
        for k in 0..cat.objects.len() {
            world.games[0].planet.levels[k] = if cat.objects[k].queue == 0 { 20 } else { 0 };
        }
        world.games[0].queue = 1;
        world.colonisation = None;
        world.games[0].planet.res = [0.0; 3];
        cat.refresh(&mut world.games[0].planet);
        let output = |p: &Planet| weighted(cat.production(p, true).0);
        let before = output(&world.games[0].planet);
        let mut steps = 0;
        while output(&world.games[0].planet) <= before {
            let k = next_research(&world, &cat, 0).expect("a step towards more output");
            assert_eq!(cat.objects[k].queue, 1);
            world.games[0].planet.levels[k] += 1;
            cat.refresh(&mut world.games[0].planet);
            steps += 1;
            assert!(steps < 100, "the lab never reached what raises output");
        }
        world.games[0].planet.res = [1e15; 3];
        let k = next_research(&world, &cat, 0).expect("a payable research");
        let price = |k: usize| weighted(cat.objects[k].price[world.games[0].planet.levels[k] as usize]);
        assert!((0..cat.objects.len()).filter(|&j| cat.objects[j].queue == 1 && world.legal(&cat, 0, j) && cat.wait_seconds(&world.games[0].planet, j) == 0.0).all(|j| price(k) <= price(j)), "a rich account did not take the cheapest");
    }

    #[test]
    fn a_planet_waiting_on_time_builds_a_factory() {
        let Some((cat, mut world)) = bare(1) else { return };
        for level in world.games[0].planet.levels.iter_mut() { *level = (*level).max(5) }
        world.games[0].queue = 0;
        cat.refresh(&mut world.games[0].planet);
        let next = cat.baseline(&world.games[0].planet, 0, Baseline::Player, &mut 0).expect("a building");
        world.games[0].planet.res = cat.objects[next].price[world.games[0].planet.levels[next] as usize];
        assert_eq!(time_bound(&world, &cat, 0, next), None, "a planet short of stock built a factory");
        world.games[0].planet.res = [1e12; 3];
        let k = time_bound(&world, &cat, 0, next).expect("a factory");
        assert!(cat.modifiers[0].iter().any(|&(m, _)| m == k), "the pick is not a time modifier of the building queue");
    }

    /// Row 79: the research share reads the host score's split, so more research levels raise it past the real share.
    #[test]
    fn research_levels_raise_the_research_share() {
        let Some((cat, mut world)) = bare(1) else { return };
        let before = research_share(&world, 0);
        for k in (0..cat.objects.len()).filter(|&k| cat.objects[k].queue == 1) { world.games[0].planet.levels[k] = 12 }
        cat.refresh(&mut world.games[0].planet);
        assert!(research_share(&world, 0) > before.max(RESEARCH_SHARE), "research levels did not raise the share: {before}");
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

    /// Enough fleet slots that only the expedition limit binds.
    fn open_fleet_slots(cat: &Catalogue, world: &mut World, table: &crate::world::Colonisation) {
        let Some(id) = table.fleet_research else { return };
        let k = cat.objects.iter().position(|o| o.id as u32 == id).expect("the fleet research");
        world.games[0].planet.levels[k] = 10;
    }

    /// The world refuses any fleet beyond the host's fleet slots (GameMission::startMissionSanityChecks): the research's
    /// level plus one at level 0, and a fleet's way home keeps its slot.
    #[test]
    fn a_fleet_beyond_the_fleet_slots_is_refused() {
        let Ok(mut cat) = Catalogue::load(&format!("{DATA}/units-parity/econ-u1-colonies.json")) else { return eprintln!("no catalogue; skipped") };
        cat.login_gaps = vec![600.0, 3600.0];
        let Ok(rules) = Rules::load(&cat, &format!("{DATA}/units-parity/units-u1.json"), &format!("{DATA}/fleet-settings-u1.json"), &format!("{DATA}/combat-u1.json")) else { return eprintln!("no rules; skipped") };
        let mut world = World::new(&cat, &[0, 0], HOURS, 0.0, 5);
        let rules = Arc::new(rules);
        world.arm(vec![Arc::clone(&rules), rules], vec![Coord { galaxy: 3, system: 100, position: 8 }, Coord { galaxy: 3, system: 101, position: 8 }]);
        let table = crate::world::Colonisation::load(&format!("{DATA}/units-parity/econ-u1-colonies.json")).expect("a colonisation table");
        if table.fleet_research.is_none() { return eprintln!("no fleet table; skipped") }
        world.colonisation = Some(Arc::new(table));
        assert_eq!(world.fleet_slots(&cat, 0), Some(1), "one slot at research 0 for this class");
        let kit = Kit::of(&world, &cat, 0).expect("a cargo hull");
        assert!(world.set_units(&cat, 0, kit.cargo, 100), "cargo ships to send");
        world.games[0].planet.res = [1e7; 3];
        assert!(world.dispatch(&cat, 0, 1, Mission::Transport, &[(kit.cargo, 1)], [0.0; 3], 10.0).is_ok(), "the one slot");
        assert_eq!(world.dispatch(&cat, 0, 1, Mission::Transport, &[(kit.cargo, 1)], [0.0; 3], 10.0), Err("maximum number of fleets"));
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
        open_fleet_slots(&cat, &mut world, &table);
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
        open_fleet_slots(&cat, &mut world, &table);
        world.colonisation = Some(Arc::new(table));
        let kit = Kit::of(&world, &cat, 0).expect("a cargo hull");
        assert!(world.set_units(&cat, 0, kit.cargo, 10_000), "cargo ships to send");
        world.games[0].planet.res = [1e7; 3];
        let (n, slots) = expedition_cargo(&world, &cat, 0, false).expect("an expedition is possible");
        assert_eq!(slots, 1);
        expedition_trips(&mut world, &cat, 0, false, false, false);
        let out: Vec<_> = world.fleets.iter().filter(|f| f.mission == Mission::Expedition).collect();
        assert_eq!(out.len(), 1, "one slot, one expedition");
        assert_eq!(out[0].ships, vec![(kit.cargo, n)], "the hold for the largest find");
        expedition_trips(&mut world, &cat, 0, false, false, false);
        assert_eq!(world.fleets.iter().filter(|f| f.mission == Mission::Expedition).count(), 1, "no second trip while the slot is taken");
    }

    /// Row 84: with the arm on, an account whose class may build the find doubler sends one with every expedition and
    /// a hold for the doubled find; an account whose class may not is unchanged.
    #[test]
    fn an_expedition_takes_the_find_doubler_and_a_hold_for_the_doubled_find() {
        let Ok(cat) = Catalogue::load(&format!("{DATA}/units-parity/econ-u1-colonies.json")) else { return eprintln!("no catalogue; skipped") };
        let Ok(rules) = Rules::load(&cat, &format!("{DATA}/units-parity/units-u1.json"), &format!("{DATA}/fleet-settings-u1.json"), &format!("{DATA}/combat-u1-class3.json")) else { return eprintln!("no rules; skipped") };
        let Some(doubler) = rules.expedition.as_ref().map(|t| t.find_doubler) else { return eprintln!("no expedition tables; skipped") };
        let u = rules.units.index(doubler as i64).expect("the doubler is a unit");
        let Some(config) = (0..rules.units.planets.len()).find(|&c| rules.units.shipyard(c).allowed[u]) else { return eprintln!("no class builds the doubler; skipped") };
        let mut world = World::new(&cat, &[config], HOURS, 0.0, 5);
        world.arm(vec![Arc::new(rules)], vec![Coord { galaxy: 3, system: 100, position: 8 }]);
        let mut table = crate::world::Colonisation::load(&format!("{DATA}/units-parity/econ-u1-colonies.json")).expect("a colonisation table");
        table.max_expeditions = vec![0, 1, 1, 1, 2];
        let k = cat.objects.iter().position(|o| o.id == table.research).expect("the colonisation research");
        world.games[0].planet.levels[k] = 1;
        open_fleet_slots(&cat, &mut world, &table);
        world.colonisation = Some(Arc::new(table));
        let kit = Kit::of(&world, &cat, 0).expect("a cargo hull");
        assert!(world.set_units(&cat, 0, kit.cargo, 10_000), "cargo ships to send");
        assert!(world.set_units(&cat, 0, doubler, 1), "a doubler to send");
        world.games[0].planet.res = [1e7; 3];
        let (plain, _) = expedition_cargo(&world, &cat, 0, false).expect("an expedition is possible");
        let (doubled, _) = expedition_cargo(&world, &cat, 0, true).expect("an expedition is possible");
        assert!(doubled >= 2 * plain - 1 && doubled <= 2 * plain, "twice the hold: {plain} -> {doubled}");
        expedition_trips(&mut world, &cat, 0, true, false, false);
        let out: Vec<_> = world.fleets.iter().filter(|f| f.mission == Mission::Expedition).collect();
        // The research's one slot and the class's own: a Discoverer flies three (PlayerService::getExpeditionSlotsMax).
        let class_slots = world.rules[0].expedition.as_ref().map_or(0, |t| t.class_slots);
        assert_eq!(out.len(), 1 + class_slots);
        assert_eq!(out[0].ships, vec![(kit.cargo, doubled), (doubler, 1)], "the cargo and the doubler fly together");
    }

    /// Row 83: a grown planet short of energy, where a production unit pays more energy per resource than any building,
    /// orders those units for the shortfall and builds a producer meanwhile instead of an energy building.
    #[test]
    fn a_grown_planet_short_of_energy_orders_units_and_builds_a_producer() {
        let Ok(mut cat) = Catalogue::load(&format!("{DATA}/units-parity/econ-u1-colonies.json")) else { return eprintln!("no catalogue; skipped") };
        cat.login_gaps = vec![600.0, 3600.0];
        let Ok(rules) = Rules::load(&cat, &format!("{DATA}/units-parity/units-u1.json"), &format!("{DATA}/fleet-settings-u1.json"), &format!("{DATA}/combat-u1.json")) else { return eprintln!("no rules; skipped") };
        let mut world = World::new(&cat, &[0], HOURS, 0.0, 5);
        world.arm(vec![Arc::new(rules)], vec![Coord { galaxy: 3, system: 100, position: 8 }]);
        // Every building at 20 except the energy ones, which lag: the planet is short and its next solar level is dear.
        let energy: Vec<usize> = (0..cat.objects.len()).filter(|&k| {
            let mut next = world.games[0].planet.clone();
            next.levels[k] += 1;
            cat.refresh(&mut next);
            next.energy_max > world.games[0].planet.energy_max
        }).collect();
        for k in (0..cat.objects.len()).filter(|&k| cat.objects[k].queue == 0) {
            world.games[0].planet.levels[k] = if energy.contains(&k) { 16 } else { 22 };
        }
        world.games[0].planet.res = [1e9; 3];
        cat.refresh(&mut world.games[0].planet);
        assert!(world.games[0].planet.factor < 1.0, "the planet is short of energy");
        let Some((u, each)) = energy_unit(&world, &cat, 0, true) else { return eprintln!("no production unit beats the buildings here; skipped") };
        let mut miner = Miner::new(5);
        miner.satellites = true;
        let pick = miner.pick(&world, &cat, 0).expect("a build");
        assert!(!energy.contains(&pick), "a producer, not an energy building, while the units come");
        power_by_units(&mut world, &cat, 0, None);
        let ordered: u64 = world.yards[0].orders.iter().filter(|o| o.unit == u).map(|o| o.amount).sum();
        let planet = &world.games[0].planet;
        assert!(ordered as f64 * each >= planet.energy_used - planet.energy_max, "the order covers the shortfall");
        power_by_units(&mut world, &cat, 0, None);
        let again: u64 = world.yards[0].orders.iter().filter(|o| o.unit == u).map(|o| o.amount).sum();
        assert_eq!(again, ordered, "units on order count: no second order");
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

    /// L5: an alliance member with warships near a mate's planet holds there before a raid lands, and the raid meets
    /// both; outside the alliance, or with a hold already there, nothing is sent.
    #[test]
    fn an_alliance_member_holds_at_a_raided_mate_and_the_raid_meets_both() {
        let Some((cat, mut world)) = bare(5) else { return eprintln!("no catalogue; skipped") };
        let fighter = Kit::of(&world, &cat, 0).expect("a fighter hull").fighter;
        world.coords[2] = Coord { galaxy: 1, system: 60, position: 8 };
        for a in [0, 2] { world.games[a].planet.res = [1e9; 3] }
        assert!(world.set_units(&cat, 2, fighter, 2_000) && world.set_units(&cat, 0, fighter, 1_000_000), "both hold fighters");
        world.dispatch(&cat, 2, 1, Mission::Attack, &[(fighter, 2_000)], [0.0; 3], 10.0).expect("the raid flies");
        let mut ledger = Ledger::default();
        assert!(!defend_mate(&mut world, &cat, 0, |_| true, &mut ledger), "no hold for an account outside the alliance");
        world.alliance_at[0] = Some(0.0);
        world.alliance_at[1] = Some(0.0);
        assert!(defend_mate(&mut world, &cat, 0, |_| true, &mut ledger), "the member holds at its mate");
        assert!(!defend_mate(&mut world, &cat, 0, |_| true, &mut ledger), "one hold per planet");
        let (raid, hold) = (&world.fleets[0], world.fleets.iter().find(|f| f.mission == Mission::AcsDefend).expect("a hold"));
        assert!(hold.arrive <= raid.arrive && raid.arrive < hold.arrive + hold.holding, "the hold covers the raid's landing");
        while !world.events.iter().any(|e| e.kind == EventKind::Fight) {
            let a = world.next_decision(&cat).expect("the world plays on");
            world.wait(&cat, a);
        }
        let fight = world.events.iter().find(|e| e.kind == EventKind::Fight).unwrap();
        assert_eq!(fight.ships_lost, vec![(fighter, 2_000)], "the raid fell to the mate's ally");
        assert!(world.fleets.iter().any(|f| f.mission == Mission::AcsDefend && f.owner == 0), "the ally still holds");
    }

    /// L5: an alliance member sends cargo ships to join a mate's raid when the mate's report shows more plunder than the
    /// raid carries; the union lands together and the partner flies home with a share. Outside the alliance, or once
    /// joined, nothing is sent.
    #[test]
    fn an_alliance_member_joins_a_mates_raid_on_a_rich_target_and_carries_a_share() {
        let Some((cat, mut world)) = bare(5) else { return eprintln!("no catalogue; skipped") };
        let kit = Kit::of(&world, &cat, 0).expect("a fighter and a cargo hull");
        world.coords[2] = Coord { galaxy: 1, system: 60, position: 8 };
        world.games[2].planet.res = [1e9; 3];
        world.games[0].planet.res = [1e7; 3];
        world.games[1].planet.res = [5e6; 3];
        assert!(world.set_units(&cat, 2, kit.fighter, 100) && world.set_units(&cat, 0, kit.cargo, 1_000), "both hold ships");
        world.reports.push(SpyReport { owner: 2, target: 1, time: 0.0, resources: [5_000_000; 3], debris: None, counter_chance: 0, ships: Some(vec![0; world.yards[1].counts.len()]), defence: Some(vec![0; world.yards[1].counts.len()]), buildings: None, research: None });
        world.dispatch(&cat, 2, 1, Mission::Attack, &[(kit.fighter, 100)], [0.0; 3], 10.0).expect("the raid flies");
        let mut ledger = Ledger::default();
        assert!(!join_raid(&mut world, &cat, 0, |_| true, &mut ledger), "no join outside the alliance");
        world.alliance_at[0] = Some(0.0);
        world.alliance_at[2] = Some(0.0);
        assert!(join_raid(&mut world, &cat, 0, |_| true, &mut ledger), "the member joins its mate's raid");
        assert!(!join_raid(&mut world, &cat, 0, |_| true, &mut ledger), "one join per raid");
        let (raid, partner) = (&world.fleets[0], world.fleets.iter().find(|f| f.owner == 0).expect("the partner flies"));
        assert_eq!((raid.union, raid.arrive), (partner.union, partner.arrive), "the union lands together");
        while !world.events.iter().any(|e| e.kind == EventKind::Return && e.owner == 0) {
            let a = world.next_decision(&cat).expect("the world plays on");
            world.wait(&cat, a);
        }
        assert_eq!(world.union_log.len(), 1, "one partner fought in the union");
        let home = world.events.iter().find(|e| e.kind == EventKind::Return && e.owner == 0).unwrap();
        assert!(home.gained.iter().sum::<f64>() > 0.0, "the partner brings home its share of the plunder");
    }

    /// Wreck fields: a raid that wipes out a planet's fleet leaves a wreck; the owner with a space dock starts the repairs at
    /// its next login and, once they are done, takes the repaired share of the lost ships back. Without a dock nothing starts.
    #[test]
    fn a_player_repairs_the_wreck_of_its_lost_fleet_at_the_space_dock() {
        let Some((cat, mut world)) = bare(5) else { return eprintln!("no catalogue; skipped") };
        let wreck = world.rules[1].settings.wreck.clone().expect("fleet settings v6");
        let fighter = Kit::of(&world, &cat, 0).expect("a fighter hull").fighter;
        assert!(world.set_units(&cat, 0, fighter, 100_000) && world.set_units(&cat, 1, fighter, 1_000), "both hold fighters");
        world.games[0].planet.res = [1e9; 3];
        world.dispatch(&cat, 0, 1, Mission::Attack, &[(fighter, 100_000)], [0.0; 3], 10.0).expect("the raid flies");
        while !world.events.iter().any(|e| e.kind == EventKind::Fight) {
            let a = world.next_decision(&cat).expect("the world plays on");
            world.wait(&cat, a);
        }
        let field = world.wrecks.get(&1).and_then(|f| f.first()).expect("the lost fleet leaves a wreck").clone();
        let kept = (1_000.0 * wreck.percentage[1] / 100.0).floor() as i64;
        assert_eq!(field.ships, vec![(fighter, kept)], "the dock's share of the lost fighters");
        let mut ledger = Ledger::default();
        world.games[1].t = world.events.last().unwrap().time;
        tend_wrecks(&mut world, &cat, 1, &mut ledger);
        assert_eq!(world.wrecks[&1][0].state, WreckState::Active, "no repairs without a space dock");
        let dock = cat.objects.iter().position(|o| o.id == wreck.dock_object).expect("a space dock in the catalogue");
        world.games[1].planet.levels[dock] = 1;
        tend_wrecks(&mut world, &cat, 1, &mut ledger);
        let repairing = world.wrecks[&1][0].clone();
        assert_eq!(repairing.state, WreckState::Repairing, "the owner starts the repairs");
        world.games[1].t = repairing.completes;
        tend_wrecks(&mut world, &cat, 1, &mut ledger);
        assert!(world.wrecks[&1].is_empty() && ledger.repaired == kept, "the repaired fighters are back");
        assert_eq!(on_shelf(&world, 1, fighter), kept);
    }

    /// Moon raids: a raider spies a neighbour's moon and raids the stock and the cargo ships parked there; without the
    /// habit it leaves the moon alone.
    #[test]
    fn a_raider_spies_and_raids_a_neighbours_moon() {
        for habit in [false, true] {
            let Some((cat, mut world)) = bare(5) else { return eprintln!("no catalogue; skipped") };
            let kit = Kit::of(&world, &cat, 0).expect("a fighter, a cargo hull and a probe");
            let moon = world.add_moon(&cat, 1, 8000, 0.0);
            world.games[moon].planet.res = [2e6; 3];
            assert!(world.set_units(&cat, moon, kit.cargo, 5), "a fleet parked on the moon");
            for (id, n) in [(kit.fighter, 5_000), (kit.cargo, 500), (kit.probe, 10)] { assert!(world.set_units(&cat, 0, id, n)) }
            world.games[0].planet.res = [1e7; 3];
            let mut raider = Raider { moon_raids: habit, ..Raider::new(1) };
            let mut ledger = Ledger::default();
            for _ in 0..5_000 {
                if world.events.iter().any(|e| e.kind == EventKind::Fight && e.target == moon) { break }
                let Some(a) = world.next_decision(&cat) else { break };
                if a == 0 { raider.act(&mut world, &cat, 0, &mut ledger); }
                world.wait(&cat, a);
            }
            let raided = world.events.iter().any(|e| e.kind == EventKind::Fight && e.target == moon && e.gained.iter().sum::<f64>() > 0.0);
            assert_eq!(raided, habit, "the moon is raided only with the habit");
        }
    }

    /// Planet moves: with the dark matter, an account moves a colony from another system into a free slot of its home
    /// system; the move lands a day later and the price is paid then. Without the dark matter nothing is asked.
    #[test]
    fn an_account_moves_a_far_colony_into_its_home_system() {
        let Some((cat, mut world)) = bare(5) else { return eprintln!("no catalogue; skipped") };
        let cost = world.rules[0].settings.relocation.as_ref().expect("fleet settings v7").cost;
        let home = world.coords[0];
        let colony = world.add_planet(&cat, 0, world.games[0].planet.config, Coord { galaxy: home.galaxy, system: home.system + 40, position: 9 }, 0.0);
        let mut ledger = Ledger::default();
        world.dark_matter[0] = cost - 1;
        assert!(!relocate_colony(&mut world, 0, &mut ledger), "short of dark matter");
        world.dark_matter[0] = cost + 5;
        assert!(relocate_colony(&mut world, 0, &mut ledger), "the colony is asked to move");
        assert!(!relocate_colony(&mut world, 0, &mut ledger), "one move at a time");
        while world.move_log.is_empty() && world.moved_at.is_empty() {
            let a = world.next_decision(&cat).expect("the world plays on");
            world.wait(&cat, a);
        }
        let at = world.coords[colony];
        assert_eq!((at.galaxy, at.system, world.dark_matter[0]), (home.galaxy, home.system, 5), "the colony stands in the home system, paid for");
        assert_eq!(world.move_log.len(), 1);
    }

    /// M4: a fleeter's warships deploy to its capital's moon; with a gate on two moons the fleet jumps away from the first
    /// and, once the gates have rested, back home to it.
    #[test]
    fn a_fleeter_keeps_its_fleet_on_its_moon_and_moves_it_through_the_gates() {
        let Some((cat, mut world)) = bare(5) else { return eprintln!("no catalogue; skipped") };
        let gear = world.rules[0].settings.moon_gear.clone().expect("fleet settings v3");
        let gate = cat.objects.iter().position(|o| o.id == gear.gate_object).expect("a jump gate in the catalogue");
        let rules = Arc::clone(&world.rules[0]);
        let warship = *gear.gate_ships.iter().find(|&&id| rules.units.index(id as i64).is_some_and(|u| rules.units.kind.get(u) == Some(&UnitKind::MilitaryShip))).expect("a warship the gate carries");
        let moon = world.add_moon(&cat, 0, 8000, 0.0);
        let colony = world.add_planet(&cat, 0, 0, Coord { galaxy: 1, system: 11, position: 6 }, 0.0);
        let other = world.add_moon(&cat, colony, 8000, 0.0);
        assert!(world.set_units(&cat, 0, warship, 50));
        world.games[0].planet.res = [1e7; 3];
        let mut ledger = Ledger::default();
        station(&mut world, &cat, 0, moon, &mut ledger);
        while !world.fleets.is_empty() {
            let a = world.next_decision(&cat).expect("the deploy lands");
            world.wait(&cat, a);
        }
        let u = rules.units.index(warship as i64).unwrap();
        assert_eq!((world.yards[0].counts[u], world.yards[moon].counts[u]), (0, 50), "the fleet stands on the moon");
        assert!(world.games[moon].planet.res[2] > 0.0, "with the deuterium for its own save");
        assert_eq!(gate_escape(&mut world, &cat, moon), None, "no gate yet");
        world.games[moon].planet.levels[gate] = 1;
        world.games[other].planet.levels[gate] = 1;
        assert_eq!(gate_escape(&mut world, &cat, moon), Some(other));
        gate_home(&mut world, &cat, moon);
        assert_eq!(world.yards[other].counts[u], 50, "the gates rest before the fleet comes back");
        world.gate_ready.iter_mut().for_each(|t| *t = 0.0);
        gate_home(&mut world, &cat, moon);
        assert_eq!((world.yards[moon].counts[u], world.yards[other].counts[u]), (50, 0));
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

    /// Late game: the yard fills the doctrine's template, a unit not held first, and never one the class may not build.
    #[test]
    fn the_yard_fills_the_doctrine_template_with_what_the_class_may_build() {
        let Some((cat, mut world)) = bare(5) else { return eprintln!("no catalogue; skipped") };
        let planet = &mut world.games[0].planet;
        planet.levels = cat.objects.iter().map(|o| (o.price.len() as u32 - 1).min(12)).collect();
        cat.refresh(planet);
        planet.res = [5e7, 5e7, 5e7];
        let rules = Arc::clone(&world.rules[0]);
        let units = &rules.units;
        let defences: Vec<usize> = (0..units.units.len()).filter(|&u| units.kind.get(u) == Some(&UnitKind::Defence) && units.max_amount(&world.yards[0], &world.games[0].planet, u) > 0).take(3).collect();
        assert_eq!(defences.len(), 3, "the test needs three defences the planet can build");
        let [held, wanted, barred] = [defences[0], defences[1], defences[2]];
        world.yards[0].counts[held] = 100;
        world.yards[0].allowed[barred] = false;
        let mut miner = Miner::new(5);
        miner.yard = true;
        miner.doctrine.defence = [held, wanted, barred].iter().map(|&u| (units.units[u].id as u32, 1.0)).collect();
        miner.spend_surplus(&mut world, &cat, 0, None);
        let ordered: Vec<usize> = world.yards[0].orders.iter().map(|o| o.unit).collect();
        assert_eq!(ordered, vec![wanted], "the template's first unit not held, and the class's bar kept");
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
