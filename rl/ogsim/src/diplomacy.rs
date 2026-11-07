//! Alliance diplomacy (arm `diplomacy`): the PHP module's alliance politics, played in the simulator.
//!
//! A port of `app/Actions/AdvanceAiAlliancePoliticsAction.php`, with its numbers from
//! `resources/behavior/alliance-war.yaml`, `grudges.yaml` and `alliance-ambition.yaml`. A test reads those files and
//! fails if a constant here drifts from them, so the simulator and the live module play one set of rules. Every input
//! is something a member can see: the combat reports of hits on its own planets (the grudges) and the public alliance
//! highscore (the points).
//!
//! Per pass, in the PHP order: peace, conspiracy, then per club: declare war, offer a pact, betray a pact.
//! - **Grudge**: a battle leaves `HIT_THREAT` of threat (0..1) from the victim toward the attacker. It holds for
//!   `GRACE_HOURS`, then halves every `HALF_LIFE_HOURS`, and is dropped under `FLOOR`. A club's grievance against
//!   another is the sum its members hold toward the other's members, divided by `max(1, members / REFERENCE_MEMBERS)`:
//!   a bloc reads its grudges per typical club, so a few raids among many members neither start nor hold a war.
//! - **War**: a club with at most `MAX_OPEN_WARS - 1` wars open declares on the club it holds the largest grievance
//!   against, once that is at least `WAR_GRIEVANCE` (times `ALLY_WAR_FACTOR` when a pact partner already fights them)
//!   and at least the other side's grievance. It never declares on a club `WAR_MAX_STRENGTH_RATIO` times its points
//!   unless a partner fights it.
//! - **Peace**: a non-coalition war that has stood `WAR_MINIMUM_HOURS` ends once both grievances sum under
//!   `PEACE_GRIEVANCE`.
//! - **Pact**: a club offers a NAP to whoever fights its enemy (or the one it fears), or, while at war, to a third club it
//!   trades hits with (no second front). The partner accepts unless its leader hates the offerer's members (threat at or
//!   above `ENEMY_THREAT`) or holds a larger grievance against the offerer than against the enemy.
//! - **Betrayal**: a leader whose persona betrays, with no war open, breaks a pact with a partner it has outgrown
//!   `BETRAY_STRENGTH_RATIO` times.
//! - **Conspiracy**: when one club holds over `COALITION_SHARE` of all club points, the two strongest runners-up sign a
//!   pact (leader to leader) and both declare war on it.

use std::collections::{BTreeMap, HashMap};

// alliance-war.yaml
pub const WAR_GRIEVANCE: f64 = 0.8;
pub const BETRAY_STRENGTH_RATIO: f64 = 2.0;
pub const COALITION_SHARE: f64 = 0.5;
pub const PEACE_GRIEVANCE: f64 = 0.3;
pub const WAR_MINIMUM_HOURS: f64 = 24.0;
pub const ALLY_WAR_FACTOR: f64 = 0.5;
pub const WAR_MAX_STRENGTH_RATIO: f64 = 4.0;
pub const MAX_OPEN_WARS: usize = 1;
pub const REFERENCE_MEMBERS: f64 = 6.0;
// grudges.yaml
pub const GRACE_HOURS: f64 = 24.0;
pub const HALF_LIFE_HOURS: f64 = 48.0;
pub const FLOOR: f64 = 0.02;
/// A battle's threat: 0.3 appraised plus 0.2 recorded (alliance-war.yaml's header; DEF-011 in relational-memory.md).
pub const HIT_THREAT: f64 = 0.5;
/// `Enemies::ENEMY_THREAT`.
pub const ENEMY_THREAT: f64 = 0.75;

/// alliance-ambition.yaml `betrays` per archetype, for the simulator's three personas: a miner is a miner, a raider a
/// raider, and a fleet saver (warships kept, expeditions) a fleeter.
pub fn betrays_share(persona: &str) -> f64 {
    match persona { "raider" => 0.6, "saver" => 0.5, _ => 0.05 }
}

#[derive(Clone, Copy, Debug, PartialEq, Eq, serde::Serialize)]
pub enum Stance { War, Pact }

/// One diplomatic move, for the season's log (`<out>.diplomacy.jsonl`).
#[derive(Clone, Debug, serde::Serialize)]
pub struct Event {
    pub hour: f64,
    pub kind: &'static str,
    pub by: usize,
    pub with: usize,
    pub cause: &'static str,
}

#[derive(Clone, Debug)]
struct Open { stance: Stance, since: f64, cause: &'static str, secret: bool, notice: Option<Notice> }

/// A pact cancelled by notice: it binds until `ends` (the hour), then war opens, declared by the club that cancelled.
#[derive(Clone, Copy, Debug)]
struct Notice { ends: f64, by: usize }

#[derive(Clone, Debug, Default)]
pub struct Relations {
    pub on: bool,
    open: BTreeMap<(usize, usize), Open>,
    /// Threat (victim account, attacker account) -> (value, hour of the last hit).
    threat: HashMap<(usize, usize), (f64, f64)>,
    /// Offers already made (offerer club, partner club): an offer is made once and awaited.
    offered: Vec<(usize, usize)>,
    /// Arm `minds`: when each club last courted another (offerer, other) -> hour.
    courted: BTreeMap<(usize, usize), f64>,
    /// Arm `minds`: every partner decision the teacher made, with what the leader could see (phase C imitation data).
    pub decisions: Vec<serde_json::Value>,
    /// Arm `cycles` (Ibn Khaldun's asabiyya): each club's cohesion, 1 at founding; it wears down in peace and is renewed by
    /// war (an outside threat binds a group).
    pub asabiyya: BTreeMap<usize, f64>,
    /// Arm `cycles`: accounts whose membership the politics moved (a schism, a nomad uprising): account -> club. The
    /// season runner holds them in that club while they play.
    pub pinned: BTreeMap<usize, usize>,
    pub cycles: bool,
    /// Phase D league (arm `probe=VARIANT`): the probe club plays VARIANT, every other club the teacher. Partner
    /// decisions: keep, loyal, betray; one decision family each: hawk (conquest war whenever at peace), dove (declares
    /// no war, peace on the enemy's consent), courtier (courts and accepts every pact), hermit (no new pacts), tributer
    /// (demands tribute of every weaker club); `teacher` = the engines; `probe_share` is its share of club points
    /// at the last pass, the score a paired-seed league compares.
    pub probe: Option<String>,
    pub probe_club: Option<usize>,
    pub probe_share: f64,
    /// Phase C (arm `bc=TREE.json`): the imitation tree plays every leader's choice toward each partner, read off the
    /// decision row the teacher logs (engines stay live: their answers are the tree's features).
    pub bc: Option<BcTree>,
    last_uprising: f64,
    /// Bands formed and the club each rises against: their war is declared once the band stands as a club.
    uprisings: Vec<(usize, usize)>,
    pub log: Vec<Event>,
    /// Per account, whether its leader taste betrays (alliance-ambition.yaml `betrays`, drawn once per account).
    pub betrays: Vec<bool>,
    /// Arm `persona`: each account's traits (social-persona.yaml), which then drive the leader's choices in place of
    /// the fixed lines, and drift with what happens to the account.
    pub persona: Option<(crate::persona::Personas, Vec<crate::persona::Traits>)>,
    /// Pacts each club has broken: public reputation.
    pub broken: HashMap<usize, u32>,
    /// Private knowledge (observer club, breaker club): a betrayal of a secret pact is known to its victim only,
    /// until it leaks.
    known: std::collections::HashSet<(usize, usize)>,
    /// Betrayals of secret pacts not yet public: (breaker, victim).
    hidden: Vec<(usize, usize)>,
    /// The leak stream (arm `persona`), seeded per season.
    pub rng: u64,
    /// Vassalage (arm `persona`): vassal club -> (overlord club, hour). The vassal pays tribute and is spared.
    pub tribute: BTreeMap<usize, (usize, f64)>,
    /// Resources paid in tribute over the season (the season runner moves them).
    pub tribute_paid: f64,
    /// Arm `persona`, governance: each club's standing leader, and the hour of the last coup attempt in the club.
    pub leaders: BTreeMap<usize, usize>,
    plotted: BTreeMap<usize, f64>,
    /// Arm `minds`: each account's living mind; this pass records what each member saw into it.
    pub minds: crate::mind::Minds,
}

/// Arm `persona`, governance (SOCIAL-WORLD-DRAFT part 10): a member this ambitious and domineering, holding this many
/// times the leader's points, plots; the members' mean authority (respect for hierarchy) under COUP_AUTHORITY lets it
/// through. One attempt per club per COUP_COOLDOWN_HOURS.
pub const COUP_AMBITION: f64 = 0.7;
pub const COUP_POINTS_RATIO: f64 = 1.5;
pub const COUP_AUTHORITY: f64 = 0.5;
pub const COUP_COOLDOWN_HOURS: f64 = 24.0 * 14.0;

/// Arm `persona`, vassalage (SOCIAL-WORLD-DRAFT part 9): a club losing its war (outweighed 2×, taking twice the harm) submits when its leader
/// values freedom below this, to an overlord whose leader's dominance is above OVERLORD_DOMINANCE.
pub const SUBMIT_FREEDOM: f64 = 0.6;
pub const OVERLORD_DOMINANCE: f64 = 0.5;
/// A vassal revolts once it holds this share of its overlord's points.
pub const REVOLT_RATIO: f64 = 0.5;
/// Share of a vassal member's stock paid each day.
pub const TRIBUTE_SHARE: f64 = 0.1;

/// Arm `persona`, secrets (SOCIAL-WORLD-DRAFT part 8): a leader this cunning keeps a pact secret.
pub const SECRET_CUNNING: f64 = 0.6;
/// A pact ends by notice (FOREIGN-MINISTRY-REPEATED-GAMES 24-72 h; DIPLOMACY-BETRAYAL P1): cancelled, it still binds
/// this long. Only a cunning leader with a grievance over its gratitude breaks one by surprise.
pub const PACT_NOTICE_HOURS: f64 = 48.0;
/// Arm `minds`, courtship: a leader weighs a pact with another club at most this often, with this many clubs a day.
pub const COURT_EVERY_HOURS: f64 = 24.0 * 7.0;
pub const COURT_CANDIDATES: usize = 2;
/// Arm `cycles` (SOCIAL-PLAN 6 Nov; Ibn Khaldun's asabiyya, Turchin's elite overproduction): cohesion lost per day of
/// peace (about a hundred days from founding to none, the "three generations" of a season), gained per day of war.
pub const ASABIYYA_PEACE_DECAY: f64 = 0.01;
pub const ASABIYYA_WAR_GAIN: f64 = 0.02;
/// Under this cohesion a club can split (when too many ambitious members compete for its top) or be overrun by nomads.
pub const ASABIYYA_SPLIT: f64 = 0.4;
/// Elite overproduction: the share of ambitious members (ambition over COUP_AMBITION) above which a club splits.
pub const ELITE_SHARE: f64 = 0.15;
/// A nomad uprising: a charismatic independent (ambition + dominance + courage over this) gathers this many independents,
/// at most once per UPRISING_EVERY_HOURS in the world.
pub const CHARISMA: f64 = 2.0;
pub const UPRISING_BAND: usize = 10;
pub const UPRISING_EVERY_HOURS: f64 = 24.0 * 30.0;
/// The ruler a band rises against leads the second club by at least this factor.
pub const RULER_LEAD: f64 = 1.5;
/// A band gathers one in this many free-spirited independents.
pub const UPRISING_SHARE: usize = 4;
pub const SCHISM_MIN_MEMBERS: usize = 6;
pub const REBEL_FREEDOM: f64 = 0.6;
pub const FOLLOWER_LOYALTY: f64 = 0.5;
pub const RIDER_FREEDOM: f64 = 0.5;

/// Arm `minds`, ambition: a war of conquest lasts at least this long.
pub const CONQUEST_HOURS: f64 = 24.0 * 7.0;
/// Per member per day, the chance a secret slips, times its disloyalty (1 - loyalty).
pub const LEAK_PER_MEMBER_DAY: f64 = 0.002;
/// Per victim member per day, the chance a hidden betrayal is told, times its vindictiveness.
pub const TELL_PER_VICTIM_DAY: f64 = 0.02;

fn key(a: usize, b: usize) -> (usize, usize) { (a.min(b), a.max(b)) }

/// The world as diplomacy reads it at one pass.
pub struct View<'a> {
    pub hour: f64,
    /// Each account's club now (None outside one).
    pub club: &'a [Option<usize>],
    /// Each account's public points.
    pub points: &'a [f64],
    /// Whether each account's leader-taste draw betrays (alliance-ambition.yaml, fixed per account).
    pub betrays: &'a [bool],
}

impl Relations {
    pub fn new(on: bool) -> Relations { Relations { on, ..Relations::default() } }

    /// Draws each account's betrayal taste from its persona, on a stream of its own so the season's other draws stay put.
    pub fn draw_betrayers(&mut self, personas: &[&str], seed: u64) {
        self.betrays = personas.iter().enumerate().map(|(a, p)| {
            let mut x = seed ^ (a as u64).wrapping_mul(0x9e37_79b9_7f4a_7c15) ^ 0xb7e1_5162_8aed_2a6b;
            x = (x ^ (x >> 30)).wrapping_mul(0xbf58_476d_1ce4_e5b9);
            x = (x ^ (x >> 27)).wrapping_mul(0x94d0_49bb_1331_11eb);
            x ^= x >> 31;
            ((x >> 11) as f64 / (1u64 << 53) as f64) < betrays_share(p)
        }).collect();
    }

    /// A hostile hit landed at `hour`: the victim's combat report names the attacker.
    pub fn hit(&mut self, attacker: usize, victim: usize, hour: f64) {
        if !self.on || attacker == victim { return }
        self.minds.saw(victim, "attacked", attacker, hour, -1.0, 0.5);
        let t = self.threat.entry((victim, attacker)).or_insert((0.0, hour));
        *t = ((t.0 + HIT_THREAT).min(1.0), hour);
    }

    pub fn stance(&self, a: usize, b: usize) -> Option<Stance> {
        if a == b { None } else { self.open.get(&key(a, b)).map(|o| o.stance) }
    }
    pub fn at_war(&self, a: usize, b: usize) -> bool { self.stance(a, b) == Some(Stance::War) }
    pub fn pact(&self, a: usize, b: usize) -> bool { self.stance(a, b) == Some(Stance::Pact) }
    /// Whether clubs a and b leave each other alone: a pact, or vassal and overlord.
    pub fn bound(&self, a: usize, b: usize) -> bool {
        self.pact(a, b) || self.tribute.get(&a).is_some_and(|t| t.0 == b) || self.tribute.get(&b).is_some_and(|t| t.0 == a)
    }

    /// Whether the pact between a and b is under notice (cancelled, not yet ended).
    pub fn in_notice(&self, a: usize, b: usize) -> bool { self.open.get(&key(a, b)).is_some_and(|o| o.notice.is_some()) }

    /// A leader ends its pact with partner p. By notice the pact keeps binding PACT_NOTICE_HOURS, a cancel that costs
    /// no reputation (a secret pact's cancel is a private notice to the partner). By surprise war opens at once; a
    /// public break is on the breaker's record, a secret one is known to its victim until it is told.
    fn end_pact(&mut self, hour: f64, c: usize, p: usize, why: &'static str, surprise: bool, victims: &[usize]) {
        if !surprise {
            if let Some(o) = self.open.get_mut(&key(c, p)) { o.notice = Some(Notice { ends: hour + PACT_NOTICE_HOURS, by: c }) }
            self.log.push(Event { hour, kind: "pact_cancel", by: c, with: p, cause: why });
            return;
        }
        let secret = self.open.get(&key(c, p)).is_some_and(|o| o.secret);
        self.set(hour, "betrayal", c, p, Some(Stance::War), "surprise");
        if secret { self.known.insert((p, c)); self.hidden.push((c, p)) } else { *self.broken.entry(c).or_default() += 1 }
        if let Some((personas, t)) = self.persona.as_mut() { for &m in victims { personas.experience(&mut t[m], "betrayed") } }
    }

    fn counterparts(&self, c: usize, s: Stance) -> Vec<usize> {
        self.open.iter().filter(|(_, o)| o.stance == s).filter_map(|(&(x, y), _)| if x == c { Some(y) } else if y == c { Some(x) } else { None }).collect()
    }

    fn threat_now(&self, victim: usize, attacker: usize, hour: f64) -> f64 {
        self.threat.get(&(victim, attacker)).map_or(0.0, |&(v, last)| {
            let idle = (hour - last - GRACE_HOURS).max(0.0);
            let v = v * 0.5f64.powf(idle / HALF_LIFE_HOURS);
            if v < FLOOR { 0.0 } else { v }
        })
    }

    fn set(&mut self, hour: f64, kind: &'static str, by: usize, with: usize, stance: Option<Stance>, cause: &'static str) {
        match stance {
            Some(s) => { self.open.insert(key(by, with), Open { stance: s, since: hour, cause, secret: false, notice: None }); }
            None => { self.open.remove(&key(by, with)); }
        }
        self.log.push(Event { hour, kind, by, with, cause });
    }

    /// One politics pass.
    pub fn pass(&mut self, v: &View) {
        if !self.on { return }
        let hour = v.hour;
        let seen_from = self.log.len();
        self.threat.retain(|&(victim, attacker), _| victim != attacker);
        let mut members: BTreeMap<usize, Vec<usize>> = BTreeMap::new();
        for (a, c) in v.club.iter().enumerate() { if let Some(c) = c { members.entry(*c).or_default().push(a) } }
        // A club that has no members today loses its stances and offers.
        self.open.retain(|&(x, y), _| members.contains_key(&x) && members.contains_key(&y));
        self.offered.retain(|&(x, y)| members.contains_key(&x) && members.contains_key(&y));
        // A notice that has run out ends the pact: the club that cancelled opens the war it gave notice of.
        let ended: Vec<(usize, usize)> = self.open.iter().filter_map(|(&(x, y), o)| o.notice.filter(|n| n.ends <= hour).map(|n| (n.by, if n.by == x { y } else { x }))).collect();
        for (by, with) in ended { self.set(hour, "war", by, with, Some(Stance::War), "betrayal") }
        let points: BTreeMap<usize, f64> = members.iter().map(|(&c, m)| (c, m.iter().map(|&a| v.points[a]).sum())).collect();
        // The leader: the strongest member, as the founder of a club its members grew around.
        let strongest: BTreeMap<usize, usize> = members.iter().map(|(&c, m)| (c, *m.iter().max_by(|&&x, &&y| v.points[x].total_cmp(&v.points[y]).then(y.cmp(&x))).unwrap())).collect();
        let leader: BTreeMap<usize, usize> = if self.persona.is_none() { strongest } else {
            // Governance: a leader stands until it leaves (succession by the strongest) or a coup unseats it.
            self.leaders.retain(|c, l| members.get(c).is_some_and(|m| m.contains(l)));
            for (&c, &s) in &strongest {
                if !self.leaders.contains_key(&c) {
                    if self.log.iter().any(|e| e.kind == "founded" && e.by == c) { self.log.push(Event { hour, kind: "succession", by: c, with: s, cause: "left" }) }
                    else { self.log.push(Event { hour, kind: "founded", by: c, with: s, cause: "strongest" }) }
                    self.leaders.insert(c, s);
                    // A new leader gets a settling-in time before anyone plots (the coup cooldown as tenure).
                    self.plotted.insert(c, hour);
                }
            }
            let (_, t) = self.persona.as_ref().unwrap();
            let trait_at = |a: usize, n: &str| crate::persona::t(&t[a], n);
            let mut coups = Vec::new();
            for (&c, m) in &members {
                let l = self.leaders[&c];
                if m.len() < 3 || self.plotted.get(&c).is_some_and(|&h| hour - h < COUP_COOLDOWN_HOURS) { continue }
                let Some(&plotter) = m.iter().filter(|&&a| a != l && trait_at(a, "ambition") > COUP_AMBITION && v.points[a] > COUP_POINTS_RATIO * v.points[l].max(1.0))
                    .max_by(|&&x, &&y| (trait_at(x, "ambition") + trait_at(x, "dominance")).total_cmp(&(trait_at(y, "ambition") + trait_at(y, "dominance")))) else { continue };
                let others: Vec<usize> = m.iter().copied().filter(|&a| a != l && a != plotter).collect();
                let respect = others.iter().map(|&a| trait_at(a, "authority")).sum::<f64>() / others.len().max(1) as f64;
                coups.push((c, l, plotter, respect < COUP_AUTHORITY));
            }
            for (c, l, plotter, won) in coups {
                self.plotted.insert(c, hour);
                if won { self.leaders.insert(c, plotter); }
                self.log.push(Event { hour, kind: if won { "coup" } else { "coup_failed" }, by: plotter, with: l, cause: "ambition" });
                if let Some((personas, t)) = self.persona.as_mut() {
                    personas.experience(&mut t[if won { l } else { plotter }], "defeat");
                    personas.experience(&mut t[if won { plotter } else { l }], "victory");
                }
            }
            self.leaders.clone()
        };
        // A club's grievance is its members' threats toward the other's, read per REFERENCE_MEMBERS of its own size: two
        // raids anger a club of six, not a bloc of a hundred and sixty (alliance-war.yaml reference_members).
        let grievance = |s: &Self, from: usize, toward: usize| -> f64 {
            let sum: f64 = members[&from].iter().flat_map(|&x| members[&toward].iter().map(move |&y| (x, y))).map(|(x, y)| s.threat_now(x, y, hour)).sum();
            sum / (members[&from].len() as f64 / REFERENCE_MEMBERS).max(1.0)
        };
        let hates = |s: &Self, player: usize, club: usize| members[&club].iter().any(|&y| s.threat_now(player, y, hour) >= ENEMY_THREAT);
        // Arm `persona`: the leader's traits scale the module's lines (1.0 with no persona, so the PHP rules stand).
        let trait_of = |s: &Self, club: usize, name: &str| s.persona.as_ref().map(|(_, t)| crate::persona::t(&t[leader[&club]], name));
        let war_scale = |s: &Self, c: usize| trait_of(s, c, "vindictiveness").map_or(1.0, |v| 1.4 - 0.8 * v);
        let odds_scale = |s: &Self, c: usize| trait_of(s, c, "courage").map_or(1.0, |v| 0.5 + v);
        let peace_scale = |s: &Self, c: usize| trait_of(s, c, "forgiveness").map_or(1.0, |v| 0.5 + v);
        let clubs: Vec<usize> = members.keys().copied().collect();

        // Leaks: a secret pact slips through a disloyal member; a hidden betrayal is told by a vindictive victim.
        if let Some((personas, t)) = self.persona.as_mut() {
            let mut unit = |x: &mut u64| { *x ^= *x << 13; *x ^= *x >> 7; *x ^= *x << 17; (*x >> 11) as f64 / (1u64 << 53) as f64 };
            let keep = |p: f64, m: &[usize], f: &dyn Fn(usize) -> f64| p * m.iter().map(|&a| 1.0 - f(a)).product::<f64>();
            let pacts: Vec<(usize, usize)> = self.open.iter().filter(|(_, o)| o.secret).map(|(&k, _)| k).collect();
            for (x, y) in pacts {
                let stay = keep(keep(1.0, &members[&x], &|a| LEAK_PER_MEMBER_DAY * (1.0 - crate::persona::t(&t[a], "loyalty"))), &members[&y], &|a| LEAK_PER_MEMBER_DAY * (1.0 - crate::persona::t(&t[a], "loyalty")));
                if unit(&mut self.rng) > stay {
                    self.open.get_mut(&(x, y)).unwrap().secret = false;
                    self.log.push(Event { hour, kind: "leak", by: x, with: y, cause: "pact" });
                }
            }
            let mut told = Vec::new();
            for (i, &(_, victim)) in self.hidden.iter().enumerate() {
                let Some(m) = members.get(&victim) else { continue };
                if unit(&mut self.rng) > keep(1.0, m, &|a| TELL_PER_VICTIM_DAY * crate::persona::t(&t[a], "vindictiveness")) { told.push(i) }
            }
            for &i in told.iter().rev() {
                let (breaker, victim) = self.hidden.remove(i);
                *self.broken.entry(breaker).or_default() += 1;
                self.log.push(Event { hour, kind: "leak", by: victim, with: breaker, cause: "betrayal" });
                for &a in members.get(&breaker).map(Vec::as_slice).unwrap_or(&[]) { personas.experience(&mut t[a], "betrayal_exposed") }
            }
        }
        // Vassalage: a vassal revolts once it has grown; an outmatched club still being hit buys peace with tribute.
        if self.persona.is_some() {
            self.tribute.retain(|v, t| members.contains_key(v) && members.contains_key(&t.0));
            let bonds: Vec<(usize, usize)> = self.tribute.iter().map(|(&v, &(o, _))| (v, o)).collect();
            for (v, o) in bonds {
                if points[&v] >= REVOLT_RATIO * points[&o] {
                    self.tribute.remove(&v);
                    self.set(hour, "revolt", v, o, Some(Stance::War), "revolt");
                }
            }
            let wars: Vec<(usize, usize)> = self.open.iter().filter(|(_, o)| o.stance == Stance::War).map(|(&k, _)| k).collect();
            for (x, y) in wars {
                let (weak, strong) = if points[&x] < points[&y] { (x, y) } else { (y, x) };
                if self.tribute.contains_key(&weak) || self.tribute.contains_key(&strong) { continue }
                // Losing the war: outweighed (the betrayal ratio) and taking at least twice the harm it deals.
                let taken = grievance(self, weak, strong);
                let crushed = points[&strong] >= BETRAY_STRENGTH_RATIO * points[&weak].max(1.0) && taken >= WAR_GRIEVANCE && taken >= 2.0 * grievance(self, strong, weak);
                if crushed && trait_of(self, weak, "freedom").is_some_and(|f| f < SUBMIT_FREEDOM) && trait_of(self, strong, "dominance").is_some_and(|d| d > OVERLORD_DOMINANCE) {
                    self.set(hour, "submit", weak, strong, None, "tribute");
                    self.tribute.insert(weak, (strong, hour));
                    if let Some((personas, t)) = self.persona.as_mut() { for &m in &members[&weak] { personas.experience(&mut t[m], "dominated") } }
                }
            }
        }
        // Phase D league: the probe club is the first club holding a pact partner (a club without one never meets the
        // partner choice). Worlds are identical until the variant first acts, so every variant picks the same club.
        if self.probe.is_some() {
            if self.probe_club.is_none() { self.probe_club = members.keys().copied().find(|&c| !self.counterparts(c, Stance::Pact).is_empty()) }
            let total: f64 = points.values().sum();
            self.probe_share = self.probe_club.and_then(|c| points.get(&c)).map_or(0.0, |p| p / total.max(1.0));
        }
        // Whether club c is the probe club playing variant v (hawk, dove, courtier, hermit, tributer override one family).
        let probe = self.probe_club.zip(self.probe.clone());
        let is = move |c: usize, v: &str| probe.as_ref().is_some_and(|(pc, pv)| *pc == c && pv == v);
        // Peace.
        let wars: Vec<((usize, usize), f64, &'static str)> = self.open.iter().filter(|(_, o)| o.stance == Stance::War).map(|(&k, o)| (k, o.since, o.cause)).collect();
        for ((x, y), since, cause) in wars {
            let line = PEACE_GRIEVANCE * peace_scale(self, x).min(peace_scale(self, y));
            // A war joined in defence of a partner lasts while some partner of the defender still fights that enemy.
            let defending = |s: &Self, d: usize, e: usize| s.counterparts(d, Stance::Pact).into_iter().any(|p| s.at_war(p, e));
            let held = cause.starts_with("defend") && (defending(self, x, y) || defending(self, y, x));
            // A war of conquest is a campaign: it runs at least CONQUEST_HOURS before the grievance line may end it.
            let minimum = if cause == "conquest" || cause == "refused-tribute" { CONQUEST_HOURS } else { WAR_MINIMUM_HOURS };
            // A dove sets its own grievance aside: peace needs only the enemy's consent (its grievance under the line).
            let felt = if is(x, "dove") { grievance(self, y, x) } else if is(y, "dove") { grievance(self, x, y) } else { grievance(self, x, y) + grievance(self, y, x) };
            if cause != "coalition" && !held && hour - since >= minimum && felt < line {
                self.set(hour, "peace", x, y, None, cause);
            }
        }
        // Conspiracy.
        let total: f64 = points.values().sum();
        let mut ranked = clubs.clone();
        ranked.sort_by(|x, y| points[y].total_cmp(&points[x]).then(x.cmp(y)));
        if ranked.len() >= 3 && points[&ranked[0]] > COALITION_SHARE * total {
            let (top, first, second) = (ranked[0], ranked[1], ranked[2]);
            let done = self.at_war(first, top) && self.at_war(second, top);
            if !done && !self.at_war(first, second) && !hates(self, leader[&first], second) && !hates(self, leader[&second], first) {
                if self.stance(first, second).is_none() && !is(first, "hermit") && !is(second, "hermit") { self.set(hour, "pact", first, second, Some(Stance::Pact), "coalition") }
                for p in [first, second] { if !self.at_war(p, top) && !is(p, "dove") { self.set(hour, "war", p, top, Some(Stance::War), "coalition") } }
            }
        }
        if self.cycles && self.persona.is_some() {
            let ready: Vec<(usize, usize)> = self.uprisings.iter().copied().filter(|(b, t)| members.contains_key(b) && members.contains_key(t)).collect();
            self.uprisings.retain(|(b, _)| !members.contains_key(b));
            for (b, t) in ready { if self.stance(b, t).is_none() { self.set(hour, "war", b, t, Some(Stance::War), "uprising") } }
            self.cycle(hour, v, &members, &points, &leader);
        }
        let mut inside: BTreeMap<(usize, usize), serde_json::Value> = BTreeMap::new();
        let mut feel: BTreeMap<(usize, usize), String> = BTreeMap::new();
        if self.minds.on {
            let pairs: Vec<(usize, usize)> = self.open.iter().filter(|(_, o)| o.stance == Stance::Pact).flat_map(|(&(x, y), _)| [(x, y), (y, x)]).collect();
            let questions: Vec<(usize, Vec<usize>, usize, f64)> = pairs.iter().map(|&(c, p)| (leader[&c], members[&p].clone(), leader[&p], points[&p] / points[&c].max(1.0))).collect();
            for (pair, answer) in pairs.iter().copied().zip(self.ask_partners(&questions)) { if let Some(a) = answer { inside.insert(pair, a); } }
            // FAtiMA: what the leader's emotions and relations move it to do toward each partner.
            let asks: Vec<(usize, usize, serde_json::Value, Vec<&str>)> = pairs.iter().map(|&(c, p)| (leader[&c], leader[&p],
                serde_json::json!({"pact": true, "ally_in_danger": self.counterparts(p, Stance::War).iter().any(|&e| e != c), "power_ratio": points[&c] / points[&p].max(1.0)}),
                vec!["Ignore", "Gift", "DefendAlly", "Betray"])).collect();
            let personas: BTreeMap<usize, serde_json::Value> = asks.iter().map(|q| (q.0, self.persona_json(q.0))).collect();
            for (pair, choice) in pairs.iter().copied().zip(self.minds.exchange(&asks, hour, &|a| personas.get(&a).cloned().unwrap_or_default())) {
                if let Some(choice) = choice { feel.insert(pair, choice); }
            }
        }
        // Arm `minds`, courtship: each leader looks at the clubs it remembers best (AgentOS gratitude over grievance) and
        // at its enemies' enemies; FAtiMA says whether it feels like offering a pact, CBRKit recalls how similar pacts
        // ended (most betrayed: hold back), and the other leader's PsychSim decides whether to accept.
        if self.minds.on {
            let mut offers: Vec<(usize, usize)> = Vec::new();
            for &c in clubs.iter().filter(|&&c| !is(c, "hermit")) {
                let l = leader[&c];
                let enemies = self.counterparts(c, Stance::War);
                let mut scored: Vec<(f64, usize)> = clubs.iter().copied().filter(|&o| o != c && self.stance(c, o).is_none() && !self.bound(c, o)
                    && self.courted.get(&(c, o)).is_none_or(|&h| hour - h >= COURT_EVERY_HOURS)).map(|o| {
                    let (g, t) = members[&o].iter().filter_map(|m| self.minds.minds[l].recalled.get(m)).fold((0.0, 0.0), |(g, t), r| (g + r.grievance, t + r.gratitude));
                    let common = enemies.iter().any(|&e| self.at_war(o, e));
                    (t - g + if common { 1.0 } else { 0.0 }, o)
                }).filter(|&(score, _)| score > 0.0 || is(c, "courtier")).collect();
                scored.sort_by(|x, y| y.0.total_cmp(&x.0));
                offers.extend(scored.into_iter().take(COURT_CANDIDATES).map(|(_, o)| (c, o)));
            }
            for &(c, o) in &offers { self.courted.insert((c, o), hour); }
            let asks: Vec<(usize, usize, serde_json::Value, Vec<&str>)> = offers.iter().map(|&(c, o)| (leader[&c], leader[&o],
                serde_json::json!({"pact": false, "power_ratio": points[&c] / points[&o].max(1.0)}), vec!["Ignore", "OfferPact", "Threaten"])).collect();
            let personas: BTreeMap<usize, serde_json::Value> = asks.iter().map(|q| (q.0, self.persona_json(q.0))).collect();
            let wanted: Vec<(usize, usize)> = offers.iter().copied().zip(self.minds.exchange(&asks, hour, &|a| personas.get(&a).cloned().unwrap_or_default()))
                .filter(|((c, _), choice)| is(*c, "courtier") || choice.as_deref() == Some("OfferPact")).map(|(pair, _)| pair).collect();
            // CBRKit: pacts in situations like this one (the other's public record, the pair's past wars).
            let pair_wars = |s: &Self, c: usize, o: usize| s.log.iter().filter(|e| e.kind == "war" && ((e.by == c && e.with == o) || (e.by == o && e.with == c))).count();
            let items: Vec<serde_json::Value> = wanted.iter().map(|&(c, o)| serde_json::json!({"engine": "cbrkit", "route": "/social/similar", "body": {"items": [{
                "situation": {"decision": "pact", "their_broken": self.broken.get(&o).copied().unwrap_or(0), "my_broken": self.broken.get(&c).copied().unwrap_or(0), "pair_wars": pair_wars(self, c, o)}, "k": 10}]}})).collect();
            let cases = self.minds.batch(items);
            let wary = |a: &serde_json::Value| a.get("results").and_then(|r| r.get(0)).and_then(|r| r.get("cases")).and_then(serde_json::Value::as_array).is_some_and(|cs| {
                let betrayed = cs.iter().filter(|x| x.pointer("/outcome/label").and_then(serde_json::Value::as_str) == Some("betrayed")).count();
                !cs.is_empty() && 2 * betrayed > cs.len()
            });
            let proposed: Vec<(usize, usize)> = wanted.into_iter().zip(cases.iter()).filter(|((c, _), a)| is(*c, "courtier") || !wary(a)).map(|(pair, _)| pair).collect();
            let questions: Vec<(usize, Vec<usize>, usize, f64)> = proposed.iter().map(|&(c, o)| (leader[&o], members[&c].clone(), leader[&c], points[&c] / points[&o].max(1.0))).collect();
            for ((c, o), answer) in proposed.into_iter().zip(self.ask_partners(&questions)) {
                if self.stance(c, o).is_some() { continue }
                let accepts = is(o, "courtier") || answer.as_ref().and_then(|a| a.get("accept_pact")).and_then(serde_json::Value::as_bool) == Some(true);
                if accepts && !is(o, "hermit") {
                    self.set(hour, "pact", c, o, Some(Stance::Pact), "courtship");
                    if trait_of(self, c, "cunning").unwrap_or(0.0).max(trait_of(self, o, "cunning").unwrap_or(0.0)) > SECRET_CUNNING {
                        if let Some(x) = self.open.get_mut(&key(c, o)) { x.secret = true }
                        if let Some(e) = self.log.last_mut() { e.cause = "secret" }
                    }
                } else {
                    self.log.push(Event { hour, kind: "pact_refused", by: o, with: c, cause: "distrust" });
                }
            }
        }
        // Arm `minds`, ambition: once a week a leader at peace eyes the weakest club it is not bound to; FAtiMA says
        // whether it threatens or demands tribute (dominance, ambition, its feelings), and the target's FAtiMA whether it
        // submits or resists. Submission makes a vassal; resistance (or a bare threat) a war of conquest.
        if self.minds.on && (hour / 24.0).round() as i64 % 7 == 0 {
            let eyed: Vec<(usize, usize)> = clubs.iter().copied().filter(|&c| self.counterparts(c, Stance::War).is_empty() && !self.tribute.contains_key(&c) && !is(c, "dove")).flat_map(|c| {
                let mut weaker: Vec<usize> = clubs.iter().copied().filter(|&o| o != c && !self.bound(c, o) && self.stance(c, o).is_none() && points[&o] * 2.0 < points[&c]).collect();
                weaker.sort_by(|x, y| points[x].total_cmp(&points[y]));
                // A tributer eyes every weaker club, the others only the weakest.
                weaker.truncate(if is(c, "tributer") { usize::MAX } else { 1 });
                weaker.into_iter().map(move |o| (c, o))
            }).collect();
            let asks: Vec<(usize, usize, serde_json::Value, Vec<&str>)> = eyed.iter().map(|&(c, o)| (leader[&c], leader[&o],
                serde_json::json!({"pact": false, "power_ratio": points[&c] / points[&o].max(1.0)}), vec!["Ignore", "Threaten", "DemandTribute"])).collect();
            let personas: BTreeMap<usize, serde_json::Value> = asks.iter().map(|q| (q.0, self.persona_json(q.0))).collect();
            let moves: Vec<((usize, usize), String)> = eyed.into_iter().zip(self.minds.exchange(&asks, hour, &|a| personas.get(&a).cloned().unwrap_or_default()))
                .filter_map(|(pair, choice)| if is(pair.0, "tributer") { Some("DemandTribute".to_string()) } else { choice.filter(|x| x != "Ignore") }.map(|x| (pair, x))).collect();
            let answers: Vec<(usize, usize, serde_json::Value, Vec<&str>)> = moves.iter().filter(|(_, m)| m == "DemandTribute").map(|&((c, o), _)| (leader[&o], leader[&c],
                serde_json::json!({"demand": true, "power_ratio": points[&o] / points[&c].max(1.0)}), vec!["Submit", "Revolt", "Ignore"])).collect();
            let personas: BTreeMap<usize, serde_json::Value> = answers.iter().map(|q| (q.0, self.persona_json(q.0))).collect();
            let mut replies = self.minds.exchange(&answers, hour, &|a| personas.get(&a).cloned().unwrap_or_default()).into_iter();
            for ((c, o), m) in moves {
                // Each demand's reply is taken before any skip, so the replies stay matched to their demands.
                let reply = if m == "DemandTribute" { replies.next().flatten() } else { None };
                if self.stance(c, o).is_some() || self.tribute.contains_key(&o) { continue }
                let submits = reply.as_deref() == Some("Submit");
                if submits {
                    self.log.push(Event { hour, kind: "demand", by: c, with: o, cause: "tribute" });
                    self.set(hour, "submit", o, c, None, "demand");
                    self.tribute.insert(o, (c, hour));
                } else if self.counterparts(c, Stance::War).len() < MAX_OPEN_WARS {
                    self.set(hour, "war", c, o, Some(Stance::War), if m == "DemandTribute" { "refused-tribute" } else { "conquest" });
                }
            }
        }
        // A hawk at peace opens a conquest war on the weakest club it is free to fight, every pass.
        for &c in clubs.iter().filter(|&&c| is(c, "hawk")) {
            if !self.counterparts(c, Stance::War).is_empty() || self.tribute.contains_key(&c) { continue }
            if let Some(o) = clubs.iter().copied().filter(|&o| o != c && !self.bound(c, o) && self.stance(c, o).is_none() && points[&o] <= WAR_MAX_STRENGTH_RATIO * points[&c].max(1.0))
                .min_by(|x, y| points[x].total_cmp(&points[y])) { self.set(hour, "war", c, o, Some(Stance::War), "conquest") }
        }
        for &c in &clubs {
            // Declare war (a dove declares none: its wars are declared on it, or joined for a partner).
            if self.counterparts(c, Stance::War).len() < MAX_OPEN_WARS && !is(c, "dove") {
                let partners = self.counterparts(c, Stance::Pact);
                let ally_factor = |s: &Self, enemy: usize| if partners.iter().any(|&p| s.at_war(p, enemy)) { ALLY_WAR_FACTOR } else { 1.0 };
                let outmatched = |s: &Self, enemy: usize| points[&enemy] > WAR_MAX_STRENGTH_RATIO * odds_scale(s, c) * points[&c].max(1.0) && ally_factor(s, enemy) >= 1.0;
                let best = clubs.iter().copied().filter(|&o| o != c && !outmatched(self, o) && self.stance(c, o) != Some(Stance::War))
                    .map(|o| (o, grievance(self, c, o)))
                    .filter(|&(o, g)| g >= WAR_GRIEVANCE * war_scale(self, c) * ally_factor(self, o) && g >= grievance(self, o, c) && !self.bound(c, o))
                    .max_by(|x, y| x.1.total_cmp(&y.1));
                if let Some((enemy, _)) = best { self.set(hour, "war", c, enemy, Some(Stance::War), "grievance") }
            }
            // Offer a pact: whoever fights the same enemy, then (at war) a third club it trades hits with.
            let wars = self.counterparts(c, Stance::War);
            let feared: Vec<usize> = clubs.iter().copied().filter(|&o| o != c && points[&o] > WAR_MAX_STRENGTH_RATIO * points[&c].max(1.0) && grievance(self, c, o) >= WAR_GRIEVANCE).collect();
            let mut candidates: Vec<(usize, usize, &'static str)> = Vec::new();
            for &enemy in wars.iter().chain(&feared) {
                for p in self.counterparts(enemy, Stance::War) { candidates.push((enemy, p, "common-enemy")) }
            }
            if let Some(&front) = wars.first() {
                for &o in clubs.iter().filter(|&&o| o != c && !wars.contains(&o)) {
                    if grievance(self, c, o) + grievance(self, o, c) >= PEACE_GRIEVANCE { candidates.push((front, o, "flank")) }
                }
            }
            let feud = |s: &Self, holder: usize, other: usize, enemy: usize| (hates(s, leader[&holder], other) && grievance(s, holder, other) >= grievance(s, holder, enemy))
                // Reputation: a leader who does not forgive refuses a club on record for breaking a pact.
                || trait_of(s, holder, "forgiveness").is_some_and(|f| f < 0.5 && (s.broken.get(&other).copied().unwrap_or(0) > 0 || s.known.contains(&(holder, other))));
            for (enemy, partner, cause) in candidates {
                if is(c, "hermit") { break }
                if partner == c || self.stance(c, partner).is_some() || (feud(self, c, partner, enemy) && !is(c, "courtier")) || self.offered.contains(&(c, partner)) { continue }
                self.offered.push((c, partner));
                // Arm `minds`: the partner's leader asks the engine whether to trust the offerer.
                let distrust = self.minds.on && {
                    let q = [(leader[&partner], members[&c].clone(), leader[&c], points[&c] / points[&partner].max(1.0))];
                    self.ask_partners(&q).pop().flatten().is_some_and(|a| a.get("accept_pact") == Some(&serde_json::Value::Bool(false)))
                };
                if is(partner, "hermit") || ((feud(self, partner, c, enemy) || distrust) && !is(partner, "courtier")) {
                    self.log.push(Event { hour, kind: "pact_refused", by: partner, with: c, cause: if is(partner, "hermit") { "hermit" } else if distrust { "distrust" } else { cause } });
                    break
                }
                self.set(hour, "pact", c, partner, Some(Stance::Pact), cause);
                if trait_of(self, c, "cunning").unwrap_or(0.0).max(trait_of(self, partner, "cunning").unwrap_or(0.0)) > SECRET_CUNNING {
                    if let Some(o) = self.open.get_mut(&key(c, partner)) { o.secret = true }
                    if let Some(e) = self.log.last_mut() { e.cause = "secret" }
                }
                break;
            }
            // Arm `minds`: the engine's act toward each partner. Help is a sacrifice: join a partner's war on its enemy.
            if self.minds.on {
                // A pact under notice is already decided: no second cancel, no help. A notice is a war in waiting, so
                // like a war it holds back any other betrayal.
                let partners: Vec<usize> = self.counterparts(c, Stance::Pact).into_iter().filter(|&p| !self.in_notice(c, p)).collect();
                let quiet = self.counterparts(c, Stance::War).is_empty() && partners.len() == self.counterparts(c, Stance::Pact).len();
                let calc = |p: usize, k: &str| inside.get(&(c, p)).and_then(|a| a.get(k)).and_then(serde_json::Value::as_bool).unwrap_or(false);
                let moved = |p: usize, k: &str| feel.get(&(c, p)).is_some_and(|x| x == k);
                // Calculation (PsychSim: future value) or feeling (FAtiMA: emotions and relations) can move a leader.
                // A felt betrayal is revenge: it needs a grievance the leader still remembers (AgentOS recall) toward the
                // partner's members to outweigh its remembered gratitude; a felt defence needs the gratitude.
                let ledgers: BTreeMap<usize, (f64, f64)> = partners.iter().map(|&p| (p, members[&p].iter().filter_map(|m| self.minds.minds[leader[&c]].recalled.get(m)).fold((0.0, 0.0), |(g, t), r| (g + r.grievance, t + r.gratitude)))).collect();
                let ledger = |p: usize| ledgers.get(&p).copied().unwrap_or((0.0, 0.0));
                let variant = if self.probe_club == Some(c) { self.probe.clone() } else { None };
                // Phase C data: what the leader saw toward each partner (visible facts and its own carried feelings).
                let l = leader[&c];
                let traits = self.persona_json(l);
                let rows: Vec<(usize, serde_json::Value)> = partners.iter().map(|&p| {
                    let (grievance, gratitude) = ledger(p);
                    let belief = self.minds.minds[l].beliefs.get(&leader[&p]).map_or(0.5, |b| b.p_loyal);
                    // The leader's own feelings toward the partner's leader (FAtiMA state it carries): its own, not hidden.
                    let them = leader[&p].to_string();
                    let fatima = &self.minds.minds[l].fatima;
                    let mut emotions = serde_json::Map::new();
                    for e in fatima.get("emotions").and_then(serde_json::Value::as_array).into_iter().flatten() {
                        if e.get("target").and_then(serde_json::Value::as_str).is_some_and(|t| t == them) {
                            if let (Some(k), Some(v)) = (e.get("type").and_then(serde_json::Value::as_str), e.get("intensity").and_then(serde_json::Value::as_f64)) {
                                let slot = emotions.entry(k.to_string()).or_insert(serde_json::json!(0.0));
                                *slot = serde_json::json!(slot.as_f64().unwrap_or(0.0).max(v));
                            }
                        }
                    }
                    let relation = fatima.get("relations").and_then(|r| r.get(&them)).cloned().unwrap_or_default();
                    (p, serde_json::json!({"hour": hour, "kind": "partner", "club": c, "partner": p,
                        "points_ratio": points[&p] / points[&c].max(1.0), "at_war": !self.counterparts(c, Stance::War).is_empty(),
                        "partner_at_war": self.counterparts(p, Stance::War).iter().any(|&e| e != c),
                        "grievance": grievance, "gratitude": gratitude, "p_loyal": belief, "mood": self.minds.minds[l].mood,
                        "their_broken": self.broken.get(&p).copied().unwrap_or(0), "persona": traits,
                        "emotions": emotions, "relation": relation,
                        "psychsim": inside.get(&(c, p)).and_then(|a| a.get("act_now")).cloned(), "fatima": feel.get(&(c, p)).cloned()}))
                }).collect();
                // Arm `bc`: the imitation tree's choice per partner, read off the very row that is logged (a probe
                // variant on its club still wins: the league compares against it).
                let picked: BTreeMap<usize, String> = match &self.bc {
                    Some(tree) if variant.is_none() => rows.iter().map(|(p, row)| (*p, tree.choose(row).to_string())).collect(),
                    _ => BTreeMap::new(),
                };
                let act = |p: usize, k: &str| if let Some(choice) = picked.get(&p) { choice == if k == "help_now" { "help" } else { "betray" } } else { match variant.as_deref() {
                    Some("keep") => false,
                    Some("loyal") => k == "help_now",
                    Some("betray") => k == "betray_now",
                    Some("dove") if k == "betray_now" => false,
                    _ => calc(p, k) || {
                        let (grievance, gratitude) = ledger(p);
                        if k == "help_now" { moved(p, "DefendAlly") && gratitude >= grievance } else { moved(p, "Betray") && grievance > gratitude }
                    },
                } };
                for (p, mut row) in rows {
                    // The act the leader can take: no betrayal while at war (the betrayal step waits for peace).
                    let choice = if act(p, "betray_now") && quiet { "betray" } else if act(p, "help_now") { "help" } else { "keep" };
                    row["choice"] = serde_json::json!(choice);
                    self.decisions.push(row);
                }
                if self.counterparts(c, Stance::War).len() < MAX_OPEN_WARS {
                    if let Some((p, e)) = partners.iter().filter(|&&p| act(p, "help_now")).find_map(|&p| self.counterparts(p, Stance::War).into_iter().find(|&e| e != c && !self.bound(c, e)).map(|e| (p, e))) {
                        self.set(hour, "war", c, e, Some(Stance::War), if calc(p, "help_now") { "defend" } else { "defend-felt" });
                    }
                }
                if quiet {
                    if let Some(p) = partners.into_iter().find(|&p| act(p, "betray_now")) {
                        let secret = self.open.get(&key(c, p)).is_some_and(|o| o.secret);
                        let why = if secret { "secret" } else if calc(p, "betray_now") { "calculated" } else { "emotional" };
                        // Surprise is revenge with a reason, and only a cunning leader takes it: a grievance it remembers
                        // over its gratitude, from a hit by the partner's members still fresh (DIPLOMACY-BETRAYAL B3).
                        let (remembered, gratitude) = ledger(p);
                        let surprise = trait_of(self, c, "cunning").is_some_and(|x| x >= SECRET_CUNNING) && remembered > gratitude && grievance(self, c, p) > 0.0;
                        self.end_pact(hour, c, p, why, surprise, &members[&p]);
                    }
                }
                continue;
            }
            // Betray.
            let betrays = match &self.persona {
                // Greed (ambition, cunning) against honour (honesty, loyalty): the drive model of part 6.
                Some((_, t)) => {
                    let l = &t[leader[&c]];
                    let greed = 0.5 * crate::persona::t(l, "ambition") + 0.5 * crate::persona::t(l, "cunning");
                    let honour = 0.6 * crate::persona::t(l, "honesty") + 0.4 * crate::persona::t(l, "loyalty");
                    greed - honour > 0.15
                }
                None => v.betrays[leader[&c]],
            };
            let noticing = self.counterparts(c, Stance::Pact).into_iter().any(|p| self.in_notice(c, p));
            if betrays && !is(c, "dove") && self.counterparts(c, Stance::War).is_empty() && !noticing {
                if let Some(p) = self.counterparts(c, Stance::Pact).into_iter().find(|&p| !self.in_notice(c, p) && points[&c] >= BETRAY_STRENGTH_RATIO * points[&p].max(1.0)) {
                    let secret = self.open.get(&key(c, p)).is_some_and(|o| o.secret);
                    // Without minds the remembered grievance is the club's standing grudge (no gratitude is kept).
                    let surprise = trait_of(self, c, "cunning").is_some_and(|x| x >= SECRET_CUNNING) && grievance(self, c, p) > 0.0;
                    self.end_pact(hour, c, p, if secret { "secret" } else { "betrayal" }, surprise, &members[&p]);
                }
            }
        }
        if self.minds.on {
            self.remember(seen_from, &members, &leader);
            // FAtiMA: each leader feels what it saw today (its emotions, mood and relations carry to tomorrow).
            let leaders: Vec<usize> = leader.values().copied().collect();
            let personas: BTreeMap<usize, serde_json::Value> = leaders.iter().map(|&a| (a, self.persona_json(a))).collect();
            self.minds.feel(&leaders, hour, &|a| personas.get(&a).cloned().unwrap_or_default());
            // Persona -> AgentOS's HEXACO: vindictive minds encode harm stronger, forgiving ones reappraise faster.
            let persona = self.persona.as_ref().map(|(_, t)| t.clone());
            let traits = move |a: usize| persona.as_ref().map(|t| {
                let x = |n: &str| crate::persona::t(&t[a], n);
                serde_json::json!({"emotionality": x("vindictiveness"), "agreeableness": x("forgiveness"), "honesty": x("honesty"),
                                   "conscientiousness": x("loyalty"), "extraversion": x("dominance"), "openness": 0.5})
            });
            self.minds.recall(hour, &traits);
        }
    }

    /// Arm `cycles`: cohesion wears in peace and grows in war; a decayed club with too many ambitious members splits
    /// (the strongest ambitious member who feels like revolting leaves with the disloyal), and a decayed leading club
    /// can be overrun by a band of independents gathered by a charismatic one (Ibn Khaldun's nomads).
    fn cycle(&mut self, hour: f64, v: &View, members: &BTreeMap<usize, Vec<usize>>, points: &BTreeMap<usize, f64>, leader: &BTreeMap<usize, usize>) {
        let t = |s: &Self, a: usize, n: &str| s.persona.as_ref().map_or(0.5, |(_, t)| crate::persona::t(&t[a], n));
        let fresh_id = |s: &Self| members.keys().chain(s.pinned.values()).copied().max().unwrap_or(0).max(v.club.len()) + 1;
        for &c in members.keys() {
            let at_war = !self.counterparts(c, Stance::War).is_empty();
            let a = self.asabiyya.entry(c).or_insert(1.0);
            *a = if at_war { (*a + ASABIYYA_WAR_GAIN).min(1.0) } else { (*a - ASABIYYA_PEACE_DECAY).max(0.0) };
        }
        // A band or splinter whose cohesion is spent disperses (a group without a cause scatters): its pinned members
        // go back to wherever their standing puts them.
        let spent: Vec<usize> = self.pinned.values().copied().collect::<std::collections::BTreeSet<_>>().into_iter()
            .filter(|c| self.asabiyya.get(c).is_some_and(|&a| a < ASABIYYA_SPLIT) && !members.get(c).is_some_and(|m| m.iter().all(|a| !self.pinned.contains_key(a)))).collect();
        for c in spent {
            self.pinned.retain(|_, club| *club != c);
            self.log.push(Event { hour, kind: "dispersed", by: c, with: c, cause: "asabiyya" });
        }
        // Elite overproduction -> schism.
        for (&c, m) in members {
            if self.asabiyya[&c] >= ASABIYYA_SPLIT || m.len() < SCHISM_MIN_MEMBERS { continue }
            let l = leader[&c];
            let elites: Vec<usize> = m.iter().copied().filter(|&a| a != l && t(self, a, "ambition") > COUP_AMBITION).collect();
            if (elites.len() as f64) < ELITE_SHARE * m.len() as f64 { continue }
            let Some(&head) = elites.iter().max_by(|&&x, &&y| v.points[x].total_cmp(&v.points[y])) else { continue };
            // The would-be founder's own feelings decide (FAtiMA Revolt), or its love of freedom without the engines.
            let revolts = if self.minds.on {
                let q = [(head, l, serde_json::json!({"demand": false, "power_ratio": v.points[head] / v.points[l].max(1.0)}), vec!["Ignore", "Revolt"])];
                let persona = self.persona_json(head);
                self.minds.exchange(&q, hour, &|_| persona.clone()).pop().flatten().as_deref() == Some("Revolt")
            } else { t(self, head, "freedom") > REBEL_FREEDOM };
            if !revolts { continue }
            let new = fresh_id(self);
            let followers: Vec<usize> = m.iter().copied().filter(|&a| a != l && (a == head || t(self, a, "loyalty") < FOLLOWER_LOYALTY)).collect();
            for &a in &followers { self.pinned.insert(a, new); }
            self.asabiyya.insert(new, 1.0);
            self.log.push(Event { hour, kind: "schism", by: c, with: new, cause: "elites" });
        }
        // Nomad uprising against a decayed leading club.
        if hour - self.last_uprising < UPRISING_EVERY_HOURS { return }
        // Nomads rise against a ruling dynasty: the leading club, at least RULER_LEAD times the second, gone soft.
        let mut ranked: Vec<(usize, f64)> = points.iter().map(|(&c, &p)| (c, p)).collect();
        ranked.sort_by(|x, y| y.1.total_cmp(&x.1));
        let Some(&(target, top)) = ranked.first() else { return };
        let second = ranked.get(1).map_or(0.0, |x| x.1);
        if top < RULER_LEAD * second || !self.asabiyya.get(&target).is_some_and(|&a| a < ASABIYYA_SPLIT) { return }
        let nomads: Vec<usize> = (0..v.club.len()).filter(|&a| v.club[a].is_none() && v.points[a] > 0.0 && !self.pinned.contains_key(&a)).collect();
        let charisma = |s: &Self, a: usize| t(s, a, "ambition") + t(s, a, "dominance") + t(s, a, "courage");
        let Some(&chief) = nomads.iter().filter(|&&a| charisma(self, a) > CHARISMA).max_by(|&&x, &&y| v.points[x].total_cmp(&v.points[y])) else { return };
        let mut band: Vec<usize> = nomads.into_iter().filter(|&a| a != chief && t(self, a, "freedom") > RIDER_FREEDOM).collect();
        band.sort_by(|&x, &y| v.points[y].total_cmp(&v.points[x]));
        // A band is a fraction of the free (a few riders, not the whole steppe).
        band.truncate((band.len() / UPRISING_SHARE).clamp(2, UPRISING_BAND - 1));
        band.push(chief);
        let new = fresh_id(self);
        for &a in &band { self.pinned.insert(a, new); }
        self.asabiyya.insert(new, 1.0);
        self.last_uprising = hour;
        self.log.push(Event { hour, kind: "uprising", by: new, with: target, cause: "nomads" });
        // The band rises against the soft giant: its war starts once its members stand in the club (tomorrow).
        self.uprisings.push((new, target));
    }

    /// The persona an engine reads: the account's 12 traits.
    fn persona_json(&self, a: usize) -> serde_json::Value {
        self.persona.as_ref().map(|(_, t)| serde_json::Value::Object(crate::persona::TRAITS.iter().enumerate().map(|(i, n)| (n.to_string(), serde_json::json!(t[a][i]))).collect()))
            .map(|traits| serde_json::json!({"traits": traits})).unwrap_or(serde_json::json!("newcomer"))
    }

    /// Arm `minds`: PsychSim's partner model (`/social/partner`, through the gateway) for each question (asker account,
    /// the partner club's members, the partner club's leader, partner points / own points). The asker's belief and bond
    /// travel in the request and come back updated; only the partner's acts the asker saw since the last question are
    /// sent (its memories with an actor in that club). None when the engine did not answer.
    pub fn ask_partners(&mut self, questions: &[(usize, Vec<usize>, usize, f64)]) -> Vec<Option<serde_json::Value>> {
        use serde_json::json;
        let traits = |s: &Self, a: usize| s.persona.as_ref().map(|(_, t)| {
            let x = |n: &str| crate::persona::t(&t[a], n);
            json!({"loyalty": x("loyalty"), "honesty": x("honesty"), "ambition": x("ambition"), "cunning": x("cunning")})
        }).unwrap_or(json!({}));
        let mut items = Vec::new();
        let mut last_seen = Vec::new();
        for (asker, partners, partner_leader, ratio) in questions {
            let mind = &self.minds.minds[*asker];
            let held = mind.beliefs.get(partner_leader).cloned().unwrap_or_default();
            let since = held.state.get("last").and_then(serde_json::Value::as_u64).unwrap_or(0);
            let acts: Vec<&crate::mind::Memory> = mind.memories.iter().chain(&mind.fresh).filter(|m| m.id > since && partners.contains(&m.actor)).collect();
            let history: Vec<serde_json::Value> = acts.iter().filter_map(|m| {
                let action = match m.event.as_str() { "betrayed" | "betrayal_exposed" => "betrayal", "attacked" | "war_declared" => "attack", "pact" | "peace" => "kept", "submitted" => "tribute", _ => return None };
                Some(json!({"action": action, "t": m.at}))
            }).collect();
            last_seen.push(acts.iter().map(|m| m.id).max().unwrap_or(since));
            // The ratio in quarter steps, so repeated questions hit the gateway's cache.
            let ratio = ((ratio * 4.0).round() / 4.0).clamp(0.25, 8.0);
            let mut body = json!({"points_ratio": ratio, "my_traits": traits(self, *asker), "horizon": 2, "history": history});
            if let Some(b) = held.state.get("belief") { body["belief"] = b.clone() }
            if let Some(b) = held.state.get("bond") { body["bond"] = b.clone() }
            items.push(json!({"engine": "psychsim", "route": "/social/partner", "body": {"items": [body]}}));
        }
        let answers = self.minds.batch(items);
        answers.into_iter().enumerate().map(|(i, a)| {
            let r = a.get("results").and_then(|r| r.get(0)).filter(|r| r.get("error").is_none()).cloned()?;
            let (asker, _, partner_leader, _) = &questions[i];
            let belief = crate::mind::Belief { p_loyal: r.get("p_loyal").and_then(serde_json::Value::as_f64).unwrap_or(0.5),
                state: json!({"belief": r.get("belief").cloned().unwrap_or_default(), "bond": r.get("bond").cloned().unwrap_or_default(), "last": last_seen[i]}) };
            self.minds.minds[*asker].beliefs.insert(*partner_leader, belief);
            Some(r)
        }).collect()
    }

    /// Arm `minds`: today's treaty events into the memories of those who saw them (members of the clubs involved; a
    /// leak reaches every club member). The actor is the other side's leader, whose name is on the act.
    fn remember(&mut self, from: usize, members: &BTreeMap<usize, Vec<usize>>, leader: &BTreeMap<usize, usize>) {
        let events: Vec<Event> = self.log[from..].to_vec();
        let all: Vec<usize> = members.values().flatten().copied().collect();
        for e in events {
            let (Some(&by_leader), with_leader) = (leader.get(&e.by), leader.get(&e.with).copied()) else { continue };
            let mut tell = |clubs: &[usize], event: &str, actor: usize, valence: f64, weight: f64, minds: &mut crate::mind::Minds| {
                for c in clubs { for &m in members.get(c).map(Vec::as_slice).unwrap_or(&[]) { minds.saw(m, event, actor, e.hour, valence, weight) } }
            };
            let w = with_leader.unwrap_or(by_leader);
            match e.kind {
                "war" => { tell(&[e.with], "war_declared", by_leader, -0.6, 0.6, &mut self.minds); tell(&[e.by], "war_declared_on", w, -0.3, 0.4, &mut self.minds) }
                "peace" => { tell(&[e.with], "peace", by_leader, 0.3, 0.3, &mut self.minds); tell(&[e.by], "peace", w, 0.3, 0.3, &mut self.minds) }
                "pact" => { tell(&[e.with], "pact", by_leader, 0.5, 0.5, &mut self.minds); tell(&[e.by], "pact", w, 0.5, 0.5, &mut self.minds) }
                "pact_refused" => tell(&[e.with], "pact_refused", by_leader, -0.2, 0.3, &mut self.minds),
                "betrayal" => tell(&[e.with], "betrayed", by_leader, -1.0, 1.0, &mut self.minds),
                "pact_cancel" => tell(&[e.with], "pact_cancelled", by_leader, -0.4, 0.5, &mut self.minds),
                "submit" => { tell(&[e.by], "dominated", w, -0.7, 0.8, &mut self.minds); tell(&[e.with], "submitted", by_leader, 0.3, 0.4, &mut self.minds) }
                "revolt" => tell(&[e.with], "revolt", by_leader, -0.6, 0.6, &mut self.minds),
                "coup" | "coup_failed" => {
                    // by = plotter account, with = leader account: their club's members saw it.
                    if let Some((&c, _)) = members.iter().find(|(_, m)| m.contains(&e.by)) {
                        let event = if e.kind == "coup" { "coup" } else { "coup_failed" };
                        tell(&[c], event, e.by, -0.3, 0.5, &mut self.minds);
                        self.minds.saw(e.with, if e.kind == "coup" { "overthrown" } else { "plot_foiled" }, e.by, e.hour, -1.0, 1.0);
                    }
                }
                "leak" => {
                    let (event, actor) = if e.cause == "betrayal" { ("betrayal_exposed", w) } else { ("secret_pact_exposed", by_leader) };
                    for &m in &all { self.minds.saw(m, event, actor, e.hour, -0.4, 0.4) }
                }
                _ => {}
            }
        }
    }
}

/// Phase C imitation tree (`social_imitation.py --export`): sklearn `tree_` arrays and the ordered feature names.
#[derive(Clone, Debug, serde::Deserialize)]
pub struct BcTree {
    pub features: Vec<String>,
    pub feature: Vec<i64>,
    pub threshold: Vec<f64>,
    pub children_left: Vec<i64>,
    pub children_right: Vec<i64>,
    pub class: Vec<String>,
}

impl BcTree {
    pub fn load(path: &str) -> BcTree { serde_json::from_str(&std::fs::read_to_string(path).expect("the bc tree")).expect("a bc tree json") }

    /// The leaf class for a decision row: sklearn goes left when x <= threshold.
    pub fn choose(&self, row: &serde_json::Value) -> &str {
        let mut n = 0usize;
        while self.children_left[n] >= 0 {
            let x = bc_feature(row, &self.features[self.feature[n] as usize]);
            n = if x <= self.threshold[n] { self.children_left[n] } else { self.children_right[n] } as usize;
        }
        &self.class[n]
    }
}

/// One named feature of a decision row; the grammar is `social_imitation.py::feature` (NAME, A-B, persona.T,
/// emotions.E, relation.K, psychsim=V, fatima=V). Anything missing or not a number reads 0.
pub fn bc_feature(row: &serde_json::Value, name: &str) -> f64 {
    let num = |v: Option<&serde_json::Value>| match v { Some(serde_json::Value::Bool(b)) => f64::from(u8::from(*b)), Some(v) => v.as_f64().unwrap_or(0.0), None => 0.0 };
    if let Some((k, v)) = name.split_once('=') { return f64::from(u8::from(row.get(k).and_then(serde_json::Value::as_str) == Some(v))) }
    if let Some((k, sub)) = name.split_once('.') {
        let obj = if k == "persona" { row.get(k).and_then(|p| p.get("traits")) } else { row.get(k) };
        return num(obj.filter(|o| o.is_object()).and_then(|o| o.get(sub)));
    }
    if let Some((a, b)) = name.split_once('-') { return num(row.get(a)) - num(row.get(b)) }
    num(row.get(name))
}

#[cfg(test)]
mod tests {
    use super::*;

    fn view<'a>(hour: f64, club: &'a [Option<usize>], points: &'a [f64], betrays: &'a [bool]) -> View<'a> { View { hour, club, points, betrays } }

    /// The constants are the PHP module's YAML values: one set of rules for the simulator and the live game.
    #[test]
    fn constants_match_the_module_yaml() {
        let root = concat!(env!("CARGO_MANIFEST_DIR"), "/../../resources/behavior/");
        let read = |file: &str, name: &str| -> f64 {
            let text = std::fs::read_to_string(format!("{root}{file}")).expect("yaml");
            text.lines().find_map(|l| l.strip_prefix(&format!("{name}:")).map(|v| v.trim().parse::<f64>().expect("number"))).unwrap_or_else(|| panic!("{name}"))
        };
        assert_eq!(read("alliance-war.yaml", "war_grievance"), WAR_GRIEVANCE);
        assert_eq!(read("alliance-war.yaml", "betray_strength_ratio"), BETRAY_STRENGTH_RATIO);
        assert_eq!(read("alliance-war.yaml", "coalition_share"), COALITION_SHARE);
        assert_eq!(read("alliance-war.yaml", "peace_grievance"), PEACE_GRIEVANCE);
        assert_eq!(read("alliance-war.yaml", "war_minimum_hours"), WAR_MINIMUM_HOURS);
        assert_eq!(read("alliance-war.yaml", "ally_war_factor"), ALLY_WAR_FACTOR);
        assert_eq!(read("alliance-war.yaml", "war_max_strength_ratio"), WAR_MAX_STRENGTH_RATIO);
        assert_eq!(read("alliance-war.yaml", "max_open_wars") as usize, MAX_OPEN_WARS);
        assert_eq!(read("alliance-war.yaml", "reference_members"), REFERENCE_MEMBERS);
        assert_eq!(read("grudges.yaml", "grace_hours"), GRACE_HOURS);
        assert_eq!(read("grudges.yaml", "half_life_hours"), HALF_LIFE_HOURS);
        assert_eq!(read("grudges.yaml", "floor"), FLOOR);
        // The living minds: the module's social-minds.yaml holds the same numbers.
        assert_eq!(read("social-minds.yaml", "court_every_hours"), COURT_EVERY_HOURS);
        assert_eq!(read("social-minds.yaml", "court_candidates") as usize, COURT_CANDIDATES);
        assert_eq!(read("social-minds.yaml", "conquest_hours"), CONQUEST_HOURS);
        assert_eq!(read("social-minds.yaml", "secret_cunning"), SECRET_CUNNING);
        assert_eq!(read("social-minds.yaml", "pact_notice_hours"), PACT_NOTICE_HOURS);
        assert_eq!(read("social-minds.yaml", "tribute_share"), TRIBUTE_SHARE);
        assert_eq!(read("social-minds.yaml", "revolt_ratio"), REVOLT_RATIO);
        assert_eq!(read("social-minds.yaml", "memory_cap") as usize, crate::mind::MEMORY_CAP);
        assert_eq!(read("social-minds.yaml", "leak_per_member_day"), LEAK_PER_MEMBER_DAY);
        assert_eq!(read("social-minds.yaml", "tell_per_victim_day"), TELL_PER_VICTIM_DAY);
        assert_eq!(read("social-minds.yaml", "coup_ambition"), COUP_AMBITION);
        assert_eq!(read("social-minds.yaml", "coup_points_ratio"), COUP_POINTS_RATIO);
        assert_eq!(read("social-minds.yaml", "coup_authority"), COUP_AUTHORITY);
        assert_eq!(read("social-minds.yaml", "coup_cooldown_hours"), COUP_COOLDOWN_HOURS);
        // Arm `cycles`: asabiyya, schism and nomad uprisings.
        assert_eq!(read("social-minds.yaml", "asabiyya_peace_decay"), ASABIYYA_PEACE_DECAY);
        assert_eq!(read("social-minds.yaml", "asabiyya_war_gain"), ASABIYYA_WAR_GAIN);
        assert_eq!(read("social-minds.yaml", "asabiyya_split"), ASABIYYA_SPLIT);
        assert_eq!(read("social-minds.yaml", "elite_share"), ELITE_SHARE);
        assert_eq!(read("social-minds.yaml", "charisma"), CHARISMA);
        assert_eq!(read("social-minds.yaml", "uprising_band") as usize, UPRISING_BAND);
        assert_eq!(read("social-minds.yaml", "uprising_every_hours"), UPRISING_EVERY_HOURS);
        assert_eq!(read("social-minds.yaml", "ruler_lead"), RULER_LEAD);
        assert_eq!(read("social-minds.yaml", "uprising_share") as usize, UPRISING_SHARE);
        assert_eq!(read("social-minds.yaml", "schism_min_members") as usize, SCHISM_MIN_MEMBERS);
        assert_eq!(read("social-minds.yaml", "rebel_freedom"), REBEL_FREEDOM);
        assert_eq!(read("social-minds.yaml", "follower_loyalty"), FOLLOWER_LOYALTY);
        assert_eq!(read("social-minds.yaml", "rider_freedom"), RIDER_FREEDOM);
    }

    #[test]
    fn two_raids_make_a_war_and_quiet_makes_peace() {
        let mut r = Relations::new(true);
        let (club, points, no) = ([Some(0), Some(1)], [10.0, 10.0], [false, false]);
        r.hit(1, 0, 0.0);
        r.pass(&view(1.0, &club, &points, &no));
        assert!(!r.at_war(0, 1), "one raid is a grudge");
        r.hit(1, 0, 2.0);
        r.pass(&view(3.0, &club, &points, &no));
        assert!(r.at_war(0, 1), "two raids are a war");
        r.pass(&view(24.0 * 10.0, &club, &points, &no));
        assert!(!r.at_war(0, 1), "grudges fade, the war ends");
    }

    /// A bloc of 24 reads its grudges per REFERENCE_MEMBERS: two raids on it are no war, eight (two per six members) are.
    #[test]
    fn a_bloc_needs_raids_in_proportion_to_its_size() {
        let n = 4 * REFERENCE_MEMBERS as usize;
        let club: Vec<Option<usize>> = (0..2 * n).map(|a| Some(a / n)).collect();
        let (points, no) = (vec![10.0; 2 * n], vec![false; 2 * n]);
        let mut r = Relations::new(true);
        for t in 0..2 { r.hit(n + t, t, t as f64) }
        r.pass(&view(3.0, &club, &points, &no));
        assert!(!r.at_war(0, 1), "two raids among 24 members are no war");
        for t in 2..8 { r.hit(n + t, t, t as f64) }
        r.pass(&view(9.0, &club, &points, &no));
        assert!(r.at_war(0, 1), "eight raids on a bloc of 24 weigh as two on a club of six");
        // Two half-lives past grace the eight grudges sum to about 1.0 raw, over the peace line; read per six, 0.25.
        r.pass(&view(24.0 + 2.0 * HALF_LIFE_HOURS + 9.0, &club, &points, &no));
        assert!(!r.at_war(0, 1), "a war no longer fought ends");
    }

    #[test]
    fn common_enemy_pact_and_betrayal_of_an_outgrown_partner() {
        let mut r = Relations::new(true);
        let club = [Some(0), Some(1), Some(2)];
        let no = [false; 3];
        for t in 0..2 { r.hit(2, 0, t as f64); r.hit(2, 1, t as f64) }
        r.pass(&view(3.0, &club, &[10.0, 10.0, 10.0], &no));
        assert!(r.at_war(0, 2) && r.at_war(1, 2) && r.pact(0, 1), "two clubs hit by one sign a pact");
        // The common enemy fades away; the betraying leader of an outgrown pair turns on its partner (club 0 holds 30 of
        // 65, under the coalition share, so no conspiracy puts it at war first).
        let (grown, betrayer) = ([30.0, 10.0, 25.0], [true, false, false]);
        r.pass(&view(24.0 * 20.0, &club, &grown, &betrayer));
        assert!(r.log.iter().any(|e| e.kind == "pact_cancel" && e.by == 0 && e.with == 1));
        assert!(r.bound(0, 1) && !r.at_war(0, 1) && r.broken.is_empty(), "cancelled by notice: still bound, no stain");
        r.pass(&view(24.0 * 20.0 + PACT_NOTICE_HOURS - 1.0, &club, &grown, &betrayer));
        assert!(r.bound(0, 1) && r.log.iter().filter(|e| e.kind == "pact_cancel").count() == 1, "one notice, still running");
        r.pass(&view(24.0 * 20.0 + PACT_NOTICE_HOURS, &club, &grown, &betrayer));
        assert!(r.at_war(0, 1) && !r.bound(0, 1) && r.log.iter().any(|e| e.kind == "war" && e.by == 0 && e.cause == "betrayal"));
        assert!(!r.log.iter().any(|e| e.kind == "betrayal"), "no surprise break");
    }

    /// A surprise break (war at once) needs a cunning leader holding a grievance toward the partner; a cunning leader
    /// with none, like any other, cancels by notice.
    #[test]
    fn a_surprise_break_needs_cunning_and_a_grievance() {
        let with = |pairs: &[(&str, f64)]| { let mut t = [0.5; 12]; for &(n, v) in pairs { t[crate::persona::TRAITS.iter().position(|&x| x == n).unwrap()] = v } t };
        let fox = with(&[("ambition", 0.9), ("cunning", 0.95), ("honesty", 0.1), ("loyalty", 0.1)]);
        let plain = with(&[("ambition", 0.9), ("cunning", 0.3), ("honesty", 0.1), ("loyalty", 0.1)]);
        for (leader, wronged, surprise) in [(fox, true, true), (fox, false, false), (plain, true, false)] {
            let mut r = Relations::new(true);
            r.persona = Some((crate::persona::Personas::load(), vec![leader, with(&[("cunning", 0.1)]), with(&[])]));
            let club = [Some(0), Some(1), Some(2)];
            for t in 0..2 { r.hit(2, 0, t as f64); r.hit(2, 1, t as f64) }
            r.pass(&view(3.0, &club, &[10.0, 10.0, 10.0], &[false; 3]));
            assert!(r.pact(0, 1));
            if wronged { r.hit(1, 0, 24.0 * 19.0) }
            r.pass(&view(24.0 * 20.0, &club, &[30.0, 10.0, 25.0], &[false; 3]));
            assert_eq!(r.at_war(0, 1), surprise, "surprise={surprise}");
            assert_eq!(r.log.iter().any(|e| e.kind == "betrayal" && e.cause == "surprise"), surprise);
            assert_eq!(r.in_notice(0, 1), !surprise);
        }
    }

    /// Arm `persona`: a cunning leader's pact is secret; betraying it stays private to the victim until a vindictive
    /// victim tells, and only then does it cost public reputation.
    #[test]
    fn a_secret_betrayal_costs_reputation_only_once_told() {
        let personas = crate::persona::Personas::load();
        let mut fox = [0.5; 12];
        for (n, v) in [("ambition", 0.8), ("cunning", 0.95), ("honesty", 0.15), ("loyalty", 1.0)] { fox[crate::persona::TRAITS.iter().position(|&x| x == n).unwrap()] = v }
        let mut victim = [0.5; 12];
        for (n, v) in [("honesty", 0.9), ("loyalty", 1.0), ("vindictiveness", 1.0), ("cunning", 0.1)] { victim[crate::persona::TRAITS.iter().position(|&x| x == n).unwrap()] = v }
        let mut r = Relations::new(true);
        r.persona = Some((personas, vec![fox, victim, victim]));
        r.rng = 7;
        let club = [Some(0), Some(1), Some(2)];
        for t in 0..2 { r.hit(2, 0, t as f64); r.hit(2, 1, t as f64) }
        r.pass(&view(3.0, &club, &[10.0, 10.0, 10.0], &[false; 3]));
        assert!(r.pact(0, 1) && r.log.iter().any(|e| e.kind == "pact" && e.cause == "secret"));
        // The partner wronged the fox once: its break is a surprise, revenge with a reason.
        r.hit(1, 0, 24.0 * 19.0);
        r.pass(&view(24.0 * 20.0, &club, &[30.0, 10.0, 25.0], &[false; 3]));
        assert!(r.log.iter().any(|e| e.kind == "betrayal" && e.cause == "surprise"));
        let told_at = (1..400).find(|d| {
            if r.broken.get(&0).copied().unwrap_or(0) > 0 { return true }
            assert!(r.known.contains(&(1, 0)), "the victim knows");
            r.pass(&view(24.0 * (20 + d) as f64, &club, &[30.0, 10.0, 25.0], &[false; 3]));
            false
        });
        assert!(told_at.is_some() && r.log.iter().any(|e| e.kind == "leak" && e.cause == "betrayal"));
    }

    /// Arm `persona`: a crushed club whose leader cares little for freedom buys peace with tribute to a domineering
    /// victor, is spared from then on, and revolts once it has grown to half its overlord.
    #[test]
    fn the_crushed_submit_and_the_grown_revolt() {
        let with = |pairs: &[(&str, f64)]| { let mut t = [0.5; 12]; for &(n, v) in pairs { t[crate::persona::TRAITS.iter().position(|&x| x == n).unwrap()] = v } t };
        let lord = with(&[("dominance", 0.9)]);
        let mut r = Relations::new(true);
        r.persona = Some((crate::persona::Personas::load(), vec![lord, lord, with(&[("freedom", 0.2), ("courage", 1.0)]), with(&[]), with(&[])]));
        // Club 0 (two members) hits club 1 twice as hard as it is hit back; the brave weak club declares the war.
        let club = [Some(0), Some(0), Some(1), Some(2), Some(3)];
        for t in 0..2 { r.hit(0, 2, t as f64); r.hit(1, 2, t as f64); r.hit(2, 0, t as f64) }
        r.pass(&view(3.0, &club, &[20.0, 20.0, 8.0, 30.0, 30.0], &[false; 5]));
        assert!(r.at_war(0, 1));
        r.pass(&view(4.0, &club, &[20.0, 20.0, 8.0, 30.0, 30.0], &[false; 5]));
        assert!(!r.at_war(0, 1) && r.bound(0, 1) && r.tribute.get(&1).is_some_and(|t| t.0 == 0), "the losing club pays");
        r.pass(&view(24.0 * 30.0, &club, &[20.0, 20.0, 20.0, 30.0, 30.0], &[false; 5]));
        assert!(r.at_war(0, 1) && !r.bound(0, 1) && r.log.iter().any(|e| e.kind == "revolt"));
    }

    /// Arm `persona`: an ambitious member who outgrew the standing leader seizes the club where members respect little
    /// hierarchy, and fails where they respect it.
    #[test]
    fn a_coup_needs_members_who_do_not_respect_hierarchy() {
        let with = |pairs: &[(&str, f64)]| { let mut t = [0.5; 12]; for &(n, v) in pairs { t[crate::persona::TRAITS.iter().position(|&x| x == n).unwrap()] = v } t };
        for (authority, won) in [(0.2, true), (0.9, false)] {
            let mut r = Relations::new(true);
            let member = with(&[("authority", authority)]);
            r.persona = Some((crate::persona::Personas::load(), vec![member, with(&[("ambition", 0.9)]), member, member]));
            let club = [Some(0); 4];
            r.pass(&view(1.0, &club, &[10.0, 5.0, 3.0, 3.0], &[false; 4]));
            assert_eq!(r.leaders[&0], 0, "the strongest founds");
            r.pass(&view(24.0 * 2.0, &club, &[10.0, 20.0, 3.0, 3.0], &[false; 4]));
            assert_eq!(r.leaders[&0], 0, "no plot while the new leader settles in");
            r.pass(&view(24.0 * 20.0, &club, &[10.0, 20.0, 3.0, 3.0], &[false; 4]));
            assert_eq!(r.leaders[&0], if won { 1 } else { 0 });
            assert!(r.log.iter().any(|e| e.kind == if won { "coup" } else { "coup_failed" }));
        }
    }

    /// Arm `bc`: a hand-written tree reads the named features off a decision row (sklearn: left when x <= threshold).
    #[test]
    fn a_bc_tree_maps_row_features_to_its_choice() {
        // Root: fatima=Betray? yes -> (gratitude-grievance <= 0 -> betray, else keep); no -> (persona.loyalty <= 0.5 ->
        // keep, else relation.trust <= 0.4 -> keep, else help).
        let tree: BcTree = serde_json::from_value(serde_json::json!({
            "features": ["fatima=Betray", "gratitude-grievance", "persona.loyalty", "relation.trust"],
            "feature": [0, 1, -2, -2, 2, -2, 3, -2, -2], "threshold": [0.5, 0.0, -2.0, -2.0, 0.5, -2.0, 0.4, -2.0, -2.0],
            "children_left": [4, 2, -1, -1, 5, -1, 7, -1, -1], "children_right": [1, 3, -1, -1, 6, -1, 8, -1, -1],
            "class": ["keep", "keep", "betray", "keep", "keep", "keep", "keep", "keep", "help"]})).expect("tree");
        let row = |fatima: Option<&str>, gratitude: f64, grievance: f64, loyalty: f64, relation: serde_json::Value| serde_json::json!({
            "fatima": fatima, "gratitude": gratitude, "grievance": grievance, "persona": {"traits": {"loyalty": loyalty}}, "relation": relation, "emotions": []});
        assert_eq!(tree.choose(&row(Some("Betray"), 0.1, 0.3, 0.9, serde_json::json!({"trust": 0.9}))), "betray");
        assert_eq!(tree.choose(&row(Some("Betray"), 0.3, 0.1, 0.9, serde_json::json!({"trust": 0.9}))), "keep");
        assert_eq!(tree.choose(&row(Some("DefendAlly"), 0.3, 0.1, 0.9, serde_json::json!({"trust": 0.9}))), "help");
        assert_eq!(tree.choose(&row(None, 0.3, 0.1, 0.9, serde_json::json!({"trust": 0.2}))), "keep");
        // A missing relation (null) reads 0, so trust 0 <= 0.4 keeps; a disloyal leader keeps.
        assert_eq!(tree.choose(&row(None, 0.3, 0.1, 0.9, serde_json::Value::Null)), "keep");
        assert_eq!(tree.choose(&row(Some("DefendAlly"), 0.3, 0.1, 0.2, serde_json::json!({"trust": 0.9}))), "keep");
        assert_eq!(bc_feature(&serde_json::json!({"at_war": true}), "at_war"), 1.0);
    }

    /// Phase D lever league: each probe variant changes its own decision family against the teacher on the same world.
    #[test]
    fn probe_variants_change_their_decision() {
        let probed = |v: &str, club: usize| { let mut r = Relations::new(true); r.probe = Some(v.into()); r.probe_club = Some(club); r };
        let club = [Some(0), Some(1), Some(2)];
        // Hawk: a quiet world stays at peace, a hawk opens a conquest war on the weakest club.
        for v in ["teacher", "hawk"] {
            let mut r = probed(v, 0);
            r.pass(&view(1.0, &club, &[30.0, 20.0, 25.0], &[false; 3]));
            assert_eq!(r.at_war(0, 1), v == "hawk", "{v}");
        }
        // Hermit and courtier: two clubs hit by one sign a pact; a hermit does not; a courtier accepts an offer its
        // leader's feud (club 0 raided it) would refuse.
        for (v, raided, pact) in [("teacher", false, true), ("hermit", false, false), ("teacher", true, false), ("courtier", true, true)] {
            let mut r = probed(v, if v == "courtier" { 1 } else { 0 });
            for t in 0..2 { r.hit(2, 0, t as f64); r.hit(2, 1, t as f64); if raided { r.hit(0, 1, t as f64) } }
            r.pass(&view(3.0, &club, &[10.0, 10.0, 10.0], &[false; 3]));
            assert_eq!(r.pact(0, 1), pact, "{v} raided={raided}");
        }
        // Dove: two raids make the teacher declare war, not a dove; a war the victim declared ends for a dove once
        // the enemy holds no grievance, while the teacher's own grievance keeps it.
        let pair = [Some(0), Some(1)];
        for v in ["teacher", "dove"] {
            let mut r = probed(v, 0);
            for t in 0..2 { r.hit(1, 0, t as f64) }
            r.pass(&view(3.0, &pair, &[10.0, 10.0], &[false; 2]));
            assert_eq!(r.at_war(0, 1), v == "teacher", "{v} declares");
            let mut r = probed("teacher", 0);
            for t in 0..2 { r.hit(1, 0, t as f64) }
            r.pass(&view(3.0, &pair, &[10.0, 10.0], &[false; 2]));
            r.probe = Some(v.into());
            r.pass(&view(30.0, &pair, &[10.0, 10.0], &[false; 2]));
            assert_eq!(r.at_war(0, 1), v == "teacher", "{v} peace");
        }
    }

    #[test]
    fn a_hegemon_draws_a_coalition() {
        let mut r = Relations::new(true);
        let club = [Some(0), Some(1), Some(2)];
        r.pass(&view(1.0, &club, &[60.0, 20.0, 15.0], &[false; 3]));
        assert!(r.pact(1, 2) && r.at_war(1, 0) && r.at_war(2, 0));
    }
}
