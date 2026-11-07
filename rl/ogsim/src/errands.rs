//! The errand choice (`plan/rl/errand-spec.md`): at a login an account decides what to do with its fleet — spy
//! someone, raid a target it has a report on, send recyclers to a field, save the fleet from an incoming attack, bring
//! a save home, order ships, or nothing.
//!
//! The candidates are offered legal by construction: each is exactly what the scripted players' own rule (`players.rs`)
//! would send, so the model decides *whether and at whom*, never the fleet arithmetic. That also makes the teacher the
//! scripted players themselves, which is what behaviour cloning needs. Features are derived only, no object is named
//! (gate 1): roles come from the host's tables through `Kit`.
//!
//! Arm `wide` (`World::wide`, plan/rl/STRONG-PLAYER-PLAN.md "5 Nov") widens the menu to every fleet capability: raid
//! fleet variants (the war fleet sized from the report, at full or half speed), one order row per buildable unit, and
//! the scripted rules the season otherwise plays on its own (missile strike, moon destruction, moon shot, ACS join and
//! defend, expeditions, deploy to the moon, gate save, phalanx-timed raid). Its columns are appended after today's, so
//! a narrow export is a prefix of a wide one by name.

use crate::economy::{Catalogue, WEIGHTS};
use crate::fleet::{self, Coord};
use crate::players::{self, harvest_plan, last_report, nearest, on_shelf, raid_plan, save_plan, stack, Kit, Miner, Raider, RaidPlan};
use crate::units::UnitKind;
use crate::world::{Decision, Mission, World};

/// Version 2 replaces hidden enemy technology in the threat feature with observed or estimated levels.
pub const FEATURE_VERSION: u64 = 2;
/// Version 3 is the wide menu (arm `wide`): more kinds, rows and columns, all appended.
pub const WIDE_FEATURE_VERSION: u64 = 3;

/// The errand kinds, in the order their one-hot columns are written; the first `NARROW_KINDS` are the narrow menu's.
pub const KIND_NAMES: [&str; 16] = [
    "spy", "raid", "recycle", "save", "recall", "order", "wait",
    "strike", "moon_destroy", "moon_shot", "acs_join", "acs_defend", "expedition", "deploy", "gate_save", "phalanx",
];
pub const NARROW_KINDS: usize = 7;

/// The account's own state, one column each (`account_features`).
pub const STATE_NAMES: [&str; 12] = [
    "stock", "stock_ratio", "probe", "cargo", "fighter", "harvester", "hull_home",
    "hour_sin", "hour_cos", "hours_since_attack", "incoming_hours", "idle",
];
/// The wide menu's own state columns, after `STATE_NAMES`.
pub const WIDE_STATE_NAMES: [&str; 5] = ["warship_value", "has_moon", "missiles", "expedition_free", "fleet_slots_free"];

/// What one candidate adds: the account's columns, then these.
pub const CAND_NAMES: [&str; 13] = [
    "distance", "flight_hours", "fuel_over_stock", "expected_loot", "loot_over_fuel",
    "target_ratio_observed_or_estimated", "report_age_hours", "target_inactive", "field", "field_over_capacity",
    "target_stock", "attack_in_hours", "order_share",
];
/// The wide menu's candidate columns, written after the narrow kinds' one-hot and before the wide kinds' one-hot.
pub const WIDE_CAND_NAMES: [&str; 18] = [
    "fleet_value", "odds", "predicted_loss", "loss_share", "predicted_win", "speed", "doubler", "escort", "expedition_free",
    "unit_attack_per_price", "unit_hull_per_price", "unit_shield_per_price", "unit_cargo_per_price", "unit_speed",
    "unit_military", "unit_civil", "unit_defence", "order_over_stock",
];

pub const STATE_DIM: usize = STATE_NAMES.len();
pub const CAND_DIM: usize = CAND_NAMES.len() + NARROW_KINDS;
pub const WIDE_STATE_DIM: usize = STATE_DIM + WIDE_STATE_NAMES.len();
pub const WIDE_CAND_DIM: usize = CAND_DIM + WIDE_CAND_NAMES.len() + KIND_NAMES.len() - NARROW_KINDS;

/// The most candidates one login is offered: the width the trainers and the PHP encoder agree on (`MAX_ROWS = 32`).
pub const MAX_CANDIDATES: usize = 32;
pub const WIDE_MAX_CANDIDATES: usize = 64;

pub fn feature_version(wide: bool) -> u64 { if wide { WIDE_FEATURE_VERSION } else { FEATURE_VERSION } }
pub fn state_dim(wide: bool) -> usize { if wide { WIDE_STATE_DIM } else { STATE_DIM } }
pub fn cand_dim(wide: bool) -> usize { if wide { WIDE_CAND_DIM } else { CAND_DIM } }
pub fn max_candidates(wide: bool) -> usize { if wide { WIDE_MAX_CANDIDATES } else { MAX_CANDIDATES } }

/// The state columns in order.
pub fn state_names(wide: bool) -> Vec<&'static str> {
    STATE_NAMES.iter().chain(WIDE_STATE_NAMES.iter().take(if wide { WIDE_STATE_NAMES.len() } else { 0 })).copied().collect()
}

/// Every candidate column in order: `CAND_NAMES`, the narrow kinds, then (wide) `WIDE_CAND_NAMES` and the wide kinds.
pub fn cand_columns(wide: bool) -> Vec<&'static str> {
    let mut names: Vec<&str> = CAND_NAMES.iter().chain(&KIND_NAMES[..NARROW_KINDS]).copied().collect();
    if wide { names.extend(WIDE_CAND_NAMES.iter().chain(&KIND_NAMES[NARROW_KINDS..])) }
    names
}

#[derive(Clone, Copy, Debug, PartialEq, Eq, Hash)]
pub enum Kind {
    Spy,
    Raid,
    Recycle,
    Save,
    Recall,
    Order,
    Wait,
    Strike,
    MoonDestroy,
    MoonShot,
    AcsJoin,
    AcsDefend,
    Expedition,
    Deploy,
    GateSave,
    Phalanx,
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
            Kind::Strike => 7,
            Kind::MoonDestroy => 8,
            Kind::MoonShot => 9,
            Kind::AcsJoin => 10,
            Kind::AcsDefend => 11,
            Kind::Expedition => 12,
            Kind::Deploy => 13,
            Kind::GateSave => 14,
            Kind::Phalanx => 15,
        }
    }

    /// Where the kind's one-hot sits in a candidate row: the narrow kinds after `CAND_NAMES`, the wide ones last.
    fn one_hot(self) -> usize {
        let c = self.column();
        if c < NARROW_KINDS { CAND_NAMES.len() + c } else { CAND_DIM + WIDE_CAND_NAMES.len() + c - NARROW_KINDS }
    }
}

/// How much of the missing count an order asks for.
#[derive(Clone, Copy, Debug, PartialEq, Eq, Hash)]
pub enum Size {
    /// The whole want, which is what the scripted rule orders and often more than the stock pays for.
    Want,
    /// Only what the stock pays for now, which is what a player orders when resources are short.
    Now,
    /// A tenth of what the stock pays for (at least one): a batch, the stock kept for the next login.
    Batch,
}

/// Which of a kind's fleets: the narrow menu has one per kind (`Base`); the wide menu adds variants.
#[derive(Clone, Copy, Debug, PartialEq, Eq, Hash, Default)]
pub enum Plan {
    #[default]
    Base,
    /// A raid with the war fleet (`players::war_plan`), at full or half speed.
    War,
    WarSlow,
    /// Expedition fleets: cargo only, with the find doubler, and with the doubler and one strongest hull riding along.
    Cargo,
    Doubler,
    Escort,
    /// An order of any buildable unit, not the scripted rule's want.
    Hull,
    /// Arm `late`: a raid by the destroyers alone against a defence wall (`players::breaker_plan`).
    Breaker,
    /// Arm `late`: a save before the night (`players::night_plan`); a gate save that flies on from the other moon.
    Night,
    /// Arm `late`: a save from an attack as a deployment to another own planet, recalled after (`players::hidden_save`).
    Hidden,
    /// Arm `late`: the moon's fleet lands on the capital on the attack's second (`players::ninja_plan`).
    Ninja,
}

impl Plan {
    /// The host's speed setting a fleet of this plan flies at (10 is 100%).
    fn speed(self) -> f64 { if self == Plan::WarSlow { 5.0 } else { 10.0 } }
}

/// One thing a login may do. A fleet errand names the slot it flies at; an order names the unit it builds and how much
/// of it to ask for.
#[derive(Clone, Copy, Debug, PartialEq, Eq, Hash)]
pub struct Errand {
    pub kind: Kind,
    pub target: Option<usize>,
    /// The catalogue index of the unit an order builds.
    pub unit: Option<usize>,
    pub size: Size,
    pub plan: Plan,
}

impl Errand {
    fn at(kind: Kind, target: usize) -> Errand {
        Errand { kind, target: Some(target), unit: None, size: Size::Want, plan: Plan::Base }
    }

    fn with(kind: Kind, target: usize, plan: Plan) -> Errand {
        Errand { plan, ..Errand::at(kind, target) }
    }
}

const WAIT: Errand = Errand { kind: Kind::Wait, target: None, unit: None, size: Size::Want, plan: Plan::Base };

/// What the season knows beside the world that the wide menu reads: who the account raids, which accounts still play
/// (an ACS mate), which planets receive a moon shot from it, and whether it keeps its fleet on its moon.
#[derive(Clone, Default)]
pub struct Menu {
    pub raids: bool,
    pub alive: Vec<bool>,
    pub shot_at: Vec<bool>,
    pub station: bool,
}

impl Menu {
    pub fn narrow(raids: bool) -> Menu {
        Menu { raids, ..Menu::default() }
    }

    fn plays(&self, b: usize) -> bool { self.alive.get(b).copied().unwrap_or(true) }
    fn shoots_at(&self, b: usize) -> bool { self.shot_at.get(b).copied().unwrap_or(false) }
}

/// The most of units-index `unit` this login could actually order: the host's own cap (`max_amount`: requirements, owned
/// limit, storage), the yard's own table, and what the stock pays for, up to the missing count. The host charges the
/// whole order or nothing, so anything above this is refused and the login is spent on nothing.
fn orderable(w: &World, cat: &Catalogue, a: usize, unit: usize, missing: i64) -> i64 {
    let rules = &w.rules[a];
    let Some(u) = rules.units.units.get(unit) else { return 0 };
    let upgrading = w.games[a].pending.iter().find(|&&(_, k)| cat.objects[k].queue == 0).map(|&(_, k)| k);
    if !w.yards[a].allowed.get(unit).copied().unwrap_or(false) || upgrading.is_some_and(|k| rules.units.blocked.contains(&k)) {
        return 0;
    }
    let cap = rules.units.max_amount(&w.yards[a], &w.games[a].planet, unit) as i64;
    let res = w.games[a].planet.res;
    let by_stock = (0..3).filter(|&r| u.price[r] > 0.0).fold(i64::MAX, |m, r| m.min((res[r] / u.price[r]).floor() as i64));
    missing.min(cap).min(by_stock).max(0)
}

/// A wide order row's amount: what the stock pays for, or a tenth of it.
fn hull_amount(w: &World, cat: &Catalogue, a: usize, unit: usize, size: Size) -> i64 {
    let all = orderable(w, cat, a, unit, i64::MAX);
    if size == Size::Batch { (all / 10).max(all.min(1)) } else { all }
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

/// Account a's fleet save still flying out: a transport with an empty hold to another account (`save_plan`'s errand). A
/// player recalls that once the danger has passed; offering any fleet recalled expeditions, colony ships and moon shots
/// at nearly every login (the door's accounts lost 2.5% and four moons in eleven).
fn saved_fleet(w: &World, a: usize) -> Option<usize> {
    w.fleets.iter().position(|f| f.owner == a && !f.returning && f.cargo == [0.0; 3] && match f.mission {
        Mission::Transport => w.account_of(f.target) != w.account_of(a),
        // Arm `late`: the hidden save (FS-010), an empty deployment to another planet of the account.
        Mission::Deploy => w.late && w.hidden_saves.contains(&f.id),
        _ => false,
    })
}

fn outbound(w: &World, a: usize) -> bool {
    saved_fleet(w, a).is_some()
}

/// Whether account a has a fleet slot free (`GameMission::startMissionSanityChecks`).
fn slot_free(w: &World, cat: &Catalogue, a: usize) -> bool {
    let out = w.fleets.iter().filter(|f| f.mission != Mission::Missile && w.account_of(f.owner) == w.account_of(a)).count();
    w.fleet_slots(cat, a).is_none_or(|slots| out < slots)
}

/// The teacher's priority: the first of these a login can take is what the scripted mix would do, and therefore the
/// label behaviour cloning learns. `wait` is last, so a login with nothing to do is labelled `wait`.
pub const TEACHER_ORDER: [Kind; 7] = [Kind::Save, Kind::Recall, Kind::Recycle, Kind::Raid, Kind::Spy, Kind::Order, Kind::Wait];

/// The wide teacher: the scripted rules with every arm on, in the order a raider's login walks them (save first, then
/// the mates, the field, the moon strike, a raid timed by the phalanx, a raid, missiles where the raid is turned away,
/// expeditions, moon shots, spying, the fleet to the moon, the yard).
pub const WIDE_TEACHER_ORDER: [Kind; 16] = [
    Kind::Save, Kind::GateSave, Kind::Recall, Kind::AcsDefend, Kind::Recycle, Kind::MoonDestroy, Kind::Phalanx, Kind::Raid,
    Kind::Strike, Kind::AcsJoin, Kind::Expedition, Kind::MoonShot, Kind::Spy, Kind::Deploy, Kind::Order, Kind::Wait,
];

/// Every errand this login may choose, `wait` first (row 0: the exporters, the metrics and the PHP encoder assume it).
/// Spying and raids are offered to an account that raids (`raids`), as the scripted players only raid as raiders: offered
/// to every account, a miner's logins went to 20 times the raids for no loot and half the moons.
pub fn candidates(w: &mut World, cat: &Catalogue, a: usize, raids: bool) -> Vec<Errand> {
    candidates_in(w, cat, a, &Menu::narrow(raids))
}

/// `candidates` with the season's `menu`; with `World::wide` the wide rows follow today's, which stay first.
pub fn candidates_in(w: &mut World, cat: &Catalogue, a: usize, menu: &Menu) -> Vec<Errand> {
    let raids = menu.raids;
    let mut out = vec![WAIT];
    let mut walked = Vec::new();
    if w.account_of(a) == a {
        if let Some(kit) = Kit::of(w, cat, a) {
            let now = w.games[a].t;
            // The walk reaches the nearest neighbours; a player also raids the richest target it has scouted, however far.
            // Same menu, wider: the nearest stay first, so the teacher's label and its fallback do not move.
            // Arm `bodies`: the walk reaches every body the galaxy view shows, colonies and moons included.
            let mut targets = if w.bodies { players::nearest_bodies(w, cat, a, players::NEIGHBOURS) } else { nearest(w, cat, a, players::NEIGHBOURS) };
            let mut juicy: Vec<(usize, f64)> = w.reports_of(a)
                .filter(|r| now - r.time < players::REPORT_HOURS * 3600.0 && !targets.contains(&r.target))
                .map(|r| (r.target, players::weighted(r.resources.map(|v| v as f64))))
                .collect();
            juicy.sort_by(|x, y| y.1.total_cmp(&x.1));
            targets.extend(juicy.iter().take(players::RICH_TARGETS).map(|&(t, _)| t));
            for &target in &targets {
                let fresh = last_report(w, a, target).is_some_and(|r| now - r.time < players::REPORT_HOURS * 3600.0);
                let hostile = !w.alliances_distinct || !w.spares(a, target, now);
                // Arm `newbie`: no raid on the galaxy view's newbie (the scout may still read it).
                let raidable = hostile && !(w.newbie && players::protected_newbie(w, cat, a, target, now));
                // Arm `rescout`: a fresh redacted report is re-read once the whole next volley is home and none is out.
                let rescout = raids && fresh && w.rescout && players::rescout_due(w, a, target)
                    && on_shelf(w, a, kit.probe) >= players::spy_volley(w, a, target)
                    && !w.fleets.iter().any(|f| f.owner == a && f.target == target && f.mission == Mission::Espionage);
                if raids && hostile && (!fresh || rescout) && on_shelf(w, a, kit.probe) >= 1 { out.push(Errand::at(Kind::Spy, target)) }
                if raids && raidable && fresh && raid_plan(w, cat, a, target, kit).is_some() { out.push(Errand::at(Kind::Raid, target)) }
                if harvest_plan(w, cat, a, target).is_some() { out.push(Errand::at(Kind::Recycle, target)) }
            }
            walked = targets;
        }
        if matches!(w.decision_kind(a), Decision::Idle) {
            for (unit, missing) in teacher().order_plans(w, cat, a) {
                out.push(Errand { kind: Kind::Order, target: None, unit: Some(unit), size: Size::Want, plan: Plan::Base });
                // A player with a short stock orders what it can pay for instead of spending the login on a refusal.
                let now = orderable(w, cat, a, unit, missing);
                if now > 0 && now < missing {
                    out.push(Errand { kind: Kind::Order, target: None, unit: Some(unit), size: Size::Now, plan: Plan::Base });
                }
            }
        }
        if incoming(w, a).is_some_and(|lands| save_plan(w, cat, a, lands - w.games[a].t).is_some()) {
            out.push(Errand::at(Kind::Save, a));
        }
        if incoming(w, a).is_none() && outbound(w, a) {
            out.push(Errand::at(Kind::Recall, a));
        }
        if w.wide { widen(w, cat, a, menu, &walked, &mut out) }
    }
    out.truncate(max_candidates(w.wide));
    out
}

/// The raid the wide teacher sends at `target`, as the plan of its fleet: at a defended target the war fleet when the
/// shelf holds one, else the cheapest-shooter raid only when it already holds `WAR_ODDS_FLOOR` times the reported
/// value; at an undefended one the cheapest raid. `None` when no raid passes.
fn teacher_raid(w: &World, cat: &Catalogue, a: usize, target: usize, kit: Kit) -> Option<Plan> {
    if w.late && players::breaker_plan(w, cat, a, target, kit).is_some() { return Some(Plan::Breaker) }
    let defended = players::reported_value(w, a, target).unwrap_or(0.0);
    if defended > 0.0 && players::war_plan(w, cat, a, target, kit, 10.0).is_some() { return Some(Plan::War) }
    let base = raid_plan(w, cat, a, target, kit)?;
    (defended <= 0.0 || players::fleet_value(w, a, &base.fleet) >= players::WAR_ODDS_FLOOR * defended).then_some(Plan::Base)
}

/// The fleet a raid errand of `plan` sends at `target`.
fn raid_fleet(w: &World, cat: &Catalogue, a: usize, target: usize, kit: Kit, plan: Plan) -> Option<RaidPlan> {
    match plan {
        Plan::War | Plan::WarSlow => players::war_plan(w, cat, a, target, kit, plan.speed()),
        Plan::Breaker => players::breaker_plan(w, cat, a, target, kit),
        _ => raid_plan(w, cat, a, target, kit),
    }
}

/// The expedition fleet of `plan` (`players::expedition_trips`' first trip), when a slot is free and the stock fuels it:
/// (the trip's ships, the doubler wanted, an escort wanted).
fn expedition_fleet(w: &World, cat: &Catalogue, a: usize, plan: Plan) -> Option<Vec<(u32, i64)>> {
    let (doubled, escort) = match plan { Plan::Cargo => (false, false), Plan::Doubler => (true, false), Plan::Escort => (true, true), _ => return None };
    let (n, slots) = players::expedition_cargo(w, cat, a, doubled)?;
    let flying = w.fleets.iter().filter(|f| w.account_of(f.owner) == w.account_of(a) && f.mission == Mission::Expedition).count();
    if flying >= slots || !slot_free(w, cat, a) { return None }
    let kit = Kit::of(w, cat, a)?;
    let ships = n.min(on_shelf(w, a, kit.cargo));
    if ships < 1 { return None }
    let mut fleet = vec![(kit.cargo, ships)];
    if doubled {
        let id = players::doubler_of(w, a, true).filter(|&id| on_shelf(w, a, id) > 0);
        if plan == Plan::Doubler && id.is_none() { return None }
        fleet.extend(id.map(|id| (id, 1)));
    }
    if escort { fleet.push((players::strongest(w, cat, a, &fleet)?, 1)) }
    let rules = &w.rules[a];
    let research = w.research(cat, a);
    let distance = fleet::distance(&rules.settings, w.coords[a], Coord { position: 16, ..w.coords[a] }, &|_, _| fleet::SystemState::Active);
    let fuel = fleet::consumption(&rules.settings, &rules.fleet, &fleet, &research, distance, 1, 10.0) as f64;
    (fuel <= w.games[a].planet.res[2]).then_some(fleet)
}

/// The wide rows, after today's: raid variants and what a raider does at each target it walked, then the alliance's
/// errands, expeditions, the moon, and one order row per unit the yard can build.
fn widen(w: &mut World, cat: &Catalogue, a: usize, menu: &Menu, walked: &[usize], out: &mut Vec<Errand>) {
    let now = w.games[a].t;
    let free = slot_free(w, cat, a);
    if let Some(kit) = Kit::of(w, cat, a).filter(|_| menu.raids) {
        for &target in walked {
            let fresh = last_report(w, a, target).is_some_and(|r| now - r.time < players::REPORT_HOURS * 3600.0);
            let hostile = !w.alliances_distinct || !w.spares(a, target, now);
            if !(hostile && fresh) { continue }
            let raidable = !(w.newbie && players::protected_newbie(w, cat, a, target, now));
            for plan in [Plan::War, Plan::WarSlow].into_iter().filter(|_| raidable) {
                if free && players::war_plan(w, cat, a, target, kit, plan.speed()).is_some() { out.push(Errand::with(Kind::Raid, target, plan)) }
            }
            if let Some(plan) = teacher_raid(w, cat, a, target, kit).filter(|_| free && players::phalanx_moon(w, cat, a, target).is_some()) {
                out.push(Errand::with(Kind::Phalanx, target, plan));
            }
            if players::strike_salvo(w, cat, a, target).is_some() { out.push(Errand::at(Kind::Strike, target)) }
            let striking = w.fleets.iter().any(|f| f.owner == a && f.target == target && f.mission == Mission::MoonDestroy);
            if free && !striking && players::moon_strike_fleet(w, cat, a, target).is_some() { out.push(Errand::at(Kind::MoonDestroy, target)) }
        }
    }
    if free {
        let mut defended: Vec<usize> = Vec::new();
        for (target, ..) in players::defend_options(w, cat, a, |b| menu.plays(b)) {
            if !defended.contains(&target) { defended.push(target); out.push(Errand::at(Kind::AcsDefend, target)) }
        }
        let mut joined: Vec<usize> = Vec::new();
        for (f, _) in players::join_options(w, cat, a, |b| menu.plays(b)) {
            let target = w.fleets[f].target;
            if !joined.contains(&target) { joined.push(target); out.push(Errand::at(Kind::AcsJoin, target)) }
        }
        if let Some((target, fighter, n)) = players::moon_shot_plan(w, cat, a, |b| menu.shoots_at(b)) {
            if on_shelf(w, a, fighter) >= n { out.push(Errand::at(Kind::MoonShot, target)) }
        }
        // The teacher's own trip first: with every arm on it takes the doubler and an escort.
        for plan in [Plan::Escort, Plan::Doubler, Plan::Cargo] {
            if expedition_fleet(w, cat, a, plan).is_some() { out.push(Errand { kind: Kind::Expedition, target: None, unit: None, size: Size::Want, plan }) }
        }
        if let Some(moon) = players::post_moon(w, cat, a) {
            let research = w.research(cat, a);
            if !players::warships(&w.rules[a], &research, &w.yards[a].counts).is_empty() { out.push(Errand::at(Kind::Deploy, moon)) }
        }
    }
    if w.late {
        late_rows(w, cat, a, menu, walked, free, out);
    } else if let Some(moon) = w.moon_of[a].filter(|&m| incoming(w, m).is_some() && players::gate_open(w, cat, m)) {
        out.push(Errand::at(Kind::GateSave, moon));
    }
    if matches!(w.decision_kind(a), Decision::Idle) {
        for unit in 0..w.rules[a].units.units.len() {
            let all = hull_amount(w, cat, a, unit, Size::Now);
            if all < 1 { continue }
            out.push(Errand { kind: Kind::Order, target: None, unit: Some(unit), size: Size::Now, plan: Plan::Hull });
            if all >= 20 {
                out.push(Errand { kind: Kind::Order, target: None, unit: Some(unit), size: Size::Batch, plan: Plan::Hull });
            }
        }
    }
}

/// The moon over `target` when account a's last word on it is stale: no report younger than `REPORT_HOURS` (INT-012:
/// a fleet once scanned is not scanned again within the day).
fn unscouted_moon(w: &World, a: usize, target: usize) -> Option<usize> {
    let moon = w.moon_of[target]?;
    let now = w.games[a].t;
    (!last_report(w, a, moon).is_some_and(|r| now - r.time < players::REPORT_HOURS * 3600.0)).then_some(moon)
}

/// Whether account a's fresh report on the moon over `target` shows ships that fight: a fleet staged there (NIN-004).
fn staged(w: &World, a: usize, target: usize) -> bool {
    let Some(moon) = w.moon_of[target] else { return false };
    let rules = &w.rules[moon];
    last_report(w, a, moon).and_then(|r| r.ships.as_deref()).is_some_and(|ships| stack(rules, ships).iter().any(|&(id, _)| rules.combat.fights(id)))
}

/// Arm `late`'s rows (plan 5 Nov "late-game tactics"): the moon spy before a raid or a moon strike (NIN-005, CRASH-005),
/// the wall breaker (Deathstar rule 2), the gate save from any gated moon under attack or before the night (CRASH-004,
/// FS-001, FS-011), the night save (FS-001/002/007/009), the hidden save (FS-010) and the ninja landing (NIN-001/002).
fn late_rows(w: &World, cat: &Catalogue, a: usize, menu: &Menu, walked: &[usize], free: bool, out: &mut Vec<Errand>) {
    let now = w.games[a].t;
    if let Some(kit) = Kit::of(w, cat, a).filter(|_| menu.raids) {
        for &target in walked {
            let fresh = last_report(w, a, target).is_some_and(|r| now - r.time < players::REPORT_HOURS * 3600.0);
            let hostile = !w.alliances_distinct || !w.spares(a, target, now);
            if !(hostile && fresh) { continue }
            if let Some(moon) = unscouted_moon(w, a, target).filter(|_| free && on_shelf(w, a, kit.probe) >= 1) { out.push(Errand::at(Kind::Spy, moon)) }
            if free && players::breaker_plan(w, cat, a, target, kit).is_some() { out.push(Errand::with(Kind::Raid, target, Plan::Breaker)) }
        }
    }
    let attacked = incoming(w, a);
    if let Some((moon, ..)) = attacked.and_then(|_| players::ninja_plan(w, cat, a)) {
        out.push(Errand::with(Kind::Deploy, moon, Plan::Ninja));
    }
    if let Some(lands) = attacked.filter(|_| free) {
        if players::hidden_save(w, cat, a, lands - now).is_some() { out.push(Errand::with(Kind::Save, a, Plan::Hidden)) }
    }
    let night = players::night_due(w, a) && !outbound(w, a) && players::exposed(w, a);
    let moons: Vec<usize> = w.planets_of(a).into_iter().filter_map(|p| w.moon_of[p]).collect();
    for &moon in &moons {
        if !players::gate_open(w, cat, moon) { continue }
        if incoming(w, moon).is_some() { out.push(Errand::at(Kind::GateSave, moon)); continue }
        if night && free { out.push(Errand::with(Kind::GateSave, moon, Plan::Night)) }
    }
    if night && free {
        for from in std::iter::once(a).chain(players::post_moon(w, cat, a)) {
            if players::night_plan(w, cat, a, from).is_some() { out.push(Errand::with(Kind::Save, from, Plan::Night)) }
        }
    }
}

/// Whether a moon strike at the moon over `target` is worth its loss roll (WIK-196's odds from the host): what it wins,
/// the fleet the report shows hiding there (the strike's fight catches it; CRASH-005, WIK-187: the moon is popped to
/// make that fleet vulnerable) and, with the destroy odds, our own warships that the moon's reported phalanx can time a
/// crash on (WIK-187's commonest reason, CRASH-007), against the stack's expected loss.
fn moon_strike_pays(w: &World, cat: &Catalogue, a: usize, target: usize) -> bool {
    let (Some(odds), Some(moon), Some(strike)) = (players::moon_odds(w, a, target), w.moon_of[target], w.rules[a].strike.as_ref()) else { return false };
    let report = last_report(w, a, moon);
    let hiding = report.and_then(|r| r.ships.as_deref()).map_or(0.0, |s| players::fleet_value(w, moon, &stack(&w.rules[moon], s)));
    let research = w.research(cat, a);
    let watched = report.is_some_and(|r| players::watches(w, cat, moon, a, r.buildings.as_deref()));
    let shielded = if watched { players::fleet_value(w, a, &players::warships(&w.rules[a], &research, &w.yards[a].counts)) } else { 0.0 };
    let at_risk = players::fleet_value(w, a, &[(strike.moon_destroyer, on_shelf(w, a, strike.moon_destroyer))]);
    odds.destroy >= odds.loss || hiding + odds.destroy / 100.0 * shielded >= odds.loss / 100.0 * at_risk
}

/// Arm `late`'s teacher, ahead of the wide order: the ninja landing (it only exists in its window), then the save the
/// late rules prefer (a gate save, the hidden save), the night save of a fleeter or raider, a moon strike whose odds
/// beat the loss roll; `None` hands the login to the wide order, where a raid on a target whose moon is unscouted or
/// shows a staged fleet is not taught (NIN-005), so the moon spy is.
fn late_pick(w: &World, cat: &Catalogue, a: usize, menu: &Menu, offered: &[Errand]) -> Option<Errand> {
    let find = |kind: Kind, plan: Plan| offered.iter().copied().find(|e| e.kind == kind && e.plan == plan);
    // NIN-005: the raid the wide order would teach waits for the spy on its target's moon.
    let kit = Kit::of(w, cat, a);
    let raid_first = || offered.iter().copied()
        .filter(|e| e.kind == Kind::Raid && kit.zip(e.target).and_then(|(kit, t)| teacher_raid(w, cat, a, t, kit)) == Some(e.plan))
        .find_map(|e| e.target.and_then(|t| unscouted_moon(w, a, t)))
        .and_then(|moon| offered.iter().copied().find(|e| e.kind == Kind::Spy && e.target == Some(moon)));
    find(Kind::Deploy, Plan::Ninja)
        .or_else(|| offered.iter().copied().find(|e| e.kind == Kind::GateSave && e.plan == Plan::Base))
        .or_else(|| find(Kind::Save, Plan::Hidden))
        .or_else(|| find(Kind::Save, Plan::Base))
        .or_else(|| (menu.station || menu.raids).then(|| find(Kind::GateSave, Plan::Night).or_else(|| find(Kind::Save, Plan::Night))).flatten())
        .or_else(|| offered.iter().copied().find(|e| e.kind == Kind::MoonDestroy && e.target.is_some_and(|t| moon_strike_pays(w, cat, a, t))))
        .or_else(|| raid_first())
}

/// The errand the scripted mix would take here (the behaviour-cloning label): the first candidate in
/// `TEACHER_ORDER`. `wait` when the login has nothing better to do.
pub fn teacher_choice(w: &mut World, cat: &Catalogue, a: usize, raids: bool) -> Errand {
    teacher_choice_in(w, cat, a, &Menu::narrow(raids))
}

/// `teacher_choice` with the season's `menu`; wide, the scripted rules with every arm on (`WIDE_TEACHER_ORDER`).
pub fn teacher_choice_in(w: &mut World, cat: &Catalogue, a: usize, menu: &Menu) -> Errand {
    let offered = candidates_in(w, cat, a, menu);
    teacher_among(w, cat, a, menu, &offered)
}

/// The teacher's pick among the errands `candidates_in` already offered at this login.
pub fn teacher_among(w: &World, cat: &Catalogue, a: usize, menu: &Menu, offered: &[Errand]) -> Errand {
    if !w.wide {
        return TEACHER_ORDER.iter().find_map(|&kind| offered.iter().copied().find(|e| e.kind == kind)).unwrap_or(WAIT);
    }
    if let Some(pick) = w.late.then(|| late_pick(w, cat, a, menu, offered)).flatten() { return pick }
    let kit = Kit::of(w, cat, a);
    // Arm `late`, NIN-005: no raid at a target whose moon is unscouted or holds a staged fleet.
    let checked = |t: usize| !w.late || (unscouted_moon(w, a, t).is_none() && !staged(w, a, t));
    let teaches = |e: &Errand| match (e.kind, e.plan) {
        (Kind::Raid, plan) => kit.zip(e.target).and_then(|(kit, t)| teacher_raid(w, cat, a, t, kit)) == Some(plan) && checked(e.target.unwrap_or(a)),
        (Kind::Phalanx, _) => checked(e.target.unwrap_or(a)),
        (Kind::Order, plan) => plan != Plan::Hull,
        (Kind::Deploy, Plan::Ninja) | (Kind::Save, Plan::Night | Plan::Hidden) | (Kind::GateSave, Plan::Night) => false,
        (Kind::Deploy, _) => menu.station,
        (Kind::MoonDestroy, _) => !w.late,
        // A night save is not called back: it lands after the next login on its own (FS-007).
        (Kind::Recall, _) => !w.late || saved_fleet(w, a).is_none_or(|f| players::hours_to_night(w, a, w.fleets[f].departed) > 1.0),
        _ => true,
    };
    WIDE_TEACHER_ORDER.iter().find_map(|&kind| offered.iter().copied().find(|e| e.kind == kind && teaches(e))).unwrap_or(WAIT)
}

/// What the errand does to the world, through the world's own calls, so a refusal is the host's refusal.
pub fn apply(w: &mut World, cat: &Catalogue, a: usize, errand: Errand) -> Result<(), &'static str> {
    apply_in(w, cat, a, errand, &Menu::narrow(true))
}

/// `apply` with the season's `menu`, which the alliance's errands read their mates from.
pub fn apply_in(w: &mut World, cat: &Catalogue, a: usize, errand: Errand, menu: &Menu) -> Result<(), &'static str> {
    let target = errand.target.unwrap_or(a);
    let kit = Kit::of(w, cat, a);
    match errand.kind {
        Kind::Spy => {
            let kit = kit.ok_or("no ships for this account")?;
            // Arm `late`: a moon is read deeper, to its buildings (a phalanx) and research, for the moon strike.
            let deep = w.late && w.moon_at[target].is_some();
            // Arm `raids`: the module's volley (`players::spy_volley`).
            let volley = if w.raids { players::spy_volley(w, a, target) } else { players::SPY_PROBES };
            let probes = on_shelf(w, a, kit.probe).min(volley * if deep { 2 } else { 1 });
            w.dispatch(cat, a, target, Mission::Espionage, &[(kit.probe, probes)], [0.0; 3], 10.0)
        }
        Kind::Raid => {
            let kit = kit.ok_or("no ships for this account")?;
            let plan = raid_fleet(w, cat, a, target, kit, errand.plan).ok_or("no raid to send")?;
            w.dispatch(cat, a, target, Mission::Attack, &plan.fleet, [0.0; 3], errand.plan.speed())
        }
        Kind::Recycle => {
            let (harvester, n) = harvest_plan(w, cat, a, target).ok_or("nothing to harvest")?;
            w.dispatch(cat, a, target, Mission::Recycle, &[(harvester, n)], [0.0; 3], 10.0)
        }
        Kind::Save if errand.plan == Plan::Night => {
            let waves = players::night_plan(w, cat, a, target).ok_or("no night save")?;
            for (to, ships, speed) in waves { w.dispatch(cat, target, to, Mission::Transport, &ships, [0.0; 3], speed)? }
            Ok(())
        }
        Kind::Save if errand.plan == Plan::Hidden => {
            let lands = incoming(w, a).ok_or("nothing to save from")?;
            let (to, ships, speed) = players::hidden_save(w, cat, a, lands - w.games[a].t).ok_or("no planet far enough")?;
            w.dispatch(cat, a, to, Mission::Deploy, &ships, [0.0; 3], speed)?;
            let id = w.fleets.last().map_or(0, |f| f.id);
            w.hidden_saves.retain(|&h| w.fleets.iter().any(|f| f.id == h && !f.returning));
            w.hidden_saves.push(id);
            Ok(())
        }
        Kind::Save => {
            let lands = incoming(w, a).ok_or("nothing to save from")?;
            let (away, ships, speed) = save_plan(w, cat, a, lands - w.games[a].t).ok_or("nowhere far enough")?;
            w.dispatch(cat, a, away, Mission::Transport, &ships, [0.0; 3], speed)
        }
        Kind::Recall => {
            let f = saved_fleet(w, a).ok_or("no fleet save out")?;
            w.recall(a, f)
        }
        Kind::Order if errand.plan == Plan::Hull => {
            let unit = errand.unit.ok_or("no unit to order")?;
            let amount = hull_amount(w, cat, a, unit, errand.size);
            if amount <= 0 { return Err("nothing the stock pays for") }
            w.order_units(cat, a, unit, amount as u64);
            Ok(())
        }
        Kind::Order => {
            let unit = errand.unit.ok_or("no unit to order")?;
            let missing = teacher().order_plans(w, cat, a).into_iter().find(|&(u, _)| u == unit).map_or(0, |(_, n)| n);
            if missing == 0 { return Err("nothing to order") }
            let amount = match errand.size { Size::Want => missing, Size::Now | Size::Batch => orderable(w, cat, a, unit, missing) };
            if amount <= 0 { return Err("nothing the stock pays for") }
            w.order_units(cat, a, unit, amount as u64);
            Ok(())
        }
        Kind::Wait => Ok(()),
        Kind::Strike => {
            let held = players::strike_salvo(w, cat, a, target).ok_or("no salvo to fire")?;
            w.launch_missiles(cat, a, target, false, held, 0)
        }
        Kind::MoonDestroy => {
            let fleet = players::moon_strike_fleet(w, cat, a, target).ok_or("no moon strike")?;
            w.dispatch(cat, a, target, Mission::MoonDestroy, &fleet, [0.0; 3], 10.0)
        }
        Kind::MoonShot => {
            let (to, fighter, n) = players::moon_shot_plan(w, cat, a, |b| menu.shoots_at(b)).filter(|s| s.0 == target).ok_or("no moon shot")?;
            w.dispatch(cat, a, to, Mission::Attack, &[(fighter, n)], [0.0; 3], 10.0)
        }
        Kind::AcsJoin => {
            let (f, fleet) = players::join_options(w, cat, a, |b| menu.plays(b)).into_iter().find(|o| w.fleets[o.0].target == target).ok_or("no raid to join")?;
            w.join(cat, a, f, &fleet, [0.0; 3], 10.0)
        }
        Kind::AcsDefend => {
            let (_, ships, hold) = players::defend_options(w, cat, a, |b| menu.plays(b)).into_iter().find(|o| o.0 == target).ok_or("no mate to hold at")?;
            w.defend(cat, a, target, &ships, [0.0; 3], 10.0, hold)
        }
        Kind::Expedition => {
            expedition_fleet(w, cat, a, errand.plan).ok_or("no expedition to send")?;
            let before = w.fleets.len();
            players::expedition_trips(w, cat, a, errand.plan != Plan::Cargo, false, errand.plan == Plan::Escort);
            if w.fleets.len() > before { Ok(()) } else { Err("the expedition was refused") }
        }
        Kind::Deploy if errand.plan == Plan::Ninja => {
            let (moon, ships, speed) = players::ninja_plan(w, cat, a).ok_or("no ninja landing")?;
            w.dispatch(cat, moon, a, Mission::Deploy, &ships, [0.0; 3], speed)
        }
        Kind::Deploy => {
            let before = w.fleets.len();
            players::station(w, cat, a, target, &mut players::Ledger::default());
            if w.fleets.len() > before { Ok(()) } else { Err("nothing deployed") }
        }
        Kind::GateSave if errand.plan == Plan::Night => {
            // The jump, then the save flies on from the other moon, where no phalanx reads it (FS-011, CRASH-006).
            let to = players::gate_escape(w, cat, target).ok_or("no gate to jump")?;
            if let Some(waves) = players::night_plan(w, cat, a, to) {
                for (b, ships, speed) in waves { let _ = w.dispatch(cat, to, b, Mission::Transport, &ships, [0.0; 3], speed); }
            }
            Ok(())
        }
        Kind::GateSave => players::gate_escape(w, cat, target).map(|_| ()).ok_or("no gate to jump"),
        Kind::Phalanx => {
            let kit = kit.ok_or("no ships for this account")?;
            let plan = raid_fleet(w, cat, a, target, kit, errand.plan).ok_or("no raid to send")?;
            let rules = std::sync::Arc::clone(&w.rules[a]);
            let research = w.research(cat, a);
            let lands = w.games[a].t + fleet::flight_seconds(&rules.settings, Mission::Attack as u32, players::account_distance(w, a, target), rules.fleet.slowest_speed(&plan.fleet, &research), 10.0) as f64;
            // The scan is the errand: a target whose fleet is home first keeps the raid at home.
            if players::fleet_home_first(w, cat, a, target, lands) { return Ok(()) }
            w.dispatch(cat, a, target, Mission::Attack, &plan.fleet, [0.0; 3], 10.0)
        }
    }
}

fn ln1p(value: f64) -> f32 {
    value.max(0.0).ln_1p() as f32
}

/// A share in 0..1, kept finite when the denominator is zero.
fn ratio(value: f64, whole: f64) -> f32 {
    if whole > 0.0 { (value / whole).clamp(0.0, 1.0) as f32 } else { 0.0 }
}

/// The account's own columns, `state_names` order.
pub fn account_features(w: &World, cat: &Catalogue, a: usize) -> Vec<f32> {
    let mut f = vec![0.0; state_dim(w.wide)];
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
    f[9] = ln1p(w.last_attacked.get(&a).map_or(0.0, |&at| (w.games[a].t - at).max(0.0) / 3600.0));
    f[10] = incoming(w, a).map_or(0.0, |lands| ((lands - w.games[a].t).max(0.0) / 3600.0) as f32);
    f[11] = f32::from(matches!(w.decision_kind(a), Decision::Idle));
    if w.wide {
        f[12] = ln1p(players::fleet_value(w, a, &players::warships(rules, &research, &w.yards[a].counts)));
        f[13] = f32::from(w.moon_of[a].is_some());
        f[14] = ln1p(rules.strike.as_ref().map_or(0, |s| on_shelf(w, a, s.missile)) as f64);
        let expeditions = w.fleets.iter().filter(|g| w.account_of(g.owner) == a && g.mission == Mission::Expedition).count();
        f[15] = w.expedition_slots(cat, a).map_or(0.0, |slots| ratio(slots.saturating_sub(expeditions) as f64, slots as f64));
        let out = w.fleets.iter().filter(|g| g.mission != Mission::Missile && w.account_of(g.owner) == a).count();
        f[16] = w.fleet_slots(cat, a).map_or(0.0, |slots| ratio(slots.saturating_sub(out) as f64, slots as f64));
    }
    f
}

/// The ships an errand flies, what it expects to bring home and the speed it flies at, and the mission the host books
/// it as; `None` for an errand that flies nothing a feature can price (an order, waiting, missiles, a gate jump).
fn flown(w: &World, cat: &Catalogue, a: usize, errand: Errand, menu: &Menu) -> Option<(Mission, Vec<(u32, i64)>, [f64; 3], f64)> {
    let now = w.games[a].t;
    let target = errand.target.unwrap_or(a);
    let kit = Kit::of(w, cat, a)?;
    let research = w.research(cat, a);
    match errand.kind {
        Kind::Raid | Kind::Phalanx => raid_fleet(w, cat, a, target, kit, errand.plan).map(|p| (Mission::Attack, p.fleet, p.expected, errand.plan.speed())),
        Kind::Spy => Some((Mission::Espionage, vec![(kit.probe, 1)], [0.0; 3], 10.0)),
        Kind::Recycle => harvest_plan(w, cat, a, target).map(|(h, n)| (Mission::Recycle, vec![(h, n)], [0.0; 3], 10.0)),
        Kind::Save if errand.plan == Plan::Night => players::night_plan(w, cat, a, target).map(|waves| (Mission::Transport, waves.into_iter().flat_map(|v| v.1).collect(), [0.0; 3], 10.0)),
        Kind::Save if errand.plan == Plan::Hidden => players::hidden_save(w, cat, a, incoming(w, a).map_or(0.0, |l| l - now)).map(|(_, s, speed)| (Mission::Deploy, s, [0.0; 3], speed)),
        Kind::Save => save_plan(w, cat, a, incoming(w, a).map_or(0.0, |l| l - now)).map(|(_, s, _)| (Mission::Transport, s, [0.0; 3], 10.0)),
        Kind::MoonDestroy => players::moon_strike_fleet(w, cat, a, target).map(|s| (Mission::MoonDestroy, s, [0.0; 3], 10.0)),
        Kind::MoonShot => players::moon_shot_plan(w, cat, a, |b| menu.shoots_at(b)).map(|(_, id, n)| (Mission::Attack, vec![(id, n)], [0.0; 3], 10.0)),
        Kind::AcsJoin => players::join_options(w, cat, a, |b| menu.plays(b)).into_iter().find(|o| w.fleets[o.0].target == target).map(|o| (Mission::Attack, o.1, [0.0; 3], 10.0)),
        Kind::AcsDefend => players::defend_options(w, cat, a, |b| menu.plays(b)).into_iter().find(|o| o.0 == target).map(|o| (Mission::AcsDefend, o.1, [0.0; 3], 10.0)),
        Kind::Deploy if errand.plan == Plan::Ninja => players::ninja_plan(w, cat, a).map(|(_, s, speed)| (Mission::Deploy, s, [0.0; 3], speed)),
        Kind::Deploy => Some((Mission::Deploy, players::warships(&w.rules[a], &research, &w.yards[a].counts), [0.0; 3], 10.0)),
        Kind::Expedition => expedition_fleet(w, cat, a, errand.plan).map(|s| (Mission::Expedition, s, [0.0; 3], 10.0)),
        Kind::Recall | Kind::Order | Kind::Wait | Kind::Strike | Kind::GateSave => None,
    }
}

/// What this candidate adds, `cand_columns` order.
pub fn candidate_features(w: &mut World, cat: &Catalogue, a: usize, errand: Errand) -> Vec<f32> {
    candidate_features_in(w, cat, a, errand, &Menu::narrow(true))
}

/// `candidate_features` with the season's `menu`, which the alliance's errands read their mates from.
pub fn candidate_features_in(w: &mut World, cat: &Catalogue, a: usize, errand: Errand, menu: &Menu) -> Vec<f32> {
    let mut f = vec![0.0; cand_dim(w.wide)];
    f[errand.kind.one_hot()] = 1.0;
    let fleet = narrow_features(w, cat, a, errand, menu, &mut f[..CAND_NAMES.len()]);
    if w.wide { wide_features(w, cat, a, errand, fleet, &mut f[CAND_DIM..CAND_DIM + WIDE_CAND_NAMES.len()]) }
    f
}

/// `CAND_NAMES`' columns; hands back the fleet the errand flies, for the wide columns.
fn narrow_features(w: &mut World, cat: &Catalogue, a: usize, errand: Errand, menu: &Menu, f: &mut [f32]) -> Option<(Vec<(u32, i64)>, f64)> {
    if errand.kind == Kind::Order && errand.plan != Plan::Hull {
        // Two orders of the same unit differ only in this: the share of the want the login asks for.
        let missing = teacher().order_plans(w, cat, a).into_iter().find(|&(u, _)| Some(u) == errand.unit).map_or(0, |(_, n)| n);
        f[12] = match errand.size {
            Size::Want => 1.0,
            Size::Now | Size::Batch => ratio(orderable(w, cat, a, errand.unit.unwrap_or(0), missing) as f64, missing as f64),
        };
    }
    let now = w.games[a].t;
    let target = errand.target.unwrap_or(a);
    let inactive = w.inactive(cat, target, now);
    let rules = &w.rules[a];
    let research = w.research(cat, a);
    let distance = players::account_distance(w, a, target);
    if matches!(errand.kind, Kind::Order | Kind::Wait) { return None }
    f[0] = (distance as f32) / 100.0;
    let (mission, ships, expected, speed) = flown(w, cat, a, errand, menu)?;
    let flight = fleet::flight_seconds(&rules.settings, mission as u32, distance, rules.fleet.slowest_speed(&ships, &research), speed) as f64;
    let fuel = fleet::consumption(&rules.settings, &rules.fleet, &ships, &research, distance, 0, speed) as f64;
    let stock = w.games[a].planet.res[2].max(1.0);
    f[1] = (flight / 3600.0) as f32;
    f[2] = (fuel / stock) as f32;
    f[3] = ln1p(players::weighted(expected));
    f[4] = if fuel > 0.0 { (players::weighted(expected) / (WEIGHTS[2] * fuel)) as f32 } else { 0.0 };
    if let Some(report) = last_report(w, a, target) {
        let guns = report.ships.as_deref().map_or(Vec::new(), |s| stack(&w.rules[target], s));
        let observed_research = players::report_research(cat, report, &research);
        let (attack, _) = players::strength(&w.rules[target], &observed_research, &guns);
        let hull = players::strength(rules, &research, &stack(rules, &w.yards[a].counts)).1.max(1.0);
        f[5] = ((attack * 3.0 / hull).max(1e-9)).ln() as f32;
        f[6] = ((now - report.time).max(0.0) / 3600.0) as f32;
        f[10] = ln1p(players::weighted(report.resources.map(|r| r.max(0) as f64)));
    }
    f[7] = f32::from(inactive);
    if let Some((harvester, n)) = harvest_plan(w, cat, a, target) {
        let hold = rules.combat.capacity(&[(harvester, 1)].into_iter().collect(), &research).max(1) * n;
        let field = players::weighted(w.debris[target]);
        f[8] = ln1p(field);
        f[9] = ratio(field, hold as f64);
    }
    if errand.kind == Kind::Save {
        f[11] = incoming(w, a).map_or(0.0, |lands| ((lands - now).max(0.0) / 3600.0) as f32);
    }
    Some((ships, speed))
}

/// `WIDE_CAND_NAMES`' columns: the fleet against the last report (value odds and the engine's forecast), the speed, the
/// expedition's riders, and an order's unit from the host's tables.
fn wide_features(w: &World, cat: &Catalogue, a: usize, errand: Errand, flown: Option<(Vec<(u32, i64)>, f64)>, f: &mut [f32]) {
    let target = errand.target.unwrap_or(a);
    let rules = &w.rules[a];
    if let Some((ships, speed)) = &flown {
        let value = players::fleet_value(w, a, ships);
        f[0] = ln1p(value);
        f[5] = (speed / 10.0) as f32;
        if matches!(errand.kind, Kind::Raid | Kind::Phalanx | Kind::MoonDestroy) {
            // Moon destruction fights the moon: its report is the moon's.
            let ground = if errand.kind == Kind::MoonDestroy { w.moon_of[target].unwrap_or(target) } else { target };
            if let Some(defended) = players::reported_value(w, a, ground).filter(|&v| v > 0.0) {
                f[1] = (value.max(1.0) / defended).ln() as f32;
            }
            if let Some((loss, win)) = players::forecast(w, cat, a, ground, ships) {
                f[2] = ln1p(loss);
                f[3] = ratio(loss, value);
                f[4] = win;
            }
            // Arm `late`: a moon strike's win is the host's odds that the moon falls (WIK-196), its loss share the
            // odds the stack is lost to the moon's roll.
            if let Some(odds) = players::moon_odds(w, a, target).filter(|_| w.late && errand.kind == Kind::MoonDestroy) {
                f[3] = (odds.loss / 100.0) as f32;
                f[4] = (odds.destroy / 100.0) as f32;
            }
        }
    }
    if errand.kind == Kind::Strike {
        if let (Some(held), Some(defended), Some(strike)) = (players::strike_salvo(w, cat, a, target), players::reported_value(w, a, target), rules.strike.as_ref()) {
            f[0] = ln1p(players::fleet_value(w, a, &[(strike.missile, held)]));
            if defended > 0.0 { f[1] = (players::fleet_value(w, a, &[(strike.missile, held)]).max(1.0) / defended).ln() as f32 }
        }
    }
    if errand.kind == Kind::Expedition {
        f[6] = f32::from(errand.plan != Plan::Cargo);
        f[7] = f32::from(errand.plan == Plan::Escort);
        let flying = w.fleets.iter().filter(|g| w.account_of(g.owner) == a && g.mission == Mission::Expedition).count();
        f[8] = w.expedition_slots(cat, a).map_or(0.0, |slots| ratio(slots.saturating_sub(flying) as f64, slots as f64));
    }
    let Some(unit) = errand.unit.filter(|_| errand.kind == Kind::Order) else { return };
    let Some(u) = rules.units.units.get(unit) else { return };
    let research = w.research(cat, a);
    let id = u.id as u32;
    let price = players::weighted(u.price).max(1.0);
    let per = |x: i64| ln1p(1000.0 * x as f64 / price);
    f[9] = per(rules.combat.attack(id, &research));
    f[10] = per(rules.combat.hull(id, &research));
    f[11] = per(rules.combat.shield(id, &research));
    f[12] = per(rules.combat.unit_capacity(id, &research));
    f[13] = ln1p(rules.fleet.speed(id, &research) as f64);
    let kind = rules.units.kind.get(unit);
    f[14] = f32::from(kind == Some(&UnitKind::MilitaryShip));
    f[15] = f32::from(kind == Some(&UnitKind::CivilShip));
    f[16] = f32::from(kind == Some(&UnitKind::Defence));
    if errand.plan == Plan::Hull {
        let stock = players::weighted(w.games[a].planet.res);
        f[17] = ratio(hull_amount(w, cat, a, unit, errand.size) as f64 * price, stock);
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::players::{FleetSaver, Ledger, Player};
    use crate::world::{EventKind, Rules};
    use crate::HashSet;
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
    fn drive(seed: u64, choose: impl FnMut(&mut World, &Catalogue, usize, &[Errand])) -> Option<(Catalogue, World)> {
        drive_wide(seed, false, choose)
    }

    fn drive_wide(seed: u64, wide: bool, choose: impl FnMut(&mut World, &Catalogue, usize, &[Errand])) -> Option<(Catalogue, World)> {
        drive_late(seed, wide, false, choose)
    }

    fn drive_late(seed: u64, wide: bool, late: bool, mut choose: impl FnMut(&mut World, &Catalogue, usize, &[Errand])) -> Option<(Catalogue, World)> {
        let (cat, mut w) = world(seed)?;
        w.wide = wide;
        w.late = late;
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
            let offered = candidates(&mut w, &cat, a, true);
            choose(&mut w, &cat, a, &offered);
            match players[a].act(&mut w, &cat, a, &mut ledger) {
                Some(k) => w.commit(&cat, a, k),
                None => w.wait(&cat, a),
            }
        }
        Some((cat, w))
    }

    #[test]
    fn errand_features_do_not_read_unreported_enemy_research() {
        let (cat, mut world) = world(7).expect("the errand test catalogue");
        let ships = world.yards[1].counts.clone();
        world.reports.push(crate::world::SpyReport { owner: 0, target: 1, time: 0.0, resources: [0; 3], debris: None, counter_chance: 0, defence: Some(vec![0; ships.len()]), ships: Some(ships), buildings: None, research: None });
        let mut hidden_changed = world.clone();
        for (k, object) in cat.objects.iter().enumerate() {
            if object.queue == 1 { hidden_changed.games[1].planet.levels[k] = 20 }
        }
        let errand = Errand::at(Kind::Spy, 1);
        let original = candidate_features(&mut world, &cat, 0, errand);
        let changed = candidate_features(&mut hidden_changed, &cat, 0, errand);
        assert_eq!(original, changed, "unreported tech cannot change policy inputs");
        hidden_changed.reports.last_mut().unwrap().research = Some(cat.objects.iter().map(|o| if o.queue == 1 { 20 } else { 0 }).collect());
        let reported = candidate_features(&mut hidden_changed, &cat, 0, errand);
        assert_ne!(original[5], reported[5], "reported research changes the threat feature");
    }

    /// The wide menu's kinds read the report too, never the target's live state: neither its unreported research nor
    /// the ships it built since the report move any column (the forecast and the odds included).
    #[test]
    fn wide_errand_features_do_not_read_unreported_enemy_state() {
        let (cat, mut world) = world(7).expect("the errand test catalogue");
        world.wide = true;
        let ships = world.yards[1].counts.clone();
        world.reports.push(crate::world::SpyReport { owner: 0, target: 1, time: world.games[0].t, resources: [50_000, 30_000, 10_000], debris: None, counter_chance: 0, defence: Some(vec![0; ships.len()]), ships: Some(ships), buildings: None, research: None });
        let kit = Kit::of(&world, &cat, 0).expect("a kit");
        assert!(players::war_plan(&world, &cat, 0, 1, kit, 10.0).is_some(), "the raider holds a war fleet for the reported target");
        let mut hidden_changed = world.clone();
        for (k, object) in cat.objects.iter().enumerate() {
            if object.queue == 1 { hidden_changed.games[1].planet.levels[k] = 20 }
        }
        for n in hidden_changed.yards[1].counts.iter_mut() { *n += 500 }
        let mut errands: Vec<Errand> = [Plan::Base, Plan::War, Plan::WarSlow].into_iter().flat_map(|plan| [Errand::with(Kind::Raid, 1, plan), Errand::with(Kind::Phalanx, 1, plan)]).collect();
        errands.extend([Kind::Spy, Kind::Strike, Kind::MoonDestroy, Kind::MoonShot, Kind::AcsJoin, Kind::AcsDefend, Kind::Deploy, Kind::GateSave].map(|kind| Errand::at(kind, 1)));
        errands.extend([Plan::Cargo, Plan::Doubler, Plan::Escort].map(|plan| Errand { kind: Kind::Expedition, target: None, unit: None, size: Size::Want, plan }));
        for errand in errands {
            let original = candidate_features(&mut world, &cat, 0, errand);
            assert_eq!(original.len(), WIDE_CAND_DIM);
            assert_eq!(original, candidate_features(&mut hidden_changed, &cat, 0, errand), "{errand:?}: unreported state cannot change policy inputs");
        }
        let war = candidate_features(&mut world, &cat, 0, Errand::with(Kind::Raid, 1, Plan::War));
        let odds = CAND_DIM + WIDE_CAND_NAMES.iter().position(|&n| n == "odds").unwrap();
        assert!(war[odds] >= (players::WAR_ODDS_FLOOR as f32).ln() - 1e-4, "the war fleet holds the floor odds against the report: {}", war[odds]);
        assert_eq!(account_features(&world, &cat, 0), account_features(&hidden_changed, &cat, 0));
    }

    /// Arm `late`: the late rows, their features and the teacher's pick read reports, the galaxy view (a moon's size)
    /// and the account's own state only: the target's and its moon's unreported research and ships move nothing.
    #[test]
    fn late_errands_do_not_read_unreported_enemy_state() {
        let (cat, mut world) = world(7).expect("the errand test catalogue");
        world.wide = true;
        world.late = true;
        let moon = world.add_moon(&cat, 1, 4000, 0.0);
        if let Some(destroyer) = world.rules[0].strike.as_ref().map(|s| s.moon_destroyer) { assert!(world.set_units(&cat, 0, destroyer, 30)) }
        for target in [1, moon] {
            let ships = world.yards[target].counts.clone();
            world.reports.push(crate::world::SpyReport { owner: 0, target, time: world.games[0].t, resources: [50_000, 30_000, 10_000], debris: None, counter_chance: 0, defence: Some(vec![0; ships.len()]), ships: Some(ships), buildings: None, research: Some(vec![0; cat.objects.len()]) });
        }
        let mut hidden_changed = world.clone();
        for slot in [1, moon] {
            for (k, object) in cat.objects.iter().enumerate() {
                if object.queue == 1 { hidden_changed.games[slot].planet.levels[k] = 20 }
            }
            for n in hidden_changed.yards[slot].counts.iter_mut() { *n += 500 }
        }
        let menu = Menu { raids: true, alive: vec![true; ACCOUNTS], shot_at: vec![false; ACCOUNTS], station: true };
        let offered = candidates_in(&mut world, &cat, 0, &menu);
        assert_eq!(offered, candidates_in(&mut hidden_changed, &cat, 0, &menu), "the offer");
        assert_eq!(teacher_among(&world, &cat, 0, &menu, &offered), teacher_among(&hidden_changed, &cat, 0, &menu, &offered), "the teacher");
        let mut errands: Vec<Errand> = [Plan::Base, Plan::Breaker].into_iter().map(|plan| Errand::with(Kind::Raid, 1, plan)).collect();
        errands.extend([Errand::at(Kind::Spy, moon), Errand::at(Kind::MoonDestroy, 1), Errand::with(Kind::Save, 0, Plan::Night), Errand::with(Kind::Save, 0, Plan::Hidden),
            Errand::with(Kind::GateSave, moon, Plan::Night), Errand::with(Kind::Deploy, moon, Plan::Ninja)]);
        errands.extend(offered.iter().copied());
        for errand in errands {
            let original = candidate_features_in(&mut world, &cat, 0, errand, &menu);
            assert_eq!(original, candidate_features_in(&mut hidden_changed, &cat, 0, errand, &menu), "{errand:?}: unreported state cannot change policy inputs");
        }
    }

    /// The wide menu is a superset by name: every narrow column keeps its name and place, the new ones follow, unique.
    #[test]
    fn wide_columns_append_to_the_narrow_ones() {
        for (narrow, wide) in [(state_names(false), state_names(true)), (cand_columns(false), cand_columns(true))] {
            assert_eq!(&wide[..narrow.len()], &narrow[..]);
            let unique: crate::HashSet<&str> = wide.iter().copied().collect();
            assert_eq!(unique.len(), wide.len(), "column names are unique");
        }
        assert_eq!(cand_columns(true).len(), WIDE_CAND_DIM);
        assert_eq!(cand_columns(false).len(), CAND_DIM);
        for kind in [Kind::Spy, Kind::Wait, Kind::Strike, Kind::Phalanx] {
            assert_eq!(cand_columns(true)[kind.one_hot()], KIND_NAMES[kind.column()]);
        }
    }

    #[test]
    fn multi_alliance_errands_offer_enemy_spies_but_not_ally_spies() {
        let (cat, mut w) = world(7).expect("the errand test catalogue");
        w.alliance_at.fill(Some(0.0));
        w.alliance_id[2] = 1;
        w.alliances_distinct = true;
        let offered = candidates(&mut w, &cat, 0, true);
        assert!(offered.iter().any(|e| e.kind == Kind::Spy && e.target == Some(2)));
        assert!(!offered.iter().any(|e| e.kind == Kind::Spy && e.target == Some(1)));
        w.alliances_distinct = false;
        assert!(candidates(&mut w, &cat, 0, true).iter().any(|e| e.kind == Kind::Spy && e.target == Some(1)));
    }

    /// Every candidate the world offers is one the world accepts: a mask that offered a refusal would train the model on
    /// actions the game rejects.
    #[test]
    fn every_offered_errand_is_one_the_world_accepts() {
        let (mut accepted, mut kinds) = (0usize, HashSet::default());
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

    /// Wide, too: every offered errand (raid variants, order rows, the new kinds) is one the world accepts, and the
    /// wide teacher's pick is always offered.
    #[test]
    fn every_offered_wide_errand_is_one_the_world_accepts() {
        for late in [false, true] { every_offered_errand_is_accepted(late) }
    }

    fn every_offered_errand_is_accepted(late: bool) {
        let (mut accepted, mut kinds, mut plans) = (0usize, HashSet::default(), HashSet::default());
        for seed in 1..=4u64 {
            let Some(_) = drive_late(seed, true, late, |w, cat, a, offered| {
                assert!(offered.len() <= WIDE_MAX_CANDIDATES);
                let menu = Menu::narrow(true);
                let label = teacher_choice_in(w, cat, a, &menu);
                assert!(offered.contains(&label), "the teacher's {label:?} is offered");
                let fleet = offered.iter().copied().filter(|e| e.kind != Kind::Wait).count();
                if fleet == 0 { return }
                let pick = offered[accepted % offered.len()];
                apply_in(w, cat, a, pick, &menu).unwrap_or_else(|e| panic!("seed {seed}: the world offered {pick:?} and refused it: {e}"));
                kinds.insert(pick.kind);
                plans.insert(pick.plan);
                accepted += 1;
            }) else { continue };
        }
        assert!(accepted > 50, "only {accepted} errands were exercised");
        assert!(plans.contains(&Plan::Hull), "no order row of the wide menu was exercised: {plans:?}");
        assert!(kinds.contains(&Kind::Raid), "no raid: {kinds:?}");
    }

    /// The order size is a real choice, not a duplicate row: with a stock that cannot pay for the whole want, the staged
    /// order queues the units the stock pays for, and it is fewer than the want.
    #[test]
    fn a_short_stock_orders_only_what_it_pays_for() {
        let mut cases = 0;
        for seed in 1..=4u64 {
            let Some(_) = drive(seed, |w, cat, a, offered| {
                let Some(now) = offered.iter().copied().find(|e| e.kind == Kind::Order && e.size == Size::Now) else { return };
                let want = offered.iter().copied().find(|e| e.kind == Kind::Order && e.size == Size::Want && e.unit == now.unit).expect("the whole want is offered beside the staged order");
                let missing = teacher().order_plans(w, cat, a).into_iter().find(|&(u, _)| Some(u) == now.unit).map_or(0, |(_, n)| n);
                assert_eq!(want.unit, now.unit, "both sizes are offered for the same unit");
                // What this order queued, not what the yard holds: the login's own yard pass may have left units of
                // another kind in the queue, and then the whole-queue count measures someone else's work (row 10c).
                let queue_of = |w: &World| -> u64 {
                    w.yards[a].orders.iter().filter(|o| Some(o.unit) == now.unit).map(|o| o.amount - o.done).sum()
                };
                let before = queue_of(w);
                apply(w, cat, a, now).expect("the world offered the staged order");
                let queued = queue_of(w).saturating_sub(before);
                assert!(queued > 0, "the staged order queued nothing");
                assert!((queued as i64) < missing, "the staged order asked for {queued} of {missing}, so it is not a smaller choice");
                cases += 1;
            }) else { continue };
        }
        assert!(cases > 0, "no login ever offered a staged order");
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
