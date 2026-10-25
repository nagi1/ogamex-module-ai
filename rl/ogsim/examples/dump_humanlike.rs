//! What the scripted accounts look like from outside, for `rl/scripts/humanlike.py` (plan/rl/WORLD-SIM-PLAN.md, W11).
//!
//! Plays a world of the scripted players and measures what an observer can see: when they log in, how long they are
//! gone, how fast they react to an attack, whether a save holds, how often they raid, and how their value grows. Every
//! number comes from the world's own clock and event log — nothing is estimated here; the bands they are compared
//! with belong to the caller.
//!
//!     cargo run --release --example dump_humanlike -- <data dir> <hours> <accounts> [seed]

use std::sync::Arc;

use ogsim::fleet::Coord;
use ogsim::players::{FleetSaver, Ledger, Miner, Player, Raider};
use ogsim::world::{EventKind, Mission, Rules, World};
use ogsim::Catalogue;
use serde_json::json;

const ROLES: [&str; 3] = ["raider", "saver", "miner"];

fn main() {
    let mut args = std::env::args().skip(1);
    let data = args.next().unwrap_or_else(|| "../../../../storage/rl/ogsim".into());
    let hours: f64 = args.next().and_then(|s| s.parse().ok()).unwrap_or(240.0);
    let accounts: usize = args.next().and_then(|s| s.parse().ok()).unwrap_or(12);
    let seed: u64 = args.next().and_then(|s| s.parse().ok()).unwrap_or(1);

    let mut cat = Catalogue::load(&format!("{data}/units-parity/econ-u1.json")).expect("the economy catalogue");
    cat.login_gaps = vec![600.0, 1800.0, 3600.0, 7200.0, 21600.0];
    let rules = Arc::new(Rules::load(&cat,
        &format!("{data}/units-parity/units-u1.json"),
        &format!("{data}/fleet-settings-u1.json"),
        &format!("{data}/combat-u1.json")).expect("the units, fleet and combat exports"));
    let configs: Vec<usize> = (0..accounts).collect();
    let mut world = World::new(&cat, &configs, hours, 0.0, seed);
    world.enable_idle_logins(&cat);
    let coords = (0..accounts).map(|a| Coord { galaxy: 1, system: 10 + 2 * a as i64, position: 4 + (a % 8) as i64 }).collect();
    world.arm(vec![Arc::clone(&rules); accounts], coords);

    let mut players: Vec<Player> = (0..accounts).map(|a| {
        let s = seed * 100 + a as u64;
        match a % 3 {
            0 => Player::Raider(Raider::new(s)),
            1 => Player::Saver(FleetSaver::new(s)),
            _ => Player::Miner(Miner::new(s)),
        }
    }).collect();
    let mut ledgers: Vec<Ledger> = (0..accounts).map(|_| Ledger::default()).collect();
    let start: f64 = world.now();
    let mut logins: Vec<Vec<f64>> = vec![Vec::new(); accounts];
    let mut by_queue: Vec<[usize; 2]> = vec![[0; 2]; accounts];
    let mut values_start: Vec<f64> = vec![0.0; accounts];

    while let Some(a) = world.next_decision(&cat) {
        if logins[a].is_empty() { values_start[a] = world.games[a].value() }
        logins[a].push(world.games[a].t);
        by_queue[a][world.games[a].queue.min(1)] += 1;
        match players[a].act(&mut world, &cat, a, &mut ledgers[a]) {
            Some(k) => world.commit(&cat, a, k),
            None => world.wait(&cat, a),
        }
    }

    let rows: Vec<_> = (0..accounts).map(|a| {
        // What an observer sees is when the player logged in, not when a build landed at the same second.
        let times = &world.games[a].login_times;
        let mut hours_seen: Vec<i64> = times.iter().map(|t| ((t % 86_400.0) / 3600.0) as i64).collect();
        hours_seen.sort_unstable();
        hours_seen.dedup();
        // The host flags an account that departs in 18 or more distinct hours of the day in one week.
        let week: i64 = (7 * 86_400) as i64;
        let busiest = times.iter().map(|&from| {
            times.iter().filter(|&&t| (t - from) as i64 <= week).map(|t| ((t % 86_400.0) / 3600.0) as i64).collect::<std::collections::BTreeSet<_>>().len()
        }).max().unwrap_or(0);
        let gaps: Vec<f64> = times.windows(2).map(|w| w[1] - w[0]).collect();
        let (raids, loot, arrived, spies) = world.events.iter().fold((0, 0.0, 0, 0), |(raids, loot, arrived, spies), e| {
            match (e.mission, e.owner == a, e.target == a, e.kind) {
                (Mission::Attack, true, _, EventKind::Fight) => (raids + 1, loot + weighted(e.gained), arrived, spies),
                (Mission::Attack, _, true, EventKind::Fight) => (raids, loot, arrived + 1, spies),
                (Mission::Espionage, true, _, _) => (raids, loot, arrived, spies + 1),
                _ => (raids, loot, arrived, spies),
            }
        });
        json!({
            "player": a,
            "role": ROLES[a % 3],
            "logins": times.len(),
            "decisions_build": by_queue[a][0],
            "decisions_research": by_queue[a][1],
            "distinct_hours": hours_seen.len(),
            "busiest_week_hours": busiest,
            "longest_gap_h": gaps.iter().copied().fold(0.0f64, f64::max) / 3600.0,
            "shortest_gap_s": gaps.iter().copied().fold(f64::INFINITY, f64::min),
            "raids": raids,
            "raids_received": arrived,
            "spies": spies,
            "loot": loot,
            "saves_sent": ledgers[a].saves_sent,
            "saves_refused": ledgers[a].saves_refused,
            "recalls": ledgers[a].recalls,
            "planets": world.planets_of(a).len().max(1),
            "levels": cat.objects.iter().zip(&world.games[a].planet.levels).filter(|(_, &l)| l > 0).map(|(o, &l)| (o.id.to_string(), l)).collect::<std::collections::BTreeMap<_, _>>(),
            "units": world.yards[a].counts.iter().sum::<u64>(),
            "yard_busy": world.yards[a].busy(),
            "value_start": values_start[a],
            "value_end": world.games[a].value(),
            "value_end_all": ogsim::players::account_value(&world, a),
        })
    }).collect();

    let mut out = json!({
        "hours": hours,
        "seed": seed,
        "accounts": accounts,
        "start": start,
        "players": rows,
    });
    if let Some(path) = std::env::args().nth(5) {
        std::fs::write(&path, serde_json::to_string_pretty(&out).expect("json")).expect("write the report");
        out = json!({ "written": path });
    }
    println!("{out}");
}

fn weighted(r: [f64; 3]) -> f64 {
    r[0] + 1.5 * r[1] + 2.0 * r[2]
}
