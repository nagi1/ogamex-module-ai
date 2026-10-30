//! W14: the host's own home planets played by the miner rule (buildings, research, the yard and the colonies it founds),
//! written as the trace `ai:rl-world-replay` plays on the same host universe. Every start carries the stock the
//! simulator paid it from, every yard order the amount it queued, every fleet its launch and its events, and every
//! planet its state at its last second. With `{DATA}/coords.json` (the host's planets) the homes sit where the host
//! has them and the host's other planets are taken; the economy file's colonisation block lets the miners colonise.
//!
//!     cargo run --release --example trace -- DATA ECON DAYS SEED OUT

use std::sync::Arc;

use ogsim::fleet::Coord;
use ogsim::players::Miner;
use ogsim::world::{Colonisation, EventKind, Rules, World};
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
    // The host's coordinates, by planet id: a fleet's flight and fuel are read from them.
    let host: Vec<(i64, Coord)> = std::fs::read_to_string(format!("{data}/coords.json")).ok()
        .map(|text| serde_json::from_str::<Vec<(i64, [i64; 3])>>(&text).expect("the coords json")).unwrap_or_default()
        .into_iter().map(|(id, [galaxy, system, position])| (id, Coord { galaxy, system, position })).collect();
    let home_ids: Vec<i64> = configs.iter().map(|&c| cat.planets[c].planet_id).collect();
    let coords = (0..homes).map(|a| host.iter().find(|h| h.0 == home_ids[a]).map_or(
        Coord { galaxy: 1, system: 1 + a as i64, position: 8 }, |h| h.1)).collect();
    world.arm(per_class, coords);
    world.taken = host.iter().filter(|h| !home_ids.contains(&h.0)).map(|h| h.1).collect();
    world.colonisation = Colonisation::load(econ).ok().map(Arc::new);
    let mut miners: Vec<Miner> = (0..homes).map(|a| {
        let mut m = Miner::new(seed.wrapping_mul(100).wrapping_add(a as u64));
        (m.yard, m.ships, m.lab, m.cap, m.factory, m.balance, m.lab_first, m.satellites) = (true, true, true, true, true, true, true, true);
        m
    }).collect();
    // Every decision is a page load, and a page load settles the planet: the host settles at each one.
    let mut touches: Vec<Vec<(f64, [f64; 3])>> = vec![Vec::new(); homes];
    while let Some(a) = world.next_decision(&cat) {
        if touches.len() < world.games.len() { touches.resize(world.games.len(), Vec::new()) }
        // The first page load of a second carries the stock before that second's actions; the replay plays it first.
        if touches[a].last().is_none_or(|l| l.0 as i64 != world.games[a].t as i64) {
            touches[a].push((world.games[a].t, world.games[a].planet.res));
        }
        // A colony is played by its account's miner, as the season plays it.
        let owner = world.account_of(a);
        let pick = miners[owner].act(&mut world, &cat, a);
        match pick {
            Some(k) => world.commit(&cat, a, k),
            None => world.wait(&cat, a),
        }
    }
    touches.resize(world.games.len(), Vec::new());
    let coord = |c: Coord| json!([c.galaxy, c.system, c.position]);
    let planets: Vec<_> = (0..world.games.len()).map(|a| {
        let game = &world.games[a];
        let starts = game.history.iter().map(|&(start, k, end, stock)| json!({
            "t": start, "queue": if cat.objects[k].queue == 1 { "research" } else { "building" },
            "id": cat.objects[k].id, "end": end, "stock": stock,
        }));
        let orders = world.unit_log.iter().filter(|o| o.0 == a).map(|&(_, t, id, asked, queued)| json!({
            "t": t, "queue": "units", "id": id, "amount": asked, "queued": queued,
        }));
        let touches = touches[a].iter().map(|&(t, stock)| json!({ "t": t, "queue": "touch", "stock": stock }));
        // A launch names its fleet by the second it left; its arrival and its return come back under that second.
        let launches = world.launch_log.iter().filter(|l| l.0.owner == a).map(|(f, speed)| json!({
            "t": f.departed, "queue": "launch", "mission": f.mission as u32, "to": coord(f.to), "ships": f.ships,
            "cargo": f.cargo, "speed": speed, "fuel": f.fuel, "arrive": f.arrive,
        }));
        let events = world.events.iter().filter(|e| e.owner == a && matches!(e.kind, EventKind::Arrival | EventKind::Return)).map(|e| json!({
            "t": e.time, "queue": if e.kind == EventKind::Arrival { "arrive" } else { "return" }, "departed": e.departed,
            "outcome": format!("{:?}", e.outcome), "gained": e.gained,
        }));
        let levels: serde_json::Map<_, _> = cat.objects.iter().zip(&game.planet.levels).map(|(o, &l)| (o.id.to_string(), json!(l))).collect();
        // The yard hands over finished units when it is next touched; the host settles it at the last second.
        let mut yard = world.yards[a].clone();
        world.rules[a].units.advance(&mut yard, game.t as i64);
        let units: serde_json::Map<_, _> = world.rules[a].units.units.iter().zip(&yard.counts)
            .map(|(u, &n)| (u.id.to_string(), json!(n))).collect();
        // A colony is known to the host by its coordinates; the replay gives it the site the simulator drew.
        let capital = world.account_of(a);
        let site = (a >= homes).then(|| world.colonisation.as_ref()?.sites.iter().find(|s| s.config == game.planet.config)
            .map(|s| json!({ "temp_max": s.temp_max, "field_max": game.planet.field_max }))).flatten();
        json!({
            "planet_id": (a < homes).then(|| home_ids[a]), "account": home_ids[capital], "coord": coord(world.coords[a]), "site": site,
            "steps": starts.chain(orders).chain(touches).chain(launches).chain(events).collect::<Vec<_>>(),
            "final": { "t": game.t, "stock": game.planet.res, "levels": levels, "units": units },
        })
    }).collect();
    // The shipyard page refuses an order while one of these upgrades; the host's queue service alone does not check it.
    let blocked: Vec<i64> = rules.units.blocked.iter().map(|&k| cat.objects[k].id).collect();
    std::fs::write(&args[4], serde_json::to_string(&json!({ "days": days, "seed": seed, "yard_blocked_by": blocked, "planets": planets })).expect("trace json"))
        .expect("write the trace");
}
