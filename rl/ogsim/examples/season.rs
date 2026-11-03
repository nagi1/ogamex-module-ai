//! A whole season of the scripted players, one JSONL line per account per simulated day (plan/rl/NORTH-STAR-PLAN.md, N3a).
//!
//! The world is played the way the binding plays it: `World::next_decision` moves the clock to the next account decision —
//! an economy event or a login — and the scripted player acts there, never in fixed steps, so a season of 64 accounts
//! over 60 days costs seconds. Coordinates sit in three galaxies, one system each, spread across the universe like real
//! ones; personas are drawn per account (Miner 40%, Raider 30%, FleetSaver 30%); and about half the accounts stop coming,
//! each drawing a quit day from an exponential with a mean of fifteen days.
//!
//! Quitting is expressed in the world's own terms: the account's login record ends at its quit day, which is exactly what
//! the host's seven-day rule reads (`World::inactive`), and its game clock then stops. No second notion of inactivity.
//!
//! The lines match §9.1 of the plan field for field. Until row N1 lands the four scores are a stub — `account_value`
//! split by kind — and every line says `"score_source": "stub"` so `hds.py` compares counts, not totals.
//!
//!     cargo run --release --example season -- <data dir> <days> <accounts> <seed> <catalogue> <out JSONL>

use std::collections::{BTreeMap, BTreeSet};
use std::fs::File;
use std::io::{BufWriter, Write};

use ogsim::players::{account_value, YardStall};
use ogsim::score;
use ogsim::season::{Policy, Universe, DAY, PERSONAS, SAVER};
use ogsim::economy::{next_random, Baseline, Books};
use ogsim::units::UnitKind;
use ogsim::world::{Colonisation, Decision, Event, EventKind, Mission, World};
use std::sync::Arc;
use ogsim::{WEIGHTS};
use serde_json::json;

fn main() {
    let mut args = std::env::args().skip(1);
    let data = args.next().unwrap_or_else(|| "../../../../storage/rl/ogsim".into());
    let days: usize = args.next().and_then(|s| s.parse().ok()).unwrap_or(60);
    let accounts: usize = args.next().and_then(|s| s.parse().ok()).unwrap_or(64);
    let seed: u64 = args.next().and_then(|s| s.parse().ok()).unwrap_or(1);
    let catalogue = args.next().unwrap_or_else(|| "u1".into());
    let out_path = args.next().unwrap_or_else(|| format!("{data}/runs/n3a-season/season-{seed}.jsonl"));

    let econ = match catalogue.ends_with(".json") {
        true => catalogue.clone(),
        false => format!("{data}/units-parity/econ-{catalogue}.json"),
    };
    let arms: Vec<String> = std::env::args().collect();
    let cat = Arc::new(Universe::catalogue(&data, &econ, &arms).expect("the economy catalogue"));
    // The host's colonisation tables, when the catalogue carries them: without them no account can found a colony.
    let colonisation = Colonisation::load(&econ).ok().map(Arc::new);
    // `jobs=FILE threads=N`: many seasons in one process, sharing the catalogue (about 0.8 GB, which every process would load
    // again). FILE has one season per line: SEED OUT [ARM...], the line's arms added to the command line's.
    match arms.iter().find_map(|arm| arm.strip_prefix("jobs=")) {
        Some(file) => {
            let threads = arms.iter().find_map(|arm| arm.strip_prefix("threads=")).map_or(1, |n| n.parse::<usize>().expect("threads=N"));
            let jobs: Vec<(u64, String, Vec<String>)> = std::fs::read_to_string(file).expect("the jobs file").lines().filter(|l| !l.trim().is_empty()).map(|line| {
                let mut words = line.split_whitespace();
                let seed = words.next().expect("a seed").parse().expect("seed");
                let out = words.next().expect("an output path").to_string();
                (seed, out, arms.iter().cloned().chain(words.map(String::from)).collect())
            }).collect();
            let queue = std::sync::Mutex::new(jobs.into_iter());
            std::thread::scope(|scope| {
                for _ in 0..threads {
                    scope.spawn(|| while let Some((seed, out, arms)) = { let next = queue.lock().expect("the job queue").next(); next } {
                        play(Arc::clone(&cat), colonisation.clone(), &data, days, accounts, seed, &out, &arms);
                    });
                }
            });
        }
        None => play(cat, colonisation, &data, days, accounts, seed, &out_path, &arms),
    }
}

/// One look-ahead decision as raw little-endian f32: state, candidates, legal flags and worths (NaN where illegal).
fn write_label(file: &mut impl Write, found: &ogsim::season::Lookahead) {
    let legal = found.legal.iter().map(|&l| f32::from(l));
    for x in found.state.iter().copied().chain(found.cands.iter().copied()).chain(legal).chain(found.worths.iter().copied()) {
        file.write_all(&x.to_le_bytes()).expect("write a label");
    }
}

/// One season, written to `out_path`, its summary printed.
#[allow(clippy::too_many_arguments)]
fn play(cat: Arc<ogsim::economy::Catalogue>, colonisation: Option<Arc<Colonisation>>, data: &str, days: usize, accounts: usize, seed: u64, out_path: &str, arms: &[String]) {
    let mut universe = Universe::new(cat, colonisation, data, days, accounts, seed, arms);
    // `policy=<weights>` hands every account's builds and research to a trained model; its persona plays the rest of the login.
    // `teacher-pick` asks the persona through the model's own door, so that door is measured apart from the model.
    let teacher_pick = arms.iter().any(|arm| arm == "teacher-pick");
    // `sampled` plays the policy as training does; a model cloned from a teacher can keep every greedy pick while it changes.
    // One stream per account, so an account's extra decision does not reshuffle the others' samples.
    let mut draw = arms.iter().any(|arm| arm == "sampled").then(|| (0..accounts as u64).map(|a| seed ^ 0x5A17 ^ (a + 1).wrapping_mul(0x9e37_79b9_7f4a_7c15)).collect::<Vec<u64>>());
    let policy = arms.iter().find_map(|arm| arm.strip_prefix("policy=")).map(|path| Policy::load(path).expect("the policy weights"));
    // `es=NOISE:SIGN:SIGMA` (with `policy=`): account a plays the policy nudged by SIGN x SIGMA x row a of NOISE, raw little-endian
    // f32 [accounts, weights] (`ogrl.es_world`). The antithetic season plays the opposite sign on the same seed.
    let nudge = |spec: &str, base: &Policy| -> Vec<Policy> {
        let mut parts = spec.split(':');
        let (file, sign, sigma) = (parts.next().expect("NOISE:SIGN:SIGMA"), parts.next().expect("sign").parse::<f32>().expect("sign"), parts.next().expect("sigma").parse::<f32>().expect("sigma"));
        let raw = std::fs::read(file).expect("the noise file");
        let noise: Vec<f32> = raw.chunks_exact(4).map(|b| f32::from_le_bytes([b[0], b[1], b[2], b[3]])).collect();
        let n = base.scoring_weights();
        assert_eq!(noise.len(), accounts * n, "noise is [accounts, {n}]");
        (0..accounts).map(|a| base.nudged(&noise[a * n..(a + 1) * n], sign * sigma)).collect()
    };
    let nudged: Option<Vec<Policy>> = arms.iter().find_map(|arm| arm.strip_prefix("es=")).zip(policy.as_ref()).map(|(spec, base)| nudge(spec, base));
    // `errand-policy=<weights>` flies the fleets of the first `fleet-model=N` accounts by a trained errand model;
    // `errand-policy=teacher` asks the scripted mix through the same door, so the door is measured apart from the model.
    let errand_policy = arms.iter().find_map(|arm| arm.strip_prefix("errand-policy="));
    let errand_model = errand_policy.filter(|&path| path != "teacher").map(|path| Policy::load(path).expect("the errand weights"));
    let fleet_model = arms.iter().find_map(|arm| arm.strip_prefix("fleet-model=")).map_or(0, |n| n.parse::<usize>().expect("fleet-model=N"));
    // `es-errand=NOISE:SIGN:SIGMA` (with `errand-policy=`): as `es=`, for the errand model each fleet account flies.
    let errand_nudged: Option<Vec<Policy>> = arms.iter().find_map(|arm| arm.strip_prefix("es-errand=")).zip(errand_model.as_ref()).map(|(spec, base)| nudge(spec, base));
    // `skill=LO:HI` gives each account its own sampling heat, log-uniform in [LO, HI]: real players range from sharp to loose.
    let heat: Vec<f32> = match arms.iter().find_map(|arm| arm.strip_prefix("skill=")).and_then(|r| r.split_once(':')) {
        Some((lo, hi)) => {
            let (lo, hi) = (lo.parse::<f64>().expect("skill low").ln(), hi.parse::<f64>().expect("skill high").ln());
            let mut rng = seed ^ 0x5C11;
            (0..accounts).map(|_| (lo + (hi - lo) * (next_random(&mut rng) >> 11) as f64 / (1u64 << 53) as f64).exp() as f32).collect()
        }
        None => vec![1.0; accounts],
    };
    // `champion=STRIDE:OFFSET:HOURS:ROLLOUTS`: the accounts whose index is OFFSET modulo STRIDE build by look-ahead
    // (`Universe::lookahead`) instead of the policy or their persona: the strong player candidates are matched against.
    let champion = arms.iter().find_map(|arm| arm.strip_prefix("champion=")).map(|spec| {
        let p: Vec<f64> = spec.split(':').map(|x| x.parse().expect("champion=STRIDE:OFFSET:HOURS:ROLLOUTS")).collect();
        (p[0] as usize, p[1] as usize, p[2], p[3] as u64)
    });
    // `champion-out=FILE`: every look-ahead decision appended as raw little-endian f32 (state, candidates, legal, worths),
    // the labels `ogrl.train_exit` clones a student from.
    let mut labels = arms.iter().find_map(|arm| arm.strip_prefix("champion-out=")).map(|path| BufWriter::new(File::create(path).expect("the label file")));
    let is_champion = move |owner: usize| champion.is_some_and(|(stride, offset, _, _)| owner % stride == offset);

    if let Some(dir) = std::path::Path::new(&out_path).parent().filter(|d| !d.as_os_str().is_empty()) {
        std::fs::create_dir_all(dir).expect("the output directory");
    }
    let out = BufWriter::new(File::create(&out_path).expect("the output file"));
    // Row 23: the books and the stock at the last line written, so a day is the difference of two samples.
    let since: Vec<(Books, f64)> = (0..accounts).map(|a| (universe.world.books(a), universe.world.stock(a))).collect();
    let mut season = Season {
        u: &mut universe,
        out,
        per_day: vec![vec![Tally::default(); accounts]; days],
        since,
        login_at: vec![0; accounts],
        counted_login: vec![f64::NEG_INFINITY; accounts],
        read: [0; 6],
        midnight: DAY,
        days,
        seed,
        lines: 0,
        previous: vec![None; accounts],
        frozen_days: 0,
        bad_stock: 0,
        unbalanced: 0,
        ahead: 0,
        production_history: vec![Vec::new(); accounts],
        previous_factors: vec![Vec::new(); accounts],
        production_drops: 0,
        delivered: vec![[0.0; 3]; accounts],
        excess: vec![[0.0; 3]; accounts],
        seen_planet: vec![false; accounts],
        over_cap: 0,
        // A build model plays every account; an errand model only the fleets of the first `fleet-model` accounts.
        champion: (0..accounts).map(is_champion).collect(),
        controlled: if policy.is_some() { accounts } else if errand_model.is_some() { fleet_model } else { 0 },
    };

    let start = std::time::Instant::now();
    while let Some(a) = season.u.next_decision() {
        let owner = season.u.world.account_of(a);
        season.flush(season.u.world.games[a].t);
        let plays = season.u.plays(a);
        if plays { season.touch(owner, a) }
        let build = plays && season.u.world.decision_kind(a) == Decision::Build;
        let pick = match &policy {
            _ if build && is_champion(owner) => champion.map(|(_, _, hours, rollouts)| {
                let found = season.u.lookahead(a, hours, rollouts);
                if let Some(file) = labels.as_mut() { write_label(file, &found) }
                found.pick
            }),
            Some(p) if build => Some(season.u.choose(nudged.as_ref().map_or(p, |all| &all[owner]), a, heat[owner], draw.as_mut().map(|d| &mut d[owner]))),
            None if build && teacher_pick => Some(season.u.teacher(a)),
            _ => None,
        };
        if errand_policy.is_some() && plays && a == owner && owner < fleet_model {
            let (offered, state, cands, legal) = season.u.observe_errands(a);
            let model = errand_nudged.as_ref().map(|all| &all[owner]).or(errand_model.as_ref());
            let row = match (model, draw.as_mut().map(|d| &mut d[owner])) {
                (Some(p), Some(rng)) => p.sample(&state, &cands, &legal, 1.0, rng).0,
                (Some(p), None) => p.best(&state, &cands, &legal),
                (None, _) => season.u.errand_teacher(a, &offered),
            };
            season.u.run_errand(a, &offered, row);
        }
        season.u.play(a, pick);
    }
    season.flush(f64::INFINITY);
    if let Some(file) = labels.as_mut() { file.flush().expect("flush the labels") }
    // Diagnosis: every account's planets as the season leaves them (fields, rate, levels by host id), beside the lines.
    if arms.iter().any(|arm| arm == "levels") {
        let planets: Vec<_> = (0..accounts).map(|a| {
            let slots: Vec<_> = season.u.world.planets_of(a).into_iter().map(|slot| {
                let planet = &season.u.world.games[slot].planet;
                let levels: serde_json::Map<String, serde_json::Value> = season.u.cat.objects.iter().zip(&planet.levels)
                    .filter(|(_, &l)| l > 0).map(|(o, &l)| (o.id.to_string(), json!(l))).collect();
                json!({"fields": season.u.cat.fields_used(planet), "cap": season.u.cat.field_cap(planet), "rate": planet.rate,
                       "position": season.u.cat.planets[planet.config].position, "levels": levels})
            }).collect();
            json!({"account": a, "inactive": season.u.gone[a], "total": score::of(&season.u.world, a).total, "planets": slots})
        }).collect();
        std::fs::write(format!("{out_path}.levels.json"), serde_json::to_string(&planets).expect("levels json")).expect("write the levels");
    }
    let lines = season.lines;
    let frozen_days = season.frozen_days;
    let bad_stock = season.bad_stock;
    let unbalanced = season.unbalanced;
    let ahead = season.ahead;
    let production_drops = season.production_drops;
    let over_cap = season.over_cap;
    let seconds = start.elapsed().as_secs_f64();
    drop(season);

    let count = |role: usize| universe.roles.iter().filter(|&&r| r == role).count();
    let quit_days: Vec<f64> = universe.quit.iter().flatten().map(|d| d / DAY).collect();
    println!("{}", json!({
        "seed": seed,
        "days": days,
        "accounts": accounts,
        "world_speed": universe.cat.speed,
        "personas": {PERSONAS[0].0: count(0), PERSONAS[1].0: count(1), PERSONAS[2].0: count(2)},
        "quit": quit_days.len(),
        "quit_day_median": median(&quit_days),
        "lines": lines,
        "health": {"frozen_active_days": frozen_days, "invalid_stock_days": bad_stock,
                   "unbalanced_days": unbalanced, "clock_ahead_days": ahead,
                   "production_drop_warnings": production_drops, "unexplained_over_cap_days": over_cap},
        "seconds": seconds,
        "out": out_path,
    }));
}

/// A season's output: the world, the day's buckets still filling, and the lines written so far.
struct Season<'a, W: Write> {
    u: &'a mut Universe,
    out: W,
    /// Per day and account, what the world played that day.
    per_day: Vec<Vec<Tally>>,
    /// Per account, its books and its stock at the last line written (row 23).
    since: Vec<(Books, f64)>,
    /// Per account, how far its login record has been read for the days already written.
    login_at: Vec<usize>,
    /// The last login second counted per account: every planet may decide at it, and it is one login.
    counted_login: Vec<f64>,
    /// How many events of the world's log have been read.
    /// How far the world's event, moon gear and halving logs have been read.
    read: [usize; 6],
    /// The next midnight no line has been written for.
    midnight: f64,
    days: usize,
    seed: u64,
    lines: usize,
    previous: Vec<Option<(f64, i64, f64)>>,
    frozen_days: usize,
    bad_stock: usize,
    unbalanced: usize,
    ahead: usize,
    production_history: Vec<Vec<f64>>,
    previous_factors: Vec<Vec<(usize, f64)>>,
    production_drops: usize,
    delivered: Vec<[f64; 3]>,
    excess: Vec<[f64; 3]>,
    seen_planet: Vec<bool>,
    over_cap: usize,
    /// The accounts a model plays (the first this many), and the accounts the look-ahead player builds for.
    controlled: usize,
    champion: Vec<bool>,
}

impl<W: Write> Season<'_, W> {
    /// Writes the line of every day that ends at or before `until` (every day left, with `INFINITY`).
    fn flush(&mut self, until: f64) {
        while self.midnight <= until && self.midnight <= self.days as f64 * DAY {
            let d = (self.midnight / DAY) as usize - 1;
            scan(&self.u.world, &mut self.read, &self.u.roles, &mut self.per_day, self.days, &mut self.delivered);
            self.write_day(d, snapshot_time(until, self.midnight));
            self.u.day_end(self.midnight);
            self.lines += self.u.world.games.len();
            self.midnight += DAY;
        }
    }

    /// One line per account for the day `d` (0-based; the line says `d + 1`), as §9.1 of the plan fixes it.
    fn write_day(&mut self, d: usize, world_time: f64) {
        let at = (d + 1) as f64 * DAY;
        for a in 0..self.u.roles.len() {
            // Inactive is the world's own flag (last login more than seven days ago), not the quit day we drew.
            let inactive = self.u.world.inactive(&self.u.cat, a, at);
            // An account's value is every planet it holds, as `score::of` already sums it.
            let planets = self.u.world.planets_of(a);
            let value: f64 = planets.iter().map(|&s| account_value(&self.u.world, s)).sum();
            let economy: f64 = planets.iter().map(|&s| self.u.world.games[s].value()).sum();
            // The host's own four scores (row N1: `score::of` equals HighscoreService on 600 drawn accounts,
            // 0 differ to the integer). Every band in the plan is written in this score, so the line says so.
            let score = score::of(&self.u.world, a);
            let galaxies: BTreeSet<i64> = planets.iter().map(|&s| self.u.world.coords[s].galaxy).collect();
            let moons = planets.iter().filter(|&&s| self.u.world.moons[s]).count();
            let logins = self.logins_today(a, d);
            let busy = self.u.world.busy_seconds(a, at - DAY, at);
            let tally = &self.per_day[d][a];
            // Row 29: what holds each queue of this account's planets, in hours, and the host's time modifiers for them.
            let stall = self.stall(a, &planets);
            // Row 23: the day's flows are the difference of two samples of the same second, so a line whose events
            // all fell inside it closes by construction and one the world moved without booking a flow does not.
            let books = self.u.world.books(a);
            let stock = self.u.world.stock(a);
            let (before, was) = self.since[a];
            let flows = flows_of(&before, &books);
            let unexplained = (stock - was) - (books.net() - before.net());
            self.since[a] = (books, stock);
            let fleet = value - economy;
            // The day an account quits, its last login is the quit itself (`retire`), where it no longer acts.
            let quits_today = (at - DAY..at).contains(&self.u.world.games[a].horizon);
            // A clock behind midnight with nothing due before it is lazy, not stuck: its day is booked at its next event.
            let lazy = planets.iter().all(|&p| self.u.world.games[p].t < at && self.u.world.moves_at(p) > at);
            let frozen = !quits_today && !lazy && frozen_day(self.previous[a], inactive, tally.logins, books.produced - before.produced, stock, score.total, fleet);
            if frozen {
                self.frozen_days += 1;
            }
            self.previous[a] = Some((stock, score.total, fleet));
            let invalid_stock = planets.iter().any(|&p| self.u.world.games[p].planet.res.iter().any(|r| !r.is_finite() || *r < -1e-6));
            let clock_ahead = planets.iter().any(|&p| self.u.world.games[p].t > world_time + 1e-6);
            self.excess.resize(self.u.world.games.len(), [0.0; 3]);
            self.seen_planet.resize(self.u.world.games.len(), false);
            let mut over_cap_detail = Vec::new();
            let unexplained_over_cap = planets.iter().fold(false, |bad, &p| {
                let planet = &self.u.world.games[p].planet;
                let mut invalid = false;
                for r in 0..3 {
                    let current = (planet.res[r] - planet.cap[r]).max(0.0);
                    let initial = if !self.seen_planet[p] { (self.u.cat.planets[planet.config].resources[r] - planet.cap[r]).max(0.0) } else { 0.0 };
                    let delivered = self.delivered.get(p).map_or(0.0, |v| v[r]) + initial;
                    let (next, bad) = excess_after(current, self.excess[p][r], delivered);
                    if bad {
                        over_cap_detail.push(json!({"slot": p, "resource": r, "stock": planet.res[r], "cap": planet.cap[r], "excess_before": self.excess[p][r], "delivered": delivered}));
                    }
                    invalid |= bad;
                    self.excess[p][r] = next;
                    if let Some(delivered) = self.delivered.get_mut(p) { delivered[r] = 0.0; }
                }
                self.seen_planet[p] = true;
                bad || invalid
            });
            let factors: Vec<(usize, f64)> = planets.iter().map(|&p| (p, self.u.world.games[p].planet.factor)).collect();
            let unchanged_energy = self.previous_factors[a].iter().all(|&(slot, factor)| {
                factors.iter().any(|&(current, now)| current == slot && now >= factor - 1e-6)
            });
            let history = &mut self.production_history[a];
            let production = books.produced - before.produced;
            let production_drop = !inactive && history.len() == 15 && unchanged_energy
                && production < median(history) * 0.5;
            if history.len() == 15 { history.remove(0); }
            history.push(production);
            self.previous_factors[a] = factors;
            self.production_drops += usize::from(production_drop);
            let unbalanced = !unexplained.is_finite() || unexplained.abs() > 1e-6 * (1.0 + stock.abs() + was.abs() + books.net().abs() + before.net().abs());
            self.bad_stock += usize::from(invalid_stock);
            self.unbalanced += usize::from(unbalanced);
            self.ahead += usize::from(clock_ahead);
            self.over_cap += usize::from(unexplained_over_cap);
            let line = json!({
                "seed": self.seed,
                "world_speed": self.u.cat.speed as i64,
                "day": d + 1,
                "account": a,
                "persona": PERSONAS[self.u.roles[a]].0,
                "class": self.u.cat.planets[self.u.world.games[a].planet.config].character_class,
                "controlled": a < self.controlled && !self.champion[a],
                "champion": self.champion[a],
                "health": {"frozen": frozen, "invalid_stock": invalid_stock, "unbalanced": unbalanced,
                           "clock_ahead": clock_ahead, "unexplained_over_cap": unexplained_over_cap,
                           "production_drop_warning": production_drop},
                "clock_ahead_detail": planets.iter().filter_map(|&p| {
                    (self.u.world.games[p].t > world_time + 1e-6).then_some(json!({"slot": p, "planet_time": self.u.world.games[p].t, "world_time": world_time, "day_end": at}))
                }).collect::<Vec<_>>(),
                "over_cap_detail": over_cap_detail,
                "inactive": inactive,
                "score_source": "host",
                "score": { "total": score.total, "economy": score.economy, "research": score.research,
                           "military": score.military },
                "planets": planets.len(),
                "moons": moons,
                "galaxies": galaxies.len(),
                "alliance": self.u.world.alliance_at[a].is_some_and(|join| at >= join),
                "value": value,
                "fleet_value": value - economy,
                "dark_matter": self.u.world.dark_matter[a],
                "logins_today": logins,
                // Row 11: where the day went, so the growth gap can be attributed instead of guessed.
            "build_hours": (busy / 3600.0 * 1000.0).round() / 1000.0,
            "idle_hours": (((DAY - busy) / 3600.0) * 1000.0).round() / 1000.0,
            "logins": tally.logins,
            "full_logins": tally.full_logins,
            "events_today": tally.json(),
            // Row 23's income ledger, in metal-equivalent at the host's score weights, and the stock it reconciles.
            "produced": flows["produced"],
            "wasted": flows["wasted"],
            "spent_build": flows["spent_build"],
            "spent_research": flows["spent_research"],
            "spent_units": flows["spent_units"],
            "fuel": flows["fuel"],
            "loot_in": flows["loot_in"],
            "loot_out": flows["loot_out"],
            "expedition_in": flows["expedition_in"],
            "cargo_lost": flows["cargo_lost"],
            "stock": stock,
            "unexplained": unexplained,
            // Row 29: where a queue is stuck - hours a build holds it, hours it holds nothing, and the modifier levels.
            "stall": stall,
            });
            writeln!(self.out, "{line}").expect("write the season line");
        }
    }

    /// Row 29: per account-day, the building queue's hours blocked by a build (capped at a day) against its idle hours,
    /// the levels of the host's own time modifiers for both queues, and what the player rule would build next with its
    /// time, its wait and whether it is legal.
    fn stall(&self, a: usize, planets: &[usize]) -> serde_json::Value {
        let (mut blocked, mut idle, mut long) = (0.0, 0.0, 0_u32);
        for &slot in planets {
            let (now, pending) = (self.u.world.games[slot].t, &self.u.world.games[slot].pending);
            let left: f64 = pending.iter().filter(|&&(_, k)| self.u.cat.objects[k].queue == 0)
                .map(|&(end, _)| (end - now).max(0.0) / 3600.0).fold(0.0, f64::max);
            blocked += left.min(24.0);
            idle += 24.0 - left.min(24.0);
            long += u32::from(left > 24.0);
        }
        let planet = &self.u.world.games[a].planet;
        let levels = |queue: usize| -> Vec<(i64, u32)> {
            self.u.cat.modifiers[queue].iter().map(|&(k, _)| (self.u.cat.objects[k].id, planet.levels[k])).collect()
        };
        let mut rng = self.seed ^ (self.midnight.to_bits());
        let next = self.u.cat.baseline(planet, 0, Baseline::Player, &mut rng);
        let hours = |seconds: f64| if seconds >= 1e9 { serde_json::Value::Null } else { json!(seconds / 3600.0) };
        // Row 29, extended by row 37: why each queue's objects cannot start, counted for **every** planet the account
        // holds (the building queue) and for the account's lab, which is the capital's; and the stock above two hours of
        // each planet's own production, which is the surplus a yard order is meant to spend.
        let mut reasons: std::collections::BTreeMap<&'static str, u32> = std::collections::BTreeMap::new();
        let mut research_reasons: std::collections::BTreeMap<&'static str, u32> = std::collections::BTreeMap::new();
        for &slot in planets {
            for k in (0..self.u.cat.objects.len()).filter(|&k| self.u.cat.objects[k].queue == 0) {
                *reasons.entry(self.u.cat.stall_of(&self.u.world.games[slot].planet, k)).or_default() += 1;
            }
        }
        for k in (0..self.u.cat.objects.len()).filter(|&k| self.u.cat.objects[k].queue == 1) {
            *research_reasons.entry(self.u.cat.stall_of(planet, k)).or_default() += 1;
        }
        let surplus: f64 = planets.iter().map(|&slot| {
            let planet = &self.u.world.games[slot].planet;
            let reserve: f64 = (0..3).map(|r| WEIGHTS[r] * planet.rate[r].max(0.0) * 2.0).sum();
            (weighted(planet.res) - reserve).max(0.0)
        }).sum();
        // Row 42: the census proper - every planet on its own line (used and added fields, the reasons its building objects
        // cannot start, the first missing prerequisite and the first resource its price cannot fit), the lab on the planet
        // that pays for it, and the yard's own bound: what the surplus is worth against what the yard could start at all.
        let census: Vec<serde_json::Value> = planets.iter().map(|&slot| {
            let planet = &self.u.world.games[slot].planet;
            let mut planet_reasons: std::collections::BTreeMap<&'static str, u32> = std::collections::BTreeMap::new();
            let (mut needs, mut unaffordable) = (serde_json::Value::Null, serde_json::Value::Null);
            for k in (0..self.u.cat.objects.len()).filter(|&k| self.u.cat.objects[k].queue == 0) {
                let reason = self.u.cat.stall_of(planet, k);
                *planet_reasons.entry(reason).or_default() += 1;
                if reason == "needs" && needs.is_null() { needs = json!(self.u.cat.needs_first(planet, k)); }
                if reason == "never affordable" && unaffordable.is_null() { unaffordable = json!(self.u.cat.unaffordable_first(planet, k)); }
            }
            let used = self.u.cat.fields_used(planet);
            let cap = self.u.cat.field_cap(planet);
            // Row 42: is the object that adds fields itself startable here, and if not, what is the first thing its own
            // chain is missing - the reachability table's whole question. Found by its data (it adds fields at its next
            // level), never by name.
            let adder = (0..self.u.cat.objects.len())
                .find(|&k| self.u.cat.objects[k].fields_added.get(planet.levels[k] as usize + 1).copied().unwrap_or(0) > 0);
            let (adder_legal, adder_needs) = match adder {
                Some(k) => (self.u.world.legal(&self.u.cat, slot, k), json!(self.u.cat.needs_first(planet, k))),
                None => (false, serde_json::Value::Null),
            };
            // Row 67: whether this planet can build defence at all and how much it holds - a moon shot needs guns there.
            let units = &self.u.world.rules[slot].units;
            let defence: Vec<usize> = (0..units.units.len()).filter(|&u| units.kind.get(u) == Some(&UnitKind::Defence)).collect();
            json!({
                "slot": slot,
                "capital": slot == a,
                "defence_buildable": defence.iter().any(|&u| units.requirements_met(planet, u)),
                "defence_held": defence.iter().map(|&u| self.u.world.yards[slot].counts[u]).sum::<u64>(),
                "used": used,
                "cap": cap,
                "sealed": used >= cap,
                "reasons": planet_reasons,
                "needs_first": needs,
                "unaffordable_first": unaffordable,
                "adder_legal": adder_legal,
                "adder_needs": adder_needs,
            })
        }).collect();
        let lab = planets.iter().find(|&&slot| slot == a).map(|&slot| {
            let planet = &self.u.world.games[slot].planet;
            let mut lab_reasons: std::collections::BTreeMap<&'static str, u32> = std::collections::BTreeMap::new();
            let mut needs = serde_json::Value::Null;
            let mut unaffordable = serde_json::Value::Null;
            for k in (0..self.u.cat.objects.len()).filter(|&k| self.u.cat.objects[k].queue == 1) {
                let reason = self.u.cat.stall_of(planet, k);
                *lab_reasons.entry(reason).or_default() += 1;
                if reason == "needs" && needs.is_null() { needs = json!(self.u.cat.needs_first(planet, k)); }
                if reason == "never affordable" && unaffordable.is_null() { unaffordable = json!(self.u.cat.unaffordable_first(planet, k)); }
            }
            json!({ "reasons": lab_reasons, "needs_first": needs, "unaffordable_first": unaffordable })
        }).map_or(serde_json::Value::Null, |lab| lab);
        let units = &self.u.world.rules[a].units;
        let yard_capacity: f64 = (0..units.units.len())
            .map(|u| units.max_amount(&self.u.world.yards[a], planet, u) as f64 * weighted(units.units[u].price))
            .sum();
        // Every unit the account owns, at home, on its moons and in flight, by host id: the coverage gate reads it.
        let world = &self.u.world;
        let mut owned: BTreeMap<u32, u64> = BTreeMap::new();
        for &slot in planets.iter().chain(planets.iter().filter_map(|&p| world.moon_of[p].as_ref())) {
            for (x, n) in world.rules[slot].units.units.iter().zip(&world.yards[slot].counts) { *owned.entry(x.id as u32).or_default() += n }
        }
        for f in world.fleets.iter().filter(|f| world.account_of(f.owner) == a) {
            for &(id, n) in &f.ships { *owned.entry(id).or_default() += n.max(0) as u64 }
        }
        owned.retain(|_, n| *n > 0);
        json!({
            "units": owned.into_iter().collect::<Vec<_>>(),
            "blocked_hours": blocked,
            "buildings": reasons,
            "research": research_reasons,
            "surplus": surplus,
            "planets": census,
            "lab": lab,
            "yard_capacity": yard_capacity,
            "idle_hours": idle,
            "long_builds": long,
            "mod_build": levels(0),
            "mod_research": levels(1),
            "network": self.u.cat.research_network.map_or(0, |n| self.u.world.games[a].planet.levels[n]),
            "built": self.u.cat.objects.iter().enumerate().filter(|(_, o)| o.queue == 0).map(|(k, o)| (o.id, planets.iter().map(|&slot| self.u.world.games[slot].planet.levels[k]).collect::<Vec<_>>())).collect::<Vec<_>>(),
            "moon_built": self.u.cat.objects.iter().enumerate().filter(|(_, o)| o.queue == 0 && o.on_moon).map(|(k, o)| (o.id, planets.iter().filter_map(|&p| self.u.world.moon_of[p]).map(|m| self.u.world.games[m].planet.levels[k]).collect::<Vec<_>>())).collect::<Vec<_>>(),
            "tech": self.u.cat.objects.iter().enumerate().filter(|(_, o)| o.queue == 1).map(|(k, o)| (o.id, self.u.world.games[a].planet.levels[k])).collect::<Vec<_>>(),
            "tech_wait": self.u.cat.objects.iter().enumerate().filter(|&(k, o)| o.queue == 1 && self.u.world.legal(&self.u.cat, a, k)).map(|(k, o)| (o.id, (self.u.cat.wait_seconds(&self.u.world.games[a].planet, k) / 3600.0) as u32, (self.u.cat.build_seconds(&self.u.world.games[a].planet, k) / 3600.0) as u32)).collect::<Vec<_>>(),
            "labs": self.u.cat.modifiers[1].first().map_or(0, |&(lab, _)| planets.iter().map(|&slot| self.u.world.games[slot].planet.levels[lab]).sum::<u32>()),
            "next_id": next.map(|k| self.u.cat.objects[k].id),
            "next_seconds": next.map(|k| self.u.cat.build_seconds(planet, k)).map(|s| s / 3600.0),
            "next_wait": next.map(|k| self.u.cat.wait_seconds(planet, k)).map_or(serde_json::Value::Null, hours),
            // The lab at the day's end: running (hours left), saving for (object, what its price lacks), or idle.
            "lab_left": hours((self.u.world.games[a].free[1] - self.u.world.games[a].t).max(0.0)),
            "lab_pending": self.u.world.games[a].pending.iter().filter(|&&(_, k)| self.u.cat.objects[k].queue == 1).map(|&(_, k)| self.u.cat.objects[k].id).collect::<Vec<_>>(),
            "capital_res": planet.res.iter().map(|r| r.round()).collect::<Vec<f64>>(),
            "keep": self.u.world.games[a].commit[0].or_else(|| self.u.cat.baseline(planet, 0, Baseline::Player, &mut 0))
                .map(|b| self.u.cat.objects[b].price[planet.levels[b] as usize].to_vec()),
            "lab_commit": self.u.world.games[a].commit[1].map(|k| self.u.cat.objects[k].id),
            "lab_lacks": self.u.world.games[a].commit[1].map(|k| {
                let price = self.u.cat.objects[k].price[planet.levels[k] as usize];
                (0..3).map(|r| (price[r] - planet.res[r]).max(0.0).round()).collect::<Vec<f64>>()
            }),
        })
    }

    /// Counts this login for the account's day, and whether a store stood at 80% or more (row 11).
    fn touch(&mut self, a: usize, slot: usize) {
        let t = self.u.world.games[slot].t;
        let d = ((t / DAY) as usize).min(self.days - 1);
        let planet = &self.u.world.games[slot].planet;
        let full = (0..3).any(|r| planet.res[r] >= 0.8 * planet.cap[r]);
        // A session places several decisions per login, so a login is a decision whose second is in the login record
        // (row 12r): counting decisions here would read 57 "logins" a day where the account logged in twelve times.
        // The world's clock never goes back, so one remembered second per account counts each login once.
        let login = self.u.world.games[slot].login_times.iter().any(|&x| (x - t).abs() < 1e-6) && t != self.counted_login[a];
        if login { self.counted_login[a] = t }
        // Row 29: at a login that finds a store full, why the yard did or did not spend it.
        let yard = full && login;
        let stall = match yard {
            true => Some(self.u.players[a].yard_stall(&self.u.world, &self.u.cat, slot)),
            false => None,
        };
        let tally = &mut self.per_day[d][a];
        tally.logins += u32::from(login);
        tally.full_logins += u32::from(full && login);
        tally.full_yard[stall.unwrap_or(YardStall::Order) as usize] += u32::from(yard);
    }

    /// The account's logins during day `d`, as seconds of the day: the world's own login record, read forward once.
    fn logins_today(&mut self, a: usize, d: usize) -> Vec<i64> {
        let end = (d + 1) as f64 * DAY;
        let times = &self.u.world.games[self.u.world.account_of(a)].login_times;
        let mut at = self.login_at[a].min(times.len());
        let mut today = Vec::new();
        while at < times.len() && times[at] < end {
            today.push((times[at] % DAY) as i64);
            at += 1;
        }
        self.login_at[a] = at;
        today
    }
}

fn frozen_day(previous: Option<(f64, i64, f64)>, inactive: bool, logins: u32, produced: f64,
              stock: f64, score: i64, fleet: f64) -> bool {
    previous.is_some_and(|(old_stock, old_score, old_fleet)| {
        !inactive && logins > 0 && produced <= 1e-6 && (stock - old_stock).abs() < 1e-6
            && score == old_score && (fleet - old_fleet).abs() < 1e-6
    })
}

fn snapshot_time(until: f64, midnight: f64) -> f64 {
    if until.is_finite() { until } else { midnight }
}

fn excess_after(current: f64, previous: f64, delivered: f64) -> (f64, bool) {
    // Stock is stored to 14 significant digits (`as_stored`), so a large excess carries rounding a fixed epsilon refuses.
    let allowed = previous + delivered;
    (current.min(allowed), current > allowed + 1e-6 + 1e-9 * allowed)
}

#[cfg(test)]
mod health_tests {
    use super::{excess_after, frozen_day, snapshot_time};

    #[test]
    fn frozen_day_requires_an_active_login_and_no_economic_change() {
        let previous = Some((100.0, 42, 10.0));
        assert!(frozen_day(previous, false, 1, 0.0, 100.0, 42, 10.0));
        assert!(!frozen_day(previous, true, 1, 0.0, 100.0, 42, 10.0));
        assert!(!frozen_day(previous, false, 0, 0.0, 100.0, 42, 10.0));
        assert!(!frozen_day(previous, false, 1, 1.0, 100.0, 42, 10.0));
        assert!(!frozen_day(previous, false, 1, 0.0, 101.0, 42, 10.0));
    }

    #[test]
    fn final_flush_checks_against_the_day_not_infinity() {
        assert_eq!(snapshot_time(f64::INFINITY, 100.0), 100.0);
        assert!(101.0 > snapshot_time(f64::INFINITY, 100.0));
        assert_eq!(snapshot_time(120.0, 100.0), 120.0);
    }

    #[test]
    fn delivery_credit_expires_when_the_excess_is_spent() {
        let (excess, bad) = excess_after(50.0, 0.0, 50.0);
        assert!(!bad);
        let (excess, bad) = excess_after(0.0, excess, 0.0);
        assert!(!bad);
        assert!(excess_after(10.0, excess, 0.0).1);
    }

    #[test]
    fn rounding_of_a_large_excess_is_not_a_violation() {
        assert!(!excess_after(95_771_162.000_01, 84_639_043.0, 11_132_119.0).1);
        assert!(excess_after(95_771_163.0, 84_639_043.0, 11_132_119.0).1);
    }
}

/// Reads the events the world wrote since the last call into their day and account.
fn scan(w: &World, read: &mut [usize; 6], roles: &[usize], per_day: &mut [Vec<Tally>], days: usize, delivered: &mut Vec<[f64; 3]>) {
    delivered.resize(w.games.len(), [0.0; 3]);
    for &(slot, time, jump) in &w.gear_log[read[1]..] {
        let tally = &mut per_day[((time / DAY) as usize).min(days - 1)][w.account_of(slot)];
        if jump { tally.jump += 1 } else { tally.phalanx += 1 }
    }
    read[1] = w.gear_log.len();
    for &(slot, time, _, _) in &w.halving_log[read[2]..] {
        per_day[((time / DAY) as usize).min(days - 1)][w.account_of(slot)].halved += 1;
    }
    read[2] = w.halving_log.len();
    for &(slot, time) in &w.union_log[read[3]..] {
        per_day[((time / DAY) as usize).min(days - 1)][w.account_of(slot)].acs_attack += 1;
    }
    read[3] = w.union_log.len();
    for &(slot, time, _) in &w.wreck_log[read[4]..] {
        per_day[((time / DAY) as usize).min(days - 1)][w.account_of(slot)].wreck_repaired += 1;
    }
    read[4] = w.wreck_log.len();
    for &(slot, time) in &w.move_log[read[5]..] {
        per_day[((time / DAY) as usize).min(days - 1)][w.account_of(slot)].relocated += 1;
    }
    read[5] = w.move_log.len();
    while read[0] < w.events.len() {
        let e = &w.events[read[0]];
        read[0] += 1;
        let recipient = match (e.kind, e.mission) {
            (EventKind::Return, _) => Some(e.owner),
            (EventKind::Arrival, Mission::Transport | Mission::Deploy | Mission::Colonise) => Some(e.target),
            _ => None,
        };
        if let Some(slot) = recipient.filter(|&slot| slot < delivered.len()) {
            for r in 0..3 { delivered[slot][r] += e.gained[r].max(0.0); }
        }
        if e.kind == EventKind::Fight && e.target < delivered.len() {
            for r in 0..3 { delivered[e.target][r] += e.salvaged[r].max(0.0); }
        }
        record(&mut per_day[((e.time / DAY) as usize).min(days - 1)], e, w, roles);
    }
}

/// Books one event against the account it happened to (and, for a raid, the account it happened at).
fn record(day: &mut [Tally], e: &Event, w: &World, roles: &[usize]) {
    let price = |slot: usize, id: u32| w.rules[slot].units.index(id as i64)
        .map_or(0.0, |u| weighted(w.rules[slot].units.units[u].price));
    // A colony slot fights, raids and colonises for its account: every tally is per account.
    let (owner, target) = (w.account_of(e.owner), w.account_of(e.target));
    // Row 17: a fight is booked to the account that fought it, and so is the moon roll the host made on its debris -
    // `moon_chance` is zero when the debris is under the host's threshold or a moon already stands, so a non-zero
    // chance is exactly "this fight rolled for a moon".
    if e.kind == EventKind::Fight {
        day[owner].fights += 1;
        day[owner].moon_rolls += u32::from(e.moon_chance > 0);
        day[owner].moon_made += u32::from(e.moon_created);
    }
    match (e.mission, e.kind) {
        (Mission::Attack, EventKind::Fight) => {
            let lost = e.ships_lost.iter().map(|&(id, n)| price(e.owner, id) * n as f64).sum::<f64>();
            day[owner].raid_sent += 1;
            day[owner].moon_raid += u32::from(w.moon_at[e.target].is_some());
            day[owner].loot_value += weighted(e.gained);
            day[owner].ships_lost_value += lost;
            let lost = e.target_lost.iter().map(|&(id, n)| price(e.target, id) * n as f64).sum::<f64>();
            day[target].raid_received += 1;
            day[target].ships_lost_value += lost;
            // The log names the ships standing at home when the fight began: a saver's fleet was out (a save held) or it
            // was still there (the save came too late). An account that owns no fleet also reads empty; the ledger's own
            // dispatch-time check (`Raid::target_had_fleet`) is the upgrade once a season reads saves as a measure.
            // ponytail: a fleet-less account counts as a held save; raise it with the ledger's `target_had_fleet`.
            if roles[target] == SAVER {
                match e.present.is_empty() {
                    true => day[target].save_ok += 1,
                    false => day[target].save_late += 1,
                }
            }
        }
        (Mission::Espionage, EventKind::Arrival) => day[owner].spy_sent += 1,
        (Mission::Recycle, EventKind::Arrival) => day[owner].recycle += 1,
        (Mission::Expedition, EventKind::Arrival) => day[owner].expedition += 1,
        (Mission::Colonise, EventKind::Arrival) => day[owner].colonise += 1,
        (Mission::Missile, EventKind::Arrival) => day[owner].missile += 1,
        (Mission::MoonDestroy, EventKind::Arrival) => day[owner].moon_shot += 1,
        (Mission::AcsDefend, EventKind::Arrival | EventKind::Lost) => day[owner].acs_defend += 1,
        _ => {}
    }
}

/// One account's day, in the `events_today` fields of §9.1.
#[derive(Default, Clone)]
struct Tally {
    /// Logins this day, and of those how many found a store at 80% or more (row 11).
    logins: u32,
    full_logins: u32,
    spy_sent: u32,
    raid_sent: u32,
    raid_received: u32,
    save_ok: u32,
    save_late: u32,
    recycle: u32,
    expedition: u32,
    colonise: u32,
    missile: u32,
    moon_shot: u32,
    /// L5: holds at a mate's planet that ended (home again, or wiped out there).
    acs_defend: u32,
    /// L5: fleets that fought as a raid union's partner.
    acs_attack: u32,
    /// Raids on a moon (its parked fleet and stock).
    moon_raid: u32,
    /// Planets moved to a new slot.
    relocated: u32,
    /// Wreck fields: repaired ships taken back at the space dock.
    wreck_repaired: u32,
    /// M4: phalanx scans and jump gate jumps.
    phalanx: u32,
    jump: u32,
    /// L6: builds halved or completed with dark matter.
    halved: u32,
    /// Row 17: every fight, those of them whose debris cleared the host's moon threshold (a roll was made), and the
    /// moons the rolls made. `moon_shot` above is moon *destruction*, a different thing.
    fights: u32,
    moon_rolls: u32,
    moon_made: u32,
    ships_lost_value: f64,
    loot_value: f64,
    /// Row 29: at the logins that found a store at 80% or more, what the yard said - ordered, share met, nothing payable.
    full_yard: [u32; 3],
}

impl Tally {
    fn json(&self) -> serde_json::Value {
        json!({
            "logins": self.logins,
            "full_logins": self.full_logins,
            "full_yard": { "order": self.full_yard[0], "share_met": self.full_yard[1], "nothing_payable": self.full_yard[2] },
            "spy_sent": self.spy_sent,
            "raid_sent": self.raid_sent,
            "raid_received": self.raid_received,
            "save_ok": self.save_ok,
            "save_late": self.save_late,
            "recycle": self.recycle,
            "expedition": self.expedition,
            "colonise": self.colonise,
            "missile": self.missile,
            "moon_shot": self.moon_shot,
            "acs_defend": self.acs_defend,
            "acs_attack": self.acs_attack,
            "wreck_repaired": self.wreck_repaired,
            "moon_raid": self.moon_raid,
            "relocated": self.relocated,
            "phalanx": self.phalanx,
            "jump": self.jump,
            "halved": self.halved,
            "fights": self.fights,
            "moon_rolls": self.moon_rolls,
            "moon_made": self.moon_made,
            "ships_lost_value": self.ships_lost_value,
            "loot_value": self.loot_value,
        })
    }
}

/// Row 23: what one account-day booked, as the difference between two samples of the same books.
fn flows_of(before: &Books, now: &Books) -> serde_json::Value {
    json!({
        "produced": now.produced - before.produced,
        "wasted": now.wasted - before.wasted,
        "spent_build": now.spent_build - before.spent_build,
        "spent_research": now.spent_research - before.spent_research,
        "spent_units": now.spent_units - before.spent_units,
        "fuel": now.fuel - before.fuel,
        "loot_in": now.loot_in - before.loot_in,
        "loot_out": now.loot_out - before.loot_out,
        "expedition_in": now.expedition_in - before.expedition_in,
        "cargo_lost": now.cargo_lost - before.cargo_lost,
    })
}

fn weighted(resources: [f64; 3]) -> f64 {
    (0..3).map(|r| WEIGHTS[r] * resources[r]).sum()
}

fn median(values: &[f64]) -> f64 {
    let mut sorted = values.to_vec();
    sorted.sort_by(f64::total_cmp);
    match sorted.len() {
        0 => 0.0,
        n => sorted[n / 2],
    }
}
