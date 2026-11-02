//! W14: the host's own home planets played by the season's personas (buildings, research, the yard and the colonies it founds),
//! written as the trace `ai:rl-world-replay` plays on the same host universe. Every start carries the stock the
//! simulator paid it from, every yard order the amount it queued, every fleet its launch and its events, and every
//! planet its state at its last second. With `{DATA}/coords.json` (the host's planets) the homes sit where the host
//! has them and the host's other planets are taken; the economy file's colonisation block lets the miners colonise.
//!
//!     cargo run --release --example trace -- DATA ECON DAYS SEED OUT

use std::sync::Arc;

use ogsim::fleet::Coord;
use ogsim::players::{FleetSaver, Ledger, Player, Raider};
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
    world.keep_launches = true;
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
    // The season's three personas in turn, with its switches: raids, saves, expeditions and transports all fly.
    let mut players: Vec<Player> = (0..homes).map(|a| {
        let s = seed.wrapping_mul(100).wrapping_add(a as u64);
        let mut p = match a % 3 { 1 => Player::Raider(Raider::new(s)), 2 => Player::Saver(FleetSaver::new(s)), _ => Player::Miner(ogsim::players::Miner::new(s)) };
        let m = p.miner();
        (m.consolidate, m.reserve, m.yard, m.expeditions, m.colonies_first, m.ships, m.lab, m.cap) = (true, true, true, true, true, true, true, true);
        (m.feed, m.factory, m.balance, m.lab_first, m.satellites, m.doubler) = (true, true, true, true, true, true);
        p
    }).collect();
    let mut ledgers: Vec<Ledger> = (0..homes).map(|_| Ledger::default()).collect();
    // Every decision is a page load, and a page load settles the planet: the host settles at each one.
    let mut touches: Vec<Vec<(f64, [f64; 3])>> = vec![Vec::new(); homes];
    while let Some(a) = world.next_decision(&cat) {
        if touches.len() < world.games.len() { touches.resize(world.games.len(), Vec::new()) }
        // The first page load of a second carries the stock before that second's actions; the replay plays it first. A fleet
        // the capital already sent from this planet in that second (a consolidation) has moved the stock, so none is taken.
        let now = world.games[a].t;
        let sent = world.launch_log.iter().rev().take_while(|l| l.0.departed == now).any(|l| l.0.owner == a);
        if !sent && touches[a].last().is_none_or(|l| l.0 as i64 != now as i64) {
            touches[a].push((now, world.games[a].planet.res));
        }
        // A colony is played by its account's player, as the season plays it.
        let owner = world.account_of(a);
        let pick = players[owner].act(&mut world, &cat, a, &mut ledgers[owner]);
        // From day 3 the accounts shoot moons for each other, as the season's alliance members do (its `moonstack`).
        if a == owner && world.games[a].t >= 3.0 * 86_400.0 {
            let logged = world.unit_log.len();
            ogsim::players::moon_shot(&mut world, &cat, a, |_| true, &mut ledgers[owner]);
            ogsim::players::ready_for_moon(&mut world, &cat, a, true, &[]);
            // A colony readied here is loaded by its own order: its sample carries the stock before that order paid.
            for &(p, t, id, _, queued) in &world.unit_log[logged..] {
                // As at a decision: no sample once a fleet has left that planet in that second (a consolidation).
                let sent = world.launch_log.iter().rev().take_while(|l| l.0.departed == t).any(|l| l.0.owner == p);
                if p == a || sent { continue }
                let rules = &world.rules[p].units;
                let price = rules.units[rules.index(id as i64).expect("an ordered unit")].price;
                let stock = std::array::from_fn(|r| world.games[p].planet.res[r] + price[r] * queued as f64);
                if touches.len() <= p { touches.resize(p + 1, Vec::new()) }
                if touches[p].last().is_none_or(|l| l.0 as i64 != t as i64) { touches[p].push((t, stock)); }
            }
        }
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
        // A launch names its fleet by its id; its arrival and its return come back under it.
        let launches = world.launch_log.iter().filter(|l| l.0.owner == a).map(|(f, speed)| json!({
            "t": f.departed, "queue": "launch", "fleet": f.id, "mission": f.mission as u32, "to": coord(f.to), "ships": f.ships,
            "cargo": f.cargo, "speed": speed, "fuel": f.fuel, "arrive": f.arrive, "moon": f.moon, "holding_hours": f.holding / 3600.0, "priority": f.priority,
        }));
        let officers = world.officer_log.iter().filter(|o| o.0 == a).map(|(_, t, key, days)| json!({ "t": t, "queue": "officer", "key": key, "days": days }));
        let events = world.events.iter().filter(|e| e.owner == a && matches!(e.kind, EventKind::Arrival | EventKind::Return | EventKind::Recall)).map(|e| json!({
            "t": e.time, "queue": match e.kind { EventKind::Arrival => "arrive", EventKind::Return => "return", _ => "recall" }, "departed": e.departed, "fleet": e.fleet,
            "outcome": format!("{:?}", e.outcome), "gained": e.gained, "seed": e.seed,
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
            "steps": starts.chain(orders).chain(touches).chain(launches).chain(events).chain(officers).collect::<Vec<_>>(),
            "final": { "t": game.t, "stock": game.planet.res, "levels": levels, "units": units, "moon": world.moons[a], "dark_matter": world.dark_matter[a] },
        })
    }).collect();
    // The shipyard page refuses an order while one of these upgrades; the host's queue service alone does not check it.
    let blocked: Vec<i64> = rules.units.blocked.iter().map(|&k| cat.objects[k].id).collect();
    std::fs::write(&args[4], serde_json::to_string(&json!({ "days": days, "seed": seed, "rank1_points": world.rank1_points, "dark_matter_initial": world.rules[0].settings.dark_matter_initial, "yard_blocked_by": blocked, "planets": planets })).expect("trace json"))
        .expect("write the trace");
}
