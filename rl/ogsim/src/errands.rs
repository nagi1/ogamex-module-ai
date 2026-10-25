//! The errand choice (`plan/rl/errand-spec.md`): at a login an account decides what to do with its fleet — spy
//! someone, raid a target it has a report on, send recyclers to a field, save the fleet from an incoming attack, bring
//! a save home, order ships, or nothing.
//!
//! The candidates are offered legal by construction: each is exactly what the scripted players' own rule (`players.rs`)
//! would send, so the model decides *whether and at whom*, never the fleet arithmetic. That also makes the teacher the
//! scripted players themselves, which is what behaviour cloning needs. Features are derived only, no object is named
//! (gate 1): roles come from the host's tables through `Kit`.

use crate::economy::{Catalogue, WEIGHTS};
use crate::fleet;
use crate::players::{self, harvest_plan, last_report, nearest, on_shelf, raid_plan, save_plan, stack, Kit, Miner, Raider};
use crate::world::{Decision, Event, Mission, World};

/// The errand kinds, in the order their one-hot columns are written.
pub const KIND_NAMES: [&str; 7] = ["spy", "raid", "recycle", "save", "recall", "order", "wait"];

/// The account's own state, one column each (`account_features`).
pub const STATE_NAMES: [&str; 12] = [
    "stock", "stock_ratio", "probe", "cargo", "fighter", "harvester", "hull_home",
    "hour_sin", "hour_cos", "hours_since_attack", "incoming_hours", "idle",
];

/// What one candidate adds: the account's columns, then these.
pub const CAND_NAMES: [&str; 12] = [
    "distance", "flight_hours", "fuel_over_stock", "expected_loot", "loot_over_fuel",
    "target_ratio", "report_age_hours", "target_inactive", "field", "field_over_capacity",
    "target_stock", "attack_in_hours",
];

pub const STATE_DIM: usize = STATE_NAMES.len();
pub const CAND_DIM: usize = CAND_NAMES.len() + KIND_NAMES.len();

#[derive(Clone, Copy, Debug, PartialEq, Eq, Hash)]
pub enum Kind {
    Spy,
    Raid,
    Recycle,
    Save,
    Recall,
    Order,
    Wait,
}

impl Kind {
    fn column(self) -> usize {
        match self {
            Kind::Spy => 0,
            Kind::Raid => 1,
            Kind::Recycle => 2,
            Kind::Save => 3,
            Kind::Recall => 4,
            Kind::Order => 5,
            Kind::Wait => 6,
        }
    }
}

/// One thing a login may do. A fleet errand names the slot it flies at; an order names the unit it builds.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub struct Errand {
    pub kind: Kind,
    pub target: Option<usize>,
    /// The catalogue index of the unit an order builds.
    pub unit: Option<usize>,
}

impl Errand {
    fn at(kind: Kind, target: usize) -> Errand {
        Errand { kind, target: Some(target), unit: None }
    }
}

/// The teacher is the scripted raider's own economy rule, so an `order` candidate is the amount it would order.
fn teacher() -> Miner {
    Raider::new(0).miner
}

/// The attack flying at slot `a` that has not landed yet, as the second it lands.
fn incoming(w: &World, a: usize) -> Option<f64> {
    let now = w.games[a].t;
    w.fleets.iter().filter(|f| f.target == a && f.mission == Mission::Attack && !f.returning && f.arrive > now).map(|f| f.arrive).min_by(f64::total_cmp)
}

fn outbound(w: &World, a: usize) -> bool {
    w.fleets.iter().any(|f| f.owner == a && !f.returning)
}

/// The last attack that landed on slot `a`, from the world's event log.
fn last_attack(w: &World, a: usize) -> Option<&Event> {
    w.events.iter().rev().find(|e| e.mission == Mission::Attack && e.target == a)
}

/// Every errand this login may choose, the last one always `wait`.
/// The most candidates one login is offered: the width the trainers and the PHP encoder agree on (`MAX_ROWS = 32`).
pub const MAX_CANDIDATES: usize = 32;

/// The teacher's priority: the first of these a login can take is what the scripted mix would do, and therefore the
/// label behaviour cloning learns. `wait` is last, so a login with nothing to do is labelled `wait`.
pub const TEACHER_ORDER: [Kind; 7] = [Kind::Save, Kind::Recall, Kind::Recycle, Kind::Raid, Kind::Spy, Kind::Order, Kind::Wait];

/// Every errand this login may choose, `wait` first (row 0: the exporters, the metrics and the PHP encoder assume it).
pub fn candidates(w: &mut World, cat: &Catalogue, a: usize) -> Vec<Errand> {
    let mut out = vec![Errand { kind: Kind::Wait, target: None, unit: None }];
    if w.account_of(a) == a {
        if let Some(kit) = Kit::of(w, cat, a) {
            let now = w.games[a].t;
            for target in nearest(w, cat, a, players::NEIGHBOURS) {
                let fresh = last_report(w, a, target).is_some_and(|r| now - r.time < players::REPORT_HOURS * 3600.0);
                if !fresh && on_shelf(w, a, kit.probe) >= 1 { out.push(Errand::at(Kind::Spy, target)) }
                if fresh && raid_plan(w, cat, a, target, kit).is_some() { out.push(Errand::at(Kind::Raid, target)) }
                if harvest_plan(w, cat, a, target).is_some() { out.push(Errand::at(Kind::Recycle, target)) }
            }
        }
        if matches!(w.decision_kind(a), Decision::Idle) {
            for (unit, _) in teacher().order_plans(w, cat, a) {
                out.push(Errand { kind: Kind::Order, target: None, unit: Some(unit) });
            }
        }
        if incoming(w, a).is_some_and(|lands| save_plan(w, cat, a, lands - w.games[a].t).is_some()) {
            out.push(Errand::at(Kind::Save, a));
        }
        if incoming(w, a).is_none() && outbound(w, a) {
            out.push(Errand::at(Kind::Recall, a));
        }
    }
    out.truncate(MAX_CANDIDATES);
    out
}

/// The errand the scripted mix would take here (the behaviour-cloning label): the first candidate in
/// `TEACHER_ORDER`. `wait` when the login has nothing better to do.
pub fn teacher_choice(w: &mut World, cat: &Catalogue, a: usize) -> Errand {
    let offered = candidates(w, cat, a);
    TEACHER_ORDER.iter().find_map(|&kind| offered.iter().copied().find(|e| e.kind == kind)).unwrap_or(Errand { kind: Kind::Wait, target: None, unit: None })
}

/// What the errand does to the world, through the world's own calls, so a refusal is the host's refusal.
pub fn apply(w: &mut World, cat: &Catalogue, a: usize, errand: Errand) -> Result<(), &'static str> {
    let target = errand.target.unwrap_or(a);
    let kit = Kit::of(w, cat, a);
    match errand.kind {
        Kind::Spy => {
            let kit = kit.ok_or("no ships for this account")?;
            let probes = on_shelf(w, a, kit.probe).min(players::SPY_PROBES);
            w.dispatch(cat, a, target, Mission::Espionage, &[(kit.probe, probes)], [0.0; 3], 10.0)
        }
        Kind::Raid => {
            let kit = kit.ok_or("no ships for this account")?;
            let plan = raid_plan(w, cat, a, target, kit).ok_or("no raid to send")?;
            w.dispatch(cat, a, target, Mission::Attack, &plan.fleet, [0.0; 3], 10.0)
        }
        Kind::Recycle => {
            let (harvester, n) = harvest_plan(w, cat, a, target).ok_or("nothing to harvest")?;
            w.dispatch(cat, a, target, Mission::Recycle, &[(harvester, n)], [0.0; 3], 10.0)
        }
        Kind::Save => {
            let lands = incoming(w, a).ok_or("nothing to save from")?;
            let (away, ships, speed) = save_plan(w, cat, a, lands - w.games[a].t).ok_or("nowhere far enough")?;
            w.dispatch(cat, a, away, Mission::Transport, &ships, [0.0; 3], speed)
        }
        Kind::Recall => {
            let f = w.fleets.iter().position(|f| f.owner == a && !f.returning).ok_or("no fleet out")?;
            w.recall(a, f)
        }
        Kind::Order => {
            let unit = errand.unit.ok_or("no unit to order")?;
            let amount = teacher().order_plans(w, cat, a).into_iter().find(|&(u, _)| u == unit).map_or(0, |(_, n)| n);
            if amount == 0 { return Err("nothing to order") }
            w.order_units(cat, a, unit, amount as u64);
            Ok(())
        }
        Kind::Wait => Ok(()),
    }
}

fn ln1p(value: f64) -> f32 {
    value.max(0.0).ln_1p() as f32
}

/// A share in 0..1, kept finite when the denominator is zero.
fn ratio(value: f64, whole: f64) -> f32 {
    if whole > 0.0 { (value / whole).clamp(0.0, 1.0) as f32 } else { 0.0 }
}

/// The account's own columns, `STATE_NAMES` order.
pub fn account_features(w: &World, cat: &Catalogue, a: usize) -> [f32; STATE_DIM] {
    let mut f = [0.0; STATE_DIM];
    let Some(kit) = Kit::of(w, cat, a) else { return f };
    let rules = &w.rules[a];
    let research = w.research(cat, a);
    let planet = &w.games[a].planet;
    let stock = players::weighted(planet.res);
    f[0] = ln1p(stock);
    f[1] = ratio(stock, planet.cap.iter().sum::<f64>());
    for (i, id) in [kit.probe, kit.cargo, kit.fighter, kit.harvester].into_iter().enumerate() {
        f[2 + i] = ln1p(on_shelf(w, a, id) as f64);
    }
    f[6] = ln1p(players::strength(rules, &research, &stack(rules, &w.yards[a].counts)).1);
    let hour = (w.games[a].t % 86_400.0) / 86_400.0 * std::f64::consts::TAU;
    f[7] = hour.sin() as f32;
    f[8] = hour.cos() as f32;
    f[9] = ln1p(last_attack(w, a).map_or(0.0, |e| (w.games[a].t - e.time).max(0.0) / 3600.0));
    f[10] = incoming(w, a).map_or(0.0, |lands| ((lands - w.games[a].t).max(0.0) / 3600.0) as f32);
    f[11] = f32::from(matches!(w.decision_kind(a), Decision::Idle));
    f
}

/// What this candidate adds, `CAND_NAMES` then one column per kind, `KIND_NAMES` order.
pub fn candidate_features(w: &mut World, cat: &Catalogue, a: usize, errand: Errand) -> [f32; CAND_DIM] {
    let mut f = [0.0; CAND_DIM];
    f[CAND_NAMES.len() + errand.kind.column()] = 1.0;
    let now = w.games[a].t;
    let target = errand.target.unwrap_or(a);
    let inactive = w.inactive(cat, target, now);
    let rules = &w.rules[a];
    let research = w.research(cat, a);
    let distance = players::account_distance(w, a, target);
    let mission = match errand.kind {
        Kind::Spy => Mission::Espionage,
        Kind::Raid => Mission::Attack,
        Kind::Recycle => Mission::Recycle,
        Kind::Save | Kind::Recall => Mission::Transport,
        Kind::Order | Kind::Wait => return f,
    };
    f[0] = (distance as f32) / 100.0;
    let kit = Kit::of(w, cat, a);
    let plan = kit.and_then(|kit| match errand.kind {
        Kind::Raid => raid_plan(w, cat, a, target, kit).map(|p| (p.fleet, p.expected)),
        Kind::Spy => Some((vec![(kit.probe, 1)], [0.0; 3])),
        Kind::Recycle => harvest_plan(w, cat, a, target).map(|(h, n)| (vec![(h, n)], [0.0; 3])),
        Kind::Save => save_plan(w, cat, a, incoming(w, a).map_or(0.0, |l| l - now)).map(|(_, s, _)| (s, [0.0; 3])),
        _ => None,
    });
    let Some((ships, expected)) = plan else { return f };
    let flight = fleet::flight_seconds(&rules.settings, mission as u32, distance, rules.fleet.slowest_speed(&ships, &research), 10.0) as f64;
    let fuel = fleet::consumption(&rules.settings, &rules.fleet, &ships, &research, distance, 0, 10.0) as f64;
    let stock = w.games[a].planet.res[2].max(1.0);
    f[1] = (flight / 3600.0) as f32;
    f[2] = (fuel / stock) as f32;
    f[3] = ln1p(players::weighted(expected));
    f[4] = if fuel > 0.0 { (players::weighted(expected) / (WEIGHTS[2] * fuel)) as f32 } else { 0.0 };
    if let Some(report) = last_report(w, a, target) {
        let guns = report.ships.as_deref().map_or(Vec::new(), |s| stack(&w.rules[target], s));
        let (attack, _) = players::strength(&w.rules[target], &w.research(cat, target), &guns);
        let hull = players::strength(rules, &research, &stack(rules, &w.yards[a].counts)).1.max(1.0);
        f[5] = ((attack * 3.0 / hull).max(1e-9)).ln() as f32;
        f[6] = ((now - report.time).max(0.0) / 3600.0) as f32;
        f[10] = ln1p(players::weighted(report.resources.map(|r| r.max(0) as f64)));
    }
    f[7] = f32::from(inactive);
    if let Some((harvester, n)) = harvest_plan(w, cat, a, target) {
        let hold = rules.combat.capacity(&[(harvester, 1)].into_iter().collect(), &research).max(1) * n;
        let field = players::weighted(w.debris[target].map(|r| r as f64));
        f[8] = ln1p(field);
        f[9] = ratio(field, hold as f64);
    }
    if errand.kind == Kind::Save {
        f[11] = incoming(w, a).map_or(0.0, |lands| ((lands - now).max(0.0) / 3600.0) as f32);
    }
    f
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::players::{FleetSaver, Ledger, Player};
    use crate::world::{EventKind, Rules};
    use std::collections::HashSet;
    use std::sync::Arc;

    const DATA: &str = "../../../../storage/rl/ogsim";
    const ACCOUNTS: usize = 12;

    /// The scripted world of the players' own test: raiders, savers and miners on one clock.
    fn world(seed: u64) -> Option<(Catalogue, World)> {
        let mut cat = Catalogue::load(&format!("{DATA}/units-parity/econ-u1.json")).ok()?;
        cat.login_gaps = vec![600.0, 1800.0, 3600.0, 7200.0, 21600.0];
        let rules = Arc::new(Rules::load(&cat, &format!("{DATA}/units-parity/units-u1.json"), &format!("{DATA}/fleet-settings-u1.json"), &format!("{DATA}/combat-u1.json")).ok()?);
        let configs: Vec<usize> = (0..ACCOUNTS).collect();
        let mut w = World::new(&cat, &configs, 240.0, 0.0, seed);
        w.enable_idle_logins(&cat);
        let coords = (0..ACCOUNTS).map(|a| crate::fleet::Coord { galaxy: 1, system: 10 + 2 * a as i64, position: 4 + (a % 8) as i64 }).collect();
        w.arm(vec![Arc::clone(&rules); ACCOUNTS], coords);
        for a in 0..ACCOUNTS {
            let kit = Kit::of(&w, &cat, a)?;
            let (cargo, probes, harvesters, fighters) = match a % 3 { 0 => (20, 6, 4, 80), 1 => (6, 2, 0, 0), _ => (3, 1, 0, 0) };
            for (id, n) in [(kit.cargo, cargo), (kit.probe, probes), (kit.harvester, harvesters), (kit.fighter, fighters)] {
                let u = w.rules[a].units.index(id as i64)?;
                w.yards[a].counts[u] = n;
            }
            w.games[a].planet.res[2] += 30_000.0;
        }
        Some((cat, w))
    }

    /// Plays the scripted mix for a day of world time, letting `choose` act at every login before the scripted player
    /// does, and hands back the world it ends in.
    fn drive(seed: u64, mut choose: impl FnMut(&mut World, &Catalogue, usize, &[Errand])) -> Option<(Catalogue, World)> {
        let (cat, mut w) = world(seed)?;
        let mut players: Vec<Player> = (0..ACCOUNTS).map(|a| {
            let s = 100 + a as u64;
            match a % 3 {
                0 => Player::Raider(Raider::new(s)),
                1 => Player::Saver(FleetSaver::new(s)),
                _ => Player::Miner(Miner::new(s)),
            }
        }).collect();
        let mut ledger = Ledger::default();
        while let Some(a) = w.next_decision(&cat) {
            let offered = candidates(&mut w, &cat, a);
            choose(&mut w, &cat, a, &offered);
            match players[a].act(&mut w, &cat, a, &mut ledger) {
                Some(k) => w.commit(&cat, a, k),
                None => w.wait(&cat, a),
            }
        }
        Some((cat, w))
    }

    /// Every candidate the world offers is one the world accepts: a mask that offered a refusal would train the model on
    /// actions the game rejects.
    #[test]
    fn every_offered_errand_is_one_the_world_accepts() {
        let (mut accepted, mut kinds) = (0usize, HashSet::new());
        for seed in 1..=4u64 {
            let Some(_) = drive(seed, |w, cat, a, offered| {
                assert_eq!(offered.first().map(|e| e.kind), Some(Kind::Wait), "wait is row 0, as the exporters read it");
                let fleet = offered.iter().copied().filter(|e| e.kind != Kind::Wait).count();
                if fleet == 0 { return }
                // One errand a login, in rotation, so one login does not empty the shelf the next candidate needs.
                let pick = offered[accepted % offered.len()];
                apply(w, cat, a, pick).unwrap_or_else(|e| panic!("seed {seed}: the world offered {pick:?} and refused it: {e}"));
                kinds.insert(pick.kind);
                accepted += 1;
            }) else { continue };
        }
        assert!(accepted > 50, "only {accepted} errands were exercised");
        for kind in [Kind::Spy, Kind::Raid, Kind::Order] {
            assert!(kinds.contains(&kind), "{kind:?} was never offered: {kinds:?}");
        }
    }

    /// A raid errand is the raider's own raid: the fleet reaches the target and comes home with loot.
    #[test]
    fn a_raid_errand_brings_loot_home() {
        let mut raids = 0;
        for seed in 1..=4u64 {
            let Some((_, w)) = drive(seed, |w, cat, a, offered| {
                let Some(raid) = offered.iter().copied().find(|e| e.kind == Kind::Raid) else { return };
                apply(w, cat, a, raid).expect("the raid the world offered");
                raids += 1;
            }) else { continue };
            let looted: f64 = w.events.iter()
                .filter(|e| e.kind == EventKind::Fight && e.mission == Mission::Attack)
                .map(|e| players::weighted(e.gained))
                .sum();
            if raids > 0 { assert!(looted > 0.0, "seed {seed}: raids were sent and nothing came home"); return }
        }
        assert!(raids > 0, "no seed ever offered a raid");
    }
}
