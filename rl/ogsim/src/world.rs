//! Many accounts in one universe on one clock (plan/rl/world-sim-spec.md). Each account's economy moves one event at
//! a time (`Catalogue::tick`) and the world always moves the account whose next event is earliest, so an event between
//! accounts (a fleet arriving) can be put on the same clock and lands before anything later on any account.

use crate::economy::{Catalogue, Game};

pub struct World {
    pub games: Vec<Game>,
    /// The second each account's clock moves next, kept beside the games so choosing the next one reads no catalogue.
    next: Vec<f64>,
}

impl World {
    /// One account per planet config, each with its own login draws; every account has made its first decision
    /// ready once `next_decision` reaches it.
    pub fn new(cat: &Catalogue, configs: &[usize], horizon_hours: f64, bonus_hours: f64, seed: u64) -> World {
        let mut games: Vec<Game> = configs.iter().enumerate()
            .map(|(a, &c)| cat.new_paused_game(c, horizon_hours, bonus_hours, seed.wrapping_add(a as u64)))
            .collect();
        let next = games.iter_mut().map(|g| cat.peek(g)).collect();
        World { games, next }
    }

    /// The world's clock: the earliest second any account moves next.
    pub fn now(&self) -> f64 {
        self.next.iter().copied().fold(f64::INFINITY, f64::min)
    }

    /// Moves the world event by event until an account has a queue to decide (its index; `games[a].queue` is the
    /// queue), or None when every account is done.
    pub fn next_decision(&mut self, cat: &Catalogue) -> Option<usize> {
        loop {
            let a = (0..self.games.len()).filter(|&a| !self.games[a].done).min_by(|&x, &y| self.next[x].total_cmp(&self.next[y]).then(x.cmp(&y)))?;
            let deciding = cat.tick(&mut self.games[a]);
            self.next[a] = cat.peek(&mut self.games[a]);
            if deciding && !self.games[a].done {
                return Some(a);
            }
        }
    }

    /// Account a commits its deciding queue to object k (`Catalogue::step` without running its clock ahead of the others).
    pub fn commit(&mut self, cat: &Catalogue, a: usize, k: usize) {
        cat.decide(&mut self.games[a], Some(k));
        self.next[a] = cat.peek(&mut self.games[a]);
    }

    /// Account a lets this login pass and decides again at its next one.
    pub fn wait(&mut self, cat: &Catalogue, a: usize) {
        cat.decide(&mut self.games[a], None);
        self.next[a] = cat.peek(&mut self.games[a]);
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::economy::Baseline;

    /// A world of independent accounts plays each of them exactly as the one-account simulator does on its own.
    #[test]
    fn a_world_of_accounts_plays_each_one_as_the_single_planet_game() {
        let path = std::env::var("OGSIM_CATALOGUE").unwrap_or_else(|_| "../../../../storage/rl/ogsim/plain-eval.json".into());
        let Ok(mut cat) = Catalogue::load(&path) else { return eprintln!("no catalogue at {path}; skipped") };
        cat.login_gaps = vec![600.0, 1800.0, 3600.0, 7200.0, 21600.0];
        let configs: Vec<usize> = (0..cat.planets.len().min(8)).collect();
        let mut world = World::new(&cat, &configs, 240.0, 0.0, 7);
        let mut rngs: Vec<u64> = (0..configs.len() as u64).map(|a| a + 100).collect();
        let mut last = 0.0;
        while let Some(a) = world.next_decision(&cat) {
            assert!(world.games[a].t >= last, "the world clock went back");
            last = world.games[a].t;
            let game = &world.games[a];
            match cat.baseline(&game.planet, game.queue, Baseline::Player, &mut rngs[a]) {
                Some(k) => world.commit(&cat, a, k),
                None => world.wait(&cat, a),
            }
        }
        for (a, &c) in configs.iter().enumerate() {
            let mut alone = cat.new_game(c, 240.0, 0.0, 7u64.wrapping_add(a as u64));
            let mut rng = a as u64 + 100;
            while !alone.done {
                match cat.baseline(&alone.planet, alone.queue, Baseline::Player, &mut rng) {
                    Some(k) => { cat.step(&mut alone, k); }
                    None => cat.wait_turn(&mut alone),
                }
            }
            assert!(!alone.history.is_empty(), "account {a}: nothing built");
            let world_game = &world.games[a];
            assert_eq!(world_game.history.len(), alone.history.len(), "account {a}: build count");
            for (x, y) in world_game.history.iter().zip(&alone.history) {
                assert_eq!((x.0, x.1, x.2), (y.0, y.1, y.2), "account {a}: build differs");
            }
            assert_eq!(world_game.planet.res, alone.planet.res, "account {a}: final stock");
        }
    }
}
