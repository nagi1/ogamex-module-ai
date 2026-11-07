//! The personas' hand-set decision numbers that no source fixes, as one tunable set per account (5 Nov: tune the
//! teacher, as the Kaggle rule-bot winners did, instead of pushing the student past it).
//!
//! * `params=FILE`: a JSON object overriding any of the fields below for every account (missing fields keep today's).
//! * `params-es=NOISE:SIGN:SIGMA`: per-account log-scale nudges for antithetic search, NOISE a little-endian f32 file
//!   of `accounts x NAMES.len()` standard normals; account k's value i is base_i x exp(SIGN x SIGMA x noise[k][i]).
//!
//! Without either arm every account reads `Tuning::default()`, today's constants, so the season is byte-identical.
//! Numbers a real-data source fixes (RESEARCH_SHARE, SETTLED_PLANETS, the satellite cap, WAR_ODDS_*, ANCHOR_VALUE, the
//! miner's and saver's military shares) stay constants and are not here.

use serde::{Deserialize, Serialize};

#[derive(Clone, Debug, PartialEq, Serialize, Deserialize)]
#[serde(default)]
pub struct Tuning {
    /// Days of the capital's production its store must hold to count as surplus (network, merchant, cheap research).
    pub surplus_days: f64,
    /// Loot (and debris) must be this many times the fuel for a raid or a harvest.
    pub pays: f64,
    /// Most enemy 3-round damage a raid accepts, as a share of the raiding hull.
    pub safe_share: f64,
    /// Hours before the same target is raided, salvoed or moon-struck again.
    pub raid_cooldown_hours: f64,
    /// Missiles kept and fired once defence turns a raid away.
    pub missile_salvo: f64,
    /// Hours a probe or attack on the account keeps it "exposed" (gates the night save).
    pub exposed_hours: f64,
    /// A store this full (share of capacity) makes the yard buy even over the persona's military share.
    pub overflow: f64,
    /// Hours of production the yard leaves in the store on top of what the queues save for.
    pub keep_hours: f64,
    /// The raider's military share of its score (no source; the miner's 0.06 and the saver's 0.20 are fixed).
    pub raider_military_share: f64,
    /// Cargo hold wanted, as a share of the stock, per persona.
    pub raider_cargo_share: f64,
    pub miner_cargo_share: f64,
    pub saver_cargo_share: f64,
    /// The raider's standing fighters.
    pub raider_fighters: f64,
    /// Share of the host's expedition slots the player keeps busy (arm `skill`; 1 = every slot). A skill number, not a
    /// searched one: it is not in `NAMES`, and `params-es=` keeps the base's.
    pub expedition_use: f64,
}

impl Default for Tuning {
    fn default() -> Tuning {
        Tuning {
            surplus_days: 60.0,
            pays: 3.0,
            safe_share: 0.3,
            raid_cooldown_hours: 3.0,
            missile_salvo: 20.0,
            exposed_hours: 24.0,
            overflow: 0.8,
            keep_hours: 2.0,
            raider_military_share: 0.30,
            raider_cargo_share: 0.2,
            miner_cargo_share: 0.1,
            saver_cargo_share: 0.1,
            raider_fighters: 30.0,
            expedition_use: 1.0,
        }
    }
}

/// The tunable fields in vector order (the order of `params-es` noise columns).
pub const NAMES: [&str; 13] = [
    "surplus_days", "pays", "safe_share", "raid_cooldown_hours", "missile_salvo", "exposed_hours", "overflow", "keep_hours",
    "raider_military_share", "raider_cargo_share", "miner_cargo_share", "saver_cargo_share", "raider_fighters",
];

impl Tuning {
    pub fn to_vec(&self) -> Vec<f64> {
        vec![self.surplus_days, self.pays, self.safe_share, self.raid_cooldown_hours, self.missile_salvo, self.exposed_hours,
             self.overflow, self.keep_hours, self.raider_military_share, self.raider_cargo_share, self.miner_cargo_share,
             self.saver_cargo_share, self.raider_fighters]
    }

    pub fn from_vec(v: &[f64]) -> Tuning {
        Tuning {
            surplus_days: v[0], pays: v[1], safe_share: v[2], raid_cooldown_hours: v[3], missile_salvo: v[4], exposed_hours: v[5],
            overflow: v[6].min(1.0), keep_hours: v[7], raider_military_share: v[8].min(0.95), raider_cargo_share: v[9],
            miner_cargo_share: v[10], saver_cargo_share: v[11], raider_fighters: v[12], expedition_use: 1.0,
        }
    }

    /// The per-account sets the arms ask for: `params=` as the base, `params-es=` nudging each account.
    pub fn for_accounts(arms: &[String], accounts: usize) -> Result<Vec<Tuning>, String> {
        let base = match arms.iter().find_map(|arm| arm.strip_prefix("params=")) {
            Some(path) => serde_json::from_str::<Tuning>(&std::fs::read_to_string(path).map_err(|e| format!("params {path}: {e}"))?)
                .map_err(|e| format!("params {path}: {e}"))?,
            None => Tuning::default(),
        };
        let Some(spec) = arms.iter().find_map(|arm| arm.strip_prefix("params-es=")) else { return Ok(vec![base; accounts]) };
        let parts: Vec<&str> = spec.rsplitn(3, ':').collect();
        let [sigma, sign, path] = parts[..] else { return Err("params-es=NOISE:SIGN:SIGMA".into()) };
        let (sign, sigma): (f64, f64) = (sign.parse().map_err(|_| "params-es SIGN")?, sigma.parse().map_err(|_| "params-es SIGMA")?);
        let bytes = std::fs::read(path).map_err(|e| format!("params-es {path}: {e}"))?;
        let noise: Vec<f32> = bytes.chunks_exact(4).map(|b| f32::from_le_bytes([b[0], b[1], b[2], b[3]])).collect();
        let n = NAMES.len();
        if noise.len() < accounts * n { return Err(format!("params-es {path}: {} values, need {}", noise.len(), accounts * n)) }
        let b = base.to_vec();
        Ok((0..accounts).map(|k| Tuning {
            expedition_use: base.expedition_use,
            ..Tuning::from_vec(&(0..n).map(|i| b[i] * (sign * sigma * noise[k * n + i] as f64).exp()).collect::<Vec<_>>())
        }).collect())
    }
}

/// Arm `skill`: players differ in how well they play (6 Nov, owner: "to get closer to reality"). Each account draws one
/// skill quantile q, uniform in (0, 1) and deterministic per (seed, account), and q acts only through what a player's skill
/// acts through, never a game rule:
/// * sessions a day: with `bands`, q picks the module's presence band (`RoutineProfile::presenceBand`, the persona's mix in
///   `AiPersonaFactory`) and the diligence inside it, so the band shares stay the module's and the strong are the active;
/// * expedition slots kept busy: `expedition_use(q)`; the host's finds stay as they are (owner), a weak player just runs
///   fewer of them.
/// The tail is heavy (few very strong, a broad middle): the use rises with q to the sixth. Target: the real top of speed-8
/// universes 128-256 days old (PUB, `rl/scripts/social_gauges.py` REAL): rank-1 / rank-10 points 3.1 / 5.5 / 13.6, rank-1
/// share 0.14 / 0.20 / 0.23; all active players' points over the median p75 8.5x, p90 31x, p99 116x.
pub fn skill_draw(seed: u64, accounts: usize) -> Vec<f64> {
    (0..accounts).map(|a| {
        let mut rng = (seed ^ 0x5C11_1D0E).wrapping_mul(0x9E37_79B9_7F4A_7C15).wrapping_add(a as u64) | 1;
        let _ = crate::economy::next_random(&mut rng);
        ((crate::economy::next_random(&mut rng) >> 11) as f64 + 0.5) / (1u64 << 53) as f64
    }).collect()
}

/// The fewest of its expedition slots the weakest player keeps busy, and the exponent of the rise to all of them.
pub const EXPEDITION_USE_FLOOR: f64 = 0.10;
pub const EXPEDITION_USE_POWER: f64 = 6.0;

/// The share of its expedition slots a player of skill quantile q keeps busy.
pub fn expedition_use(q: f64) -> f64 {
    EXPEDITION_USE_FLOOR + (1.0 - EXPEDITION_USE_FLOOR) * q.clamp(0.0, 1.0).powf(EXPEDITION_USE_POWER)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn defaults_round_trip_and_no_arm_gives_defaults() {
        let d = Tuning::default();
        assert_eq!(Tuning::from_vec(&d.to_vec()), d);
        assert_eq!(d.to_vec().len(), NAMES.len());
        assert_eq!(Tuning::for_accounts(&[], 3).unwrap(), vec![d; 3]);
    }

    #[test]
    fn skill_is_deterministic_per_seed_and_account_and_spreads_expedition_use() {
        let (a, b) = (skill_draw(7, 64), skill_draw(7, 64));
        assert_eq!(a, b);
        assert_ne!(a, skill_draw(8, 64));
        assert_eq!(skill_draw(7, 10)[..], a[..10], "an account's skill does not depend on how many accounts follow");
        assert!(a.iter().all(|&q| q > 0.0 && q < 1.0));
        let mean = a.iter().sum::<f64>() / a.len() as f64;
        assert!((0.35..0.65).contains(&mean), "uniform quantiles, mean {mean}");
        assert_eq!(expedition_use(1.0), 1.0);
        assert_eq!(expedition_use(0.0), EXPEDITION_USE_FLOOR);
        assert!(expedition_use(0.5) < 0.5 && expedition_use(0.99) > 0.9, "a heavy top: few run every slot");
    }

    /// One rule set: the live module's `skill.yaml` (`SkillQuantile`) holds the same expedition-use numbers.
    #[test]
    fn skill_constants_match_the_module_yaml() {
        let path = concat!(env!("CARGO_MANIFEST_DIR"), "/../../resources/behavior/skill.yaml");
        let text = std::fs::read_to_string(path).expect("skill.yaml");
        let read = |name: &str| -> f64 {
            text.lines().find_map(|l| l.strip_prefix(&format!("{name}:")).map(|v| v.trim().parse::<f64>().expect("number"))).unwrap_or_else(|| panic!("{name}"))
        };
        assert_eq!(read("expedition_use_floor"), EXPEDITION_USE_FLOOR);
        assert_eq!(read("expedition_use_power"), EXPEDITION_USE_POWER);
    }
}
