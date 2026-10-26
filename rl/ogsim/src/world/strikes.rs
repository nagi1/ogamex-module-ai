//! The missions that run on the strike and expedition modules: expeditions, interplanetary missiles and moon
//! destruction. Each mission's rules are the module's (`expedition`, `strike`); this file only moves what the modules
//! return into the world: stock, ships, moons, dark matter and the fleet's way home.

use super::*;

impl World {
    /// What a moon destruction needs besides the usual checks: strike tables, a moon over the target and a moon destroyer.
    pub(super) fn moon_destroyable(&self, rules: &Rules, target: usize, sends: &dyn Fn(&[u32]) -> bool) -> Result<(), &'static str> {
        let strike = rules.strike.as_ref().ok_or("no strike tables")?;
        if !self.moons[target] { return Err("no moon") }
        if !sends(&[strike.moon_destroyer]) { return Err("no moon destroyer") }
        Ok(())
    }

    /// ExpeditionMission::isMissionPossible and its sanity checks: deep space, astrophysics, and a ship that is not a probe.
    /// The research is the one the colonisation tables name (astrophysics), so an expedition needs those tables too.
    pub(super) fn expeditionable(&self, cat: &Catalogue, a: usize, to: Coord, rules: &Rules, sent: &[(u32, i64)]) -> Result<(), &'static str> {
        if rules.expedition.is_none() { return Err("no expedition tables") }
        if to.position != 16 { return Err("not deep space") }
        let table = self.colonisation.as_ref().ok_or("no astrophysics tables")?;
        if self.research(cat, a).get(&(table.research as u32)).copied().unwrap_or(0) == 0 { return Err("astrophysics first") }
        if sent.iter().all(|(id, _)| rules.combat.missions.espionage_units.contains(id)) { return Err("an expedition needs a ship") }
        Ok(())
    }

    /// Account a, while it decides, fires `missiles` at slot `target`'s planet, or at its moon with `on_moon`, with the
    /// host's priority code (`GalaxyController::missileAttack`). The missiles leave the shelf at once and no fleet
    /// comes home.
    pub fn launch_missiles(&mut self, cat: &Catalogue, a: usize, target: usize, on_moon: bool, missiles: i64, priority: usize) -> Result<(), &'static str> {
        let rules = Arc::clone(&self.rules[a]);
        let strike = rules.strike.as_ref().ok_or("no strike tables")?;
        let now = self.games[a].t;
        self.catch_up(cat, a, now);
        self.idle_stash[a] = None;
        let u = rules.units.index(strike.missile as i64).ok_or("unknown unit")?;
        let coords = |p: usize| [self.coords[p].galaxy, self.coords[p].system, self.coords[p].position];
        let order = strike::Launch {
            missiles,
            available: self.yards[a].counts[u] as i64,
            target_exists: !on_moon || self.moons[target],
            own_target: self.account[target] == self.account[a],
            impulse_level: self.research(cat, a).get(&strike.range_research).copied().unwrap_or(0),
            from: coords(a),
            to: coords(target),
        };
        let flight = strike::launch(strike, &order).map_err(|refusal| match refusal {
            strike::Refusal::NoMissiles => "no missiles",
            strike::Refusal::NotEnough => "not enough missiles",
            strike::Refusal::NoTarget => "no target",
            strike::Refusal::OwnTarget => "own planet",
            strike::Refusal::OutOfRange => "out of range",
        })?;
        self.yards[a].counts[u] -= missiles as u64;
        self.sync_units(cat, a);
        self.next[a] = cat.peek(&mut self.games[a]);
        let arrive = now + flight.seconds as f64;
        self.fleets.push(Fleet { owner: a, target, mission: Mission::Missile, ships: vec![(strike.missile, missiles)], cargo: [0.0; 3], fuel: 0.0, to: self.coords[target], arrive, home: arrive, returning: false, departed: now, holding: 0.0, priority, moon: on_moon });
        Ok(())
    }

    /// MissileMission::processArrival: interceptors meet missiles, the rest destroy defences in the priority's order.
    /// The missiles are spent; nothing comes home.
    pub(super) fn missile_strikes(&mut self, cat: &Catalogue, f: usize) {
        let fleet = self.fleets.swap_remove(f);
        let (a, target, at) = (fleet.owner, fleet.target, fleet.arrive);
        self.catch_up(cat, target, at);
        let (attacker, defender) = (Arc::clone(&self.rules[a]), Arc::clone(&self.rules[target]));
        let strike = attacker.strike.as_ref().expect("a missile flew without strike tables");
        // A moon's defences are its own and the planet's interceptors meet the missiles first; a planet's are its yard's.
        let mut moon_counts = self.moon_holds[target].counts.clone();
        moon_counts.resize(defender.units.units.len(), 0);
        let counts = if fleet.moon { &moon_counts } else { &self.yards[target].counts };
        let defence: combat::Units = defender.units.units.iter().zip(counts)
            .filter(|(u, &n)| n > 0 && defender.combat.is_defense(u.id as u32))
            .map(|(u, &n)| (u.id as u32, n as i64)).collect();
        let interceptor = defender.units.index(strike.interceptor as i64);
        let parent_interceptors = interceptor.filter(|_| fleet.moon).map_or(0, |u| self.yards[target].counts[u] as i64);
        let hit = strike::Impact {
            missiles: fleet.ships.iter().map(|s| s.1).sum(),
            priority: fleet.priority,
            weapon_level: self.research(cat, a).get(&strike.weapon_research).copied().unwrap_or(0),
            armour_level: self.research(cat, target).get(&strike.armour_research).copied().unwrap_or(0),
            defence: &defence,
            parent_interceptors,
        };
        let struck = strike::impact(strike, &hit);
        for (u, unit) in defender.units.units.iter().enumerate() {
            if defence.contains_key(&(unit.id as u32)) {
                let left = struck.defence_after.get(&(unit.id as u32)).copied().unwrap_or(0) as u64;
                match fleet.moon {
                    true => moon_counts[u] = left,
                    false => self.yards[target].counts[u] = left,
                }
            }
        }
        if fleet.moon {
            if let Some(u) = interceptor { self.yards[target].counts[u] = struck.interceptors_after[1] as u64 }
            self.moon_holds[target].counts = moon_counts;
        }
        self.sync_units(cat, target);
        self.next[target] = cat.peek(&mut self.games[target]);
        let lost: Vec<(u32, i64)> = defence.iter().map(|(&id, &n)| (id, n - struck.defence_after.get(&id).copied().unwrap_or(0))).filter(|s| s.1 > 0).collect();
        self.log(EventKind::Arrival, at, &fleet, Outcome::Done, |e| e.target_lost = lost);
    }

    /// ExpeditionMission::processArrival at the end of the hold: the module's dice give what the fleet finds and loses
    /// (a black hole takes all of it), and what is left flies home the outward flight later, more or less the delay.
    pub(super) fn expedition_arrives(&mut self, cat: &Catalogue, f: usize) {
        let (a, at) = (self.fleets[f].owner, self.fleets[f].next());
        self.catch_up(cat, a, at);
        let rules = Arc::clone(&self.rules[a]);
        let table = rules.expedition.as_ref().expect("an expedition flew without its tables");
        let research = self.research(cat, a);
        let fleet = self.fleets[f].clone();
        let units: combat::Units = fleet.ships.iter().copied().collect();
        let carried = fleet.cargo.map(|r| r as i64);
        let flight = (fleet.arrive - fleet.departed) as i64;
        let sent = expedition::Sent {
            fleet: &units,
            carried,
            free_hold: expedition::free_hold(&rules.combat, &units, &research, carried),
            rank1_points: self.rank1_points,
            flight_seconds: flight,
            holding_seconds: fleet.holding as i64,
        };
        let (seed, engine) = self.recorded_expedition.take().unwrap_or_else(|| ((next_random(&mut self.rng) >> 32) as u32, None));
        let found = expedition::arrive_with(table, &rules.combat, &research, &sent, &mut expedition::Mt19937::new(seed), engine);
        let capital = self.account[a];
        self.dark_matter[capital] += found.dark_matter;
        if found.debris.iter().any(|&d| d > 0) {
            let field = self.deep_space.entry((fleet.to.galaxy, fleet.to.system)).or_insert([0; 3]);
            for r in 0..3 { field[r] += found.debris[r] }
        }
        let home = found.survivors(&units);
        let lost: Vec<(u32, i64)> = found.lost.iter().map(|(&id, &n)| (id, n)).collect();
        if found.fight.is_some() {
            let result = match (home.is_empty(), lost.is_empty()) {
                (true, _) => Outcome::Defeated,
                (false, true) => Outcome::Won,
                (false, false) => Outcome::Draw,
            };
            self.log(EventKind::Fight, at, &fleet, result, |e| { e.ships_lost = lost.clone(); e.debris = found.debris });
        }
        let resources = found.resources.map(|r| r as f64);
        self.log(EventKind::Arrival, at, &fleet, Outcome::Found(found.kind), |e| { e.gained = resources; e.ships_lost = lost });
        if home.is_empty() {
            // Row 23s: a black hole takes the fleet and the hold with it, so the hold is an outflow of its own.
            self.games[a].planet.books.cargo_lost += weighted(self.fleets[f].cargo);
            self.fleets.swap_remove(f);
            return self.log(EventKind::Lost, at, &fleet, Outcome::Defeated, |_| {});
        }
        let back = &mut self.fleets[f];
        back.ships = home.into_iter().collect();
        // Row 23: the find (or the hold a lost expedition took) is the difference the hold itself moved by.
        let carried_home = found.cargo_home(carried).map(|r| r as f64);
        let (found_home, held) = (weighted(carried_home), weighted(back.cargo));
        if found_home >= held {
            self.games[a].planet.books.expedition_in += found_home - held;
        } else {
            self.games[a].planet.books.cargo_lost += held - found_home;
        }
        back.cargo = carried_home;
        back.fuel = 0.0;
        back.arrive = at;
        back.holding = 0.0;
        back.home = at + found.return_seconds(flight) as f64;
        back.returning = true;
    }

    /// MoonDestructionMission::processArrival: a fight against what the moon holds, then (when the fleet won) the two
    /// rolls that decide the moon and the destroyers. The loot leaves the moon and goes nowhere; the fleet flies home
    /// with the cargo it left with, whatever survived; a moon destroyed is gone with all it held.
    pub(super) fn moon_destruction(&mut self, cat: &Catalogue, f: usize) -> Outcome {
        let (a, target, at) = (self.fleets[f].owner, self.fleets[f].target, self.fleets[f].arrive);
        let (attacker, defender) = (Arc::clone(&self.rules[a]), Arc::clone(&self.rules[target]));
        let strike = attacker.strike.as_ref().expect("a moon destroyer flew without strike tables");
        let (attacker_research, defender_research) = (self.research(cat, a), self.research(cat, target));
        let mut hold = self.moon_holds[target].clone();
        hold.counts.resize(defender.units.units.len(), 0);
        let held: Vec<(u32, i64)> = defender.units.units.iter().zip(&hold.counts).filter(|(_, &n)| n > 0).map(|(u, &n)| (u.id as u32, n as i64)).collect();
        let ships = self.fleets[f].ships.clone();
        let mut cargo = self.fleets[f].cargo;
        cargo[2] += self.fleets[f].fuel / 2.0;
        let seed = self.rng;
        self.rng = next_random(&mut self.rng);
        let battle = Battle { attacker: &ships, attacker_research: &attacker_research, attacker_cargo: cargo, defender: &held, defender_research: &defender_research, defender_stock: hold.stock, defender_engineer: false, moon_exists: true, seed };
        let engine = match self.recorded.take() {
            Some(fight) => fight.engine,
            None => {
                let fighters = strike::combatants(strike, &held);
                combat::run_engine(&combat::engine_input(&attacker.combat, &defender.combat, &Battle { defender: &fighters, ..battle }))
            }
        };
        let roll_max = strike.moon_destruction.roll_max.max(1) as u64;
        let rolls = self.recorded_rolls.take().unwrap_or_else(|| [0, 1].map(|_| 1 + (next_random(&mut self.rng) % roll_max) as i64));
        let result = strike::resolve_moon_destruction(strike, &attacker.combat, &defender.combat, &battle, engine, hold.diameter, rolls);

        for r in 0..3 { hold.stock[r] = as_stored(hold.stock[r] - result.loot[r] as f64) }
        for (u, unit) in defender.units.units.iter().enumerate() {
            let id = unit.id as u32;
            // Missiles and interceptors never fight, so the moon keeps them as they were.
            if id == strike.missile || id == strike.interceptor { continue }
            if held.iter().any(|s| s.0 == id) { hold.counts[u] = result.defender_left.get(&id).copied().unwrap_or(0) as u64 }
        }
        for r in 0..3 { self.debris[target][r] += result.debris[r] }
        self.fields[target] = true;
        let fleet = self.fleets[f].clone();
        let lost: Vec<(u32, i64)> = ships.iter().map(|&(id, n)| (id, n - result.return_fleet.as_ref().and_then(|r| r.get(&id)).copied().unwrap_or(0))).filter(|s| s.1 > 0).collect();
        let outcome = match (result.verdict, result.moon_destroyed) {
            (_, true) => Outcome::MoonDestroyed,
            (strike::Verdict::NotAttempted, _) if result.defender_left.is_empty() && result.return_fleet.is_some() => Outcome::Won,
            (strike::Verdict::NotAttempted, _) => Outcome::Defeated,
            _ => Outcome::Won,
        };
        self.log(EventKind::Fight, at, &fleet, outcome, |e| {
            e.ships_lost = lost;
            e.gained = result.loot.map(|r| r as f64);
            e.debris = result.debris;
            e.present = held.iter().filter(|(id, _)| !defender.combat.is_defense(*id)).copied().collect();
        });
        self.fleets[f].ships = result.return_fleet.clone().unwrap_or_default().into_iter().collect();
        self.fleets[f].fuel = 0.0;
        match result.moon_destroyed {
            true => {
                self.moons[target] = false;
                self.moon_holds[target] = MoonHold::default();
            }
            false => self.moon_holds[target] = hold,
        }
        outcome
    }
}
