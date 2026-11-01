//! The season's universe (W24): the world `examples/season.rs` measures and the binding trains in are one and the same,
//! so a policy learns in exactly the world `hds.py` judges. Personas, quitters, alliances and the arms named on the
//! season's command line are set up here; the caller only picks who decides and writes what it measures.

use std::sync::Arc;

use crate::fleet::Coord;
use crate::players::{self, FleetSaver, Ledger, Miner, Player, Raider};
use crate::score;
use crate::world::{Colonisation, Rules, World};
use crate::{next_random, Catalogue, CAND_DIM, HashMap, STATE_DIM};

pub const DAY: f64 = 86_400.0;
/// Personas in role order, with the share of the accounts each gets.
pub const PERSONAS: [(&str, f64); 3] = [("miner", 0.4), ("raider", 0.3), ("saver", 0.3)];
pub const RAIDER: usize = 1;
pub const SAVER: usize = 2;
/// An account that stops playing draws its quit day from an exponential with this mean, in days.
const QUIT_MEAN_DAYS: f64 = 15.0;
/// Row 15: the alliance join day is drawn five days after the third (exponential). 65% and the mean are assumptions -
/// the bands say only that 65% of real active players are in an alliance (p10 24%, p90 77%) - so they are stated here.
const ALLIANCE_SHARE: u64 = 65;
const ALLIANCE_MEAN_DAYS: f64 = 5.0;
/// Row 92: how much a place in an alliance falls with the highscore rank percentile p (inactives ranked too): an account
/// with its own draw u is a member while u < 1 - slope * p, about 0.96 at the top, 0.76 mid-table, 0.44 below the middle.
const ALLIANCE_STANDING_SLOPE: f64 = 0.8;
const RECRUIT_DAYS: u32 = 7;
/// What the learner reads of its account beside the planet: where it stands, which `hds.py` judges and a planet cannot say.
pub const ACCOUNT_NAMES: [&str; 8] = ["age_years", "is_research", "eco_share", "res_share", "mil_share", "ln_score", "rank", "planets"];
pub const LEARNER_STATE: usize = STATE_DIM + ACCOUNT_NAMES.len();
/// The candidate columns plus one: 1 on the row the persona would take. It knows what no column says (the research that
/// opens a planet slot, a prerequisite of the ship it wants), so the model starts at its play and learns when to leave it.
pub const LEARNER_CAND: usize = CAND_DIM + 1;

struct Layer { weight: Vec<Vec<f32>>, bias: Vec<f32> }

impl Layer {
    fn load(v: &serde_json::Value, name: &str) -> Result<Self, String> {
        let weight = v[format!("{name}.weight")].as_array().ok_or(format!("{name} missing"))?.iter().map(floats).collect();
        Ok(Layer { weight, bias: floats(&v[format!("{name}.bias")]) })
    }

    fn apply(&self, input: &[f32]) -> Vec<f32> {
        self.weight.iter().zip(&self.bias).map(|(row, b)| (row.iter().zip(input).map(|(w, x)| w * x).sum::<f32>() + b).max(0.0)).collect()
    }

    /// A network's last layer has no ReLU after it.
    fn output(&self, input: &[f32]) -> f32 {
        self.weight[0].iter().zip(input).map(|(w, x)| w * x).sum::<f32>() + self.bias[0]
    }
}

fn floats(v: &serde_json::Value) -> Vec<f32> {
    v.as_array().map_or(Vec::new(), |a| a.iter().map(|x| x.as_f64().unwrap_or(0.0) as f32).collect())
}

fn weights(path: &str) -> Result<serde_json::Value, String> {
    let text = std::fs::read_to_string(path).map_err(|e| format!("{path}: {e}"))?;
    serde_json::from_str(&text).map_err(|e| format!("{path}: {e}"))
}

/// `ogrl.discriminator`'s detector: is this account's day real? It reads `hds.py`'s eight measures standardised inside the
/// account's cell (rank band by universe age) of the real sample, and answers a logit.
pub struct Detector { cells: HashMap<String, (Vec<f32>, Vec<f32>)>, net: Vec<Layer> }

impl Detector {
    pub fn load(path: &str) -> Result<Self, String> {
        let v = weights(path)?;
        let cells = v["cells"].as_object().ok_or("no cells")?.iter()
            .map(|(name, c)| (name.clone(), (floats(&c["centre"]), floats(&c["spread"])))).collect();
        Ok(Detector { cells, net: vec![Layer::load(&v, "net.0")?, Layer::load(&v, "net.2")?, Layer::load(&v, "net.4")?] })
    }

    /// The logit of `measures` (`hds.py`'s order, growth as ln(1 + growth)); `None` where no real universe of that age exists.
    pub fn logit(&self, measures: &[f32; 8], rank: f64, day: f64) -> Option<f32> {
        let band = if rank < 0.10 { "top10" } else if rank < 0.50 { "mid" } else { "bottom" };
        let age = match day {
            d if d < 15.0 => "0-14",
            d if d < 64.0 => return None,
            d if d < 129.0 => "64-128",
            d if d < 257.0 => "128-256",
            d if d < 513.0 => "256-512",
            _ => "512+",
        };
        let (centre, spread) = self.cells.get(&format!("{band}|{age}"))?;
        let mut x: Vec<f32> = measures.iter().zip(centre).zip(spread).map(|((m, c), s)| ((m - c) / s).clamp(-8.0, 8.0)).collect();
        for layer in &self.net[..2] { x = layer.apply(&x) }
        Some(self.net[2].output(&x))
    }
}

/// `ogrl.model.CandidateScorer` played in Rust, from `ogrl.export_policy`'s weights: a season writes its own lines, so
/// `hds.py` judges a model exactly as it judges the personas.
pub struct Policy { norm: [Vec<f32>; 4], state_net: Vec<Layer>, cand_net: Layer, joint: Vec<Layer>, value: Vec<Layer> }

impl Policy {
    pub fn load(path: &str) -> Result<Self, String> {
        Self::parse(&weights(path)?)
    }

    pub fn parse(v: &serde_json::Value) -> Result<Self, String> {
        Ok(Policy {
            norm: [floats(&v["state_mean"]), floats(&v["state_std"]), floats(&v["cand_mean"]), floats(&v["cand_std"])],
            state_net: vec![Layer::load(v, "state_net.0")?, Layer::load(v, "state_net.2")?],
            cand_net: Layer::load(v, "cand_net.0")?,
            joint: vec![Layer::load(v, "joint.0")?, Layer::load(v, "joint.2")?, Layer::load(v, "joint.4")?],
            value: vec![Layer::load(v, "value.0")?, Layer::load(v, "value.2")?],
        })
    }

    /// The state's hidden layer and the score of every legal row; `cands` is [K, C] flat.
    fn scores(&self, state: &[f32], cands: &[f32], legal: &[u8]) -> (Vec<f32>, Vec<(usize, f32)>) {
        let scaled = |x: &[f32], mean: &[f32], std: &[f32]| x.iter().zip(mean).zip(std).map(|((v, m), s)| (v - m) / s).collect::<Vec<f32>>();
        let mut s = scaled(state, &self.norm[0], &self.norm[1]);
        for layer in &self.state_net { s = layer.apply(&s) }
        let rows = (0..legal.len()).filter(|&row| legal[row] > 0).map(|row| {
            let c = self.cand_net.apply(&scaled(&cands[row * LEARNER_CAND..(row + 1) * LEARNER_CAND], &self.norm[2], &self.norm[3]));
            let mut x: Vec<f32> = s.iter().chain(&c).copied().collect();
            for layer in &self.joint[..2] { x = layer.apply(&x) }
            (row, self.joint[2].output(&x))
        }).collect();
        (s, rows)
    }

    /// The legal row with the highest score.
    pub fn best(&self, state: &[f32], cands: &[f32], legal: &[u8]) -> usize {
        self.scores(state, cands, legal).1.into_iter().max_by(|x, y| x.1.total_cmp(&y.1)).map_or(legal.len() - 1, |best| best.0)
    }

    /// A row drawn from the softmax over the legal rows, with its log-probability and the state's value.
    pub fn sample(&self, state: &[f32], cands: &[f32], legal: &[u8], rng: &mut u64) -> (usize, f32, f32) {
        let (s, rows) = self.scores(state, cands, legal);
        let top = rows.iter().map(|r| r.1).fold(f32::NEG_INFINITY, f32::max);
        let weights: Vec<f64> = rows.iter().map(|r| ((r.1 - top) as f64).exp()).collect();
        let total: f64 = weights.iter().sum();
        let mut draw = (next_random(rng) >> 11) as f64 / (1u64 << 53) as f64 * total;
        let mut at = rows.len() - 1;
        for (i, w) in weights.iter().enumerate() {
            if draw < *w { at = i; break }
            draw -= w;
        }
        (rows[at].0, (weights[at] / total).ln() as f32, self.value[1].output(&self.value[0].apply(&s)))
    }
}

pub struct Universe {
    pub cat: Arc<Catalogue>,
    pub world: World,
    pub roles: Vec<usize>,
    pub players: Vec<Player>,
    pub ledgers: Vec<Ledger>,
    /// Per account, the second it joined an alliance (row 15), or `None` if it never did.
    pub alliance_at: Vec<Option<f64>>,
    pub quit: Vec<Option<f64>>,
    pub gone: Vec<bool>,
    standing: Option<Vec<f64>>,
    /// Row 82: an active account that has held its world's top tenth for `RECRUIT_DAYS` day ends joins an alliance.
    recruit: bool,
    top_days: Vec<u32>,
    /// Per account, its place in the last highscore as a share of the world, inactives ranked too, as `hds.py` reads it.
    pub rank: Vec<f64>,
    moon_shots: bool,
    moon_feed: bool,
}

impl Universe {
    /// `econ` is the economy catalogue; `data` holds the units, fleet and combat exports and the login gaps. `arms` are
    /// the season's switches, by the names its command line uses.
    /// The economy catalogue at `econ` with the accounts' own login gaps from `data` (loaded once, shared by every season).
    pub fn catalogue(data: &str, econ: &str, arms: &[String]) -> Result<Catalogue, String> {
        let mut cat = Catalogue::load(econ)?;
        // The accounts' own login gaps where the corpus has them: the host rule reads this table, so a season must play real ones.
        cat.login_gaps = std::fs::read_to_string(format!("{data}/login-gaps.json")).ok()
            .and_then(|text| serde_json::from_str::<Vec<f64>>(&text).ok())
            .filter(|gaps| !gaps.is_empty())
            .unwrap_or_else(|| vec![600.0, 1800.0, 3600.0, 7200.0, 21600.0]);
        // Row 23's ceiling arm: no gaps at all is the economy's own "always there" path.
        if arms.iter().any(|arm| arm == "always-online") { cat.login_gaps.clear() }
        Ok(cat)
    }

    pub fn new(cat: Arc<Catalogue>, colonisation: Option<Arc<Colonisation>>, data: &str, days: usize, accounts: usize, seed: u64, arms: &[String]) -> Self {
        let on = |name: &str| arms.iter().any(|arm| arm == name);
        let rules = Arc::new(Rules::load(&cat,
            &format!("{data}/units-parity/units-u1.json"),
            &format!("{data}/fleet-settings-u1.json"),
            &format!("{data}/combat-u1.json")).expect("the units, fleet and combat exports"));

        // One shipyard table per home planet: a colony site has none, so a season starts its accounts on homes.
        let configs: Vec<usize> = (0..accounts).map(|a| a % rules.units.planets.len()).collect();
        let mut world = World::new(&cat, &configs, days as f64 * 24.0, 0.0, seed);
        world.colonisation = colonisation;
        // Row 66: how many galaxies each account colonises in, 1 to 7 alike - an assumption, as the alliance draw is.
        if on("reach") {
            let mut draw = seed ^ 0x5eed_6a1a;
            world.reach = (0..world.games.len()).map(|_| 1 + (next_random(&mut draw) % 7) as u32).collect();
        }
        // A fully built account still logs in, to raid or to save its fleet.
        world.enable_idle_logins(&cat);
        // Three galaxies, a system each, wide apart: a raider has neighbours to fly at and a saver has somewhere to be away.
        let systems = rules.settings.number_of_systems as i64;
        let coords: Vec<Coord> = (0..accounts as i64).map(|a| Coord { galaxy: 1 + a % 3, system: 1 + (a * 167) % systems, position: 4 + a % 9 }).collect();
        world.arm(vec![Arc::clone(&rules); accounts], coords);

        let mut rng = seed ^ 0x5eed_1234;
        let mut roles: Vec<usize> = (0..PERSONAS.len())
            .flat_map(|r| std::iter::repeat(r).take((accounts as f64 * PERSONAS[r].1).round() as usize))
            .collect();
        while roles.len() < accounts { roles.push(0) }
        roles.truncate(accounts);
        shuffle(&mut roles, &mut rng);
        let mut order: Vec<usize> = (0..accounts).collect();
        shuffle(&mut order, &mut rng);
        // Row 74: an account flies with its own character class's rules, from the host's export of that class.
        if on("classes") {
            let class_rules = |class: u32| Arc::new(Rules::load(&cat,
                &format!("{data}/units-parity/units-u1.json"),
                &format!("{data}/fleet-settings-u1-class{class}.json"),
                &format!("{data}/combat-u1-class{class}.json")).expect("a class's combat export"));
            let by_class: Vec<(u32, Arc<Rules>)> = vec![(2, class_rules(2)), (3, class_rules(3))];
            for a in 0..accounts {
                let class = cat.planets[configs[a]].character_class;
                if let Some((_, r)) = by_class.iter().find(|(c, _)| Some(*c) == class) {
                    world.rules[a] = Arc::clone(r);
                }
            }
        }
        // Half the accounts stop playing, each at its own quit day: a quitter's last login is the day it leaves.
        let mut quit: Vec<Option<f64>> = vec![None; accounts];
        for &a in &order[..accounts / 2] {
            quit[a] = Some(-unit(&mut rng).ln() * QUIT_MEAN_DAYS * DAY);
        }
        // Row 15: an account still playing at day 3 joins an alliance, at a drawn day; a quitter never joins.
        let mut alliance_at: Vec<Option<f64>> = vec![None; accounts];
        // Row 92 arm: membership follows standing, decided at each day's highscore instead of drawn up front.
        let standing = on("standing").then(|| (0..accounts).map(|_| unit(&mut rng)).collect::<Vec<f64>>());
        for a in (0..accounts).filter(|_| standing.is_none()) {
            let quits = quit[a].unwrap_or(f64::INFINITY);
            if quits <= 3.0 * DAY || next_random(&mut rng) % 100 >= ALLIANCE_SHARE { continue }
            let join = 3.0 * DAY + -unit(&mut rng).ln() * ALLIANCE_MEAN_DAYS * DAY;
            if join < quits { alliance_at[a] = Some(join) }
        }

        let mut players: Vec<Player> = (0..accounts).map(|a| {
            let s = seed.wrapping_mul(100).wrapping_add(a as u64);
            match roles[a] {
                RAIDER => Player::Raider(Raider::new(s)),
                SAVER => Player::Saver(FleetSaver::new(s)),
                _ => Player::Miner(Miner::new(s)),
            }
        }).collect();
        let stack = on("stack") || on("yardstack") || on("moonstack");
        for player in &mut players {
            let m = player.miner();
            m.consolidate = on("consolidate") || stack;
            m.reserve = on("reserve") || stack;
            m.yard = on("yard") || on("yardstack") || on("moonstack");
            m.expeditions = on("expedition");
            m.colonies_first = on("colonies");
            m.ships = on("ships");
            m.lab = on("lab");
            m.cap = on("cap");
            m.feed = on("feed");
            m.factory = on("factory");
            m.balance = on("balance");
            m.lab_first = on("labfirst");
            m.satellites = on("satellites");
            m.doubler = on("doubler");
        }
        Universe {
            cat, world, roles, players, ledgers: (0..accounts).map(|_| Ledger::default()).collect(), alliance_at,
            quit, gone: vec![false; accounts], standing, recruit: on("recruit"), top_days: vec![0; accounts], rank: vec![0.5; accounts],
            moon_shots: on("moonstack"), moon_feed: on("moonfeed"),
        }
    }

    pub fn accounts(&self) -> usize { self.roles.len() }

    pub fn next_decision(&mut self) -> Option<usize> {
        self.world.next_decision(&self.cat)
    }

    /// Whether slot a's account still plays: an account is retired at its quit day, and its slots then only wait (`play`).
    pub fn plays(&mut self, a: usize) -> bool {
        let owner = self.world.account_of(a);
        if let Some(q) = self.quit[owner].filter(|&q| !self.gone[owner] && self.world.games[a].t >= q) {
            retire(&mut self.world, owner, q);
            self.gone[owner] = true;
        }
        !self.gone[owner]
    }

    /// Slot a's login as its persona plays it. With `pick` the build is the caller's: it is committed first, so the
    /// persona playing the rest of the login (yard, colonies, consolidation) keeps its price, and the persona's own is dropped.
    pub fn play(&mut self, a: usize, pick: Option<Option<usize>>) {
        let owner = self.world.account_of(a);
        let t = self.world.games[a].t;
        if self.gone[owner] { return self.world.wait(&self.cat, a) }
        if let Some(Some(k)) = pick { self.world.commit(&self.cat, a, k) }
        let persona = self.players[owner].act(&mut self.world, &self.cat, a, &mut self.ledgers[owner]);
        // Row 64: alliance members shoot moons for each other at their logins (membership is the season's data).
        if self.moon_shots && a == owner && self.alliance_at[owner].is_some_and(|join| t >= join) {
            let (alliance_at, gone) = (&self.alliance_at, &self.gone);
            players::moon_shot(&mut self.world, &self.cat, a, |b| alliance_at[b].is_some_and(|join| t >= join) && !gone[b], &mut self.ledgers[owner]);
            players::ready_for_moon(&mut self.world, &self.cat, a, self.moon_feed);
        }
        match (pick, persona) {
            (Some(Some(_)), _) => {}
            (None, Some(k)) => self.world.commit(&self.cat, a, k),
            _ => self.world.wait(&self.cat, a),
        }
    }

    /// What the learner reads at slot a's decision, with the persona's own `advice` (`teacher`): state [LEARNER_STATE],
    /// candidates [K, LEARNER_CAND] and the legal rows; the last row, always legal, passes (the persona waits at half its
    /// decisions, field reserve and saving for a build).
    pub fn observe(&self, a: usize, advice: Option<usize>, state: &mut [f32], cands: &mut [f32], legal: &mut [u8]) {
        let (world, cat, owner) = (&self.world, &self.cat, self.world.account_of(a));
        let game = &world.games[a];
        cat.state_features(&game.planet, &mut state[..STATE_DIM]);
        let held = score::of(world, owner);
        let total = (held.total as f32).max(1.0);
        state[STATE_DIM..].copy_from_slice(&[
            (game.t / (365.0 * DAY)) as f32, game.queue as f32, held.economy as f32 / total, held.research as f32 / total,
            held.military as f32 / total, total.ln(), self.rank[owner] as f32, world.planets_of(owner).len() as f32 / 16.0,
        ]);
        let objects = cat.objects.len();
        for k in 0..objects {
            cat.candidate_features(&game.planet, k, &mut cands[k * LEARNER_CAND..k * LEARNER_CAND + CAND_DIM]);
            cands[k * LEARNER_CAND + CAND_DIM] = 0.0;
            legal[k] = u8::from(world.legal(cat, a, k));
        }
        cands[objects * LEARNER_CAND..].fill(0.0);
        cands[advice.unwrap_or(objects) * LEARNER_CAND + CAND_DIM] = 1.0;
        legal[objects] = 1;
    }

    /// The model's build at slot a: an object, or `None` to pass. With `draw` it samples as it does in training.
    pub fn choose(&mut self, policy: &Policy, a: usize, draw: Option<&mut u64>) -> Option<usize> {
        let k = self.cat.objects.len() + 1;
        let (mut state, mut cands, mut legal) = (vec![0.0; LEARNER_STATE], vec![0.0; k * LEARNER_CAND], vec![0; k]);
        let advice = self.teacher(a);
        self.observe(a, advice, &mut state, &mut cands, &mut legal);
        let row = match draw {
            Some(rng) => policy.sample(&state, &cands, &legal, rng).0,
            None => policy.best(&state, &cands, &legal),
        };
        Some(row).filter(|&row| row < k - 1)
    }

    /// The persona's own build at slot a, asked as the model is asked: the learner's teacher.
    pub fn teacher(&mut self, a: usize) -> Option<usize> {
        let owner = self.world.account_of(a);
        let (world, cat) = (&self.world, &self.cat);
        self.players[owner].miner().pick(world, cat, a).filter(|&k| world.legal(cat, a, k))
    }

    /// `hds.py`'s eight measures of an account at `midnight`, growth as ln(1 + growth).
    pub fn measures(&self, owner: usize, midnight: f64) -> [f32; 8] {
        let held = score::of(&self.world, owner);
        let total = (held.total as f32).max(1.0);
        let planets = self.world.planets_of(owner);
        let galaxies: std::collections::BTreeSet<i64> = planets.iter().map(|&s| self.world.coords[s].galaxy).collect();
        let speed_days = (midnight / DAY) as f32 * self.cat.speed as f32;
        [
            (1.0 + held.total.max(0) as f32 / speed_days).ln(), held.economy as f32 / total, held.research as f32 / total, held.military as f32 / total,
            planets.len() as f32, planets.iter().filter(|&&s| self.world.moons[s]).count() as f32, galaxies.len() as f32,
            f32::from(self.alliance_at[owner].is_some_and(|join| midnight >= join)),
        ]
    }

    /// The day's highscore at `midnight`: the rank-1 score an expedition's find reads, and alliance membership by standing.
    pub fn day_end(&mut self, midnight: f64) {
        let n = self.accounts();
        let totals: Vec<i64> = (0..n).map(|a| score::of(&self.world, a).total).collect();
        // N6b: an expedition's find bracket reads the universe's rank-1 score, which the host's highscore refreshes daily.
        self.world.rank1_points = totals.iter().copied().max().unwrap_or(0);
        let mut ranked: Vec<usize> = (0..n).collect();
        ranked.sort_by_key(|&a| std::cmp::Reverse(totals[a]));
        for (place, &a) in ranked.iter().enumerate() { self.rank[a] = (place + 1) as f64 / n as f64 }
        if let Some(draws) = &self.standing {
            for (rank, &a) in ranked.iter().enumerate() {
                let p = rank as f64 / n as f64;
                // Membership follows today's standing both ways: an alliance drops a member that falls away.
                let playing = self.world.games[a].horizon > midnight && midnight >= 3.0 * DAY;
                let placed = playing && draws[a] < 1.0 - ALLIANCE_STANDING_SLOPE * p;
                if placed != self.alliance_at[a].is_some() { self.alliance_at[a] = placed.then_some(midnight) }
            }
        }
        if self.recruit {
            let top = n.div_ceil(10);
            for (rank, &a) in ranked.iter().enumerate() {
                self.top_days[a] = if rank < top { self.top_days[a] + 1 } else { 0 };
                if self.top_days[a] >= RECRUIT_DAYS && self.alliance_at[a].is_none() && !self.world.inactive(&self.cat, a, midnight) {
                    self.alliance_at[a] = Some(midnight);
                }
            }
        }
    }
}

/// The account's owner stopped coming at `quit`: the login record ends there, which is what `World::inactive` reads, and
/// its clock stops, so nothing of it is decided afterwards.
fn retire(w: &mut World, a: usize, quit: f64) {
    for slot in w.planets_of(a) {
        let end = quit.max(w.games[slot].t);
        let game = &mut w.games[slot];
        game.horizon = end;
        game.login_times.retain(|&t| t <= end);
        if game.login_times.is_empty() { game.login_times.push(end) }
        // A login no clock reaches: the record has no entry after the quit day, and `Catalogue::login` draws none.
        game.login_times.push(f64::INFINITY);
    }
}

fn unit(rng: &mut u64) -> f64 {
    ((next_random(rng) >> 11) as f64 + 0.5) / (1u64 << 53) as f64
}

fn shuffle<T>(values: &mut [T], rng: &mut u64) {
    for i in (1..values.len()).rev() {
        values.swap(i, (next_random(rng) % (i as u64 + 1)) as usize);
    }
}
