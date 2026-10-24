//! ogsim: OGame's economy as equations in time, played from one event to the next (plan/rl).
//!
//! Every number comes from the host through `ai:rl-catalogue`, so this crate names no object (Modules/AI/AGENTS.md,
//! gate 1). Only the rules that combine the tables live here, and they follow `PlanetService`: production per object
//! at a 100% factor, an energy factor floored to whole percent, totals rounded up, accrual stopped at the store's cap
//! (`updateResourcesUntil`), and a producer that burns deuterium giving no energy on a planet that has run dry
//! (`updateResourceProductionStatsInner`).

use serde::Deserialize;
use std::collections::HashMap;

#[cfg(feature = "python")]
mod python;

/// Metal-equivalent weights of the closed-loop value (plan/rl/reward-design.md §2), so the simulator and the PHP
/// evaluation score the same thing.
pub const WEIGHTS: [f64; 3] = [1.0, 1.5, 2.0];
pub const STATE_DIM: usize = 18;
pub const CAND_DIM: usize = 14;
const NEVER: f64 = 1e9;
const NO_PAYBACK_HOURS: f64 = 1e5;

#[derive(Deserialize)]
struct RawCatalogue {
    economy_speed: f64,
    time_grid_level: usize,
    objects: Vec<RawObject>,
    time_modifiers: Vec<RawModifier>,
    planets: Vec<RawPlanet>,
}

#[derive(Deserialize)]
struct RawObject {
    id: i64,
    consumes_field: bool,
    requirements: Vec<RawRequirement>,
    price: Vec<[f64; 3]>,
    build_seconds: Vec<f64>,
    build_seconds_grid: Vec<Vec<f64>>,
}

#[derive(Deserialize)]
struct RawRequirement {
    id: i64,
    level: u32,
}

#[derive(Deserialize)]
struct RawModifier {
    id: i64,
    divisor: Vec<f64>,
}

#[derive(Deserialize)]
struct RawPlanet {
    position: f64,
    temperature: f64,
    field_max: u32,
    basic_income: [f64; 4],
    resources: [f64; 3],
    levels: HashMap<String, u32>,
    production: HashMap<String, Vec<[f64; 4]>>,
    storage: HashMap<String, Vec<[f64; 3]>>,
}

pub struct Object {
    pub id: i64,
    pub consumes_field: bool,
    /// (object index, level); no index means a requirement outside the table (research), met only at level 0.
    pub requires: Vec<(Option<usize>, u32)>,
    /// Price and host build time (every station at zero) of the level after index `level`.
    pub price: Vec<[f64; 3]>,
    pub build_seconds: Vec<f64>,
    /// The host's own build time per level for every combination of modifier levels up to `time_grid_level`.
    pub build_seconds_grid: Vec<Vec<f64>>,
}

pub struct PlanetConfig {
    pub position: f64,
    pub temperature: f64,
    pub field_max: u32,
    pub basic: [f64; 4],
    pub resources: [f64; 3],
    pub levels: Vec<u32>,
    pub production: Vec<Option<Vec<[f64; 4]>>>,
    pub storage: Vec<Option<Vec<[f64; 3]>>>,
}

pub struct Catalogue {
    pub speed: f64,
    pub time_grid_level: usize,
    pub objects: Vec<Object>,
    /// Objects whose level divides every build time, with the divisor per level.
    pub modifiers: Vec<(usize, Vec<f64>)>,
    pub planets: Vec<PlanetConfig>,
}

#[derive(Clone)]
pub struct Planet {
    pub config: usize,
    pub levels: Vec<u32>,
    pub res: [f64; 3],
    pub rate: [f64; 3],
    pub cap: [f64; 3],
    pub energy_max: f64,
    pub energy_used: f64,
    pub factor: f64,
}

#[derive(Clone)]
pub struct Game {
    pub planet: Planet,
    pub t: f64,
    pub horizon: f64,
    pub bonus_hours: f64,
    pub invested: f64,
    pub done: bool,
    pub invalid: u32,
    /// (start second, object index, end second, stock before paying) of every build, for traces and the host replay.
    pub history: Vec<(f64, usize, f64, [f64; 3])>,
}

#[derive(Clone, Copy)]
pub enum Baseline {
    Random,
    Soonest,
    Payback,
    /// Payback plus what every experienced player does with stores: upgrade the one that would overflow before
    /// the queue is free again.
    Player,
}

fn per_object<T: Clone>(map: &HashMap<String, T>, index: &HashMap<i64, usize>, count: usize) -> Vec<Option<T>> {
    let mut rows = vec![None; count];
    for (id, row) in map {
        if let Some(&i) = id.parse::<i64>().ok().and_then(|id| index.get(&id)) {
            rows[i] = Some(row.clone());
        }
    }
    rows
}

fn ln1p(value: f64) -> f32 {
    value.max(0.0).ln_1p() as f32
}

fn signed_log(value: f64) -> f32 {
    (value.signum() * value.abs().ln_1p()) as f32
}

fn weighted(values: [f64; 3]) -> f64 {
    (0..3).map(|r| WEIGHTS[r] * values[r]).sum()
}

pub fn next_random(state: &mut u64) -> u64 {
    *state = state.wrapping_add(0x9E37_79B9_7F4A_7C15);
    let mut z = *state;
    z = (z ^ (z >> 30)).wrapping_mul(0xBF58_476D_1CE4_E5B9);
    z = (z ^ (z >> 27)).wrapping_mul(0x94D0_49BB_1331_11EB);
    z ^ (z >> 31)
}

impl Catalogue {
    pub fn load(path: &str) -> Result<Catalogue, String> {
        let text = std::fs::read_to_string(path).map_err(|e| format!("{path}: {e}"))?;
        let raw: RawCatalogue = serde_json::from_str(&text).map_err(|e| format!("{path}: {e}"))?;
        let index: HashMap<i64, usize> = raw.objects.iter().enumerate().map(|(i, o)| (o.id, i)).collect();
        let count = raw.objects.len();
        let objects = raw.objects.iter().map(|o| Object {
            id: o.id,
            consumes_field: o.consumes_field,
            requires: o.requirements.iter().map(|r| (index.get(&r.id).copied(), r.level)).collect(),
            price: o.price.clone(),
            build_seconds: o.build_seconds.clone(),
            build_seconds_grid: o.build_seconds_grid.clone(),
        }).collect();
        let modifiers = raw.time_modifiers.iter().filter_map(|m| index.get(&m.id).map(|&i| (i, m.divisor.clone()))).collect();
        let planets = raw.planets.iter().map(|p| PlanetConfig {
            position: p.position,
            temperature: p.temperature,
            field_max: p.field_max,
            basic: p.basic_income,
            resources: p.resources,
            levels: per_object(&p.levels, &index, count).into_iter().map(|l| l.unwrap_or(0)).collect(),
            production: per_object(&p.production, &index, count),
            storage: per_object(&p.storage, &index, count),
        }).collect();

        Ok(Catalogue { speed: raw.economy_speed, time_grid_level: raw.time_grid_level, objects, modifiers, planets })
    }

    pub fn new_game(&self, config: usize, horizon_hours: f64, bonus_hours: f64) -> Game {
        let c = &self.planets[config];
        let mut planet = Planet { config, levels: c.levels.clone(), res: c.resources, rate: [0.0; 3], cap: [0.0; 3], energy_max: 0.0, energy_used: 0.0, factor: 1.0 };
        self.refresh(&mut planet);
        let mut game = Game { planet, t: 0.0, horizon: horizon_hours * 3600.0, bonus_hours, invested: 0.0, done: false, invalid: 0, history: Vec::new() };
        if !self.any_legal(&game) {
            self.finish(&mut game);
        }
        game
    }

    /// Rates, energy and factor from the levels (PlanetService::updateResourceProductionStatsInner); with
    /// `full_factor` the factor is forced to 100%, the nominal output a player reads off the resource page.
    fn production(&self, planet: &Planet, full_factor: bool) -> ([f64; 3], f64, f64, f64) {
        let c = &self.planets[planet.config];
        let mut made = [0.0; 3];
        let (mut energy_max, mut energy_used) = (c.basic[3].max(0.0), (-c.basic[3]).max(0.0));
        let row = |k: usize, rows: &Vec<[f64; 4]>| rows[(planet.levels[k] as usize).min(rows.len() - 1)];
        for (k, rows) in c.production.iter().enumerate() {
            let Some(rows) = rows else { continue };
            let row = row(k, rows);
            if row[3] > 0.0 { energy_max += row[3] } else { energy_used -= row[3] }
            for r in 0..3 { made[r] += row[r] }
        }
        for (k, rows) in c.production.iter().enumerate() {
            let Some(rows) = rows else { continue };
            let row = row(k, rows);
            if row[3] > 0.0 && row[2] < 0.0 && -row[2] > c.basic[2] + made[2] && planet.res[2] <= 0.0 {
                energy_max -= row[3];
            }
        }
        let (energy_max, energy_used) = (energy_max.trunc(), energy_used.trunc());
        let factor = match () {
            _ if full_factor || energy_used == 0.0 => 1.0,
            _ if energy_max <= 0.0 => 0.0,
            _ => (energy_max / energy_used * 100.0).floor().clamp(0.0, 100.0) / 100.0,
        };
        let rate = [0, 1, 2].map(|r| (c.basic[r] + made[r] * factor).ceil());
        (rate, energy_max, energy_used, factor)
    }

    pub fn refresh(&self, planet: &mut Planet) {
        let (rate, energy_max, energy_used, factor) = self.production(planet, false);
        let c = &self.planets[planet.config];
        let mut cap = [0.0; 3];
        for (k, rows) in c.storage.iter().enumerate() {
            let Some(rows) = rows else { continue };
            let row = rows[(planet.levels[k] as usize).min(rows.len() - 1)];
            for r in 0..3 { cap[r] += row[r] }
        }
        *planet = Planet { rate, cap, energy_max, energy_used, factor, ..planet.clone() };
    }

    /// Accrual at the stored rates, each resource stopping at its cap (PlanetService::updateResourcesUntil). A stock
    /// never goes below zero here; the host has no floor, but no economy this table reaches burns more than it makes.
    fn accrue(&self, planet: &mut Planet, seconds: f64) {
        let hours = seconds / 3600.0;
        for r in 0..3 {
            if planet.res[r] < planet.cap[r] {
                planet.res[r] += (planet.rate[r] * hours).min(planet.cap[r] - planet.res[r]).max(-planet.res[r]);
            }
        }
    }

    pub fn fields_used(&self, planet: &Planet) -> u32 {
        self.objects.iter().zip(&planet.levels).filter(|(o, _)| o.consumes_field).map(|(_, l)| *l).sum()
    }

    fn requirements_met(&self, levels: &[u32], k: usize) -> bool {
        self.objects[k].requires.iter().all(|(target, level)| match target {
            Some(i) => levels[*i] >= *level,
            None => *level == 0,
        })
    }

    /// What BuildingQueueService::add accepts, plus a price the planet can ever pay at today's stores and rates.
    pub fn legal(&self, planet: &Planet, k: usize) -> bool {
        let o = &self.objects[k];
        (planet.levels[k] as usize) < o.price.len()
            && self.requirements_met(&planet.levels, k)
            && !(o.consumes_field && self.fields_used(planet) >= self.planets[planet.config].field_max)
            && self.wait_seconds(planet, k) < NEVER
    }

    pub fn any_legal(&self, game: &Game) -> bool {
        (0..self.objects.len()).any(|k| self.legal(&game.planet, k))
    }

    /// Whole seconds until the stock covers the next level's price; NEVER when a store is too small or a rate is zero.
    pub fn wait_seconds(&self, planet: &Planet, k: usize) -> f64 {
        let price = self.objects[k].price[planet.levels[k] as usize];
        let mut wait: f64 = 0.0;
        for r in 0..3 {
            if price[r] <= planet.res[r] { continue }
            if price[r] > planet.cap[r] || planet.rate[r] <= 0.0 { return NEVER }
            wait = wait.max((price[r] - planet.res[r]) / planet.rate[r] * 3600.0);
        }
        wait.ceil()
    }

    /// Host build time (PlanetService::getBuildingConstructionTime), read from the host's grid. The host rounds
    /// after dividing, so dividing its whole seconds drifts by a second; the divisor is only the fallback beyond
    /// the grid, applied to the time with every station at zero except the object's own level.
    pub fn build_seconds(&self, planet: &Planet, k: usize) -> f64 {
        let level = planet.levels[k] as usize;
        let side = self.time_grid_level + 1;
        let cell = self.modifiers.iter().try_fold(0usize, |at, (m, _)| {
            let l = planet.levels[*m] as usize;
            (l < side).then(|| at * side + l)
        });
        if let Some(cell) = cell {
            return self.objects[k].build_seconds_grid[level][cell];
        }
        let mut seconds = self.objects[k].build_seconds[planet.levels[k] as usize];
        for (m, divisor) in &self.modifiers {
            if *m != k {
                seconds /= divisor[(planet.levels[*m] as usize).min(divisor.len() - 1)];
            }
        }
        seconds.floor().max(1.0)
    }

    fn finish(&self, game: &mut Game) {
        self.accrue(&mut game.planet, game.horizon - game.t);
        game.t = game.horizon;
        game.done = true;
    }

    /// Commits the free queue to object k: wait until it is affordable, pay, build, and stop at the next free queue.
    /// The reward is the change in value (invested plus held, metal-equivalent), which is what the planet produced;
    /// the last step adds `bonus_hours` of the production left running, so the end of the horizon is not a cliff.
    pub fn step(&self, game: &mut Game, k: usize) -> f64 {
        let before = game.value();
        let start = game.t + self.wait_seconds(&game.planet, k);
        if start >= game.horizon {
            self.finish(game);
            return game.value() - before + game.bonus_hours * game.effective_rate();
        }
        self.accrue(&mut game.planet, start - game.t);
        game.t = start;
        let stock = game.planet.res;
        let price = self.objects[k].price[game.planet.levels[k] as usize];
        for r in 0..3 { game.planet.res[r] -= price[r] }
        game.invested += weighted(price);
        let end = start + self.build_seconds(&game.planet, k);
        game.history.push((start, k, end, stock));
        if end >= game.horizon {
            self.finish(game);
            return game.value() - before + game.bonus_hours * game.effective_rate();
        }
        self.accrue(&mut game.planet, end - game.t);
        game.t = end;
        game.planet.levels[k] += 1;
        self.refresh(&mut game.planet);
        if !self.any_legal(game) {
            self.finish(game);
        }
        let bonus = if game.done { game.bonus_hours * game.effective_rate() } else { 0.0 };
        game.value() - before + bonus
    }

    pub fn state_features(&self, game: &Game, out: &mut [f32]) {
        let p = &game.planet;
        let c = &self.planets[p.config];
        let fill = |r: usize| if p.cap[r] > 0.0 { (p.res[r] / p.cap[r]) as f32 } else { 0.0 };
        let values = [
            ln1p(p.res[0]), ln1p(p.res[1]), ln1p(p.res[2]), fill(0), fill(1), fill(2),
            ln1p(p.rate[0]), ln1p(p.rate[1]), ln1p(p.rate[2]), ln1p(p.energy_max), ln1p(p.energy_used), p.factor as f32,
            (c.temperature / 100.0) as f32, (c.position / 15.0) as f32, self.fields_used(p) as f32 / c.field_max.max(1) as f32,
            (game.t / game.horizon) as f32, ln1p((game.horizon - game.t) / 3600.0), ln1p(self.speed),
        ];
        out.copy_from_slice(&values);
    }

    /// What a player reads about the next level of object k, from the tables alone: no identity, only numbers.
    pub fn candidate_features(&self, game: &Game, k: usize, out: &mut [f32]) {
        let p = &game.planet;
        let o = &self.objects[k];
        let level = p.levels[k] as usize;
        if level >= o.price.len() {
            out.fill(0.0);
            return;
        }
        let price = o.price[level];
        let cost = weighted(price);
        let mut next = p.clone();
        next.levels[k] += 1;
        self.refresh(&mut next);
        let gain = weighted(next.rate) - weighted(p.rate);
        let nominal_gain = weighted(self.production(&next, true).0) - weighted(self.production(p, true).0);
        let energy_delta = (next.energy_max - next.energy_used) - (p.energy_max - p.energy_used);
        let time_gain = self.modifiers.iter().find(|(m, _)| *m == k)
            .map_or(0.0, |(_, d)| 1.0 - d[level.min(d.len() - 1)] / d[(level + 1).min(d.len() - 1)]);
        let payback = if gain > 0.0 { (cost / gain).min(NO_PAYBACK_HOURS) } else { NO_PAYBACK_HOURS };
        let unlocks = (0..self.objects.len()).filter(|&j| !self.requirements_met(&p.levels, j) && self.requirements_met(&next.levels, j)).count();
        let values = [
            ln1p(price[0]), ln1p(price[1]), ln1p(price[2]),
            ln1p(self.wait_seconds(p, k) / 3600.0), ln1p(self.build_seconds(p, k) / 3600.0), ln1p(level as f64),
            signed_log(gain), signed_log(energy_delta), ln1p(weighted(next.cap) - weighted(p.cap)), time_gain as f32,
            ln1p(payback), unlocks as f32, ln1p(cost / (weighted(p.res) + 1.0)), signed_log(nominal_gain),
        ];
        out.copy_from_slice(&values);
    }

    /// The deterministic players the model must beat. Payback is the classic rule: power first when the factor is
    /// short, else the next level that repays its price fastest, a store when no producer fits, else the cheapest.
    pub fn baseline(&self, game: &Game, kind: Baseline, rng: &mut u64) -> Option<usize> {
        let p = &game.planet;
        let legal: Vec<usize> = (0..self.objects.len()).filter(|&k| self.legal(p, k)).collect();
        if legal.is_empty() {
            return None;
        }
        let cost = |k: usize| weighted(self.objects[k].price[p.levels[k] as usize]);
        let min_by = |keys: Vec<(usize, f64)>| keys.into_iter().min_by(|a, b| a.1.total_cmp(&b.1)).map(|(k, _)| k);
        match kind {
            Baseline::Random => Some(legal[(next_random(rng) % legal.len() as u64) as usize]),
            Baseline::Soonest => min_by(legal.iter().map(|&k| (k, self.wait_seconds(p, k) * 1e6 + cost(k))).collect()),
            Baseline::Player => {
                let pick = self.baseline(game, Baseline::Payback, rng)?;
                let busy_hours = (self.wait_seconds(p, pick) + self.build_seconds(p, pick)) / 3600.0;
                let overflow = (0..3).filter(|&r| p.res[r] + p.rate[r].max(0.0) * busy_hours >= p.cap[r]).collect::<Vec<_>>();
                let store = legal.iter().filter_map(|&k| {
                    let mut n = p.clone();
                    n.levels[k] += 1;
                    self.refresh(&mut n);
                    let relief: f64 = overflow.iter().map(|&r| n.cap[r] - p.cap[r]).sum();
                    (relief > 0.0).then(|| (k, -relief / cost(k)))
                }).collect::<Vec<_>>();
                min_by(store).or(Some(pick))
            }
            Baseline::Payback => {
                let after = |k: usize| { let mut n = p.clone(); n.levels[k] += 1; self.refresh(&mut n); n };
                let nominal = |q: &Planet| weighted(self.production(q, true).0);
                let power: Vec<(usize, f64)> = legal.iter().filter_map(|&k| {
                    let n = after(k);
                    let delta = (n.energy_max - n.energy_used) - (p.energy_max - p.energy_used);
                    (delta > 0.0).then(|| (k, cost(k) / delta))
                }).collect();
                if p.factor < 1.0 && !power.is_empty() {
                    return min_by(power);
                }
                let producers: Vec<(usize, f64)> = legal.iter().filter_map(|&k| {
                    let gain = nominal(&after(k)) - nominal(p);
                    (gain > 0.0).then(|| (k, cost(k) / gain))
                }).collect();
                if !producers.is_empty() {
                    return min_by(producers);
                }
                let stores: Vec<(usize, f64)> = legal.iter().filter_map(|&k| {
                    let gain = weighted(after(k).cap) - weighted(p.cap);
                    (gain > 0.0).then(|| (k, -gain / cost(k)))
                }).collect();
                if !stores.is_empty() {
                    return min_by(stores);
                }
                min_by(legal.iter().map(|&k| (k, cost(k))).collect())
            }
        }
    }
}

impl Game {
    pub fn value(&self) -> f64 {
        self.invested + weighted(self.planet.res)
    }

    /// Metal-equivalent output per hour that still lands somewhere (a full store makes nothing).
    pub fn effective_rate(&self) -> f64 {
        (0..3).map(|r| if self.planet.res[r] < self.planet.cap[r] { WEIGHTS[r] * self.planet.rate[r] } else { 0.0 }).sum()
    }
}
