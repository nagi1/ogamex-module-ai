//! Late-game bench (owner, 4 Nov: late game first, no run over ten minutes). Each seed's world is played by the personas
//! to day START once, then copied per candidate; every copy plays the same WINDOW days from the identical position with
//! the same dice, so the paired log-ratio of each account's points against the first candidate is the candidate's
//! late-game effect alone.
//!
//!     late <data> <catalogue.json> START WINDOW SEEDS(1,2,3) cand=NAME=SPEC... [world arms]
//!
//! SPEC: `persona`; `greedy:POLICY` or `sampled:POLICY` (every account's builds); `champ:BASE:HOURS:ROLLOUTS:STRIDE`
//! [:WIDTH] (accounts with owner % STRIDE == 0, trying only BASE's WIDTH best rows when given, look ahead with BASE playing their rollouts, `Universe::rollout_policy`; every
//! other account plays BASE sampled); `champv:` the same, its rollouts scored to the season's end (`value_to_end`).
//!
//! `es=OUT es-base=POLICY [es-minutes=9 es-sigma=0.02 es-lr=0.01]`: evolution strategies on the late game (`ogrl.es_world`'s
//! method: each account its own noise row, every position played at +sigma and -sigma with the same dice, ranked
//! ln-points differences as votes, Adam). The positions are the SEEDS' worlds at START, built once; each generation
//! reseeds their dice. Writes OUT/latest.policy.json every generation and OUT/es.jsonl. Errands stay with the personas in every candidate.

use std::sync::Arc;
use std::time::Instant;

use ogsim::score;
use ogsim::season::{Policy, Universe, DAY};
use ogsim::world::{Colonisation, Decision};

#[derive(Clone)]
enum Spec { Persona, Model(Arc<Policy>, bool), Champ(Arc<Policy>, f64, u64, usize, bool, usize), Each(Arc<Vec<Policy>>), Switch(f64, Box<Spec>, Box<Spec>), With(Vec<String>, Box<Spec>) }

fn spec(text: &str) -> Spec {
    let parts: Vec<&str> = text.split(':').collect();
    let load = |path: &str| Arc::new(Policy::load(path).expect("the policy weights"));
    match parts[0] {
        "persona" => Spec::Persona,
        "greedy" => Spec::Model(load(parts[1]), false),
        "sampled" => Spec::Model(load(parts[1]), true),
        "champ" | "champv" => Spec::Champ(load(parts[1]), parts[2].parse().expect("hours"), parts[3].parse().expect("rollouts"), parts[4].parse().expect("stride"), parts[0] == "champv", parts.get(5).map_or(0, |w| w.parse().expect("width"))),
        // `switch:DAY:FIRST|THEN`: FIRST's spec until DAY, THEN's after it (specs joined by `|`).
        "switch" => {
            let (first, then) = text.splitn(3, ':').nth(2).expect("switch:DAY:FIRST|THEN").split_once('|').expect("FIRST|THEN");
            Spec::Switch(parts[1].parse::<f64>().expect("a day") * DAY, Box::new(spec(first)), Box::new(spec(then)))
        }
        // `with:T1+T2:SPEC`: SPEC with the personas' tactic switches changed for every account (`tactics`).
        "with" => {
            let mut rest = text.splitn(3, ':').skip(1);
            let tactics = rest.next().expect("with:TACTICS:SPEC").split('+').map(String::from).collect();
            Spec::With(tactics, Box::new(spec(rest.next().expect("with:TACTICS:SPEC"))))
        }
        other => panic!("unknown candidate {other}"),
    }
}

/// Plays `u` until the clock reaches `until`; the decision found at or past it is handed back unplayed.
fn advance(u: &mut Universe, until: f64, midnight: &mut f64, pending: Option<usize>, how: &Spec, draws: &mut [u64]) -> Option<usize> {
    let mut next = pending.or_else(|| u.next_decision());
    while let Some(a) = next {
        let t = u.world.games[a].t;
        while *midnight <= t.min(until) { u.day_end(*midnight); *midnight += DAY }
        if t >= until { return Some(a) }
        let owner = u.world.account_of(a);
        let plays = u.plays(a);
        let build = plays && u.world.decision_kind(a) == Decision::Build;
        let how = match how { Spec::With(_, inner) => &**inner, other => other };
        let how = match how { Spec::Switch(day, first, then) => match t < *day { true => &**first, false => &**then }, other => other };
        let pick = match how {
            _ if !build => None,
            Spec::Persona => None,
            Spec::Model(p, sampled) => Some(u.choose(p, a, 1.0, sampled.then_some(&mut draws[owner]))),
            Spec::Champ(base, hours, rollouts, stride, _, width) if owner % stride == 0 => {
                let prior = (*width > 0).then(|| (&**base, *width));
                Some(u.lookahead(a, *hours, *rollouts, prior, None, true).pick)
            }
            Spec::Champ(base, ..) => Some(u.choose(base, a, 1.0, Some(&mut draws[owner]))),
            Spec::Each(all) => Some(u.choose(&all[owner], a, 1.0, Some(&mut draws[owner]))),
            Spec::Switch(..) | Spec::With(..) => unreachable!("unwrapped above"),
        };
        u.play(a, pick);
        next = u.next_decision();
    }
    None
}

fn main() {
    let args: Vec<String> = std::env::args().collect();
    let (data, econ) = (&args[1], &args[2]);
    let start: f64 = args[3].parse().expect("START day");
    let window: f64 = args[4].parse().expect("WINDOW days");
    let seeds: Vec<u64> = args[5].split(',').map(|s| s.parse().expect("seed")).collect();
    let cands: Vec<(String, Spec)> = args.iter().filter_map(|a| a.strip_prefix("cand=")).map(|c| {
        let (name, text) = c.split_once('=').expect("cand=NAME=SPEC");
        (name.to_string(), spec(text))
    }).collect();
    let cat = Arc::new(Universe::catalogue(data, econ, &args).expect("the economy catalogue"));
    let colonisation = Colonisation::load(econ).ok().map(Arc::new);
    let days = (start + window).ceil() as usize;
    let began = Instant::now();
    if let Some(out) = args.iter().find_map(|a| a.strip_prefix("es=")) {
        return evolve(out, &args, Arc::clone(&cat), colonisation, data, start, window, days, &seeds, began);
    }

    // Per seed, per candidate: each account's (points at START, points at the end, hourly production at the end), None
    // where it quit. Production is the leading measure: a late investment shows in it within days, in points only later.
    let results: Vec<Vec<Vec<Option<(f64, f64, f64, f64, f64, f64, f64)>>>> = std::thread::scope(|scope| {
        let jobs: Vec<_> = seeds.iter().map(|&seed| {
            let (cat, colonisation, cands, args) = (Arc::clone(&cat), colonisation.clone(), &cands, &args);
            scope.spawn(move || {
                let mut base = Universe::new(cat, colonisation, data, days, 64, seed, args);
                let accounts = base.accounts();
                let mut draws: Vec<u64> = (0..accounts as u64).map(|a| seed ^ 0x5A17 ^ (a + 1).wrapping_mul(0x9e37_79b9_7f4a_7c15)).collect();
                let mut midnight = DAY;
                let pending = advance(&mut base, start * DAY, &mut midnight, None, &Spec::Persona, &mut draws);
                let before: Vec<f64> = (0..accounts).map(|a| score::of(&base.world, a).total as f64).collect();
                eprintln!("seed {seed}: day {start} reached at {:.0}s", began.elapsed().as_secs_f64());
                std::thread::scope(|inner| {
                    let runs: Vec<_> = cands.iter().map(|(name, how)| {
                        let (mut u, mut draws, mut midnight, before) = (base.clone(), draws.clone(), midnight, before.clone());
                        if let Spec::With(names, _) = how { tactics(&mut u, names) }
                        if let Spec::Champ(rollout_base, _, _, _, to_end, _) = how {
                            u.rollout_policy = Some(Arc::clone(rollout_base));
                            u.value_to_end = to_end.then_some(365.0 * DAY);
                        }
                        inner.spawn(move || {
                            advance(&mut u, (start + window) * DAY, &mut midnight, pending, how, &mut draws);
                            eprintln!("seed {seed}: {name} done at {:.0}s", began.elapsed().as_secs_f64());
                            (0..accounts).map(|a| (!u.gone[a]).then(|| (before[a], score::of(&u.world, a).total as f64, u.world.hourly(a), u.world.planets_of(a).len() as f64, score::of(&u.world, a).research as f64, score::of(&u.world, a).military as f64, u.world.stock(a)))).collect::<Vec<_>>()
                        })
                    }).collect();
                    runs.into_iter().map(|r| r.join().expect("a candidate")).collect::<Vec<_>>()
                })
            })
        }).collect();
        jobs.into_iter().map(|j| j.join().expect("a seed")).collect()
    });

    println!("late bench: days {start}..{} on seeds {seeds:?}, {:.0}s", start + window, began.elapsed().as_secs_f64());
    for (c, (name, how)) in cands.iter().enumerate() {
        let stride = match how { Spec::Champ(_, _, _, s, _, _) => Some(*s), _ => None };
        let mut per_seed = Vec::new();
        let mut seat_seed = Vec::new();
        let mut growth = Vec::new();
        let mut hourly = Vec::new();
        for seed in &results {
            let pairs: Vec<(usize, f64)> = seed[c].iter().zip(&seed[0]).enumerate()
                .filter_map(|(a, (x, b))| Some((a, ((x.as_ref()?.1).max(1.0) / (b.as_ref()?.1).max(1.0)).ln())))
                .collect();
            per_seed.push(pairs.iter().map(|p| p.1).sum::<f64>() / pairs.len().max(1) as f64);
            let seats: Vec<f64> = pairs.iter().filter(|(a, _)| stride.is_some_and(|s| a % s == 0)).map(|p| p.1).collect();
            if !seats.is_empty() { seat_seed.push(seats.iter().sum::<f64>() / seats.len() as f64) }
            let rates: Vec<f64> = seed[c].iter().zip(&seed[0]).filter_map(|(x, b)| Some((x.as_ref()?.2.max(1.0) / b.as_ref()?.2.max(1.0)).ln())).collect();
            hourly.push(rates.iter().sum::<f64>() / rates.len().max(1) as f64);
            let grown: Vec<f64> = seed[c].iter().flatten().map(|(b, e, ..)| (e.max(1.0) / b.max(1.0)).ln()).collect();
            growth.push(grown.iter().sum::<f64>() / grown.len().max(1) as f64);
        }
        let mean = |v: &[f64]| v.iter().sum::<f64>() / v.len().max(1) as f64;
        let up = per_seed.iter().filter(|&&x| x > 0.0).count();
        print!("{name:>12}: vs {} points x{:.3} (seeds up {up}/{}), production x{:.3}, own growth x{:.2}", cands[0].0, mean(&per_seed).exp(), per_seed.len(), mean(&hourly).exp(), mean(&growth).exp());
        let alive: Vec<&(f64, f64, f64, f64, f64, f64, f64)> = results.iter().flat_map(|seed| seed[c].iter().flatten()).collect();
        let avg = |f: &dyn Fn(&(f64, f64, f64, f64, f64, f64, f64)) -> f64| alive.iter().map(|x| f(x)).sum::<f64>() / alive.len().max(1) as f64;
        print!(", planets {:.1}, research {:.1}M, military {:.1}M, points {:.1}M, unspent {:.1}G", avg(&|x| x.3), avg(&|x| x.4) / 1e6, avg(&|x| x.5) / 1e6, avg(&|x| x.1) / 1e6, avg(&|x| x.6) / 1e9);
        if !seat_seed.is_empty() { print!(", look-ahead seats x{:.3}", mean(&seat_seed).exp()) }
        println!();
    }
}

/// A position: a world at START with its sampling draws, its next midnight and the decision it stopped on.
type Position = (Universe, Vec<u64>, f64, Option<usize>);

fn position(cat: Arc<ogsim::economy::Catalogue>, colonisation: Option<Arc<Colonisation>>, data: &str, days: usize, seed: u64, start: f64, args: &[String]) -> Position {
    let mut u = Universe::new(cat, colonisation, data, days, 64, seed, args);
    let mut draws: Vec<u64> = (0..u.accounts() as u64).map(|a| seed ^ 0x5A17 ^ (a + 1).wrapping_mul(0x9e37_79b9_7f4a_7c15)).collect();
    let mut midnight = DAY;
    let pending = advance(&mut u, start * DAY, &mut midnight, None, &Spec::Persona, &mut draws);
    (u, draws, midnight, pending)
}

/// Standard normal noise from (generation, position, account), regenerated rather than stored on disk.
fn noise(gen: u64, p: usize, a: usize, n: usize) -> Vec<f32> {
    let mut state = (gen << 40) ^ ((p as u64) << 20) ^ a as u64 ^ 0xd1b5_4a32_d192_ed03;
    let mut uniform = || { state = state.wrapping_add(0x9e37_79b9_7f4a_7c15); let mut z = state; z = (z ^ (z >> 30)).wrapping_mul(0xbf58_476d_1ce4_e5b9); z = (z ^ (z >> 27)).wrapping_mul(0x94d0_49bb_1331_11eb); ((z ^ (z >> 31)) >> 11) as f64 / (1u64 << 53) as f64 };
    (0..n).map(|_| ((-2.0 * (1.0 - uniform()).ln()).sqrt() * (std::f64::consts::TAU * uniform()).cos()) as f32).collect()
}

#[allow(clippy::too_many_arguments)]
fn evolve(out: &str, args: &[String], cat: Arc<ogsim::economy::Catalogue>, colonisation: Option<Arc<Colonisation>>, data: &str, start: f64, window: f64, days: usize, seeds: &[u64], began: Instant) {
    let get = |key: &str, default: f64| args.iter().find_map(|a| a.strip_prefix(key)).map_or(default, |v| v.parse().expect("a number"));
    let (minutes, sigma, lr) = (get("es-minutes=", 9.0), get("es-sigma=", 0.02) as f32, get("es-lr=", 0.01) as f32);
    let base_path = args.iter().find_map(|a| a.strip_prefix("es-base=")).expect("es-base=POLICY");
    let template: serde_json::Value = serde_json::from_str(&std::fs::read_to_string(base_path).expect("the base policy")).expect("its JSON");
    let base = Policy::parse(&template).expect("the base policy");
    std::fs::create_dir_all(out).expect("the output directory");
    let positions: Vec<Position> = std::thread::scope(|scope| {
        let jobs: Vec<_> = seeds.iter().map(|&seed| { let (cat, colonisation) = (Arc::clone(&cat), colonisation.clone()); scope.spawn(move || position(cat, colonisation, data, days, seed, start, args)) }).collect();
        jobs.into_iter().map(|j| j.join().expect("a position")).collect()
    });
    eprintln!("{} positions at day {start} in {:.0}s", positions.len(), began.elapsed().as_secs_f64());
    let mut theta = base.params();
    let n = theta.len();
    let (mut m, mut v) = (vec![0.0f32; n], vec![0.0f32; n]);
    let mut gen = 0u64;
    while began.elapsed().as_secs_f64() < minutes * 60.0 {
        gen += 1;
        let clock = Instant::now();
        let policy = base.with_params(&theta);
        // Per position and sign, each account's ln(1 + points) at the window's end, None where it quit.
        let ends: Vec<[Vec<Option<f64>>; 2]> = std::thread::scope(|scope| {
            let jobs: Vec<_> = positions.iter().enumerate().map(|(p, (u, draws, midnight, pending))| {
                let policy = &policy;
                scope.spawn(move || {
                    let rows: Vec<Vec<f32>> = (0..u.accounts()).map(|a| noise(gen, p, a, n)).collect();
                    let play = |sign: f32| {
                        let all = Arc::new(rows.iter().map(|row| policy.nudged(row, sign * sigma)).collect::<Vec<_>>());
                        let (mut w, mut d, mut night) = (u.clone(), draws.clone(), *midnight);
                        w.world.reseed(gen);
                        advance(&mut w, (start + window) * DAY, &mut night, *pending, &Spec::Each(all), &mut d);
                        (0..w.accounts()).map(|a| (!w.gone[a]).then(|| (score::of(&w.world, a).total as f64).ln_1p())).collect::<Vec<_>>()
                    };
                    std::thread::scope(|inner| {
                        let up = inner.spawn(|| play(1.0));
                        let down = play(-1.0);
                        [up.join().expect("the + run"), down]
                    })
                })
            }).collect();
            jobs.into_iter().map(|j| j.join().expect("a position")).collect()
        });
        let mut votes = Vec::new();
        for (p, [up, down]) in ends.iter().enumerate() {
            for a in 0..up.len() {
                if let (Some(x), Some(y)) = (up[a], down[a]) { votes.push((p, a, x - y, x, y)) }
            }
        }
        // Centred ranks (Salimans et al.): one lucky account moves the step no more than its rank.
        let mut order: Vec<usize> = (0..votes.len()).collect();
        order.sort_by(|&i, &j| votes[i].2.total_cmp(&votes[j].2));
        let mut weight = vec![0.0f32; votes.len()];
        for (rank, &i) in order.iter().enumerate() { weight[i] = rank as f32 / (votes.len().max(2) - 1) as f32 - 0.5 }
        let mut grad = vec![0.0f32; n];
        for (i, &(p, a, ..)) in votes.iter().enumerate() {
            for (g, e) in grad.iter_mut().zip(noise(gen, p, a, n)) { *g += weight[i] * e }
        }
        let t = gen as i32;
        for k in 0..n {
            let g = grad[k] / votes.len().max(1) as f32 / sigma;
            m[k] = 0.9 * m[k] + 0.1 * g;
            v[k] = 0.999 * v[k] + 0.001 * g * g;
            theta[k] += lr * (m[k] / (1.0 - 0.9f32.powi(t))) / ((v[k] / (1.0 - 0.999f32.powi(t))).sqrt() + 1e-8);
        }
        let saved = serde_json::to_string(&base.with_params(&theta).to_json(&template)).expect("the policy JSON");
        std::fs::write(format!("{out}/latest.policy.json"), &saved).expect("write the policy");
        let mean_ln = votes.iter().map(|x| (x.3 + x.4) / 2.0).sum::<f64>() / votes.len().max(1) as f64;
        let line = serde_json::json!({"gen": gen, "votes": votes.len(), "mean_ln_points": mean_ln, "vote_mean_abs": votes.iter().map(|x| x.2.abs()).sum::<f64>() / votes.len().max(1) as f64, "seconds": clock.elapsed().as_secs_f64().round()});
        println!("{line}");
        use std::io::Write;
        writeln!(std::fs::OpenOptions::new().create(true).append(true).open(format!("{out}/es.jsonl")).expect("the log"), "{line}").expect("write the log");
    }
}

/// Turns the personas' tactic switches on (`name`) or off (`-name`) for every account, as `Universe::new` sets them
/// from the world's arms: `cap` stops the yard's surplus spending at the persona's military share.
fn tactics(u: &mut Universe, names: &[String]) {
    for player in u.players.iter_mut() {
        let m = player.miner();
        for name in names {
            let (on, flag) = name.strip_prefix('-').map_or((true, name.as_str()), |f| (false, f));
            let field = match flag {
                "cap" => &mut m.cap,
                "yard" => &mut m.yard,
                "ships" => &mut m.ships,
                "expofull" => &mut m.expo_full,
                "exposhield" => &mut m.expo_ship,
                "doubler" => &mut m.doubler,
                "feed" => &mut m.feed,
                "factory" => &mut m.factory,
                "satellites" => &mut m.satellites,
                other => panic!("unknown tactic {other}"),
            };
            *field = on;
        }
    }
}
