//! The season's universe (W24): the world `examples/season.rs` measures and the binding trains in are one and the same,
//! so a policy learns in exactly the world `hds.py` judges. Personas, quitters, alliances and the arms named on the
//! season's command line are set up here; the caller only picks who decides and writes what it measures.

use std::sync::Arc;

use crate::fleet::Coord;
use crate::errands::{self, Errand};
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

#[derive(Clone)]
struct Layer { weight: Vec<f32>, bias: Vec<f32>, inputs: usize }

impl Layer {
    fn load(v: &serde_json::Value, name: &str) -> Result<Self, String> {
        let rows: Vec<Vec<f32>> = v[format!("{name}.weight")].as_array().ok_or(format!("{name} missing"))?.iter().map(floats).collect();
        Ok(Layer { inputs: rows.first().map_or(0, Vec::len), weight: rows.concat(), bias: floats(&v[format!("{name}.bias")]) })
    }

    /// Output o's weights on inputs `from..from + len` (row-major, one flat buffer so the dot products vectorise).
    #[inline(always)]
    fn row(&self, o: usize, from: usize, len: usize) -> &[f32] {
        &self.weight[o * self.inputs + from..o * self.inputs + from + len]
    }

    #[inline(always)]
    fn apply(&self, input: &[f32]) -> Vec<f32> {
        (0..self.bias.len()).map(|o| (dot(self.row(o, 0, self.inputs), input) + self.bias[o]).max(0.0)).collect()
    }

    /// `apply` into a buffer the caller reuses.
    #[inline(always)]
    fn apply_into(&self, input: &[f32], out: &mut Vec<f32>) {
        out.clear();
        for o in 0..self.bias.len() { out.push((dot(self.row(o, 0, self.inputs), input) + self.bias[o]).max(0.0)) }
    }

    /// A network's last layer has no ReLU after it.
    #[inline(always)]
    fn output(&self, input: &[f32]) -> f32 {
        dot(self.row(0, 0, self.inputs), input) + self.bias[0]
    }
}

/// A dot product in eight lanes: a plain `zip().sum()` is one serial chain the compiler may not reorder, and model
/// inference was ~90% of a season's time (4 Nov: 300 s against 28 s for the scripted players over 30 days).
#[inline(always)]
fn dot(a: &[f32], b: &[f32]) -> f32 {
    let mut lanes = [0.0f32; 8];
    let (whole, rest) = (a.len() / 8 * 8, a.len().min(b.len()));
    for (x, y) in a[..whole].chunks_exact(8).zip(b[..whole].chunks_exact(8)) {
        for i in 0..8 { lanes[i] += x[i] * y[i] }
    }
    lanes.iter().sum::<f32>() + a[whole..rest].iter().zip(&b[whole..rest]).map(|(x, y)| x * y).sum::<f32>()
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

/// Who flies an account's fleet errands at its logins.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum Pilot {
    /// Its persona, by script.
    Persona,
    /// The learner: `errand-policy=` over the first `fleet-model=N` accounts, the only seats `es-errand=` perturbs.
    Learner,
    /// Entry K of `errand-pool=A,B,teacher` (`teacher`: the scripted mix through the model's door), never perturbed.
    Pool(usize),
}

/// Per account, its `Pilot`. `errand-split=LO-HI:K,...` gives accounts LO..=HI to pool entry K; the first `fleet-model`
/// accounts stay the learner's, and an account no range names keeps its persona. Without the two pool arms this is
/// today's `fleet-model=N` exactly.
pub fn pilots(arms: &[String], accounts: usize) -> Vec<Pilot> {
    let arm = |name: &str| arms.iter().find_map(|arm| arm.strip_prefix(name));
    let fleet_model = arm("fleet-model=").map_or(0, |n| n.parse::<usize>().expect("fleet-model=N"));
    let mut out: Vec<Pilot> = (0..accounts).map(|a| if a < fleet_model { Pilot::Learner } else { Pilot::Persona }).collect();
    let pool = arm("errand-pool=").map_or(0, |list| list.split(',').count());
    for range in arm("errand-split=").into_iter().flat_map(|spec| spec.split(',')) {
        let (span, k) = range.split_once(':').expect("errand-split=LO-HI:K,...");
        let (lo, hi) = span.split_once('-').unwrap_or((span, span));
        let (lo, hi, k) = (lo.parse::<usize>().expect("errand-split LO"), hi.parse::<usize>().expect("errand-split HI"), k.parse::<usize>().expect("errand-split K"));
        assert!(k < pool, "errand-split names pool entry {k}, errand-pool= has {pool}");
        assert!(lo <= hi && hi < accounts, "errand-split range {lo}-{hi} outside the {accounts} accounts");
        for p in out[lo..=hi].iter_mut().filter(|p| **p == Pilot::Persona) { *p = Pilot::Pool(k) }
    }
    out
}

/// `errand-pool=A,B,teacher`'s entries in order, each errand model loaded (`wide` for the wide menu's version); `None`
/// is `teacher`.
pub fn errand_pool(arms: &[String]) -> Result<Vec<Option<Policy>>, String> {
    let wide = arms.iter().any(|arm| arm == "wide");
    arms.iter().find_map(|arm| arm.strip_prefix("errand-pool=")).map_or(Ok(Vec::new()), |list| list.split(',')
        .map(|path| if path == "teacher" { Ok(None) } else { Policy::load_errand(path, wide).map(Some).map_err(|e| format!("{path}: {e}")) })
        .collect())
}

/// `ogrl.model.CandidateScorer` played in Rust, from `ogrl.export_policy`'s weights: a season writes its own lines, so
/// `hds.py` judges a model exactly as it judges the personas.
#[derive(Clone)]
pub struct Policy { norm: [Vec<f32>; 4], state_net: Vec<Layer>, cand_net: Layer, joint: Vec<Layer>, value: Vec<Layer> }

impl Policy {
    pub fn load(path: &str) -> Result<Self, String> {
        Self::parse(&weights(path)?)
    }

    /// Errand models must name the current feature semantics, not just match the tensor width.
    /// With `wide`, the wide menu's (`errands::WIDE_FEATURE_VERSION`).
    pub fn load_errand(path: &str, wide: bool) -> Result<Self, String> {
        Self::parse_errand(&weights(path)?, wide)
    }

    fn parse_errand(v: &serde_json::Value, wide: bool) -> Result<Self, String> {
        let version = crate::errands::feature_version(wide);
        if v["_errand_feature_version"].as_u64() != Some(version) {
            return Err(format!("errand policy requires feature version {version}; old or unversioned weights must be retrained"));
        }
        Self::parse(v)
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

    /// The policy with `noise * scale` added to its scoring weights, walked in the trainer's parameter order (state_net,
    /// cand_net, joint; weight rows then bias). The value head is not part of the policy and stays. Evolution strategies.
    pub fn nudged(&self, noise: &[f32], scale: f32) -> Policy {
        let mut out = self.clone();
        let mut at = 0;
        let layers = out.state_net.iter_mut().chain(std::iter::once(&mut out.cand_net)).chain(out.joint.iter_mut());
        for layer in layers {
            for w in layer.weight.iter_mut().chain(layer.bias.iter_mut()) {
                *w += noise[at] * scale;
                at += 1;
            }
        }
        out
    }

    /// The scoring weights in `nudged`'s order, as one vector.
    pub fn params(&self) -> Vec<f32> {
        self.state_net.iter().chain(std::iter::once(&self.cand_net)).chain(&self.joint).flat_map(|l| l.weight.iter().chain(&l.bias).copied()).collect()
    }

    /// The policy with these scoring weights (`params`' order); the normalisation and value head stay.
    pub fn with_params(&self, theta: &[f32]) -> Policy {
        let zero = vec![0.0; theta.len()];
        let mut out = self.nudged(&zero, 0.0);
        let mut at = 0;
        let layers = out.state_net.iter_mut().chain(std::iter::once(&mut out.cand_net)).chain(out.joint.iter_mut());
        for layer in layers {
            for w in layer.weight.iter_mut().chain(layer.bias.iter_mut()) {
                *w = theta[at];
                at += 1;
            }
        }
        out
    }

    /// The weights file `load` reads, from the file this policy came from with its scoring layers replaced.
    pub fn to_json(&self, template: &serde_json::Value) -> serde_json::Value {
        let mut out = template.clone();
        let names = ["state_net.0", "state_net.2", "cand_net.0", "joint.0", "joint.2", "joint.4"];
        for (name, layer) in names.iter().zip(self.state_net.iter().chain(std::iter::once(&self.cand_net)).chain(&self.joint)) {
            out[format!("{name}.weight")] = serde_json::json!(layer.weight.chunks(layer.inputs).collect::<Vec<_>>());
            out[format!("{name}.bias")] = serde_json::json!(layer.bias);
        }
        out
    }

    /// How many weights `nudged` reads.
    pub fn scoring_weights(&self) -> usize {
        self.state_net.iter().chain(std::iter::once(&self.cand_net)).chain(&self.joint).map(|l| l.weight.len() + l.bias.len()).sum()
    }

    /// The state's hidden layer and the score of every legal row; `cands` is [K, C] flat.
    fn scores(&self, state: &[f32], cands: &[f32], legal: &[u8]) -> (Vec<f32>, Vec<(usize, f32)>) {
        // The same arithmetic in 256-bit registers where the CPU has them: `dot`'s eight lanes are one register, and
        // without fused multiply-adds every product and sum rounds as before (bit-identical scores).
        #[cfg(target_arch = "x86_64")]
        if std::is_x86_feature_detected!("avx2") {
            // SAFETY: the CPU was just found to support AVX2.
            return unsafe { self.scores_avx2(state, cands, legal) };
        }
        self.scores_in(state, cands, legal)
    }

    #[cfg(target_arch = "x86_64")]
    #[target_feature(enable = "avx2")]
    unsafe fn scores_avx2(&self, state: &[f32], cands: &[f32], legal: &[u8]) -> (Vec<f32>, Vec<(usize, f32)>) {
        self.scores_in(state, cands, legal)
    }

    #[inline(always)]
    fn scores_in(&self, state: &[f32], cands: &[f32], legal: &[u8]) -> (Vec<f32>, Vec<(usize, f32)>) {
        // Plain loops, not iterator adapters: the adapters are functions of their own the AVX2 copy would call.
        #[inline(always)]
        fn scaled_into(x: &[f32], mean: &[f32], std: &[f32], out: &mut Vec<f32>) {
            out.clear();
            for i in 0..x.len().min(mean.len()).min(std.len()) { out.push((x[i] - mean[i]) / std[i]) }
        }
        let mut s = Vec::with_capacity(state.len());
        scaled_into(state, &self.norm[0], &self.norm[1], &mut s);
        let mut next = Vec::new();
        for layer in &self.state_net {
            layer.apply_into(&s, &mut next);
            std::mem::swap(&mut s, &mut next);
        }
        // joint.0 reads [state, candidate]: its state half is the same for every row, so it is summed once per decision.
        let (first, width) = (&self.joint[0], self.norm[2].len());
        let mut shared: Vec<f32> = Vec::with_capacity(first.bias.len());
        for o in 0..first.bias.len() { shared.push(dot(first.row(o, 0, s.len()), &s) + first.bias[o]) }
        // One set of buffers for every row: the arithmetic is `apply`'s, only the allocations are shared.
        let (mut z, mut c, mut x, mut h) = (Vec::with_capacity(width), Vec::new(), Vec::with_capacity(shared.len()), Vec::new());
        let mut rows = Vec::with_capacity(legal.len());
        for row in 0..legal.len() {
            if legal[row] == 0 { continue }
            scaled_into(&cands[row * width..(row + 1) * width], &self.norm[2], &self.norm[3], &mut z);
            self.cand_net.apply_into(&z, &mut c);
            x.clear();
            for o in 0..shared.len() { x.push((shared[o] + dot(first.row(o, s.len(), c.len()), &c)).max(0.0)) }
            self.joint[1].apply_into(&x, &mut h);
            rows.push((row, self.joint[2].output(&h)));
        }
        (s, rows)
    }

    /// The value head's estimate at a state: what `ogrl.train_value` taught it, the log of the points the account ends
    /// the season with.
    pub fn value(&self, state: &[f32]) -> f32 {
        let mut s: Vec<f32> = state.iter().zip(&self.norm[0]).zip(&self.norm[1]).map(|((v, m), d)| (v - m) / d).collect();
        for layer in &self.state_net { s = layer.apply(&s) }
        self.value[1].output(&self.value[0].apply(&s))
    }

    /// The legal rows, best score first.
    pub fn ranked(&self, state: &[f32], cands: &[f32], legal: &[u8]) -> Vec<usize> {
        let mut rows = self.scores(state, cands, legal).1;
        rows.sort_by(|x, y| y.1.total_cmp(&x.1));
        rows.into_iter().map(|r| r.0).collect()
    }

    /// The legal row with the highest score.
    pub fn best(&self, state: &[f32], cands: &[f32], legal: &[u8]) -> usize {
        self.scores(state, cands, legal).1.into_iter().max_by(|x, y| x.1.total_cmp(&y.1)).map_or(legal.len() - 1, |best| best.0)
    }

    /// A row drawn from the softmax over the legal rows, scores divided by `heat`, with its log-probability and the state's value.
    pub fn sample(&self, state: &[f32], cands: &[f32], legal: &[u8], heat: f32, rng: &mut u64) -> (usize, f32, f32) {
        let (s, rows) = self.scores(state, cands, legal);
        let top = rows.iter().map(|r| r.1).fold(f32::NEG_INFINITY, f32::max);
        let weights: Vec<f64> = rows.iter().map(|r| ((r.1 - top) as f64 / heat as f64).exp()).collect();
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

/// A look-ahead decision: the pick (None waits), what the learner reads there and each row's worth.
pub struct Lookahead {
    pub pick: Option<usize>,
    pub state: Vec<f32>,
    pub cands: Vec<f32>,
    pub legal: Vec<u8>,
    pub worths: Vec<f32>,
}

/// A look-ahead errand: the offer, what the errand model reads, each row's worth (NaN where none) and the row chosen.
pub struct ErrandLookahead {
    pub offered: Vec<Errand>,
    pub state: Vec<f32>,
    pub cands: Vec<f32>,
    pub legal: Vec<u8>,
    pub worths: Vec<f32>,
    pub row: usize,
}

#[derive(Clone)]
pub struct Universe {
    pub cat: Arc<Catalogue>,
    pub world: World,
    pub roles: Vec<usize>,
    pub players: Vec<Player>,
    pub ledgers: Vec<Ledger>,
    pub quit: Vec<Option<f64>>,
    pub gone: Vec<bool>,
    standing: Option<Vec<f64>>,
    /// Row 82: an active account that has held its world's top tenth for `RECRUIT_DAYS` day ends joins an alliance.
    recruit: bool,
    top_days: Vec<u32>,
    /// Time-limited messages for the experimental alliance war scenario.
    war_requests: Vec<players::WarRequest>,
    /// Per account, its place in the last highscore as a share of the world, inactives ranked too, as `hds.py` reads it.
    pub rank: Vec<f64>,
    moon_shots: bool,
    /// `noadvice`: the persona's own pick is not shown to the model. A clone of the teacher that sees it copies the flag,
    /// and PPO then has to unlearn a near-certain copy before it can improve on it.
    advise: bool,
    moon_feed: bool,
    /// L5 arm: alliance members hold at a mate's planet that a raid is flying at.
    acs_defend: bool,
    acs_attack: bool,
    wrecks: bool,
    relocations: bool,
    /// `wide` with an errand model: these accounts' alliance errands, moon shots and expeditions are the errand
    /// model's (`errands::Menu`), so the login does not also play them by script.
    errand_kinds: Vec<bool>,
    /// Leverage probes: `jolt=D` spends one draw of the world's dice at day D's end and changes nothing else; `skip=A:D`
    /// makes account A pass on its persona's build at its first login after day D. Their gap is a decision's signal over its noise.
    jolt: Option<f64>,
    skip: Option<(usize, f64)>,
    /// The share of its planets each account wants mooned, when accounts differ in that: real mid players hold 1 to 12 moons
    /// on about 10 planets, and the top tenth a moon on almost every planet.
    moon_taste: Option<Vec<f64>>,
    /// The policy that plays the look-ahead account's own later builds inside a rollout (sampled), instead of its persona.
    /// A rollout improves on the policy that plays it, and late the persona is weaker than the best model (4 Nov).
    pub rollout_policy: Option<Arc<Policy>>,
    /// The season's end in seconds: a rollout's end is then scored as its worth plus the account's hourly production
    /// until that end, the points it would close the season with if nothing changed. Worth alone is the next hours'
    /// production, which late no build moves: the 12 h champion with best-es3 rollouts lost to best-es3 (4 Nov).
    pub value_to_end: Option<f64>,
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
        // The energy-only research ceiling also counts what the units require (the deathstar's graviton level).
        let rules = Rules::load(&cat, &format!("{data}/units-parity/units-u1.json"), &format!("{data}/fleet-settings-u1.json"), &format!("{data}/combat-u1.json"))?;
        let needs: Vec<(usize, u32)> = rules.units.units.iter().flat_map(|u| u.requires.iter()).filter_map(|&(k, l)| Some((k?, l))).collect();
        cat.set_ceilings(&needs);
        Ok(cat)
    }

    pub fn new(cat: Arc<Catalogue>, colonisation: Option<Arc<Colonisation>>, data: &str, days: usize, accounts: usize, seed: u64, arms: &[String]) -> Self {
        let on = |name: &str| arms.iter().any(|arm| arm == name);
        // `realexpo` (off by default): expedition finds take the original game's factor draw (`expedition::real_find`).
        let real_expo = |mut r: Rules| { if let Some(e) = r.expedition.as_mut() { e.real_factor = on("realexpo") } r };
        let rules = Arc::new(real_expo(Rules::load(&cat,
            &format!("{data}/units-parity/units-u1.json"),
            &format!("{data}/fleet-settings-u1.json"),
            &format!("{data}/combat-u1.json")).expect("the units, fleet and combat exports")));

        // One shipyard table per home planet: a colony site has none, so a season starts its accounts on homes.
        let homes = rules.units.planets.len();
        let mut configs: Vec<usize> = (0..accounts).map(|a| a % homes).collect();
        // The personas are drawn first (their own stream, untouched by the homes), so a class can follow the persona.
        let mut rng = seed ^ 0x5eed_1234;
        // Arm `personas=M:R:S`: the miner, raider and saver shares instead of PERSONAS' (a war world: `personas=0:1:0`).
        let shares: Vec<f64> = arms.iter().find_map(|arm| arm.strip_prefix("personas="))
            .map_or_else(|| PERSONAS.iter().map(|p| p.1).collect(), |v| v.split(':').map(|x| x.parse().expect("personas=M:R:S")).collect());
        assert_eq!(shares.len(), PERSONAS.len(), "personas=M:R:S");
        let mut roles: Vec<usize> = (0..PERSONAS.len())
            .flat_map(|r| std::iter::repeat(r).take((accounts as f64 * shares[r]).round() as usize))
            .collect();
        while roles.len() < accounts { roles.push(0) }
        roles.truncate(accounts);
        shuffle(&mut roles, &mut rng);
        let mut order: Vec<usize> = (0..accounts).collect();
        shuffle(&mut order, &mut rng);
        // `class=N`: an account plays class N, as a player picks it. It starts on the class-N donor home nearest its own home's
        // temperature, so the class's production rows, research time and fleet rules all come with it. The classes are
        // the donors' own (gate 1). `personaclass` (off by default) picks the class by persona instead, as players pick
        // the class their play profits from: a raider the host's class 2, a miner class 1, a saver (expeditions) class 3
        // (`CharacterClass` ids, the donors' `character_class`); it overrides `class=N`.
        let one_class = arms.iter().find_map(|arm| arm.strip_prefix("class=")).map(|n| n.parse::<u32>().expect("class=N"));
        let by_persona = |role: usize| match role { RAIDER => 2, SAVER => 3, _ => 1 };
        let class_of: Option<Vec<u32>> = match on("personaclass") {
            true => Some(roles.iter().map(|&r| by_persona(r)).collect()),
            false => one_class.map(|class| vec![class; accounts]),
        };
        if let Some(class_of) = class_of {
            let of = |c: usize| (cat.planets[c].character_class, cat.planets[c].temperature);
            for (config, &class) in configs.iter_mut().zip(&class_of) {
                let own = of(*config).1;
                if let Some(home) = (0..homes).filter(|&h| of(h).0 == Some(class)).min_by(|&x, &y| (of(x).1 - own).abs().total_cmp(&(of(y).1 - own).abs())) { *config = home }
            }
        }
        let mut world = World::new(&cat, &configs, days as f64 * 24.0, 0.0, seed);
        world.colonisation = colonisation;
        // Row 66: how many galaxies each account colonises in, 1 to 7 alike - an assumption, as the alliance draw is.
        world.spread = on("spread");
        world.paid_lab = on("paidlab");
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

        // Row 74: an account flies with its own character class's rules, from the host's export of that class.
        if on("classes") {
            let class_rules = |class: u32| Arc::new(real_expo(Rules::load(&cat,
                &format!("{data}/units-parity/units-u1.json"),
                &format!("{data}/fleet-settings-u1-class{class}.json"),
                &format!("{data}/combat-u1-class{class}.json")).expect("a class's combat export")));
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
        // Arm `churn=SHARE:MEAN`: SHARE of the accounts quit, at an exponential day of mean MEAN, so quitting goes on all
        // season and late quitters leave grown accounts behind as farms (REAL-SERVER-PLAYER-PROFILE.md #2, #6).
        let (share, mean) = arms.iter().find_map(|arm| arm.strip_prefix("churn=")).map_or((0.5, QUIT_MEAN_DAYS), |v| {
            let (s, m) = v.split_once(':').expect("churn=SHARE:MEAN");
            (s.parse::<f64>().expect("churn share"), m.parse::<f64>().expect("churn mean"))
        });
        let quitters = ((accounts as f64 * share).round() as usize).min(accounts);
        for &a in &order[..quitters] {
            quit[a] = Some(-unit(&mut rng).ln() * mean * DAY);
        }
        // Row 15: an account still playing at day 3 joins an alliance, at a drawn day; a quitter never joins.
        let mut alliance_at: Vec<Option<f64>> = vec![None; accounts];
        // Row 92 arm: membership follows standing, decided at each day's highscore instead of drawn up front.
        let standing = (on("standing") && !on("noalliance")).then(|| (0..accounts).map(|_| unit(&mut rng)).collect::<Vec<f64>>());
        for a in (0..accounts).filter(|_| standing.is_none()) {
            let quits = quit[a].unwrap_or(f64::INFINITY);
            if quits <= 3.0 * DAY || next_random(&mut rng) % 100 >= ALLIANCE_SHARE { continue }
            let join = 3.0 * DAY + -unit(&mut rng).ln() * ALLIANCE_MEAN_DAYS * DAY;
            if join < quits { alliance_at[a] = Some(join) }
        }
        // Arm `noalliance`: nobody joins one, so every account is every other's enemy.
        if on("noalliance") { alliance_at.iter_mut().for_each(|j| *j = None) }
        world.alliance_at = alliance_at;
        // Explicit experimental teams; absent this arm, the historical single alliance stays unchanged.
        if let Some(teams) = arms.iter().find_map(|arm| arm.strip_prefix("alliances=")) {
            let teams: usize = teams.parse().expect("alliances=N");
            assert!(teams > 0, "alliances must be positive");
            world.alliance_id = (0..accounts).map(|a| a % teams).collect();
            world.alliances_distinct = true;
        }

        let mut players: Vec<Player> = (0..accounts).map(|a| {
            let s = seed.wrapping_mul(100).wrapping_add(a as u64);
            match roles[a] {
                RAIDER => Player::Raider(Raider::new(s)),
                SAVER => Player::Saver(FleetSaver::new(s)),
                _ => Player::Miner(Miner::new(s)),
            }
        }).collect();
        // `fleet-model=N` (and `errand-split=`): these accounts' fleet errands are a model's, so their persona keeps only the
        // economy and its yard (its archetype's doctrine stays), as the errand world does.
        let flown: Vec<bool> = pilots(arms, accounts).iter().map(|p| *p != Pilot::Persona).collect();
        // The persona stays (a saver still saves, its yard and doctrine stay); only a raider's own raids stop, as the model
        // now decides them. Reset to a plain miner, these accounts lost 4% against the same personas played by script.
        for (player, _) in players.iter_mut().zip(&flown).filter(|(_, &f)| f) {
            if let Player::Raider(raider) = player { raider.hostile = false }
        }
        let stack = on("stack") || on("yardstack") || on("moonstack");
        // Late-game arm: each persona's yard fills its archetype's fleet and defence templates (`rl/scripts/doctrine_json.py`).
        let doctrine: HashMap<String, players::Doctrine> = if on("doctrine") {
            serde_json::from_str(&std::fs::read_to_string(format!("{data}/doctrine.json")).expect("the doctrine export")).expect("doctrine.json")
        } else { HashMap::default() };
        // `params=` / `params-es=` (tuning.rs): the personas' tunable numbers, per account; none = today's constants.
        let tuned = arms.iter().any(|arm| arm.starts_with("params=") || arm.starts_with("params-es="));
        let mut tunes = crate::tuning::Tuning::for_accounts(arms, accounts).expect("params");
        // `skill` (tuning.rs): one skill quantile per account; it sets the expedition slots kept busy here and, with `bands`,
        // the account's presence band below.
        let skill = on("skill").then(|| crate::tuning::skill_draw(seed, accounts));
        for (t, &q) in tunes.iter_mut().zip(skill.iter().flatten()) { t.expedition_use = crate::tuning::expedition_use(q) }
        for (a, player) in players.iter_mut().enumerate() {
            let m = player.miner();
            if tuned {
                let t = &tunes[a];
                match PERSONAS[roles[a]].0 {
                    "raider" => { m.military_share = t.raider_military_share; m.cargo_share = t.raider_cargo_share; m.fighters = t.raider_fighters.round() as i64 }
                    "miner" => m.cargo_share = t.miner_cargo_share,
                    _ => m.cargo_share = t.saver_cargo_share,
                }
            }
            m.doctrine = doctrine.get(PERSONAS[roles[a]].0).cloned().unwrap_or_default();
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
            m.lab_ahead = on("labahead");
            m.network = on("netchain");
            m.tech_tree = on("techtree");
            m.merchant = on("merchant");
            m.scrap = on("scrap");
            m.trim = on("trim");
            m.satellites = on("satellites");
            m.doubler = on("doubler");
            m.expo_full = on("expofull");
            m.expo_ship = on("exposhield");
            m.expedition_errand = on("wide") && flown[a];
            m.sat_cap = on("satcap");
            m.war_share = on("warshare");
            m.post_count = on("postcount");
            m.moon_post = on("minerpost");
            m.ds_anchor = on("anchor");
        }
        for player in players.iter_mut() {
            if let Player::Raider(raider) = player { raider.moon_raids = on("moonraid") }
        }
        world.keyed = on("keyed");
        world.event_dice = on("eventdice");
        world.war_fleet = on("warfleet");
        world.raids = on("raids");
        world.rescout = on("rescout");
        world.guess = on("guess");
        if tuned || skill.is_some() { world.tune = Arc::new(tunes) }
        world.wide = on("wide");
        world.late = on("late");
        world.fresh_spy = on("freshspy");
        world.keep_fights = on("battles") || on("levels") || on("social");
        world.relations = crate::diplomacy::Relations::new(on("diplomacy"));
        if on("diplomacy") && on("persona") {
            let personas = crate::persona::Personas::load();
            let traits = (0..accounts).map(|a| personas.draw(seed, a).1).collect();
            world.relations.persona = Some((personas, traits));
            world.relations.rng = seed.wrapping_mul(0x2545_f491_4f6c_dd1d) | 1;
        }
        world.relations.cycles = on("cycles");
        world.relations.probe = arms.iter().find_map(|arm| arm.strip_prefix("probe=")).map(String::from);
        world.relations.bc = arms.iter().find_map(|arm| arm.strip_prefix("bc=")).map(crate::diplomacy::BcTree::load);
        world.relations.minds = crate::mind::Minds::new(on("diplomacy") && on("minds"), accounts);
        if on("diplomacy") { world.relations.draw_betrayers(&roles.iter().map(|&r| PERSONAS[r].0).collect::<Vec<_>>(), seed) }
        world.fuel_keep = on("fuelkeep");
        // `bands`: each account plays the module's own activity band (`RoutineProfile::presenceBand`, drawn by archetype
        // in `AiPersonaFactory`; persona miner -> Miner, raider -> Raider, saver -> Fleeter), placed in it by its own
        // diligence, so the simulated world holds the casual and hardcore players the deployed accounts are. The shared
        // real gaps give about `awake / mean gap` logins a day; the account waits that many times its target sessions.
        // Arm `dark9`: the module's own night (SessionPlanner::CORE_DARK_MINUTES = 540): nine dark hours a day, not 6-8.
        if on("dark9") { for g in world.games.iter_mut() { g.sleep_hours = 9.0 } }
        if on("bands") && !cat.login_gaps.is_empty() {
            // [casual, regular, active, hardcore] weights and their sessions-per-day bands, as the module states them.
            const MIX: [[f64; 4]; 3] = [[10.0, 55.0, 30.0, 5.0], [5.0, 25.0, 55.0, 15.0], [5.0, 25.0, 55.0, 15.0]];
            const SESSIONS: [(f64, f64); 4] = [(1.0, 3.0), (3.0, 7.0), (7.0, 12.0), (12.0, 18.0)];
            let mean_gap = cat.login_gaps.iter().sum::<f64>() / cat.login_gaps.len() as f64;
            let mut rng = seed ^ 0x9ACE;
            let mut unit = || (crate::economy::next_random(&mut rng) >> 11) as f64 / (1u64 << 53) as f64;
            for a in 0..accounts {
                let mix = MIX[roles[a].min(2)];
                // With `skill` the account's quantile places it: its band by the mix's cumulative shares, its diligence by
                // where it sits inside that band's share (the band shares stay the module's; the strong are the active).
                let q = skill.as_ref().map(|s| s[a]);
                let mut pick = q.unwrap_or_else(&mut unit) * mix.iter().sum::<f64>();
                let band = mix.iter().position(|&w| { if pick < w { return true } pick -= w; false }).unwrap_or(3);
                let inside = match q { Some(_) => (pick / mix[band]).clamp(0.0, 1.0), None => unit() };
                let (fewest, most) = SESSIONS[band];
                let sessions = fewest + ((most - fewest) * inside).round();
                let base = (24.0 - world.games[a].sleep_hours) * 3600.0 / mean_gap;
                world.games[a].pace = base / sessions;
            }
        }
        Universe {
            cat, world, roles, players, ledgers: (0..accounts).map(|_| Ledger::default()).collect(),
            quit, gone: vec![false; accounts], standing, war_requests: Vec::new(), recruit: on("recruit"), top_days: vec![0; accounts], rank: vec![0.5; accounts],
            rollout_policy: None, value_to_end: None, advise: !on("noadvice"), moon_shots: on("moonstack"), moon_feed: on("moonfeed"), acs_defend: on("acsdefend"), acs_attack: on("acsattack"), wrecks: on("wrecks"), relocations: on("relocate"),
            errand_kinds: flown.iter().map(|&f| f && on("wide")).collect(),
            jolt: arms.iter().find_map(|arm| arm.strip_prefix("jolt=")).map(|d| d.parse::<f64>().expect("jolt=DAY") * DAY),
            skip: arms.iter().find_map(|arm| arm.strip_prefix("skip=")).map(|v| {
                let (a, d) = v.split_once(':').expect("skip=ACCOUNT:DAY");
                (a.parse().expect("skip account"), d.parse::<f64>().expect("skip day") * DAY)
            }),
            moon_taste: on("moontaste").then(|| { let mut draw = seed ^ 0x4d00_a7e5; (0..accounts).map(|_| unit(&mut draw)).collect() }),
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
        if self.moon_shots && a == owner && self.world.alliance_at[owner].is_some_and(|join| t >= join) {
            let wants: Vec<bool> = (0..self.accounts()).map(|b| self.wants_moon(b)).collect();
            let (alliance_at, alliance_id, gone) = (self.world.alliance_at.clone(), self.world.alliance_id.clone(), &self.gone);
            let mate = |b: usize| wants[b] && alliance_at[b].is_some_and(|join| t >= join) && alliance_id[b] == alliance_id[owner] && !gone[b];
            match self.errand_kinds[owner] {
                true => players::moon_shot_stock(&mut self.world, &self.cat, a, mate),
                false => { players::moon_shot(&mut self.world, &self.cat, a, mate, &mut self.ledgers[owner]); }
            }
            if wants[owner] {
                let wall = self.players[owner].miner().doctrine.defence.clone();
                players::ready_for_moon(&mut self.world, &self.cat, a, self.moon_feed, &wall);
            }
        }
        if self.acs_defend && a == owner && !self.errand_kinds[owner] && self.world.alliance_at[owner].is_some_and(|join| t >= join) {
            let gone = &self.gone;
            players::defend_mate(&mut self.world, &self.cat, a, |b| !gone[b], &mut self.ledgers[owner]);
        }
        if self.acs_attack && a == owner && self.world.alliance_at[owner].is_some_and(|join| t >= join) {
            let gone = &self.gone;
            if self.world.alliances_distinct { players::coordinate_war(&mut self.world, &self.cat, a, &mut self.war_requests, &mut self.ledgers[owner]) }
            if !self.errand_kinds[owner] { players::join_raid(&mut self.world, &self.cat, a, |b| !gone[b], &mut self.ledgers[owner]); }
        }
        if self.wrecks && a == owner {
            players::tend_wrecks(&mut self.world, &self.cat, a, &mut self.ledgers[owner]);
        }
        if self.relocations && a == owner {
            players::relocate_colony(&mut self.world, a, &mut self.ledgers[owner]);
        }
        let skipped = self.skip.is_some_and(|(b, day)| b == owner && a == owner && t >= day && pick.is_none() && persona.is_some());
        if skipped { self.skip = None; return self.world.wait(&self.cat, a) }
        match (pick, persona) {
            (Some(Some(_)), _) => {}
            (None, Some(k)) => self.world.commit(&self.cat, a, k),
            _ => self.world.wait(&self.cat, a),
        }
    }

    /// Whether account b wants another moon: the top tenth always, the others up to their own share of their planets.
    fn wants_moon(&self, b: usize) -> bool {
        let Some(taste) = &self.moon_taste else { return true };
        let slots = self.world.planets_of(b);
        let moons = slots.iter().filter(|&&s| self.world.moons[s]).count();
        self.rank[b] < 0.10 || (moons + 1) as f64 <= taste[b] * slots.len() as f64
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
            legal[k] = u8::from(world.committable(cat, a, k));
        }
        // A planet down to the fields its field-adding chain needs takes that chain's next step or waits, as the persona
        // does: one other building there takes the field the chain needed and the planet never grows again.
        let keeps = game.queue == 0 && world.moon_at[a].is_none() && self.players[owner].miner_ref().reserve;
        if let Some(hold) = keeps.then(|| players::field_reserve(world, cat, a)).flatten() {
            (0..objects).filter(|&k| Some(k) != hold).for_each(|k| legal[k] = 0);
        }
        cands[objects * LEARNER_CAND..].fill(0.0);
        if self.advise { cands[advice.unwrap_or(objects) * LEARNER_CAND + CAND_DIM] = 1.0 }
        legal[objects] = 1;
    }

    /// The errand choice at slot a's login: the errands offered (row 0 waits), and the state, candidate rows and legal
    /// flags the errand model reads, padded to `errands::MAX_CANDIDATES` rows.
    pub fn observe_errands(&mut self, a: usize) -> (Vec<Errand>, Vec<f32>, Vec<f32>, Vec<u8>) {
        let menu = self.menu(a);
        self.observe_errands_in(a, &menu)
    }

    /// `observe_errands` with the login's `menu` (one errand loop reads it once: nothing in the loop moves it before
    /// the errand runs).
    pub fn observe_errands_in(&mut self, a: usize, menu: &errands::Menu) -> (Vec<Errand>, Vec<f32>, Vec<f32>, Vec<u8>) {
        let (world, cat) = (&mut self.world, &self.cat);
        let (rows, width) = (errands::max_candidates(world.wide), errands::cand_dim(world.wide));
        let offered = errands::candidates_in(world, cat, a, menu);
        let state = errands::account_features(world, cat, a);
        let mut cands = vec![0.0; rows * width];
        let mut legal = vec![0; rows];
        for (row, &errand) in offered.iter().enumerate() {
            cands[row * width..(row + 1) * width].copy_from_slice(&errands::candidate_features_in(world, cat, a, errand, menu));
            legal[row] = 1;
        }
        (offered, state, cands, legal)
    }

    /// `observe_errands`' offer alone, for a login whose errand nobody reads features for (the scripted teacher picks
    /// from the offer): the state and candidate rows are a third of an errand loop's time.
    pub fn offer_errands(&mut self, a: usize, menu: &errands::Menu) -> Vec<Errand> {
        errands::candidates_in(&mut self.world, &self.cat, a, menu)
    }

    /// Whether slot a's account raids: its persona is a raider (the errand menu offers spying and raids only then).
    fn raids(&self, a: usize) -> bool {
        matches!(self.players[self.world.account_of(a)], Player::Raider(_))
    }

    /// What the errand menu reads of the season at slot a: whether it raids and, wide, its mates and moon shots.
    pub fn menu(&self, a: usize) -> errands::Menu {
        let mut menu = errands::Menu::narrow(self.raids(a));
        if !self.world.wide { return menu }
        let (w, owner) = (&self.world, self.world.account_of(a));
        let t = w.games[a].t;
        let joined = |b: usize| w.alliance_at[b].is_some_and(|join| t >= join);
        menu.alive = self.gone.iter().map(|&g| !g).collect();
        if joined(owner) {
            menu.shot_at = (0..self.accounts()).map(|b| self.wants_moon(b) && joined(b) && w.alliance_id[b] == w.alliance_id[owner] && !self.gone[b]).collect();
        }
        menu.station = matches!(self.players[owner], Player::Saver(_));
        menu
    }

    /// The row of `offered` the scripted mix would take at slot a: the errand model's teacher.
    pub fn errand_teacher(&mut self, a: usize, offered: &[Errand]) -> usize {
        let menu = self.menu(a);
        self.errand_teacher_in(a, &menu, offered)
    }

    /// `errand_teacher` with the login's `menu`.
    pub fn errand_teacher_in(&mut self, a: usize, menu: &errands::Menu, offered: &[Errand]) -> usize {
        // Wide, the offer is the one the caller just read (the menu is long): the narrow teacher keeps its own pass.
        let pick = match self.world.wide {
            true => errands::teacher_among(&self.world, &self.cat, a, menu, offered),
            false => errands::teacher_choice_in(&mut self.world, &self.cat, a, menu),
        };
        offered.iter().position(|&e| e == pick).unwrap_or(0)
    }

    /// Runs the errand at row `row` of `offered`; a row that is not offered, or one the world refuses, counts as invalid.
    pub fn run_errand(&mut self, a: usize, offered: &[Errand], row: usize) {
        let menu = self.menu(a);
        self.run_errand_in(a, &menu, offered, row)
    }

    /// `run_errand` with the login's `menu`.
    pub fn run_errand_in(&mut self, a: usize, menu: &errands::Menu, offered: &[Errand], row: usize) {
        let done = offered.get(row).map(|&e| errands::apply_in(&mut self.world, &self.cat, a, e, menu));
        if !matches!(done, Some(Ok(()))) { self.world.games[a].invalid += 1 }
        // Wide, a raider whose raid a defence turns away keeps a missile salvo from then on, as `Raider::raid` does.
        let owner = self.world.account_of(a);
        if self.world.wide && menu.raids && a == owner {
            if let (Some(kit), Player::Raider(raider)) = (players::Kit::of(&self.world, &self.cat, a), &self.players[owner]) {
                if raider.miner.missiles == 0 {
                    let targets = players::nearest(&mut self.world, &self.cat, a, players::NEIGHBOURS);
                    if targets.into_iter().any(|t| players::salvo_due(&self.world, &self.cat, a, t, kit)) { self.players[owner].miner().missiles = self.world.tn(a).missile_salvo.round() as i64 }
                }
            }
        }
    }

    /// The model's build at slot a: an object, or `None` to pass. With `draw` it samples as it does in training.
    pub fn choose(&mut self, policy: &Policy, a: usize, heat: f32, draw: Option<&mut u64>) -> Option<usize> {
        let k = self.cat.objects.len() + 1;
        let (mut state, mut cands, mut legal) = (vec![0.0; LEARNER_STATE], vec![0.0; k * LEARNER_CAND], vec![0; k]);
        let advice = self.teacher(a);
        self.observe(a, advice, &mut state, &mut cands, &mut legal);
        let row = match draw {
            Some(rng) => policy.sample(&state, &cands, &legal, heat, rng).0,
            None => policy.best(&state, &cands, &legal),
        };
        Some(row).filter(|&row| row < k - 1)
    }

    /// The look-ahead player's build at slot a: the rollout algorithm, one step of policy improvement over the persona.
    /// Each legal build, and waiting, is committed in `rollouts` copies of the world with fresh dice (the same dice for
    /// every pick, so picks are compared on equal luck); every account then plays its persona for `hours`, and the pick
    /// whose account ends worth the most (points at price plus its stock) wins. The persona's own pick wins every tie.
    /// Also hands back what the learner reads at this decision and every row's worth (NaN where illegal), the labels
    /// a student is cloned from (expert iteration).
    /// With a `prior`, only its `width` best rows are tried beside the persona's pick and waiting (AlphaZero's policy
    /// prior narrows its search the same way). With a `critic`, a rollout's end is scored by its value head (the season's
    /// end as it expects it) instead of the worth at the horizon, which favours what pays back within `hours`.
    /// With `solo` the other accounts only run their economies in a rollout (fleets already flying still land): a world held
    /// still while the account weighs its move, 8-27x cheaper than playing every persona (3 Nov).
    pub fn lookahead(&mut self, a: usize, hours: f64, rollouts: u64, prior: Option<(&Policy, usize)>, critic: Option<&Policy>, solo: bool) -> Lookahead {
        let k = self.cat.objects.len() + 1;
        let (mut state, mut cands, mut legal) = (vec![0.0; LEARNER_STATE], vec![0.0; k * LEARNER_CAND], vec![0; k]);
        let persona = self.teacher(a);
        self.observe(a, persona, &mut state, &mut cands, &mut legal);
        let mut rows: Vec<usize> = (0..k).filter(|&row| legal[row] > 0).collect();
        if let Some((policy, width)) = prior {
            let top: Vec<usize> = policy.ranked(&state, &cands, &legal).into_iter().take(width).collect();
            rows.retain(|&row| top.contains(&row) || Some(row) == persona || row == k - 1);
        }
        let mut worths = vec![f32::NAN; k];
        if rows.len() == 1 { return Lookahead { pick: None, state, cands, legal, worths } }
        let found = self.rollout_worths(a, &rows, &|u: &mut Universe, row| u.play(a, Some((row < k - 1).then_some(row))), hours, rollouts, critic, solo);
        rows.iter().zip(&found).for_each(|(&row, &w)| worths[row] = w as f32);
        let first = rows.iter().position(|&row| Some(row) == persona.or(Some(k - 1))).unwrap_or(0);
        let best = (0..rows.len()).fold(first, |best, i| if found[i] > found[best] { i } else { best });
        Lookahead { pick: Some(rows[best]).filter(|&row| row < k - 1), state, cands, legal, worths }
    }

    /// The champion's errand at slot a's login (the fleet half of its play): every errand offered is run in copies of the
    /// world, followed by the login's `build` as the season plays it, and scored as `lookahead` scores a build; the scripted
    /// mix's own errand wins every tie. Hands back the offer, what the errand model reads and every row's worth.
    pub fn lookahead_errand(&mut self, a: usize, build: Option<Option<usize>>, hours: f64, rollouts: u64, solo: bool) -> ErrandLookahead {
        let (offered, state, cands, legal) = self.observe_errands(a);
        let teacher = self.errand_teacher(a, &offered);
        let rows: Vec<usize> = (0..offered.len()).collect();
        let mut worths = vec![f32::NAN; legal.len()];
        if rows.len() == 1 { return ErrandLookahead { offered, state, cands, legal, worths, row: 0 } }
        let found = self.rollout_worths(a, &rows, &|u: &mut Universe, row| { u.run_errand(a, &offered, row); u.play(a, build) }, hours, rollouts, None, solo);
        rows.iter().zip(&found).for_each(|(&row, &w)| worths[row] = w as f32);
        let row = (0..rows.len()).fold(teacher, |best, i| if found[i] > found[best] { i } else { best });
        ErrandLookahead { offered, state, cands, legal, worths, row }
    }

    /// The worth of slot a's account after each of `rows` is played by `first` in copies of the world and every account
    /// then plays on for `hours` (with `solo`, the others only run their economies), mean of `rollouts` fresh-dice copies,
    /// one thread per row. Scored by the critic's value head at the end when given, else points at price plus stock.
    fn rollout_worths(&mut self, a: usize, rows: &[usize], first: &(dyn Fn(&mut Universe, usize) + Sync), hours: f64, rollouts: u64, critic: Option<&Policy>, solo: bool) -> Vec<f64> {
        let k = self.cat.objects.len() + 1;
        let (owner, end) = (self.world.account_of(a), self.world.games[a].t + hours * 3600.0);
        // The copies carry only the events some ledger has not read yet, and none of the logs play never reads.
        let unread = self.ledgers.iter().map(Ledger::read).min().unwrap_or(0);
        let logs = self.world.take_logs(unread);
        let base = &*self;
        let worth = |row: usize| -> f64 {
            (0..rollouts).map(|r| {
                let mut u = base.clone();
                u.ledgers.iter_mut().for_each(|ledger| ledger.rebase(unread));
                u.world.reseed(r + 1);
                first(&mut u, row);
                let mut draw = (r + 1).wrapping_mul(0x9e37_79b9_7f4a_7c15) ^ row as u64;
                while let Some(b) = u.next_decision() {
                    if u.world.games[b].t >= end { break }
                    if solo && u.world.account_of(b) != owner { u.world.wait(&u.cat, b); continue }
                    let own_build = u.plays(b) && u.world.account_of(b) == owner && u.world.decision_kind(b) == crate::world::Decision::Build;
                    let pick = u.rollout_policy.clone().filter(|_| own_build).map(|p| u.choose(&p, b, 1.0, Some(&mut draw)));
                    u.play(b, pick);
                }
                match critic {
                    Some(critic) => {
                        let (mut end_state, mut end_cands, mut end_legal) = (vec![0.0; LEARNER_STATE], vec![0.0; k * LEARNER_CAND], vec![0; k]);
                        u.observe(owner, None, &mut end_state, &mut end_cands, &mut end_legal);
                        critic.value(&end_state) as f64
                    }
                    None => score::of(&u.world, owner).total as f64 * 1000.0 + u.world.stock(owner)
                        + u.value_to_end.map_or(0.0, |close| u.world.hourly(owner) * (close - end).max(0.0) / 3600.0),
                }
            }).sum::<f64>() / rollouts as f64
        };
        let found = std::thread::scope(|scope| {
            let jobs: Vec<_> = rows.iter().map(|&row| scope.spawn(move || worth(row))).collect();
            jobs.into_iter().map(|job| job.join().expect("a rollout")).collect()
        });
        self.world.put_logs(logs);
        found
    }

    /// The persona's own build at slot a, asked as the model is asked: the learner's teacher.
    pub fn teacher(&mut self, a: usize) -> Option<usize> {
        let owner = self.world.account_of(a);
        let (world, cat) = (&self.world, &self.cat);
        self.players[owner].miner().pick(world, cat, a).filter(|&k| world.committable(cat, a, k))
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
            f32::from(self.world.alliance_at[owner].is_some_and(|join| midnight >= join)),
        ]
    }

    /// The day's highscore at `midnight`: the rank-1 score an expedition's find reads, and alliance membership by standing.
    pub fn day_end(&mut self, midnight: f64) {
        if self.jolt.is_some_and(|day| midnight >= day) { self.jolt = None; self.world.jolt() }
        let n = self.accounts();
        let totals: Vec<i64> = (0..n).map(|a| score::of(&self.world, a).total).collect();
        // N6b: an expedition's find bracket reads the universe's rank-1 score, which the host's highscore refreshes daily.
        self.world.rank1_points = totals.iter().copied().max().unwrap_or(0);
        let mut ranked: Vec<usize> = (0..n).collect();
        ranked.sort_by_key(|&a| std::cmp::Reverse(totals[a]));
        if self.world.alliances_distinct { self.world.public_highscore.clone_from(&ranked) }
        for (place, &a) in ranked.iter().enumerate() { self.rank[a] = (place + 1) as f64 / n as f64 }
        if self.world.relations.on {
            let w = &self.world;
            let club: Vec<Option<usize>> = (0..n).map(|a| w.club(a, midnight)).collect();
            let points: Vec<f64> = totals.iter().map(|&t| t as f64).collect();
            let betrays = w.relations.betrays.clone();
            let view = crate::diplomacy::View { hour: midnight / 3600.0, club: &club, points: &points, betrays: &betrays };
            self.world.relations.pass(&view);
            // Tribute: each vassal member sends a share of its home stock to the overlord's strongest member.
            let bonds: Vec<(usize, usize)> = self.world.relations.tribute.iter().map(|(&v, &(o, _))| (v, o)).collect();
            for (v, o) in bonds {
                let Some(lord) = (0..n).filter(|&a| club[a] == Some(o)).max_by_key(|&a| totals[a]) else { continue };
                for a in (0..n).filter(|&a| club[a] == Some(v) && a != lord) {
                    // Sent as a transport from the member's home planet, as a real vassal pays: no cargo ships, no tribute.
                    let paid = self.world.games[a].planet.res.map(|r| (r * crate::diplomacy::TRIBUTE_SHARE).floor());
                    if crate::players::ship_from_home(&mut self.world, &self.cat, a, lord, paid, [0.0; 3]) {
                        self.world.relations.tribute_paid += crate::economy::weighted(paid);
                    }
                }
            }
        }
        if let Some(draws) = &self.standing {
            for (rank, &a) in ranked.iter().enumerate() {
                let p = rank as f64 / n as f64;
                // Membership follows today's standing both ways: an alliance drops a member that falls away.
                let playing = self.world.games[a].horizon > midnight && midnight >= 3.0 * DAY;
                // A member the politics moved (a schism, a nomad band) stands in its new club while it plays.
                let pinned = self.world.relations.pinned.get(&a).copied();
                if let Some(club) = pinned { self.world.alliance_id[a] = club }
                let placed = playing && (pinned.is_some() || draws[a] < 1.0 - ALLIANCE_STANDING_SLOPE * p);
                if placed != self.world.alliance_at[a].is_some() { self.world.alliance_at[a] = placed.then_some(midnight) }
            }
        }
        if self.recruit {
            let top = n.div_ceil(10);
            for (rank, &a) in ranked.iter().enumerate() {
                self.top_days[a] = if rank < top { self.top_days[a] + 1 } else { 0 };
                if self.top_days[a] >= RECRUIT_DAYS && self.world.alliance_at[a].is_none() && !self.world.inactive(&self.cat, a, midnight) {
                    self.world.alliance_at[a] = Some(midnight);
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

#[cfg(test)]
mod tests {
    use super::*;

    const DATA: &str = concat!(env!("CARGO_MANIFEST_DIR"), "/../../../../storage/rl/ogsim/r8w");

    /// Plays the universe by its personas until `days` have passed.
    fn run(u: &mut Universe, days: f64) {
        while let Some(b) = u.next_decision() {
            if u.world.games[b].t >= days * 86_400.0 { break }
            u.plays(b);
            u.play(b, None);
        }
    }

    #[test]
    fn war_highscore_tracks_the_daily_public_ranking() {
        let econ = format!("{DATA}/units-parity/econ-u1-colonies.json");
        let arms = vec![String::from("alliances=2")];
        let cat = Arc::new(Universe::catalogue(DATA, &econ, &arms).expect("the catalogue"));
        let colonisation = Colonisation::load(&econ).ok().map(Arc::new);
        let mut u = Universe::new(cat, colonisation, DATA, 2, 8, 3, &arms);
        let mut expected: Vec<usize> = (0..u.accounts()).collect();
        expected.sort_by_key(|&a| std::cmp::Reverse(score::of(&u.world, a).total));
        assert!(u.world.public_highscore.is_empty());
        u.day_end(DAY);
        assert_eq!(u.world.public_highscore, expected);
    }

    #[test]
    fn errand_policy_rejects_old_or_unversioned_features() {
        let mut value = serde_json::json!({});
        assert!(Policy::parse_errand(&value, false).is_err());
        value["_errand_feature_version"] = serde_json::json!(1);
        assert!(Policy::parse_errand(&value, false).is_err());
        value["_errand_feature_version"] = serde_json::json!(crate::errands::FEATURE_VERSION);
        for name in ["state_net.0", "state_net.2", "cand_net.0", "joint.0", "joint.2", "joint.4", "value.0", "value.2", "value.4"] {
            value[format!("{name}.weight")] = serde_json::json!([[0.0]]);
            value[format!("{name}.bias")] = serde_json::json!([0.0]);
        }
        assert!(Policy::parse_errand(&value, false).is_ok());
        assert!(Policy::parse_errand(&value, true).is_err(), "a narrow model does not read the wide menu");
        value["_errand_feature_version"] = serde_json::json!(crate::errands::WIDE_FEATURE_VERSION);
        assert!(Policy::parse_errand(&value, true).is_ok());
    }

    /// A look-ahead plays copies of the world: whatever a copy does must not reach the world it was copied from.
    #[test]
    fn a_copy_of_the_world_leaves_the_original_untouched() {
        let econ = format!("{DATA}/units-parity/econ-u1-colonies.json");
        let arms: Vec<String> = "moonstack reach expedition colonies ships cap lab classes feed factory balance satellites doubler moonfeed standing labfirst moontaste netchain merchant techtree doctrine acsdefend acsattack wrecks moonraid relocate keyed class=3 expofull"
            .split(' ').map(String::from).collect();
        let cat = Arc::new(Universe::catalogue(DATA, &econ, &arms).expect("the catalogue"));
        let colonisation = Colonisation::load(&econ).ok().map(Arc::new);
        let fresh = || Universe::new(Arc::clone(&cat), colonisation.clone(), DATA, 14, 16, 3, &arms);
        let (mut plain, mut copied) = (fresh(), fresh());
        run(&mut plain, 6.0);
        run(&mut copied, 6.0);
        let mut copy = copied.clone();
        copy.world.reseed(7);
        run(&mut copy, 12.0);
        run(&mut plain, 12.0);
        run(&mut copied, 12.0);
        let totals = |u: &Universe| (0..16).map(|a| (score::of(&u.world, a).total, u.world.stock(a) as i64)).collect::<Vec<_>>();
        assert_eq!(totals(&plain), totals(&copied));
        assert!(totals(&copy) != totals(&plain), "fresh dice change the copy's future");
    }

    /// A look-ahead moves the world's logs out while it copies: the world it leaves must play on exactly as one never asked.
    #[test]
    fn a_lookahead_leaves_the_world_as_it_found_it() {
        let econ = format!("{DATA}/units-parity/econ-u1-colonies.json");
        let arms: Vec<String> = "moonstack reach expedition colonies ships cap lab classes feed factory balance satellites doubler moonfeed standing labfirst moontaste netchain merchant techtree doctrine acsdefend acsattack wrecks moonraid relocate keyed class=3 expofull"
            .split(' ').map(String::from).collect();
        let cat = Arc::new(Universe::catalogue(DATA, &econ, &arms).expect("the catalogue"));
        let colonisation = Colonisation::load(&econ).ok().map(Arc::new);
        let fresh = || Universe::new(Arc::clone(&cat), colonisation.clone(), DATA, 8, 16, 5, &arms);
        let (mut plain, mut asked) = (fresh(), fresh());
        run(&mut plain, 8.0);
        let mut looked = 0;
        while let Some(b) = asked.next_decision() {
            if asked.world.games[b].t >= 8.0 * 86_400.0 { break }
            asked.plays(b);
            if asked.world.account_of(b) == 0 && asked.world.decision_kind(b) == crate::world::Decision::Build && looked < 40 {
                asked.lookahead(b, 2.0, 1, None, None, looked % 2 == 0);
                looked += 1;
            }
            asked.play(b, None);
        }
        assert!(looked > 0);
        let totals = |u: &Universe| (0..16).map(|a| (score::of(&u.world, a).total, u.world.stock(a) as i64, u.world.events.len())).collect::<Vec<_>>();
        assert_eq!(totals(&plain), totals(&asked));
    }
}
