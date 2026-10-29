//! W14: the host's own home planets played by the miner rule (buildings, research and the yard; no fleet, no colony),
//! written as the trace `ai:rl-world-replay` plays on the same host universe. Every start carries the stock the
//! simulator paid it from, every yard order the amount it queued, and every planet its state at its last second.
//!
//!     cargo run --release --example trace -- DATA ECON DAYS SEED OUT

use std::sync::Arc;

use ogsim::fleet::Coord;
use ogsim::players::Miner;
use ogsim::world::{Rules, World};
use ogsim::Catalogue;
use serde_json::json;

fn main() {
    let args: Vec<String> = std::env::args().skip(1).collect();
    let (data, econ) = (&args[0], &args[1]);
    let days: f64 = args[2].parse().expect("days");
    let seed: u64 = args[3].parse().expect("seed");
    let mut cat = Catalogue::load(econ).expect("the economy catalogue");
    // The season's login gaps: with none the economy is always online and a login that picks nothing never moves on.
    cat.login_gaps = serde_json::from_str(&std::fs::read_to_string(format!("{data}/login-gaps.json")).expect("the login gaps"))
        .expect("the login gaps json");
    let rules_of = |suffix: &str| Arc::new(Rules::load(&cat,
        &format!("{data}/units-parity/units-u1.json"),
        &format!("{data}/fleet-settings-u1{suffix}.json"),
        &format!("{data}/combat-u1{suffix}.json")).expect("the units, fleet and combat exports"));
    let rules = rules_of("");
    let homes = rules.units.planets.len();
    let configs: Vec<usize> = (0..homes).collect();
    let mut world = World::new(&cat, &configs, days * 24.0, 0.0, seed);
    world.enable_idle_logins(&cat);
    let per_class: Vec<Arc<Rules>> = configs.iter().map(|&c| match cat.planets[c].character_class {
        Some(2) => rules_of("-class2"),
        Some(3) => rules_of("-class3"),
        _ => Arc::clone(&rules),
    }).collect();
    // A home's coordinates do not enter an economy without fleets; the world only needs distinct ones.
    let coords = (0..homes as i64).map(|a| Coord { galaxy: 1, system: 1 + a, position: 8 }).collect();
    world.arm(per_class, coords);
    let mut miners: Vec<Miner> = (0..homes).map(|a| {
        let mut m = Miner::new(seed.wrapping_mul(100).wrapping_add(a as u64));
        (m.yard, m.ships, m.lab, m.cap, m.factory, m.balance, m.lab_first, m.satellites) = (true, true, true, true, true, true, true, true);
        m
    }).collect();
    // Every decision is a page load, and a page load settles the planet: the host settles at each one.
    let mut touches: Vec<Vec<f64>> = vec![Vec::new(); homes];
    while let Some(a) = world.next_decision(&cat) {
        touches[a].push(world.games[a].t);
        let pick = miners[a].act(&mut world, &cat, a);
        match pick {
            Some(k) => world.commit(&cat, a, k),
            None => world.wait(&cat, a),
        }
    }
    let planets: Vec<_> = (0..homes).map(|a| {
        let game = &world.games[a];
        let starts = game.history.iter().map(|&(start, k, end, stock)| json!({
            "t": start, "queue": if cat.objects[k].queue == 1 { "research" } else { "building" },
            "id": cat.objects[k].id, "end": end, "stock": stock,
        }));
        let orders = world.unit_log.iter().filter(|o| o.0 == a).map(|&(_, t, id, asked, queued)| json!({
            "t": t, "queue": "units", "id": id, "amount": asked, "queued": queued,
        }));
        let touches = touches[a].iter().map(|&t| json!({ "t": t, "queue": "touch" }));
        let levels: serde_json::Map<_, _> = cat.objects.iter().zip(&game.planet.levels).map(|(o, &l)| (o.id.to_string(), json!(l))).collect();
        // The yard hands over finished units when it is next touched; the host settles it at the last second.
        let mut yard = world.yards[a].clone();
        world.rules[a].units.advance(&mut yard, game.t as i64);
        let units: serde_json::Map<_, _> = world.rules[a].units.units.iter().zip(&yard.counts)
            .map(|(u, &n)| (u.id.to_string(), json!(n))).collect();
        json!({
            "planet_id": cat.planets[configs[a]].planet_id,
            "steps": starts.chain(orders).chain(touches).collect::<Vec<_>>(),
                        "final": { "t": game.t, "stock": game.planet.res, "levels": levels, "units": units },
        })
    }).collect();
    // The shipyard page refuses an order while one of these upgrades; the host's queue service alone does not check it.
    let blocked: Vec<i64> = rules.units.blocked.iter().map(|&k| cat.objects[k].id).collect();
    std::fs::write(&args[4], serde_json::to_string(&json!({ "days": days, "seed": seed, "yard_blocked_by": blocked, "planets": planets })).expect("trace json"))
        .expect("write the trace");
}
