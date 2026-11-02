//! Python side of ogsim: many games stepped in one call, arrays handed over as raw bytes (numpy.frombuffer).

use crate::fleet::Coord;
use crate::players::{self, Ledger, Player};
use crate::score;
use crate::season::{Detector, Policy, Universe, ACCOUNT_NAMES, DAY, LEARNER_CAND, LEARNER_STATE};
use crate::world::{Colonisation, Decision, Rules, World};
use crate::{errands, next_random, Baseline, Catalogue, Game, CAND_DIM, CAND_NAMES, STATE_DIM, STATE_NAMES};
use pyo3::exceptions::PyValueError;
use pyo3::prelude::*;
use pyo3::types::PyBytes;
use rayon::prelude::*;
use crate::HashMap;
use std::sync::Arc;

/// A finished game: (planet config, final value, final effective rate per hour, decisions, invalid actions).
/// A finished account: (planet config, value, effective rate, decisions, invalid actions, fleet value). The last one
/// is everything the account owns beyond its economy — ships at price, cargo in flight, half the fuel — so an evaluation
/// can tell a policy that lost fleets from one that built less.
type Finished = (usize, f64, f64, usize, u32, f64);

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
        // The economy env has no ships, so the fleet share of an account's worth is nothing.
        self.finished.push((g.planet.config, g.value(), g.effective_rate(), g.history.len(), g.invalid, 0.0));
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

/// A transition closed by the account deciding again or by its world ending: (ticket, reward, done).
type Closed = (u64, f32, u8);

/// Many worlds of many accounts each. Every world holds one pending decision of an account the caller controls; the
/// other accounts play the player rule inside the world. Several accounts act in one world, so a decision's reward is
/// known only when that account decides again: `observe` hands out a ticket per decision and `step` returns the
/// transitions it closed, by ticket.
#[pyclass]
struct VecWorld {
    catalogue: Arc<Catalogue>,
    rules: Option<Arc<Rules>>,
    worlds: Vec<World>,
    /// Per world: the deciding account and its ticket.
    deciding: Vec<(usize, u64)>,
    /// Per world and account: the open transition (ticket, value when it acted).
    open: Vec<Vec<Option<(u64, f64)>>>,
    controlled: Vec<bool>,
    planets: Vec<usize>,
    horizon_hours: f64,
    bonus_hours: f64,
    rng: u64,
    tickets: u64,
    closed: Vec<Closed>,
    finished: Vec<Finished>,
    /// Errand mode: a controlled account decides what its fleet does at a login (kind 2) instead of which object to
    /// build, and every account's economy and ships are played by the scripted player rule.
    errands: bool,
    /// Per world and account, the scripted player that plays the rest of the login.
    players: Vec<Vec<Player>>,
    ledgers: Vec<Vec<Ledger>>,
    /// Per world, the errands the deciding account was offered (row 0 is always `wait`).
    offered: Vec<Vec<errands::Errand>>,
    /// Credit attribution: the worth of every fleet event an account has had since it last decided, and of the ones whose
    /// fleet the decision being closed dispatched. The interval reward drops the first and adds the second, so a raid is
    /// credited to the decision that sent it and an enemy raid is not blamed on a decision that had nothing to do with it.
    during: Vec<Vec<f64>>,
    credited: Vec<Vec<f64>>,
    /// (world, owner, departure second) -> the ticket that dispatched that fleet.
    sent: HashMap<(usize, usize, u64), u64>,
    /// How many events of each world's log have been attributed.
    read: Vec<usize>,
}

impl VecWorld {
    /// The deciding account's value: the economy's in the economy mode, everything it owns (ships at price, stock, what
    /// flies) in the errand mode, where a raid or a lost fleet has to show up in the reward.
    fn value(&self, w: usize, a: usize) -> f64 {
        if self.errands { players::account_value(&self.worlds[w], a) } else { self.worlds[w].games[a].value() }
    }

    fn fresh_world(&mut self) -> World {
        let accounts = self.controlled.len();
        let configs: Vec<usize> = (0..accounts).map(|_| self.planets[(next_random(&mut self.rng) % self.planets.len() as u64) as usize]).collect();
        let mut world = World::new(&self.catalogue, &configs, self.horizon_hours, self.bonus_hours, next_random(&mut self.rng));
        if let Some(rules) = &self.rules {
            let (galaxies, systems) = (rules.settings.number_of_galaxies as u64, rules.settings.number_of_systems as u64);
            let coords = (0..accounts).map(|_| Coord {
                galaxy: 1 + (next_random(&mut self.rng) % galaxies) as i64,
                system: 1 + (next_random(&mut self.rng) % systems) as i64,
                position: 4 + (next_random(&mut self.rng) % 9) as i64,
            }).collect();
            world.arm(vec![Arc::clone(rules); accounts], coords);
            // Errands live at the login, which an account with nothing left to build must still get.
            if self.errands { world.enable_idle_logins(&self.catalogue); }
        }
        let seed = next_random(&mut self.rng);
        let mixed = self.errands;
        let players: Vec<Player> = (0..accounts).map(|a| {
            let s = seed.wrapping_add(a as u64);
            match (self.controlled[a], mixed, a % 3) {
                // A controlled account's errands are the model's; the rule only plays its economy and its ships.
                (true, _, _) => Player::Miner(players::Miner::new(s)),
                (false, false, _) => Player::Miner(players::Miner::new(s)),
                (false, true, 0) => Player::Raider(players::Raider::new(s)),
                (false, true, 1) => Player::Saver(players::FleetSaver::new(s)),
                (false, true, _) => Player::Miner(players::Miner::new(s)),
            }
        }).collect();
        self.during.push(vec![0.0; accounts]);
        self.credited.push(vec![0.0; accounts]);
        self.read.push(0);
        self.players.push(players);
        self.ledgers.push((0..accounts).map(|_| Ledger::default()).collect());
        world
    }

    /// Plays the rest of a login for account a: its economy queue and its ship orders, as the scripted player rule does.
    fn play_rest(&mut self, w: usize, cat: &Catalogue, a: usize) {
        let (worlds, players, ledgers) = (&mut self.worlds, &mut self.players, &mut self.ledgers);
        // A colony slot decides with its account's player and ledger.
        let owner = worlds[w].account_of(a);
        let player = &mut players[w][owner];
        let ledger = &mut ledgers[w][owner];
        let world = &mut worlds[w];
        match player.act(world, cat, a, ledger) {
            Some(k) => world.commit(cat, a, k),
            None => world.wait(cat, a),
        }
    }

    /// Runs world w until a controlled account decides, closing that account's previous transition; a finished world
    /// closes every open transition (with the bonus for the production left running), is recorded, and starts afresh.
    /// Tie every fleet the deciding account just sent to its ticket, so the log can credit the outcome back to it.
    fn register(&mut self, w: usize, a: usize, ticket: u64) {
        for fleet in &self.worlds[w].fleets {
            if fleet.owner == a {
                // A fleet already claimed belongs to an older ticket: sending it again must not steal its outcome.
                self.sent.entry((w, a, fleet.departed.to_bits())).or_insert(ticket);
            }
        }
    }

    /// Read the events played since the last look: every one is worth something to its owner, and the ones whose fleet
    /// this account's open ticket dispatched are worth that to the decision being closed.
    fn attribute(&mut self, w: usize) {
        let start = self.read[w];
        let world = &self.worlds[w];
        let mut rows: Vec<(usize, f64, bool)> = Vec::new();
        for e in &world.events[start..] {
            let key = (w, e.owner, e.departed.to_bits());
            let price = world.rules.get(e.owner).map_or(0.0, |r| {
                e.ships_lost.iter().map(|&(id, n)| players::unit_price(r, id) * n.max(0) as f64).sum::<f64>()
            });
            let worth = players::weighted(e.gained) - price;
            // Credit only the fleet THIS decision sent. A flight still in the air from an older ticket keeps its worth out
            // of every reward rather than handing it to a decision that did not cause it.
            let ours = self.open[w].get(e.owner).and_then(|t| t.map(|(ticket, _)| ticket)) == self.sent.get(&key).copied();
            rows.push((e.owner, worth, ours));
        }
        self.read[w] = world.events.len();
        for (owner, worth, ours) in rows {
            if owner < self.during[w].len() {
                self.during[w][owner] += worth;
                if ours {
                    self.credited[w][owner] += worth;
                }
            }
        }
    }

    /// Whether slot a's decision is the caller's: a controlled capital's.
    fn model_decides(&self, a: usize) -> bool {
        self.controlled.get(a).copied().unwrap_or(false)
    }

    fn advance(&mut self, w: usize) {
        let cat = Arc::clone(&self.catalogue);
        loop {
            self.attribute(w);
            let Some(a) = self.worlds[w].next_decision(&cat) else {
                for a in 0..self.controlled.len() {
                    if let Some((ticket, before)) = self.open[w][a].take() {
                        let bonus = self.worlds[w].games[a].bonus_hours * self.worlds[w].games[a].effective_rate();
                        let value = self.value(w, a);
                        let reward = value - before + bonus - self.during[w][a] + self.credited[w][a];
                        self.closed.push((ticket, reward as f32, 1));
                        self.during[w][a] = 0.0;
                        self.credited[w][a] = 0.0;
                    }
                    let g = &self.worlds[w].games[a];
                    let fleet = players::account_value(&self.worlds[w], a) - g.value();
                    self.finished.push((g.planet.config, g.value(), g.effective_rate(), g.history.len(), g.invalid, fleet));
                }
                self.sent.retain(|k, _| k.0 != w);
                self.worlds[w] = self.fresh_world();
                self.read[w] = 0;
                continue;
            };
            if !self.model_decides(a) {
                self.play_rest(w, &cat, a);
                continue;
            }
            let value = self.value(w, a);
            if let Some((ticket, before)) = self.open[w][a].take() {
                let reward = value - before - self.during[w][a] + self.credited[w][a];
                self.closed.push((ticket, reward as f32, 0));
                self.during[w][a] = 0.0;
                self.credited[w][a] = 0.0;
            }
            self.tickets += 1;
            self.deciding[w] = (a, self.tickets);
            if self.errands {
                self.offered[w] = errands::candidates(&mut self.worlds[w], &cat, a);
            }
            return;
        }
    }
}

#[pymethods]
impl VecWorld {
    /// `controlled[a]` says whether account slot a of every world is the caller's; the rest play the player rule.
    /// With `units`, `fleet_settings` and `combat` (the host exports) the worlds are armed: ships, defence and fleets.
    #[new]
    #[pyo3(signature = (catalogue, planets, n_worlds, controlled, horizon_hours=240.0, bonus_hours=24.0, seed=0, login_gaps=None, units=None, fleet_settings=None, combat=None, errands=false))]
    #[allow(clippy::too_many_arguments)]
    fn new(catalogue: &str, planets: Vec<usize>, n_worlds: usize, controlled: Vec<bool>, horizon_hours: f64, bonus_hours: f64, seed: u64, login_gaps: Option<Vec<f64>>, units: Option<&str>, fleet_settings: Option<&str>, combat: Option<&str>, errands: bool) -> PyResult<Self> {
        let mut loaded = Catalogue::load(catalogue).map_err(PyValueError::new_err)?;
        loaded.login_gaps = login_gaps.unwrap_or_default();
        if loaded.login_gaps.is_empty() {
            return Err(PyValueError::new_err("a world needs login gaps: an account that is always there decides forever at one second"));
        }
        if planets.is_empty() || planets.iter().any(|&p| p >= loaded.planets.len()) || !controlled.iter().any(|&c| c) {
            return Err(PyValueError::new_err("planets must index the catalogue and at least one account must be controlled"));
        }
        let rules = match (units, fleet_settings, combat) {
            (Some(u), Some(f), Some(c)) => Some(Arc::new(Rules::load(&loaded, u, f, c).map_err(PyValueError::new_err)?)),
            (None, None, None) => None,
            _ => return Err(PyValueError::new_err("units, fleet_settings and combat come together")),
        };
        let accounts = controlled.len();
        if errands && rules.is_none() {
            return Err(PyValueError::new_err("errands need the units, fleet_settings and combat exports: nothing can fly without them"));
        }
        let mut env = VecWorld { catalogue: Arc::new(loaded), rules, worlds: Vec::new(), deciding: vec![(0, 0); n_worlds], open: vec![vec![None; accounts]; n_worlds], controlled, planets, horizon_hours, bonus_hours, rng: seed, tickets: 0, closed: Vec::new(), finished: Vec::new(), errands, players: Vec::new(), ledgers: Vec::new(), offered: vec![Vec::new(); n_worlds], during: Vec::new(), credited: Vec::new(), sent: HashMap::default(), read: Vec::new() };
        env.worlds = (0..n_worlds).map(|_| env.fresh_world()).collect();
        for w in 0..n_worlds {
            env.advance(w);
        }
        Ok(env)
    }

    /// (state width, candidates per choice, candidate width), as VecEnv, so the same models read both. In the errand
    /// mode the candidates are the errands a login may take, padded to the width the trainers agree on.
    fn dims(&self) -> (usize, usize, usize) {
        if self.errands { return (errands::STATE_DIM, errands::MAX_CANDIDATES, errands::CAND_DIM) }
        (STATE_DIM, self.catalogue.objects.len(), CAND_DIM)
    }

    /// The feature names of the decision this world hands out, in column order.
    fn feature_names(&self) -> (Vec<&'static str>, Vec<&'static str>) {
        if self.errands { return (errands::STATE_NAMES.to_vec(), errands::CAND_NAMES.to_vec()) }
        (STATE_NAMES.to_vec(), CAND_NAMES.to_vec())
    }

    /// Per world: (deciding account, the decision kind, ticket). Kind 2 is an errand.
    fn decisions(&self) -> Vec<(usize, usize, u64)> {
        self.deciding.iter().enumerate().map(|(w, &(a, t))| (a, if self.errands { 2 } else { self.worlds[w].games[a].queue }, t)).collect()
    }

    /// The deciding account's planet in every world: (state f32 [N,S], candidates f32 [N,K,C], legal u8 [N,K]).
    fn observe<'py>(&mut self, py: Python<'py>) -> (Bound<'py, PyBytes>, Bound<'py, PyBytes>, Bound<'py, PyBytes>) {
        if self.errands { return self.observe_errands(py) }
        let (n, k) = (self.worlds.len(), self.catalogue.objects.len());
        let mut state = vec![0f32; n * STATE_DIM];
        let mut cands = vec![0f32; n * k * CAND_DIM];
        let mut legal = vec![0u8; n * k];
        for (w, world) in self.worlds.iter().enumerate() {
            let a = self.deciding[w].0;
            let planet = &world.games[a].planet;
            self.catalogue.state_features(planet, &mut state[w * STATE_DIM..(w + 1) * STATE_DIM]);
            for j in 0..k {
                let at = (w * k + j) * CAND_DIM;
                self.catalogue.candidate_features(planet, j, &mut cands[at..at + CAND_DIM]);
                legal[w * k + j] = u8::from(world.legal(&self.catalogue, a, j));
            }
        }
        (bytes(py, &state), bytes(py, &cands), bytes(py, &legal))
    }

    /// The errand rows: the same three arrays, one row per offered errand (row 0 is `wait`), unused rows illegal.
    fn observe_errands<'py>(&mut self, py: Python<'py>) -> (Bound<'py, PyBytes>, Bound<'py, PyBytes>, Bound<'py, PyBytes>) {
        let (n, k) = (self.worlds.len(), errands::MAX_CANDIDATES);
        let mut state = vec![0f32; n * errands::STATE_DIM];
        let mut cands = vec![0f32; n * k * errands::CAND_DIM];
        let mut legal = vec![0u8; n * k];
        let cat = Arc::clone(&self.catalogue);
        for w in 0..n {
            let a = self.deciding[w].0;
            state[w * errands::STATE_DIM..(w + 1) * errands::STATE_DIM].copy_from_slice(&errands::account_features(&self.worlds[w], &cat, a));
            for (j, errand) in self.offered[w].iter().enumerate() {
                let at = (w * k + j) * errands::CAND_DIM;
                cands[at..at + errands::CAND_DIM].copy_from_slice(&errands::candidate_features(&mut self.worlds[w], &cat, a, *errand));
                legal[w * k + j] = 1;
            }
        }
        (bytes(py, &state), bytes(py, &cands), bytes(py, &legal))
    }

    /// One answer per world for its deciding account: an object index, or -2 to let the login pass. An illegal answer
    /// is counted and replaced by the first legal row. Returns the transitions closed since the last call.
    fn step(&mut self, actions: Vec<i64>) -> PyResult<Vec<Closed>> {
        if actions.len() != self.worlds.len() {
            return Err(PyValueError::new_err("one action per world"));
        }
        let cat = Arc::clone(&self.catalogue);
        for (w, &action) in actions.iter().enumerate() {
            let (a, ticket) = self.deciding[w];
            self.open[w][a] = Some((ticket, self.value(w, a)));
            if self.errands {
                let errand = usize::try_from(action).ok().and_then(|i| self.offered[w].get(i).copied());
                match errand {
                    Some(e) => { if errands::apply(&mut self.worlds[w], &cat, a, e).is_err() { self.worlds[w].games[a].invalid += 1 } }
                    None => {}
                }
                self.register(w, a, ticket);
                self.play_rest(w, &cat, a);
                self.advance(w);
                continue;
            }
            let world = &mut self.worlds[w];
            if action == -2 {
                world.wait(&cat, a);
            } else {
                let chosen = usize::try_from(action).ok().filter(|&k| k < cat.objects.len() && world.legal(&cat, a, k));
                match chosen.or_else(|| (0..cat.objects.len()).find(|&k| world.legal(&cat, a, k))) {
                    Some(k) => {
                        if chosen.is_none() { world.games[a].invalid += 1 }
                        world.commit(&cat, a, k);
                    }
                    None => world.wait(&cat, a),
                }
            }
            self.advance(w);
        }
        Ok(std::mem::take(&mut self.closed))
    }

    /// The player rule's answer for every world's deciding account (-2 when it has none). In the errand mode, the row of
    /// the errand the scripted mix would take (row 0 is `wait`), which is the behaviour-cloning label.
    fn baseline(&mut self) -> Vec<i64> {
        if self.errands {
            let cat = Arc::clone(&self.catalogue);
            return self.deciding.iter().enumerate().map(|(w, &(a, _))| {
                let teacher = errands::teacher_choice(&mut self.worlds[w], &cat, a);
                self.offered[w].iter().position(|e| *e == teacher).map_or(0, |i| i as i64)
            }).collect();
        }
        let cat = Arc::clone(&self.catalogue);
        let mut rng = self.rng;
        let picks = self.worlds.iter().zip(&self.deciding).map(|(world, &(a, _))| {
            let g = &world.games[a];
            cat.baseline(&g.planet, g.queue, Baseline::Player, &mut rng).filter(|&k| world.legal(&cat, a, k)).map_or(-2, |k| k as i64)
        }).collect();
        self.rng = next_random(&mut rng);
        picks
    }

    /// Accounts whose world finished since the last call: (planet config, value, effective rate, builds, invalid).
    fn take_finished(&mut self) -> Vec<Finished> {
        std::mem::take(&mut self.finished)
    }
}

/// W24: training in the season's own universe (`season::Universe`), the world `examples/season.rs` writes and `hds.py`
/// judges. The builds of the first `controlled` accounts' planets in `queue` (`BOTH_QUEUES`: buildings and research) are
/// the model's; their personas play the rest of each login, and every other account is its persona. A decision's reward
/// is the growth of the account's highscore until it decides again (ln score: the host's own measure, in which a store
/// counts nothing and a year's compounding is not 1000x a first week's), plus `lambda` times the detector's logit at each
/// midnight crossed (plan/rl/TRAINING-RUN.md).
#[pyclass]
struct SeasonWorld {
    shared: Arc<SeasonRules>,
    seats: Vec<Seat>,
    policy: Option<Policy>,
}

/// What every season of a `SeasonWorld` reads.
struct SeasonRules {
    catalogue: Arc<Catalogue>,
    colonisation: Option<Arc<Colonisation>>,
    detector: Option<Arc<Detector>>,
    lambda: f64,
    data: String,
    days: usize,
    accounts: usize,
    controlled: usize,
    arms: Vec<String>,
    queue: usize,
}

/// One season in play: seats step in parallel, each alone with its own universe.
struct Seat {
    rng: u64,
    /// The high bits of its tickets, so tickets stay unique without a shared counter.
    id: u64,
    universe: Universe,
    midnight: f64,
    deciding: (usize, u64),
    advice: Option<usize>,
    /// In the errand mode, the deciding login's errands and the rows the model reads (state, candidates, legal).
    errand: Option<(Vec<errands::Errand>, Vec<f32>, Vec<f32>, Vec<u8>)>,
    /// Until this second the personas play: seasons started together would keep every batch on one day of the year.
    warm_until: f64,
    open: Vec<Option<(u64, f64)>>,
    realism: Vec<f64>,
    tickets: u64,
    closed: Vec<Closed>,
    finished: Vec<Finished>,
    /// Per account, the decisions `collect` has played and not yet handed over.
    streams: Vec<Vec<Row>>,
    recording: bool,
}

/// One decision as the learner reads it.
struct Row { state: Vec<f32>, cands: Vec<f32>, legal: Vec<u8>, action: i64, logp: f32, value: f32, reward: f32, done: u8, closed: bool, day: f32 }

/// What `collect` hands over, flat, for `ppo_update`.
#[derive(Default)]
struct Rollout { state: Vec<f32>, cands: Vec<f32>, legal: Vec<u8>, action: Vec<i64>, logp: Vec<f32>, adv: Vec<f32>, ret: Vec<f32> }

const BOTH_QUEUES: usize = 3;
/// The decision kind of a login's fleet errand (`errands.rs`): the season's first `controlled` accounts fly by the model.
const ERRANDS: usize = 2;
/// A `done` that cuts the stream at the season's horizon, not an end of the account: the universe plays on, so the
/// trainer bootstraps from V (1 is the account quitting, which ends it).
const CUT: u8 = 2;
const LOGIT_CLIP: f32 = 5.0;

fn log_score(world: &World, owner: usize) -> f64 {
    (1.0 + score::of(world, owner).total.max(0) as f64).ln()
}

impl SeasonRules {
    fn universe(&self, seed: u64) -> Universe {
        Universe::new(Arc::clone(&self.catalogue), self.colonisation.clone(), &self.data, self.days, self.accounts, seed, &self.arms)
    }
}

impl Seat {
    fn close(&mut self, owner: usize, done: u8) {
        let Some((ticket, before)) = self.open[owner].take() else { return };
        let reward = log_score(&self.universe.world, owner) - before + self.realism[owner];
        if self.recording {
            if let Some(row) = self.streams[owner].last_mut() { (row.reward, row.done, row.closed) = (reward as f32, done, true) }
        } else {
            self.closed.push((ticket, reward as f32, done));
        }
        self.realism[owner] = 0.0;
    }

    /// Plays `rows` decisions with the policy, every row recorded on its account's stream.
    fn play_rows(&mut self, rules: &SeasonRules, policy: &Policy, rows: usize) {
        self.recording = true;
        let k = rules.catalogue.objects.len() + 1;
        for _ in 0..rows {
            let (mut state, mut cands, mut legal) = (vec![0.0; LEARNER_STATE], vec![0.0; k * LEARNER_CAND], vec![0; k]);
            let a = self.deciding.0;
            match &self.errand {
                Some((_, s, c, l)) => (state, cands, legal) = (s.clone(), c.clone(), l.clone()),
                None => self.universe.observe(a, self.advice, &mut state, &mut cands, &mut legal),
            }
            let (action, logp, value) = policy.sample(&state, &cands, &legal, 1.0, &mut self.rng);
            let owner = self.universe.world.account_of(a);
            let day = (self.universe.world.games[a].t / DAY) as f32;
            self.streams[owner].push(Row { state, cands, legal, action: action as i64, logp, value, reward: 0.0, done: 0, closed: false, day });
            self.answer(rules, action as i64);
        }
    }

    /// GAE along each account's stream, discounting by game days, over every row whose successor is known: the rest wait.
    /// An account that quit ends with no future; the season's horizon only cuts it, so that one bootstraps from its V.
    fn hand_over(&mut self, gamma: f32, lam: f32, scale: f32, out: &mut Rollout) {
        for stream in &mut self.streams {
            let mut start = 0;
            while start < stream.len() {
                let Some(last) = (start..stream.len()).take_while(|&i| stream[i].closed).find(|&i| stream[i].done > 0 || !stream.get(i + 1).is_some_and(|next| next.closed)) else { break };
                let successor = (stream[last].done == 0).then(|| stream.get(last + 1)).flatten();
                if stream[last].done == 0 && successor.is_none() { break }
                let (mut following, mut following_day) = match successor {
                    Some(next) => (next.value, next.day),
                    None if stream[last].done == CUT => (stream[last].value, stream[last].day),
                    None => (0.0, stream[last].day),
                };
                let mut trace = 0.0;
                let mut advantages = vec![0.0; last + 1 - start];
                for i in (start..=last).rev() {
                    let row = &stream[i];
                    let days = (following_day - row.day).max(0.0);
                    let discount = gamma.powf(days);
                    trace = row.reward / scale + discount * following - row.value + discount * lam.powf(days) * trace;
                    advantages[i - start] = trace;
                    (following, following_day) = (row.value, row.day);
                }
                for (row, adv) in stream[start..=last].iter().zip(advantages) {
                    out.state.extend_from_slice(&row.state);
                    out.cands.extend_from_slice(&row.cands);
                    out.legal.extend_from_slice(&row.legal);
                    out.action.push(row.action);
                    out.logp.push(row.logp);
                    out.adv.push(adv);
                    out.ret.push(adv + row.value);
                }
                start = last + 1;
            }
            stream.drain(..start);
        }
    }

    /// At a midnight, what the detector says of every controlled account still playing.
    fn judge(&mut self, rules: &SeasonRules, midnight: f64) {
        let Some(detector) = &rules.detector else { return };
        // The warm-up's days are the teacher's, and billing them would land on the account's first real decision.
        if midnight < self.warm_until { return }
        for owner in 0..rules.controlled {
            if self.universe.world.inactive(&rules.catalogue, owner, midnight) { continue }
            let u = &self.universe;
            if let Some(logit) = detector.logit(&u.measures(owner, midnight), u.rank[owner], midnight / DAY) {
                self.realism[owner] += rules.lambda * logit.clamp(-LOGIT_CLIP, LOGIT_CLIP) as f64;
            }
        }
    }

    /// Plays on until a controlled account has a build in `queue` to decide; a retired account's stream ends there, and a
    /// finished season closes every open decision, is recorded, and starts afresh.
    fn advance(&mut self, rules: &SeasonRules) {
        loop {
            let Some(a) = self.universe.next_decision() else {
                for owner in 0..rules.accounts {
                    self.close(owner, CUT);
                    let world = &self.universe.world;
                    let slots = world.planets_of(owner);
                    let invalid = slots.iter().map(|&s| world.games[s].invalid).sum();
                    let builds = slots.iter().map(|&s| world.games[s].history.len()).sum();
                    self.finished.push((slots.len(), score::of(world, owner).total as f64, world.games[owner].effective_rate(), builds, invalid, 0.0));
                }
                self.universe = rules.universe(next_random(&mut self.rng));
                self.midnight = DAY;
                self.warm_until = 0.0;
                self.realism = vec![0.0; rules.accounts];
                continue;
            };
            let t = self.universe.world.games[a].t;
            while self.midnight <= t && self.midnight <= rules.days as f64 * DAY {
                let midnight = self.midnight;
                self.universe.day_end(midnight);
                self.judge(rules, midnight);
                self.midnight += DAY;
            }
            let u = &mut self.universe;
            let owner = u.world.account_of(a);
            let plays = u.plays(a);
            let build = u.world.decision_kind(a) == Decision::Build && (rules.queue == BOTH_QUEUES || u.world.games[a].queue == rules.queue);
            // An errand is the capital's, at every login it plays (a build login or an idle one).
            let decides = if rules.queue == ERRANDS { a == owner } else { build };
            if owner >= rules.controlled || !plays || !decides || t < self.warm_until {
                u.play(a, None);
                if owner < rules.controlled && !plays { self.close(owner, 1) }
                continue;
            }
            self.close(owner, 0);
            self.tickets += 1;
            self.deciding = (a, self.id << 40 | self.tickets);
            if rules.queue == ERRANDS {
                let rows = self.universe.observe_errands(a);
                self.advice = Some(self.universe.errand_teacher(a, &rows.0));
                self.errand = Some(rows);
                return;
            }
            self.advice = self.universe.teacher(a);
            return;
        }
    }

    /// One answer: an object index, or the last row to pass. An illegal answer is counted and replaced by the first legal row.
    fn answer(&mut self, rules: &SeasonRules, action: i64) {
        let cat = &rules.catalogue;
        let (a, ticket) = self.deciding;
        let u = &mut self.universe;
        let owner = u.world.account_of(a);
        self.open[owner] = Some((ticket, log_score(&u.world, owner)));
        if let Some((offered, ..)) = self.errand.take() {
            u.run_errand(a, &offered, usize::try_from(action).unwrap_or(usize::MAX));
            u.play(a, None);
            return self.advance(rules);
        }
        let chosen = usize::try_from(action).ok().filter(|&k| k < cat.objects.len() && u.world.legal(cat, a, k));
        let pass = action == cat.objects.len() as i64;
        let fallback = (!pass && chosen.is_none()).then(|| (0..cat.objects.len()).find(|&k| u.world.legal(cat, a, k))).flatten();
        if !pass && chosen.is_none() { u.world.games[a].invalid += 1 }
        u.play(a, Some(chosen.or(fallback)));
        self.advance(rules);
    }
}

#[pymethods]
impl SeasonWorld {
    /// `data` holds the units, fleet and combat exports and the login gaps; `catalogue` is the economy export with its
    /// colonisation block; `arms` are the season's switches by their command-line names (`run.sh` of the flow row);
    /// `detector` is `ogrl.discriminator`'s file, read when `lambda` is above 0.
    #[new]
    #[pyo3(signature = (data, catalogue, n_worlds, arms, controlled=6, accounts=64, days=365, seed=0, queue=BOTH_QUEUES, detector=None, lambda=0.0, stagger=false))]
    #[allow(clippy::too_many_arguments)]
    fn new(py: Python<'_>, data: &str, catalogue: &str, n_worlds: usize, arms: Vec<String>, controlled: usize, accounts: usize, days: usize, seed: u64, queue: usize, detector: Option<&str>, lambda: f64, stagger: bool) -> PyResult<Self> {
        if controlled == 0 || controlled > accounts {
            return Err(PyValueError::new_err("controlled must be between 1 and the number of accounts"));
        }
        let loaded = Universe::catalogue(data, catalogue, &arms).map_err(PyValueError::new_err)?;
        let colonisation = Some(Arc::new(Colonisation::load(catalogue).map_err(PyValueError::new_err)?));
        let detector = detector.filter(|_| lambda > 0.0).map(|path| Detector::load(path).map(Arc::new).map_err(PyValueError::new_err)).transpose()?;
        let mut arms = arms;
        if queue == ERRANDS { arms.push(format!("fleet-model={controlled}")) }
        let shared = Arc::new(SeasonRules { catalogue: Arc::new(loaded), colonisation, detector, lambda, data: data.to_string(), days, accounts, controlled, arms, queue });
        let mut rng = seed;
        let draws: Vec<(u64, u64, u64)> = (0..n_worlds).map(|_| (next_random(&mut rng), next_random(&mut rng), next_random(&mut rng))).collect();
        let seats = py.detach(|| draws.into_par_iter().enumerate().map(|(w, (universe, own, warm))| {
            let mut seat = Seat {
                rng: own, id: w as u64, universe: shared.universe(universe), midnight: DAY, deciding: (0, 0), advice: None, errand: None,
                warm_until: if stagger { (warm % days as u64) as f64 * DAY } else { 0.0 },
                open: vec![None; accounts], realism: vec![0.0; accounts], tickets: 0, closed: Vec::new(), finished: Vec::new(),
                streams: (0..accounts).map(|_| Vec::new()).collect(), recording: false,
            };
            seat.advance(&shared);
            seat
        }).collect());
        Ok(SeasonWorld { shared, seats, policy: None })
    }

    /// (state width, candidates per choice, candidate width); the last candidate row lets the login pass.
    fn dims(&self) -> (usize, usize, usize) {
        if self.shared.queue == ERRANDS { return (errands::STATE_DIM, errands::MAX_CANDIDATES, errands::CAND_DIM) }
        (LEARNER_STATE, self.shared.catalogue.objects.len() + 1, LEARNER_CAND)
    }

    fn feature_names(&self) -> (Vec<&'static str>, Vec<&'static str>) {
        if self.shared.queue == ERRANDS {
            return (errands::STATE_NAMES.to_vec(), errands::CAND_NAMES.iter().chain(&errands::KIND_NAMES).copied().collect());
        }
        (STATE_NAMES.iter().chain(&ACCOUNT_NAMES).copied().collect(), CAND_NAMES.iter().chain(&["advised"]).copied().collect())
    }

    /// Per world: (deciding account, the decision kind, ticket).
    fn decisions(&self) -> Vec<(usize, usize, u64)> {
        self.seats.iter().map(|seat| (seat.universe.world.account_of(seat.deciding.0), self.shared.queue, seat.deciding.1)).collect()
    }

    /// The deciding planet in every world: (state f32 [N,S], candidates f32 [N,K,C], legal u8 [N,K]).
    fn observe<'py>(&self, py: Python<'py>) -> (Bound<'py, PyBytes>, Bound<'py, PyBytes>, Bound<'py, PyBytes>) {
        if self.shared.queue == ERRANDS {
            let errands: Vec<_> = self.seats.iter().map(|seat| seat.errand.as_ref().expect("an errand to decide")).collect();
            let state: Vec<f32> = errands.iter().flat_map(|e| e.1.iter().copied()).collect();
            let cands: Vec<f32> = errands.iter().flat_map(|e| e.2.iter().copied()).collect();
            let legal: Vec<u8> = errands.iter().flat_map(|e| e.3.iter().copied()).collect();
            return (bytes(py, &state), bytes(py, &cands), bytes(py, &legal));
        }
        let (n, k) = (self.seats.len(), self.shared.catalogue.objects.len() + 1);
        let mut state = vec![0f32; n * LEARNER_STATE];
        let mut cands = vec![0f32; n * k * LEARNER_CAND];
        let mut legal = vec![0u8; n * k];
        py.detach(|| {
            state.par_chunks_mut(LEARNER_STATE).zip(cands.par_chunks_mut(k * LEARNER_CAND)).zip(legal.par_chunks_mut(k)).zip(&self.seats)
                .for_each(|(((s, c), l), seat)| seat.universe.observe(seat.deciding.0, seat.advice, s, c, l));
        });
        (bytes(py, &state), bytes(py, &cands), bytes(py, &legal))
    }

    /// One answer per world. Returns the transitions closed since the last call.
    fn step(&mut self, py: Python<'_>, actions: Vec<i64>) -> PyResult<Vec<Closed>> {
        if actions.len() != self.seats.len() {
            return Err(PyValueError::new_err("one action per world"));
        }
        let shared = Arc::clone(&self.shared);
        let seats = &mut self.seats;
        py.detach(|| seats.par_iter_mut().zip(actions).for_each(|(seat, action)| seat.answer(&shared, action)));
        Ok(self.seats.iter_mut().flat_map(|seat| std::mem::take(&mut seat.closed)).collect())
    }

    /// The weights `collect` plays, as `ogrl.export_policy` writes them.
    fn set_policy(&mut self, weights: &str) -> PyResult<()> {
        let v: serde_json::Value = serde_json::from_str(weights).map_err(|e| PyValueError::new_err(e.to_string()))?;
        self.policy = Some(Policy::parse(&v).map_err(PyValueError::new_err)?);
        Ok(())
    }

    /// Plays `rows` decisions per world on every core, deciding in Rust: a model this small costs less here than one trip
    /// to a GPU (measured: 25k decisions a second on 12 cores, 10.7k through a GPU batch). Returns the
    /// finished rows: (state, candidates, legal, action, log-probability, advantage, return), raw arrays.
    #[allow(clippy::type_complexity)]
    fn collect<'py>(&mut self, py: Python<'py>, rows: usize, gamma: f32, lam: f32, scale: f32) -> PyResult<(Bound<'py, PyBytes>, Bound<'py, PyBytes>, Bound<'py, PyBytes>, Bound<'py, PyBytes>, Bound<'py, PyBytes>, Bound<'py, PyBytes>, Bound<'py, PyBytes>)> {
        let policy = self.policy.as_ref().ok_or_else(|| PyValueError::new_err("set_policy first"))?;
        let shared = Arc::clone(&self.shared);
        let seats = &mut self.seats;
        let parts: Vec<Rollout> = py.detach(|| seats.par_iter_mut().map(|seat| {
            seat.play_rows(&shared, policy, rows);
            let mut out = Rollout::default();
            seat.hand_over(gamma, lam, scale, &mut out);
            out
        }).collect());
        let join = |pick: fn(&Rollout) -> &[f32]| parts.iter().flat_map(|p| pick(p).iter().copied()).collect::<Vec<f32>>();
        let (state, cands, logp, adv, ret) = (join(|p| &p.state), join(|p| &p.cands), join(|p| &p.logp), join(|p| &p.adv), join(|p| &p.ret));
        let legal: Vec<u8> = parts.iter().flat_map(|p| p.legal.iter().copied()).collect();
        let action: Vec<i64> = parts.iter().flat_map(|p| p.action.iter().copied()).collect();
        Ok((bytes(py, &state), bytes(py, &cands), bytes(py, &legal), bytes(py, &action), bytes(py, &logp), bytes(py, &adv), bytes(py, &ret)))
    }

    /// The persona's own build for every deciding account (the last row when it would pass): the teacher.
    fn baseline(&self) -> Vec<i64> {
        let pass = if self.shared.queue == ERRANDS { 0 } else { self.shared.catalogue.objects.len() as i64 };
        self.seats.iter().map(|seat| seat.advice.map_or(pass, |k| k as i64)).collect()
    }

    /// Accounts whose season finished since the last call, `accounts` rows per season: (planets, score, the capital's
    /// effective rate, builds, invalid actions, 0).
    fn take_finished(&mut self) -> Vec<Finished> {
        self.seats.iter_mut().flat_map(|seat| std::mem::take(&mut seat.finished)).collect()
    }
}

#[pymodule]
fn ogsim(m: &Bound<'_, PyModule>) -> PyResult<()> {
    m.add_class::<VecEnv>()?;
    m.add_class::<VecWorld>()?;
    m.add_class::<SeasonWorld>()
}
