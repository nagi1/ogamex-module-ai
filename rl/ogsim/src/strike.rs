//! Interplanetary missiles and moon destruction as the host resolves them (world sim, row W8).
//!
//! Every id and constant comes from the `strike` section of the combat catalogue
//! (`ExportAiCombatCatalogueAction::strike`), so no unit or research is named here (gate 1). The rules follow
//! `GalaxyController::missileAttack`, `MissileMission` and `MoonDestructionMission`. Everything is deterministic:
//! the caller supplies the dice (a moon destruction's two rolls) and the fight's survivors.
//!
//! API for `world`:
//!
//! * `StrikeRules::load(path)` / `from_value(json)`: read the `strike` section of a `combat-u1*.json`.
//! * Missiles: `launch(&Launch) -> Result<Flight, Refusal>` (the controller's checks in its order, range from the
//!   impulse level, flight `30 + 60 * systems` seconds with no universe speed; the missiles leave the origin's stock at
//!   once) and, on arrival, `impact(&Impact) -> Struck` (interception, ABMs left on the target and its parent planet,
//!   the defences destroyed in the mission's priority order). The mission removes nothing else and has no return.
//! * Moon destruction: `resolve_moon_destruction` takes the fleet's fight (the engine's survivors; a fresh fight is
//!   `combat::run_engine(&combat::engine_input(..))`), the moon's diameter and the two rolls in 1..=100, and answers
//!   with the fight's loot and debris, what is left standing on the moon, whether it was attempted and destroyed, and the
//!   fleet that flies home (`None`: nothing flies). The return carries the mission's original cargo, not the loot.
use crate::combat::{defender_units, dice, repair_rate, roll_repairs, settle_in, Battle, CombatCatalogue, EngineResult, Units};
use serde::Deserialize;
use serde_json::Value;

/// A priority code's meaning: `cheapest` and `expensive` sort the defences by metal plus crystal, a unit goes first.
#[derive(Deserialize, Clone, Debug, PartialEq)]
#[serde(untagged)]
pub enum Priority {
    Mode(String),
    Unit(u32),
}

/// One defence as `MissileMission` reads it: the raw structural integrity (no armour bonus) and metal plus crystal.
#[derive(Deserialize, Clone, Debug)]
pub struct Defence {
    pub id: u32,
    pub structure: f64,
    pub cost: f64,
}

#[derive(Deserialize, Clone, Debug)]
pub struct Flying {
    pub base_seconds: i64,
    pub seconds_per_system: i64,
}

#[derive(Deserialize, Clone, Debug)]
pub struct Damage {
    pub per_missile: i64,
    pub weapon_bonus: f64,
    pub armour_bonus: f64,
    pub structure_divisor: f64,
}

#[derive(Deserialize, Clone, Debug)]
pub struct MoonRules {
    pub chance_base: f64,
    pub loss_divisor: f64,
    pub roll_max: i64,
}

#[derive(Deserialize, Clone, Debug)]
pub struct StrikeRules {
    pub missile: u32,
    pub interceptor: u32,
    pub weapon_research: u32,
    pub armour_research: u32,
    pub range_research: u32,
    pub range_by_level: Vec<i64>,
    pub priorities: Vec<Priority>,
    /// In the order the mission walks them before sorting (the sort is stable).
    pub defences: Vec<Defence>,
    pub flight: Flying,
    pub damage: Damage,
    pub moon_destroyer: u32,
    pub moon_destruction: MoonRules,
}

impl StrikeRules {
    pub fn load(path: &str) -> Result<StrikeRules, String> {
        let text = std::fs::read_to_string(path).map_err(|e| format!("{path}: {e}"))?;
        Self::from_value(&serde_json::from_str(&text).map_err(|e| format!("{path}: {e}"))?)
    }

    pub fn from_value(catalogue: &Value) -> Result<StrikeRules, String> {
        let section = catalogue.get("strike").ok_or("the catalogue has no strike section (re-run ai:rl-combat-catalogue)")?;
        serde_json::from_value(section.clone()).map_err(|e| e.to_string())
    }

    /// `PlayerService::getMissileRange`, tabulated by the host: systems a missile reaches at an impulse level.
    pub fn range(&self, impulse_level: u32) -> i64 {
        self.range_by_level[impulse_level as usize]
    }

    /// `calculateSystemDistance`: 0 within a system, the system gap in a galaxy, none across galaxies.
    pub fn distance(from: [i64; 3], to: [i64; 3]) -> Option<i64> {
        match (from[0] == to[0], from[1] == to[1]) {
            (false, _) => None,
            (true, true) => Some(0),
            (true, false) => Some((from[1] - to[1]).abs()),
        }
    }

    /// `(30 + 60 x systems)` seconds, with no universe speed (the controller leaves it a TODO).
    pub fn flight_seconds(&self, systems: i64) -> i64 {
        self.flight.base_seconds + self.flight.seconds_per_system * systems
    }
}

/// A launch as `missileAttack` receives it.
pub struct Launch {
    pub missiles: i64,
    /// The missiles on the launching planet.
    pub available: i64,
    pub target_exists: bool,
    pub own_target: bool,
    pub impulse_level: u32,
    pub from: [i64; 3],
    pub to: [i64; 3],
}

#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum Refusal {
    /// None asked for or none held ("Insufficient range ..." in the host's text).
    NoMissiles,
    NotEnough,
    NoTarget,
    OwnTarget,
    OutOfRange,
}

#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub struct Flight {
    pub systems: i64,
    pub seconds: i64,
}

/// `GalaxyController::missileAttack`'s checks in its order. Range 0 (no impulse drive) still reaches the own system.
pub fn launch(rules: &StrikeRules, order: &Launch) -> Result<Flight, Refusal> {
    if order.missiles <= 0 || order.available <= 0 {
        return Err(Refusal::NoMissiles);
    }
    if order.missiles > order.available {
        return Err(Refusal::NotEnough);
    }
    if !order.target_exists {
        return Err(Refusal::NoTarget);
    }
    if order.own_target {
        return Err(Refusal::OwnTarget);
    }
    let systems = StrikeRules::distance(order.from, order.to).filter(|&d| d <= rules.range(order.impulse_level)).ok_or(Refusal::OutOfRange)?;
    Ok(Flight { systems, seconds: rules.flight_seconds(systems) })
}

/// Missiles landing on a planet or moon.
pub struct Impact<'a> {
    pub missiles: i64,
    /// The launch form's code (0..=7 in the controller, 0..=9 decoded).
    pub priority: usize,
    pub weapon_level: u32,
    /// The defender's armour technology.
    pub armour_level: u32,
    /// Everything the target holds that is a defence, interceptors included.
    pub defence: &'a Units,
    /// The parent planet's interceptors when the target is a moon, else 0.
    pub parent_interceptors: i64,
}

#[derive(Clone, Debug, PartialEq)]
pub struct Struck {
    pub intercepted: i64,
    pub effective: i64,
    pub destroyed: Units,
    pub defence_after: Units,
    /// Interceptors left on the target and on the parent planet.
    pub interceptors_after: [i64; 2],
}

/// `MissileMission::processArrival`: interceptors meet missiles one for one (the parent's go first), the rest destroy
/// defences. Each defence in priority order loses `floor(power left / armour)` units, power being `missiles x damage x
/// (1 + weapon bonus x level)` and armour `structure x (1 + armour bonus x level) / 10`.
pub fn impact(rules: &StrikeRules, hit: &Impact) -> Struck {
    let held = hit.defence.get(&rules.interceptor).copied().unwrap_or(0);
    let intercepted = hit.missiles.min(held + hit.parent_interceptors);
    let effective = hit.missiles - intercepted;
    let from_parent = intercepted.min(hit.parent_interceptors);
    let from_target = intercepted - from_parent;

    let mut power = (effective * rules.damage.per_missile) as f64 * (1.0 + rules.damage.weapon_bonus * hit.weapon_level as f64);
    let mut destroyed = Units::new();
    for defence in sorted(rules, hit.priority) {
        if power <= 0.0 {
            break;
        }
        let count = hit.defence.get(&defence.id).copied().unwrap_or(0);
        if defence.id == rules.missile || defence.id == rules.interceptor || count <= 0 {
            continue;
        }
        let armour = defence.structure * (1.0 + rules.damage.armour_bonus * hit.armour_level as f64) / rules.damage.structure_divisor;
        let killed = ((power / armour).floor() as i64).min(count);
        if killed > 0 {
            destroyed.insert(defence.id, killed);
            power -= killed as f64 * armour;
        }
    }

    let mut defence_after = hit.defence.clone();
    for (&id, &n) in &destroyed {
        *defence_after.entry(id).or_insert(0) -= n;
    }
    if from_target > 0 {
        *defence_after.entry(rules.interceptor).or_insert(0) -= from_target;
    }
    defence_after.retain(|_, n| *n > 0);
    Struck { intercepted, effective, destroyed, defence_after, interceptors_after: [held - from_target, hit.parent_interceptors - from_parent] }
}

/// The defences in the order `sortDefensesByPriority` leaves them: by metal plus crystal up or down, or one unit first
/// and the rest by price up. A code the host does not know is the cheapest first.
fn sorted(rules: &StrikeRules, priority: usize) -> Vec<&Defence> {
    let mut defences: Vec<&Defence> = rules.defences.iter().collect();
    match rules.priorities.get(priority) {
        Some(Priority::Mode(mode)) if mode == "expensive" => defences.sort_by(|a, b| b.cost.total_cmp(&a.cost)),
        Some(Priority::Unit(first)) => defences.sort_by(|a, b| (b.id == *first).cmp(&(a.id == *first)).then(a.cost.total_cmp(&b.cost))),
        _ => defences.sort_by(|a, b| a.cost.total_cmp(&b.cost)),
    }
    defences
}

// ---------------------------------------------------------------------------------------------------------------------
// Moon destruction
// ---------------------------------------------------------------------------------------------------------------------

/// A planet's units without the missiles and interceptors, which the host's battle engines never take into a fight.
pub fn combatants(rules: &StrikeRules, planet: &[(u32, i64)]) -> Vec<(u32, i64)> {
    planet.iter().filter(|(id, _)| *id != rules.missile && *id != rules.interceptor).cloned().collect()
}

/// The chance in percent that the moon goes and that the whole destroyer fleet is lost, as the mission rolls against them.
#[derive(Clone, Copy, Debug, PartialEq)]
pub struct Chances {
    pub destroy: f64,
    pub loss: f64,
}

impl StrikeRules {
    /// `calculateMoonDestructionChance` and `calculateDeathstarLossChance`, both clamped to 0..=100.
    pub fn chances(&self, diameter: i64, destroyers: i64) -> Chances {
        let diameter = diameter.max(1) as f64;
        let rules = &self.moon_destruction;
        Chances {
            destroy: ((rules.chance_base - diameter.sqrt()) * (destroyers.max(0) as f64).sqrt()).clamp(0.0, 100.0),
            loss: (diameter.sqrt() / rules.loss_divisor).clamp(0.0, 100.0),
        }
    }
}

#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum Verdict {
    /// The attacker did not wipe the moon out: no attempt, the survivors go home.
    NotAttempted,
    /// The moon is gone (the destroyers may still have been lost).
    Destroyed,
    /// The moon stands, the fleet comes home.
    Failed,
    /// The moon stands and every destroyer is lost; the host sends nothing home.
    Catastrophic,
}

#[derive(Clone, Debug, PartialEq)]
pub struct MoonStrike {
    pub rounds: u32,
    pub verdict: Verdict,
    /// `None` when no attempt was made.
    pub chances: Option<Chances>,
    /// Destroyers that survived the fight, before the loss roll.
    pub destroyers_before: i64,
    pub moon_destroyed: bool,
    /// Taken from the moon's stock, as the fight's loot (the fleet does not fly it home).
    pub loot: [i64; 3],
    /// Into the debris field over the moon: nothing is hauled out of it.
    pub debris: [i64; 3],
    /// What stands on the moon after the fight; the moon repairs nothing.
    pub defender_left: Units,
    /// Each allied holding fleet that survived the battle, in arrival order.
    pub allies_left: Vec<Units>,
    /// The fleet that flies home, with the mission's original cargo; `None` flies nothing.
    pub return_fleet: Option<Units>,
}

/// `MoonDestructionMission::processArrival` for one attacking fleet, given the fight's survivors. `rolls` are the two
/// draws in `1..=roll_max` taken after the fight (destroy, then loss); they are used only when the attacker won.
pub fn resolve_moon_destruction(rules: &StrikeRules, attacker: &CombatCatalogue, defender: &CombatCatalogue, battle: &Battle, engine: EngineResult, diameter: i64, rolls: [i64; 2]) -> MoonStrike {
    // Missiles and interceptors do not fight (and so are never lost or repaired); the moon keeps them.
    let planet = combatants(rules, battle.defender);
    let battle = &Battle { defender: &planet, ..*battle };
    let fought = defender_units(defender, battle);
    let lost: Units = fought.iter().map(|(&id, &n)| (id, n - engine.defender_left.get(&id).copied().unwrap_or(0))).filter(|&(_, n)| n > 0).collect();
    let repaired = roll_repairs(defender, &lost, battle.defender_engineer, &mut dice(battle.seed, repair_rate(defender, battle.defender_engineer)));
    let rounds = engine.rounds;
    let attacker_left = engine.attacker_left.clone();
    let mut defender_left = engine.defender_left.clone();
    let allies_left = engine.allies_left.clone();
    for (&id, &n) in allies_left.iter().flatten() { *defender_left.entry(id).or_insert(0) -= n }
    defender_left.retain(|_, n| *n > 0);
    // Looting is the plunder of a fight; hauling debris out of the field is an attack's alone, so the field is settled
    // without it (`MoonDestructionMission` appends the engine's debris as it is).
    let won = !attacker_left.is_empty() && engine.defender_left.is_empty();
    let looted = settle_in(attacker, defender, battle, engine.clone(), repaired.clone(), 0, true);
    let field = settle_in(attacker, defender, battle, engine, repaired, 0, false);
    let mut strike = MoonStrike {
        rounds,
        verdict: Verdict::NotAttempted,
        chances: None,
        destroyers_before: attacker_left.get(&rules.moon_destroyer).copied().unwrap_or(0),
        moon_destroyed: false,
        loot: looted.loot,
        debris: field.debris,
        defender_left,
        allies_left,
        return_fleet: Some(attacker_left.clone()).filter(|fleet| !fleet.is_empty()),
    };
    if !won {
        return strike;
    }

    let chances = rules.chances(diameter, strike.destroyers_before);
    let destroyed = rolls[0] as f64 <= chances.destroy;
    let all_lost = rolls[1] as f64 <= chances.loss;
    let mut survivors = attacker_left;
    if all_lost {
        survivors.remove(&rules.moon_destroyer);
    }
    strike.chances = Some(chances);
    strike.moon_destroyed = destroyed;
    strike.verdict = match (destroyed, all_lost) {
        (true, _) => Verdict::Destroyed,
        (false, true) => Verdict::Catastrophic,
        (false, false) => Verdict::Failed,
    };
    strike.return_fleet = Some(survivors).filter(|fleet| strike.verdict != Verdict::Catastrophic && !fleet.is_empty());
    strike
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::combat::{CombatCatalogue, Units};

    const DATA: &str = "../../../../storage/rl/ogsim";

    fn catalogue_file(class: u64) -> String {
        let suffix = match class {
            2 => "-class2",
            3 => "-class3",
            _ => "",
        };
        format!("{DATA}/combat-u1{suffix}.json")
    }

    fn units(value: &Value) -> Units {
        value.as_object().map_or_else(Units::new, |m| m.iter().filter_map(|(id, n)| Some((id.parse().ok()?, n.as_i64()?))).filter(|&(_, n)| n > 0).collect())
    }

    fn research(player: &Value) -> crate::Levels {
        player["research"].as_object().unwrap().iter().map(|(id, level)| (id.parse().unwrap(), level.as_u64().unwrap() as u32)).collect()
    }

    fn coordinates(planet: &Value) -> [i64; 3] {
        let c = &planet["coordinates"];
        [c[0].as_i64().unwrap(), c[1].as_i64().unwrap(), c[2].as_i64().unwrap()]
    }

    fn cases(mission: &str) -> Option<(StrikeRules, Vec<Value>)> {
        let text = std::fs::read_to_string(format!("{DATA}/more-mission-cases-u1.json")).ok()?;
        let rules = StrikeRules::load(&catalogue_file(1)).ok()?;
        let all: Value = serde_json::from_str(&text).expect("more mission cases");
        let picked = all["cases"].as_array().unwrap().iter().filter(|c| c["mission"] == mission).cloned().collect();
        Some((rules, picked))
    }

    fn refusal(error: &str) -> Refusal {
        match error {
            "Target is out of missile range" => Refusal::OutOfRange,
            "You do not have enough missiles" => Refusal::NotEnough,
            "You cannot attack your own planet" => Refusal::OwnTarget,
            e if e.starts_with("Insufficient range") => Refusal::NoMissiles,
            e => panic!("a refusal the strike rules do not know: {e}"),
        }
    }

    /// One missile case against the host's answer; the first difference comes back as text.
    fn missile_case(rules: &StrikeRules, case: &Value) -> Result<(), String> {
        let input = &case["input"];
        let origin = &input["origin"];
        let missiles = input["missiles"].as_i64().unwrap();
        let own = origin["owner"] == input["target"]["owner"];
        let order = Launch {
            missiles,
            available: units(&origin["defence"]).get(&rules.missile).copied().unwrap_or(0),
            target_exists: !input["target"].is_null(),
            own_target: own,
            impulse_level: research(&input["origin_player"]).get(&rules.range_research).copied().unwrap_or(0),
            from: coordinates(origin),
            to: [0, 1, 2].map(|i| input["target_coordinates"][i].as_i64().unwrap()),
        };
        let dispatch = &case["dispatch"];
        let flight = launch(rules, &order);
        if !dispatch["accepted"].as_bool().unwrap() {
            let wanted = refusal(dispatch["error"].as_str().unwrap());
            return if flight == Err(wanted) { Ok(()) } else { Err(format!("refusal {flight:?}, host {wanted:?}")) };
        }
        let flight = flight.map_err(|r| format!("refused {r:?}, host flew it"))?;
        let arrival = &case["arrival"];
        let seconds = dispatch["arrival"].as_i64().unwrap() - dispatch["departure"].as_i64().unwrap();
        if flight.seconds != seconds || flight.systems != arrival["distance"].as_i64().unwrap() {
            return Err(format!("flight {flight:?}, host {seconds}s over {}", arrival["distance"]));
        }
        if rules.range(order.impulse_level) != arrival["range"].as_i64().unwrap() {
            return Err(format!("range {}, host {}", rules.range(order.impulse_level), arrival["range"]));
        }

        let defence = units(&arrival["defence_before"]);
        let parent = arrival["abm_before"][1].as_i64().unwrap();
        let target_player = &input["target_player"];
        let hit = Impact {
            missiles,
            priority: arrival["priority"].as_u64().unwrap() as usize,
            weapon_level: research(&input["origin_player"]).get(&rules.weapon_research).copied().unwrap_or(0),
            armour_level: research(target_player).get(&rules.armour_research).copied().unwrap_or(0),
            defence: &defence,
            parent_interceptors: parent,
        };
        let struck = impact(rules, &hit);
        let wanted = |key: &str| arrival[key].as_i64().unwrap();
        if struck.intercepted != wanted("intercepted") || struck.effective != wanted("effective") {
            return Err(format!("intercepted {} effective {}, host {} and {}", struck.intercepted, struck.effective, wanted("intercepted"), wanted("effective")));
        }
        let abm_after = [arrival["abm_after"][0].as_i64().unwrap(), arrival["abm_after"][1].as_i64().unwrap()];
        if struck.interceptors_after != abm_after {
            return Err(format!("interceptors left {:?}, host {abm_after:?}", struck.interceptors_after));
        }
        if struck.defence_after != units(&arrival["defence_after"]) {
            return Err(format!("defence left {:?}, host {:?} (priority {})", struck.defence_after, units(&arrival["defence_after"]), hit.priority));
        }
        Ok(())
    }

    #[test]
    fn replays_the_hosts_missile_strikes() {
        let Some((rules, cases)) = cases("missile") else { return eprintln!("no more-mission cases or strike section; skipped") };
        let mut matched = 0;
        let mut wrong = Vec::new();
        for case in &cases {
            match missile_case(&rules, case) {
                Ok(()) => matched += 1,
                Err(why) => wrong.push(format!("case {}: {why}", case["index"])),
            }
        }
        let flown = cases.iter().filter(|c| c["dispatch"]["accepted"] == true).count();
        eprintln!("strike missile: {matched}/{} cases matched ({flown} flown, {} refused)", cases.len(), cases.len() - flown);
        assert!(flown > 0, "no accepted missile case");
        assert!(wrong.is_empty(), "{} missile case(s) differ:\n{}", wrong.len(), wrong.join("\n"));
    }

    fn moon_case(rules: &StrikeRules, case: &Value) -> Result<(), String> {
        let dispatch = &case["dispatch"];
        if !dispatch["accepted"].as_bool().unwrap() {
            return Ok(());
        }
        let input = &case["input"];
        let (origin_player, target_player) = (&input["origin_player"], &input["target_player"]);
        let (attacker, defender) = (
            CombatCatalogue::load(&catalogue_file(origin_player["class"].as_u64().unwrap())).map_err(|e| e.to_string())?,
            CombatCatalogue::load(&catalogue_file(target_player["class"].as_u64().unwrap())).map_err(|e| e.to_string())?,
        );
        let arrival = &case["arrival"];
        let target = &arrival["target_before"];
        let mut fleet: Vec<(u32, i64)> = units(&input["units"]).into_iter().collect();
        fleet.sort_unstable();
        let mut planet = units(&target["ships"]);
        planet.extend(units(&target["defence"]));
        let planet: Vec<(u32, i64)> = planet.into_iter().collect();
        let (attacker_research, defender_research) = (research(origin_player), research(target_player));
        let mut cargo = [0, 1, 2].map(|i| input["cargo"][i].as_f64().unwrap());
        cargo[2] += dispatch["fuel"].as_f64().unwrap() / 2.0;
        let report = &arrival["battle"];
        let battle = Battle {
            attacker: &fleet,
            attacker_research: &attacker_research,
            attacker_cargo: cargo,
            defender: &planet,
            defender_research: &defender_research,
            allies: &[], partners: &[],
            defender_stock: [0, 1, 2].map(|i| target["resources"][i].as_f64().unwrap()),
            defender_engineer: false,
            moon_exists: true,
            seed: arrival["seed"].as_u64().unwrap(),
        };
        let engine = EngineResult { rounds: report["rounds"].as_u64().unwrap() as u32, attacker_left: units(&report["attacker_left"]), defender_left: units(&report["defender_left"]), allies_left: Vec::new(), partners_left: Vec::new() };
        let rolls = if arrival["rolls"].is_null() { [0, 0] } else { [arrival["rolls"]["destroy"].as_i64().unwrap(), arrival["rolls"]["loss"].as_i64().unwrap()] };
        let diameter = arrival["moon_before"]["diameter"].as_i64().unwrap();
        let strike = resolve_moon_destruction(rules, &attacker, &defender, &battle, engine, diameter, rolls);

        let attempted = arrival["attempted"].as_bool().unwrap();
        if (strike.verdict != Verdict::NotAttempted) != attempted {
            return Err(format!("attempted {:?}, host {attempted}", strike.verdict));
        }
        if strike.moon_destroyed == arrival["moon_exists_after"].as_bool().unwrap() {
            return Err(format!("moon destroyed {}, host says it still exists {}", strike.moon_destroyed, arrival["moon_exists_after"]));
        }
        if let Some(chances) = strike.chances {
            let host = (arrival["chance"]["destroy"].as_f64().unwrap(), arrival["chance"]["loss"].as_f64().unwrap());
            // serde_json without `float_roundtrip` reads a 17-digit double one ulp off, so the file's chance is compared to 1e-12.
            if (chances.destroy - host.0).abs() > 1e-12 || (chances.loss - host.1).abs() > 1e-12 {
                return Err(format!("chances {chances:?}, host {host:?}"));
            }
        }
        if strike.destroyers_before != arrival["deathstars_before"].as_i64().unwrap() {
            return Err(format!("destroyers {}, host {}", strike.destroyers_before, arrival["deathstars_before"]));
        }
        let home = strike.return_fleet.clone().unwrap_or_default();
        let after = home.get(&rules.moon_destroyer).copied().unwrap_or(0);
        if after != arrival["deathstars_after"].as_i64().unwrap() {
            return Err(format!("destroyers home {after}, host {}", arrival["deathstars_after"]));
        }
        let host_home = if arrival["return_mission"].is_null() { None } else { Some(units(&arrival["return_mission"]["units"])) };
        if strike.return_fleet != host_home {
            return Err(format!("return fleet {:?}, host {host_home:?}", strike.return_fleet));
        }
        let host_loot = [0, 1, 2].map(|i| report["loot"][i].as_i64().unwrap());
        if strike.loot != host_loot {
            return Err(format!("loot {:?}, host {host_loot:?}", strike.loot));
        }
        let host_debris = [0, 1, 2].map(|i| arrival["debris_after"][i].as_i64().unwrap());
        if strike.debris != host_debris {
            return Err(format!("debris {:?}, host {host_debris:?}", strike.debris));
        }
        if strike.defender_left != units(&report["defender_left"]) {
            return Err(format!("left on the moon {:?}, host {:?}", strike.defender_left, units(&report["defender_left"])));
        }
        if !strike.moon_destroyed {
            // What the host leaves out of the fight (missiles, interceptors) stays on the moon untouched.
            let fought = defender_units(&defender, &Battle { defender: &combatants(rules, &planet), ..battle });
            let mut stands = units(&arrival["target_after"]["ships"]);
            stands.extend(units(&arrival["target_after"]["defence"]));
            let mut kept = planet.iter().filter(|(id, _)| !fought.contains_key(id)).cloned().collect::<Vec<_>>();
            kept.sort_unstable();
            let mut extra: Vec<(u32, i64)> = stands.iter().filter(|(id, _)| !fought.contains_key(id)).map(|(&id, &n)| (id, n)).collect();
            extra.sort_unstable();
            stands.retain(|id, _| fought.contains_key(id));
            if stands != strike.defender_left || extra != kept {
                return Err(format!("moon after {stands:?} and {extra:?}, left {:?} and {kept:?}", strike.defender_left));
            }
        }
        Ok(())
    }

    #[test]
    fn replays_the_hosts_moon_destructions() {
        let Some((rules, cases)) = cases("moon_destruction") else { return eprintln!("no more-mission cases or strike section; skipped") };
        let mut matched = 0;
        let mut wrong = Vec::new();
        for case in &cases {
            match std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| moon_case(&rules, case))) {
                Ok(Ok(())) => matched += 1,
                Ok(Err(why)) => wrong.push(format!("case {}: {why}", case["index"])),
                Err(_) => wrong.push(format!("case {}: panicked", case["index"])),
            }
        }
        let flown = cases.iter().filter(|c| c["dispatch"]["accepted"] == true).count();
        eprintln!("strike moon destruction: {matched}/{} cases matched ({flown} flown, {} refused before launch)", cases.len(), cases.len() - flown);
        assert!(flown > 0, "no accepted moon destruction case");
        assert!(wrong.is_empty(), "{} moon destruction case(s) differ:\n{}", wrong.len(), wrong.join("\n"));
    }

    #[test]
    fn a_missile_with_no_impulse_drive_still_reaches_its_own_system() {
        let Ok(rules) = StrikeRules::load(&catalogue_file(1)) else { return eprintln!("no combat catalogue; skipped") };
        let order = |to: [i64; 3]| Launch { missiles: 1, available: 1, target_exists: true, own_target: false, impulse_level: 0, from: [1, 5, 3], to };
        assert_eq!(launch(&rules, &order([1, 5, 9])), Ok(Flight { systems: 0, seconds: 30 }));
        assert_eq!(launch(&rules, &order([1, 6, 9])), Err(Refusal::OutOfRange));
        assert_eq!(launch(&rules, &order([2, 5, 9])), Err(Refusal::OutOfRange));
    }
}
