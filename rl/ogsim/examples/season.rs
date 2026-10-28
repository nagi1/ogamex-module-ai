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

use std::collections::BTreeSet;
use std::fs::File;
use std::io::{BufWriter, Write};
use std::sync::Arc;

use ogsim::fleet::Coord;
use ogsim::players::{self, account_value, FleetSaver, Ledger, Miner, Player, Raider, YardStall};
use ogsim::score::{self, Score};
use ogsim::economy::{Baseline, Books};
use ogsim::units::UnitKind;
use ogsim::world::{Colonisation, Event, EventKind, Mission, Rules, World};
use ogsim::{next_random, Catalogue, WEIGHTS};
use serde_json::json;

const DAY: f64 = 86_400.0;
/// Personas in role order, with the share of the accounts each gets.
const PERSONAS: [(&str, f64); 3] = [("miner", 0.4), ("raider", 0.3), ("saver", 0.3)];
const RAIDER: usize = 1;
const SAVER: usize = 2;
/// An account that stops playing draws its quit day from an exponential with this mean, in days.
const QUIT_MEAN_DAYS: f64 = 15.0;
/// Row 15: the alliance join day is drawn five days after the third (exponential). 65% and the mean are assumptions -
/// the bands say only that 65% of real active players are in an alliance (p10 24%, p90 77%) - so they are stated here.
const ALLIANCE_SHARE: u64 = 65;
const ALLIANCE_MEAN_DAYS: f64 = 5.0;

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
    let mut cat = Catalogue::load(&econ).expect("the economy catalogue");
    // The accounts' own login gaps where the corpus has them: the host rule reads this table, so a season must play real ones.
    cat.login_gaps = std::fs::read_to_string(format!("{data}/login-gaps.json")).ok()
        .and_then(|text| serde_json::from_str::<Vec<f64>>(&text).ok())
        .filter(|gaps| !gaps.is_empty())
        .unwrap_or_else(|| vec![600.0, 1800.0, 3600.0, 7200.0, 21600.0]);
    // Row 23's ceiling arm: no gaps at all is the economy's own "always there" path, so it bounds what any cadence
    // change can buy. Quitting, personas and everything else stay as they are.
    if std::env::args().any(|arg| arg == "always-online") {
        cat.login_gaps.clear();
    }
    let rules = Arc::new(Rules::load(&cat,
        &format!("{data}/units-parity/units-u1.json"),
        &format!("{data}/fleet-settings-u1.json"),
        &format!("{data}/combat-u1.json")).expect("the units, fleet and combat exports"));

    // One shipyard table per home planet: a colony site has none, so a season starts its accounts on homes.
    let configs: Vec<usize> = (0..accounts).map(|a| a % rules.units.planets.len()).collect();
    let mut world = World::new(&cat, &configs, days as f64 * 24.0, 0.0, seed);
    // The host's colonisation tables, when the catalogue carries them: without them no account can found a colony.
    world.colonisation = Colonisation::load(&econ).ok().map(Arc::new);
    // Row 66: how many galaxies each account colonises in, 1 to 7 alike - an assumption, as the alliance draw is: the
    // bands say real mid accounts hold 1 / 3 / 6 galaxies at p10 / p50 / p90. Its own stream leaves the other draws as they were.
    if std::env::args().any(|arg| arg == "reach") {
        let mut draw = seed ^ 0x5eed_6a1a;
        world.reach = (0..world.games.len()).map(|_| 1 + (next_random(&mut draw) % 7) as u32).collect();
    }
    let moon_shots = std::env::args().any(|arg| arg == "moonstack");
    // A fully built account still logs in, to raid or to save its fleet.
    world.enable_idle_logins(&cat);
    // Three galaxies, a system each, wide apart: a raider has neighbours to fly at and a saver has somewhere to be away.
    let systems = rules.settings.number_of_systems as i64;
    let coords: Vec<Coord> = (0..accounts as i64).map(|a| Coord {
        galaxy: 1 + a % 3,
        system: 1 + (a * 167) % systems,
        position: 4 + a % 9,
    }).collect();
    world.arm(vec![Arc::clone(&rules); accounts], coords);

    let mut rng = seed ^ 0x5eed_1234;
    let mut roles: Vec<usize> = (0..PERSONAS.len())
        .flat_map(|r| std::iter::repeat(r).take((accounts as f64 * PERSONAS[r].1).round() as usize))
        .collect();
    while roles.len() < accounts { roles.push(0) }
    roles.truncate(accounts);
    shuffle(&mut roles, &mut rng);
    let mut order: Vec<usize> = (0..accounts).collect();
    shuffle(&mut order, &mut rng);
    // Half the accounts stop playing, each at its own quit day: a quitter's last login is the day it leaves.
    let mut quit: Vec<Option<f64>> = vec![None; accounts];
    for &a in &order[..accounts / 2] {
        let u = ((next_random(&mut rng) >> 11) as f64 + 0.5) / (1u64 << 53) as f64;
        quit[a] = Some(-u.ln() * QUIT_MEAN_DAYS * DAY);
    }

    // Row 15: an account still playing at day 3 joins an alliance, at a drawn day; a quitter never joins. Membership
    // is the season's data, not a world mechanic (the world has no alliances).
    let mut alliance_at: Vec<Option<f64>> = vec![None; accounts];
    for a in 0..accounts {
        let quits = quit[a].unwrap_or(f64::INFINITY);
        if quits <= 3.0 * DAY || next_random(&mut rng) % 100 >= ALLIANCE_SHARE {
            continue;
        }
        let u = ((next_random(&mut rng) >> 11) as f64 + 0.5) / (1u64 << 53) as f64;
        let join = 3.0 * DAY + -u.ln() * ALLIANCE_MEAN_DAYS * DAY;
        if join < quits {
            alliance_at[a] = Some(join);
        }
    }

    let mut players: Vec<Player> = (0..accounts).map(|a| {
        let s = seed.wrapping_mul(100).wrapping_add(a as u64);
        match roles[a] {
            RAIDER => Player::Raider(Raider::new(s)),
            SAVER => Player::Saver(FleetSaver::new(s)),
            _ => Player::Miner(Miner::new(s)),
        }
    }).collect();
    // Row 45 arm C: every persona brings its colonies' surplus home at each login.
    // Row 45 arm FR: every persona keeps its planets' last fields for the field-adding chain.
    // Row 46: the stack of both.
    let consolidate = std::env::args().any(|arg| arg == "consolidate" || arg == "stack" || arg == "yardstack" || arg == "moonstack");
    let reserve = std::env::args().any(|arg| arg == "reserve" || arg == "stack" || arg == "yardstack" || arg == "moonstack");
    // Row 33: the spending order, alone and on the kept stack.
    let yard = std::env::args().any(|arg| arg == "yard" || arg == "yardstack" || arg == "moonstack");
    // N6b: every persona fills its expedition slots.
    let expeditions = std::env::args().any(|arg| arg == "expedition");
    for player in &mut players {
        let miner = player.miner();
        miner.consolidate = consolidate;
        miner.reserve = reserve;
        miner.yard = yard;
        miner.expeditions = expeditions;
    }

    if let Some(dir) = std::path::Path::new(&out_path).parent().filter(|d| !d.as_os_str().is_empty()) {
        std::fs::create_dir_all(dir).expect("the output directory");
    }
    let out = BufWriter::new(File::create(&out_path).expect("the output file"));
    // Row 23: the books and the stock at the last line written, so a day is the difference of two samples.
    let since: Vec<(Books, f64)> = (0..accounts).map(|a| (world.books(a), world.stock(a))).collect();
    let mut season = Season {
        world: &mut world,
        cat: &cat,
        roles: &roles,
        players,
        ledgers: (0..accounts).map(|_| Ledger::default()).collect(),
        out,
        per_day: vec![vec![Tally::default(); accounts]; days],
        since,
        login_at: vec![0; accounts],
        counted_login: vec![f64::NEG_INFINITY; accounts],
        alliance_at,
        read: 0,
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
    };

    let start = std::time::Instant::now();
    let mut gone = vec![false; accounts];
    while let Some(a) = season.world.next_decision(season.cat) {
        // A colony slot decides too; its player, quit day and ledger are its account's.
        let owner = season.world.account_of(a);
        let t = season.world.games[a].t;
        season.flush(t);
        if let Some(q) = quit[owner] {
            if !gone[owner] && t >= q {
                retire(season.world, owner, q);
                gone[owner] = true;
            }
        }
        if gone[owner] {
            season.world.wait(season.cat, a);
            continue;
        }
        season.touch(owner, a);
        let pick = season.players[owner].act(&mut *season.world, season.cat, a, &mut season.ledgers[owner]);
        // Row 64: alliance members shoot moons for each other at their logins (membership is the season's data).
        if moon_shots && a == owner && season.alliance_at[owner].is_some_and(|join| t >= join) {
            let alliance_at = &season.alliance_at;
            players::moon_shot(&mut *season.world, season.cat, a, |b| alliance_at[b].is_some_and(|join| t >= join) && !gone[b], &mut season.ledgers[owner]);
            players::ready_for_moon(&mut *season.world, season.cat, a);
        }
        match pick {
            Some(k) => season.world.commit(season.cat, a, k),
            None => season.world.wait(season.cat, a),
        }
    }
    season.flush(f64::INFINITY);
    let lines = season.lines;
    let frozen_days = season.frozen_days;
    let bad_stock = season.bad_stock;
    let unbalanced = season.unbalanced;
    let ahead = season.ahead;
    let production_drops = season.production_drops;
    let over_cap = season.over_cap;
    let seconds = start.elapsed().as_secs_f64();
    drop(season);

    let count = |role: usize| roles.iter().filter(|&&r| r == role).count();
    let quit_days: Vec<f64> = quit.iter().flatten().map(|d| d / DAY).collect();
    println!("{}", json!({
        "seed": seed,
        "days": days,
        "accounts": accounts,
        "world_speed": cat.speed,
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

/// The account's owner stopped coming at `quit`: the login record ends there, which is what `World::inactive` reads, and
/// its clock stops, so nothing of it is decided afterwards.
fn retire(w: &mut World, a: usize, quit: f64) {
    // Every planet the account holds stops with it, colonies included.
    for slot in w.planets_of(a) {
        let end = quit.max(w.games[slot].t);
        let game = &mut w.games[slot];
        game.horizon = end;
        game.login_times.retain(|&t| t <= end);
        if game.login_times.is_empty() { game.login_times.push(end) }
        // A login no clock reaches: the record has no entry after the quit day, and `Catalogue::login` draws none.
        game.login_times.push(f64::INFINITY);
    }
}

/// A season's output: the world, the day's buckets still filling, and the lines written so far.
struct Season<'a, W: Write> {
    world: &'a mut World,
    cat: &'a Catalogue,
    roles: &'a [usize],
    players: Vec<Player>,
   /// What each account did.
    ledgers: Vec<Ledger>,
    out: W,
    /// Per day and account, what the world played that day.
    per_day: Vec<Vec<Tally>>,
    /// Per account, its books and its stock at the last line written (row 23).
    since: Vec<(Books, f64)>,
    /// Per account, how far its login record has been read for the days already written.
    login_at: Vec<usize>,
    /// The last login second counted per account: every planet may decide at it, and it is one login.
    counted_login: Vec<f64>,
    /// Per account, the second it joined an alliance (row 15), or `None` if it never did.
    alliance_at: Vec<Option<f64>>,
    /// How many events of the world's log have been read.
    read: usize,
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
}

impl<W: Write> Season<'_, W> {
    /// Writes the line of every day that ends at or before `until` (every day left, with `INFINITY`).
    fn flush(&mut self, until: f64) {
        while self.midnight <= until && self.midnight <= self.days as f64 * DAY {
            let d = (self.midnight / DAY) as usize - 1;
            scan(self.world, &mut self.read, self.roles, &mut self.per_day, self.days, &mut self.delivered);
            self.write_day(d, snapshot_time(until, self.midnight));
            // N6b: an expedition's find bracket reads the universe's rank-1 score, which the host's highscore refreshes
            // daily; the world's own leader stands in for it instead of a fixed number.
            self.world.rank1_points = (0..self.roles.len()).map(|a| score::of(self.world, a).total).max().unwrap_or(0);
            self.lines += self.world.games.len();
            self.midnight += DAY;
        }
    }

    /// One line per account for the day `d` (0-based; the line says `d + 1`), as §9.1 of the plan fixes it.
    fn write_day(&mut self, d: usize, world_time: f64) {
        let at = (d + 1) as f64 * DAY;
        for a in 0..self.roles.len() {
            // Inactive is the world's own flag (last login more than seven days ago), not the quit day we drew.
            let inactive = self.world.inactive(self.cat, a, at);
            // An account's value is every planet it holds, as `score::of` already sums it.
            let planets = self.world.planets_of(a);
            let value: f64 = planets.iter().map(|&s| account_value(self.world, s)).sum();
            let economy: f64 = planets.iter().map(|&s| self.world.games[s].value()).sum();
            // The host's own four scores (row N1: `score::of` equals HighscoreService on 600 drawn accounts,
            // 0 differ to the integer). Every band in the plan is written in this score, so the line says so.
            let score = score::of(self.world, a);
            let galaxies: BTreeSet<i64> = planets.iter().map(|&s| self.world.coords[s].galaxy).collect();
            let moons = planets.iter().filter(|&&s| self.world.moons[s]).count();
            let logins = self.logins_today(a, d);
            let busy = self.world.busy_seconds(a, at - DAY, at);
            let tally = &self.per_day[d][a];
            // Row 29: what holds each queue of this account's planets, in hours, and the host's time modifiers for them.
            let stall = self.stall(a, &planets);
            // Row 23: the day's flows are the difference of two samples of the same second, so a line whose events
            // all fell inside it closes by construction and one the world moved without booking a flow does not.
            let books = self.world.books(a);
            let stock = self.world.stock(a);
            let (before, was) = self.since[a];
            let flows = flows_of(&before, &books);
            let unexplained = (stock - was) - (books.net() - before.net());
            self.since[a] = (books, stock);
            let fleet = value - economy;
            let frozen = frozen_day(self.previous[a], inactive, tally.logins, books.produced - before.produced, stock, score.total, fleet);
            if frozen {
                self.frozen_days += 1;
            }
            self.previous[a] = Some((stock, score.total, fleet));
            let invalid_stock = planets.iter().any(|&p| self.world.games[p].planet.res.iter().any(|r| !r.is_finite() || *r < -1e-6));
            let clock_ahead = planets.iter().any(|&p| self.world.games[p].t > world_time + 1e-6);
            self.excess.resize(self.world.games.len(), [0.0; 3]);
            self.seen_planet.resize(self.world.games.len(), false);
            let unexplained_over_cap = planets.iter().fold(false, |bad, &p| {
                let planet = &self.world.games[p].planet;
                let mut invalid = false;
                for r in 0..3 {
                    let current = (planet.res[r] - planet.cap[r]).max(0.0);
                    let initial = if !self.seen_planet[p] { (self.cat.planets[planet.config].resources[r] - planet.cap[r]).max(0.0) } else { 0.0 };
                    let (next, bad) = excess_after(current, self.excess[p][r], self.delivered.get(p).map_or(0.0, |v| v[r]) + initial);
                    invalid |= bad;
                    self.excess[p][r] = next;
                    if let Some(delivered) = self.delivered.get_mut(p) { delivered[r] = 0.0; }
                }
                self.seen_planet[p] = true;
                bad || invalid
            });
            let factors: Vec<(usize, f64)> = planets.iter().map(|&p| (p, self.world.games[p].planet.factor)).collect();
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
                "world_speed": self.cat.speed as i64,
                "day": d + 1,
                "account": a,
                "persona": PERSONAS[self.roles[a]].0,
                "controlled": false,
                "health": {"frozen": frozen, "invalid_stock": invalid_stock, "unbalanced": unbalanced,
                           "clock_ahead": clock_ahead, "unexplained_over_cap": unexplained_over_cap,
                           "production_drop_warning": production_drop},
                "clock_ahead_detail": planets.iter().filter_map(|&p| {
                    (self.world.games[p].t > world_time + 1e-6).then_some(json!({"slot": p, "planet_time": self.world.games[p].t, "world_time": world_time, "day_end": at}))
                }).collect::<Vec<_>>(),
                "inactive": inactive,
                "score_source": "host",
                "score": { "total": score.total, "economy": score.economy, "research": score.research,
                           "military": score.military },
                "planets": planets.len(),
                "moons": moons,
                "galaxies": galaxies.len(),
                "alliance": self.alliance_at[a].is_some_and(|join| at >= join),
                "value": value,
                "fleet_value": value - economy,
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
            let (now, pending) = (self.world.games[slot].t, &self.world.games[slot].pending);
            let left: f64 = pending.iter().filter(|&&(_, k)| self.cat.objects[k].queue == 0)
                .map(|&(end, _)| (end - now).max(0.0) / 3600.0).fold(0.0, f64::max);
            blocked += left.min(24.0);
            idle += 24.0 - left.min(24.0);
            long += u32::from(left > 24.0);
        }
        let planet = &self.world.games[a].planet;
        let levels = |queue: usize| -> Vec<(i64, u32)> {
            self.cat.modifiers[queue].iter().map(|&(k, _)| (self.cat.objects[k].id, planet.levels[k])).collect()
        };
        let mut rng = self.seed ^ (self.midnight.to_bits());
        let next = self.cat.baseline(planet, 0, Baseline::Player, &mut rng);
        let hours = |seconds: f64| if seconds >= 1e9 { serde_json::Value::Null } else { json!(seconds / 3600.0) };
        // Row 29, extended by row 37: why each queue's objects cannot start, counted for **every** planet the account
        // holds (the building queue) and for the account's lab, which is the capital's; and the stock above two hours of
        // each planet's own production, which is the surplus a yard order is meant to spend.
        let mut reasons: std::collections::BTreeMap<&'static str, u32> = std::collections::BTreeMap::new();
        let mut research_reasons: std::collections::BTreeMap<&'static str, u32> = std::collections::BTreeMap::new();
        for &slot in planets {
            for k in (0..self.cat.objects.len()).filter(|&k| self.cat.objects[k].queue == 0) {
                *reasons.entry(self.cat.stall_of(&self.world.games[slot].planet, k)).or_default() += 1;
            }
        }
        for k in (0..self.cat.objects.len()).filter(|&k| self.cat.objects[k].queue == 1) {
            *research_reasons.entry(self.cat.stall_of(planet, k)).or_default() += 1;
        }
        let surplus: f64 = planets.iter().map(|&slot| {
            let planet = &self.world.games[slot].planet;
            let reserve: f64 = (0..3).map(|r| WEIGHTS[r] * planet.rate[r].max(0.0) * 2.0).sum();
            (weighted(planet.res) - reserve).max(0.0)
        }).sum();
        // Row 42: the census proper - every planet on its own line (used and added fields, the reasons its building objects
        // cannot start, the first missing prerequisite and the first resource its price cannot fit), the lab on the planet
        // that pays for it, and the yard's own bound: what the surplus is worth against what the yard could start at all.
        let census: Vec<serde_json::Value> = planets.iter().map(|&slot| {
            let planet = &self.world.games[slot].planet;
            let mut planet_reasons: std::collections::BTreeMap<&'static str, u32> = std::collections::BTreeMap::new();
            let (mut needs, mut unaffordable) = (serde_json::Value::Null, serde_json::Value::Null);
            for k in (0..self.cat.objects.len()).filter(|&k| self.cat.objects[k].queue == 0) {
                let reason = self.cat.stall_of(planet, k);
                *planet_reasons.entry(reason).or_default() += 1;
                if reason == "needs" && needs.is_null() { needs = json!(self.cat.needs_first(planet, k)); }
                if reason == "never affordable" && unaffordable.is_null() { unaffordable = json!(self.cat.unaffordable_first(planet, k)); }
            }
            let used = self.cat.fields_used(planet);
            let cap = self.cat.field_cap(planet);
            // Row 42: is the object that adds fields itself startable here, and if not, what is the first thing its own
            // chain is missing - the reachability table's whole question. Found by its data (it adds fields at its next
            // level), never by name.
            let adder = (0..self.cat.objects.len())
                .find(|&k| self.cat.objects[k].fields_added.get(planet.levels[k] as usize + 1).copied().unwrap_or(0) > 0);
            let (adder_legal, adder_needs) = match adder {
                Some(k) => (self.world.legal(self.cat, slot, k), json!(self.cat.needs_first(planet, k))),
                None => (false, serde_json::Value::Null),
            };
            // Row 67: whether this planet can build defence at all and how much it holds - a moon shot needs guns there.
            let units = &self.world.rules[slot].units;
            let defence: Vec<usize> = (0..units.units.len()).filter(|&u| units.kind.get(u) == Some(&UnitKind::Defence)).collect();
            json!({
                "slot": slot,
                "capital": slot == a,
                "defence_buildable": defence.iter().any(|&u| units.requirements_met(planet, u)),
                "defence_held": defence.iter().map(|&u| self.world.yards[slot].counts[u]).sum::<u64>(),
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
            let planet = &self.world.games[slot].planet;
            let mut lab_reasons: std::collections::BTreeMap<&'static str, u32> = std::collections::BTreeMap::new();
            let mut needs = serde_json::Value::Null;
            let mut unaffordable = serde_json::Value::Null;
            for k in (0..self.cat.objects.len()).filter(|&k| self.cat.objects[k].queue == 1) {
                let reason = self.cat.stall_of(planet, k);
                *lab_reasons.entry(reason).or_default() += 1;
                if reason == "needs" && needs.is_null() { needs = json!(self.cat.needs_first(planet, k)); }
                if reason == "never affordable" && unaffordable.is_null() { unaffordable = json!(self.cat.unaffordable_first(planet, k)); }
            }
            json!({ "reasons": lab_reasons, "needs_first": needs, "unaffordable_first": unaffordable })
        }).map_or(serde_json::Value::Null, |lab| lab);
        let units = &self.world.rules[a].units;
        let yard_capacity: f64 = (0..units.units.len())
            .map(|u| units.max_amount(&self.world.yards[a], planet, u) as f64 * weighted(units.units[u].price))
            .sum();
        json!({
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
            "next_id": next.map(|k| self.cat.objects[k].id),
            "next_seconds": next.map(|k| self.cat.build_seconds(planet, k)).map(|s| s / 3600.0),
            "next_wait": next.map(|k| self.cat.wait_seconds(planet, k)).map_or(serde_json::Value::Null, hours),
        })
    }

    /// Counts this login for the account's day, and whether a store stood at 80% or more (row 11).
    fn touch(&mut self, a: usize, slot: usize) {
        let t = self.world.games[slot].t;
        let d = ((t / DAY) as usize).min(self.days - 1);
        let planet = &self.world.games[slot].planet;
        let full = (0..3).any(|r| planet.res[r] >= 0.8 * planet.cap[r]);
        // A session places several decisions per login, so a login is a decision whose second is in the login record
        // (row 12r): counting decisions here would read 57 "logins" a day where the account logged in twelve times.
        // The world's clock never goes back, so one remembered second per account counts each login once.
        let login = self.world.games[slot].login_times.iter().any(|&x| (x - t).abs() < 1e-6) && t != self.counted_login[a];
        if login { self.counted_login[a] = t }
        // Row 29: at a login that finds a store full, why the yard did or did not spend it.
        let yard = full && login;
        let stall = match yard {
            true => Some(self.players[a].yard_stall(&*self.world, self.cat, slot)),
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
        let times = &self.world.games[self.world.account_of(a)].login_times;
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

/// What the account paid for its research levels: the stub's research share of `account_value`.
fn research_value(w: &World, cat: &Catalogue, a: usize) -> f64 {
    let levels = &w.games[w.account_of(a)].planet.levels;
    cat.objects.iter().enumerate().filter(|(_, o)| o.queue == 1)
        .map(|(k, o)| (0..levels[k] as usize).map(|level| weighted(o.price[level])).sum::<f64>())
        .sum()
}

/// Reads the events the world wrote since the last call into their day and account.
fn scan(w: &World, read: &mut usize, roles: &[usize], per_day: &mut [Vec<Tally>], days: usize, delivered: &mut Vec<[f64; 3]>) {
    delivered.resize(w.games.len(), [0.0; 3]);
    while *read < w.events.len() {
        let e = &w.events[*read];
        *read += 1;
        let recipient = match (e.kind, e.mission) {
            (EventKind::Return, _) => Some(e.owner),
            (EventKind::Arrival, Mission::Transport | Mission::Deploy | Mission::Colonise) => Some(e.target),
            _ => None,
        };
        if let Some(slot) = recipient.filter(|&slot| slot < delivered.len()) {
            for r in 0..3 { delivered[slot][r] += e.gained[r].max(0.0); }
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

fn shuffle<T>(values: &mut [T], rng: &mut u64) {
    for i in (1..values.len()).rev() {
        values.swap(i, (next_random(rng) % (i as u64 + 1)) as usize);
    }
}

fn median(values: &[f64]) -> f64 {
    let mut sorted = values.to_vec();
    sorted.sort_by(f64::total_cmp);
    match sorted.len() {
        0 => 0.0,
        n => sorted[n / 2],
    }
}
