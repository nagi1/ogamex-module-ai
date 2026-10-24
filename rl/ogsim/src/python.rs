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
    finished: Vec<Finished>,
}

fn bytes<'py, T: Copy>(py: Python<'py>, values: &[T]) -> Bound<'py, PyBytes> {
    // SAFETY: f32, f64 and u8 slices viewed as their own bytes, read-only and copied into Python.
    let raw = unsafe { std::slice::from_raw_parts(values.as_ptr() as *const u8, std::mem::size_of_val(values)) };
    PyBytes::new(py, raw)
}

impl VecEnv {
    fn fresh_game(&mut self, slot: usize) -> Game {
        let config = if self.eval { self.planets[slot] } else { self.planets[(next_random(&mut self.rng) % self.planets.len() as u64) as usize] };
        self.catalogue.new_game(config, self.horizon_hours, self.bonus_hours)
    }

    fn record(&mut self, slot: usize) {
        let g = &self.games[slot];
        self.finished.push((g.planet.config, g.value(), g.effective_rate(), g.history.len(), g.invalid));
    }
}

#[pymethods]
impl VecEnv {
    #[new]
    #[pyo3(signature = (catalogue, planets, n_envs, horizon_hours=72.0, bonus_hours=24.0, seed=0, eval=false, trace=false))]
    fn new(catalogue: &str, planets: Vec<usize>, n_envs: usize, horizon_hours: f64, bonus_hours: f64, seed: u64, eval: bool, trace: bool) -> PyResult<Self> {
        let catalogue = Arc::new(Catalogue::load(catalogue).map_err(PyValueError::new_err)?);
        if planets.is_empty() || planets.iter().any(|&p| p >= catalogue.planets.len()) {
            return Err(PyValueError::new_err("planets must be indices into the catalogue's planets"));
        }
        let n_envs = if eval { planets.len() } else { n_envs };
        let mut env = VecEnv { catalogue, games: Vec::new(), planets, horizon_hours, bonus_hours, rng: seed, eval, finished: Vec::new() };
        env.games = (0..n_envs).map(|slot| env.fresh_game(slot)).collect();
        if trace {
            env.games.iter_mut().for_each(|g| g.trace = Some(Vec::new()));
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
            self.catalogue.state_features(g, &mut state[i * STATE_DIM..(i + 1) * STATE_DIM]);
            for j in 0..k {
                let at = (i * k + j) * CAND_DIM;
                self.catalogue.candidate_features(g, j, &mut cands[at..at + CAND_DIM]);
                legal[i * k + j] = u8::from(!g.done && self.catalogue.legal(&g.planet, j));
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
            let chosen = usize::try_from(action).ok().filter(|&k| k < cat.objects.len() && cat.legal(&game.planet, k));
            let k = chosen.unwrap_or_else(|| {
                game.invalid += 1;
                (0..cat.objects.len()).find(|&k| cat.legal(&game.planet, k)).unwrap_or(0)
            });
            rewards[i] = cat.step(game, k) as f32;
            if game.done {
                dones[i] = 1;
                self.record(i);
                if !self.eval {
                    self.games[i] = self.fresh_game(i);
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
        let choices = self.games.iter().map(|g| if g.done { -1 } else { cat.baseline(g, kind, &mut rng).map_or(-1, |k| k as i64) }).collect();
        self.rng = next_random(&mut rng);
        Ok(choices)
    }

    /// Games finished since the last call: (planet config, value, effective rate per hour, decisions, invalid).
    fn take_finished(&mut self) -> Vec<Finished> {
        std::mem::take(&mut self.finished)
    }

    /// Build order of game i: (start second, host object id, end second, stock before paying).
    fn history(&self, i: usize) -> Vec<(f64, i64, f64, [f64; 3])> {
        self.games[i].history.iter().map(|&(s, k, e, stock)| (s, self.catalogue.objects[k].id, e, stock)).collect()
    }

    /// Decision-time features of game i, one entry per build: (decision second, state, candidates [K*C] row-major, legal flags).
    fn trace(&self, i: usize) -> Vec<(f64, Vec<f32>, Vec<f32>, Vec<u8>)> {
        self.games[i].trace.clone().unwrap_or_default()
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
