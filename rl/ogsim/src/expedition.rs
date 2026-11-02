//! Expedition outcomes as the host draws them (world sim, plan/rl/WORLD-SIM-PLAN.md row W8): what an expedition finds,
//! loses or fights when it arrives, as pure functions of the sent fleet and a source of random draws. The decision order
//! is `ExpeditionMission::processArrival`'s, call for call, so a world that draws from `Mt19937` seeded like the host's
//! `Randomizer` reproduces the host's outcome exactly (the proof below replays every accepted host case).
//!
//! Every number and every object comes from the host (gate 1): the weights, brackets, multipliers and delay tables from
//! the `expedition` block of `php artisan ai:rl-combat-catalogue` (`ExportAiCombatCatalogueAction::expedition`), the
//! prices, capacities and fights from the same file's combat catalogue. This module names no ship and no research.
//!
//! API for `world`:
//!
//! * `Rules::load(path)` / `Rules::from_value(&json)`: the `expedition` block, valued for one account (its class
//!   multipliers and the Discoverer's halved pirate and alien weights are inside). Load the same file with
//!   `combat::CombatCatalogue::load`.
//! * `Mt19937::new(seed)`: PHP's `Random\Engine\Mt19937` with `Randomizer::getInt`, `shuffleArray` and the rest. It is
//!   the simulator's own random source: a world seeds one per mission from its stream. Anything that implements
//!   `Draws` (`int(min, max)` inclusive, or a closure) works, so a world may feed the draws it recorded instead.
//! * `Sent { fleet, carried, free_hold, rank1_points, flight_seconds, holding_seconds }` and `free_hold(...)`: what the
//!   host reads at arrival. `free_hold` is the fleet's cargo capacity less what it already carries.
//! * `arrive(&rules, &catalogue, &research, &sent, &mut draws) -> Found`: the whole outcome, a pirate or alien fight
//!   included (the host's Rust engine runs it, with a seed taken from `draws`). `roll(...)` stops short of a fight and
//!   returns `Roll::Fight(npc)`; `settle_fight(...)` finishes it from an engine result, which is how the host's PHP-engine
//!   cases are compared.
//! * `Found`: `kind` (what happened, after the host's fallbacks) and `rolled` (the dice's pick), `resources` found,
//!   `ships` found and `lost`, `dark_matter`, `return_delay` (seconds added to the return flight, negative for a
//!   speedup), `debris` (into the slot-16 field), `fight`. `Found::survivors`, `returns`, `return_seconds` and
//!   `cargo_home` say what flies back and when. A `merchant` only says the merchant was called; the host's
//!   `MerchantService` rolls the trader on its own.
//!
//! Host quirks mirrored on purpose: the holding time is `hours * 3600`, whatever the holding fleet speed; a delay is
//! divided by the peaceful fleet speed and a speedup is a share of the whole one-way trip plus the hold, so it can
//! outrun the remaining trip (the return then takes no time: `return_seconds` floors at zero); the `items` outcome
//! has no weight; a ships find that names no ship falls back to nothing; dark matter is the flat range times the
//! variant multiplier (the host never counts a Pathfinder there); a resource find is cut to the free hold, with crystal
//! two thirds and deuterium one third of the metal find; a fight's debris is a tenth of everything lost on both sides.
use crate::combat::{self, Battle, CombatCatalogue, EngineResult, Units};
use serde::Deserialize;
use serde_json::Value;
use crate::HashMap;

/// A source of the host's integer draws: `int(min, max)` is `Randomizer::getInt`, both ends included.
pub trait Draws {
    fn int(&mut self, min: i64, max: i64) -> i64;

    /// `Randomizer::shuffleArray` on a list: Fisher-Yates from the end with `getInt(0, i)`.
    fn shuffle(&mut self, list: &mut [u32]) {
        for last in (1..list.len()).rev() {
            let pick = self.int(0, last as i64) as usize;
            list.swap(last, pick);
        }
    }
}

impl<F: FnMut(i64, i64) -> i64> Draws for F {
    fn int(&mut self, min: i64, max: i64) -> i64 {
        self(min, max)
    }
}

/// PHP's `Random\Engine\Mt19937` (the standard MT19937 stream) behind `Randomizer`'s range, shuffle and pick.
pub struct Mt19937 {
    state: [u32; 624],
    index: usize,
}

impl Mt19937 {
    pub fn new(seed: u32) -> Mt19937 {
        let mut state = [0u32; 624];
        state[0] = seed;
        for i in 1..624 {
            state[i] = 1_812_433_253u32.wrapping_mul(state[i - 1] ^ (state[i - 1] >> 30)).wrapping_add(i as u32);
        }
        Mt19937 { state, index: 624 }
    }

    fn reload(&mut self) {
        for i in 0..624 {
            let y = (self.state[i] & 0x8000_0000) | (self.state[(i + 1) % 624] & 0x7fff_ffff);
            let twist = if y & 1 == 1 { 0x9908_b0df } else { 0 };
            self.state[i] = self.state[(i + 397) % 624] ^ (y >> 1) ^ twist;
        }
        self.index = 0;
    }

    pub fn next_u32(&mut self) -> u32 {
        if self.index >= 624 {
            self.reload();
        }
        let mut y = self.state[self.index];
        self.index += 1;
        y ^= y >> 11;
        y ^= (y << 7) & 0x9d2c_5680;
        y ^= (y << 15) & 0xefc6_0000;
        y ^ (y >> 18)
    }
}

impl Mt19937 {
    fn word64(&mut self) -> u64 {
        let low = self.next_u32() as u64;
        low | (self.next_u32() as u64) << 32
    }

    fn range64(&mut self, span: u64) -> u64 {
        let mut word = self.word64();
        if span == u64::MAX {
            return word;
        }
        let range = span + 1;
        if range & (range - 1) == 0 {
            return word & span;
        }
        let limit = u64::MAX - (u64::MAX % range) - 1;
        while word > limit {
            word = self.word64();
        }
        word % range
    }
}

impl Draws for Mt19937 {
    /// `php_random_range32`: one 32-bit word, masked for a power-of-two range and re-drawn above the unbiased limit
    /// otherwise. A wider range is `php_random_range64`'s two words, the first low.
    fn int(&mut self, min: i64, max: i64) -> i64 {
        let span = (max - min) as u64;
        if span > u32::MAX as u64 {
            return min.wrapping_add(self.range64(span) as i64);
        }
        let mut word = self.next_u32();
        if span == u32::MAX as u64 {
            return min + word as i64;
        }
        let range = span as u32 + 1;
        if range & (range - 1) == 0 {
            return min + (word & (range - 1)) as i64;
        }
        let limit = u32::MAX - (u32::MAX % range) - 1;
        while word > limit {
            word = self.next_u32();
        }
        min + (word % range) as i64
    }
}

#[derive(Deserialize, Clone, Debug)]
pub struct Reward {
    pub resources: f64,
    pub ships: f64,
    pub dark_matter: f64,
}

#[derive(Deserialize, Clone, Debug)]
pub struct DarkMatter {
    pub minimum: i64,
    pub maximum: i64,
    pub multiplier: f64,
}

#[derive(Deserialize, Clone, Debug)]
pub struct DelayTable {
    /// `[multiplier of the holding time, weight]`.
    pub weights: Vec<(i64, i64)>,
}

/// One kind of enemy (`NPCFleetGeneratorService`).
#[derive(Deserialize, Clone, Debug)]
pub struct NpcKind {
    /// Added to the player's weapon, shielding and armour levels (floored at 0).
    pub tech: i64,
    /// Whether the fleet's value rounds up; it rounds down otherwise.
    pub round_up: bool,
    /// By battle size: `[share of the player's fleet value, variance, bonus ship, bonus count]`.
    pub sizes: Vec<(f64, f64, u32, i64)>,
    /// `[player tier needed, ship]`, in the host's order (the pick is by position).
    pub pool: Vec<(u32, u32)>,
}

#[derive(Deserialize, Clone, Debug)]
pub struct Npc {
    pub size_weights: Vec<i64>,
    pub player_tiers: HashMap<String, u32>,
    pub extra_ship_chance: i64,
    pub extra_ship_count: (i64, i64),
    pub probe_chance: i64,
    pub probe: u32,
    pub cargo: Vec<u32>,
    pub kinds: HashMap<String, NpcKind>,
}

#[derive(Deserialize, Clone, Debug)]
pub struct FightRules {
    pub debris_rate: f64,
    /// Weapon, shielding and armour research ids: what an enemy's levels are set from.
    pub tech_research: [u32; 3],
    pub npc: Npc,
}

/// The `expedition` block of the combat catalogue.
#[derive(Deserialize, Clone, Debug)]
pub struct Rules {
    /// In the host's order, which the dice follow.
    pub outcome_weights: Vec<(String, f64)>,
    pub fleet_speed_peaceful: f64,
    pub reward_multiplier: Reward,
    pub class_resource_multiplier: f64,
    /// The class's expedition slots on top of the colonisation table's (a Discoverer's two); absent in older exports.
    #[serde(default)]
    pub class_slots: usize,
    /// `[weight, multiplier minimum, maximum]`; the first is the plain find and draws no multiplier.
    pub variants: Vec<(i64, i64, i64)>,
    /// `[rank-1 score the bracket stays below, largest metal find]`.
    pub find_brackets: Vec<(i64, i64)>,
    pub find_minimum_share: f64,
    pub resource_shares: [f64; 3],
    pub delay: DelayTable,
    pub speedup_percent: (i64, i64),
    pub dark_matter: DarkMatter,
    /// Ship tiers, lowest first; a find can name ships up to one tier above the fleet's best.
    pub ship_levels: Vec<Vec<u32>>,
    /// A ship that can be found only when the fleet holds at least this tier.
    pub ship_needs: HashMap<String, u32>,
    pub find_doubler: u32,
    pub ship_types_max: i64,
    pub ship_split: (f64, f64),
    pub fight: FightRules,
}

impl Rules {
    pub fn load(path: &str) -> Result<Rules, String> {
        let text = std::fs::read_to_string(path).map_err(|e| format!("{path}: {e}"))?;
        Self::from_value(&serde_json::from_str(&text).map_err(|e| format!("{path}: {e}"))?)
    }

    pub fn from_value(catalogue: &Value) -> Result<Rules, String> {
        let block = catalogue.get("expedition").ok_or("the catalogue has no expedition block (regenerate it with ai:rl-combat-catalogue)")?;
        serde_json::from_value(block.clone()).map_err(|e| e.to_string())
    }
}

/// Which outcome the dice picked.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum Kind {
    Nothing,
    Resources,
    Ships,
    DarkMatter,
    Delay,
    Speedup,
    Merchant,
    Pirates,
    Aliens,
    BlackHole,
}

impl Kind {
    pub fn named(name: &str) -> Option<Kind> {
        Some(match name {
            "nothing" => Kind::Nothing,
            "resources" => Kind::Resources,
            "ships" => Kind::Ships,
            "dark_matter" => Kind::DarkMatter,
            "delay" => Kind::Delay,
            "speedup" => Kind::Speedup,
            "merchant" => Kind::Merchant,
            "pirates" => Kind::Pirates,
            "aliens" => Kind::Aliens,
            "black_hole" => Kind::BlackHole,
            _ => return None,
        })
    }

    fn enemy(self) -> Option<&'static str> {
        match self {
            Kind::Pirates => Some("pirate"),
            Kind::Aliens => Some("alien"),
            _ => None,
        }
    }
}

/// What the host reads when the fleet arrives.
#[derive(Clone, Debug)]
pub struct Sent<'a> {
    pub fleet: &'a Units,
    /// What the fleet carries out (it flies home with all of it, whatever else happens).
    pub carried: [i64; 3],
    /// Cargo capacity less `carried`: what a resource or ships find is cut to.
    pub free_hold: i64,
    /// The best score in the highscore: it sets how much an expedition can find.
    pub rank1_points: i64,
    /// One way, at the dispatch speed.
    pub flight_seconds: i64,
    /// `fleet::holding_seconds(hours)`.
    pub holding_seconds: i64,
}

/// `UnitCollection::getTotalCargoCapacity` less the cargo the mission carries.
pub fn free_hold(catalogue: &CombatCatalogue, fleet: &Units, research: &HashMap<u32, u32>, carried: [i64; 3]) -> i64 {
    catalogue.capacity(fleet, research) - carried.iter().sum::<i64>()
}

/// An enemy the dice made (`NPCFleetGeneratorService::generateEnemyFleet`).
#[derive(Clone, Debug, PartialEq)]
pub struct Enemy {
    pub kind: Kind,
    pub fleet: Units,
    /// Weapon, shielding and armour levels.
    pub tech: [u32; 3],
}

/// A fight, settled.
#[derive(Clone, Debug, PartialEq)]
pub struct Fight {
    pub enemy: Enemy,
    pub rounds: u32,
    pub enemy_left: Units,
}

#[derive(Clone, Debug, PartialEq)]
pub struct Found {
    /// What happened, after the host's fallbacks.
    pub kind: Kind,
    /// What the dice picked (`Ships` that named no ship ends as `Nothing`).
    pub rolled: Kind,
    pub resources: [i64; 3],
    pub ships: Units,
    pub lost: Units,
    pub dark_matter: i64,
    pub return_delay: i64,
    /// Into the deep-space field at slot 16.
    pub debris: [i64; 3],
    pub fight: Option<Fight>,
}

impl Found {
    fn of(kind: Kind) -> Found {
        Found { kind, rolled: kind, resources: [0; 3], ships: Units::new(), lost: Units::new(), dark_matter: 0, return_delay: 0, debris: [0; 3], fight: None }
    }

    /// The fleet that flies home: what was sent and found, less what was lost.
    pub fn survivors(&self, sent: &Units) -> Units {
        let mut home = sent.clone();
        for (&id, &n) in &self.ships {
            *home.entry(id).or_insert(0) += n;
        }
        for (&id, &n) in &self.lost {
            *home.entry(id).or_insert(0) -= n;
        }
        home.retain(|_, n| *n > 0);
        home
    }

    /// Whether a return mission exists (the host starts none for an empty fleet).
    pub fn returns(&self, sent: &Units) -> bool {
        !self.survivors(sent).is_empty()
    }

    /// The return flight: the outward flight and the delay or speedup, never below zero (a speedup past the whole trip
    /// brings the fleet home at once).
    pub fn return_seconds(&self, flight_seconds: i64) -> i64 {
        (flight_seconds + self.return_delay).max(0)
    }

    /// The cargo that flies home: what it carried out and what it found.
    pub fn cargo_home(&self, carried: [i64; 3]) -> [i64; 3] {
        [carried[0] + self.resources[0], carried[1] + self.resources[1], carried[2] + self.resources[2]]
    }
}

/// What the dice made before any fight is fought.
#[derive(Clone, Debug, PartialEq)]
pub enum Roll {
    Done(Found),
    Fight(Enemy),
}

fn one(id: u32, n: i64) -> Units {
    Units::from([(id, n)])
}

/// A unit's build price in all three resources (`price->resources->sum()`).
fn price(catalogue: &CombatCatalogue, id: u32) -> i64 {
    catalogue.price_of(&one(id, 1)).iter().sum()
}

/// `AppUtil::selectWeightedRandom`: one `getInt(1, total)` and the first cumulative weight at or above it.
fn weighted(weights: &[i64], draws: &mut dyn Draws) -> usize {
    let total: i64 = weights.iter().sum();
    let roll = draws.int(1, total.max(1));
    let mut cumulative = 0;
    for (index, weight) in weights.iter().enumerate() {
        cumulative += weight;
        if roll <= cumulative {
            return index;
        }
    }
    0
}

/// `selectRandomOutcome`: weights times ten as whole numbers, so the small ones stay reachable.
fn pick_outcome(rules: &Rules, draws: &mut dyn Draws) -> Kind {
    let enabled: Vec<(Kind, f64)> = rules.outcome_weights.iter().filter(|(_, w)| *w > 0.0).filter_map(|(name, w)| Some((Kind::named(name)?, *w))).collect();
    let total: f64 = enabled.iter().fold(0.0, |sum, (_, w)| sum + w);
    if enabled.is_empty() || total <= 0.0 {
        return Kind::Nothing;
    }
    let roll = draws.int(1, (total * 10.0).round() as i64);
    let mut cumulative = 0;
    for (kind, weight) in enabled {
        cumulative += (weight * 10.0).round() as i64;
        if roll <= cumulative {
            return kind;
        }
    }
    Kind::Nothing
}

/// `selectExpeditionFindVariant`: the multiplier of a normal, rare or exceptional find.
fn variant_multiplier(rules: &Rules, draws: &mut dyn Draws) -> i64 {
    let weights: Vec<i64> = rules.variants.iter().map(|v| v.0).collect();
    let index = weighted(&weights, draws);
    let (_, low, high) = rules.variants[index];
    if index == 0 {
        return low;
    }
    draws.int(low, high)
}

/// `getBaseMaxFindFromHighscore`: a draw between a tenth of the bracket's largest find and all of it.
fn base_find(rules: &Rules, sent: &Sent, draws: &mut dyn Draws) -> i64 {
    let largest = rules.find_brackets.iter().find(|(below, _)| sent.rank1_points < *below).or(rules.find_brackets.last()).map_or(0, |b| b.1);
    let smallest = ((largest as f64 * rules.find_minimum_share).floor() as i64).max(1);
    draws.int(smallest, largest)
}

/// `determineMaxResourceFind` and `determineMaxShipFind`: the draw, the class multiplier, the doubling for the find
/// doubler in the fleet and the reward setting, each cut to a whole number where the host casts.
fn max_find(rules: &Rules, sent: &Sent, reward: f64, draws: &mut dyn Draws) -> i64 {
    let mut amount = (base_find(rules, sent, draws) as f64 * rules.class_resource_multiplier) as i64;
    if sent.fleet.get(&rules.find_doubler).copied().unwrap_or(0) > 0 {
        amount *= 2;
    }
    (amount as f64 * reward) as i64
}

fn resources_found(rules: &Rules, sent: &Sent, draws: &mut dyn Draws) -> Found {
    let multiplier = variant_multiplier(rules, draws);
    let most = (max_find(rules, sent, rules.reward_multiplier.resources, draws) as f64 * multiplier as f64) as i64;
    let kind = draws.int(0, 2) as usize;
    let mut found = Found::of(Kind::Resources);
    let wanted = most as f64 * rules.resource_shares[kind];
    found.resources[kind] = (sent.free_hold as f64).min(wanted) as i64;
    found
}

/// The ships a find can name for this fleet, in the host's order.
fn findable(rules: &Rules, fleet: &Units) -> Vec<u32> {
    let tier_of = |id: u32| rules.ship_levels.iter().position(|ids| ids.contains(&id)).map_or(0, |index| index as u32 + 1);
    let best = fleet.keys().map(|&id| tier_of(id)).max().unwrap_or(0).max(1);
    let top = (best as usize + 1).min(rules.ship_levels.len());
    rules.ship_levels[..top].iter().flatten().copied().filter(|id| rules.ship_needs.get(&id.to_string()).map_or(true, |&needs| best >= needs)).collect()
}

/// A share of the find for each of a few drawn ship types, the last taking what is left (`processExpeditionGainShipsOutcome`).
fn ships_found(rules: &Rules, catalogue: &CombatCatalogue, sent: &Sent, draws: &mut dyn Draws) -> Found {
    let mut possible = findable(rules, sent.fleet);
    if possible.is_empty() {
        return Found { rolled: Kind::Ships, ..Found::of(Kind::Nothing) };
    }
    let multiplier = variant_multiplier(rules, draws);
    let most = (max_find(rules, sent, rules.reward_multiplier.ships, draws) as f64 * multiplier as f64) as i64;
    let budget = sent.free_hold.min(most).max(0);
    let types = draws.int(1, rules.ship_types_max).min(possible.len() as i64) as usize;
    draws.shuffle(&mut possible);
    let chosen = &possible[..types];

    let average = budget as f64 / types as f64;
    let mut remaining = budget;
    let mut ships = Units::new();
    for (index, &id) in chosen.iter().enumerate() {
        let share = match index + 1 < types {
            true => remaining.min(draws.int((average * rules.ship_split.0).round() as i64, (average * rules.ship_split.1).round() as i64)),
            false => remaining,
        };
        let amount = (share as f64 / price(catalogue, id) as f64).floor() as i64;
        if amount > 0 {
            *ships.entry(id).or_insert(0) += amount;
        }
        remaining = (remaining - share).max(0);
    }
    if ships.is_empty() {
        let cheapest = possible.iter().copied().fold(None, |best: Option<u32>, id| match best {
            Some(held) if price(catalogue, id) >= price(catalogue, held) => Some(held),
            _ => Some(id),
        });
        if let Some(id) = cheapest.filter(|&id| budget >= price(catalogue, id)) {
            ships.insert(id, 1);
        }
    }
    if ships.is_empty() {
        return Found { rolled: Kind::Ships, ..Found::of(Kind::Nothing) };
    }
    Found { ships, ..Found::of(Kind::Ships) }
}

fn delay_found(rules: &Rules, sent: &Sent, draws: &mut dyn Draws) -> Found {
    let weights: Vec<i64> = rules.delay.weights.iter().map(|w| w.1).collect();
    let multiplier = rules.delay.weights[weighted(&weights, draws)].0;
    Found { return_delay: ((sent.holding_seconds * multiplier) as f64 / rules.fleet_speed_peaceful) as i64, ..Found::of(Kind::Delay) }
}

fn speedup_found(rules: &Rules, sent: &Sent, draws: &mut dyn Draws) -> Found {
    let percent = draws.int(rules.speedup_percent.0, rules.speedup_percent.1);
    let trip = sent.flight_seconds + sent.holding_seconds;
    Found { return_delay: -((trip as f64 * (percent as f64 / 100.0)) as i64), ..Found::of(Kind::Speedup) }
}

fn dark_matter_found(rules: &Rules, draws: &mut dyn Draws) -> Found {
    let multiplier = variant_multiplier(rules, draws);
    let reward = &rules.dark_matter;
    let base = (draws.int(reward.minimum, reward.maximum) as f64 * reward.multiplier) as i64;
    let amount = (((base * multiplier) as f64) * rules.reward_multiplier.dark_matter) as i64;
    Found { dark_matter: amount, ..Found::of(Kind::DarkMatter) }
}

/// The fleet's best tier for the enemy's pool: one of the host's four, the deathstar's winning outright.
fn player_tier(npc: &Npc, fleet: &Units) -> u32 {
    fleet.keys().map(|id| npc.player_tiers.get(&id.to_string()).copied().unwrap_or(1)).max().unwrap_or(1)
}

/// `generateEnemyFleet`: the battle size, the value share with its variance, 1-2 combat ships, a probe, cargo ships for
/// the rest, and the bonus ships on top.
fn enemy(rules: &Rules, catalogue: &CombatCatalogue, research: &HashMap<u32, u32>, sent: &Sent, kind: Kind, draws: &mut dyn Draws) -> Enemy {
    let npc = &rules.fight.npc;
    let model = &npc.kinds[kind.enemy().expect("an enemy kind")];
    let value: i64 = catalogue.price_of(sent.fleet).iter().sum();
    let size = weighted(&npc.size_weights, draws);
    let tier = player_tier(npc, sent.fleet);
    let levels = rules.fight.tech_research.map(|id| (research.get(&id).copied().unwrap_or(0) as i64 + model.tech).max(0) as u32);

    let (base, variance, bonus, bonus_count) = model.sizes[size];
    let share = base + (draws.int(-100, 100) as f64 / 100.0) * variance;
    let worth = match model.round_up {
        true => (value as f64 * share).ceil() as i64,
        false => (value as f64 * share).floor() as i64,
    };

    let mut fleet = Units::new();
    let mut remaining = worth;
    if draws.int(1, 100) <= npc.extra_ship_chance {
        let count = draws.int(npc.extra_ship_count.0, npc.extra_ship_count.1);
        let pool: Vec<u32> = model.pool.iter().filter(|(needs, _)| tier >= *needs).map(|&(_, id)| id).collect();
        for _ in 0..count {
            let id = pool[draws.int(0, pool.len() as i64 - 1) as usize];
            if remaining >= price(catalogue, id) {
                *fleet.entry(id).or_insert(0) += 1;
                remaining -= price(catalogue, id);
            }
        }
    }
    if draws.int(1, 100) <= npc.probe_chance && remaining >= price(catalogue, npc.probe) {
        *fleet.entry(npc.probe).or_insert(0) += 1;
        remaining -= price(catalogue, npc.probe);
    }
    let cargo = npc.cargo[0];
    let cargo_ships = (remaining as f64 / price(catalogue, cargo) as f64).floor() as i64;
    if cargo_ships > 0 {
        *fleet.entry(cargo).or_insert(0) += cargo_ships;
    }
    *fleet.entry(bonus).or_insert(0) += bonus_count;
    Enemy { kind, fleet, tech: levels }
}

/// The weapon, shielding and armour levels as the engine's research map.
fn tech_map(rules: &Rules, tech: [u32; 3]) -> HashMap<u32, u32> {
    rules.fight.tech_research.iter().copied().zip(tech).collect()
}

/// A fight after the engine: the fleet that survived, the enemy that is left, and the debris a tenth of every loss makes.
/// There is no loot, no repair and no moon.
pub fn settle_fight(rules: &Rules, catalogue: &CombatCatalogue, sent: &Sent, enemy: Enemy, engine: EngineResult) -> Found {
    let kind = enemy.kind;
    let lost: Units = sent.fleet.iter().map(|(&id, &n)| (id, n - engine.attacker_left.get(&id).copied().unwrap_or(0))).filter(|&(_, n)| n > 0).collect();
    let enemy_lost: Units = enemy.fleet.iter().map(|(&id, &n)| (id, n - engine.defender_left.get(&id).copied().unwrap_or(0))).filter(|&(_, n)| n > 0).collect();
    let mut debris = [0i64; 3];
    for (&id, &n) in lost.iter().chain(enemy_lost.iter()) {
        let price = catalogue.price_of(&one(id, n));
        for r in 0..if catalogue.rules.debris_deuterium { 3 } else { 2 } {
            debris[r] += (price[r] as f64 * rules.fight.debris_rate).floor() as i64;
        }
    }
    let fight = Fight { enemy, rounds: engine.rounds, enemy_left: engine.defender_left };
    Found { lost, debris, fight: Some(fight), ..Found::of(kind) }
}

/// The dice up to a fight: everything that does not need the engine is settled, a fight is returned as its enemy.
pub fn roll(rules: &Rules, catalogue: &CombatCatalogue, research: &HashMap<u32, u32>, sent: &Sent, draws: &mut dyn Draws) -> Roll {
    let rolled = pick_outcome(rules, draws);
    let found = match rolled {
        Kind::Resources => resources_found(rules, sent, draws),
        Kind::Ships => ships_found(rules, catalogue, sent, draws),
        Kind::DarkMatter => dark_matter_found(rules, draws),
        Kind::Delay => delay_found(rules, sent, draws),
        Kind::Speedup => speedup_found(rules, sent, draws),
        Kind::BlackHole => Found { lost: sent.fleet.clone(), ..Found::of(Kind::BlackHole) },
        Kind::Pirates | Kind::Aliens => return Roll::Fight(enemy(rules, catalogue, research, sent, rolled, draws)),
        Kind::Nothing | Kind::Merchant => Found::of(rolled),
    };
    Roll::Done(Found { rolled, ..found })
}

/// A whole arrival from the draws: `roll`, and a fight run through the host's Rust battle engine with a seed from `draws`.
pub fn arrive(rules: &Rules, catalogue: &CombatCatalogue, research: &HashMap<u32, u32>, sent: &Sent, draws: &mut dyn Draws) -> Found {
    arrive_with(rules, catalogue, research, sent, draws, None)
}

/// `arrive`, with a fight the referee already settled (the engine's result from the player's side, as the host's PHP
/// engine produced it) instead of a fresh one.
pub fn arrive_with(rules: &Rules, catalogue: &CombatCatalogue, research: &HashMap<u32, u32>, sent: &Sent, draws: &mut dyn Draws, recorded: Option<EngineResult>) -> Found {
    match roll(rules, catalogue, research, sent, draws) {
        Roll::Done(found) => found,
        Roll::Fight(enemy) => {
            let rolled = enemy.kind;
            if let Some(engine) = recorded {
                return Found { rolled, ..settle_fight(rules, catalogue, sent, enemy, engine) };
            }
            // BattleEngine::simulateBattle draws its seed from the mission's stream.
            let seed = draws.int(0, i64::MAX) as u64;
            let ours: Vec<(u32, i64)> = sent.fleet.iter().map(|(&id, &n)| (id, n)).collect();
            let theirs: Vec<(u32, i64)> = enemy.fleet.iter().map(|(&id, &n)| (id, n)).collect();
            let tech = tech_map(rules, enemy.tech);
            let battle = Battle { attacker: &ours, attacker_research: research, attacker_cargo: [0.0; 3], defender: &theirs, defender_research: &tech, allies: &[], defender_stock: [0.0; 3], defender_engineer: false, moon_exists: true, seed };
            let engine = combat::run_engine(&combat::engine_input(catalogue, catalogue, &battle));
            Found { rolled, ..settle_fight(rules, catalogue, sent, enemy, engine) }
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::fleet;
    use std::collections::BTreeMap;

    /// The host's cases and the combat catalogues that carry the `expedition` block. The block is new, so the catalogues
    /// in the data directory are used once they have it; `OGSIM_EXPEDITION_DATA` points at a directory that does.
    const DATA: &str = "../../../../storage/rl/ogsim";

    #[derive(Deserialize)]
    struct Cases {
        settings: Settings,
        cases: Vec<Value>,
    }

    #[derive(Deserialize)]
    struct Settings {
        fleet_speed_peaceful: i64,
    }

    #[derive(Deserialize)]
    struct Case {
        index: i64,
        mission: String,
        case_seed: u32,
        input: Input,
        dispatch: Dispatch,
        arrival: Option<Arrival>,
    }

    #[derive(Deserialize)]
    struct Input {
        origin_player: Player,
        units: HashMap<String, i64>,
        cargo: [i64; 3],
        holding_hours: i64,
        rank1_points: i64,
    }

    #[derive(Deserialize)]
    struct Player {
        class: u32,
        research: HashMap<String, u32>,
    }

    #[derive(Deserialize)]
    struct Dispatch {
        accepted: bool,
        departure: Option<i64>,
        arrival: Option<i64>,
        holding_seconds: Option<i64>,
    }

    #[derive(Deserialize)]
    struct Arrival {
        time: i64,
        outcome: String,
        found_resources: [i64; 3],
        found_ships: HashMap<String, i64>,
        #[serde(default)]
        user_changes: Value,
        debris_slot16: Option<[i64; 3]>,
        return_delay: Option<i64>,
        return_mission: Option<Flight>,
        battle: Option<Fought>,
        holding_seconds: i64,
    }

    #[derive(Deserialize)]
    struct Flight {
        departure: i64,
        arrival: i64,
        units: HashMap<String, i64>,
        cargo: [i64; 3],
    }

    #[derive(Deserialize)]
    struct Fought {
        report: Report,
    }

    /// The report from the player's side: the enemy is the attacker.
    #[derive(Deserialize)]
    struct Report {
        attacker: Side,
        defender: Side,
        attacker_left: Value,
        defender_left: Value,
        rounds: Option<Value>,
    }

    #[derive(Deserialize)]
    struct Side {
        units: HashMap<String, i64>,
        weapon: u32,
        shield: u32,
        armour: u32,
    }

    fn units(map: &HashMap<String, i64>) -> Units {
        map.iter().filter(|(_, &n)| n != 0).map(|(id, &n)| (id.parse().unwrap(), n)).collect()
    }

    fn counted(value: &Value) -> Units {
        value.as_object().map_or_else(Units::new, |m| m.iter().filter_map(|(id, n)| Some((id.parse().ok()?, n.as_i64()?))).filter(|&(_, n)| n > 0).collect())
    }

    fn files(class: u32) -> (CombatCatalogue, Rules) {
        let suffix = match class {
            2 => "-class2",
            3 => "-class3",
            _ => "",
        };
        let dir = std::env::var("OGSIM_EXPEDITION_DATA").unwrap_or_else(|_| DATA.to_string());
        let path = format!("{dir}/combat-u1{suffix}.json");
        (CombatCatalogue::load(&path).expect("combat catalogue"), Rules::load(&path).expect("expedition block"))
    }

    fn kind_of(name: &str) -> Kind {
        Kind::named(name).unwrap_or_else(|| panic!("unknown outcome {name}"))
    }

    /// Plays one accepted case from its seed and compares everything the host recorded; the reasons come back as text.
    fn replay(case: &Case, peaceful: i64, catalogues: &mut HashMap<u32, (CombatCatalogue, Rules)>) -> Vec<String> {
        let (dispatch, arrival) = (&case.dispatch, case.arrival.as_ref().expect("an accepted case has an arrival"));
        let (catalogue, rules) = catalogues.entry(case.input.origin_player.class).or_insert_with(|| files(case.input.origin_player.class));
        let research: HashMap<u32, u32> = case.input.origin_player.research.iter().map(|(id, &l)| (id.parse().unwrap(), l)).collect();
        let fleet = units(&case.input.units);
        let flight = dispatch.arrival.unwrap() - dispatch.departure.unwrap();
        let sent = Sent {
            fleet: &fleet,
            carried: case.input.cargo,
            free_hold: free_hold(catalogue, &fleet, &research, case.input.cargo),
            rank1_points: case.input.rank1_points,
            flight_seconds: flight,
            holding_seconds: fleet::holding_seconds(case.input.holding_hours),
        };
        let mut wrong = Vec::new();
        let mut say = |what: &str, ours: String, host: String| {
            if ours != host {
                wrong.push(format!("{what}: ours {ours} host {host}"));
            }
        };
        assert_eq!(rules.fleet_speed_peaceful as i64, peaceful, "the catalogue's peaceful fleet speed is the universe's");

        let mut draws = Mt19937::new(case.case_seed);
        let found = match roll(rules, catalogue, &research, &sent, &mut draws) {
            Roll::Done(found) => found,
            Roll::Fight(enemy) => {
                // The host fought with its PHP engine, so the fight itself is its record: the enemy the dice made must be
                // the enemy it met, and the arithmetic after the fight is settled from the survivors it recorded.
                let report = &arrival.battle.as_ref().expect("a fight case has a report").report;
                say("enemy fleet", format!("{:?}", enemy.fleet), format!("{:?}", units(&report.attacker.units)));
                say("enemy tech", format!("{:?}", enemy.tech), format!("{:?}", [report.attacker.weapon, report.attacker.shield, report.attacker.armour]));
                say("player fleet in the report", format!("{:?}", fleet), format!("{:?}", units(&report.defender.units)));
                let engine = EngineResult { rounds: report.rounds.as_ref().and_then(|r| r.as_u64()).unwrap_or(1) as u32, attacker_left: counted(&report.defender_left), defender_left: counted(&report.attacker_left), allies_left: Vec::new() };
                settle_fight(rules, catalogue, &sent, enemy, engine)
            }
        };

        let host_kind = match (arrival.outcome.as_str(), arrival.found_ships.is_empty()) {
            ("ships", true) => Kind::Nothing,
            (name, _) => kind_of(name),
        };
        say("outcome", format!("{:?}", found.kind), format!("{host_kind:?}"));
        say("resources found", format!("{:?}", found.resources), format!("{:?}", arrival.found_resources));
        let net: Units = found.ships.iter().map(|(&id, &n)| (id, n)).chain(found.lost.iter().map(|(&id, &n)| (id, -n))).collect::<BTreeMap<_, _>>();
        say("ships found less ships lost", format!("{net:?}"), format!("{:?}", units(&arrival.found_ships)));
        let dark = arrival.user_changes.get("dark_matter").map_or(0, |pair| pair[1].as_i64().unwrap() - pair[0].as_i64().unwrap());
        say("dark matter", found.dark_matter.to_string(), dark.to_string());
        say("holding seconds", sent.holding_seconds.to_string(), dispatch.holding_seconds.unwrap().to_string());
        say("holding seconds recorded at arrival", arrival.holding_seconds.to_string(), sent.holding_seconds.to_string());
        say("arrival time is the flight and the hold", (dispatch.arrival.unwrap() + sent.holding_seconds).to_string(), arrival.time.to_string());
        say("debris at slot 16", format!("{:?}", found.debris), format!("{:?}", arrival.debris_slot16.unwrap_or([0; 3])));

        let home = found.survivors(&fleet);
        match &arrival.return_mission {
            None => {
                say("a return mission exists", found.returns(&fleet).to_string(), "false".into());
            }
            Some(back) => {
                say("a return mission exists", found.returns(&fleet).to_string(), "true".into());
                say("return delay", found.return_delay.to_string(), arrival.return_delay.unwrap().to_string());
                say("return flight", (flight + found.return_delay).to_string(), (back.arrival - back.departure).to_string());
                // A speedup past the whole trip is a negative flight on the host: the fleet is home the moment it is due.
                say("return flight, floored", found.return_seconds(flight).to_string(), (back.arrival - back.departure).max(0).to_string());
                say("return departs at the end of the hold", (arrival.time).to_string(), back.departure.to_string());
                say("fleet home", format!("{home:?}"), format!("{:?}", units(&back.units)));
                say("cargo home", format!("{:?}", found.cargo_home(sent.carried)), format!("{:?}", back.cargo));
            }
        }
        wrong
    }

    /// Every accepted host expedition: the dice, from the case's own seed, give the host's outcome and every quantity it
    /// changed. The host's recorded draws (`outcome_roll`, `variant_roll`, ...) are the same stream, so a replay that
    /// matches the outcome and the amounts has used them in the host's order.
    #[test]
    fn replays_the_hosts_expeditions() {
        let dir = std::env::var("OGSIM_EXPEDITION_DATA").unwrap_or_else(|_| DATA.to_string());
        let Ok(text) = std::fs::read_to_string(format!("{DATA}/more-mission-cases-u1.json")) else { return eprintln!("no more-mission cases; skipped") };
        if Rules::load(&format!("{dir}/combat-u1.json")).is_err() {
            return eprintln!("the combat catalogue has no expedition block; regenerate it (ai:rl-combat-catalogue) or set OGSIM_EXPEDITION_DATA; skipped");
        }
        let cases: Cases = serde_json::from_str(&text).expect("more-mission cases");
        let mut catalogues = HashMap::default();
        let (mut matched, mut total) = (0usize, 0usize);
        let mut by_kind: BTreeMap<String, (usize, usize)> = BTreeMap::new();
        let mut reasons: Vec<String> = Vec::new();
        let expeditions = cases.cases.iter().filter(|c| c["mission"] == "expedition").map(|c| serde_json::from_value::<Case>(c.clone()).expect("an expedition case"));
        for case in expeditions.filter(|c| c.dispatch.accepted) {
            total += 1;
            let case = &case;
            let wrong = replay(case, cases.settings.fleet_speed_peaceful, &mut catalogues);
            let entry = by_kind.entry(case.arrival.as_ref().unwrap().outcome.clone()).or_default();
            entry.1 += 1;
            match wrong.is_empty() {
                true => {
                    matched += 1;
                    entry.0 += 1;
                }
                false => reasons.push(format!("case {} ({}): {}", case.index, case.arrival.as_ref().unwrap().outcome, wrong.join("; "))),
            }
        }
        for (kind, (ok, all)) in &by_kind {
            eprintln!("  {kind}: {ok}/{all}");
        }
        eprintln!("expedition parity: {matched}/{total} accepted cases matched the host");
        reasons.iter().for_each(|r| eprintln!("  {r}"));
        assert!(total > 0, "no accepted expedition case");
        assert_eq!(matched, total, "the simulator plays these expeditions differently from the host");
    }

    /// The simulator's own run: dice from its own stream, a fight through the Rust engine, and the invariants of any
    /// outcome (nothing negative, a find fits the hold, a fleet that is gone does not return).
    #[test]
    fn plays_expeditions_without_a_referee() {
        let dir = std::env::var("OGSIM_EXPEDITION_DATA").unwrap_or_else(|_| DATA.to_string());
        let path = format!("{dir}/combat-u1.json");
        let (Ok(catalogue), Ok(rules)) = (CombatCatalogue::load(&path), Rules::load(&path)) else { return eprintln!("no expedition catalogue; skipped") };
        let research: HashMap<u32, u32> = [(109, 8), (110, 8), (111, 8), (114, 6)].into_iter().collect();
        let fleet: Units = rules.ship_levels.iter().flatten().take(4).map(|&id| (id, 300)).collect();
        let hold = free_hold(&catalogue, &fleet, &research, [0; 3]);
        let sent = Sent { fleet: &fleet, carried: [0; 3], free_hold: hold, rank1_points: 3_000_000, flight_seconds: 1500, holding_seconds: 7200 };
        let mut seen: BTreeMap<String, usize> = BTreeMap::new();
        for seed in 0..4000u32 {
            let found = arrive(&rules, &catalogue, &research, &sent, &mut Mt19937::new(seed));
            *seen.entry(format!("{:?}", found.kind)).or_default() += 1;
            assert!(found.resources.iter().all(|&r| r >= 0) && found.resources.iter().sum::<i64>() <= hold, "seed {seed}: {found:?}");
            assert!(found.lost.iter().all(|(id, &n)| n > 0 && n <= fleet[id]), "seed {seed}: more lost than sent");
            let home = found.survivors(&fleet);
            assert_eq!(found.returns(&fleet), !home.is_empty());
            assert!(found.return_seconds(1500) >= 0);
            if found.kind == Kind::BlackHole {
                assert!(home.is_empty());
            }
        }
        eprintln!("4000 simulated arrivals: {seen:?}");
        for kind in ["Nothing", "Resources", "Ships", "DarkMatter", "Delay", "Speedup", "Pirates", "Aliens"] {
            assert!(seen.contains_key(kind), "{kind} never came up");
        }
    }

    /// The stream is PHP's: the first draws of seed 102000307 are the ones the host recorded for case 301.
    #[test]
    fn the_stream_is_phps() {
        let mut draws = Mt19937::new(102_000_307);
        assert_eq!(draws.int(1, 976), 708);
        let mut list = [0u32, 1, 2, 3, 4, 5, 6, 7, 8];
        Mt19937::new(5).shuffle(&mut list);
        assert_eq!(list, [0, 5, 4, 7, 1, 3, 2, 6, 8], "PHP's Randomizer::shuffleArray on the same seed");
        // A battle's seed: PHP's getInt(0, PHP_INT_MAX) on these seeds.
        for (seed, battle) in [(1, 9_171_440_914_760_070_181), (7, 4_193_665_733_827_817_647), (123_456, 275_101_013_860_489_281)] {
            assert_eq!(Mt19937::new(seed).int(0, i64::MAX), battle);
        }
    }
}
