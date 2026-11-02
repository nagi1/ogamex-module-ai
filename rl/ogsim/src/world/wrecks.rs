//! L2: wreck fields (WreckFieldService, CleanupWreckFields). A battle's lost ships leave a share at the defender's
//! planet; the owner repairs them at the space dock and takes them back, or the field expires. A General's own losses
//! ride home with its fleet and land as a wreck at its planet.

use super::*;

#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum WreckState { Active, Repairing, Blocked }

#[derive(Clone, Debug)]
pub struct Wreck {
    pub ships: Vec<(u32, i64)>,
    pub expires: f64,
    pub state: WreckState,
    pub started: f64,
    pub completes: f64,
}

impl Wreck {
    /// `getTimeBasedRepairProgress`, in whole percent.
    fn progress(&self, now: f64) -> i64 {
        let total = self.completes.trunc() - self.started.trunc();
        if total <= 0.0 { return if now >= self.completes { 100 } else { 0 } }
        (((now.trunc() - self.started.trunc()) / total * 100.0) as i64).clamp(0, 100)
    }

    /// The repaired share of each ship at `now`, and what is left of each.
    fn repaired(&self, now: f64) -> (Vec<(u32, i64)>, Vec<(u32, i64)>) {
        let rate = self.progress(now) as f64 / 100.0;
        let done: Vec<(u32, i64)> = self.ships.iter().map(|&(id, n)| (id, (n as f64 * rate).floor() as i64)).collect();
        let left = self.ships.iter().zip(&done).map(|(&(id, n), &(_, d))| (id, n - d)).filter(|s| s.1 > 0).collect();
        (done.into_iter().filter(|s| s.1 > 0).collect(), left)
    }
}

impl World {
    /// `calculateWreckField` / `calculateAttackerWreckField`: the ships a battle's losses leave at planet p's dock, or None
    /// when the loss misses the floors (value lost, share of the fleet that started).
    pub(super) fn wreckage(&self, cat: &Catalogue, p: usize, rules: &Rules, start: &combat::Units, lost: &combat::Units) -> Option<Vec<(u32, i64)>> {
        let wreck = rules.settings.wreck.as_ref()?;
        let value = |units: &combat::Units| rules.combat.price_of(units).iter().sum::<i64>();
        let (lost_value, start_value) = (value(lost), value(start));
        if start_value == 0 || lost_value < wreck.min_loss || (lost_value as f64 / start_value as f64) * 100.0 < wreck.min_fleet_percentage { return None }
        let level = self.moon_level(cat, p, wreck.dock_object).max(1).min(wreck.percentage.len() - 1);
        let share = wreck.percentage[level] / 100.0;
        let mut ships: Vec<(u32, i64)> = lost.iter()
            .filter(|&(&id, &n)| n > 0 && !rules.combat.is_defense(id) && !wreck.excluded.contains(&id))
            .map(|(&id, &n)| (id, (n as f64 * share).floor() as i64)).filter(|s| s.1 > 0).collect();
        ships.sort();
        Some(ships)
    }

    /// `createWreckField`: an untouched field takes the ships in and its clock restarts; one under repair (or waiting)
    /// leaves the new ships waiting in a field of their own.
    pub(super) fn add_wreck(&mut self, p: usize, ships: Vec<(u32, i64)>, now: f64) {
        let Some(lifetime) = self.rules[p].settings.wreck.as_ref().map(WreckRules::lifetime) else { return };
        let fields = self.wrecks.entry(p).or_default();
        match fields.first_mut() {
            Some(field) if field.state == WreckState::Active => {
                for (id, n) in ships {
                    match field.ships.iter_mut().find(|s| s.0 == id) {
                        Some(s) => s.1 += n,
                        None => field.ships.push((id, n)),
                    }
                }
                field.expires = now + lifetime;
            }
            Some(_) => fields.push(Wreck { ships, expires: now + lifetime, state: WreckState::Blocked, started: 0.0, completes: 0.0 }),
            None => fields.push(Wreck { ships, expires: now + lifetime, state: WreckState::Active, started: 0.0, completes: 0.0 }),
        }
    }

    /// FacilitiesController::startRepairs on planet p's first field: a dock, a field neither expired nor already repairing
    /// with ships in it, and no other field repairing there.
    pub fn start_repairs(&mut self, cat: &Catalogue, p: usize, now: f64) -> Result<(), &'static str> {
        let wreck = self.rules[p].settings.wreck.clone().ok_or("no wreck rules")?;
        if self.moon_level(cat, p, wreck.dock_object) < 1 { return Err("space dock required") }
        let fields = self.wrecks.get_mut(&p).filter(|f| !f.is_empty()).ok_or("no wreck field")?;
        let field = &fields[0];
        if field.state == WreckState::Repairing || field.expires < now { return Err("cannot be repaired") }
        let ships: i64 = field.ships.iter().map(|s| s.1).sum();
        if ships == 0 { return Err("no ships to repair") }
        if fields[1..].iter().any(|f| f.state == WreckState::Repairing) { return Err("another field is repairing") }
        let field = &mut fields[0];
        field.state = WreckState::Repairing;
        field.started = now;
        field.completes = now + wreck.repair_seconds(ships);
        Ok(())
    }

    /// `collectRepairedShipsAtomic`: the repairing field's ships repaired so far land on planet p; the rest stay in repair.
    pub fn collect_wreck(&mut self, cat: &Catalogue, p: usize, now: f64) -> Result<i64, &'static str> {
        let fields = self.wrecks.get_mut(&p).ok_or("no wreck field")?;
        let i = fields.iter().position(|f| f.state == WreckState::Repairing).ok_or(if fields.is_empty() { "no wreck field" } else { "repairs not started" })?;
        let (done, left) = fields[i].repaired(now);
        match left.is_empty() {
            true => { fields.remove(i); }
            false => fields[i].ships = left,
        }
        Ok(self.wreck_lands(cat, p, now, &done))
    }

    /// CleanupWreckFields run at `at`: untouched fields past their lifetime are gone, and a field repairing for longer
    /// than the lifetime lands what was repaired on planet p.
    pub fn sweep_wrecks(&mut self, cat: &Catalogue, p: usize, at: f64) {
        let Some(lifetime) = self.rules[p].settings.wreck.as_ref().map(WreckRules::lifetime) else { return };
        let Some(fields) = self.wrecks.get_mut(&p) else { return };
        fields.retain(|f| f.state == WreckState::Repairing || f.expires >= at);
        let due: Vec<Wreck> = fields.iter().filter(|f| f.state == WreckState::Repairing && f.started < at - lifetime).cloned().collect();
        fields.retain(|f| !(f.state == WreckState::Repairing && f.started < at - lifetime));
        for field in due {
            let (done, _) = field.repaired(at);
            self.wreck_lands(cat, p, at, &done);
        }
    }

    fn wreck_lands(&mut self, cat: &Catalogue, p: usize, at: f64, ships: &[(u32, i64)]) -> i64 {
        for &(id, n) in ships {
            if let Some(u) = self.rules[p].units.index(id as i64) { self.yards[p].counts[u] += n as u64 }
        }
        self.sync_units(cat, p);
        let total = ships.iter().map(|s| s.1).sum();
        if total > 0 { self.wreck_log.push((p, at, total)) }
        total
    }
}
