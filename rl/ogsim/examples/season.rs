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
use ogsim::players::{account_value, FleetSaver, Ledger, Miner, Player, Raider};
use ogsim::score::{self, Score};
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
    let rules = Arc::new(Rules::load(&cat,
        &format!("{data}/units-parity/units-u1.json"),
        &format!("{data}/fleet-settings-u1.json"),
        &format!("{data}/combat-u1.json")).expect("the units, fleet and combat exports"));

    // One shipyard table per home planet: a colony site has none, so a season starts its accounts on homes.
    let configs: Vec<usize> = (0..accounts).map(|a| a % rules.units.planets.len()).collect();
    let mut world = World::new(&cat, &configs, days as f64 * 24.0, 0.0, seed);
    // The host's colonisation tables, when the catalogue carries them: without them no account can found a colony.
    world.colonisation = Colonisation::load(&econ).ok().map(Arc::new);
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

    let players: Vec<Player> = (0..accounts).map(|a| {
        let s = seed.wrapping_mul(100).wrapping_add(a as u64);
        match roles[a] {
            RAIDER => Player::Raider(Raider::new(s)),
            SAVER => Player::Saver(FleetSaver::new(s)),
            _ => Player::Miner(Miner::new(s)),
        }
    }).collect();

    if let Some(dir) = std::path::Path::new(&out_path).parent().filter(|d| !d.as_os_str().is_empty()) {
        std::fs::create_dir_all(dir).expect("the output directory");
    }
    let out = BufWriter::new(File::create(&out_path).expect("the output file"));
    let mut season = Season {
        world: &mut world,
        cat: &cat,
        roles: &roles,
        players,
        ledgers: (0..accounts).map(|_| Ledger::default()).collect(),
        out,
        per_day: vec![vec![Tally::default(); accounts]; days],
        login_at: vec![0; accounts],
        alliance_at,
        read: 0,
        midnight: DAY,
        days,
        seed,
        lines: 0,
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
        match season.players[owner].act(&mut *season.world, season.cat, a, &mut season.ledgers[owner]) {
            Some(k) => season.world.commit(season.cat, a, k),
            None => season.world.wait(season.cat, a),
        }
    }
    season.flush(f64::INFINITY);
    let lines = season.lines;
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
    /// Per account, how far its login record has been read for the days already written.
    login_at: Vec<usize>,
    /// Per account, the second it joined an alliance (row 15), or `None` if it never did.
    alliance_at: Vec<Option<f64>>,
    /// How many events of the world's log have been read.
    read: usize,
    /// The next midnight no line has been written for.
    midnight: f64,
    days: usize,
    seed: u64,
    lines: usize,
}

impl<W: Write> Season<'_, W> {
    /// Writes the line of every day that ends at or before `until` (every day left, with `INFINITY`).
    fn flush(&mut self, until: f64) {
        while self.midnight <= until && self.midnight <= self.days as f64 * DAY {
            let d = (self.midnight / DAY) as usize - 1;
            scan(self.world, &mut self.read, self.roles, &mut self.per_day, self.days);
            self.write_day(d);
            self.lines += self.world.games.len();
            self.midnight += DAY;
        }
    }

    /// One line per account for the day `d` (0-based; the line says `d + 1`), as §9.1 of the plan fixes it.
    fn write_day(&mut self, d: usize) {
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
            let line = json!({
                "seed": self.seed,
                "world_speed": self.cat.speed as i64,
                "day": d + 1,
                "account": a,
                "persona": PERSONAS[self.roles[a]].0,
                "controlled": false,
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
            });
            writeln!(self.out, "{line}").expect("write the season line");
        }
    }

    /// Counts this login for the account's day, and whether a store stood at 80% or more (row 11).
    fn touch(&mut self, a: usize, slot: usize) {
        let t = self.world.games[slot].t;
        let d = ((t / DAY) as usize).min(self.days - 1);
        let planet = &self.world.games[slot].planet;
        let full = (0..3).any(|r| planet.res[r] >= 0.8 * planet.cap[r]);
        // A session places several decisions per login, so a login is a decision whose second is in the login record
        // (row 12r): counting decisions here would read 57 "logins" a day where the account logged in twelve times.
        let login = self.world.games[slot].login_times.iter().any(|&x| (x - t).abs() < 1e-6);
        let tally = &mut self.per_day[d][a];
        tally.logins += u32::from(login);
        tally.full_logins += u32::from(full && login);
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

/// What the account paid for its research levels: the stub's research share of `account_value`.
fn research_value(w: &World, cat: &Catalogue, a: usize) -> f64 {
    let levels = &w.games[w.account_of(a)].planet.levels;
    cat.objects.iter().enumerate().filter(|(_, o)| o.queue == 1)
        .map(|(k, o)| (0..levels[k] as usize).map(|level| weighted(o.price[level])).sum::<f64>())
        .sum()
}

/// Reads the events the world wrote since the last call into their day and account.
fn scan(w: &World, read: &mut usize, roles: &[usize], per_day: &mut [Vec<Tally>], days: usize) {
    while *read < w.events.len() {
        let e = &w.events[*read];
        *read += 1;
        record(&mut per_day[((e.time / DAY) as usize).min(days - 1)], e, w, roles);
    }
}

/// Books one event against the account it happened to (and, for a raid, the account it happened at).
fn record(day: &mut [Tally], e: &Event, w: &World, roles: &[usize]) {
    let price = |slot: usize, id: u32| w.rules[slot].units.index(id as i64)
        .map_or(0.0, |u| weighted(w.rules[slot].units.units[u].price));
    // A colony slot fights, raids and colonises for its account: every tally is per account.
    let (owner, target) = (w.account_of(e.owner), w.account_of(e.target));
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
    ships_lost_value: f64,
    loot_value: f64,
}

impl Tally {
    fn json(&self) -> serde_json::Value {
        json!({
            "logins": self.logins,
            "full_logins": self.full_logins,
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
            "ships_lost_value": self.ships_lost_value,
            "loot_value": self.loot_value,
        })
    }
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
