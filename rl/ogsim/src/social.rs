//! Social gauges (arm `social`): alliance structure, dominance and the war graph of a season, computed in memory at its
//! end and checked against the real bands in `plan/rl/SOCIAL-STEP1-AUDIT.md`. This is a read-only measurement: nothing
//! here feeds a decision. Python's `rl/scripts/social_gauges.py` reads the same quantities from the season files and
//! serves as the cross-check.
//!
//! Hostility is a fight on an attack mission whose target is not an alliance mate at that second (an attack on a mate is
//! a moon shot). Structural balance signs a pair `+` when both sides sit in one alliance at the season's end and `-`
//! when they fought; it counts only closed triads (every pair signed). Triads are counted sparsely: all-positive ones
//! in closed form per alliance, and the others from the negative edges, so a 1000-account world costs about the same
//! as its hostile pairs.

use std::collections::{HashMap, HashSet};

/// What the season accumulates fight by fight and day by day.
#[derive(Clone, Debug, Default)]
pub struct Tally {
    enemies: HashSet<(usize, usize)>,
    hostile: u64,
    inter: u64,
    intra: u64,
    at_top10: u64,
    active_days: u64,
    /// Each account's alliance on the last day row: the season's final midnight drops every member under `standing`
    /// (no account plays past the horizon), so the end state would read nobody allied.
    clubs: Vec<Option<usize>>,
}

/// The gauges at the season's end, named as `social_gauges.py` names them.
#[derive(Clone, Debug, serde::Serialize)]
pub struct Gauges {
    pub ally_share: f64,
    pub largest_of_active: f64,
    pub clubs3_per100: f64,
    pub top_ally_pts: f64,
    pub top10_sides: usize,
    pub rank1_share: f64,
    pub r1_r10: f64,
    pub hostile_per_active_day: f64,
    pub inter_ally: f64,
    pub intra_ally: f64,
    pub at_top10: f64,
    pub balance: f64,
    pub closed_triads: f64,
}

impl Tally {
    /// One hostile fight. `clubs` is each side's alliance at that second (None outside one); `top10` says whether
    /// the defender was in the latest daily top ten.
    pub fn fight(&mut self, attacker: usize, defender: usize, clubs: (Option<usize>, Option<usize>), top10: bool) {
        if attacker == defender { return }
        self.hostile += 1;
        if let (Some(x), Some(y)) = clubs {
            if x == y { self.intra += 1 } else { self.inter += 1 }
        }
        self.at_top10 += u64::from(top10);
        self.enemies.insert((attacker.min(defender), attacker.max(defender)));
    }

    /// One day's count of active accounts (the denominator of hostility per active account-day).
    pub fn day(&mut self, active: usize, clubs: Vec<Option<usize>>) {
        self.active_days += active as u64;
        self.clubs = clubs;
    }

    /// The alliances of the last day row (empty before the first).
    pub fn clubs(&self) -> &[Option<usize>] { &self.clubs }

    /// The gauges from the season's last state: per account, whether it is active, its alliance (None outside one)
    /// and its points.
    pub fn gauges(&self, active: &[bool], club: &[Option<usize>], points: &[f64]) -> Gauges {
        let accounts: Vec<usize> = (0..active.len()).filter(|&a| active[a]).collect();
        let n = accounts.len().max(1) as f64;
        let total: f64 = accounts.iter().map(|&a| points[a]).sum::<f64>().max(1.0);
        let mut members: HashMap<usize, Vec<usize>> = HashMap::new();
        for &a in &accounts { if let Some(c) = club[a] { members.entry(c).or_default().push(a) } }
        let mut sizes: Vec<usize> = members.values().map(Vec::len).collect();
        sizes.sort_unstable_by(|x, y| y.cmp(x));
        let club_points = members.values().map(|m| m.iter().map(|&a| points[a]).sum::<f64>()).fold(0.0, f64::max);
        let mut order = accounts.clone();
        order.sort_by(|&x, &y| points[y].total_cmp(&points[x]));
        let top10 = &order[..order.len().min(10)];
        let sides: HashSet<usize> = top10.iter().filter_map(|&a| club[a]).collect();
        let solo = top10.iter().filter(|&&a| club[a].is_none()).count();
        let share = |k: u64| if self.hostile == 0 { f64::NAN } else { k as f64 / self.hostile as f64 };
        let (balanced, closed) = balance(&accounts, club, &members, &self.enemies);
        Gauges {
            ally_share: sizes.iter().sum::<usize>() as f64 / n,
            largest_of_active: sizes.first().copied().unwrap_or(0) as f64 / n,
            clubs3_per100: 100.0 * sizes.iter().filter(|&&s| s >= 3).count() as f64 / n,
            top_ally_pts: club_points / total,
            top10_sides: sides.len() + solo,
            rank1_share: order.first().map_or(0.0, |&a| points[a] / total),
            r1_r10: if order.len() >= 10 { points[order[0]] / points[order[9]].max(1.0) } else { f64::NAN },
            hostile_per_active_day: self.hostile as f64 / self.active_days.max(1) as f64,
            inter_ally: share(self.inter),
            intra_ally: share(self.intra),
            at_top10: share(self.at_top10),
            balance: if closed > 0.0 { balanced / closed } else { f64::NAN },
            closed_triads: closed,
        }
    }
}

/// Balanced and closed triads among `accounts`. A pair is `+` in one alliance, else `-` when it fought, else unsigned.
/// All-`+` triads are C(size, 3) per alliance. A triad with k `-` edges is met once from each of them, so it adds 1/k.
fn balance(accounts: &[usize], club: &[Option<usize>], members: &HashMap<usize, Vec<usize>>,
           enemies: &HashSet<(usize, usize)>) -> (f64, f64) {
    let live: HashSet<usize> = accounts.iter().copied().collect();
    let same = |a: usize, b: usize| club[a].is_some() && club[a] == club[b];
    let sign = |a: usize, b: usize| -> i8 {
        if same(a, b) { 1 } else if enemies.contains(&(a.min(b), a.max(b))) { -1 } else { 0 }
    };
    let mut foes: HashMap<usize, Vec<usize>> = HashMap::new();
    for &(a, b) in enemies {
        if live.contains(&a) && live.contains(&b) && !same(a, b) {
            foes.entry(a).or_default().push(b);
            foes.entry(b).or_default().push(a);
        }
    }
    let choose3 = |k: usize| (k * k.saturating_sub(1) * k.saturating_sub(2) / 6) as f64;
    let positive: f64 = members.values().map(|m| choose3(m.len())).sum();
    let (mut balanced, mut closed) = (positive, positive);
    let none = Vec::new();
    for (&u, list) in &foes {
        for &v in list.iter().filter(|&&v| v > u) {
            // Every third account signed to both: u's mates and u's foes.
            let mates = club[u].and_then(|c| members.get(&c)).unwrap_or(&none);
            for &w in mates.iter().chain(foes.get(&u).unwrap_or(&none)) {
                if w == u || w == v { continue }
                let (su, sv) = (sign(u, w), sign(v, w));
                if su == 0 || sv == 0 { continue }
                let negatives = 1 + i32::from(su < 0) + i32::from(sv < 0);
                let weight = 1.0 / f64::from(negatives);
                closed += weight;
                if -i32::from(su) * i32::from(sv) > 0 { balanced += weight }
            }
        }
    }
    (balanced, closed)
}

#[cfg(test)]
mod tests {
    use super::*;

    /// The sparse count equals a brute-force pass over every triple.
    #[test]
    fn sparse_balance_equals_brute_force() {
        let mut seed = 7u64;
        let mut next = || { seed ^= seed << 13; seed ^= seed >> 7; seed ^= seed << 17; seed };
        for _ in 0..20 {
            let n = 30;
            let club: Vec<Option<usize>> = (0..n).map(|_| { let r = next() % 5; (r < 4).then_some(r as usize) }).collect();
            let mut t = Tally::default();
            for _ in 0..60 { let (a, b) = ((next() % n as u64) as usize, (next() % n as u64) as usize); t.fight(a, b, (club[a], club[b]), false) }
            let active: Vec<bool> = (0..n).map(|_| next() % 10 != 0).collect();
            let accounts: Vec<usize> = (0..n).filter(|&a| active[a]).collect();
            let mut members: HashMap<usize, Vec<usize>> = HashMap::new();
            for &a in &accounts { if let Some(c) = club[a] { members.entry(c).or_default().push(a) } }
            let (b, c) = balance(&accounts, &club, &members, &t.enemies);
            let sign = |a: usize, b: usize| -> i32 {
                if club[a].is_some() && club[a] == club[b] { 1 } else if t.enemies.contains(&(a.min(b), a.max(b))) { -1 } else { 0 }
            };
            let (mut bb, mut cc) = (0.0, 0.0);
            for i in 0..accounts.len() { for j in i + 1..accounts.len() { for k in j + 1..accounts.len() {
                let (x, y, z) = (accounts[i], accounts[j], accounts[k]);
                let s = sign(x, y) * sign(y, z) * sign(x, z);
                if s != 0 { cc += 1.0; if s > 0 { bb += 1.0 } }
            }}}
            assert!((b - bb).abs() < 1e-6 && (c - cc).abs() < 1e-6, "sparse {b}/{c} vs brute {bb}/{cc}");
        }
    }

    #[test]
    fn structure_and_dominance() {
        let mut t = Tally::default();
        t.day(4, Vec::new());
        t.fight(0, 2, (Some(1), None), true);
        t.fight(1, 0, (Some(2), Some(1)), false);
        let g = t.gauges(&[true, true, true, true], &[Some(1), Some(2), None, Some(1)], &[50.0, 30.0, 15.0, 5.0]);
        assert_eq!(g.ally_share, 0.75);
        assert_eq!(g.largest_of_active, 0.5);
        assert_eq!(g.top10_sides, 3);
        assert_eq!(g.rank1_share, 0.5);
        assert_eq!(g.inter_ally, 0.5);
        assert_eq!(g.at_top10, 0.5);
        assert_eq!(g.hostile_per_active_day, 0.5);
    }
}
