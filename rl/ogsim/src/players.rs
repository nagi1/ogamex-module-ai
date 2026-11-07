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
/// ponytail: fixed probe ceiling; replace with a measured target-specific risk budget if needed.
const MAX_FOLLOWUP_PROBES: i64 = 32;
/// The interplanetary missiles a raider keeps once a target's defence has turned a raid away: a salvo, as players
/// fire them (gate 3); the silo's capacity, the host's, bounds what the yard takes.
pub(crate) const MISSILE_SALVO: i64 = 20;
/// The espionage level from which a player's event list shows a hostile fleet's per-type amounts (the host's
/// `IncomingFleetIntelLevel::Full`); below it the counts an ally would size a hold from are not known.
const FULL_FLEET_INTEL: u32 = 8;

pub(crate) fn weighted(r: [f64; 3]) -> f64 {
    (0..3).map(|i| WEIGHTS[i] * r[i]).sum()
}

/// What the players did, for the table and the checks.
#[derive(Clone, Default)]
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
    /// Coordination messages and launches, not a reward.
    pub war_ready_logins: usize,
    pub war_reports: usize,
    pub war_opportunities: usize,
    pub war_requests: usize,
    pub war_acceptances: usize,
    pub war_launches: usize,
    /// Why an accepted request did not fly (diagnostic): expired unread, leader's ships away, acceptance older than an
    /// hour, report stale or gone, strength recheck failed, dispatch refused.
    pub war_launch_refused: [usize; 6],
    /// `Raider::raid` per target looked at: no report, stale/cooldown/beaten, no plan (too strong), loot under the fuel bar,
    /// too risky (strike instead), fleet home first, dispatch refused, launched.
    pub raid_gates: [usize; 8],
    /// Of `raid_gates[2]` (no plan): the report lacks ships or defence (too few probes); too strong for the shelf; fuel.
    pub no_plan: [usize; 3],
    /// Arm `guess`: raids looked at on a redacted report, of them planned on the estimate, launched; and the launched
    /// ones' outcomes (won, draw, beaten) with their loot and loss (metal equivalent).
    pub guess_gates: [usize; 3],
    /// Diagnostic: espionage arrivals that met counter-espionage (a fight; every ledger reads the shared log, so the
    /// season sum counts each once per ledger); scout looks with no idle probe while probes sit in the yard queue, or
    /// none is queued (all out flying, or none ordered).
    pub probe_diag: [usize; 3],
    pub guess_outcomes: [usize; 3],
    pub guess_value: [f64; 2],
    guessed: HashSet<(usize, usize, u64)>,
    /// Of `raid_gates[1]`: report past the intel window, inside the raid cooldown, beaten there before, the bashing
    /// limit reached (arm `bodies`), a protected newbie (arm `newbie`).
    pub stale_split: [usize; 5],
    /// `Raider::spy` under `raids` per target reached: fresh intel, probe out, no probe on the shelf, a volley short of
    /// the wanted count, a full volley, a re-scout of a redacted report.
    pub spy_gates: [usize; 6],
    /// Why an ally said no (diagnostic): leader's report stale or gone, lands after the leader, cannot fuel, strength test.
    pub war_accept_refused: [usize; 4],
    /// Why an outmatched leader asked nobody (diagnostic): cannot fuel the strike, no mate in the alliance to ask.
    pub war_request_refused: [usize; 2],
    /// Wreck fields: ships repaired at the space dock and taken back.
    pub repaired: i64,
    /// Colonies asked to move next to the home planet.
    pub relocations: usize,
    /// How many events of the world's log have been read.
    read: usize,
}

#[derive(Clone)]
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
    /// How far into the world's event log this ledger has read.
    pub fn read(&self) -> usize { self.read }

    /// The cursor moved back by `by` events, for a copy of the world whose log starts `by` events later.
    pub fn rebase(&mut self, by: usize) { self.read = self.read.saturating_sub(by) }

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
                    if self.guessed.remove(&(e.owner, e.target, e.time.to_bits())) {
                        self.guess_outcomes[match e.outcome { Outcome::Won => 0, Outcome::Defeated => 2, _ => 1 }] += 1;
                        self.guess_value[0] += weighted(e.gained);
                        self.guess_value[1] += e.ships_lost.iter().map(|&(id, n)| price(e.owner, id) * n as f64).sum::<f64>();
                    }
                    let research = w.research(cat, e.target);
                    let stood = e.present.iter().any(|&(id, _)| movable(&w.rules[e.target], id, &research));
                    if let Some(r) = self.raids.iter_mut().find(|r| r.attacker == e.owner && r.target == e.target && r.arrive == e.time) {
                        r.loot = Some(e.gained);
                        r.saved = !stood;
                    }
                }
                (EventKind::Fight, Mission::Espionage) => self.probe_diag[0] += 1,
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

fn movable(rules: &Rules, id: u32, research: &crate::Levels) -> bool {
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
        let missions = &rules.combat.missions;
        let probe = *missions.espionage_units.first()?;
        let harvester = *missions.harvesters.values().next()?;
        // What the rules and the research alone decide is kept per slot until either changes (`World::kit_cache`).
        let levels = || cat.objects.iter().zip(&w.games[w.account[a]].planet.levels).filter(|(o, _)| o.queue == 1).map(|(_, &l)| l);
        let mut cache = w.kit_cache.0.lock().expect("kit cache");
        if !cache.get(&a).is_some_and(|e| Arc::ptr_eq(&e.rules, rules) && e.levels.iter().copied().eq(levels())) {
            let levels: Vec<u32> = levels().collect();
            let research = w.research(cat, a);
            // Every ship but the probe and the harvesters that can fly, asked once: both passes below read only these.
            let ships: Vec<(usize, u32)> = rules.units.units.iter().enumerate()
                .map(|(u, unit)| (u, unit.id as u32))
                .filter(|&(_, id)| id != probe && !missions.harvesters.values().any(|&h| h == id) && movable(rules, id, &research))
                .collect();
            let cargo = ships.iter()
                .map(|&(_, id)| (id, rules.combat.unit_capacity(id, &research) as f64 / rules.units.units[rules.units.index(id as i64).unwrap()].price.iter().sum::<f64>().max(1.0)))
                .filter(|c| c.1 > 0.0)
                .max_by(|x, y| x.1.total_cmp(&y.1)).map(|c| c.0);
            // Whether each shoots, asked only of the ships the fighter pass reads (all but the cargo ship).
            let ships = ships.into_iter().map(|(u, id)| (u, id, Some(id) != cargo && rules.combat.fights(id) && rules.combat.attack(id, &research) as f64 > 1.0)).collect();
            cache.insert(a, crate::world::KitEntry { rules: Arc::clone(rules), levels, ships, cargo });
        }
        let entry = &cache[&a];
        let cargo = entry.cargo?;
        // `owned` per ship, from one pass over the yard's orders and the account's fleets (integer sums: order-free).
        let yard = &w.yards[a];
        let mut held: Vec<i64> = yard.counts.iter().map(|&n| n as i64).collect();
        for o in &yard.orders { held[o.unit] += (o.amount - o.done) as i64 }
        for f in w.fleets.iter().filter(|f| f.owner == a) {
            for &(id, n) in &f.ships {
                if let Some(u) = rules.units.index(id as i64) { held[u] += n }
            }
        }
        let owns = |id: u32| rules.units.index(id as i64).is_some_and(|u| held[u] > 0);
        // The cheapest shooter among ships the account holds or can build; failing those, among all movable ones.
        let best = |usable: &dyn Fn(usize, u32) -> bool| entry.ships.iter()
            .filter(|&&(u, id, _)| id != cargo && usable(u, id))
            .filter(|s| s.2)
            .min_by(|x, y| weighted(rules.units.units[x.0].price).total_cmp(&weighted(rules.units.units[y.0].price))).map(|c| c.1);
        let fighter = best(&|u, id| owns(id) || rules.units.max_amount(yard, &w.games[a].planet, u) > 0).or_else(|| best(&|_, _| true))?;
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

/// Arm `warshare`: the military share without the civil ships' half, the share the persona's yard aims at (measured,
/// cap-e: top10 savers met 0.20 with cargo stacks alone and held no warship; their template is 84% Deathstar by value).
fn war_share(w: &World, a: usize) -> f64 {
    let score = score::of(w, w.account_of(a));
    let war = score.military as f64 - score::civil(w, w.account_of(a)) as f64 / 2.0;
    if score.total > 0 { war / score.total as f64 } else { 0.0 }
}

pub(crate) fn owned(w: &World, a: usize, id: u32) -> i64 {
    let Some(u) = w.rules[a].units.index(id as i64) else { return 0 };
    let queued: u64 = w.yards[a].orders.iter().filter(|o| o.unit == u).map(|o| o.amount - o.done).sum();
    let flying: i64 = w.fleets.iter().filter(|f| f.owner == a).flat_map(|f| f.ships.iter()).filter(|s| s.0 == id).map(|s| s.1).sum();
    (w.yards[a].counts[u] + queued) as i64 + flying
}

fn flying(w: &World, a: usize, id: u32) -> i64 {
    w.fleets.iter().filter(|f| f.owner == a).flat_map(|f| f.ships.iter()).filter(|s| s.0 == id).map(|s| s.1).sum()
}

pub(crate) fn on_shelf(w: &World, a: usize, id: u32) -> i64 {
    w.rules[a].units.index(id as i64).map_or(0, |u| w.yards[a].counts[u] as i64)
}

pub(crate) fn account_distance(w: &World, a: usize, b: usize) -> i64 {
    fleet::distance(&w.rules[a].settings, w.coords[a], w.coords[b], &|_, _| SystemState::Active)
}

/// The other accounts (their capitals) by coordinate, closest first.
fn others_by_distance(w: &World, a: usize) -> Vec<usize> {
    let mut cache = w.near_cache.0.lock().expect("near cache");
    if let Some(Some(e)) = cache.get(a) {
        if Arc::ptr_eq(&e.rules, &w.rules[a]) && e.slots == w.games.len() && e.at == w.coords[a] && e.account == w.account
            && e.others.iter().all(|&(b, at)| w.coords[b] == at) {
            return e.others.iter().map(|&(b, _)| b).collect();
        }
    }
    let mut others: Vec<usize> = (0..w.games.len()).filter(|&b| b != a && w.account_of(b) == b).collect();
    others.sort_by_cached_key(|&b| (account_distance(w, a, b), b));
    if cache.len() <= a { cache.resize(a + 1, None) }
    cache[a] = Some(crate::world::NearEntry {
        rules: Arc::clone(&w.rules[a]), slots: w.games.len(), account: w.account.clone(), at: w.coords[a],
        others: others.iter().map(|&b| (b, w.coords[b])).collect(),
    });
    others
}

/// The nearest other accounts, the inactive ones (OGame's 'i') before the rest, each group closest first.
pub(crate) fn nearest(w: &mut World, cat: &Catalogue, a: usize, n: usize) -> Vec<usize> {
    let now = w.games[a].t;
    let mut others = others_by_distance(w, a);
    let mut keyed: Vec<(bool, usize)> = others.iter().map(|&b| (!w.inactive(cat, b, now), b)).collect();
    keyed.sort_by_key(|k| k.0);
    let mut others: Vec<usize> = keyed.into_iter().map(|k| k.1).collect();
    others.truncate(n);
    others
}

/// Arm `bodies`: the n bodies (planets and their moons) of other accounts nearest to account a's capital, the inactive
/// accounts' before the rest, each group closest first: the module's candidate query (QueueableSpyPlanner) reads every
/// planet and moon the galaxy view shows, not only the homeworlds.
pub(crate) fn nearest_bodies(w: &mut World, cat: &Catalogue, a: usize, n: usize) -> Vec<usize> {
    let now = w.games[a].t;
    let mut keyed: Vec<(bool, i64, usize)> = Vec::new();
    for owner in others_by_distance(w, a) {
        let quiet = w.inactive(cat, owner, now);
        for p in w.planets_of(owner) {
            for body in std::iter::once(p).chain(w.moon_of[p]) {
                keyed.push((!quiet, account_distance(w, a, body), body));
            }
        }
    }
    keyed.sort_unstable();
    keyed.into_iter().take(n).map(|k| k.2).collect()
}

/// OGame's newbie protection (WIK-045; serverData `newbieProtectionHigh` 50,000 and `newbieProtectionLimit` 500,000 on
/// 257 of 267 live universes): under the high mark a player is protected from anyone 5 times its points, up to the
/// limit from anyone 10 times, and above the limit from nobody.
pub(crate) const NEWBIE_HIGH: f64 = 50_000.0;
pub(crate) const NEWBIE_LIMIT: f64 = 500_000.0;

/// Whether a player on `weak` points is protected from one on `strong` points (PlayerService::protectsNewbie).
pub(crate) fn newbie_protects(weak: f64, strong: f64) -> bool {
    weak < NEWBIE_LIMIT && weak * if weak < NEWBIE_HIGH { 5.0 } else { 10.0 } < strong
}

/// Arm `newbie`: the galaxy view's newbie mark (PlayerService::isNewbie, RaidPlanner::protectedNewbie): the target's
/// owner is active and protected from the raider's points (`newbie_protects`), so the raider leaves it alone.
pub(crate) fn protected_newbie(w: &mut World, cat: &Catalogue, a: usize, target: usize, now: f64) -> bool {
    let owner = w.account_of(target);
    if w.inactive(cat, owner, now) { return false }
    newbie_protects(score::of(w, owner).total as f64, score::of(w, w.account_of(a)).total as f64)
}

/// Attacks one account may land on one body in 24 h (RaidPlanner::BASHING_LIMIT, OGame's in-game bashing protection).
pub(crate) const BASH_LIMIT: usize = 6;

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
pub(crate) fn strength(rules: &Rules, research: &crate::Levels, units: &[(u32, i64)]) -> (f64, f64) {
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
    w.last_report_of(a, target)
        // Arm `freshspy`: a report older than the account's own last hit there no longer describes the body.
        .filter(|r| !w.fresh_spy || w.own_hits.get(&(w.account_of(a), target)).is_none_or(|&hit| r.time > hit))
}

/// Use observed research when available; otherwise estimate equal technology from the observer own levels.
pub(crate) fn report_research(cat: &Catalogue, report: &SpyReport, own: &crate::Levels) -> crate::Levels {
    report.research.as_ref().filter(|levels| levels.len() == cat.objects.len()).map_or_else(|| own.clone(), |levels| cat.objects.iter().zip(levels)
        .filter(|(o, _)| o.queue == 1).map(|(o, &l)| (o.id as u32, l)).collect())
}

/// The raider's sizing of a raid: fighters enough to take the defenders' hull down in about three rounds, cargo ships
/// for the loot that fits. `None` when the account does not hold that fleet, the report lacks the fleet or defence
/// section, or there is nothing to take. Payoff and safety stay with the caller (the raider's rule, or an errand's
/// mask), so the one sizing serves both.
pub(crate) fn raid_plan(w: &World, cat: &Catalogue, a: usize, target: usize, kit: Kit) -> Option<RaidPlan> {
    if w.raids { return module_plan(w, cat, a, target, kit) }
    let report = last_report(w, a, target)?;
    let (Some(ships), Some(defence)) = (&report.ships, &report.defence) else { return None };
    let rules = &w.rules[a];
    let research = w.research(cat, a);
    let hold = rules.combat.capacity(&[(kit.cargo, 1)].into_iter().collect(), &research).max(1);
    let loot = report.resources.map(|r| r.max(0) as f64 * rules.combat.rules.loot_percentage / 100.0);
    let mut guns = stack(&w.rules[target], ships);
    guns.extend(stack(&w.rules[target], defence));
    let observed_research = report_research(cat, report, &research);
    let (attack, defending_hull) = strength(&w.rules[target], &observed_research, &guns);
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

/// Whether `raid_plan` fails because the account holds too few fighters for the reported guns (diagnostics only).
fn raid_plan_shelf_short(w: &World, cat: &Catalogue, a: usize, target: usize, kit: Kit) -> bool {
    let Some(report) = last_report(w, a, target) else { return false };
    let (Some(ships), Some(defence)) = (&report.ships, &report.defence) else { return false };
    let research = w.research(cat, a);
    let mut guns = stack(&w.rules[target], ships);
    guns.extend(stack(&w.rules[target], defence));
    let (_, hull) = strength(&w.rules[target], &report_research(cat, report, &research), &guns);
    let (each, _) = strength(&w.rules[a], &research, &[(kit.fighter, 1)]);
    hull > 0.0 && (hull * 1.5 / (3.0 * each.max(1e-9))).ceil() as i64 > on_shelf(w, a, kit.fighter)
}

/// Arm `raids`: the live module's probe volley (QueueableSpyPlanner::probeCount): one probe in the opening (no settled
/// colony yet), `VOLLEY_PROBES` after; a target whose last report is redacted but shows a fleet, a defence or a rich
/// stock gets `VOLLEY_PROBES` doubled per redacted report in a row (at most `VOLLEY_DOUBLINGS` times), since the host
/// hides ships below 2 and defence below 3 probes plus the square of the espionage gap (EspionageMission).
pub(crate) fn spy_volley(w: &World, a: usize, target: usize) -> i64 {
    let floor = volley_floor(w, a);
    let Some(report) = last_report(w, a, target) else { return floor };
    let complete = |r: &SpyReport| r.ships.is_some() && r.defence.is_some();
    if complete(report) { return floor }
    let shows = |section: &Option<Vec<u64>>| section.as_ref().is_some_and(|v| v.iter().any(|&n| n > 0));
    let defended = shows(&report.ships) || shows(&report.defence);
    if !defended && weighted(report.resources.map(|r| r.max(0) as f64)) < RICH_YIELD { return floor }
    let redacted = redacted_streak(w, a, target, VOLLEY_DOUBLINGS + 1);
    if w.rescout { return rescout_volley(floor, redacted) }
    floor.max(VOLLEY_PROBES * 2_i64.pow(redacted.max(1) as u32 - 1))
}

/// Arm `rescout`: the volley after `redacted` redacted reports in a row. The last one was read by at least the floor's
/// volley, so the next doubles from there too, up to `VOLLEY_PROBES` doubled `VOLLEY_DOUBLINGS` times.
fn rescout_volley(floor: i64, redacted: usize) -> i64 {
    if redacted == 0 { return floor }
    (VOLLEY_PROBES << (redacted - 1)).max(floor << redacted).min(VOLLEY_PROBES << VOLLEY_DOUBLINGS)
}

/// The account's own reports on the target that came back redacted, newest first and in a row, counted up to `cap`.
fn redacted_streak(w: &World, a: usize, target: usize, cap: usize) -> usize {
    let mine: Vec<&SpyReport> = w.reports_of(a).filter(|r| r.target == target).collect();
    mine.iter().rev().take(cap).take_while(|r| r.ships.is_none() || r.defence.is_none()).count()
}

/// Arm `rescout`: the last report is redacted, the body is worth a volley (`spy_volley` escalates on it) and the next
/// volley is larger than the one that read it; once the largest has come back redacted too, the espionage gap is too
/// wide for probes and the intel window decides.
pub(crate) fn rescout_due(w: &World, a: usize, target: usize) -> bool {
    let floor = volley_floor(w, a);
    let redacted = redacted_streak(w, a, target, VOLLEY_DOUBLINGS + 2);
    spy_volley(w, a, target) > floor && rescout_volley(floor, redacted) > rescout_volley(floor, redacted - 1)
}

/// One probe in the opening (no settled colony yet), `VOLLEY_PROBES` after (QueueableSpyPlanner::probeCount's floor).
fn volley_floor(w: &World, a: usize) -> i64 {
    if w.planets_of(w.account_of(a)).len() < 2 { 1 } else { VOLLEY_PROBES }
}

/// QueueableSpyPlanner's escalated volley, its doublings and the yield that makes a redacted report worth one.
pub(crate) const VOLLEY_PROBES: i64 = 5;
const VOLLEY_DOUBLINGS: usize = 3;
/// QueueableUnitPlanner::PROBE_STOCK: the idle probes each planet keeps past the opening, flying ones not counted.
const PROBE_STOCK: i64 = 15;
const RICH_YIELD: f64 = 10_000.0;
const RAID_CANDIDATES: usize = 20;
/// RaidPlanner::lossTolerance for the raider archetype: the share of the loot it risks on the fight's tail.
const RAIDER_LOSS_TOLERANCE: f64 = 0.5;

/// Arm `raids`: the live module's launch (RaidPlanner::launchUnits and its profit gate): cargo for the loot, then whole
/// stocks of warships in counter order (the host's rapid fire against the reported mix minus its rapid fire back, then
/// attack per price) until the forecast fight on the report is won; refused when the expected loss outweighs the loot
/// plus the raider's loss tolerance, or the stock cannot fuel it. The fleet is what the economy built, not a fixed count.
pub(crate) fn module_plan(w: &World, cat: &Catalogue, a: usize, target: usize, kit: Kit) -> Option<RaidPlan> {
    let report = last_report(w, a, target)?;
    let rules = &w.rules[a];
    let trules = &w.rules[target];
    let research = w.research(cat, a);
    let observed_research = report_research(cat, report, &research);
    let (ships, defence) = match (&report.ships, &report.defence) {
        (Some(ships), Some(defence)) => (ships.clone(), defence.clone()),
        _ if w.guess => guessed_sections(w, a, target, report, &observed_research)?,
        _ => return None,
    };
    let mut mix = stack(trules, &ships);
    mix.extend(stack(trules, &defence));
    mix.retain(|&(id, _)| trules.combat.fights(id));
    let stock = report.resources.map(|r| r.max(0) as f64);
    let loot = stock.map(|r| r * rules.combat.rules.loot_percentage / 100.0);
    let hold = rules.combat.capacity(&[(kit.cargo, 1)].into_iter().collect(), &research).max(1);
    let cargo = ((loot.iter().sum::<f64>() / hold as f64).ceil() as i64).clamp(0, on_shelf(w, a, kit.cargo));
    let destroyer = rules.strike.as_ref().map(|s| s.moon_destroyer);
    let mut pool: Vec<(u32, i64)> = warships(rules, &research, &w.yards[a].counts).into_iter()
        .filter(|&(id, _)| Some(id) != destroyer && rules.combat.fights(id)).collect();
    let counter = |id: u32| mix.iter().map(|&(t, n)| (rules.combat.rapidfire(id, t) - trules.combat.rapidfire(t, id)) * n).sum::<i64>();
    let per_cost = |id: u32| rules.combat.attack(id, &research) as f64 / unit_price(rules, id).max(1.0);
    pool.sort_by(|x, y| counter(y.0).cmp(&counter(x.0)).then(per_cost(y.0).total_cmp(&per_cost(x.0))));
    let mut fleet: Vec<(u32, i64)> = if cargo > 0 { vec![(kit.cargo, cargo)] } else { Vec::new() };
    let mut loss = 0.0;
    if mix.is_empty() {
        // Nothing fights back: cargo plus one cheapest kill hull (RaidPlanner::withKillHull).
        if let Some(&(id, _)) = pool.iter().min_by(|x, y| unit_price(rules, x.0).total_cmp(&unit_price(rules, y.0))) { fleet.push((id, 1)) }
    } else {
        let mut won = false;
        for &hull in &pool {
            fleet.push(hull);
            let (lost, win) = fight(w, (a, &fleet, &research), (target, &mix, &observed_research), stock, 200.0);
            if win >= 1.0 { loss = lost; won = true; break }
        }
        if !won { return None }
    }
    if fleet.is_empty() { return None }
    let carried = rules.combat.capacity(&fleet.iter().copied().collect(), &research);
    let expected = combat::distribute_loot(loot, carried);
    let tolerance = if mix.is_empty() { 0.0 } else { RAIDER_LOSS_TOLERANCE };
    if weighted(expected) - loss + tolerance * weighted(expected) <= 0.0 { return None }
    let (attack, _) = strength(trules, &observed_research, &mix);
    let (_, hull) = strength(rules, &research, &fleet);
    let distance = fleet::distance(&rules.settings, w.coords[a], w.coords[target], &|_, _| SystemState::Active);
    let fuel = fleet::consumption(&rules.settings, &rules.fleet, &fleet, &research, distance, 0, 10.0) as f64;
    if fuel > w.games[a].planet.res[2] || rules.combat.fuel_capacity(&fleet.iter().copied().collect(), &research) < fuel as i64 { return None }
    Some(RaidPlan { fleet, expected, attack, hull })
}

/// Arm `guess`: a redacted report's missing sections, filled with the worst case a player can read (ReportedPlanet::
/// estimated): every public military point (the highscore's, the account's ships and defence at full price, civil ships
/// at half) the visible sections do not explain stands on this body, in the mix the account's own last report that read
/// the section saw here, or else as the cheapest unit of the missing kind that fights with a real weapon. The forecast
/// fight and the profit gate then decide on that picture, as on a full report.
/// ponytail: the highscore is read live; the host refreshes it on a schedule.
pub(crate) fn guessed_sections(w: &World, a: usize, target: usize, report: &SpyReport, observed: &crate::Levels) -> Option<(Vec<u64>, Vec<u64>)> {
    let rules = &w.rules[target];
    let units = &rules.units;
    let points = |counts: &[u64]| -> f64 {
        counts.iter().enumerate().map(|(u, &n)| {
            let price = units.units[u].price.iter().sum::<f64>() * n as f64;
            if units.kind[u] == UnitKind::CivilShip { price * 0.5 } else { price }
        }).sum()
    };
    let shown: f64 = [&report.ships, &report.defence].into_iter().flatten().map(|v| points(v)).sum();
    let hidden = (crate::score::of(w, w.account_of(target)).military as f64 * 1000.0 - shown).max(0.0);
    let mine: Vec<&SpyReport> = w.reports_of(a).filter(|r| r.target == target).collect();
    let older = |pick: fn(&SpyReport) -> &Option<Vec<u64>>| mine.iter().rev().find_map(|r| pick(r).clone());
    let blank = vec![0u64; units.units.len()];
    let mut template = (
        if report.ships.is_none() { older(|r| &r.ships).unwrap_or_else(|| blank.clone()) } else { blank.clone() },
        if report.defence.is_none() { older(|r| &r.defence).unwrap_or_else(|| blank.clone()) } else { blank.clone() },
    );
    if points(&template.0) + points(&template.1) <= 0.0 {
        let defence_missing = report.defence.is_none();
        let kind = if defence_missing { UnitKind::Defence } else { UnitKind::MilitaryShip };
        let cheapest = (0..units.units.len())
            .filter(|&u| units.kind[u] == kind && rules.combat.fights(units.units[u].id as u32) && rules.combat.attack(units.units[u].id as u32, observed) > 1)
            .min_by(|&x, &y| units.units[x].price.iter().sum::<f64>().total_cmp(&units.units[y].price.iter().sum::<f64>()))?;
        if defence_missing { template.1[cheapest] = 1 } else { template.0[cheapest] = 1 }
    }
    let scale = hidden / (points(&template.0) + points(&template.1));
    let fill = |seen: &Option<Vec<u64>>, guess: &[u64]| seen.clone().unwrap_or_else(|| guess.iter().map(|&n| (n as f64 * scale).ceil() as u64).collect());
    Some((fill(&report.ships, &template.0), fill(&report.defence, &template.1)))
}

/// What a raid holds against the target's last report, at host prices: the attacker's own fleet over the value of every
/// ship and defence the report shows. `None` without a report that read both sections.
pub(crate) fn reported_value(w: &World, a: usize, target: usize) -> Option<f64> {
    let report = last_report(w, a, target)?;
    let (Some(ships), Some(defence)) = (&report.ships, &report.defence) else { return None };
    let rules = &w.rules[target];
    Some(stack(rules, ships).into_iter().chain(stack(rules, defence)).map(|(id, n)| unit_price(rules, id) * n as f64).sum())
}

/// The value at host prices of a fleet of account a.
pub(crate) fn fleet_value(w: &World, a: usize, fleet: &[(u32, i64)]) -> f64 {
    fleet.iter().map(|&(id, n)| unit_price(&w.rules[a], id) * n as f64).sum()
}

/// Real attacks start at this attacker over defender value (OGMEM's p10, `ogmem-strategies.json` "odds") and aim at the
/// median.
pub(crate) const WAR_ODDS_FLOOR: f64 = 1.6;
pub(crate) const WAR_ODDS_AIM: f64 = 3.4;

/// The war fleet a player sends at a defended target: a slice of every warship on its shelf (its own mix, so the
/// fleet it built is the fleet it flies; the moon destroyer stays home for its own mission), sized from the last report
/// so the fleet's value is `WAR_ODDS_AIM` times the reported ships and defence, never under `WAR_ODDS_FLOOR`, plus
/// cargo hulls for the loot the warships cannot carry. `None` when the report shows nothing to fight, the shelf cannot
/// reach the floor, or the stock cannot fuel it at `speed`.
pub(crate) fn war_plan(w: &World, cat: &Catalogue, a: usize, target: usize, kit: Kit, speed: f64) -> Option<RaidPlan> {
    let defended = reported_value(w, a, target).filter(|&v| v > 0.0)?;
    let report = last_report(w, a, target)?;
    let rules = &w.rules[a];
    let research = w.research(cat, a);
    let destroyer = rules.strike.as_ref().map(|s| s.moon_destroyer);
    let pool: Vec<(u32, i64)> = warships(rules, &research, &w.yards[a].counts).into_iter().filter(|&(id, _)| Some(id) != destroyer && rules.combat.fights(id)).collect();
    let held = fleet_value(w, a, &pool);
    if held < WAR_ODDS_FLOOR * defended { return None }
    let share = (WAR_ODDS_AIM * defended / held).min(1.0);
    let mut fleet: Vec<(u32, i64)> = pool.iter().map(|&(id, n)| (id, ((n as f64 * share).ceil() as i64).min(n))).filter(|s| s.1 > 0).collect();
    let loot = report.resources.map(|r| r.max(0) as f64 * rules.combat.rules.loot_percentage / 100.0);
    let carried = rules.combat.capacity(&fleet.iter().copied().collect(), &research);
    let hold = rules.combat.capacity(&[(kit.cargo, 1)].into_iter().collect(), &research).max(1);
    let cargo = (((loot.iter().sum::<f64>() - carried as f64) / hold as f64).ceil() as i64).clamp(0, on_shelf(w, a, kit.cargo));
    if cargo > 0 { fleet.push((kit.cargo, cargo)) }
    let expected = combat::distribute_loot(loot, carried + cargo * hold);
    let observed_research = report_research(cat, report, &research);
    let mut guns = stack(&w.rules[target], report.ships.as_deref()?);
    guns.extend(stack(&w.rules[target], report.defence.as_deref()?));
    let (attack, _) = strength(&w.rules[target], &observed_research, &guns);
    let (_, hull) = strength(rules, &research, &fleet);
    let distance = fleet::distance(&rules.settings, w.coords[a], w.coords[target], &|_, _| SystemState::Active);
    let fuel = fleet::consumption(&rules.settings, &rules.fleet, &fleet, &research, distance, 0, speed) as f64;
    if fuel > w.games[a].planet.res[2] || rules.combat.fuel_capacity(&fleet.iter().copied().collect(), &research) < fuel as i64 { return None }
    Some(RaidPlan { fleet, expected, attack, hull })
}

/// The fight a raid expects from the last report alone: the host's engine on both sides scaled down to a few hundred
/// units (the report's counts, its research or else our own, never the target's live state), as (own loss at host
/// prices, 1 for a win, 0.5 for a draw, 0 for a loss). `None` without a full report.
pub(crate) fn forecast(w: &World, cat: &Catalogue, a: usize, target: usize, fleet: &[(u32, i64)]) -> Option<(f64, f32)> {
    const UNITS: f64 = 200.0;
    let report = last_report(w, a, target)?;
    let (Some(ships), Some(defence)) = (&report.ships, &report.defence) else { return None };
    let research = w.research(cat, a);
    let observed_research = report_research(cat, report, &research);
    let mut defender = stack(&w.rules[target], ships);
    defender.extend(stack(&w.rules[target], defence));
    Some(fight(w, (a, fleet, &research), (target, &defender, &observed_research), report.resources.map(|r| r.max(0) as f64), UNITS))
}

/// The host's engine on two sides as a player pictures them (slot whose tables, units, research), scaled down so the
/// larger side holds about `units`: (the attacker's loss at host prices, 1 for an attacker win, 0.5 a draw, 0 a loss).
pub(crate) fn fight(w: &World, attacking: (usize, &[(u32, i64)], &crate::Levels), defending: (usize, &[(u32, i64)], &crate::Levels), stock: [f64; 3], units: f64) -> (f64, f32) {
    let ((a, fleet, research), (target, defender, observed_research)) = (attacking, defending);
    let count = |side: &[(u32, i64)]| side.iter().map(|s| s.1).sum::<i64>() as f64;
    let scale = (count(fleet).max(count(defender)) / units).max(1.0);
    let shrink = |side: &[(u32, i64)]| -> Vec<(u32, i64)> { side.iter().map(|&(id, n)| (id, (n as f64 / scale).ceil() as i64)).collect() };
    let (attacker, defender) = (shrink(fleet), shrink(defender));
    let battle = combat::Battle {
        attacker: &attacker, partners: &[], attacker_research: research, attacker_cargo: [0.0; 3],
        defender: &defender, defender_research: observed_research, allies: &[],
        defender_stock: stock, defender_engineer: false, moon_exists: false, seed: 1,
    };
    let engine = combat::run_engine(&combat::engine_input(&w.rules[a].combat, &w.rules[target].combat, &battle));
    let lost: f64 = attacker.iter().map(|&(id, n)| (n - engine.attacker_left.get(&id).copied().unwrap_or(0)).max(0) as f64 * unit_price(&w.rules[a], id)).sum();
    let standing = |left: &combat::Units| left.values().any(|&n| n > 0);
    let win = match (standing(&engine.attacker_left), standing(&engine.defender_left)) {
        (true, false) => 1.0,
        (false, _) => 0.0,
        (true, true) => 0.5,
    };
    (lost * scale, win)
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
#[derive(Clone)]
pub struct Miner {
    rng: u64,
    /// Hold wanted, as a share of the stock's weight in cargo ships.
    pub(crate) cargo_share: f64,
    probes: i64,
    harvesters: i64,
    pub(crate) fighters: i64,
    /// Buys ships at every login it can pay for them, not only with an idle or overflowing store.
    eager: bool,
    /// The share of the host's own military score this persona aims for (row 10, S4): from the real mil_share medians
    /// (top10 0.058, mid 0.16, bottom 0.066), a taste over host data that names no object.
    pub(crate) military_share: f64,
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
    /// `wide` with an errand model: the expeditions are the errand's (`errands::Kind::Expedition`), so the login's own
    /// trips stay home; the cargo they need is still ordered.
    pub expedition_errand: bool,
    /// Arm `satcap`: the field chain's energy is bought in units only up to `CHAIN_UNITS_MAX` held (`power_by_units`).
    pub sat_cap: bool,
    /// Arm `warshare`: the yard's military share leaves the civil ships out (`war_share`).
    pub war_share: bool,
    /// Arm `postcount`: the fleet template counts the account's planets and moons as held (`template_pick`).
    pub post_count: bool,
    /// Arm `minerpost`: a miner's capital keeps its warships on its moon as a fleeter does (`station`; anomaly #1 on
    /// cap-e: miners' ~2T of warships sat on planets that have a moon, TP-023, CRASH-009).
    pub moon_post: bool,
    /// Arm `anchor`: the capital keeps its template's dearest hull once the yard may build it, one per 2e9 of warship
    /// value (at least one). LATE-GAME-FLEET-STORE.md (OGMEM, verified): real defenders hold it in 70% of 1e9-1e10
    /// fleets and 93% of 1e10-1e11 ones, median 1-2 below 1e10 and 11-27 above; most hold it for fleet saves.
    pub ds_anchor: bool,
    /// Row 94 arm: the idle lab takes its research without keeping the next building's price back (a player keeps the
    /// lab running; the building waits). Without it the capital's metal never covered both and the lab sat idle for weeks.
    pub lab_first: bool,
    /// `labahead`: the lab goes up between researches while a level pays for itself on the next one (`lab_ahead`).
    pub lab_ahead: bool,
    /// A grown account works towards the research network and raises its colonies' labs to the capital's (`network_step`).
    pub network: bool,
    /// The lab walks the requirements of what it wants, and a rich account keeps the cheapest research moving (`next_research`).
    pub tech_tree: bool,
    /// An account with idle surplus trades its richest resource into its poorest at the merchant (`trade_surplus`).
    pub merchant: bool,
    /// Arm `scrap`: the capital sells the flying ships it has no use for at the scrap merchant (host `MerchantController::
    /// scrapExecute`, 35% of the price, within free storage), as an expedition player does with its finds.
    pub scrap: bool,
    /// Arm `trim`: with `scrap`, the doctrine's hulls are kept up to their share of the template's fleet value (twice it),
    /// not without limit: real late fleets hold almost no light fighters or small cargo (OGMEM, 4 Nov), while expedition
    /// finds piled millions of them.
    pub trim: bool,
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
    /// `resources/behavior/defence-doctrines.yaml` `standing_wall_ceiling`: no planet holds more defence units than this,
    /// the rule the PHP player already follows; past it the surplus buys the fleet template. 0 leaves the wall unbounded.
    #[serde(default)]
    pub wall_ceiling: u64,
}

impl Doctrine {
    /// Whether account a's defences, at price, are short of its weight times its warships, as the host's kinds tell them.
    fn walled(&self, w: &World, a: usize) -> bool {
        let units = &w.rules[a].units;
        self.wall_ceiling > 0 && (0..units.units.len()).filter(|&u| units.kind.get(u) == Some(&UnitKind::Defence)).map(|u| w.yards[a].counts[u]).sum::<u64>() >= self.wall_ceiling
    }

    fn defence_due(&self, w: &World, a: usize) -> bool {
        if self.walled(w, a) { return false }
        let units = &w.rules[a].units;
        let value = |kind: UnitKind| (0..units.units.len()).filter(|&u| units.kind.get(u) == Some(&kind))
            .map(|u| owned(w, a, units.units[u].id as u32) as f64 * weighted(units.units[u].price)).sum::<f64>();
        !self.defence.is_empty() && value(UnitKind::Defence) < self.defence_weight * value(UnitKind::MilitaryShip)
    }
}

/// `ArchetypeDoctrine::templateGaps` followed by the planner's pick: among the template's units the yard can start, the one
/// furthest below its share by count, and any not held yet first. Held units of the template are the denominator.
/// `bodies` are the slots whose units count as held: the yard's own, or (arm `postcount`) the account's planets and moons,
/// where a fleeter keeps the fleet the template describes (measured, cap-e: savers' 39B of warships sat on moons, the
/// capital held none, so every hull counted as missing and the Deathstar, 3 of 111 by count, was never the pick).
fn template_pick(w: &World, a: usize, bodies: &[usize], template: &[(u32, f64)], buildable: impl Fn(usize) -> bool) -> Option<usize> {
    let rules = &w.rules[a];
    let held = |id: u32| bodies.iter().map(|&b| owned(w, b, id)).sum::<i64>();
    let total = template.iter().map(|&(id, _)| held(id)).sum::<i64>().max(1) as f64;
    let weight = template.iter().map(|t| t.1).sum::<f64>().max(1.0);
    let gap = |&(id, share): &(u32, f64)| { let held = held(id); share / weight - held as f64 / total + if held == 0 { 1.0 } else { 0.0 } };
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

/// `MerchantController::scrapExecute`'s base offer, without bargaining (bargains cost dark matter).
const SCRAP_SHARE: f64 = 0.35;

/// Capital a sells every flying ship above what it keeps at the scrap merchant, cheapest first, as far as free storage
/// takes the 35% it pays (gate 1: the units' prices and speeds are the host's; nothing is named).
fn scrap_surplus(w: &mut World, cat: &Catalogue, a: usize, kept: &HashMap<u32, i64>) {
    let rules = Arc::clone(&w.rules[a]);
    let research = w.research(cat, a);
    let mut order: Vec<usize> = (0..rules.units.units.len()).filter(|&u| w.yards[a].counts[u] > 0).collect();
    order.sort_by(|&x, &y| weighted(rules.units.units[x].price).total_cmp(&weighted(rules.units.units[y].price)));
    for u in order {
        let id = rules.units.units[u].id as u32;
        if rules.fleet.speed(id, &research) <= 0 { continue }
        let spare = w.yards[a].counts[u] as i64 - kept.get(&id).copied().unwrap_or(0);
        if spare <= 0 { continue }
        let each = rules.units.units[u].price.map(|p| (p * SCRAP_SHARE).floor());
        let planet = &w.games[a].planet;
        let room = (0..3).filter(|&r| each[r] > 0.0).map(|r| ((planet.cap[r] - planet.res[r]).max(0.0) / each[r]).floor() as i64).min().unwrap_or(0);
        let sold = spare.min(room);
        if sold <= 0 { continue }
        w.yards[a].counts[u] -= sold as u64;
        let got = each.map(|e| e * sold as f64);
        let planet = &mut w.games[a].planet;
        for r in 0..3 { planet.res[r] += got[r] }
        planet.settle();
        planet.books.merchant_net += weighted(got);
        w.scrapped += sold as u64;
    }
    w.refresh_units(cat, a);
}

/// Whether the account's capital holds more than its mines can use.
fn surplus(w: &World, cat: &Catalogue, a: usize) -> bool {
    let capital = &w.games[w.account_of(a)].planet;
    weighted(capital.res) >= w.tn(a).surplus_days * 24.0 * weighted(cat.production(capital, true).0)
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

/// The lab level a capital raises before its next research, which waits for it: the host holds the lab while any research
/// runs, so research started back to back leaves it at its level for good (a miner held lab 8 for a whole season, its
/// research taking 3,000 hours). A player raises it while the level's build is shorter than what it saves the shortest
/// research the lab can start (gate 3); which object sets research time and both durations come from the catalogue (gate 1).
fn lab_ahead(w: &World, cat: &Catalogue, a: usize) -> Option<usize> {
    let &(lab, _) = cat.modifiers[1].first()?;
    let (game, planet) = (&w.games[a], &w.games[a].planet);
    // The building queue asks the host, research included; the research queue waits only for a building queue that is free.
    let startable = match game.queue { 0 => w.legal(cat, a, lab), _ => cat.legal(planet, lab) && game.free[0] <= game.t };
    if a != w.account_of(a) || !startable || cat.wait_seconds(planet, lab) > 0.0 { return None }
    let mut raised = planet.clone();
    raised.levels[lab] += 1;
    cat.refresh(&mut raised);
    let research = (0..cat.objects.len()).filter(|&k| cat.objects[k].queue == 1 && cat.legal(planet, k));
    let saved = research.map(|k| cat.build_seconds(planet, k) - cat.build_seconds(&raised, k)).fold(f64::INFINITY, f64::min);
    (saved.is_finite() && cat.build_seconds(planet, lab) < saved).then_some(lab)
}

fn idle_lab(w: &World, cat: &Catalogue, a: usize, k: usize) -> bool {
    let capital = w.account_of(a);
    capital != a && cat.sets_research_time(k) && cat.research_network.is_none_or(|n| w.games[capital].planet.levels[n] == 0)
}

impl Miner {
    pub fn new(seed: u64) -> Miner {
        Miner { rng: seed, cargo_share: 0.1, probes: 1, harvesters: 0, fighters: 0, eager: false, military_share: 0.06, consolidate: false, reserve: false, yard: false, colony_cargo: 0, expeditions: false, colonies_first: false, ships: false, lab: false, cap: false, feed: false, factory: false, balance: false, satellites: false, doubler: false, expo_full: false, expo_ship: false, expedition_errand: false, sat_cap: false, war_share: false, post_count: false, moon_post: false, ds_anchor: false, lab_first: false, lab_ahead: false, network: false, tech_tree: false, merchant: false, scrap: false, trim: false, missiles: 0, interceptors: HashMap::default(), doctrine: Doctrine::default() }
    }

    /// The economy's pick for the deciding queue (None lets the login pass; at a login with nothing to build there is no
    /// pick), after any ship order. Only a capital orders ships: a colony only builds.
    pub fn act(&mut self, w: &mut World, cat: &Catalogue, a: usize) -> Option<usize> {
        let pick = match w.decision_kind(a) {
            Decision::Build => self.pick(w, cat, a),
            Decision::Idle => None,
        };
        // The colony the ship was built for is founded at the same login, before any other fleet takes the last slot: one
        // mission, no cargo (expeditions sent first held every slot and the ship waited days in the hangar).
        if w.account_of(a) == a {
            if let (Some(to), Some(ship)) = (w.colony_site(cat, a), w.colonisation.as_ref().map(|t| t.ship)) {
                if let Some(u) = w.rules[a].units.index(ship as i64) {
                    if w.yards[a].counts[u] > 0 {
                        let _ = w.colonise(cat, a, to, &[(ship, 1)], [0.0; 3], 1.0);
                    }
                }
            }
        }
        if self.scrap && w.account_of(a) == a {
            let kept = self.kept(w, cat, a);
            scrap_surplus(w, cat, a, &kept);
        }
        let planet = &w.games[a].planet;
        let overflow = (0..3).any(|r| planet.res[r] >= w.tn(a).overflow * planet.cap[r]);
        if self.consolidate && w.account_of(a) == a {
            self.bring_home(w, cat, a);
        }
        if self.feed && w.account_of(a) == a {
            feed_colonies(w, cat, a, pick);
        }
        if self.satellites {
            power_by_units(w, cat, a, pick, self.sat_cap);
            boost_by_units(w, cat, a, pick);
        }
        // Under its military share the persona buys before the economy: the share is the top giveaway (row 10, S4).
        let short = match self.war_share { true => war_share(w, a), false => military_share(w, a) } < self.military_share;
        // Row 65: a capital waiting on a building the busy yard blocks lets the yard run dry so the building can start.
        let orders = w.account_of(a) == a && !(self.reserve && yard_blocks_reserve(w, cat, a));
        // Arm `anchor`: a missing anchor hull is an order of its own, as a planned colony ship is (measured, anc-b: the
        // plan sat in the list while the capital never reached an ordering login: 9 of 42 eligible top10 held one).
        let anchor_due = self.ds_anchor && w.account_of(a) == a && self.anchor_plan(w, a).is_some();
        if orders && (pick.is_none() || overflow || self.eager || short || self.colony_cargo > 0 || anchor_due) {
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
        if self.expeditions && !self.expedition_errand && w.account_of(a) == a {
            expedition_trips(w, cat, a, self.doubler, self.expo_full, self.expo_ship);
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
        if let Some(lab) = self.lab_ahead.then(|| lab_ahead(w, cat, a)).flatten() {
            return (queue == 0).then_some(lab);
        }
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
            .filter(|&k| !burns_strike_fuel(w, cat, a, k))
    }

    /// Row 29: why this login's yard order did or did not happen, asked of the same rule `act` uses: under its military
    /// share the persona buys, over it and with a store overflowing it still buys, and over it with a store that is not
    /// overflowing it buys nothing at all - the share stands as a ceiling while the surplus sits in the store.
    fn yard_stall(&self, w: &World, cat: &Catalogue, a: usize) -> YardStall {
        if military_share(w, a) < self.military_share { return YardStall::Order }
        let planet = &w.games[a].planet;
        let overflow = (0..3).any(|r| planet.cap[r] > 0.0 && planet.res[r] >= w.tn(a).overflow * planet.cap[r]);
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
        let bodies: Vec<usize> = match self.post_count && fighter {
            true => w.planets_of(w.account_of(a)).into_iter().flat_map(|p| std::iter::once(p).chain(w.moon_of[p])).collect(),
            false => vec![a],
        };
        if let Some(u) = template_pick(w, a, &bodies, template, buildable) { return Some(u) }
        let ship = fighter.then(|| Kit::of(w, cat, a)).flatten().and_then(|kit| rules.units.index(kit.fighter as i64));
        if let Some(u) = ship.filter(|&u| buildable(u) || self.fighters > 0) { return Some(u) }
        if self.doctrine.walled(w, a) { return None }
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
        for r in 0..3 { keep[r] += w.tn(a).keep_hours * game.planet.rate[r].max(0.0) }
        let rules = &w.rules[a];
        // The ships the persona already plans (the colony ship among them) are paid before the surplus (row 63: without
        // this the yard spent the colony ship's price and accounts held 6 planets instead of 8).
        for (u, amount) in self.order_plans(w, cat, a) {
            for r in 0..3 { keep[r] += rules.units.units[u].price[r] * amount as f64 }
        }
        let unit = self.hull(w, cat, a, ((self.fighters > 0 || self.ships) && !self.doctrine.defence_due(w, a)) || self.doctrine.walled(w, a));
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
        // Arm `raids`: probes are ordered from what the planet holds, not after the building being saved for
        // (QueueableUnitPlanner::queueable asks only whether the yard can pay): a late account saving for a building
        // dearer than its stock held no probe for months and its scouting stopped.
        let probe = Kit::of(w, cat, a).filter(|_| w.raids).and_then(|kit| w.rules[a].units.index(kit.probe as i64));
        for (u, amount) in self.order_plans(w, cat, a) {
            let amount = within(w, a, u, amount as u64, if Some(u) == probe { [0.0; 3] } else { keep });
            if amount > 0 { w.order_units(cat, a, u, amount); }
        }
    }

    /// The ships this player keeps, by host id: everything of its doctrine's fleet template, the kit's wants (probes, cargo
    /// for its stock and expeditions, harvesters, fighters, the expedition doubler) and a colony ship.
    fn kept(&self, w: &World, cat: &Catalogue, a: usize) -> HashMap<u32, i64> {
        let mut kept: HashMap<u32, i64> = match self.trim {
            true => self.template_caps(w, a),
            false => self.doctrine.fleet.iter().map(|&(id, _)| (id, i64::MAX)).collect(),
        };
        let Some(kit) = Kit::of(w, cat, a) else { return kept };
        let rules = &w.rules[a];
        let research = w.research(cat, a);
        let hold = rules.combat.capacity(&[(kit.cargo, 1)].into_iter().collect(), &research).max(1);
        let stock: f64 = w.games[a].planet.res.iter().sum();
        let (trips, slots) = if self.expeditions { expedition_cargo(w, cat, a, self.doubler).map_or((0, 0), |(n, slots)| (n * slots as i64, slots as i64)) } else { (0, 0) };
        let cargo = (stock * self.cargo_share / hold as f64).ceil() as i64 + self.colony_cargo + trips;
        let doubler = doubler_of(w, a, self.doubler).map_or((kit.probe, 0), |id| (id, slots));
        for (id, want) in [(kit.probe, self.probe_stock(w, a)), (kit.cargo, cargo), (kit.harvester, self.harvesters), (kit.fighter, self.fighters), doubler] {
            let at = kept.entry(id).or_insert(0);
            *at = at.saturating_add(want.max(0));
        }
        if let Some(t) = w.colonisation.as_ref() { kept.entry(t.ship).or_insert(1); }
        kept
    }

    /// Each template hull kept up to twice its share of the fleet value the account's template hulls stand for: the
    /// median of value over share across the hulls it owns, so a pile of one cheap hull does not raise its own cap.
    fn template_caps(&self, w: &World, a: usize) -> HashMap<u32, i64> {
        let units = &w.rules[a].units;
        let price = |id: u32| units.index(id as i64).map_or(0.0, |u| weighted(units.units[u].price));
        let total: f64 = self.doctrine.fleet.iter().map(|&(_, share)| share).sum();
        let mut ratios: Vec<f64> = self.doctrine.fleet.iter()
            .filter(|&&(id, share)| share > 0.0 && owned(w, a, id) > 0)
            .map(|&(id, share)| owned(w, a, id) as f64 * price(id) / (share / total))
            .collect();
        ratios.sort_by(f64::total_cmp);
        let scale = ratios.get(ratios.len() / 2).copied().unwrap_or(0.0);
        self.doctrine.fleet.iter()
            .map(|&(id, share)| (id, match price(id) > 0.0 && scale > 0.0 {
                true => (2.0 * scale * share / total / price(id)).ceil() as i64,
                false => i64::MAX,
            }))
            .collect()
    }

    /// The probes the yard keeps. Arm `raids`: QueueableUnitPlanner's stock, one in the opening and `PROBE_STOCK` after,
    /// counted idle (`order_plans` leaves the flying ones out), whatever the persona's own count.
    fn probe_stock(&self, w: &World, a: usize) -> i64 {
        match w.raids {
            true => if volley_floor(w, a) == 1 { 1 } else { PROBE_STOCK },
            false => self.probes,
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
        let wants = [(kit.probe, self.probe_stock(w, a)), (kit.cargo, (stock * self.cargo_share / hold as f64).ceil() as i64 + self.colony_cargo + trips), (kit.harvester, self.harvesters), (kit.fighter, self.fighters), doubler];
        // A player takes a new planet as soon as the host allows it: one colony ship, whose id the catalogue gives, paid
        // before the other ships (ordered last, the cargo and expedition ships spent its price at most logins it was due).
        let mut plans: Vec<(usize, i64)> = Vec::new();
        if let Some(ship) = w.colonisation.as_ref().and_then(|t| w.colony_site(cat, a).map(|_| t.ship)) {
            if let Some(u) = rules.units.index(ship as i64) {
                if owned(w, a, ship) < 1 { plans.push((u, 1)) }
            }
        }
        plans.extend(wants.into_iter().filter_map(|(id, want)| {
            // Arm `raids`: the probe stock is the idle one (QueueableUnitPlanner: shelf plus yard), so a volley in
            // flight is replaced and the scout keeps reading while it is out.
            let missing = want - if w.raids && id == kit.probe { owned(w, a, id) - flying(w, a, id) } else { owned(w, a, id) };
            let u = rules.units.index(id as i64)?;
            (missing > 0).then_some((u, missing))
        }));
        plans.extend(self.strike_plans(w, a));
        if self.ds_anchor && w.account_of(a) == a {
            plans.extend(self.anchor_plan(w, a));
        }
        plans
    }

    /// Arm `anchor`: the template's dearest hull the yard may build, and how many the account still lacks (gate 1: the
    /// doctrine's data and the host's prices pick it).
    fn anchor_plan(&self, w: &World, a: usize) -> Option<(usize, i64)> {
        let units = &w.rules[a].units;
        let worth = |u: usize| units.units[u].price.iter().sum::<f64>();
        let u = self.doctrine.fleet.iter().filter_map(|&(id, _)| units.index(id as i64)).max_by(|&x, &y| worth(x).total_cmp(&worth(y)))?;
        // `allowed` is the class's permission; the requirements are the host's own (`standing` reads them the same way).
        if !w.yards[a].allowed[u] || !units.requirements_met(&w.games[a].planet, u) { return None }
        let bodies: Vec<usize> = w.planets_of(a).into_iter().flat_map(|p| std::iter::once(p).chain(w.moon_of[p])).collect();
        let war = |u: usize| units.kind.get(u) == Some(&UnitKind::MilitaryShip);
        let mut value: f64 = bodies.iter().map(|&b| w.yards[b].counts.iter().enumerate().filter(|&(u, _)| war(u)).map(|(u, &n)| n as f64 * worth(u)).sum::<f64>()).sum();
        value += w.fleets.iter().filter(|f| w.account_of(f.owner) == a).flat_map(|f| f.ships.iter())
            .filter_map(|&(id, n)| units.index(id as i64).filter(|&u| war(u)).map(|u| n as f64 * worth(u))).sum::<f64>();
        let want = ((value / ANCHOR_VALUE).floor() as i64).max(1);
        let held: i64 = bodies.iter().map(|&b| owned(w, b, units.units[u].id as u32)).sum();
        (want > held).then_some((u, want - held))
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
    for r in 0..3 { keep[r] += w.tn(a).keep_hours * w.games[a].planet.rate[r].max(0.0) }
    keep
}

/// The warships on a planet that fly.
pub(crate) fn warships(rules: &Rules, research: &crate::Levels, counts: &[u64]) -> Vec<(u32, i64)> {
    stack(rules, counts).into_iter()
        .filter(|&(id, _)| movable(rules, id, research) && rules.units.index(id as i64).is_some_and(|u| rules.units.kind.get(u) == Some(&UnitKind::MilitaryShip)))
        .collect()
}

/// L5: an alliance member whose warships reach a mate's planet before a raid lands there holds at it, when the planet
/// alone would lose and the two together destroy the raid in three rounds without being worn down: the help a player
/// in an alliance gives (gate 3). Raids landing soonest go first, one hold per planet.
pub fn defend_mate(w: &mut World, cat: &Catalogue, a: usize, mate: impl Fn(usize) -> bool, ledger: &mut Ledger) -> bool {
    for (target, ships, hold) in defend_options(w, cat, a, mate) {
        if w.defend(cat, a, target, &ships, [0.0; 3], 10.0, hold).is_ok() {
            ledger.defends += 1;
            return true;
        }
    }
    false
}

/// `defend_mate`'s holds, soonest raid first, as (mate's planet, warships, hold hours): what it would try in turn.
pub(crate) fn defend_options(w: &World, cat: &Catalogue, a: usize, mate: impl Fn(usize) -> bool) -> Vec<(usize, Vec<(u32, i64)>, i64)> {
    let now = w.games[a].t;
    let rules = Arc::clone(&w.rules[a]);
    let research = w.research(cat, a);
    let ships = warships(&rules, &research, &w.yards[a].counts);
    let mut options = Vec::new();
    if ships.is_empty() || !rules.settings.alliance_combat_system { return options }
    let mut raids: Vec<(usize, f64, usize, Vec<(u32, i64)>)> = w.fleets.iter()
        .filter(|f| matches!(f.mission, Mission::Attack | Mission::MoonDestroy) && !f.returning && f.arrive > now && w.account_of(f.owner) != w.account_of(a))
        .filter_map(|f| {
            let target = if f.mission == Mission::MoonDestroy { w.moon_of[f.target]? } else { f.target };
            // A raid on a mate is known only once the mate was online after it left and could pass it on.
            let told = f.departed <= w.online_at[w.account_of(target)];
            (told && w.mates(a, target, now) && mate(w.account_of(target))).then(|| (target, f.arrive, f.owner, f.ships.clone()))
        }).collect();
    raids.sort_by(|x, y| x.1.total_cmp(&y.1));
    let ours = strength(&rules, &research, &ships);
    for (target, lands, raider, raid) in raids {
        if w.fleets.iter().any(|f| f.owner == a && f.mission == Mission::AcsDefend && f.target == target && !f.returning) { continue }
        let flight = fleet::flight_seconds(&rules.settings, Mission::AcsDefend as u32, account_distance(w, a, target), rules.fleet.slowest_speed(&ships, &research), 10.0) as f64;
        if now + flight >= lands { continue }
        // Shared, not seen: the mate relays its own planet and its event list, which shows the raid's amounts only at
        // full intel; the raider's research is known only from a report, else taken as equal to ours.
        let mate_research = w.research(cat, target);
        let espionage = w.rules[target].combat.missions.espionage_research;
        if espionage.and_then(|id| mate_research.get(&id).copied()).unwrap_or(0) < FULL_FLEET_INTEL { continue }
        let (mate_account, raider_account) = (w.account_of(target), w.account_of(raider));
        let raider_research = w.reports.iter().rev()
            .find(|r| (r.owner == a || r.owner == mate_account) && w.account_of(r.target) == raider_account && r.research.is_some())
            .map_or_else(|| research.clone(), |r| report_research(cat, r, &research));
        let planet = strength(&w.rules[target], &mate_research, &stack(&w.rules[target], &w.yards[target].counts));
        let (attack, hull) = strength(&w.rules[raider], &raider_research, &raid);
        let alone_loses = planet.0 * 3.0 < hull;
        let together_win = (ours.0 + planet.0) * 3.0 >= hull && attack * 3.0 <= SAFE_SHARE * (ours.1 + planet.1);
        if !(alone_loses && together_win) { continue }
        options.push((target, ships.clone(), ((lands - now - flight) / 3600.0).floor() as i64 + 1));
    }
    options
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

/// A time-limited request shares a proposed fleet estimate with one alliance mate.
#[derive(Clone)]
pub(crate) struct WarRequest {
    leader: usize,
    ally: usize,
    target: usize,
    created: f64,
    lead: (f64, f64),
    lead_flight: f64,
    accepted: Option<(f64, f64, f64)>,
}

/// A request is answered only when the recipient logs in. Acceptance is not a guaranteed dispatch.
pub(crate) fn coordinate_war(w: &mut World, cat: &Catalogue, a: usize, requests: &mut Vec<WarRequest>, ledger: &mut Ledger) {
    let now = w.games[a].t;
    let live = |r: &WarRequest| now - r.created < 6.0 * 3600.0 && w.mates(r.leader, r.ally, now) && !w.mates(r.leader, r.target, now);
    ledger.war_launch_refused[0] += requests.iter().filter(|r| r.accepted.is_some() && !live(r)).count();
    requests.retain(|r| live(r));
    let own_research = w.research(cat, a);
    let fleet = warships(&w.rules[a], &own_research, &w.yards[a].counts);
    let holds_accepted = requests.iter().any(|r| r.leader == a && r.accepted.is_some());
    if fleet.is_empty() { ledger.war_launch_refused[1] += usize::from(holds_accepted); return }
    ledger.war_ready_logins += 1;
    let own = strength(&w.rules[a], &own_research, &fleet);
    // Alliance messages share the latest report; neither participant reads the target current hidden state.
    let enemy = |w: &World, leader: usize, target: usize| -> Option<(f64, f64)> {
        let report = last_report(w, leader, target).filter(|r| now - r.time < REPORT_HOURS * 3600.0)?;
        let (ships, defence, levels) = (report.ships.as_ref()?, report.defence.as_ref()?, report.research.as_ref()?);
        if ships.iter().all(|&n| n == 0) { return None }
        let tech: crate::Levels = cat.objects.iter().zip(levels).filter(|(o, _)| o.queue == 1).map(|(o, &l)| (o.id as u32, l)).collect();
        let mut units = stack(&w.rules[target], ships);
        units.extend(stack(&w.rules[target], defence));
        Some(strength(&w.rules[target], &tech, &units))
    };
    for request in requests.iter_mut().filter(|r| r.ally == a && r.accepted.is_none()) {
        let Some((attack, hull)) = enemy(w, request.leader, request.target) else { ledger.war_accept_refused[0] += 1; continue };
        let rules = &w.rules[a];
        let distance = account_distance(w, a, request.target);
        let flight = fleet::flight_seconds(&rules.settings, Mission::Attack as u32, distance, rules.fleet.slowest_speed(&fleet, &own_research), 10.0) as f64;
        let fuel = fleet::consumption(&rules.settings, &rules.fleet, &fleet, &own_research, distance, 0, 10.0);
        let ships: combat::Units = fleet.iter().copied().collect();
        if flight > request.lead_flight { ledger.war_accept_refused[1] += 1; continue }
        if fuel as f64 > w.games[a].planet.res[2] || rules.combat.fuel_capacity(&ships, &own_research) < fuel { ledger.war_accept_refused[2] += 1; continue }
        if !(request.lead.0 * 3.0 < hull && (request.lead.0 + own.0) * 3.0 >= hull
            && attack * 3.0 <= SAFE_SHARE * (request.lead.1 + own.1)) { ledger.war_accept_refused[3] += 1; continue }
        request.accepted = Some((now, own.0, own.1));
        ledger.war_acceptances += 1;
    }
    let accepted = requests.iter().find(|r| r.leader == a && r.accepted.is_some_and(|(t, _, _)| now - t < 3600.0)).cloned();
    if accepted.is_none() && holds_accepted { ledger.war_launch_refused[2] += 1 }
    if let Some(request) = accepted {
        let (_, support_attack, support_hull) = request.accepted.unwrap();
        match enemy(w, a, request.target) {
            None => ledger.war_launch_refused[3] += 1,
            Some((attack, hull)) if !(own.0 * 3.0 < hull && (own.0 + support_attack) * 3.0 >= hull && attack * 3.0 <= SAFE_SHARE * (own.1 + support_hull)) => ledger.war_launch_refused[4] += 1,
            Some(_) => match send(w, cat, a, request.target, Mission::Attack, &fleet, 10.0, ledger) {
                Ok(arrive) => {
                    ledger.raids.push(Raid { attacker: a, target: request.target, arrive, loot: None, target_had_fleet: true, saved: false });
                    ledger.war_launches += 1;
                    requests.retain(|r| r.leader != a);
                    return;
                }
                Err(_) => ledger.war_launch_refused[5] += 1,
            },
        }
    }
    if requests.iter().any(|r| r.leader == a) { return }
    // Reports are chronological; rank the scouted bodies by their owner, including colonies and moons.
    let mut seen = HashSet::default();
    let mut scouted: Vec<(usize, usize)> = w.reports.iter().rev().take_while(|r| now - r.time < REPORT_HOURS * 3600.0)
        .filter_map(|r| {
            let target = r.target;
            if r.time > now || r.owner != a || !seen.insert(target) || w.account_of(target) == a || w.mates(a, target, now) { return None }
            if w.moon_at[target].is_some_and(|planet| w.moon_of[planet] != Some(target)) { return None }
            let rank = w.public_highscore.iter().position(|&owner| owner == w.account_of(target))?;
            Some((rank, target))
        }).collect();
    scouted.sort_by_key(|&(rank, _)| rank);
    let target = scouted.into_iter().find_map(|(_, target)| {
        let (_, hull) = enemy(w, a, target)?;
        ledger.war_reports += 1;
        (own.0 * 3.0 < hull).then_some(target)
    });
    let Some(target) = target else { return };
    ledger.war_opportunities += 1;
    let rules = &w.rules[a];
    let lead_flight = fleet::flight_seconds(&rules.settings, Mission::Attack as u32, account_distance(w, a, target), rules.fleet.slowest_speed(&fleet, &own_research), 10.0) as f64;
    // Asking mates to commit to a launch the leader cannot fuel only wastes their answer (measured: every refused
    // coordinated dispatch was the leader's deuterium).
    let fuel = fleet::consumption(&rules.settings, &rules.fleet, &fleet, &own_research, account_distance(w, a, target), 0, 10.0);
    let ships: combat::Units = fleet.iter().copied().collect();
    if fuel as f64 > w.games[a].planet.res[2] || rules.combat.fuel_capacity(&ships, &own_research) < fuel { ledger.war_request_refused[0] += 1; return }
    if !others_by_distance(w, a).into_iter().any(|b| w.mates(a, b, now)) { ledger.war_request_refused[1] += 1; return }
    // ponytail: ask up to four nearby allies; add richer communication only when measured necessary.
    for ally in others_by_distance(w, a).into_iter().filter(|&b| w.mates(a, b, now)).take(4) {
        requests.push(WarRequest { leader: a, ally, target, created: now, lead: own, lead_flight, accepted: None });
        ledger.war_requests += 1;
    }
}

/// L5: an alliance member sends cargo ships to join a mate's raid when the mate's report shows more plunder than the
/// raid carries, the extra plunder pays for the fuel, the target's guns cannot wear down the joint fleet and the ships
/// land within the union's window: the ACS a player in an alliance flies for a rich target (gate 3).
pub fn join_raid(w: &mut World, cat: &Catalogue, a: usize, mate: impl Fn(usize) -> bool, ledger: &mut Ledger) -> bool {
    for (f, fleet) in join_options(w, cat, a, mate) {
        if w.join(cat, a, f, &fleet, [0.0; 3], 10.0).is_ok() {
            ledger.joins += 1;
            return true;
        }
    }
    false
}

/// `join_raid`'s joins in the order it tries them, as (the raid's lead fleet, the ships that join it).
pub(crate) fn join_options(w: &World, cat: &Catalogue, a: usize, mate: impl Fn(usize) -> bool) -> Vec<(usize, Vec<(u32, i64)>)> {
    let mut options = Vec::new();
    let now = w.games[a].t;
    if !w.rules[a].settings.alliance_combat_system { return options }
    let raids: Vec<usize> = (0..w.fleets.len()).filter(|&f| {
        let lead = &w.fleets[f];
        lead.mission == Mission::Attack && !lead.returning && !lead.moon && lead.arrive > now && lead.union.is_none_or(|u| u == lead.id)
            && w.account_of(lead.owner) != w.account_of(a) && mate(w.account_of(lead.owner)) && w.mates(a, lead.owner, now)
            && !w.mates(a, lead.target, now) && w.account_of(lead.target) != w.account_of(a)
            && !w.fleets.iter().any(|g| g.owner == a && g.union.is_some() && g.union == Some(lead.union.unwrap_or(lead.id)))
    }).collect();
    let rules = Arc::clone(&w.rules[a]);
    let research = w.research(cat, a);
    if w.alliances_distinct && !raids.is_empty() {
        let fleet = warships(&rules, &research, &w.yards[a].counts);
        if !fleet.is_empty() {
            for &f in &raids {
                let lead = &w.fleets[f];
                let Some(report) = last_report(w, lead.owner, lead.target).filter(|r| now - r.time < REPORT_HOURS * 3600.0) else { continue };
                let (Some(ships), Some(defence), Some(levels)) = (&report.ships, &report.defence, &report.research) else { continue };
                let target_research: crate::Levels = cat.objects.iter().zip(levels).filter(|(o, _)| o.queue == 1).map(|(o, &l)| (o.id as u32, l)).collect();
                let mut defenders = stack(&w.rules[lead.target], ships);
                defenders.extend(stack(&w.rules[lead.target], defence));
                let (enemy_attack, enemy_hull) = strength(&w.rules[lead.target], &target_research, &defenders);
                let key = lead.union.unwrap_or(lead.id);
                let (attack, hull) = w.fleets.iter().filter(|g| g.id == lead.id || (g.union == Some(key) && !g.returning)).fold((0.0, 0.0), |(attack, hull), g| {
                    let (a, h) = strength(&w.rules[g.owner], &w.research(cat, g.owner), &g.ships);
                    (attack + a, hull + h)
                });
                let (our_attack, our_hull) = strength(&rules, &research, &fleet);
                // ponytail: three-round estimate; replace with a bounded battle-simulator forecast when measured affordable.
                if enemy_hull > 0.0 && attack * 3.0 < enemy_hull && (attack + our_attack) * 3.0 >= enemy_hull
                    && enemy_attack * 3.0 <= SAFE_SHARE * (hull + our_hull) {
                    options.push((f, fleet.clone()));
                }
            }
        }
    }
    let Some(kit) = Kit::of(w, cat, a) else { return options };
    let shelf = on_shelf(w, a, kit.cargo);
    if shelf < 1 { return options }
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
        let observed_research = report_research(cat, report, &research);
        let (attack, _) = strength(&w.rules[target], &observed_research, &guns);
        let fleet = [(kit.cargo, n)];
        if attack * 3.0 > SAFE_SHARE * (union_hull + strength(&rules, &research, &fleet).1) { continue }
        let extra = combat::distribute_loot(loot, capacity + n * hold);
        let carried = combat::distribute_loot(loot, capacity);
        let fuel = fleet::consumption(&rules.settings, &rules.fleet, &fleet, &research, account_distance(w, a, target), 0, 10.0) as f64;
        if weighted(extra) - weighted(carried) < PAYS * WEIGHTS[2] * fuel { continue }
        options.push((f, fleet.to_vec()));
    }
    options
}

/// Row 64: a moon shot for an alliance mate, the way players make moons: the shot `shot_size` gives, sent at the nearest
/// mate's planet that has no moon and guns to shoot it all down in three rounds (the moon forms at the receiver). Short
/// of fighters, the shooter orders them from what its queues do not need. Returns whether a shot left.
pub fn moon_shot(w: &mut World, cat: &Catalogue, a: usize, mate: impl Fn(usize) -> bool, ledger: &mut Ledger) -> bool {
    let Some((target, fighter, n)) = moon_shot_plan(w, cat, a, mate) else { return false };
    if on_shelf(w, a, fighter) >= n {
        return send(w, cat, a, target, Mission::Attack, &[(fighter, n)], 10.0, ledger).is_ok();
    }
    order_shot(w, cat, a, fighter, n)
}

/// The shot `moon_shot` would send, as (mate's planet, fighter, how many), whether or not the shelf holds it yet.
pub(crate) fn moon_shot_plan(w: &World, cat: &Catalogue, a: usize, mate: impl Fn(usize) -> bool) -> Option<(usize, u32, i64)> {
    let (fighter, n, hull) = shot_size(w, cat, a)?;
    // An attack bound for the mate's planet rules it out only once the mate has seen it and could say so.
    let raided = |p: usize| w.fleets.iter().any(|f| f.target == p && f.mission == Mission::Attack && !f.returning
        && (w.account_of(f.owner) == w.account_of(a) || f.departed <= w.online_at[w.account_of(p)]));
    let receives = |p: usize| !w.moons[p] && !raided(p) && guns(w, cat, p) * 3.0 >= hull;
    let target = others_by_distance(w, a).into_iter().filter(|&b| mate(b)).flat_map(|b| w.planets_of(b)).find(|&p| receives(p))?;
    Some((target, fighter, n))
}

/// The shooter's yard half of `moon_shot` without the shot: short of fighters for a shot it would send, it orders them.
/// An account whose shots are its errand model's still keeps its yard (`Universe::play`).
pub fn moon_shot_stock(w: &mut World, cat: &Catalogue, a: usize, mate: impl Fn(usize) -> bool) {
    let Some((_, fighter, n)) = moon_shot_plan(w, cat, a, mate) else { return };
    if on_shelf(w, a, fighter) < n { order_shot(w, cat, a, fighter, n); }
}

fn order_shot(w: &mut World, cat: &Catalogue, a: usize, fighter: u32, n: i64) -> bool {
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
    let Some(u) = template_pick(w, p, &[p], wall, standing).or_else(|| (0..rules.units.units.len()).filter(|&u| standing(u))
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
pub(crate) fn expedition_cargo(w: &World, cat: &Catalogue, a: usize, doubled: bool) -> Option<(i64, usize)> {
    let rules = &w.rules[a];
    let table = rules.expedition.as_ref()?;
    let research = w.research(cat, a);
    // A class's slots stand before the research does, but no expedition flies without it (ExpeditionMission::isMissionPossible).
    if research.get(&(w.colonisation.as_ref()?.research as u32)).copied().unwrap_or(0) == 0 { return None }
    let slots = w.expedition_slots(cat, a)?;
    if slots == 0 { return None }
    // Arm `skill` (tuning.rs): a weaker player keeps fewer of its slots busy; 1 everywhere else, so the slots stay the host's.
    let slots = match w.tn(a).expedition_use { u if u < 1.0 => ((slots as f64 * u).ceil() as usize).clamp(1, slots), _ => slots };
    let largest = table.find_brackets.iter().find(|(below, _)| w.rank1_points < *below).or(table.find_brackets.last())?.1;
    let twice = if doubler_of(w, a, doubled).is_some() { 2.0 } else { 1.0 };
    let find = twice * largest as f64 * table.class_resource_multiplier * table.reward_multiplier.resources;
    let kit = Kit::of(w, cat, a)?;
    let hold = rules.combat.capacity(&[(kit.cargo, 1)].into_iter().collect(), &research).max(1);
    Some(((find / hold as f64).ceil() as i64, slots))
}

/// N6b: a player sends an expedition from its capital whenever a slot is free (gate 3): cargo ships for the largest
/// find, to deep space in its own system, held an hour. The world refuses past the host's slot limit or without fuel.
pub(crate) fn expedition_trips(w: &mut World, cat: &Catalogue, a: usize, doubled: bool, full: bool, escort: bool) {
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
pub(crate) fn strongest(w: &World, cat: &Catalogue, a: usize, fleet: &[(u32, i64)]) -> Option<u32> {
    let rules = &w.rules[a];
    let research = w.research(cat, a);
    rules.units.units.iter().map(|u| u.id as u32)
        .filter(|&id| on_shelf(w, a, id) > fleet.iter().filter(|s| s.0 == id).map(|s| s.1).sum::<i64>() && rules.combat.fights(id) && movable(rules, id, &research))
        .max_by_key(|&id| rules.combat.hull(id, &research))
}

/// Row 84: the ship whose presence doubles an expedition's find, when the arm is on and the account's class may build
/// it (the host's expedition table names it by id; nothing here does, gate 1).
pub(crate) fn doubler_of(w: &World, a: usize, on: bool) -> Option<u32> {
    let id = w.rules[a].expedition.as_ref()?.find_doubler;
    let u = w.rules[a].units.index(id as i64)?;
    (on && w.yards[a].allowed[u]).then_some(id)
}

const RESEARCH_LEAD: u32 = 3;
/// Arm `anchor`: warship value per anchor hull (OGMEM medians: 1-2 below 1e10, 11-27 at 1e10-1e11).
const ANCHOR_VALUE: f64 = 2e9;

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

/// The deuterium a capital's warships burn to strike the farthest of its nearest accounts at full speed: what a fleeter
/// keeps in store so the fleet can fly when a target or a mate's call comes (gate 3; the war bench measured strikes
/// waiting on 9.4M deuterium against a store of 242, the store emptied by building and research starts: 83% of it).
/// `fuelkeep`: a capital's build that would pay its deuterium out of the strike fuel waits, as a fleeter's does.
fn burns_strike_fuel(w: &World, cat: &Catalogue, a: usize, k: usize) -> bool {
    let planet = &w.games[a].planet;
    let deuterium = cat.objects[k].price[planet.levels[k] as usize][2];
    w.fuel_keep && w.account_of(a) == a && deuterium > 0.0 && planet.res[2] - deuterium < strike_fuel(w, cat, a)
}

fn strike_fuel(w: &World, cat: &Catalogue, a: usize) -> f64 {
    let rules = &w.rules[a];
    let research = w.research(cat, a);
    let fleet = warships(rules, &research, &w.yards[a].counts);
    let Some(distance) = others_by_distance(w, a).into_iter().take(NEIGHBOURS).map(|b| account_distance(w, a, b)).max() else { return 0.0 };
    if fleet.is_empty() { return 0.0 }
    fleet::consumption(&rules.settings, &rules.fleet, &fleet, &research, distance, 0, 10.0) as f64
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
    let moon_only = |k: usize| cat.objects[k].queue == 0 && cat.objects[k].on_moon && !cat.objects[k].on_planet && startable(k);
    // Arm `late`: every moon-only building stands before the phalanx climbs (CRASH-004: the gate's value is coverage and
    // movement); raising the phalanx first kept the capital's moon gateless all season (fix5: gate 0 on most first moons).
    let raise = phalanx.filter(|_| !w.late || !(0..cat.objects.len()).any(|k| moon_only(k) && planet.levels[k] == 0));
    (0..cat.objects.len())
        .filter(|&k| moon_only(k) && (planet.levels[k] == 0 || Some(k) == raise))
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
pub(crate) fn ship_from_home(w: &mut World, cat: &Catalogue, a: usize, to: usize, lack: [f64; 3], keep: [f64; 3]) -> bool {
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
        let payable = cheapest(research().filter(|&k| w.legal(cat, a, k) && cat.wait_seconds(planet, k) == 0.0 && !(w.paid_lab && price(k) <= 0.0)).collect());
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
    let legal: Vec<usize> = (0..cat.objects.len()).filter(|&k| cat.objects[k].queue == 0 && cat.legal(planet, k)).collect();
    let building = legal.iter().zip(cat.raised(planet, &legal))
        .filter_map(|(&k, next)| {
            let gain = (next.energy_max - next.energy_used) - spare(planet);
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
fn power_by_units(w: &mut World, cat: &Catalogue, a: usize, pick: Option<usize>, cap: bool) {
    let planet = &w.games[a].planet;
    let for_chain = match cap && !chain_within_cap(w, cat, a) { true => 0.0, false => chain_energy(cat, planet) };
    // Arm `satcap`: the field chain no longer leaves a surplus, so the energy a research asks for (graviton's one
    // useful level, WIK-175) is bought on purpose, on the capital, within the same unit cap.
    let for_chain = match cap && w.account_of(a) == a && research_within_cap(w, cat, a) { true => for_chain.max(research_energy(cat, planet)), false => for_chain };
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

/// Whether planet a can come by the energy its field chain asks for: units the account can pay for from its stock and
/// `CHAIN_POWER_DAYS` of production. A player who cannot makes do with the fields it has rather than hold the last ones
/// for good (gate 3). The account's, not the planet's: a planet's store dips at every start, and one dip let the last
/// fields go while the satellites were on order, sealing a capital that had the energy a day later.
fn chain_powered(w: &World, cat: &Catalogue, a: usize) -> bool {
    let planet = &w.games[a].planet;
    let slots = w.planets_of(w.account_of(a));
    let wealth = |r: usize| slots.iter().map(|&s| { let p = &w.games[s].planet; p.res[r] + CHAIN_POWER_DAYS * 24.0 * p.rate[r].max(0.0) }).sum::<f64>();
    let short = chain_energy(cat, planet);
    if short <= 0.0 { return true }
    let Some((u, each)) = energy_unit(w, cat, a, false) else { return false };
    let coming: u64 = w.yards[a].orders.iter().filter(|o| o.unit == u).map(|o| o.amount - o.done).sum();
    let needed = ((short / each).ceil() - coming as f64).max(0.0);
    let price = w.rules[a].units.units[u].price;
    (0..3).all(|r| price[r] * needed <= wealth(r))
}

/// Arm `satcap`: whether the field chain's next energy price can be met with at most `CHAIN_UNITS_MAX` energy units on
/// the planet. The chain's energy doubles with every level, and buying it in satellites gave top planets 2.3M of them
/// (runs/fix5; `power_by_units` ordered ~40M a season per world); real hit planets hold at most ~10.5k (OGMEM) and a real
/// ten-planet empire shows terraformer levels 0-5 (reddit 1gj4y6). Past the cap the planet makes do with its fields.
fn chain_within_cap(w: &World, cat: &Catalogue, a: usize) -> bool {
    let short = chain_energy(cat, &w.games[a].planet);
    if short <= 0.0 { return true }
    let Some((u, each)) = energy_unit(w, cat, a, false) else { return false };
    let coming: u64 = w.yards[a].orders.iter().filter(|o| o.unit == u).map(|o| o.amount - o.done).sum();
    w.yards[a].counts[u] as f64 + coming as f64 + (short / each).ceil() <= CHAIN_UNITS_MAX
}

/// The energy short of the dearest research the lab could start next but for its energy price: requirements met,
/// below the catalogue's useful ceiling (`Catalogue::ceiling`), priced in energy (gate 1: nothing named).
fn research_energy(cat: &Catalogue, planet: &Planet) -> f64 {
    (0..cat.objects.len())
        .filter(|&k| cat.objects[k].queue == 1 && cat.stall_of(planet, k) == "no energy")
        .filter_map(|k| cat.objects[k].energy_price.get(planet.levels[k] as usize))
        .fold(0.0, |short: f64, &need| short.max(need - (planet.energy_max - planet.energy_used)))
}

/// Arm `satcap`: whether the research's energy can be met with at most `CHAIN_UNITS_MAX` energy units on the planet.
fn research_within_cap(w: &World, cat: &Catalogue, a: usize) -> bool {
    let short = research_energy(cat, &w.games[a].planet);
    if short <= 0.0 { return false }
    let Some((u, each)) = energy_unit(w, cat, a, false) else { return false };
    let coming: u64 = w.yards[a].orders.iter().filter(|o| o.unit == u).map(|o| o.amount - o.done).sum();
    w.yards[a].counts[u] as f64 + coming as f64 + (short / each).ceil() <= CHAIN_UNITS_MAX
}

/// The most energy units a field chain may stand on (OGMEM: the largest satellite count on a real hit planet).
const CHAIN_UNITS_MAX: f64 = 10_500.0;

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
pub(crate) fn field_reserve(w: &World, cat: &Catalogue, a: usize) -> Option<Option<usize>> {
    let queue = w.games[a].queue;
    // The lab starts the chain while a planet still has RESEARCH_LEAD times its reserve free: research takes weeks, and a
    // building queue that waits on it at the last fields idles for them (row 46: the stack lost C's growth that way).
    let lead = if queue == 0 { 1 } else { RESEARCH_LEAD };
    let short = |slot: usize| {
        let planet = &w.games[slot].planet;
        let (chain, fields) = field_chain(cat, planet)?;
        // The building queue always keeps them: a planet that spends its last field never adds one again, while one that
        // keeps it forgoes a single level. Energy only decides whether research starts the chain early.
        (cat.field_cap(planet).saturating_sub(cat.fields_used(planet)) <= lead * fields && (queue == 0 || chain_powered(w, cat, slot))).then_some(chain)
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
#[derive(Clone)]
pub struct Raider {
    pub miner: Miner,
    /// A twin world turns raiding off to show what the same accounts do in peace.
    pub hostile: bool,
    /// A neighbour's moon is spied and raided like its planet: the fleet parked there and the stock it holds.
    pub moon_raids: bool,
    last: f64,
    spied: HashMap<usize, (f64, i64)>,
    hit: HashMap<usize, f64>,
    /// Per target, the second the last salvo left.
    fired: HashMap<usize, f64>,
    /// Arm `bodies`: per target body, the seconds the account's attacks left in the last 24 h (the bashing limit).
    bashed: HashMap<usize, Vec<f64>>,
}

impl Raider {
    pub fn new(seed: u64) -> Raider {
        let miner = Miner { rng: seed, cargo_share: 0.2, probes: 4, harvesters: 3, fighters: 30, eager: true, military_share: 0.30, consolidate: false, reserve: false, yard: false, colony_cargo: 0, expeditions: false, colonies_first: false, ships: false, lab: false, cap: false, feed: false, factory: false, balance: false, satellites: false, doubler: false, expo_full: false, expo_ship: false, expedition_errand: false, sat_cap: false, war_share: false, post_count: false, moon_post: false, ds_anchor: false, lab_first: false, lab_ahead: false, network: false, tech_tree: false, merchant: false, scrap: false, trim: false, missiles: 0, interceptors: HashMap::default(), doctrine: Doctrine::default() };
        Raider { miner, hostile: true, moon_raids: false, last: -1.0, spied: HashMap::default(), hit: HashMap::default(), fired: HashMap::default(), bashed: HashMap::default() }
    }

    pub fn act(&mut self, w: &mut World, cat: &Catalogue, a: usize, ledger: &mut Ledger) -> Option<usize> {
        let pick = self.miner.act(w, cat, a);
        let now = w.games[a].t;
        if !self.hostile || now == self.last || w.account_of(a) != a { return pick }
        self.last = now;
        let Some(kit) = Kit::of(w, cat, a) else { return pick };
        // Arm `raids`: the module's scout weighs its 20 nearest candidates (QueueableSpyPlanner::MAX_CANDIDATES).
        let mut targets = if w.raids && w.bodies { nearest_bodies(w, cat, a, RAID_CANDIDATES) } else { nearest(w, cat, a, if w.raids { RAID_CANDIDATES } else { NEIGHBOURS }) };
        if w.alliances_distinct {
            // Public highscore plus inactive marker: scout the strongest active rival even beyond nearby farms.
            let ranked = w.public_highscore.clone();
            if let Some(target) = ranked.into_iter().find(|&b| b != a && !w.mates(a, b, now) && !w.inactive(cat, b, now)) {
                targets.retain(|&b| b != target);
                targets.insert(0, target);
            }
        }
        if w.relations.on {
            // At war: the enemy club's nearest active members come first (the leader's broadcast: "their planets and
            // moons are targets").
            let enemies: Vec<usize> = others_by_distance(w, a).into_iter().filter(|&b| w.enemy(a, b, now) && !w.inactive(cat, b, now)).take(3).collect();
            targets.retain(|t| !enemies.contains(t));
            for (i, e) in enemies.into_iter().enumerate() { targets.insert(i, e) }
        }
        if self.moon_raids && !(w.raids && w.bodies) {
            if w.alliances_distinct {
                targets = targets.into_iter().flat_map(|t| std::iter::once(t).chain(w.moon_of[t])).collect();
            } else {
                targets.extend(targets.clone().into_iter().filter_map(|t| w.moon_of[t]));
            }
        }
        for target in targets {
            // Arm `slots`: the host's own fleet slots (Login\FleetSlots: maximum less the missions flying), not a fixed four.
            if w.raids && w.slots {
                let out = w.fleets.iter().filter(|f| f.mission != Mission::Missile && w.account_of(f.owner) == a).count();
                if out >= w.fleet_slots(cat, a).unwrap_or(SLOTS) { break }
            } else if w.fleets.iter().filter(|f| f.owner == a).count() >= SLOTS { break }
            self.recycle(w, cat, a, target, ledger);
            if w.alliances_distinct && w.spares(a, target, now) { continue }
            if self.destroy_moon(w, cat, a, target, ledger) { continue }
            if self.raid(w, cat, a, target, kit, ledger) { continue }
            self.spy(w, cat, a, target, kit, ledger);
        }
        pick
    }

    fn spy(&mut self, w: &mut World, cat: &Catalogue, a: usize, target: usize, kit: Kit, ledger: &mut Ledger) {
        let now = w.games[a].t;
        // A salvo since the report has changed the defence it read.
        let report = last_report(w, a, target);
        let fresh = report.is_some_and(|r| now - r.time < REPORT_HOURS * 3600.0 && self.fired.get(&target).is_none_or(|&t| t < r.time));
        let previous = self.spied.get(&target).copied();
        if w.raids {
            // The module's scout: any report inside the intel window skips the target (QueueableSpyPlanner's fresh intel),
            // a probe still out does too; the next volley is `spy_volley`'s, and the yard keeps the probes it asks for.
            // Arm `rescout`: a redacted report the next volley can still read through is not settled intel.
            let rescout = fresh && w.rescout && rescout_due(w, a, target);
            if fresh && !rescout { ledger.spy_gates[0] += 1; return }
            if previous.is_some_and(|(t, _)| now - t < 2.0 * 3600.0) { ledger.spy_gates[1] += 1; return }
            let wanted = spy_volley(w, a, target);
            let held = on_shelf(w, a, kit.probe);
            // A re-scout waits for the whole volley: a short one is read redacted again (QueueableSpyPlanner).
            if held < 1 || (rescout && held < wanted) {
                ledger.spy_gates[2] += 1;
                if held < 1 { ledger.probe_diag[if owned(w, a, kit.probe) > flying(w, a, kit.probe) { 1 } else { 2 }] += 1 }
                return;
            }
            let probes = held.min(wanted);
            if send(w, cat, a, target, Mission::Espionage, &[(kit.probe, probes)], 10.0, ledger).is_ok() {
                self.spied.insert(target, (now, probes));
                ledger.spies += 1;
                ledger.spy_gates[if rescout { 5 } else if probes < wanted { 3 } else { 4 }] += 1;
            }
            return;
        }
        let mut wanted = SPY_PROBES;
        if fresh {
            let (Some(report), Some((sent, count))) = (report, previous) else { return };
            if !w.alliances_distinct || report.time < sent || report.research.is_some() || count >= MAX_FOLLOWUP_PROBES { return }
            wanted = (count * 2).min(MAX_FOLLOWUP_PROBES);
            // The previous reported chance bounds the estimated probe loss only while the target is unchanged.
            let risk = unit_price(&w.rules[a], kit.probe) * wanted as f64 * report.counter_chance as f64 / 100.0;
            if let Some(ships) = &report.ships {
                let rules = &w.rules[target];
                let military: f64 = ships.iter().enumerate().filter(|(u, _)| rules.units.kind.get(*u) == Some(&UnitKind::MilitaryShip))
                    .map(|(u, &n)| n as f64 * weighted(rules.units.units[u].price)).sum();
                if military <= risk { return }
            } else {
                // ponytail: only the public top rival gets an exploratory scan, budgeted to one hour of own mines.
                let top = w.public_highscore.iter().copied().find(|&b| b != a && !w.mates(a, b, now));
                if top != Some(w.account_of(target)) || risk > w.hourly(w.account_of(a)) { return }
            }
            self.miner.probes = self.miner.probes.max(wanted);
        }
        let recent = previous.is_some_and(|(t, _)| now - t < 2.0 * 3600.0);
        let available = on_shelf(w, a, kit.probe);
        if recent || available < 1 || (fresh && available < wanted) { return }
        let probes = available.min(wanted);
        if send(w, cat, a, target, Mission::Espionage, &[(kit.probe, probes)], 10.0, ledger).is_ok() {
            self.spied.insert(target, (now, probes));
            ledger.spies += 1;
        }
    }

    /// Sends fighters and cargo ships with an attack when the last full report says the loot pays for the fuel and the
    /// target cannot hurt.
    fn raid(&mut self, w: &mut World, cat: &Catalogue, a: usize, target: usize, kit: Kit, ledger: &mut Ledger) -> bool {
        let now = w.games[a].t;
        let newbie = w.raids && w.newbie && last_report(w, a, target).is_some() && protected_newbie(w, cat, a, target, now);
        let Some(report) = last_report(w, a, target) else { ledger.raid_gates[0] += 1; return false };
        let stale = now - report.time > REPORT_HOURS * 3600.0;
        let cooling = self.hit.get(&target).is_some_and(|&t| now - t < w.tn(a).raid_cooldown_hours * 3600.0);
        let bashed = w.raids && w.bodies && self.bashed.get_mut(&target).is_some_and(|times| {
            times.retain(|&t| now - t < 24.0 * 3600.0);
            times.len() >= BASH_LIMIT
        });
        let beaten = ledger.beaten.contains(&(a, target));
        if stale || cooling || bashed || newbie || beaten {
            ledger.raid_gates[1] += 1;
            ledger.stale_split[if stale { 0 } else if cooling { 1 } else if beaten { 2 } else if bashed { 3 } else { 4 }] += 1;
            return false;
        }
        let redacted = report.ships.is_none() || report.defence.is_none();
        if redacted && w.guess { ledger.guess_gates[0] += 1 }
        let Some(plan) = raid_plan(w, cat, a, target, kit).or_else(|| if w.war_fleet { war_plan(w, cat, a, target, kit, 10.0) } else { None }) else {
            ledger.raid_gates[2] += 1;
            let partial = last_report(w, a, target).is_some_and(|r| r.ships.is_none() || r.defence.is_none());
            let short = !partial && raid_plan_shelf_short(w, cat, a, target, kit);
            ledger.no_plan[if partial { 0 } else if short { 1 } else { 2 }] += 1;
            // More guns than the raider's fighters can take on: the missiles go first when the loot pays for the salvo.
            if defended_loot(w, cat, a, target) { self.strike(w, cat, a, target, ledger) }
            return false;
        };
        if redacted { ledger.guess_gates[1] += 1 }
        let rules = Arc::clone(&w.rules[a]);
        let research = w.research(cat, a);
        let fuel = fleet::consumption(&rules.settings, &rules.fleet, &plan.fleet, &research, account_distance(w, a, target), 0, 10.0) as f64;
        if weighted(plan.expected) < w.tn(a).pays * WEIGHTS[2] * fuel { ledger.raid_gates[3] += 1; return false }
        if !w.raids && plan.attack * 3.0 > w.tn(a).safe_share * plan.hull {
            ledger.raid_gates[4] += 1;
            self.strike(w, cat, a, target, ledger);
            return false;
        }
        let lands = now + fleet::flight_seconds(&rules.settings, Mission::Attack as u32, account_distance(w, a, target), rules.fleet.slowest_speed(&plan.fleet, &research), 10.0) as f64;
        if fleet_home_first(w, cat, a, target, lands) { ledger.raid_gates[5] += 1; return false }
        let had_fleet = stack(&w.rules[target], &w.yards[target].counts).iter().any(|&(id, _)| movable(&w.rules[target], id, &w.research(cat, target)));
        let Ok(arrive) = send(w, cat, a, target, Mission::Attack, &plan.fleet, 10.0, ledger) else { ledger.raid_gates[6] += 1; return false };
        ledger.raid_gates[7] += 1;
        if redacted {
            ledger.guess_gates[2] += 1;
            ledger.guessed.insert((a, target, arrive.to_bits()));
        }
        self.hit.insert(target, now);
        if w.raids && w.bodies { self.bashed.entry(target).or_default().push(now) }
        ledger.raids.push(Raid { attacker: a, target, arrive, loot: None, target_had_fleet: had_fleet, saved: false });
        true
    }

    /// A rich target whose defence turns the raid away is softened with missiles first, as a raider with a silo does
    /// (gate 3): the account keeps a salvo from then on, its prerequisites built through `unlock`, and fires what it holds.
    fn strike(&mut self, w: &mut World, cat: &Catalogue, a: usize, target: usize, ledger: &mut Ledger) {
        let Some(missile) = w.rules[a].strike.as_ref().map(|s| s.missile) else { return };
        self.miner.missiles = w.tn(a).missile_salvo.round() as i64;
        let now = w.games[a].t;
        let held = on_shelf(w, a, missile);
        if held < 1 || self.fired.get(&target).is_some_and(|&t| now - t < w.tn(a).raid_cooldown_hours * 3600.0) { return }
        if w.launch_missiles(cat, a, target, false, held, 0).is_ok() {
            self.fired.insert(target, now);
            ledger.missiles += held;
        }
    }

    /// M4: a raider holding the host's moon destroyer goes for a scouted moon with a fleet or an observed phalanx
    /// covering the raider's planet, when its reported guns
    /// cannot hurt its warships: the fleet is caught and the moon (its phalanx, its gate) may fall, as a deathstar
    /// player does (gate 3). The strike tables name the destroyer (gate 1).
    fn destroy_moon(&mut self, w: &mut World, cat: &Catalogue, a: usize, target: usize, ledger: &mut Ledger) -> bool {
        let Some(moon) = w.moon_of[target] else { return false };
        let now = w.games[a].t;
        if self.hit.get(&moon).is_some_and(|&t| now - t < w.tn(a).raid_cooldown_hours * 3600.0) { return false }
        let Some(fleet) = moon_strike_fleet(w, cat, a, target) else { return false };
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
        if weighted(field).min((n * hold) as f64) < w.tn(a).pays * WEIGHTS[2] * fuel { return }
        if send(w, cat, a, target, Mission::Recycle, &[(harvester, n)], 10.0, ledger).is_ok() { ledger.recycles += 1 }
    }
}

/// `Raider::destroy_moon`'s fleet at the moon over `target`: every warship that flies, when the account holds the moon
/// destroyer, a fresh full report on the moon (research included) shows a fleet parked there or a phalanx watching us,
/// and its guns cannot wear the fleet down. Reads the report only.
pub(crate) fn moon_strike_fleet(w: &World, cat: &Catalogue, a: usize, target: usize) -> Option<Vec<(u32, i64)>> {
    let moon = w.moon_of[target]?;
    let destroyer = w.rules[a].strike.as_ref().map(|s| s.moon_destroyer)?;
    let now = w.games[a].t;
    if on_shelf(w, a, destroyer) < 1 { return None }
    let report = last_report(w, a, moon).filter(|r| r.time <= now && now - r.time < REPORT_HOURS * 3600.0)?;
    let (Some(ships), Some(defence)) = (&report.ships, &report.defence) else { return None };
    // Arm `late`: a report without the research section estimates it as our own, as a raid's forecast does.
    let observed_research: crate::Levels = match (&report.research, w.late) {
        (Some(levels), _) => cat.objects.iter().zip(levels).filter(|(o, _)| o.queue == 1).map(|(o, &l)| (o.id as u32, l)).collect(),
        (None, true) => w.research(cat, a),
        (None, false) => return None,
    };
    let mut observed = stack(&w.rules[moon], ships);
    let hides_fleet = observed.iter().any(|&(id, _)| movable(&w.rules[moon], id, &observed_research));
    if !hides_fleet && !watches(w, cat, moon, a, report.buildings.as_deref()) { return None }
    observed.extend(stack(&w.rules[moon], defence));
    let (enemy_attack, _) = strength(&w.rules[moon], &observed_research, &observed);
    let research = w.research(cat, a);
    let rules = &w.rules[a];
    // Arm `late`: the destroyers fly alone, a pure stack (plan 5 Nov Deathstar rules 3 and 6, WIK-187: the loss roll takes
    // every ship of the fleet, so nothing else rides along).
    let fleet: Vec<(u32, i64)> = stack(rules, &w.yards[a].counts).into_iter()
        .filter(|&(id, _)| movable(rules, id, &research) && rules.combat.fights(id) && (!w.late || id == destroyer))
        .collect();
    let (_, hull) = strength(rules, &research, &fleet);
    (enemy_attack * 3.0 <= w.tn(a).safe_share * hull).then_some(fleet)
}

/// The host's moon destruction odds for account a's destroyers at the moon over `target` (`StrikeRules::chances`:
/// (100 - sqrt(diameter)) * sqrt(n) to destroy, sqrt(diameter) / 2 to lose the fleet; WIK-196). The diameter is what the
/// galaxy view shows of a moon; the count is the account's own shelf.
pub(crate) fn moon_odds(w: &World, a: usize, target: usize) -> Option<crate::strike::Chances> {
    let strike = w.rules[a].strike.as_ref()?;
    w.moon_of[target]?;
    Some(strike.chances(w.moon_holds[target].diameter, on_shelf(w, a, strike.moon_destroyer)))
}

/// Arm `late`, Deathstar rule 2 (plan 5 Nov, WIK-013, OGMEM: 10/11 attacks with the moon destroyer are a pure stack
/// against a defence wall, losing ~0): the destroyers alone, plus cargo hulls for the loot, against a target whose last
/// full report is mostly defence, when the host engine on that report wins outright and the stack loses under
/// `BREAKER_LOSS` of its value. `None` otherwise or when the stock cannot fuel it.
pub(crate) fn breaker_plan(w: &World, cat: &Catalogue, a: usize, target: usize, kit: Kit) -> Option<RaidPlan> {
    let destroyer = w.rules[a].strike.as_ref()?.moon_destroyer;
    let held = on_shelf(w, a, destroyer);
    if held < 1 { return None }
    let report = last_report(w, a, target)?;
    let (Some(ships), Some(defence)) = (&report.ships, &report.defence) else { return None };
    let trules = &w.rules[target];
    let walls: f64 = stack(trules, defence).into_iter().map(|(id, n)| unit_price(trules, id) * n as f64).sum();
    let fleet_value_seen: f64 = stack(trules, ships).into_iter().map(|(id, n)| unit_price(trules, id) * n as f64).sum();
    if walls <= 0.0 || walls < BREAKER_WALL * (walls + fleet_value_seen) { return None }
    let stack_only = [(destroyer, held)];
    let mut wall = stack(trules, ships);
    wall.extend(stack(trules, defence));
    // The engine is scaled to `BREAKER_UNITS` on the larger side; a stack smaller than the scale would be rounded up
    // to one hull and win fights it cannot, so such a wall is out of reach.
    let scale = wall.iter().map(|s| s.1).sum::<i64>() as f64 / BREAKER_UNITS;
    if (held as f64) < scale { return None }
    let rules = &w.rules[a];
    let research = w.research(cat, a);
    let observed_research = report_research(cat, report, &research);
    let (loss, win) = fight(w, (a, &stack_only, &research), (target, &wall, &observed_research), report.resources.map(|r| r.max(0) as f64), BREAKER_UNITS);
    if win < 1.0 || loss > BREAKER_LOSS * fleet_value(w, a, &stack_only) { return None }
    let loot = report.resources.map(|r| r.max(0) as f64 * rules.combat.rules.loot_percentage / 100.0);
    let carried = rules.combat.capacity(&stack_only.iter().copied().collect(), &research);
    let hold = rules.combat.capacity(&[(kit.cargo, 1)].into_iter().collect(), &research).max(1);
    let cargo = (((loot.iter().sum::<f64>() - carried as f64) / hold as f64).ceil() as i64).clamp(0, on_shelf(w, a, kit.cargo));
    let mut fleet = stack_only.to_vec();
    if cargo > 0 { fleet.push((kit.cargo, cargo)) }
    let expected = combat::distribute_loot(loot, carried + cargo * hold);
    let (_, hull) = strength(rules, &research, &fleet);
    let distance = account_distance(w, a, target);
    let fuel = fleet::consumption(&rules.settings, &rules.fleet, &fleet, &research, distance, 0, 10.0) as f64;
    if fuel > w.games[a].planet.res[2] || rules.combat.fuel_capacity(&fleet.iter().copied().collect(), &research) < fuel as i64 { return None }
    Some(RaidPlan { fleet, expected, attack: 0.0, hull })
}

/// The share of a target's reported value its defence must hold for a wall breaker (OGMEM: median 88% defence).
const BREAKER_WALL: f64 = 0.5;
/// The most of its value a wall breaker may expect to lose (OGMEM: the stacks lose ~0 hulls).
const BREAKER_LOSS: f64 = 0.05;
const BREAKER_UNITS: f64 = 2000.0;

/// The missiles account a would fire at `target` (`Raider::strike`): all it holds, when the last report shows a defence
/// that turns raids away over loot worth the salvo and the target is in range. `None` otherwise.
pub(crate) fn strike_salvo(w: &World, cat: &Catalogue, a: usize, target: usize) -> Option<i64> {
    let strike = w.rules[a].strike.as_ref()?;
    let held = on_shelf(w, a, strike.missile);
    if held < 1 || !defended_loot(w, cat, a, target) { return None }
    let coords = |p: usize| [w.coords[p].galaxy, w.coords[p].system, w.coords[p].position];
    let order = crate::strike::Launch {
        missiles: held, available: held, target_exists: true, own_target: w.account_of(target) == w.account_of(a),
        impulse_level: w.research(cat, a).get(&strike.range_research).copied().unwrap_or(0), from: coords(a), to: coords(target),
    };
    crate::strike::launch(strike, &order).is_ok().then_some(held)
}

/// Whether a raider facing `target` keeps a missile salvo: its last fresh report shows a defence the raid plan cannot
/// take on over loot worth the salvo (the moment `Raider::raid` turns to `strike`).
pub(crate) fn salvo_due(w: &World, cat: &Catalogue, a: usize, target: usize, kit: Kit) -> bool {
    let now = w.games[a].t;
    last_report(w, a, target).is_some_and(|r| now - r.time <= REPORT_HOURS * 3600.0) && raid_plan(w, cat, a, target, kit).is_none() && defended_loot(w, cat, a, target)
}

/// One of account a's moons whose phalanx reaches `target` and holds the deuterium for a scan: what `fleet_home_first`
/// reads a target through.
pub(crate) fn phalanx_moon(w: &World, cat: &Catalogue, a: usize, target: usize) -> Option<usize> {
    if w.moon_at[target].is_some() { return None }
    let gear = w.rules[a].settings.moon_gear.as_ref()?;
    w.planets_of(a).into_iter().filter_map(|p| w.moon_of[p]).find(|&m| {
        let level = w.moon_level(cat, m, gear.phalanx_object);
        let range = gear.phalanx_range[level.min(gear.phalanx_range.len() - 1)];
        let (from, to) = (w.coords[m], w.coords[target]);
        let systems = (from.system - to.system).abs();
        level > 0 && range >= 0 && from.galaxy == to.galaxy && systems.min((systems - w.rules[a].settings.number_of_systems).abs()) <= range
            && w.games[m].planet.res[2] >= gear.phalanx_cost
    })
}

/// Whether the fleet on moon slot `moon` can jump to another of the account's gated moons now (`gate_escape`'s test).
pub(crate) fn gate_open(w: &World, cat: &Catalogue, moon: usize) -> bool {
    let Some(gear) = w.rules[moon].settings.moon_gear.as_ref() else { return false };
    let now = w.games[w.account_of(moon)].t;
    let rested = |m: usize| w.moon_level(cat, m, gear.gate_object) > 0 && w.gate_ready[m] <= now;
    w.moon_at[moon].is_some() && rested(moon) && !gate_ships(w, moon, &gear.gate_ships).is_empty()
        && w.planets_of(w.account_of(moon)).into_iter().filter_map(|p| w.moon_of[p]).any(|m| m != moon && rested(m))
}

/// Whether moon slot `moon` has a phalanx whose range reaches planet `p` (same galaxy, systems around the donut).
pub(crate) fn watches(w: &World, cat: &Catalogue, moon: usize, p: usize, buildings: Option<&[u32]>) -> bool {
    let Some(g) = w.rules[moon].settings.moon_gear.as_ref() else { return false };
    let Some(level) = cat.objects.iter().position(|o| o.id == g.phalanx_object).and_then(|k| buildings.and_then(|b| b.get(k))).copied() else { return false };
    let level = level as usize;
    let range = g.phalanx_range[level.min(g.phalanx_range.len() - 1)];
    let (from, to, systems) = (w.coords[moon], w.coords[p], w.rules[moon].settings.number_of_systems);
    let d = (from.system - to.system).abs();
    level > 0 && from.galaxy == to.galaxy && d.min(systems - d) <= range
}

/// Whether the last full report on `target` shows defence that shoots and loot worth more than a missile salvo.
pub(crate) fn defended_loot(w: &World, cat: &Catalogue, a: usize, target: usize) -> bool {
    let Some(report) = last_report(w, a, target) else { return false };
    let Some(defence) = &report.defence else { return false };
    let Some(missile) = w.rules[a].strike.as_ref().map(|s| s.missile) else { return false };
    let observed_research = report_research(cat, report, &w.research(cat, a));
    let shoots = strength(&w.rules[target], &observed_research, &stack(&w.rules[target], defence)).0 > 0.0;
    let loot = weighted(report.resources.map(|r| r.max(0) as f64 * w.rules[a].combat.rules.loot_percentage / 100.0));
    shoots && loot > w.tn(a).missile_salvo.round() * unit_price(&w.rules[a], missile)
}

/// M4: a raider with a phalanx in range reads the target before it attacks and holds back when the target's own fleet
/// lands there before the raid would (gate 3: a phalanx is how a player times a hit). Without a phalanx it raids blind.
pub(crate) fn fleet_home_first(w: &mut World, cat: &Catalogue, a: usize, target: usize, lands: f64) -> bool {
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
#[derive(Clone)]
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
        let post = post_moon(w, cat, a).unwrap_or(a);
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
        // Attacks name the actual slot; moon destruction names the parent planet and sets the moon flag.
        let aimed = |f: &Fleet| match f.mission {
            Mission::Attack => f.target == post,
            Mission::MoonDestroy => post != a && f.target == a && f.moon,
            _ => false,
        };
        let coming: Vec<(usize, f64)> = w.fleets.iter().filter(|f| aimed(f) && !f.returning && f.arrive > now).map(|f| (f.owner, f.arrive)).collect();
        let fresh: Vec<f64> = coming.into_iter().filter(|c| self.answered.insert((c.0, c.1.to_bits()))).map(|c| c.1).collect();
        for lands in fresh {
            if gate_escape(w, cat, post).is_some() {
                ledger.saves_sent += 1;
                continue;
            }
            let saved = self.leave(w, cat, post, lands - now, ledger);
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

/// Hours from second `t` until account a's night starts (its sleep window, the same clock `FleetSaver` reads).
pub(crate) fn hours_to_night(w: &World, a: usize, t: f64) -> f64 {
    (w.games[a].sleep_start - (t % 86_400.0) / 3600.0).rem_euclid(24.0)
}

/// Arm `late`, FS-001: the login before an offline gap of hours, the one a player fleet-saves at.
pub(crate) fn night_due(w: &World, a: usize) -> bool {
    w.late && w.games[a].sleep_hours > 0.0 && hours_to_night(w, a, w.games[a].t) <= 1.0
}

/// Arm `late`: the night save of the fleet on slot `from` (FS-001) as its waves, each (to, ships, speed): away until
/// the account wakes plus the fleet's own flight (FS-007, it lands after the login), the destination and speed varied
/// by the day (FS-002: never the same landing every night), and split in two waves over two destinations when two slots
/// are free (FS-009: a phalanx-timed crash catches only part). `None` when nothing flies or no route is fuelled.
pub(crate) fn night_plan(w: &World, cat: &Catalogue, a: usize, from: usize) -> Option<Vec<(usize, Vec<(u32, i64)>, f64)>> {
    let rules = &w.rules[from];
    let research = w.research(cat, from);
    let ships: Vec<(u32, i64)> = stack(rules, &w.yards[from].counts).into_iter().filter(|&(id, _)| movable(rules, id, &research)).collect();
    if ships.is_empty() { return None }
    let now = w.games[a].t;
    let remaining = (hours_to_night(w, a, now) + w.games[a].sleep_hours) * 3600.0;
    let out = w.fleets.iter().filter(|f| f.mission != Mission::Missile && w.account_of(f.owner) == w.account_of(a)).count();
    let free = w.fleet_slots(cat, a).map_or(usize::MAX, |slots| slots.saturating_sub(out));
    if free == 0 { return None }
    let waves: Vec<Vec<(u32, i64)>> = match free >= 2 && ships.iter().all(|s| s.1 >= 2) {
        true => vec![ships.iter().map(|&(id, n)| (id, n / 2)).collect(), ships.iter().map(|&(id, n)| (id, n - n / 2)).collect()],
        false => vec![ships.clone()],
    };
    let slowest = rules.fleet.slowest_speed(&ships, &research);
    let mut routes: Vec<(usize, f64)> = Vec::new();
    for speed in [1.0, 2.0, 5.0, 10.0] {
        for b in others_by_distance(w, from).into_iter().filter(|&b| w.account_of(b) != w.account_of(a)) {
            let away = 2 * fleet::flight_seconds(&rules.settings, Mission::Transport as u32, account_distance(w, from, b), slowest, speed);
            if (away as f64) > remaining + 120.0 { routes.push((b, speed)) }
            if routes.len() >= NIGHT_ROUTES { break }
        }
        if routes.len() >= NIGHT_ROUTES { break }
    }
    if routes.is_empty() { return None }
    let mut draw = (w.account_of(a) as u64) << 32 ^ (now / 86_400.0) as u64;
    let first = crate::economy::next_random(&mut draw) as usize % routes.len();
    let plan: Vec<(usize, Vec<(u32, i64)>, f64)> = waves.into_iter().enumerate().map(|(i, wave)| {
        let (b, speed) = routes[(first + i) % routes.len()];
        (b, wave, speed)
    }).collect();
    let fuel: f64 = plan.iter().map(|(b, wave, speed)| fleet::consumption(&rules.settings, &rules.fleet, wave, &research, account_distance(w, from, *b), 0, *speed) as f64).sum();
    (fuel <= w.games[from].planet.res[2]).then_some(plan)
}

/// Arm `late`, FS-001's exposure: the night save is for a fleet someone is hunting, shown by what the account's own
/// messages show a player: a probe from another account on any of its planets or moons, or an attack on one, within
/// the last `EXPOSED_HOURS`. Without it a nightly save of every fleet cost the top accounts 8% of their score (bench
/// runs/late abl-night) for a risk nobody had shown.
pub(crate) fn exposed(w: &World, a: usize) -> bool {
    let now = w.games[a].t;
    let account = w.account_of(a);
    let hours = w.tn(a).exposed_hours;
    let recent = |t: f64| t <= now && now - t < hours * 3600.0;
    w.reports.iter().rev().take_while(|r| now - r.time < hours * 3600.0 || r.time > now)
        .any(|r| recent(r.time) && w.account_of(r.target) == account && w.account_of(r.owner) != account)
        || w.last_attacked.iter().any(|(&slot, &t)| recent(t) && w.account_of(slot) == account)
}

/// How far back a probe or an attack still marks an account as hunted (FS-001's exposure).
const EXPOSED_HOURS: f64 = 24.0;

/// The routes a night save draws its landing from (FS-002).
const NIGHT_ROUTES: usize = 3;

/// Arm `late`, FS-010: the save from an attack landing in `remaining` seconds as a deployment to another planet of the
/// account whose one-way flight is twice that long: recalled once the attack has passed, it never lands, and an
/// outbound deployment is not on the phalanx (`World::phalanx`). (to, ships, speed), or `None` without such a planet.
pub(crate) fn hidden_save(w: &World, cat: &Catalogue, a: usize, remaining: f64) -> Option<(usize, Vec<(u32, i64)>, f64)> {
    let rules = &w.rules[a];
    let research = w.research(cat, a);
    let ships: Vec<(u32, i64)> = stack(rules, &w.yards[a].counts).into_iter().filter(|&(id, _)| movable(rules, id, &research)).collect();
    if ships.is_empty() { return None }
    let slowest = rules.fleet.slowest_speed(&ships, &research);
    for speed in [1.0, 2.0, 5.0, 10.0] {
        for b in w.planets_of(a).into_iter().filter(|&b| b != a) {
            let one_way = fleet::flight_seconds(&rules.settings, Mission::Deploy as u32, account_distance(w, a, b), slowest, speed) as f64;
            if one_way <= 2.0 * remaining + 120.0 { continue }
            let fuel = fleet::consumption(&rules.settings, &rules.fleet, &ships, &research, account_distance(w, a, b), 0, speed) as f64;
            if fuel <= w.games[a].planet.res[2] { return Some((b, ships, speed)) }
        }
    }
    None
}

/// Arm `late`, NIN-001/002/004: the fleet kept on the post moon (`post_moon`) (absent from the spy report that drew the raid)
/// deploys down to the capital to land on the attack's second, when the event list shows the attacking ships (the
/// host's full fleet intel) and the host engine says planet and fleet together destroy them. (moon, ships, speed): the
/// slowest speed that lands within `NINJA_WINDOW` seconds before the attack. The attacker's research is its last
/// report's, else taken as our own.
pub(crate) fn ninja_plan(w: &World, cat: &Catalogue, a: usize) -> Option<(usize, Vec<(u32, i64)>, f64)> {
    let moon = post_moon(w, cat, a)?;
    let now = w.games[a].t;
    let lands = w.fleets.iter().filter(|f| f.target == a && f.mission == Mission::Attack && !f.returning && f.arrive > now).map(|f| f.arrive).min_by(f64::total_cmp)?;
    let research = w.research(cat, a);
    let espionage = w.rules[a].combat.missions.espionage_research;
    if espionage.and_then(|id| research.get(&id).copied()).unwrap_or(0) < FULL_FLEET_INTEL { return None }
    let rules = &w.rules[moon];
    let fleet: Vec<(u32, i64)> = warships(rules, &research, &w.yards[moon].counts).into_iter().filter(|&(id, _)| rules.combat.fights(id)).collect();
    if fleet.is_empty() { return None }
    let distance = fleet::distance(&rules.settings, w.coords[moon], w.coords[a], &|_, _| SystemState::Active);
    let slowest = rules.fleet.slowest_speed(&fleet, &research);
    let speed = (1..=10).map(f64::from).find(|&speed| {
        let at = now + fleet::flight_seconds(&rules.settings, Mission::Deploy as u32, distance, slowest, speed) as f64;
        at < lands && at >= lands - NINJA_WINDOW
    })?;
    let coming: Vec<&Fleet> = w.fleets.iter().filter(|f| f.target == a && f.mission == Mission::Attack && !f.returning && f.arrive == lands).collect();
    let attacker = coming.first()?.owner;
    let mut raid: Vec<(u32, i64)> = Vec::new();
    for &(id, n) in coming.iter().flat_map(|f| f.ships.iter()) {
        match raid.iter_mut().find(|s| s.0 == id) { Some(s) => s.1 += n, None => raid.push((id, n)) }
    }
    let raider_account = w.account_of(attacker);
    let raider_research = w.reports.iter().rev()
        .find(|r| r.owner == a && w.account_of(r.target) == raider_account && r.research.is_some())
        .map_or_else(|| research.clone(), |r| report_research(cat, r, &research));
    let mut home = stack(&w.rules[a], &w.yards[a].counts);
    for &(id, n) in &fleet {
        match home.iter_mut().find(|s| s.0 == id) { Some(s) => s.1 += n, None => home.push((id, n)) }
    }
    let (_, win) = fight(w, (attacker, &raid, &raider_research), (a, &home, &research), w.games[a].planet.res, 200.0);
    (win == 0.0).then_some((moon, fleet, speed))
}

/// How long before the attack's second a ninja fleet may land (FS-004: a player wakes 120-180 s before impact).
const NINJA_WINDOW: f64 = 300.0;

/// The moon a fleeter keeps its fleet on: its capital's; with arm `late` the first of its moons with a jump gate when
/// the capital's has none (CRASH-004: the gate is what makes a moon a safe post).
pub(crate) fn post_moon(w: &World, cat: &Catalogue, a: usize) -> Option<usize> {
    let gated = |m: usize| w.rules[m].settings.moon_gear.as_ref().is_some_and(|g| w.moon_level(cat, m, g.gate_object) > 0);
    let capital = w.moon_of[a];
    if !w.late || capital.is_some_and(gated) { return capital }
    w.planets_of(a).into_iter().filter_map(|p| w.moon_of[p]).find(|&m| gated(m)).or(capital)
}

/// M4: the capital's warships deploy to its moon with a hold of deuterium for the moon's own save, up to a hold the
/// moon does not have yet and half of what the capital holds.
pub(crate) fn station(w: &mut World, cat: &Catalogue, a: usize, moon: usize, ledger: &mut Ledger) {
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
pub(crate) fn gate_escape(w: &mut World, cat: &Catalogue, moon: usize) -> Option<usize> {
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
#[derive(Clone)]
pub enum Player {    Miner(Miner),
    Raider(Raider),
    Saver(FleetSaver),
}

impl Player {
    /// The economy every persona shares.
    pub fn miner_ref(&self) -> &Miner {
        match self {
            Player::Miner(m) => m,
            Player::Raider(r) => &r.miner,
            Player::Saver(s) => &s.miner,
        }
    }

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
            Player::Miner(p) => {
                let pick = p.act(w, cat, a);
                if p.moon_post && w.account_of(a) == a {
                    if let Some(post) = post_moon(w, cat, a) {
                        w.catch_up(cat, post, w.games[a].t);
                        station(w, cat, a, post, ledger);
                    }
                }
                pick
            }
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
    /// A planet that cannot make the energy its next field adder asks for still keeps the field from its building queue (a
    /// spent last field seals it for good), and its research does not start the chain early.
    #[test]
    fn a_planet_short_of_its_field_chains_energy_keeps_the_reserve_and_researches_nothing_for_it() {
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
        assert!(field_reserve(&world, &cat, 0).is_some(), "a planet short of energy spent its last field");
        world.games[0].queue = 1;
        assert!(field_reserve(&world, &cat, 0).is_none(), "research started a chain the planet cannot power");
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

    /// Arm `bodies`: the scout weighs every body the galaxy view shows, so a rival's colony next door comes before a
    /// homeworld three galaxies away, which the capitals-only list never offered.
    #[test]
    fn the_nearest_bodies_include_a_rivals_colony_next_door() {
        let Ok(mut cat) = Catalogue::load(&format!("{DATA}/units-parity/econ-u1-colonies.json")) else { return eprintln!("no catalogue; skipped") };
        cat.login_gaps = vec![600.0, 3600.0];
        let Ok(rules) = Rules::load(&cat, &format!("{DATA}/units-parity/units-u1.json"), &format!("{DATA}/fleet-settings-u1.json"), &format!("{DATA}/combat-u1.json")) else { return eprintln!("no rules; skipped") };
        let rules = Arc::new(rules);
        let mut world = World::new(&cat, &[0, 0, 0], HOURS, 0.0, 5);
        world.arm(vec![Arc::clone(&rules), Arc::clone(&rules), rules], vec![
            Coord { galaxy: 3, system: 100, position: 8 }, Coord { galaxy: 6, system: 400, position: 8 }, Coord { galaxy: 4, system: 200, position: 8 },
        ]);
        let colony = world.add_planet(&cat, 1, 0, Coord { galaxy: 3, system: 101, position: 9 }, 0.0);
        assert_eq!(nearest(&mut world, &cat, 0, 1), vec![2], "capitals only: the nearer homeworld");
        assert_eq!(nearest_bodies(&mut world, &cat, 0, 3), vec![colony, 2, 1], "every body, closest first");
    }

    /// OGame's protection bands: 1:5 under 50,000 points, 1:10 up to 500,000, none above.
    #[test]
    fn newbie_protection_follows_the_live_servers_bands() {
        assert!(newbie_protects(10_000.0, 50_001.0) && !newbie_protects(10_000.0, 50_000.0));
        assert!(newbie_protects(100_000.0, 1_000_001.0) && !newbie_protects(100_000.0, 600_000.0));
        assert!(!newbie_protects(500_000.0, 1e12), "above the limit nobody is protected");
    }

    /// Arm `newbie`: an active account under a fifth of the raider's points carries the galaxy view's newbie mark and
    /// is left alone; the same account is fair game for a raider of its own size.
    #[test]
    fn an_active_account_under_a_fifth_of_the_raiders_points_is_a_protected_newbie() {
        let Ok(cat) = Catalogue::load(&format!("{DATA}/units-parity/econ-u1-colonies.json")) else { return eprintln!("no catalogue; skipped") };
        let Ok(rules) = Rules::load(&cat, &format!("{DATA}/units-parity/units-u1.json"), &format!("{DATA}/fleet-settings-u1.json"), &format!("{DATA}/combat-u1.json")) else { return eprintln!("no rules; skipped") };
        let rules = Arc::new(rules);
        let mut world = World::new(&cat, &[0, 0, 0], HOURS, 0.0, 5);
        world.arm(vec![Arc::clone(&rules), Arc::clone(&rules), rules], vec![
            Coord { galaxy: 1, system: 1, position: 4 }, Coord { galaxy: 1, system: 2, position: 4 }, Coord { galaxy: 1, system: 3, position: 4 },
        ]);
        let mine = cat.objects.iter().position(|o| o.queue == 0).expect("a building");
        world.games[0].planet.levels[mine] = 20;
        world.games[2].planet.levels[mine] = 19;
        world.games[1].planet.levels[mine] = 2;
        assert!(protected_newbie(&mut world, &cat, 0, 1, 0.0), "a tiny account is the big raider's newbie");
        assert!(!protected_newbie(&mut world, &cat, 0, 2, 0.0), "a peer is not");
        assert!(!protected_newbie(&mut world, &cat, 1, 0, 0.0), "the big account is never the small one's newbie");
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
    fn a_long_research_waits_for_the_lab_level_that_shortens_it() {
        let Some((cat, mut world)) = bare(1) else { return };
        let Some(&(lab, _)) = cat.modifiers[1].first() else { return };
        let game = &mut world.games[0];
        for k in (0..cat.objects.len()).filter(|&k| cat.objects[k].queue == 1) { game.planet.levels[k] = 10 }
        (game.planet.levels[lab], game.planet.res, game.queue) = (1, [1e15; 3], 0);
        cat.refresh(&mut game.planet);
        assert_eq!(lab_ahead(&world, &cat, 0), Some(lab), "a minutes-long lab level before a research of weeks");
        world.games[0].queue = 1;
        world.games[0].free[0] = world.games[0].t + 3600.0;
        assert_eq!(lab_ahead(&world, &cat, 0), None, "the research does not wait on a building queue that is busy");
        let Some(k) = (0..cat.objects.len()).find(|&k| cat.objects[k].queue == 1) else { return };
        let game = &mut world.games[0];
        (game.queue, game.free[0]) = (0, game.t);
        game.pending.push((game.t + 3600.0, k));
        assert_eq!(lab_ahead(&world, &cat, 0), None, "the host holds the lab while a research runs");
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
        power_by_units(&mut world, &cat, 0, None, false);
        let ordered: u64 = world.yards[0].orders.iter().filter(|o| o.unit == u).map(|o| o.amount).sum();
        let planet = &world.games[0].planet;
        assert!(ordered as f64 * each >= planet.energy_used - planet.energy_max, "the order covers the shortfall");
        power_by_units(&mut world, &cat, 0, None, false);
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
        world.alliance_id[1] = 1;
        assert!(!defend_mate(&mut world, &cat, 0, |_| true, &mut ledger), "no hold for another alliance");
        world.alliance_id[1] = 0;
        set_espionage(&mut world, &cat, 1, FULL_FLEET_INTEL - 1);
        assert!(!defend_mate(&mut world, &cat, 0, |_| true, &mut ledger), "the mate's event list hides the raid's amounts");
        set_espionage(&mut world, &cat, 1, FULL_FLEET_INTEL);
        let left = world.fleets[0].departed;
        world.online_at[1] = left - 1.0;
        assert!(!defend_mate(&mut world, &cat, 0, |_| true, &mut ledger), "a mate offline since the raid left has told nobody");
        world.online_at[1] = left;
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

    fn set_espionage(world: &mut World, cat: &Catalogue, slot: usize, level: u32) {
        let tech = world.rules[slot].combat.missions.espionage_research.expect("espionage research");
        let index = cat.objects.iter().position(|o| o.id as u32 == tech).expect("research in catalogue");
        world.games[slot].planet.levels[index] = level;
    }

    /// `fuelkeep`: a capital with warships lets a build that pays deuterium wait while the store would fall below the
    /// strike fuel; unit purchases (probes among them) are not held back, and without the arm nothing changes.
    #[test]
    fn a_fleeter_keeps_the_fuel_its_warships_strike_with() {
        let (cat, mut world) = bare(5).expect("the player test catalogue");
        let fighter = Kit::of(&world, &cat, 0).expect("a fighter").fighter;
        let k = (0..cat.objects.len()).find(|&k| cat.objects[k].price[world.games[0].planet.levels[k] as usize][2] > 0.0).expect("a build that costs deuterium");
        let deuterium = cat.objects[k].price[world.games[0].planet.levels[k] as usize][2];
        world.fuel_keep = true;
        world.games[0].planet.res[2] = deuterium;
        assert!(!burns_strike_fuel(&world, &cat, 0, k), "nothing to keep without warships");
        assert!(world.set_units(&cat, 0, fighter, 10_000));
        let fuel = strike_fuel(&world, &cat, 0);
        assert!(fuel > 0.0);
        world.games[0].planet.res[2] = fuel + deuterium - 1.0;
        assert!(burns_strike_fuel(&world, &cat, 0, k), "the build waits on the strike fuel");
        assert_eq!(saving(&world, &cat, 0, None)[2], 0.0, "unit purchases keep no fuel");
        world.games[0].planet.res[2] = fuel + deuterium;
        assert!(!burns_strike_fuel(&world, &cat, 0, k), "with the fuel kept the build starts");
        world.fuel_keep = false;
        world.games[0].planet.res[2] = 0.0;
        assert!(!burns_strike_fuel(&world, &cat, 0, k), "the arm off builds as before");
    }

    /// An ally sized at the margin: the raider's research decides the hold, so only research it has a report of may.
    #[test]
    fn a_mates_defence_does_not_read_the_raiders_unreported_research() {
        let (cat, mut world) = bare(5).expect("the player test catalogue");
        let fighter = Kit::of(&world, &cat, 0).expect("a fighter hull").fighter;
        world.coords[2] = Coord { galaxy: 1, system: 60, position: 8 };
        for a in [0, 2] { world.games[a].planet.res = [1e9; 3] }
        for a in [0, 1] { world.alliance_at[a] = Some(0.0) }
        set_espionage(&mut world, &cat, 1, FULL_FLEET_INTEL);
        assert!(world.set_units(&cat, 2, fighter, 2_000) && world.set_units(&cat, 0, fighter, 10_000), "both hold fighters");
        world.dispatch(&cat, 2, 1, Mission::Attack, &[(fighter, 2_000)], [0.0; 3], 10.0).expect("the raid flies");
        let strong: Vec<u32> = cat.objects.iter().map(|o| if o.queue == 1 { 20 } else { 0 }).collect();
        let mut hidden = world.clone();
        for (k, &l) in strong.iter().enumerate() { if l > 0 { hidden.games[2].planet.levels[k] = l } }
        let mut reported = hidden.clone();
        reported.reports.push(SpyReport { owner: 1, target: 2, time: 0.0, resources: [0; 3], debris: None, counter_chance: 0, defence: None, ships: None, buildings: None, research: Some(strong) });
        let decide = |w: &mut World| defend_mate(w, &cat, 0, |_| true, &mut Ledger::default());
        assert!(decide(&mut world), "an even raid is held");
        assert!(decide(&mut hidden), "unseen research leaves the decision as it was");
        assert!(!decide(&mut reported), "the mate's report of that research turns the ally away");
    }

    #[test]
    fn alliance_can_plan_a_coordinated_attack_on_a_scouted_moon() {
        let (cat, mut world) = bare(5).expect("the combat catalogue");
        let fighter = Kit::of(&world, &cat, 0).expect("a fighter").fighter;
        world.coords[0] = Coord { galaxy: 1, system: 60, position: 8 };
        for a in [0, 1, 2] { world.games[a].planet.res = [1e9; 3] }
        world.alliance_at[0] = Some(0.0);
        world.alliance_at[2] = Some(0.0);
        world.alliance_id[1] = 1;
        world.alliances_distinct = true;
        world.public_highscore = vec![1, 0, 2];
        let moon = world.add_moon(&cat, 1, 8000, 0.0);
        assert!(world.set_units(&cat, 0, fighter, 1));
        assert!(world.set_units(&cat, moon, fighter, 100));
        assert!(world.set_units(&cat, 2, fighter, 2_000));
        let ships = world.yards[moon].counts.clone();
        world.reports.push(SpyReport { owner: 0, target: moon, time: 0.0, resources: [0; 3], debris: None, counter_chance: 0, defence: Some(vec![0; ships.len()]), ships: Some(ships), buildings: None, research: Some(vec![0; cat.objects.len()]) });
        let mut requests = Vec::new();
        let (mut leader, mut ally) = (Ledger::default(), Ledger::default());
        coordinate_war(&mut world, &cat, 0, &mut requests, &mut leader);
        assert!(requests.iter().any(|r| r.target == moon && r.ally == 2), "a scouted moon can be proposed");
        coordinate_war(&mut world, &cat, 2, &mut requests, &mut ally);
        assert!(requests.iter().any(|r| r.target == moon && r.accepted.is_some()), "the ally accepts");
        coordinate_war(&mut world, &cat, 0, &mut requests, &mut leader);
        assert!(world.fleets.iter().any(|f| f.owner == 0 && f.target == moon && f.mission == Mission::Attack));
        assert!(join_raid(&mut world, &cat, 2, |_| true, &mut ally), "the ally joins the moon attack");
        while !world.events.iter().any(|e| e.kind == EventKind::Fight && e.target == moon) {
            let slot = world.next_decision(&cat).expect("the world plays on");
            world.wait(&cat, slot);
        }
        assert_eq!(world.union_log.len(), 1, "both fleets fought at the moon");
    }

    #[test]
    fn allies_ask_accept_and_fight_a_coordinated_attack() {
        let (cat, mut world) = bare(5).expect("the combat catalogue");
        let fighter = Kit::of(&world, &cat, 0).expect("a fighter").fighter;
        world.coords[0] = Coord { galaxy: 1, system: 60, position: 8 };
        for a in [0, 1, 2] { world.games[a].planet.res = [1e9; 3] }
        world.alliance_at[0] = Some(0.0);
        world.alliance_at[2] = Some(0.0);
        world.alliance_id[1] = 1;
        world.alliances_distinct = true;
        world.public_highscore = vec![1, 0, 2];
        assert!(world.set_units(&cat, 0, fighter, 1));
        assert!(world.set_units(&cat, 1, fighter, 100));
        assert!(world.set_units(&cat, 2, fighter, 2_000));
        let ships = world.yards[1].counts.clone();
        world.reports.push(SpyReport { owner: 0, target: 1, time: 0.0, resources: [0; 3], debris: None, counter_chance: 0, defence: Some(vec![0; ships.len()]), ships: Some(ships), buildings: None, research: None });
        let mut requests = Vec::new();
        let (mut leader, mut ally) = (Ledger::default(), Ledger::default());
        coordinate_war(&mut world, &cat, 0, &mut requests, &mut leader);
        assert!(requests.is_empty(), "no request without a full report");
        world.reports.last_mut().unwrap().research = Some(vec![0; cat.objects.len()]);
        let mut dry = world.clone();
        dry.games[0].planet.res[2] = 0.0;
        let mut dry_requests = Vec::new();
        coordinate_war(&mut dry, &cat, 0, &mut dry_requests, &mut Ledger::default());
        assert!(dry_requests.is_empty(), "a leader that cannot fuel its fleet asks nobody to follow it");
        coordinate_war(&mut world, &cat, 0, &mut requests, &mut leader);
        assert!(requests.iter().any(|r| r.ally == 2 && r.accepted.is_none()), "the leader asks a mate");
        assert!(world.fleets.is_empty(), "the leader waits for a response");
        let (mut far, mut far_requests) = (world.clone(), requests.clone());
        far.coords[2].system = 300;
        coordinate_war(&mut far, &cat, 2, &mut far_requests, &mut Ledger::default());
        assert!(far_requests.iter().all(|r| r.accepted.is_none()), "an ally too far away cannot promise support");
        coordinate_war(&mut world, &cat, 2, &mut requests, &mut ally);
        assert!(requests.iter().any(|r| r.ally == 2 && r.accepted.is_some()), "the mate accepts at its login");
        let (mut stale, mut stale_requests) = (world.clone(), requests.clone());
        stale.games[0].t = 3_601.0;
        coordinate_war(&mut stale, &cat, 0, &mut stale_requests, &mut Ledger::default());
        assert!(stale.fleets.is_empty(), "an old acceptance cannot launch an attack");
        let (mut friendly, mut friendly_requests) = (world.clone(), requests.clone());
        friendly.alliance_at[1] = Some(0.0);
        friendly.alliance_id[1] = 0;
        coordinate_war(&mut friendly, &cat, 0, &mut friendly_requests, &mut Ledger::default());
        assert!(friendly.fleets.is_empty(), "an attack is not launched against a new ally");
        coordinate_war(&mut world, &cat, 0, &mut requests, &mut leader);
        assert!(world.fleets.iter().any(|f| f.owner == 0 && f.mission == Mission::Attack), "the leader launches");
        assert!(join_raid(&mut world, &cat, 2, |_| true, &mut ally), "the ally sends its own fleet");
        while !world.events.iter().any(|e| e.kind == EventKind::Fight && e.mission == Mission::Attack) {
            let slot = world.next_decision(&cat).expect("the world plays on");
            world.wait(&cat, slot);
        }
        assert_eq!(world.union_log.len(), 1, "both fleets participated in the same battle");
    }

    #[test]
    fn a_mate_sends_warships_to_support_an_outmatched_attack() {
        let (cat, mut world) = bare(5).expect("the combat catalogue");
        let fighter = Kit::of(&world, &cat, 0).expect("a fighter").fighter;
        world.coords[2] = Coord { galaxy: 1, system: 60, position: 8 };
        for a in [0, 1, 2] { world.games[a].planet.res = [1e9; 3] }
        world.alliance_at[0] = Some(0.0);
        world.alliance_at[2] = Some(0.0);
        world.alliances_distinct = true;
        assert!(world.set_units(&cat, 0, fighter, 2_000));
        assert!(world.set_units(&cat, 1, fighter, 100));
        assert!(world.set_units(&cat, 2, fighter, 1));
        let ships = world.yards[1].counts.clone();
        let defence = vec![0; ships.len()];
        world.reports.push(SpyReport { owner: 2, target: 1, time: 0.0, resources: [0; 3], debris: None, counter_chance: 0, ships: Some(ships), defence: Some(defence), buildings: None, research: None });
        world.dispatch(&cat, 2, 1, Mission::Attack, &[(fighter, 1)], [0.0; 3], 10.0).expect("the lead attack flies");
        let mut ledger = Ledger::default();
        assert!(!join_raid(&mut world, &cat, 0, |_| true, &mut ledger), "no forecast without observed research");
        world.reports.last_mut().unwrap().research = Some(vec![0; cat.objects.len()]);
        world.reports.last_mut().unwrap().time = -REPORT_HOURS * 3600.0;
        assert!(!join_raid(&mut world, &cat, 0, |_| true, &mut ledger), "no forecast from a stale report");
        world.reports.last_mut().unwrap().time = 0.0;
        world.alliance_id[2] = 1;
        assert!(!join_raid(&mut world, &cat, 0, |_| true, &mut ledger), "no combat support for another alliance");
        world.alliance_id[2] = 0;
        assert!(join_raid(&mut world, &cat, 0, |_| true, &mut ledger), "the ally sends combat ships");
        let partner = world.fleets.iter().find(|f| f.owner == 0).expect("the partner flies");
        assert_eq!(partner.ships, vec![(fighter, 2_000)]);
        while !world.events.iter().any(|e| e.kind == EventKind::Fight && e.mission == Mission::Attack) {
            let slot = world.next_decision(&cat).expect("the world plays on");
            world.wait(&cat, slot);
        }
        assert_eq!(world.union_log.len(), 1, "the combat fleet joined the battle");
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
        world.alliance_id[2] = 1;
        assert!(!join_raid(&mut world, &cat, 0, |_| true, &mut ledger), "no join for another alliance");
        world.alliance_id[2] = 0;
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

    #[test]
    fn raid_forecasts_do_not_read_unreported_enemy_research() {
        let (cat, mut world) = bare(5).expect("the player test catalogue");
        let kit = Kit::of(&world, &cat, 0).expect("a fighter and cargo");
        world.games[0].planet.res = [1e9; 3];
        assert!(world.set_units(&cat, 0, kit.fighter, 100_000));
        assert!(world.set_units(&cat, 0, kit.cargo, 100));
        assert!(world.set_units(&cat, 1, kit.fighter, 100));
        let ships = world.yards[1].counts.clone();
        world.reports.push(SpyReport { owner: 0, target: 1, time: 0.0, resources: [1_000_000; 3], debris: None, counter_chance: 0, defence: Some(vec![0; ships.len()]), ships: Some(ships), buildings: None, research: None });
        let mut hidden_changed = world.clone();
        for (k, object) in cat.objects.iter().enumerate() {
            if object.queue == 1 { hidden_changed.games[1].planet.levels[k] = 20 }
        }
        let original = raid_plan(&world, &cat, 0, 1, kit).expect("a forecast");
        let changed = raid_plan(&hidden_changed, &cat, 0, 1, kit).expect("a forecast");
        assert_eq!(original.fleet, changed.fleet, "unreported tech cannot affect the fleet sent");
        assert_eq!(original.attack, changed.attack, "unreported tech cannot affect the estimated threat");
        hidden_changed.reports.last_mut().unwrap().research = Some(cat.objects.iter().map(|o| if o.queue == 1 { 20 } else { 0 }).collect());
        let reported = raid_plan(&hidden_changed, &cat, 0, 1, kit).expect("a forecast with observed tech");
        assert!(reported.attack > original.attack, "reported enemy research changes the threat estimate");
    }

    #[test]
    fn a_top_rival_with_hidden_ships_gets_a_bounded_followup() {
        let (cat, mut world) = bare(5).expect("the player test catalogue");
        world.alliances_distinct = true;
        world.public_highscore = vec![1, 2];
        let kit = Kit::of(&world, &cat, 0).expect("a probe and fighter");
        world.games[0].planet.res = [1e9; 3];
        world.games[0].planet.rate = [10_000.0; 3];
        assert!(world.set_units(&cat, 0, kit.probe, 8));
        world.reports.push(SpyReport { owner: 0, target: 1, time: 0.0, resources: [0; 3], debris: None, counter_chance: 0, defence: None, ships: None, buildings: None, research: None });
        let (mut raider, mut ledger) = (Raider::new(1), Ledger::default());
        raider.spied.insert(1, (-7200.0, 4));
        let mut not_top = world.clone();
        not_top.public_highscore = vec![2, 1];
        let mut other_raider = raider.clone();
        other_raider.spy(&mut not_top, &cat, 0, 1, kit, &mut Ledger::default());
        assert!(not_top.fleets.is_empty(), "an unknown non-leading target is not escalated");
        let mut risky = world.clone();
        risky.games[0].planet.rate = [0.0; 3];
        risky.reports.last_mut().unwrap().counter_chance = 100;
        raider.clone().spy(&mut risky, &cat, 0, 1, kit, &mut Ledger::default());
        assert!(risky.fleets.is_empty(), "an unknown target does not justify risk above the production budget");
        raider.spy(&mut world, &cat, 0, 1, kit, &mut ledger);
        let sent = world.fleets.last().expect("the top rival gets a follow-up");
        assert_eq!(sent.ships, vec![(kit.probe, 8)]);
        assert_eq!(ledger.spies, 1);
    }

    #[test]
    fn raids_arm_volley_doubles_on_redacted_defended_reports() {
        let (cat, mut world) = bare(5).expect("the player test catalogue");
        let kit = Kit::of(&world, &cat, 0).expect("a probe and fighter");
        assert_eq!(spy_volley(&world, 0, 1), 1, "the opening sends one probe");
        assert!(world.set_units(&cat, 1, kit.fighter, 3));
        let ships = world.yards[1].counts.clone();
        let redacted = |time: f64| SpyReport { owner: 0, target: 1, time, resources: [0; 3], debris: None, counter_chance: 0, defence: None, ships: Some(ships.clone()), buildings: None, research: None };
        for (n, want) in [(1, 5), (2, 10), (3, 20), (4, 40), (5, 40)] {
            world.reports.push(redacted(n as f64));
            assert_eq!(spy_volley(&world, 0, 1), want, "{n} redacted reports in a row");
        }
        world.reports.push(SpyReport { defence: Some(vec![0; ships.len()]), ..redacted(9.0) });
        assert_eq!(spy_volley(&world, 0, 1), 1, "a complete report resets the volley");
    }

    #[test]
    fn guess_arm_fills_a_hidden_defence_with_the_unexplained_military_points() {
        let (cat, mut world) = bare(5).expect("the player test catalogue");
        let kit = Kit::of(&world, &cat, 0).expect("a probe and fighter");
        assert!(world.set_units(&cat, 1, kit.fighter, 10));
        let ships = world.yards[1].counts.clone();
        let units = &world.rules[1].units;
        let blank = vec![0u64; units.units.len()];
        let research = world.research(&cat, 0);
        let redacted = SpyReport { owner: 0, target: 1, time: 1.0, resources: [0; 3], debris: None, counter_chance: 0, defence: None, ships: Some(ships.clone()), buildings: None, research: None };
        // The shown fighters are all the military points there are: nothing is hidden.
        let (_, defence) = guessed_sections(&world, 0, 1, &redacted, &research).expect("an estimate");
        assert_eq!(defence.iter().sum::<u64>(), 0, "every military point is explained");
        // Hide the fighters from the report: their value comes back as defence, in the mix an older report read there.
        let mut older = blank.clone();
        let wall = (0..units.units.len()).find(|&u| units.kind[u] == UnitKind::Defence && world.rules[1].combat.attack(units.units[u].id as u32, &research) > 1).expect("a defence");
        older[wall] = 1;
        world.reports.push(SpyReport { ships: Some(blank.clone()), defence: Some(older), ..redacted.clone() });
        let blind = SpyReport { ships: Some(blank.clone()), ..redacted };
        let (_, defence) = guessed_sections(&world, 0, 1, &blind, &research).expect("an estimate");
        let points = crate::score::of(&world, 1).military as f64 * 1000.0;
        let each = units.units[wall].price.iter().sum::<f64>();
        assert_eq!(defence[wall], (points / each).ceil() as u64, "the hidden points stand here as the wall last seen");
    }

    #[test]
    fn rescout_arm_rereads_a_redacted_report_until_the_largest_volley() {
        let (cat, mut world) = bare(5).expect("the player test catalogue");
        world.rescout = true;
        let kit = Kit::of(&world, &cat, 0).expect("a probe and fighter");
        assert!(world.set_units(&cat, 1, kit.fighter, 3));
        let ships = world.yards[1].counts.clone();
        let redacted = |time: f64| SpyReport { owner: 0, target: 1, time, resources: [0; 3], debris: None, counter_chance: 0, defence: None, ships: Some(ships.clone()), buildings: None, research: None };
        // Opening floor 1: 1 -> 5 -> 10 -> 20 -> 40, and a fifth redacted report (read by 40) ends the re-scouts.
        for (n, want, due) in [(1, 5, true), (2, 10, true), (3, 20, true), (4, 40, true), (5, 40, false)] {
            world.reports.push(redacted(n as f64));
            assert_eq!(spy_volley(&world, 0, 1), want, "{n} redacted reports in a row");
            assert_eq!(rescout_due(&world, 0, 1), due, "{n} redacted reports in a row");
        }
        assert_eq!(rescout_volley(VOLLEY_PROBES, 1), 10, "past the opening the first redacted volley of 5 is doubled");
        world.reports.push(SpyReport { defence: Some(vec![0; ships.len()]), ..redacted(9.0) });
        assert!(!rescout_due(&world, 0, 1), "a complete report is settled intel");
    }

    #[test]
    fn raids_arm_launch_grows_from_the_warship_stock_until_the_forecast_wins() {
        let (cat, mut world) = bare(5).expect("the player test catalogue");
        world.raids = true;
        let kit = Kit::of(&world, &cat, 0).expect("a probe and fighter");
        world.games[0].planet.res = [1e9; 3];
        assert!(world.set_units(&cat, 1, kit.fighter, 20));
        let ships = world.yards[1].counts.clone();
        world.reports.push(SpyReport { owner: 0, target: 1, time: 0.0, resources: [1_000_000; 3], debris: None, counter_chance: 0, defence: Some(vec![0; ships.len()]), ships: Some(ships), buildings: None, research: None });
        assert!(world.set_units(&cat, 0, kit.fighter, 5));
        assert!(raid_plan(&world, &cat, 0, 1, kit).is_none(), "five fighters do not win against twenty");
        assert!(world.set_units(&cat, 0, kit.fighter, 400));
        assert!(world.set_units(&cat, 0, kit.cargo, 50));
        let plan = raid_plan(&world, &cat, 0, 1, kit).expect("the stock wins the forecast");
        assert!(plan.fleet.contains(&(kit.fighter, 400)), "the whole stock of the counter hull flies: {:?}", plan.fleet);
        assert!(plan.fleet.iter().any(|&(id, n)| id == kit.cargo && n > 0), "cargo rides for the loot");
    }

    #[test]
    fn reconnaissance_followup_respects_risk_and_waits_for_probes() {
        let (cat, mut world) = bare(5).expect("the player test catalogue");
        world.alliances_distinct = true;
        let kit = Kit::of(&world, &cat, 0).expect("a probe and fighter");
        world.games[0].planet.res = [1e9; 3];
        assert!(world.set_units(&cat, 0, kit.probe, 4));
        assert!(world.set_units(&cat, 1, kit.fighter, 1));
        let ships = world.yards[1].counts.clone();
        world.reports.push(SpyReport { owner: 0, target: 1, time: 0.0, resources: [0; 3], debris: None, counter_chance: 100, defence: Some(vec![0; ships.len()]), ships: Some(ships), buildings: None, research: None });
        let (mut raider, mut ledger) = (Raider::new(1), Ledger::default());
        raider.spied.insert(1, (-7200.0, 4));
        raider.spy(&mut world, &cat, 0, 1, kit, &mut ledger);
        assert!(world.fleets.is_empty() && raider.miner.probes == 4, "a low-value fleet does not justify the reported risk");
        let u = world.rules[1].units.index(kit.fighter as i64).unwrap();
        let report = world.reports.last_mut().unwrap();
        report.ships.as_mut().unwrap()[u] = 100;
        report.counter_chance = 0;
        raider.spy(&mut world, &cat, 0, 1, kit, &mut ledger);
        assert!(world.fleets.is_empty() && raider.miner.probes == 8, "the scout asks for more probes and waits");
        assert!(world.set_units(&cat, 0, kit.probe, 8));
        raider.spy(&mut world, &cat, 0, 1, kit, &mut ledger);
        let sent = world.fleets.last().expect("the follow-up was dispatched");
        assert_eq!(sent.ships, vec![(kit.probe, 8)]);
        assert_eq!(ledger.spies, 1);
    }

    #[test]
    fn a_war_raider_deepens_a_partial_military_report() {
        let (cat, mut world) = bare(5).expect("the player test catalogue");
        world.alliances_distinct = true;
        let kit = Kit::of(&world, &cat, 0).expect("a probe and fighter");
        let tech = world.rules[0].combat.missions.espionage_research.expect("espionage research");
        let index = cat.objects.iter().position(|o| o.id as u32 == tech).expect("research in catalogue");
        world.games[0].planet.levels[index] = 1;
        world.games[1].planet.levels[index] = 0;
        world.games[0].planet.res = [1e9; 3];
        assert!(world.set_units(&cat, 0, kit.probe, 8));
        assert!(world.set_units(&cat, 1, kit.fighter, 100));
        let (mut raider, mut ledger) = (Raider::new(1), Ledger::default());
        raider.spy(&mut world, &cat, 0, 1, kit, &mut ledger);
        for _ in 0..20_000 {
            if world.reports.iter().any(|r| r.owner == 0 && r.target == 1) { break }
            let slot = world.next_decision(&cat).expect("the first probe arrives");
            world.wait(&cat, slot);
        }
        let first = last_report(&world, 0, 1).expect("the first report");
        assert!(first.ships.is_some() && first.defence.is_some() && first.research.is_none(), "the first report is incomplete");
        assert_eq!(first.counter_chance, 0);
        for _ in 0..20_000 {
            let slot = world.next_decision(&cat).expect("the second probe arrives");
            if slot == 0 && world.games[0].t >= 2.0 * 3600.0 && on_shelf(&world, 0, kit.probe) >= 8 {
                raider.spy(&mut world, &cat, 0, 1, kit, &mut ledger);
            }
            world.wait(&cat, slot);
            if last_report(&world, 0, 1).is_some_and(|r| r.research.is_some()) { break }
        }
        assert!(last_report(&world, 0, 1).is_some_and(|r| r.research.is_some()), "a larger follow-up reveals research");
        assert_eq!(ledger.spies, 2, "one follow-up was sent");
    }

    #[test]
    fn a_war_raider_scouts_the_highest_ranked_active_rival_and_its_moon() {
        for war in [false, true] {
            let (cat, mut world) = bare(5).expect("the player test catalogue");
            let probe = Kit::of(&world, &cat, 0).expect("a probe").probe;
            assert!(world.set_units(&cat, 0, probe, 20));
            world.games[0].planet.res = [1e9; 3];
            world.alliances_distinct = war;
            world.alliance_at[0] = Some(0.0);
            world.alliance_at[1] = Some(0.0);
            world.alliance_at[11] = Some(0.0);
            world.alliance_id[11] = 1;
            world.public_highscore = vec![1, 11];
            let moon = world.add_moon(&cat, 11, 8000, 0.0);
            let mut raider = Raider { moon_raids: true, ..Raider::new(1) };
            raider.act(&mut world, &cat, 0, &mut Ledger::default());
            let spied = |target| world.fleets.iter().any(|f| f.owner == 0 && f.target == target && f.mission == Mission::Espionage);
            assert_eq!(spied(11), war, "only the war scenario scouts beyond its neighbours");
            assert_eq!(spied(moon), war, "the rival moon is scouted before the fleet slots fill");
            if war { assert!(!spied(1), "the higher-ranked ally is not a target") }
        }
    }

    #[test]
    fn a_fleet_saver_notices_an_attack_on_its_moon() {
        let (cat, mut world) = bare(5).expect("the player test catalogue");
        let fighter = Kit::of(&world, &cat, 0).expect("a fighter").fighter;
        world.games[0].sleep_start = 12.0;
        world.games[0].sleep_hours = 8.0;
        world.games[1].planet.res = [1e9; 3];
        let moon = world.add_moon(&cat, 0, 8000, 0.0);
        world.games[moon].planet.res = [1e9; 3];
        assert!(world.set_units(&cat, moon, fighter, 100));
        assert!(world.set_units(&cat, 1, fighter, 1));
        let mut planet_attack = world.clone();
        planet_attack.dispatch(&cat, 1, 0, Mission::Attack, &[(fighter, 1)], [0.0; 3], 10.0).expect("the planet is attacked");
        FleetSaver::new(1).act(&mut planet_attack, &cat, 0, &mut Ledger::default());
        assert!(!planet_attack.fleets.iter().any(|f| f.owner == moon && f.mission == Mission::Transport), "an attack on the planet does not target the fleet on the moon");
        let mut destruction = world.clone();
        let destroyer = destruction.rules[1].strike.as_ref().expect("moon destruction rules").moon_destroyer;
        assert!(destruction.set_units(&cat, 1, destroyer, 1));
        destruction.dispatch(&cat, 1, 0, Mission::MoonDestroy, &[(destroyer, 1)], [0.0; 3], 10.0).expect("the moon is threatened");
        FleetSaver::new(1).act(&mut destruction, &cat, 0, &mut Ledger::default());
        assert!(destruction.fleets.iter().any(|f| f.owner == moon && f.mission == Mission::Transport), "moon destruction also triggers a save");
        world.dispatch(&cat, 1, moon, Mission::Attack, &[(fighter, 1)], [0.0; 3], 10.0).expect("the moon is attacked");
        let mut ledger = Ledger::default();
        FleetSaver::new(1).act(&mut world, &cat, 0, &mut ledger);
        assert!(world.fleets.iter().any(|f| f.owner == moon && f.mission == Mission::Transport && !f.returning), "the fleet leaves the attacked moon");
        assert_eq!(ledger.saves_sent, 1);
    }

    #[test]
    fn moon_destruction_decisions_use_reports_not_hidden_fleets() {
        let (cat, mut world) = bare(5).expect("the player test catalogue");
        let kit = Kit::of(&world, &cat, 0).expect("a fighter");
        let destroyer = world.rules[0].strike.as_ref().expect("moon destruction rules").moon_destroyer;
        world.games[0].planet.res = [1e9; 3];
        let moon = world.add_moon(&cat, 1, 8000, 0.0);
        assert!(world.set_units(&cat, 0, destroyer, 20));
        assert!(world.set_units(&cat, moon, kit.fighter, 100));
        let mut raider = Raider::new(1);
        assert!(!raider.destroy_moon(&mut world, &cat, 0, 1, &mut Ledger::default()), "unseen ships do not trigger a strike");
        let ships = world.yards[moon].counts.clone();
        world.reports.push(SpyReport { owner: 0, target: moon, time: 0.0, resources: [0; 3], debris: None, counter_chance: 0, defence: Some(vec![0; ships.len()]), ships: Some(ships), buildings: None, research: Some(vec![0; cat.objects.len()]) });
        let mut stale = world.clone();
        stale.reports.last_mut().unwrap().time = -REPORT_HOURS * 3600.0;
        assert!(!Raider::new(1).destroy_moon(&mut stale, &cat, 0, 1, &mut Ledger::default()), "a stale report does not trigger a strike");
        let mut hidden_changed = world.clone();
        assert!(hidden_changed.set_units(&cat, moon, kit.fighter, 1_000_000));
        let (mut first, mut second) = (Raider::new(1), Raider::new(1));
        let original = first.destroy_moon(&mut world, &cat, 0, 1, &mut Ledger::default());
        let changed = second.destroy_moon(&mut hidden_changed, &cat, 0, 1, &mut Ledger::default());
        assert!(original && changed, "the same report gives the same decision despite different unseen fleets");
    }

    #[test]
    fn moon_strike_uses_observed_phalanx_buildings() {
        let (cat, mut world) = bare(5).expect("the player test catalogue");
        let destroyer = world.rules[0].strike.as_ref().expect("moon destruction rules").moon_destroyer;
        let gear = world.rules[1].settings.moon_gear.clone().expect("phalanx rules");
        let phalanx = cat.objects.iter().position(|o| o.id == gear.phalanx_object).expect("phalanx object");
        world.games[0].planet.res = [1e9; 3];
        let moon = world.add_moon(&cat, 1, 8000, 0.0);
        assert!(world.set_units(&cat, 0, destroyer, 20));
        let ships = vec![0; world.yards[moon].counts.len()];
        world.reports.push(SpyReport { owner: 0, target: moon, time: 0.0, resources: [0; 3], debris: None, counter_chance: 0, defence: Some(ships.clone()), ships: Some(ships), buildings: None, research: Some(vec![0; cat.objects.len()]) });
        world.games[moon].planet.levels[phalanx] = 5;
        assert!(!Raider::new(1).destroy_moon(&mut world, &cat, 0, 1, &mut Ledger::default()), "an unseen phalanx cannot justify a strike");
        world.games[moon].planet.levels[phalanx] = 0;
        let mut observed = vec![0; cat.objects.len()];
        observed[phalanx] = 5;
        world.reports.last_mut().unwrap().buildings = Some(observed);
        assert!(Raider::new(1).destroy_moon(&mut world, &cat, 0, 1, &mut Ledger::default()), "the reported in-range phalanx justifies a strike");
    }

    #[test]
    fn a_gate_escape_does_not_schedule_a_recall_of_an_unrelated_fleet() {
        let (cat, mut world) = bare(5).expect("the player test catalogue");
        let gear = world.rules[0].settings.moon_gear.clone().expect("jump gate rules");
        let gate = cat.objects.iter().position(|o| o.id == gear.gate_object).expect("a jump gate");
        let fighter = *gear.gate_ships.iter().find(|&&id| world.rules[0].units.index(id as i64).is_some_and(|u| world.rules[0].units.kind.get(u) == Some(&UnitKind::MilitaryShip))).expect("a gate-capable warship");
        world.games[0].sleep_start = 12.0;
        world.games[0].sleep_hours = 8.0;
        world.games[1].planet.res = [1e9; 3];
        let moon = world.add_moon(&cat, 0, 8000, 0.0);
        let colony = world.add_planet(&cat, 0, 0, Coord { galaxy: 1, system: 11, position: 6 }, 0.0);
        let other = world.add_moon(&cat, colony, 8000, 0.0);
        world.games[moon].planet.levels[gate] = 1;
        world.games[other].planet.levels[gate] = 1;
        assert!(world.set_units(&cat, moon, fighter, 100));
        assert!(world.set_units(&cat, 1, fighter, 1));
        world.dispatch(&cat, 1, moon, Mission::Attack, &[(fighter, 1)], [0.0; 3], 10.0).expect("the moon is attacked");
        let mut saver = FleetSaver::new(1);
        let mut ledger = Ledger::default();
        saver.act(&mut world, &cat, 0, &mut ledger);
        assert!(on_shelf(&world, other, fighter) > 0, "the fleet escaped through the gate");
        assert!(saver.saving.is_empty(), "a gate jump has no outbound fleet to recall");
        assert_eq!(ledger.saves_sent, 1);
    }

    /// Arm `late` world: account 0 holds a moon with a gate, a colony whose moon has a gate, warships on the first moon.
    fn late_world(late: bool) -> Option<(Catalogue, World, usize, usize, u32)> {
        let (cat, mut world) = bare(5)?;
        world.wide = true;
        world.late = late;
        let gear = world.rules[0].settings.moon_gear.clone()?;
        let gate = cat.objects.iter().position(|o| o.id == gear.gate_object)?;
        let fighter = *gear.gate_ships.iter().find(|&&id| world.rules[0].units.index(id as i64).is_some_and(|u| world.rules[0].units.kind.get(u) == Some(&UnitKind::MilitaryShip)))?;
        world.games[0].sleep_hours = 8.0;
        world.games[0].sleep_start = 12.0;
        for a in 0..ACCOUNTS { world.games[a].planet.res = [1e9; 3] }
        let moon = world.add_moon(&cat, 0, 8000, 0.0);
        let colony = world.add_planet(&cat, 0, 0, Coord { galaxy: 1, system: 11, position: 6 }, 0.0);
        let other = world.add_moon(&cat, colony, 8000, 0.0);
        world.games[moon].planet.levels[gate] = 1;
        world.games[other].planet.levels[gate] = 1;
        world.games[moon].planet.res = [1e9; 3];
        world.games[other].planet.res = [1e9; 3];
        assert!(world.set_units(&cat, moon, fighter, 100));
        Some((cat, world, moon, other, fighter))
    }

    /// CRASH-004, FS-001, FS-011: before the night the fleet on a gated moon jumps to the other moon and flies its save
    /// from there; under attack the gate save is taught. Without `late` neither night row exists.
    #[test]
    fn late_gate_saves_before_the_night_and_under_attack() {
        use crate::errands::{self, Kind, Menu, Plan};
        let (cat, mut world, moon, other, fighter) = late_world(true).expect("the late world");
        world.games[0].sleep_start = (world.games[0].t % 86_400.0) / 3600.0 + 0.5;
        // A short night, so the test's close neighbours are far enough for a save.
        world.games[0].sleep_hours = 1.0;
        let menu = Menu { raids: false, alive: vec![true; ACCOUNTS], shot_at: vec![false; ACCOUNTS], station: true };
        let mut off = world.clone();
        off.late = false;
        let offered = errands::candidates_in(&mut off, &cat, 0, &menu);
        assert!(!offered.iter().any(|e| e.plan == Plan::Night), "no night rows without the arm");
        // FS-001's exposure: nobody has probed or hit the account, so no night save is offered.
        let offered = errands::candidates_in(&mut world, &cat, 0, &menu);
        assert!(!offered.iter().any(|e| e.plan == Plan::Night), "no night save for a fleet nobody hunts");
        let now = world.games[0].t;
        world.reports.push(SpyReport { owner: 1, target: moon, time: now - 3600.0, resources: [0; 3], debris: None, counter_chance: 0, defence: None, ships: None, buildings: None, research: None });
        assert!(exposed(&world, 0));
        let offered = errands::candidates_in(&mut world, &cat, 0, &menu);
        let pick = errands::teacher_among(&world, &cat, 0, &menu, &offered);
        assert_eq!((pick.kind, pick.plan, pick.target), (Kind::GateSave, Plan::Night, Some(moon)), "{offered:?}");
        errands::apply_in(&mut world, &cat, 0, pick, &menu).expect("the gate night save");
        assert_eq!(on_shelf(&world, moon, fighter) + on_shelf(&world, other, fighter), 0, "jumped and flown on");
        assert!(world.fleets.iter().any(|f| f.owner == other && f.mission == Mission::Transport), "the save leaves from the other moon");

        let (cat, mut world, moon, _, fighter) = late_world(true).expect("the late world");
        assert!(world.set_units(&cat, 1, fighter, 1));
        world.dispatch(&cat, 1, moon, Mission::Attack, &[(fighter, 1)], [0.0; 3], 10.0).expect("the moon is attacked");
        let offered = errands::candidates_in(&mut world, &cat, 0, &menu);
        let pick = errands::teacher_among(&world, &cat, 0, &menu, &offered);
        assert_eq!((pick.kind, pick.plan), (Kind::GateSave, Plan::Base));
    }

    /// FS-002 and FS-009: a night save splits into two waves over two destinations and its first route varies by day.
    #[test]
    fn a_night_save_splits_and_varies_its_landing() {
        let (cat, mut world, _, _, fighter) = late_world(true).expect("the late world");
        assert!(world.set_units(&cat, 0, fighter, 100));
        world.games[0].sleep_hours = 1.0;
        let mut firsts = HashSet::default();
        for day in 0..6 {
            world.games[0].t = day as f64 * 86_400.0 + 11.5 * 3600.0;
            assert!(night_due(&world, 0));
            let waves = night_plan(&world, &cat, 0, 0).expect("a night save");
            assert_eq!(waves.len(), 2, "two waves");
            assert_ne!(waves[0].0, waves[1].0, "two destinations");
            assert!(waves.iter().all(|w| w.1.iter().all(|s| s.1 == 50)));
            firsts.insert((waves[0].0, waves[0].2.to_bits()));
        }
        assert!(firsts.len() > 1, "the landing varies by day: {firsts:?}");
        world.late = false;
        assert!(!night_due(&world, 0), "no night save without the arm");
    }

    /// Deathstar rules 3 and 6 (plan 5 Nov), WIK-187/196: with `late` the moon strike flies the destroyers alone and the
    /// teacher sends it when the host's destroy odds beat the loss roll; without it every warship rides along.
    #[test]
    fn late_moon_destruction_flies_a_pure_stack_on_the_odds() {
        use crate::errands::{self, Kind, Menu};
        let (cat, mut world) = bare(5).expect("the player test catalogue");
        world.wide = true;
        let kit = Kit::of(&world, &cat, 0).expect("a fighter");
        let destroyer = world.rules[0].strike.as_ref().expect("moon destruction rules").moon_destroyer;
        world.games[0].planet.res = [1e9; 3];
        let moon = world.add_moon(&cat, 1, 3000, 0.0);
        assert!(world.set_units(&cat, 0, destroyer, 20));
        assert!(world.set_units(&cat, 0, kit.fighter, 100));
        assert!(world.set_units(&cat, moon, kit.fighter, 100));
        for target in [1, moon] {
            let (ships, defence) = (world.yards[target].counts.clone(), vec![0; world.yards[target].counts.len()]);
            world.reports.push(SpyReport { owner: 0, target, time: 0.0, resources: [0; 3], debris: None, counter_chance: 0, defence: Some(defence), ships: Some(ships), buildings: None, research: Some(vec![0; cat.objects.len()]) });
        }
        let mixed = moon_strike_fleet(&world, &cat, 0, 1).expect("a strike");
        assert!(mixed.iter().any(|s| s.0 != destroyer), "without the arm the fleet rides along");
        world.late = true;
        assert_eq!(moon_strike_fleet(&world, &cat, 0, 1), Some(vec![(destroyer, 20)]), "a pure stack");
        let odds = moon_odds(&world, 0, 1).expect("odds");
        assert!(odds.destroy >= odds.loss);
        let menu = errands::Menu { raids: true, ..Menu::default() };
        let offered = errands::candidates_in(&mut world, &cat, 0, &menu);
        let pick = errands::teacher_among(&world, &cat, 0, &menu, &offered);
        assert_eq!((pick.kind, pick.target), (Kind::MoonDestroy, Some(1)), "{offered:?}");
    }

    /// NIN-001/002: the fleet on the capital's moon lands on the capital within the window before a raid it destroys
    /// together with the planet; the raider's live research does not move the plan (only its report would).
    #[test]
    fn a_ninja_fleet_lands_before_the_raid_and_wins() {
        let (cat, mut world, moon, _, fighter) = late_world(true).expect("the late world");
        set_espionage(&mut world, &cat, 0, FULL_FLEET_INTEL);
        assert!(world.set_units(&cat, moon, fighter, 1000));
        assert!(world.set_units(&cat, 1, fighter, 5));
        world.dispatch(&cat, 1, 0, Mission::Attack, &[(fighter, 5)], [0.0; 3], 10.0).expect("the raid");
        let lands = world.fleets[0].arrive;
        assert!(ninja_plan(&world, &cat, 0).is_none(), "far from the raid, nothing to time yet");
        let research = world.research(&cat, 0);
        let distance = fleet::distance(&world.rules[moon].settings, world.coords[moon], world.coords[0], &|_, _| SystemState::Active);
        let flight = fleet::flight_seconds(&world.rules[moon].settings, Mission::Deploy as u32, distance, world.rules[moon].fleet.slowest_speed(&[(fighter, 1)], &research), 10.0) as f64;
        world.games[0].t = lands - flight - 60.0;
        let plan = ninja_plan(&world, &cat, 0).expect("the ninja landing");
        let mut hidden = world.clone();
        for (k, o) in cat.objects.iter().enumerate() { if o.queue == 1 { hidden.games[1].planet.levels[k] = 20 } }
        assert_eq!(ninja_plan(&hidden, &cat, 0).map(|p| p.2), Some(plan.2), "the raider's unreported research is not read");
        world.dispatch(&cat, moon, 0, Mission::Deploy, &plan.1, [0.0; 3], plan.2).expect("the ninja leaves");
        assert!(world.fleets.last().unwrap().arrive < lands, "it lands before the raid");
    }

    #[test]
    fn allied_fleet_defends_a_moon_from_destruction() {
        let (cat, mut world) = bare(5).expect("the player test catalogue");
        let destroyer = world.rules[0].strike.as_ref().expect("moon destruction rules").moon_destroyer;
        world.coords[2] = Coord { galaxy: 1, system: world.coords[1].system, position: 15 };
        for a in [0, 1, 2] { world.games[a].planet.res = [1e9; 3] }
        world.alliance_at[1] = Some(0.0);
        world.alliance_at[2] = Some(0.0);
        let moon = world.add_moon(&cat, 1, 8000, 0.0);
        assert!(world.set_units(&cat, 0, destroyer, 1));
        assert!(world.set_units(&cat, 2, destroyer, 20));
        world.dispatch(&cat, 0, 1, Mission::MoonDestroy, &[(destroyer, 1)], [0.0; 3], 10.0).expect("the moon destruction flies");
        let mut ledger = Ledger::default();
        world.alliance_id[2] = 1;
        assert!(!defend_mate(&mut world, &cat, 2, |_| true, &mut ledger), "another alliance does not defend");
        world.alliance_id[2] = 0;
        set_espionage(&mut world, &cat, 1, FULL_FLEET_INTEL);
        assert!(defend_mate(&mut world, &cat, 2, |_| true, &mut ledger), "the ally decides to defend the moon");
        assert_eq!(ledger.defends, 1);
        let hold = world.fleets.iter().find(|f| f.mission == Mission::AcsDefend).unwrap();
        let strike = world.fleets.iter().find(|f| f.mission == Mission::MoonDestroy).unwrap();
        assert!(hold.arrive <= strike.arrive && strike.arrive < hold.arrive + hold.holding);
        for _ in 0..20_000 {
            if world.events.iter().any(|e| e.kind == EventKind::Fight && e.mission == Mission::MoonDestroy) { break }
            let slot = world.next_decision(&cat).expect("the world plays on");
            world.wait(&cat, slot);
        }
        let fight = world.events.iter().find(|e| e.kind == EventKind::Fight && e.mission == Mission::MoonDestroy).expect("the fleets fought");
        let survivors = world.fleets.iter().find(|f| f.owner == 2).expect("the ally survived this battle");
        assert!(survivors.returning && !survivors.ships.is_empty(), "the actual surviving fleet returns");
        assert!(matches!(fight.outcome, Outcome::Defeated | Outcome::Draw), "a surviving defender cannot lose");
        assert!(world.moons[1], "a surviving defender prevents moon destruction");
        assert!(world.yards[moon].counts.iter().all(|&n| n == 0), "allied survivors are not copied onto the moon");
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
