//! Social persona (arm `persona`): who each account is socially, as 12 traits in 0..1, drawn near an archetype of
//! `resources/behavior/social-persona.yaml` and drifting with experience (SOCIAL-WORLD-DRAFT.md, part 7). The file is
//! the single source: this module parses it at startup and names no trait value of its own.

use std::collections::BTreeMap;

pub const TRAITS: [&str; 12] = ["ambition", "honesty", "loyalty", "cruelty", "cunning", "courage", "generosity",
    "dominance", "freedom", "authority", "forgiveness", "vindictiveness"];

pub type Traits = [f64; 12];

pub fn t(traits: &Traits, name: &str) -> f64 {
    traits[TRAITS.iter().position(|&x| x == name).unwrap_or_else(|| panic!("trait {name}"))]
}

#[derive(Clone, Debug)]
pub struct Archetype { pub name: String, pub share: f64, pub traits: Traits }

#[derive(Clone, Debug, Default)]
pub struct Personas {
    pub spread: f64,
    pub archetypes: Vec<Archetype>,
    /// event -> trait deltas
    pub drift: BTreeMap<String, Vec<(usize, f64)>>,
}

fn numbers(text: &str) -> Vec<f64> {
    text.split(|c: char| !(c.is_ascii_digit() || c == '.' || c == '-' || c == '+')).filter(|s| !s.is_empty()).filter_map(|s| s.parse().ok()).collect()
}

impl Personas {
    /// Parses the persona YAML (its own small, fixed layout: flow mappings one per line).
    pub fn parse(text: &str) -> Personas {
        let mut p = Personas::default();
        let mut section = "";
        for raw in text.lines() {
            let line = raw.split('#').next().unwrap_or("");
            if line.trim().is_empty() { continue }
            if !line.starts_with(' ') {
                let (key, rest) = line.split_once(':').unwrap_or((line, ""));
                section = match key.trim() { "spread" => { p.spread = rest.trim().parse().expect("spread"); "" } k => if k == "archetypes" { "archetypes" } else if k == "drift" { "drift" } else { "" } };
                continue;
            }
            let Some((name, body)) = line.trim().split_once(':') else { continue };
            match section {
                "archetypes" => {
                    let share = numbers(body.split("traits").next().unwrap_or(""))[0];
                    let v = numbers(body.split_once('[').map(|x| x.1).unwrap_or(""));
                    let traits: Traits = v.try_into().unwrap_or_else(|_| panic!("archetype {name}: 12 traits"));
                    p.archetypes.push(Archetype { name: name.trim().into(), share, traits });
                }
                "drift" => {
                    let deltas = body.trim().trim_matches(|c| c == '{' || c == '}').split(',').filter_map(|kv| {
                        let (k, v) = kv.split_once(':')?;
                        Some((TRAITS.iter().position(|&x| x == k.trim()).unwrap_or_else(|| panic!("drift trait {k}")), v.trim().parse::<f64>().ok()?))
                    }).collect();
                    p.drift.insert(name.trim().into(), deltas);
                }
                _ => {}
            }
        }
        assert!(!p.archetypes.is_empty(), "no archetypes");
        p
    }

    pub fn load() -> Personas {
        let path = concat!(env!("CARGO_MANIFEST_DIR"), "/../../resources/behavior/social-persona.yaml");
        Personas::parse(&std::fs::read_to_string(path).expect("social-persona.yaml"))
    }

    /// One account's archetype and traits, on a stream of its own (seed, account) so other draws stay put.
    pub fn draw(&self, seed: u64, account: usize) -> (usize, Traits) {
        let mut x = seed ^ (account as u64).wrapping_mul(0x9e37_79b9_7f4a_7c15) ^ 0x5eed_9e75_0a11_7a11;
        let mut unit = || {
            x ^= x << 13; x ^= x >> 7; x ^= x << 17;
            (x >> 11) as f64 / (1u64 << 53) as f64
        };
        let total: f64 = self.archetypes.iter().map(|a| a.share).sum();
        let mut pick = unit() * total;
        let k = self.archetypes.iter().position(|a| { pick -= a.share; pick < 0.0 }).unwrap_or(self.archetypes.len() - 1);
        let mut traits = self.archetypes[k].traits;
        for v in traits.iter_mut() {
            // Box-Muller gaussian noise.
            let (u1, u2) = (unit().max(1e-12), unit());
            let z = (-2.0 * u1.ln()).sqrt() * (2.0 * std::f64::consts::PI * u2).cos();
            *v = (*v + z * self.spread).clamp(0.0, 1.0);
        }
        (k, traits)
    }

    /// Experience moves the traits (`drift` in the file).
    pub fn experience(&self, traits: &mut Traits, event: &str) {
        for &(i, d) in self.drift.get(event).map(Vec::as_slice).unwrap_or(&[]) { traits[i] = (traits[i] + d).clamp(0.0, 1.0) }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn the_file_parses_and_shares_sum_to_one() {
        let p = Personas::load();
        assert_eq!(p.archetypes.len(), 16);
        assert!((p.archetypes.iter().map(|a| a.share).sum::<f64>() - 1.0).abs() < 1e-9);
        assert!(p.spread > 0.0 && p.drift.contains_key("betrayed"));
    }

    #[test]
    fn draws_follow_the_shares_and_stay_in_range() {
        let p = Personas::load();
        let mut count = vec![0usize; p.archetypes.len()];
        for a in 0..20_000 {
            let (k, traits) = p.draw(7, a);
            count[k] += 1;
            assert!(traits.iter().all(|v| (0.0..=1.0).contains(v)));
        }
        for (k, a) in p.archetypes.iter().enumerate() {
            let seen = count[k] as f64 / 20_000.0;
            assert!((seen - a.share).abs() < 0.015, "{} drawn {seen} vs share {}", a.name, a.share);
        }
    }

    #[test]
    fn betrayal_drifts_toward_distrust() {
        let p = Personas::load();
        let mut traits = p.archetypes[0].traits;
        let before = t(&traits, "loyalty");
        p.experience(&mut traits, "betrayed");
        assert!(t(&traits, "loyalty") < before);
    }
}
