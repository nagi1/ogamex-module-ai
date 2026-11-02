//! Moving a planet (PlanetMoveController, PlanetMoveService): scheduled when nothing is building or flying there and
//! the dark matter is held, carried out a day later if that still holds. The planet takes a new slot and the tables of a
//! site there, its moon comes along with a resting gate, its ships fly over, and fleets bound for it turn back.

use super::*;

impl World {
    /// PlanetMoveController::move for planet p to `to`, at its account's clock.
    pub fn relocate(&mut self, cat: &Catalogue, p: usize, to: Coord) -> Result<(), &'static str> {
        let rules = self.rules[p].settings.relocation.clone().ok_or("no relocation rules")?;
        let now = self.games[self.account[p]].t;
        let settings = &self.rules[p].settings;
        if self.moon_at[p].is_some() { return Err("a moon moves with its planet") }
        if !(1..=settings.number_of_galaxies).contains(&to.galaxy) || !(1..=settings.number_of_systems).contains(&to.system) || !(1..=15).contains(&to.position) { return Err("invalid coordinates") }
        if self.occupied(to) { return Err("the target position is not empty") }
        if self.moves.contains_key(&p) { return Err("a relocation is already in progress") }
        if self.moved_at.get(&p).is_some_and(|&t| t + rules.cooldown > now) { return Err("relocation is on cooldown") }
        if self.dark_matter[self.account[p]] < rules.cost { return Err("insufficient dark matter") }
        self.unsettled(p, now)?;
        self.moves.insert(p, (to, now + rules.duration));
        Ok(())
    }

    /// The move falls due: refused again on the same grounds (a cancel starts the cooldown, a taken slot does not),
    /// otherwise carried out.
    pub(super) fn move_due(&mut self, cat: &Catalogue, p: usize) {
        let Some((to, due)) = self.moves.remove(&p) else { return };
        let Some(rules) = self.rules[p].settings.relocation.clone() else { return };
        if self.occupied(to) { return }
        let account = self.account[p];
        if self.unsettled(p, due).is_err() || self.dark_matter[account] < rules.cost {
            self.moved_at.insert(p, due);
            return;
        }
        self.catch_up(cat, p, due);
        self.games[p].planet.settle();
        self.dark_matter[account] -= rules.cost;
        let from = self.coords[p];
        self.coords[p] = to;
        // The planet takes a site's tables at its new slot (its temperature); its size stays its own.
        let site = self.colonisation.clone().and_then(|table| self.pick_site(cat, account, &table, to.position));
        if let Some(site) = site {
            let fields = self.games[p].planet.field_max;
            self.games[p].planet.config = site.config;
            cat.refresh(&mut self.games[p].planet);
            self.games[p].planet.field_max = fields;
        }
        let moon = self.moon_of[p];
        for s in [Some(p), moon].into_iter().flatten() {
            self.coords[s] = to;
            self.send_over(cat, s, from, &rules, due);
        }
        if let Some(m) = moon {
            let gate = self.rules[m].settings.moon_gear.as_ref().map(|g| g.gate_object);
            if gate.is_some_and(|g| self.moon_level(cat, m, g) > 0) { self.gate_ready[m] = due + rules.cooldown }
        }
        // Fleets of others bound for the planet or its moon find nothing at the old slot and fly home.
        for f in self.fleets.iter_mut().filter(|f| (f.target == p || Some(f.target) == moon) && self.account[f.owner] != account && !f.returning && f.owner != p) {
            f.returning = true;
            f.home = f.arrive + (f.arrive - f.departed);
        }
        self.moved_at.insert(p, due);
        self.move_log.push((p, due));
        self.next[p] = cat.peek(&mut self.games[p]);
    }

    /// createShipTransferMission: slot s's ships, all but those that stay, deploy to its new place at the transfer speed,
    /// burning nothing.
    fn send_over(&mut self, cat: &Catalogue, s: usize, from: Coord, rules: &Relocation, at: f64) {
        let units = Arc::clone(&self.rules[s]);
        let research = self.research(cat, s);
        let ships: Vec<(u32, i64)> = units.units.units.iter().zip(&self.yards[s].counts)
            .filter(|(u, &n)| n > 0 && !units.combat.is_defense(u.id as u32) && !rules.stay.contains(&(u.id as u32)))
            .map(|(u, &n)| (u.id as u32, n as i64)).collect();
        if ships.is_empty() { return }
        for &(id, _) in &ships {
            if let Some(u) = units.units.index(id as i64) { self.yards[s].counts[u] = 0 }
        }
        self.sync_units(cat, s);
        let distance = fleet::distance(&units.settings, from, self.coords[s], &|_, _| SystemState::Active) as f64;
        let slowest = units.fleet.slowest_speed(&ships, &research) as f64;
        let seconds = ((35_000.0 / 10.0 * (distance * 10.0 / slowest).sqrt() + 10.0) / rules.fleet_speed).round().max(1.0);
        let to = self.coords[s];
        self.fleets.push(Fleet { owner: s, target: s, to, mission: Mission::Deploy, ships, cargo: [0.0; 3], fuel: 0.0, arrive: at + seconds, home: at + seconds, returning: false, departed: at, holding: 0.0, priority: 0, moon: false, id: self.launch_log.len(), union: None });
        self.launch_log.push((self.fleets.last().expect("just sent").clone(), rules.fleet_speed));
    }

    /// A planet slot or a moon's slot stands at `to`.
    fn occupied(&self, to: Coord) -> bool {
        self.coords.iter().enumerate().any(|(s, &c)| c == to && !self.games[s].done)
    }

    /// The host's blocking reasons: a building or research queue running, units on order, or a fleet from or to it.
    fn unsettled(&self, p: usize, now: f64) -> Result<(), &'static str> {
        let running = |q: usize| self.games[p].free[q] > now && self.games[p].free[q] < NEVER;
        if running(0) { return Err("buildings are being constructed") }
        if running(1) { return Err("research is in progress") }
        if self.yards[p].busy() { return Err("units are being built") }
        if self.fleets.iter().any(|f| f.owner == p || f.target == p) { return Err("fleet missions are active") }
        Ok(())
    }
}
