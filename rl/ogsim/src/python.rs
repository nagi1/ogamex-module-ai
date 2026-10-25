//! Python side of ogsim: many games stepped in one call, arrays handed over as raw bytes (numpy.frombuffer).

use crate::{next_random, Baseline, Catalogue, Game, CAND_DIM, CAND_NAMES, STATE_DIM, STATE_NAMES};
use pyo3::exceptions::PyValueError;
use pyo3::prelude::*;
use pyo3::types::PyBytes;
use std::sync::Arc;

/// A finished game: (planet config, final value, final effective rate per hour, decisions, invalid actions).
type Finished = (usize, f64, f64, usize, u32);

#[pyclass]
struct VecEnv {
    catalogue: Arc<Catalogue>,
    games: Vec<Game>,
    planets: Vec<usize>,
    horizon_hours: f64,
    bonus_hours: f64,
    rng: u64,
    /// Evaluation plays every listed planet exactly once (env i plays planets[i]) and never resets.
    eval: bool,
    /// The queue the model decides (0 buildings, 1 research); the other plays the player rule.
    queue: usize,
    trace: bool,
    /// A fixed login schedule for every game (replaying a recorded account); empty draws logins from the gap table.
    login_times: Vec<f64>,
    finished: Vec<Finished>,
}

fn bytes<'py, T: Copy>(py: Python<'py>, values: &[T]) -> Bound<'py, PyBytes> {
    // SAFETY: f32, f64 and u8 slices viewed as their own bytes, read-only and copied into Python.
    let raw = unsafe { std::slice::from_raw_parts(values.as_ptr() as *const u8, std::mem::size_of_val(values)) };
    PyBytes::new(py, raw)
}

impl VecEnv {
    fn fresh_game(&mut self, slot: usize, retries: u32) -> Game {
        let config = if self.eval { self.planets[slot] } else { self.planets[(next_random(&mut self.rng) % self.planets.len() as u64) as usize] };
        let login_seed = next_random(&mut self.rng);
        let mut game = self.catalogue.new_game(config, self.horizon_hours, self.bonus_hours, login_seed);
        game.trace = self.trace.then(Vec::new);
        game.login_times = self.login_times.clone();
        self.catalogue.play_others(&mut game, self.queue, &mut self.rng);
        // A start whose controlled queue never gets a decision (research with no lab) is played out by the rule and teaches
        // nothing; training redraws, evaluation keeps it so every listed planet has its value.
        if game.done && !self.eval && retries > 0 {
            return self.fresh_game(slot, retries - 1);
        }
        game
    }

    fn record(&mut self, slot: usize) {
        let g = &self.games[slot];
        self.finished.push((g.planet.config, g.value(), g.effective_rate(), g.history.len(), g.invalid));
    }
}

#[pymethods]
impl VecEnv {
    #[new]
    #[pyo3(signature = (catalogue, planets, n_envs, horizon_hours=72.0, bonus_hours=24.0, seed=0, eval=false, trace=false, queue=0, login_gaps=None, login_times=None))]
    fn new(catalogue: &str, planets: Vec<usize>, n_envs: usize, horizon_hours: f64, bonus_hours: f64, seed: u64, eval: bool, trace: bool, queue: usize, login_gaps: Option<Vec<f64>>, login_times: Option<Vec<f64>>) -> PyResult<Self> {
        let mut loaded = Catalogue::load(catalogue).map_err(PyValueError::new_err)?;
        loaded.login_gaps = login_gaps.unwrap_or_default();
        if login_times.is_some() && loaded.login_gaps.is_empty() {
            loaded.login_gaps = vec![1e9];
        }
        let catalogue = Arc::new(loaded);
        if planets.is_empty() || planets.iter().any(|&p| p >= catalogue.planets.len()) {
            return Err(PyValueError::new_err("planets must be indices into the catalogue's planets"));
        }
        let n_envs = if eval { planets.len() } else { n_envs };
        let mut env = VecEnv { catalogue, games: Vec::new(), planets, horizon_hours, bonus_hours, rng: seed, eval, queue, trace, login_times: login_times.unwrap_or_default(), finished: Vec::new() };
        env.games = (0..n_envs).map(|slot| env.fresh_game(slot, 100)).collect();
        for slot in 0..env.games.len() {
            if env.eval && env.games[slot].done {
                env.record(slot);
            }
        }
        if queue < 2 && !(env.catalogue.objects.iter().any(|o| o.queue == queue)) {
            return Err(PyValueError::new_err("the catalogue has no objects for that queue"));
        }
        Ok(env)
    }

    /// The feature names, in column order: EconomyChoiceEncoder's own names for the columns this simulator computes.
    #[staticmethod]
    fn feature_names() -> (Vec<&'static str>, Vec<&'static str>) {
        (STATE_NAMES.to_vec(), CAND_NAMES.to_vec())
    }

    /// (state width, candidates per choice, candidate width)
    fn dims(&self) -> (usize, usize, usize) {
        (STATE_DIM, self.catalogue.objects.len(), CAND_DIM)
    }

    /// The queue that decides next in every game.
    fn decision_queues(&self) -> Vec<usize> {
        self.games.iter().map(|g| g.queue).collect()
    }

    fn all_done(&self) -> bool {
        self.games.iter().all(|g| g.done)
    }

    /// (state f32 [N,S], candidates f32 [N,K,C], legal u8 [N,K]); a finished evaluation game has no legal row.
    fn observe<'py>(&self, py: Python<'py>) -> (Bound<'py, PyBytes>, Bound<'py, PyBytes>, Bound<'py, PyBytes>) {
        let (n, k) = (self.games.len(), self.catalogue.objects.len());
        let mut state = vec![0f32; n * STATE_DIM];
        let mut cands = vec![0f32; n * k * CAND_DIM];
        let mut legal = vec![0u8; n * k];
        for (i, g) in self.games.iter().enumerate() {
            self.catalogue.state_features(&g.planet, &mut state[i * STATE_DIM..(i + 1) * STATE_DIM]);
            for j in 0..k {
                let at = (i * k + j) * CAND_DIM;
                self.catalogue.candidate_features(&g.planet, j, &mut cands[at..at + CAND_DIM]);
                legal[i * k + j] = u8::from(!g.done && self.catalogue.queue_legal(g, j));
            }
        }
        (bytes(py, &state), bytes(py, &cands), bytes(py, &legal))
    }

    /// One decision per game; an illegal answer is counted and replaced by the first legal row. Returns
    /// (reward f32 [N], done u8 [N]); in training a finished game is recorded and replaced by a fresh one.
    fn step<'py>(&mut self, py: Python<'py>, actions: Vec<i64>) -> PyResult<(Bound<'py, PyBytes>, Bound<'py, PyBytes>)> {
        if actions.len() != self.games.len() {
            return Err(PyValueError::new_err("one action per game"));
        }
        let mut rewards = vec![0f32; self.games.len()];
        let mut dones = vec![0u8; self.games.len()];
        for (i, &action) in actions.iter().enumerate() {
            if self.games[i].done {
                dones[i] = 1;
                continue;
            }
            let cat = Arc::clone(&self.catalogue);
            let game = &mut self.games[i];
            if action == -2 {
                self.catalogue.wait_turn(&mut self.games[i]);
                if self.games[i].done {
                    dones[i] = 1;
                    self.record(i);
                }
                continue;
            }
            let chosen = usize::try_from(action).ok().filter(|&k| k < cat.objects.len() && cat.queue_legal(game, k));
            let k = chosen.unwrap_or_else(|| {
                game.invalid += 1;
                (0..cat.objects.len()).find(|&k| cat.queue_legal(game, k)).unwrap_or(0)
            });
            let mut rng = self.rng;
            rewards[i] = (cat.step(game, k) + cat.play_others(game, self.queue, &mut rng)) as f32;
            self.rng = next_random(&mut rng);
            if game.done {
                dones[i] = 1;
                self.record(i);
                if !self.eval {
                    self.games[i] = self.fresh_game(i, 100);
                }
            }
        }
        Ok((bytes(py, &rewards), bytes(py, &dones)))
    }

    /// The baseline's choice for every game (-1 for a finished one).
    fn baseline(&mut self, kind: &str) -> PyResult<Vec<i64>> {
        let kind = match kind {
            "random" => Baseline::Random,
            "soonest" => Baseline::Soonest,
            "payback" => Baseline::Payback,
            "player" => Baseline::Player,
            _ => return Err(PyValueError::new_err("baseline is random, soonest, payback or player")),
        };
        let cat = Arc::clone(&self.catalogue);
        let mut rng = self.rng;
        let choices = self.games.iter().map(|g| if g.done { -1 } else { cat.baseline(&g.planet, g.queue, kind, &mut rng).map_or(-1, |k| k as i64) }).collect();
        self.rng = next_random(&mut rng);
        Ok(choices)
    }

    /// Games finished since the last call: (planet config, value, effective rate per hour, decisions, invalid).
    fn take_finished(&mut self) -> Vec<Finished> {
        std::mem::take(&mut self.finished)
    }

    /// Build order of game i: (start second, host object id, end second, stock before paying, queue).
    fn history(&self, i: usize) -> Vec<(f64, i64, f64, [f64; 3], usize)> {
        self.games[i].history.iter().map(|&(s, k, e, stock)| (s, self.catalogue.objects[k].id, e, stock, self.catalogue.objects[k].queue)).collect()
    }

    /// Decision-time features of game i, one entry per build: (decision second, state, candidates [K*C] row-major, legal flags).
    fn trace(&self, i: usize) -> Vec<(f64, Vec<f32>, Vec<f32>, Vec<u8>, i64)> {
        self.games[i].trace.iter().flatten().map(|(d, s, c, l, k)| (*d, s.clone(), c.clone(), l.clone(), self.catalogue.objects[*k].id)).collect()
    }

    /// Stock and levels of game i now: ([metal, crystal, deuterium], [(host object id, level)]).
    fn state(&self, i: usize) -> ([f64; 3], Vec<(i64, u32)>) {
        let p = &self.games[i].planet;
        (p.res, self.catalogue.objects.iter().zip(&p.levels).map(|(o, &l)| (o.id, l)).collect())
    }

    /// Planets a catalogue file holds, so a caller can split them into training and evaluation.
    #[staticmethod]
    fn planet_count(catalogue: &str) -> PyResult<usize> {
        Ok(Catalogue::load(catalogue).map_err(PyValueError::new_err)?.planets.len())
    }
}

#[pymodule]
fn ogsim(m: &Bound<'_, PyModule>) -> PyResult<()> {
    m.add_class::<VecEnv>()
}
