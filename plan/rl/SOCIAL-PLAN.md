# Social cognition plan (owner-approved goal, 6 Nov 2026)

**Goal.** A social player that is **indistinguishable from humans** in alliance structure and movement, and
**stronger** through its social choices (join, lead, war, peace, betrayal). Strength is judged against a held-out
population that includes betrayers. Every decision uses **visible information only**.

Background: `SOCIAL-STEP1-AUDIT.md` (real bands and the gap), `SOCIAL-LITERATURE.md` (rules and measures from the field).

## Three gauges, always reported together (a Pareto view, never one number)

1. **Strength**: rank and survival against a held-out league (visitor and resident modes, Melting Pot style).
2. **Indistinguishability**: social bands from real data (`rl/scripts/social_gauges.py`), later inside HDS/AUC.
3. **Integrity**: say–do consistency and promise-keeping are high, and deception is a low-rate tool (the Cicero rule).

## Standing rules

- No social decision reads hidden state. Each one gets a test where changing unseen state leaves the decision unchanged.
- No phase starts until the previous one has moved its stated number.
- No experiment runs longer than 15 minutes. Runs go through `capped.sh`, on one binary with paired seeds.
- One spec for the simulator and PHP (`alliance-politics.md`), so the two never drift.
- Shrink before grow. Reuse the `alliances=N`, `acsattack`, `acsdefend` and `coordinate_war` arms before adding code.

## Phases

| # | What | Done when |
| --- | --- | --- |
| 0 | **Ruler.** `social_gauges.py`: structure, dominance, war graph (hostile/day, inter- vs intra-alliance, structural balance, attacks on the top 10) | Self-test reproduces the logged noquit and churn-base numbers (done 6 Nov) |
| 1 | **Ablation.** Does alliance structure alone (several clubs, no diplomacy) move dominance and war in the everyone-plays world? Arms: base, `alliances=6`, `alliances=12` | Read the gauges plus a paired strength test. If nothing moves, the dominance gap is economic or military and is fixed first |
| 2 | **Substrate.** Endogenous membership (join, accept, found, leave by rule from public data); relations (war, NAP) with a public reputation for broken pacts; sanctions | Clubs per 100 and largest share inside the real bands; inter-alliance share of hostility rises toward 0.64; intra stays near 0 |
| 3 | **Teacher and league.** Social personas with varied social preferences (loyal, opportunist, betrayer, leader); Axelrod profile; level-1 theory of mind from visible data; betrayal rare and purposeful (partner weak, or the end near) | Behaviour bands hold; betrayal rate and timing inside the literature priors; structural balance high |
| 4 | **Learning.** Diplomacy choice kind behind the seam: BC from the teacher, then RL on strength with a KL anchor and band constraints | Beats the teacher on strength against the held-out league with indistinguishability not worse (Pareto) |
| 5 | **Transfer and language.** Intents move to PHP; text is rendered from intents; Jev reads human messages | Say–do consistency measured on live accounts |

## Log

- 6 Nov: phase 0 ruler written and self-tested (noquit r1 0.053, r1/r10 1.21; churn-base 0.102 / 4.4).
  Finding: in the everyone-plays world hostile attacks are 0.009 per active account per day (churn-base 1.39: raids
  live on quitters), and no attack ever reaches the top 10.
- 6 Nov: phase 1 launched: `runs/social/{base,a6,a12}`, war3.bin, g16 errands, churn=0:1, seeds 1-10.
- 6 Nov 11:25: phase 0 in Rust: `src/social.rs` (arm `social`, `<out>.social.json` at season end; sparse triads, a test
  that matches brute force; slots mapped to accounts, which the Python file reader could not do for colonies). Found:
  `levels.json` writes `alliance_id` null under `standing`, so the Python fallback put every member in one club;
  the Rust gauges are authoritative. First a6/a12 run: 6 of 10 seasons timed out at 540 s (multi-alliance arms are
  slower) and were void. Rerun of base/a6/a12 on `runs/social/social.bin` with `social`, 840 s cap.
- 6 Nov 11:50: **phase 1 gate result (10/10 seasons per arm, Rust gauges)**: alliance structure alone moves nothing that
  matters. rank-1 share base 0.053 / a6 0.054 / a12 0.055 (real 0.20); r1/r10 1.21 / 1.23 / 1.24 (real 5.5); hostile
  attacks per active account-day 0.009 / 0.005 / 0.006. With several clubs, a quarter of hostility becomes
  inter-alliance (a6 0.24, a12 0.28; real 0.64), and intra stays 0. Reading: the dominance gap is **war volume**:
  raiders almost never attack active players (matches the raid-gates finding: "no plan" blocks them). A social layer
  on top of a world with no war has nothing to steer. Structure gauges at the end read 0 members (the final
  midnight drops everyone under `standing`); fixed by taking the last day row's clubs, rebuild running.
- OWNER? Phase 1 says war volume comes first, and that lives in `players.rs` (Raider::raid gates), where another session
  is working right now. Options: (a) wait for that session's raid fix, then rerun the ablation; (b) I take the raid
  gates myself after it stops; (c) go on with phase 2 (endogenous clubs, war/NAP) in parallel, on the bet that a
  declared war gives raiders a target and a reason (that is the social path to war volume). My recommendation is (c)
  as a short test: a war declaration that makes its members probe and raid the enemy club, measured by hostile/day
  and r1/r10. It does not touch the raid gates.
- 6 Nov 11:45: owner stopped the other session and handed over the decisions. Decision: root cause first. A war
  declaration would go through the same raid planner that refuses ("no plan": the fighters-only plan is short against
  defended active players, 406k of 615k refusals). Testing the other session's unrun fix, arm `warfleet` (fall back to
  `war_plan`, the whole warship mix at OGMem odds), in the everyone-plays world: `runs/social/{wf, wf6}` beside
  `{base, a6}` (fixed binary, Rust gauges plus gates.json). Go: hostile/day and r1/r10 up with HDS not worse.
- 6 Nov 12:00: **structure fixed and in band.** With the last-day clubs, a6 reads: largest alliance 0.14 of active
  (real 0.13-0.24), 9.4 clubs of ≥3 per 100 (real 5.3-9.8), top-10 sides 5.5 (real 2.7-6), structural balance
  0.996 (balanced, as in Pardus), intra-alliance hostility 0. So `alliances=6` gives a realistic social skeleton.
- 6 Nov 12:00: **`warfleet` result (10 seeds)**: launched raids ×6.5 (1966 → 12863), hostile per active account-day
  0.009 → 0.065, loot per season 0.12B → 17.0B. **Dominance unchanged**: rank-1 share 0.053 → 0.054, r1/r10
  1.21 → 1.24. With 6 clubs (wf6) hostility is only 0.018, because alliances spare their mates. Largest remaining
  refusal: a partial report (176k): too few probes to read ships and defence. Reality and HDS for base and wf running.
  Reading: more war is necessary, not sufficient. Every account is equally strong, so the loot spreads instead of piling
  on one winner.
- 6 Nov 12:25: **`warfleet` adopted** (HDS 0.724 vs base 0.723; raid loot/production 0.009 → 0.155, real 0.5-2).
- 6 Nov 12:25: **phase 2 in the simulator, PHP rules ported** (`src/diplomacy.rs`: AdvanceAiAlliancePoliticsAction with
  alliance-war.yaml/grudges.yaml/alliance-ambition.yaml numbers; a test fails if they drift). wf6 → dip (10 seeds):
  hostile/day 0.018 → 0.051; inter-alliance share 0.20 → **0.71 (real 0.64)**; intra 0; balance 0.99. Per season:
  5.9 wars (grievance) + 0.3 coalition wars, 4.8 peaces, 1.2 pacts (0.5 common-enemy, 0.7 flank) + 0.2 coalition pacts,
  **0.7 betrayals** (median day 49, p90 101). Dominance unchanged (r1 0.054, r1/r10 1.24).
  Tuning notes for the YAML, not yet acted on: betrayal has no endgame effect (literature: it clusters late), and pacts
  are few next to wars.
- 6 Nov 12:25: next, testing whether activity spread makes dominance: arm `bands` (the module's presence bands,
  1-3 up to 12-18 sessions a day) on the dip world, `runs/social/bands`.
- 6 Nov 12:40: `bands` (activity spread) on the dip world: rank-1 share 0.054 → 0.060, r1/r10 1.24 → 1.38, HDS 0.725 →
  **0.727** (violations 833 → 751), inter-alliance 0.53. A small move in the right direction, kept. Reading:
  dominance is not social. Raid loot is 0.155 of production (real 0.5-2), and the top 10% sit on about 100 days of stock
  (real 1-2), so war cannot shift the ranks. That is an economy gap (hoarding), outside the social layer.
  Reference world from now on: `warfleet alliances=6 diplomacy bands`.
- 6 Nov 12:50: engines audit. PsychSim was stopped (exit 137, 4 days ago); restarted. Its `/evaluate` is one
  cooperation dilemma with one input: it cooperates below temptation 1.0 and defects above, about 28 ms a call. The
  module maps threat×2 into that, so as wired it equals the rule "threat > 0.5": a threshold, not a theory of mind. FAtiMA
  (8092/8095), CBRKit (8091) and AgentOS (8093) are up.
- OWNER? Real ToM value needs a PsychSim alliance scenario: "will this partner betray me?", with beliefs over the
  partner leader's type, updated from its public history (pacts broken, strength ratio). That is the sidecar's
  scenario plus the module's mapping, not a port. Recommended next, explained to the owner before any code.
- 6 Nov 13:30: PsychSim alliance scenario prototyped (`docker/cognition/psychsim/alliance_tom.py`, not yet served).
  Setup: the account moves first, and the partner leader answers holding one of two possible types (loyal, or an
  opportunist whose temptation grows with its strength ratio). The account's belief over the type comes from the
  partner club's public pact history (Laplace prior 0.35). Result: in a sequential, observable game PsychSim keeps
  cooperating unless P(opportunist) > 2/3, because the partner retaliates at once. Betrayal pays only when the war
  payoff against a weak partner exceeds the pact's, and that payoff is module policy: tuning it to get betrayal just
  reproduces the PHP "betray at 2× strength" rule. Value found: reputation-aware pact refusal. Betrayal: none beyond
  the rule. Stopped here instead of tuning payoffs in a circle.
- 6 Nov 14:10: OWNER? Proposed `Modules/Empire` (off by default, for humans and AI alike), with host wiring only:
  view slots `alliance.tabs`, `alliance.page`, `player.profile`, and membership and inactivity events. Order:
  (1) diplomacy board, with treaty table and reputation moved from AI to Empire, (2) aid and call to arms, (3) shared
  target, (4) succession, (5) vassalage and tribute. The AI module becomes a client of Empire. The simulator mirrors the
  rules. Awaiting the owner's agreement.
- 6 Nov 14:40: owner approved `Modules/Empire`. Step 1 written: host slot `alliance.tabs` (ModuleSlotService and
  alliance/index.blade.php); module Empire (off by default in modules_statuses.json) with an `empire_alliance_treaties`
  table, `DiplomacyService` (war, pact offer/accept/decline, betrayal with the breaker on record, peace offer/accept,
  reputation = pacts broken), the alliance-page Diplomacy tab with leader acts (PERMISSION_MANAGE_ALLY), en strings,
  and feature tests. Next: tests green and the tool chain, then the AI module becomes Empire's client (politics writes
  through DiplomacyService, ai_alliance_relations migrated).
- 2026-11-06 phase A: arm `persona` wired (social-persona.yaml → persona.rs → diplomacy.rs). Leader traits scale war line (vindictiveness), outmatched odds (courage), peace line (forgiveness); betrayal = greed(ambition,cunning) − honour(honesty,loyalty) > 0.15; broken-pact reputation feeds pact refusal (forgiveness < 0.5); betrayed members drift. 9 lib tests pass. Run `persona` vs `bands` launched (10 seeds).
- 2026-11-06 15:00 phase A result, `persona` vs `bands` (10 seeds each): structure unchanged (rank-1 0.059 vs 0.060, r1/r10
  1.36 vs 1.38, ally 0.59), inter_ally 0.53 → 0.56 (real 0.64), balance 0.97. Per season: pacts 0.8 → 1.5, betrayals
  0.2 → 0.5, refusals 0.1 → 0.3, wars 6.3, peaces 5.7. HDS 0.724 (bands 0.727): no human-likeness cost. Betrayal days
  16, 19, 38, 67, 223: early-heavy (no endgame effect yet; tuning note). **`persona` adopted** into the reference world.
  Four questions: moves the social bands and integrity, not strength; the dominance gap (r1/r10 1.4 vs 5.5) is economic
  and the AI economy files are being edited by another session right now, so the social track goes on and economy waits.
- 2026-11-06 15:20 secrets: a pact is secret when either leader's cunning > 0.6; betraying a secret pact is known to the
  victim only (private memory feeds its pact refusals) and becomes public reputation once a vindictive victim tells
  (0.02 × vindictiveness per victim member per day); a secret pact itself slips via disloyal members (0.002 × (1 −
  loyalty) per member per day); exposed breakers drift (`betrayal_exposed`). 10 lib tests pass. Run `secrets` launched.
- 2026-11-06 15:40 `secrets` result (10 seeds): per season 0.9 secret pacts of 1.5, 0.4 pact leaks, 0.3 secret
  betrayals all told within the season (0.3 betrayal leaks), refusals 0.3 → 0.1 (a hidden breaker escapes refusal until
  told). Structure unchanged (rank-1 0.059, r1/r10 1.34, inter_ally 0.56, balance 0.98). Kept.
- 2026-11-06 16:00 vassalage: a club at war, outmatched over 4×, still hit (grievance ≥ 0.8), whose leader's freedom
  < 0.6, submits to a victor whose leader's dominance > 0.5: war ends, the vassal pays 10% of every member's stock per
  day to the overlord's strongest member (booked as loot out/in; a ledger move standing in for transports — PHP will
  send real transports) and the two spare each other; a vassal revolts at half its overlord's points; vassal members
  drift `dominated`. First strength lever of the social track (aims at r1/r10). 11 lib tests pass. Run `vassal` launched.
- 2026-11-06 16:30 `vassal` result: 0 submissions in 10 seasons (tribute 0): no war ever had a 4× gap, since clubs in
  this flat world are close (r1/r10 1.34). Fix 1 of at most 3: submit when losing the war, i.e. outweighed 2× (the
  betrayal ratio) and taking twice the harm dealt. Run `vassal2` launched. If submissions stay rare, vassalage waits
  for the economy gap (inequality is its precondition) and the social track moves to governance.
- 2026-11-06 17:00 `vassal2`: 1 submission in 10 seasons (day 17), revolt on day 26, 472M paid in that season; structure
  unchanged (r1/r10 1.34). Vassalage kept as a rare emergent event; no more tuning (its precondition is inequality,
  the economy gap). Governance added: standing leaders (founded, succession when the leader leaves), coups by an
  ambitious member over 1.5× the leader's points where members' mean authority < 0.5 (else coup_failed), one try per
  club per 14 days. 12 lib tests pass. Run `coup` launched.
- 2026-11-06 17:30 `coup`: per season 1.7 coups, 3.6 failed, 4.0 successions, mostly days 6-17 (small young clubs where
  1.5× the leader is easy). Pacts fell 1.5 → 0.6, betrayals 0.5 → 0.1, inter_ally 0.56 → 0.49 (the standing leader is
  no longer always the strongest, so the club's politics changes hands). Fix 1: a new leader gets a 14-day tenure
  before any plot. Run `coup2` launched.
- 2026-11-06 16:35 phase B tables (`rl/scripts/engine_tables.py`, `resources/behavior/engine-tables/`): FAtiMA 441
  cells (7 characters × Harm/Threaten/Aid × 21 levels), PsychSim 81 cells (temptation 0-4); live re-check 100% on a
  200-key sample. Limits: served FAtiMA gives anger/gratitude = level and fear = level × safety weight; served PsychSim
  is a cooperate/defect threshold at temptation 1.0 with no partner belief; CBRKit has no social case base. The served
  engines add little beyond the sim's own lines: their value needs richer social scenarios served by the engines.
- 2026-11-06 16:45 Empire tool chain (rector/pint/stan on Modules/Empire and the host slot): rector modernised
  AllianceTreaty, stan found a match without default in DiplomacyController::act (fixed: abort(404)); tests Empire 3/3,
  ModuleHost 3/3, HelloWorld 2/2, ModuleSlotService 9/9 after updating its slot list test to include `alliance.tabs`.
  `.phpunit.cache` is root-owned (needs `sudo chown -R nagi .phpunit.cache`; worked around with --cache-directory).
- 2026-11-06 17:00 owner: use every cognition engine at full depth, behind a reverse proxy with batched requests,
  even if training slows. Contract written (SOCIAL-ENGINES-CONTRACT.md); four parallel workers: gateway (port 8099,
  batch, cache, FAtiMA replicas), FAtiMA social appraisal + exchanges, PsychSim recursive partner model + CBRKit social
  case base from sim logs, AgentOS relationship memory. `.phpunit.cache` ownership fixed through docker.
- 2026-11-06 17:20 `coup2` (14-day tenure) over 20 seeds vs `bands` 20 seeds: coups 2.1 + 4.2 failed per season,
  median coup day 17 → 30; pacts 0.89 vs 1.16, betrayals 0.11 vs 0.42, inter_ally 0.53-0.58 vs 0.65. Coups still
  frequent and they damp betrayal. Stop tuning governance lines (two fixes, no gauge progress): the living mind
  (loyalty to the leader as a relation, memories) will drive coups instead. Gateway up on 8099 (fatima3-6 replicas,
  batch 2.8× faster cold, cache 1700 req/s; docker socket mounted read-only for image ids).
- 2026-11-06 17:55 engines live: AgentOS `/social/recall` (encoding, Ebbinghaus decay, consolidation, HEXACO from
  persona; per-actor grievance/gratitude/broken/kept; ~150 items/s per container, 4 replicas now behind the gateway);
  FAtiMA `/social/appraise` + `/social/exchange` (16 personas, OCC rules from the 12 traits and relations, per-emotion
  decay, relation updates, 10 CiF exchanges incl. DefendAlly; history-dependent: a friend's betrayal angers more,
  repeated help builds trust/debt, coward submits, fox betrays, knight defends); fatima3-6 recreated on the new image.
  Sim: `src/mind.rs` (mind state, gateway client over raw HTTP, no new crate), diplomacy events -> members' memories
  (visibility rules: only what each saw; leaks reach everyone), daily AgentOS recall behind arm `minds`. 15 lib tests
  pass. Live season `minds1` (seed 1) running to measure engine load and wall time.
- 2026-11-06 18:05 PsychSim `/social/partner` live (simultaneous turns, loyal vs opportunist partner models, belief
  and bond carried call to call, horizon; p_loyal 0.50 → 0.01 after one break, 0.72 after three kept) and CBRKit
  `/social/similar` (1347 cases from 98 sim seasons, history-aware). 8 PsychSim replicas behind the gateway. `minds1`
  (seed 1, AgentOS recall daily): 6.5 min wall, 958 recall calls, 0 errors, 48 ms mean. Decisions now from the
  engines under arm `minds`: pact acceptance = PsychSim accept_pact (refusal cause "distrust"), betrayal = PsychSim
  betray_now toward each pact partner, sacrifice = help_now joins a partner's war ("defend"); beliefs carried per
  leader. Run `minds` (10 seeds) launched against `coup2` as reference.
- 2026-11-06 18:20 `minds` (PsychSim decides pact acceptance/betrayal/help; 10 seeds) vs `coup2`: pacts 0.6 vs 0.67,
  betrayals 0.1 vs 0.11, distrust refusals 0.1, no defend wars, structure unchanged (r1/r10 1.35, inter_ally 0.50).
  The engines get too few questions: pacts are born only from common enemies or flanks (0.6 per season over 6 clubs),
  so minds rarely matter. Next: pact offers themselves from the engines (FAtiMA OfferPact/Ignore/Threaten toward each
  non-hostile club, PsychSim acceptance). FAtiMA appraisal (leaders, daily) + exchange (Betray/DefendAlly/Gift/Ignore
  toward each partner; betrayal cause "calculated" vs "emotional") wired; run `minds2` launched.
- 2026-11-06 18:25 owner: more players to see the minds' effect. arm.sh takes ACCOUNTS/DAYS. Launched `big128`
  (128 accounts, alliances=12, seeds 1-5) and `big256` (256 accounts, alliances=24, seeds 1-3), both with minds
  (PsychSim + FAtiMA + AgentOS), 256 days; the 840 s timeout will tell whether 256 accounts fit the 15-minute cap.
- 2026-11-06 18:27 owner: a 500-player world with 3 alliances and independents ("nomads": accounts outside every
  alliance, which `standing` already leaves out). Speed measured: 128 accounts ≈ 20 days/min, 256 ≈ 10 days/min, so
  within the 15-minute cap: `big256` re-run at 128 days, `w500` = 500 accounts, alliances=3, 64 days, seeds 1-2.
- 2026-11-06 18:30 owner: test the late game with a real season length, not the opening. The sim has no state save,
  so a late game means playing the whole season: `w500` (500 accounts, 3 alliances + independents) and `big256` (256
  accounts, 24 alliances) re-launched at 256 days with a 1-hour timeout (owner-approved longer run; arm.sh TIMEOUT).
  Read-out restricted to days 128-256 (diplomacy log by day; structure via social_gauges --day).
- 2026-11-06 18:55 `minds2` (PsychSim + FAtiMA, 10 seeds): pacts 1.5, betrayals 1.2 per season (0.1 calculated, 0.3
  emotional, 0.8 secret) = most pacts betrayed: FAtiMA's Betray exchange fires too freely. Fix 1: a felt betrayal is
  revenge and needs a remembered grievance (AgentOS recall: grievance > gratitude toward the partner's members); a felt
  defence needs gratitude ≥ grievance. Run `minds3` launched. `big128` (840 s timeout) died at days 167-237 with no
  read-out; re-launched with the 1-hour timeout. w500 ≈ day 107, big256 ≈ day 150 after 20 min.
- 2026-11-06 19:10 `minds3` (revenge needs remembered grievance): betrayals 1.2 → 0.8 per season (0.1 calculated, 0.3
  emotional, 0.4 secret) on 1.2 pacts; betrayal days now late-heavy (51 ... 234, median ~150: an endgame effect
  appears); felt defences 0.2. Structure unchanged (r1/r10 1.35). Courtship added (all four engines in one decision):
  each leader looks at the 2 clubs it remembers best (AgentOS gratitude − grievance, + a shared enemy), FAtiMA
  exchange OfferPact/Ignore/Threaten, CBRKit recalls 10 similar pacts (hold back if most were betrayed), the other
  leader's PsychSim accepts or refuses (distrust). Each pair courted at most weekly. Run `minds4` launched.
- 2026-11-06 19:35 `minds4` (courtship): +0.4 pacts per season by courtship, betrayals 1.0, structure unchanged.
  `big128` (128 accounts, 12 clubs, 5 seeds, full 256 days): per season 7.8 secret + 2.2 other pacts, 6.6
  betrayals, 0.4 submissions, but **30.8 defend wars and 45.6 peaces**: a defend/peace churn (a defence war ended by
  the grievance-peace rule the next day, then joined again). big256 (1 seed done): 312 defend wars, 340 peaces.
  rank-1 share 0.032 and r1/r10 1.33 at 128 accounts (flat). w500 was cut by the 1-hour timeout at day ~195 with no
  read-out. Fixes: a defence war holds while a partner of the defender still fights that enemy; read-outs (diplomacy
  log, social gauges with their day) are written every 16 days. Relaunched `w500` (1 h) and `minds5` (10 seeds).
- 2026-11-06 19:50 `minds5` (defence holds): defend wars 0.2 → 0.1, betrayals 1.0 → 0.5, pacts 1.0, no churn. w500
  read-outs every 16 days work (day 80-96 after 15 min: rank-1 0.010, r1/r10 1.2, ally 0.6). Four questions: the
  social layer moves social bands only; strength/dominance (r1/r10 1.2-1.35 vs 5.5) has not moved in any social run and
  is the biggest gap. No other OGameX session is live; economy diagnosis (read-only + ≤15-min arm toggles) delegated in
  parallel, per Part 17 decision 6.
- 2026-11-06 20:05 economy diagnosis (storage/rl/ogsim/runs/econ-diag/): the top three deciles are all savers living
  on expeditions (74% of their income; the find scales with the universe's top bracket, not the account, so it adds
  the same to every saver: r1/r10 ≈ 1.2 among them). Loot moves ~0.5% of production (raiders keep 4 probes, so 257k
  raid plans fail on missing fleet/defence sections; 207k on defence bigger than the fixed 30 fighters). `nocap`
  raises r1/r10 to 1.56 but halves top scores (rejected). Expeditions stay as the host has them (owner). Test: the
  raider fighter fleet (params raider_fighters 30 → 120 / 400), runs `f120`, `f400` (persona world, 10 seeds).
- 2026-11-06 20:10 w500 (500 accounts, 3 alliances + independents) at day 160, both seeds: ally share 0.62, rank-1
  0.008, r1/r10 1.12; diplomacy all in the opening (3 founded, 1 war, 2 pacts incl. 1 secret, 2-3 coups), nothing
  after day 128: the three blocs settle into pacts and stop. Seed 2 inter_ally 0.05: hostility goes to the
  independents (the blocs prey on the unallied, not on each other). Late game is socially quiet: no pressure (no
  hegemon over 50%, no grievance between blocs) to break pacts.
- 2026-11-06 20:20 `f120`/`f400` (raider fighters 30 → 120 / 400, 10 seeds each) vs `coup2`: rank-1 0.060/0.061 vs
  0.061, r1/r10 1.34/1.39 vs 1.35, hostile/day 0.052/0.057 vs 0.051: no effect (the binding limit is spying and the
  uniform expedition income, not the fleet). Two economy tries without progress: stop local fixes here.
  OWNER? Real servers get r1/r10 ≈ 5.5 largely from players who differ in skill and activity; every sim persona plays
  equally well and the top is a band of identical savers. Options: (a) per-account skill/activity spread (efficiency of
  queues, hours online) drawn from real activity data; (b) accept a flat sim for the social work and judge dominance
  only in the live game. Meanwhile the social track continues.
- 2026-11-06 20:35 w500 late game (days 192-208): still no diplomacy event after day 128; rank-1 0.008. Pressure
  added from the social side, not inequality: ambition under arm `minds` — weekly, a leader at peace eyes the weakest
  unbound club under half its points; FAtiMA exchange Ignore/Threaten/DemandTribute (dominance, ambition, feelings);
  on a demand the target's FAtiMA Submit/Revolt/Ignore; submit -> vassal, else war ("conquest" /
  "refused-tribute"). Runs `minds6` (64 accounts) and `big128b` (128 accounts, 3 seeds, 1 h).
- 2026-11-06 20:50 `minds6` (ambition) vs `minds5`: the late game wakes up (days 128+: 3.3 conquest wars, 0.7
  betrayals, 1.1 pacts per season vs 2.0 grievance wars and 0.2 betrayals). Whole season: pacts 1.0 → 3.8,
  betrayals 0.5 → 1.9, tribute submissions 0.3, conquest wars 7.6; top alliance's points 0.23 → 0.27 (real 0.49),
  r1/r10 1.35 → 1.40, inter_ally 0.53 → 0.43 (real 0.64). But 15.4 peaces: conquest wars end after a day. Fix 1: a
  conquest campaign lasts at least 7 days. `big128b` (128 acc., partial): 10.7 pacts, 6.6 betrayals, 1.3 submissions.
  w500 (no ambition) finished: no late events at all. Relaunched `minds7` and `w500` with ambition + campaigns.
- 2026-11-06 21:15 `minds7` (7-day campaigns): late game active (3.5 conquest wars, 0.5 betrayals, 1.0 pacts after
  day 128), inter_ally 0.66 (real 0.64), r1/r10 1.39, top alliance 0.24. `big128b` (128 acc.): 15 pacts, 8.7
  betrayals, 1.7 submissions per season, late game active. HDS of minds7: health BROKEN, 323 unexplained over-cap
  days: the tribute ledger move put stock over storage with no delivery. Fixed the faithful way: tribute now flies as
  a Transport from each vassal member's home (players::ship_from_home made pub(crate); no cargo ships, no tribute),
  so the host's delivery rules apply. Run `minds8` launched for gauges + HDS.
- 2026-11-06 21:30 `minds8` (tribute by transport): health PASS (0 unexplained over-cap), HDS 0.726 (bands 0.727:
  human-likeness unchanged), inter_ally 0.66 (real 0.64), ally share 0.60, top alliance 0.24, r1/r10 1.37. Per
  season: 3.0 pacts, 1.4 betrayals, 0.3 submissions (tribute 21-34 G in the seasons that had one), 12.9 wars, late
  game active. **Phase A+B gate**: betrayal, hypocrisy (secret pacts and leaks), tribute, coups, revenge, sacrifice
  (defence of partners), courtship and conquest all emerge from the four live engines; social bands hold; HDS holds.
  Open: dominance (OWNER? skill spread). Next: phase C/E groundwork — the live module mirrors the minds (Empire
  DiplomacyService as the write path, engine calls through the gateway).
- 2026-11-06 21:35 Phase E groundwork delegated: AI politics writes through an `AllianceTreaties` contract (native
  AiAllianceRelation by default; Empire DiplomacyService when Empire is enabled), same decisions, tests both ways.
- 2026-11-06 21:50 Phase E step 1b done: `AllianceTreaties` contract (AiAllianceRelationTreaties default,
  EmpireAllianceTreaties when Empire is enabled: Nap↔Pact, War↔War, breaking a pact by war records the breaker);
  AdvanceAiAlliancePoliticsAction and Enemies read/write only through it (decisions unchanged). Tests 63/63 across
  AllianceWar, HumanDiplomacy, Ceasefire, AllianceDeterrence, WorldJudge and Empire (incl. new AiDiplomacyTest, 4);
  WarTest needs FFI (absent locally, unrelated). Left: AI answers/puts pact offers on the tab; pactsBroken unread.
  Next: the live minds — the module calls the gateway (FAtiMA, PsychSim, AgentOS, CBRKit) as the sim does.
- 2026-11-06 22:05 w500 with ambition finished: still no event after day 128 — three equal blocs bound by pacts, no
  club under half another's points: a balance of power holds (finding, not a bug). Engines all healthy. Delegated:
  the live module's minds (ai_social_minds table, gateway calls, same decisions and constants as the sim, behind a
  default-off flag, falling back to today's rules when the gateway is down).
- 2026-11-06 22:30 Phase E: live minds in the module (flag `AI_SOCIAL_MINDS`, default off; gateway
  `AI_COGNITION_GATEWAY_URL`): ai_social_minds table (migration 2026_11_06_000037), SocialMinds + SocialPersona
  (draw identical to persona.rs), SocialGatewayClient, social-minds.yaml; pact acceptance / betrayal / defence /
  courtship / ambition from the four engines, today's rules when the gateway is down. One rule set: the Rust test
  `constants_match_the_module_yaml` now also checks social-minds.yaml (7 constants). Race fixed: the politics pass runs
  under a cache lock (`ai:alliance-politics`), so concurrent ticks cannot overwrite minds. Tests 46/46 (social minds,
  alliance war, human diplomacy, AI diplomacy). Gaps vs sim: leaks, tribute payment, coups, persona-scaled lines;
  courtship/ambition AI-led only.
- 2026-11-06 22:35 Delegated the three live gaps (one rule set with the sim): secret pacts + leaks, tribute paid by
  real Transport missions (vassals spared from raids), standing leaders + coups.
- 2026-11-06 23:00 Live gaps closed: leaks (seeded daily draw, announced, remembered), tribute by real Transport
  (PayAiTributeAction via FleetMissionService; vassal/overlord spare each other in Enemies), standing leaders +
  coups (kept in the module: the host cannot transfer an alliance's founder). Rust YAML-match test now covers 13
  social constants. Tests 42/42 (+ 150/150 in the worker's wider run; WarTest needs FFI). Game accuracy: OGame's
  pushing rule forbids resources to a higher-ranked account for nothing tangible
  (https://no.ogame.gameforge.com/ajax/main/rules); protection as "tangible" is unclear, so live tribute payment has
  its own flag `AI_SOCIAL_TRIBUTE` (default off); vassals are still spared. OWNER? enable tribute on a server only
  with the operator's ruling.
- 2026-11-06 23:15 Phase C groundwork: the sim writes `<out>.decisions.jsonl`, every partner decision of the engine
  teacher with the leader's visible features (points ratio, wars, remembered grievance/gratitude, belief p_loyal,
  mood, partner's public breaks, persona) and the engines' own answers (PsychSim act_now, FAtiMA exchange) and the
  final choice (keep/help/betray). Run `teach` (20 seeds, 64 accounts) to build the first imitation set.
- 2026-11-06 23:30 `teach`: 16 of 20 seeds wrote decisions, 10,362 partner decisions (keep 8,896, help 726, betray
  740 of which most fell while at war, where betrayal cannot act: relabelled keep). First imitation (gradient boosted
  trees on 20 visible features, held-out seeds): agreement 0.95, keep 0.97 F1, help recall 0.36, betray too rare in
  the held-out set to judge (1 case). Gaps: the leader's own FAtiMA emotions toward the partner are not in the row
  (help is driven by DefendAlly), and betrayals are rare. Next: add emotion features (own feelings are the
  player's own, not hidden) and log ambition/courtship decisions; gather on 128-account worlds.
- 2026-11-06 23:35 Decision rows now carry the leader's own FAtiMA emotions toward the partner's leader (max intensity
  per type) and its FAtiMA relation (trust, liking, fear, respect, debt); the label is the act the leader can take
  (no betrayal while at war). `teach` re-run (20 seeds); old set kept in old/teach-v1.
- 2026-11-06 23:50 OWNER decisions: (2) skill variance approved ("to get closer to reality there must be variance
  between levels") — delegated: arm `skill`, per-account heavy-tailed skill acting through real levers (queue refill,
  sessions, spending, raid planning), measured vs HDS. (1) Owner wants "mutations" that can flip the balance and
  change direction; proposal in discussion (schism, leader burnout/quit, rising star, fleet crash, wing alliances,
  prey exhaustion), to be grounded in real OGame history before building.
- 2026-11-07 00:05 Owner: "pick the deepest, most game-changing, most fun". Built arm `cycles` (Ibn Khaldun +
  Turchin): per-club asabiyya 1.0 at founding, −0.01/day of peace, +0.02/day of war; below 0.4 a club with ≥25%
  ambitious members splits (the strongest ambitious member, if its FAtiMA chooses Revolt, leaves with the disloyal:
  `schism`), and the leading decayed club can be overrun by a nomad band (a charismatic independent gathers 10
  free-spirited independents: `uprising`, war next day), at most one uprising per 30 days. Moved members are pinned to
  their new club by the season's standing. Runs `cycles` (64 acc., 10 seeds) and `w500c` (500 acc., 3 blocs, 1 h).
- 2026-11-07 00:20 Phase C imitation, `teach` v2 (10,663 decisions, held-out seeds ≡ 0 mod 4): visible features only
  0.79-0.84 agreement (emotions help keep, not help); with the engines' answers as features (Part 16: engines are the
  policy's understanding features) a depth-8 tree reaches 0.989 (keep 0.99, help 1.00, betray 0.33 on 9 cases);
  gradient boosting underfit the rare classes (0.83). Betrayal data is the bottleneck (33 rows): gather on 128-account
  worlds next. `cycles` running (partial at ~day 30: rank-1 0.105, r1/r10 2.85 — read at the end).
- 2026-11-07 00:30 Owner asleep until morning: full autonomy, loop every 20 min (cron de64ec32 alive). Overnight plan,
  in order: (1) read `cycles` + `w500c` (Ibn Khaldun) and the skill-variance runs (skill0/skill1, worker resumed);
  (2) if skill moves r1/r10 toward 3-13 with HDS ≥ 0.72 and health PASS, adopt `skill` into the reference world and
  re-run cycles on it (inequality + decay should break long peaces: the north-star dominance gap); (3) gather betrayal
  data on 128-account worlds for imitation; (4) start phase D groundwork: league of fixed opponents (betrayer, loyal,
  greedy, coward) scoring a policy's strength vs held-out opponents, with HDS and integrity gauges.
- 2026-11-07 00:45 `cycles` (Ibn Khaldun) vs `minds8`: the late game is the most alive yet (days 128+: 1.6 betrayals,
  1.1 nomad uprisings, 4.3 conquest wars, 2.9 pacts per season; whole season 2.6 uprisings, 3.0 betrayals, 6.6
  pacts, 0.7 submissions), inter_ally 0.74 (real 0.64). But bands never disperse: ally share 0.85 (real ≤ 0.81) and
  clubs3/100 12.5 (≤ 9.8); no schism fired (25% ambitious members is rarer than the persona mix gives); dominance
  unchanged (r1/r10 1.40). Fix 1: a pinned band/splinter whose asabiyya falls under 0.4 disperses; elite share 0.15.
  Run `cycles2`.
- 2026-11-07 01:00 **Skill variance adopted** (arm `skill`, tuning.rs skill_draw/expedition_use): per-account q in
  0..1 acts through behaviour only — attendance band from the module's own mix (RoutineProfile presence bands) and the
  share of expedition slots kept busy (0.10 + 0.90·q⁶); expedition finds untouched. Tail fitted to PUB speed-8
  128-256-day worlds. skill0 → skill2: r1/r10 1.32 → 4.15 [1.9-6.0] (real 3.1-13.6 ✓), rank-1 0.061 → 0.099 (real
  0.14-0.23), HDS 0.725 → 0.743, health PASS; bot-signal violations 745 → 1018 (to check). `cycles2`: dispersal
  works (1/season) but ally share 0.84 and uprisings 3.4/season; second cycles fix waits for the combined run `sc`
  (minds + cycles + skill, launched): inequality is the precondition for both hegemons and decay.
- 2026-11-07 01:20 `sc` (minds + cycles + skill, 10 seeds): r1/r10 3.83 [2.1-7.6] ✓ real band, rank-1 0.100, the
  richest social life yet (per season 12.1 pacts, 5.7 betrayals, 1.4 submissions, 2.9 uprisings, 20.6 wars; late game
  2.7 betrayals, 0.6 submissions). Off-band: ally share 0.87 (≤0.81), clubs3/100 12.5 (≤9.8), inter_ally 0.52 (0.64).
  Cycles fix 2 (Ibn Khaldun's actual claim): nomads rise only against a ruling dynasty (the leading club ≥ 1.5× the
  second, gone soft), and a band is a quarter of the free-spirited independents (2-9). Run `sc2`. If bands still push
  ally share over the band, stop tuning cycles (rule of three) and cap bands by the real alliance-count band instead.
- 2026-11-07 01:40 **New reference world `sc2`** = `warfleet alliances=6 diplomacy bands persona minds cycles skill`
  (+ churn=0:1 social): r1/r10 3.93 [2.2-6.2] ✓, rank-1 0.100, ally share 0.63 ✓, clubs3/100 9.4 ✓, HDS 0.742
  (health PASS), top alliance 0.25 (real 0.49), inter_ally 0.24 (OGMem 0.64; skill made the strong raid the weak
  unallied — single-source band, watched, not chased). Per season: 5.7 pacts, 2.6 betrayals, 0.7 submissions, 0.8
  uprisings, 12.9 wars; late game alive. w500c (cycles, no skill): seed 2 had 2 nomad uprisings and 7 wars after day
  128; seed 1 stayed at peace (no ruling club). Launched `w500s` (500 acc., 3 blocs, reference arms, 1 h) and
  `teach2` (20 new seeds on the reference, imitation data with more betrayals).
- 2026-11-07 02:00 Phase D groundwork: league probe (arm `probe=VARIANT`): the lowest-numbered club plays a fixed
  partner policy (keep / loyal / betray) or the engine teacher, every other club the teacher; its final share of club
  points is written to the diplomacy log (`probe` line); paired seeds 1-10 on the reference world compare variants.
  Launched lg-teacher and lg-betray; lg-keep and lg-loyal next (CPU).
- 2026-11-07 02:10 `teach2`: 11 of 20 seasons were cut by the 840 s timeout (the reference world with minds and
  w500s alongside is slower), and decisions were written only at the end: 7 files, 5,121 rows, 9 betrayals. Fix:
  decisions and the probe score are written every 16 days too. League restarted in pairs (teacher+betray now,
  keep+loyal next) to keep seasons inside the timeout.
- 2026-11-07 02:20 League lg-teacher vs lg-betray (paired seeds 1-10, reference world): the probe club's final share
  of club points differs by ≤ 0.02 per seed (teacher 0.089-0.297, betray 0.088-0.292). Social choices barely move
  strength because war's economic channel is near-dead (raid loot ≈ 0.155 of production vs real 0.5-2). This is now
  the north-star blocker for "stronger through social choices". Delegated: realistic raids (arm `raids`): probes sized
  by the host's spy-report rule, attack fleet sized to the reported defence as the module's RaidPlanner does (one rule
  set). lg-keep / lg-loyal running for completeness.
- 2026-11-07 02:40 League complete (paired seeds 1-10): mean share minus teacher = betray −0.0006, keep −0.0005,
  loyal +0.0014 (seed 5 +0.037, seed 7 −0.016): no policy separates while war has no economic weight; the league
  harness works and waits for `raids`. w500s (reference arms, 500 acc., 3 blocs) at day 176: rank-1 0.019, r1/r10
  1.4-1.7 (skill lifts the top less in a 500-world with 3 blocs), no late events: three equal blocs, no ruler, so no
  uprising (by design of fix 2). Raids worker running (rd0).
- 2026-11-07 03:30 w500s ended at the 1 h cap at day 176 (rank-1 0.019/0.019, r1/r10 1.73/1.43). Raids worker variant rd2: 4386 attacks/season, win 0.95, raider loot/prod 0.222 (rd0 ~0.155; real 0.5-2), rank-1 0.095, r1/r10 3.79 - moving the right way, still under band; worker continues.
- 2026-11-07 03:50 Raids worker done: arm `raids` (host spy burst + RaidPlanner fleet sizing, 20 candidates) raises raider loot/prod 0.079 -> 0.222 (scripted raiders 0.308), health PASS, HDS 0.738 unchanged (HDS does not read loot), dominance unchanged. Remaining gap: the 21 learned-policy accounts rarely attack (retraining = long training, not tonight). Adopted `raids` into the reference arms; relaunched the league lgr-{teacher,betray,keep,loyal} on social-raids.bin, seeds 1-10.
- 2026-11-07 04:20 League with raids (lgr, seeds 1-10, compared at the last common day): betray +0.0018, keep +0.0002, loyal +0.0013 vs teacher; in 5 of 8 seeds all four variants are byte-identical: the probe club (lowest club id) had no pact partner, so the variant never acted. Fix (measurement, not player): probe club = first club holding a pact partner (same across variants, worlds identical until the variant acts). Relaunched lgp-* on social-raids3.bin.
- 2026-11-07 04:45 League lgp (raids, probe = first club with a pact partner, seeds 1-10 complete): betray +0.0026 (4+/2-), keep +0.0008 (4+/2-), loyal +0.0012 (5+/2-); seeds 6 and 9 never form a pact. Third league in a row at noise level: the pact-partner choice (betray/keep/help) is not a strength lever in this world - strength comes from economy and raids. Stop chasing it (circle rule). Route change: Phase D RL on partner choice is parked; next tangible work is one-rule-set parity - port `cycles` (asabiyya, schism, uprising) to the PHP politics action behind its flag (delegated).
- 2026-11-07 05:10 One rule set: `cycles` ported to PHP (AdvanceAiAlliancePoliticsAction::cycle, model AiAllianceCohesion, migration 000038, flag AI_SOCIAL_CYCLES off; needs minds on). The 9 constants live in social-minds.yaml and the Rust yaml test checks them. Tests: SocialCycles 7/7, SocialMinds 10/10, AiDiplomacy 4/4, Alliance 177/177, Rust lib 136/136. Mapped differences: game leave-cooldown before the splinter founds, founders cannot leave, humans never moved, no 30-day season grace live, gateway-down fallback = freedom > 0.6. Open: the elite-share gate counts other members' traits (a world-level condition carried from the sim, each account still decides from its own traits) - logged, not a player-decision leak; hardcoded 0.6/0.5/6 should move into the yaml on both sides.
- 2026-11-07 05:40 Cycles thresholds moved out of code into social-minds.yaml on both sides (schism_min_members 6, rebel_freedom 0.6, follower_loyalty 0.5, rider_freedom 0.5); Rust yaml test now checks 13 cycles constants. Rust lib 136/136, Pest SocialCycles+SocialMinds+AiDiplomacy 21/21, pint pass; phpstan errors in the file are pre-existing (lines 138-162, 396, 1477), none on changed lines. Behaviour unchanged (same values).
- 2026-11-07 05:55 Strength gap probe (no training): fm0 = reference world + raids with fleet-model=0 (all 64 accounts fly the scripted RaidPlanner raider instead of the 21 learned-policy seats), social-raids3.bin, seeds 1-10, baseline lgp-teacher. Question: is the dominance gap (rank-1 0.095 vs real 0.2) the learned seats' passivity? Read rank-1, r1/r10, loot/prod, HDS next cycle.
- 2026-11-07 06:30 fm0 (all 64 accounts fly the scripted raider) vs lgp-teacher (21 learned seats), seeds 1-10: rank-1 0.106 vs 0.105, r1/r10 4.16 vs 3.94, ally 0.59 vs 0.61, inter_ally 0.27 vs 0.24; HDS (seeds 1,5,6,7) 0.733 vs 0.745, health PASS both. The learned seats' passivity is NOT the dominance gap; keep fleet-model=21. Measurement check (REAL-SERVER-PLAYER-PROFILE #9): the real rank-1 share (0.165-0.20) is over all points of a universe full of inactive accounts, so it does not transfer to a 64-account everyone-plays world; the scale-free gauge r1/r10 is in band (3.94 in 3.1-13.6). Dominance is closed for this world; rank-1 share stays a watch item for the w500 world only. Next: back to phase work (imitation data with betrayals / PHP pact answers).
- 2026-11-07 06:45 PHP gap delegated: AI-led alliances answer human pact/peace offers on the Empire tab with the same courtship decision (PsychSim accept + CBRKit hold-back), AI offers show as pending Empire offers, and pactsBroken feeds `their_broken` like the sim. Empire stays off by default.
- 2026-11-07 07:05 Empire tab parity done: AI-led alliances answer human pact offers (answerOffers: minds -> CBRKit hold-back + PsychSim accept_pact via shared pactSituation; no minds -> existing pactOffered rule), declined offers are declined on the tab, AI offers to humans are pending Empire offers, their_broken/my_broken read DiplomacyService::pactsBroken. Pest 82/82 (HumanDiplomacy|AllianceWar|AiDiplomacy|SocialMinds|SocialCycles|AllianceLife); no new phpstan errors on touched lines. Integrity check: Empire has no secret pacts (every pact and its break are public, a break opens war at once), so pactsBroken is visible reputation, not a leak. Note: the serial test DB lacks empire_alliance_treaties (parallel clones have it).
- 2026-11-07 07:40 Phase C imitation v3: imit2 (reference world + raids, seeds 11-20) adds 9,847 partner decisions; pooled with lgp-teacher, sc2, teach2 = 35,644 rows, 109 betrayals (was 33). Held-out seeds ≡ 0 mod 4 (7,120 rows): depth-8 tree on visible features + carried FAtiMA state 0.956 (keep 0.95, help 1.00, betray 0.92 on 13); with the engines' answers as features 1.000 (betray 0.85, 11/13). Betrayal recall up from 0.33 (9 cases). Script: scratchpad bc2.py (to move into rl/scripts if adopted).
- 2026-11-07 08:00 Phase C close-out delegated: social_imitation.py moved to rl/scripts (export tree), arm `bc=` plays the tree inside the sim (engines stay live as features), seeds 1-10 vs teacher on HDS (must not drop from ~0.745) and social gauges. Gate per 16.3: policy matches teacher and HDS holds.
- 2026-11-07 04:40 Phase C closed: social_imitation.py cleaned (argparse, --export; features named by one grammar read off the logged row by both Python and Rust `diplomacy::bc_feature`; fixed draft parsing: real trait names, emotions Anger/Gratitude/Hate/Love, relation debt/fear/liking/respect/trust, plus gratitude-grievance). Trained on imit2+sc2+teach2 (26,840 rows), held out lgp-teacher seeds 1-10 (8,804): engines-as-features tree (depth 7, 39 nodes) 1.000 (betray 36/36, help 1485/1485); visible-only 0.913. Arm `bc=TREE.json` (social-bc.bin, unit test, lib 137/137) plays the leader's partner choice in the sim with engines live; seeds 1-10 vs teacher rerun on the same binary: in-sim agreement 1.000 every seed, betrayals 30 vs 30, helps 1485 vs 1485, seasons and decisions byte-identical to teacher (and to lgp-teacher), gauges identical (r1/r10 3.86, rank-1 0.106, ally 0.59, inter_ally 0.26), HDS (seeds 1,5,6,7) 0.745 vs 0.745, health PASS. Gate 16.3 met. Note: the teacher's choice is a deterministic function of the logged row (PsychSim act_now, FAtiMA act, ledger), so the tree is exact; sc2 shares seeds 1-10 (older binary) with the evaluation.
- 2026-11-07 08:40 Phase C CLOSED (gate 16.3 met: bc tree plays in-sim, agreement 1.000 on seeds 1-10, HDS 0.745 = teacher, health PASS). Engines all healthy (gateway up 11 h, 0 errors). Phase D scoping: before any RL, a lever league (probe variants hawk/dove/courtier/hermit/tributer vs teacher, seeds 1-10) to find which social decision family moves strength; RL targets only a family that separates (delegated).
- 2026-11-07 09:00 Lever league lv-* running (6 variants x 10 seeds, load ~40/72). In parallel, one-rule-set gap: `skill` (per-account q: attendance band + expedition slot use 0.10+0.90q^6) being ported to PHP behind a flag, off by default (delegated).
- 2026-11-07 09:20 Lever league (social-lever.bin, probe variants hawk/dove/courtier/hermit/tributer in diplomacy.rs, unit test probe_variants_change_their_decision, lib 138/138; seeds 1-10, 840 s, compared at the last day common to all variants per seed: day 96-208; seeds 6 and 9 never form a pact so no probe club). Probe-club point share minus teacher: hawk -0.49pp (2W/5L, 61 conquest wars vs 8), dove -0.18pp (2W/5L, 0 betrayals/coalition, 20 peaces vs 37), courtier +0.56pp (5W/3L, 98 pacts vs 13, 39 betrayals suffered/made), hermit -0.06pp (5W/3L, 15 refusals), tributer +0.18pp (3W/1L, 10 vassals, 20 refused-tribute wars). Per-seed spread ~1-2pp, so no variant separates (best |t| ~1.7 hawk, ~1.2 courtier); member rank not available (levels.json only at season end, all probe seeds hit the timeout). Social decisions are weak levers at this horizon; the war-start family (hawk, consistent loss) and pact breadth (courtier) are the only candidates; confirm on 30 seeds before any Phase D RL.
- 2026-11-07 09:30 Lever league verdict (8 usable seeds): no family separates beyond seed noise (hawk -0.49 pp t≈-1.7, courtier +0.56 pp t≈1.2, dove/hermit/tributer ~0). Confirmation run lw-{teacher,hawk,courtier} on fresh seeds 11-30 (social-lever.bin, includes the tribute-reply fix) before deciding Phase D's target; if still not significant, social decisions are not a strength lever at this horizon and Phase D's "stronger than teacher" gate must be measured on other axes (survival, rank of members, war outcomes).
- 2026-11-07 09:40 One rule set for `skill`: ported to the live module behind `ai.population.skill` (AI_POPULATION_SKILL, off by default). Per-account q in `ai_profiles.skill_quantile` (migration 000039, drawn once from the account's seed by `SkillQuantile`); q places the account in its archetype's activity mix and diligence (RoutineProfile, also DefenseNeedEvaluator/AllianceChoice pace) and caps the expedition slots kept busy at ceil(slots·(0.10+0.90q⁶)) (QueueableExpeditionPlanner); constants in `behavior/skill.yaml`, pinned by tuning.rs `skill_constants_match_the_module_yaml`. Not 1:1: PHP draws q by the module's hash RNG (same uniform law, different values per seed than `skill_draw`). Test `SkillSpreadTest`.
- 2026-11-07 10:00 Confirmation lw-* (fresh seeds 11-30, all 20 complete, final probe share): hawk +0.35 pp (t 0.52, 6+/10-), courtier +0.34 pp (t 0.40, 10+/10-). The 8-seed hints vanish (hawk even flips sign). Verdict, five leagues: no social decision family (partner choice, war start, pact breadth, isolation, tribute) moves the club's point share at a 256-day horizon in this world. Strength is set by economy and raids; social choices shape the world's story, not its scoreboard.
- OWNER? Phase D gate (16.3) reads "stronger than the teacher against strangers". Since no social family moves point share, RL on social decisions has nothing to climb on that axis. Options: (a) keep the gate and wait for a longer horizon / harsher war economy; (b) measure "stronger" on social outcomes (club survival, members' rank, war wins, avoided betrayals) while point share must not drop; (c) skip social RL, ship the engine-driven teacher (Phase C) to the live game (Phase E). Default while waiting: (c) work continues on Phase E parity (one rule set in PHP) — no RL started.
- 2026-11-07 10:30 Phase E parity audit: tribute, cycles, skill, Empire answers, pactsBroken, leaks, coups are ported; remaining gap = courtship and ambition skip human-led alliances. Delegated: same AI-side decision toward human-led clubs, humans answer by message/Empire tab, under a human-pressure cap (decision 17.2, default medium).
- 2026-11-07 11:00 Phase E humans: court() and eyeTheWeak() now reach human-led clubs through the same FAtiMA/CBRKit path; a human gets the offer as a message plus a pending Empire offer, or a threat/tribute ultimatum (ultimatum_hours 72) that ends in the conquest war unless the club signed a pact, is already at war, or (tribute on) sent a transport. Threats capped per leader per week by ai.cognition.social.human_pressure (low 0, medium 1, high 3, social-minds.yaml; live only, the simulator has no humans). Tests: SocialMindsPoliticsTest (4 new), AiDiplomacyTest (1 new).
- 2026-11-07 11:00 Reviewed the human-led courtship/ambition port: Pest 88/88. Open follow-ups: (1) human-enjoyment gauge (decision 17.2) not built yet — next; (2) AI diplomacy lines live in alliance-war.yaml (existing pattern, rephrased by the model) rather than t_ translation keys — AGENTS.md wants translatable text; flag for a later pass, not changed tonight.
- 2026-11-07 11:20 Human-enjoyment gauge (decision 17.2, spec validation.md "Measure humans separately") delegated: read-only `ai:human-experience` command — meaningful interactions per human-week, pressure concentration and cap hits, continuing after loss, D7/D30 by cohort; AI accounts excluded.
- 2026-11-07 12:00 Human-enjoyment gauge built: `ai:human-experience {--days=7} {--loss=20} {--json}` (BuildHumanExperienceReportAction, read only, no new tables). Humans = users without an AiProfile. Interactions: raids/defences vs AI (battle_reports), pacts with AI-led clubs (ai_alliance_relations), ACS-defend help either way, two-way chats (AI answered a human line); opportunities: AI attacks launched, chat contacts. Pressure: AI attack/ACS/moon-destruction/missile fleets per human-day p50/p90/max, top-decile share, threats from minds `pressed`, AI wars on human clubs, AI leaders at the weekly cap (the cap is per leader, not per human). Resilience: lost > X% of worth (points*1000 + loss) to AI and active after (users.time). D7/D30 proxy from last activity only (no login history). Guardrails: humans ignoring AI accounts; no reports/opt-out table exists. Not read: Empire treaty board when that module is on, fulfilled trades, feedback. Test: HumanExperienceReportTest 2/2.
- 2026-11-07 12:00 Regression sweep: Empire tests 12/12. AI module tests (parallel, workers need --passthru-php="-d memory_limit=1G") 1905/1970 pass, 17 fail. Attribution: SurrenderColonyNotEncoded (2) fails on committed text ("peace or surrender" wiki quote already in HEAD grudges.yaml/alliance-war.yaml/politics action) - pre-existing; HeadhuntGap golden fails because behaviour yaml (alliance-war.yaml: court/threat/tribute/leak lines) is uncommitted - clears when committed with a refreshed fingerprint; the rest (DefenseComposition picks small_shield_dome x7, Diameter guard x4 files, FleetSettingsExport, WarTest deathstar, InboundMessageRepeat, FarmPolicy, PublicApiSnapshot) touch areas with other sessions' uncommitted work (ObjectService shield dome, Decision/*, 174 modified files in Modules/AI) - not social work, not touched tonight. OWNER: review before any commit.
- 2026-11-07 12:20 Morning summary for the owner written: plan/rl/SOCIAL-NIGHT-REPORT-2026-11-07.md (Arabic).
- 2026-11-07 12:45 Integrity gauge built (rl/scripts/integrity_gauge.py: pacts, kept share, secret share, betrayals per club-year, reasoned share). Teacher (lw-teacher, 20 seasons): 5.6 pacts/season, kept 0.52, secret 0.70, 0.85 betrayals/club-yr, reasoned 1.00 (every betrayal backed by grievance or PsychSim). Gap vs sources: every sim betrayal is a SURPRISE break (war at once), while real NAP breaks go by notice (cancel starts a 24-72 h notice, FOREIGN-MINISTRY-REPEATED-GAMES.md:22; DIPLOMACY-BETRAYAL.md:222 "cancel only by notice, never by surprise"; M5 surprise breaks ≤0.3% of pacts) - "often broken" (WIK-199) but by notice. Next: betrayal = cancel with notice then war; a surprise break stays possible only for the cunning with a reason, and is measured (delegated, sim + PHP, one rule set).
- 2026-11-07 13:10 Notice-break worker: sim binary built (social-notice.bin), run nt (seeds 11-20) in progress; PHP side pending. No new step started to avoid overlapping its files.
- 2026-11-07 13:30 nt partial (seeds 11-20, not finished): surprise breaks / pacts 0.478 -> 0.026, kept 0.52 -> 0.47, pacts 5.6 -> 8.3/season, betrayals/club-yr 0.85 -> 1.11, reasoned 1.00. Surprise still ~9x the M5 floor (≤0.003); read full results when the worker reports.
- 2026-11-07 13:45 Pact notice done (sim + PHP, one rule set): a leader turning on a partner cancels; the pact binds pact_notice_hours (48, social-minds.yaml) then the canceller's war opens (war cause betrayal); a surprise break (war at once, the only one on the public record) needs cunning >= secret_cunning, a remembered grievance over gratitude and a fresh hit by the partner's members (DIPLOMACY-BETRAYAL B3); a running notice blocks another betrayal like a war. nt vs lw-teacher seeds 11-20: surprise/pacts 0.455 -> 0.026 (2 of 85; M5 floor 0.003 not yet met), kept 0.555 -> 0.470, pacts 6.8 -> 8.5/season, betrayals (cancels + surprises)/club-yr 0.75 -> 1.16, reasoned 1.00; social gauges unchanged within spread (inter_ally 0.28 -> 0.23); HDS 0.742 vs 0.741, health PASS. PHP: AllianceTreaties cancelPact/inNotice/endNotices, new migrations (AI ai_alliance_relations, Empire empire_alliance_treaties: notice_ends_at, cancelled_by_alliance_id), Empire tab gets 'Cancel pact (48 h notice)' beside 'Break pact without notice'; pactsBroken counts surprise breaks only. Tests 97/97 (filtered), ogsim lib 140/140.
- 2026-11-07 13:50 Notice-break adopted as the reference rule (sim + PHP + Empire tab: 'cancel (48 h notice)' next to 'break without notice'). Final nt vs lw-teacher seeds 11-20: surprise 0.455 -> 0.026 (2/85; M5 floor 0.003, small sample - watch), kept 0.56 -> 0.47, pacts 6.8 -> 8.5/season, inter_ally 0.28 -> 0.23, HDS 0.741 -> 0.742, health PASS. Rust 140/140, Pest 97/97. Follow-up: '48 h' is written in two announce lines, the Empire translation and Empire NOTICE_HOURS - should read social-minds.yaml.
- 2026-11-07 14:15 Notice length single-sourced: AI announce lines use {hours} from social-minds.yaml pact_notice_hours, Empire button/flash use :hours from DiplomacyService::NOTICE_HOURS, and a new test (Modules/AI/tests/Unit/PactNoticeHoursTest.php) pins NOTICE_HOURS to the yaml. Pest 78/78.
- 2026-11-07 14:35 nt2 launched: notice rule on fresh seeds 21-40 to size the surprise-break share (nt had 2/85).
- 2026-11-07 15:00 nt2 (seeds 21-40) surprise 0.032, kept 0.50, 9.6 pacts/season. Pooled nt+nt2: 6 surprise breaks in 277 pacts (0.022) = one per 5 seasons, each by a cunning leader with grievance after a fresh hit. Decision: keep. The 0.3% M5 floor is a borrowed Diplomacy-AI benchmark (FOREIGN-MINISTRY M5: 'no real rate exists'), not OGame data; OGame sources say NAPs are 'often broken' and the surprise break is the punished sin - rare, reasoned and reputation-costly matches that. Integrity gauge closed for now.
- 2026-11-07 15:40 inter_ally diagnostic (nt2 seeds 21-25, 23k attacks): member->non-member 0.45, member->member 0.14, non-member->non-member 0.20 - members raid independents; single-source band, watched not chased. HDS FAIL source found instead: verdict FAIL is driven by 563 `login_hours_18_or_more` violations (host bot rule: ≥18 distinct hours touched in a week). Parity gap: the sim sleeps 6-8 h (economy.rs) while the live module keeps a 9 h core dark (SessionPlanner::CORE_DARK_MINUTES 540). Arm `dark9` (season.rs: sleep_hours = 9) built as social-dark.bin; run dk (seeds 21-30) vs nt2 same seeds.
- 2026-11-07 16:10 dark9 vs nt2 (seeds 21,22,24,25, health PASS both): login_hours_18_or_more 526 -> 0 (host bot rule now clean), HDS in-band 0.742 -> 0.733, AUC 0.997 -> 0.996. Seed 23 has one frozen account-day under dark9 (health BROKEN on that seed; 1 of ~15k active days) - to trace. Adopted `dark9` into the reference arms for parity with the live module's 9 h core dark (SessionPlanner::CORE_DARK_MINUTES): reference = warfleet alliances=6 diplomacy bands persona minds cycles skill raids dark9. HDS verdict still FAIL on in-band share (<0.75): the giveaways are galaxies (54% out of band) and growth (52%), economy-side, not social.
- 2026-11-07 16:40 Frozen-day trace (seed 23): zero-production login days 2 (nt2) -> 14 (dark9), all in the strongest accounts (10, 27, 48), each followed by a day booking ~2x production: production is booked late (to the next day), an accounting/day-boundary artifact, not a stalled player; census health flags only the one row that also has no stock/score/fleet move. Lives in economy/world accounting (other session's files: world.rs/players.rs) - logged for them, not patched here; dark9 stays.
- 2026-11-07 17:10 HDS bottom cells are structural: real bottom = near-inactive (bottom|128-256 real p50 157 pts vs ours 3,287) while the owner's world has no quitters; galaxies/growth giveaways sit there - not chased. Launched w500n (500 acc., 3 blocs, full reference incl. raids + notice + dark9, seeds 1-2, 1 h cap as approved for big worlds) for the late-game read.
- 2026-11-07 17:30 w500n at day 112: rank-1 0.020/0.019, r1/r10 1.78/1.46, ally 0.61; 1.5 pacts/season, none broken yet. Running to the 1 h cap.
- 2026-11-07 17:50 w500n day 160: no diplomacy event after day 128 in either seed (as w500s); three equal blocs (top alliance 0.30/0.29 of points, real 0.49), no ruler, no weaker target, so no war/uprising: a dead late game, against the owner's grinding-war world. Next: diagnose which war trigger fails among equal blocs once the run ends.
- 2026-11-07 18:20 w500n seed 1 attack mix (45k attacks): member->independent 26.7k, independent->independent 10.6k, inter-alliance 2.7k, on own mates 1.5k (mate_target - to check), independent->member 3.3k. Inter-alliance raids per 16 days: 736 (d16) -> 168 (d128) -> 7 (d208): the one grievance war (bloc A vs B, open all season, no peace event) fizzles while B-C hold a flank pact; no new war because no other pair builds grievance. Diagnosis pending end of run: why the A-B war never makes peace, and why the strong blocs raid independents instead of each other.
- 2026-11-07 18:45 w500n final (cap): seed 1 day 208, seed 2 day 224; rank-1 0.019/0.018, r1/r10 1.87/1.60, top alliance 0.29. Seed 1: one grievance war (day 13) never ends; seed 2: war day 13 -> peace day 172 -> secret pact. Cause: club grievance is a SUM over members, so in 160-member blocs a few raids keep it above the peace line (and wars are nominal). Delegated: size-aware grievance (sum / max(1, members/REFERENCE_MEMBERS)) in sim + PHP, checked on the 64-world (must stay) and a 500-world rerun.
- 2026-11-07 19:10 Size-aware grievance worker: sz (64-world, seeds 21-30) done, w500z (500-world) running; waiting for its report.
- 2026-11-07 19:30 w500z partial (day 160/144): seed 1 no war at all, seed 2 one war that made peace; no event after day 128. Normalising grievance ends the stuck war but also removes war onset among equal blocs - the late game stays quiet. Waiting for the worker's full comparison before deciding.
- 2026-11-07 19:50 Sources for war life (DIPLOMACY-BETRAYAL.md §2 lines 80-86, PL war rules 2021/2024, MED-HIGH): wars are alliance vs alliance, declared with end conditions (loss threshold or a date 1-3 months out), max 3 wars per alliance, and a war with no valid battle report for 3 weeks is closed. Candidate rule if size-normalised grievance kills war onset: keep summed grievance for onset, and close a war after 21 days without a battle between the sides (sourced), plus a 1-3 month horizon. Decide after the worker reports.
- 2026-11-07 12:15 Size-aware grievance (sim diplomacy.rs + PHP AdvanceAiAlliancePoliticsAction::grievance, one rule): club grievance = sum of member threats / max(1, members / reference_members), reference_members 6 (alliance-war.yaml; median club of nt2/dk 64-worlds 6.4, largest 8-10), constant test + bloc test in both. 64-world sz vs dk (seeds 21-30): wars 196/189, peace 171/164, late wars 81/66, gauges equal (rank1 0.105/0.106, r1/r10 4.13/3.99, inter_ally 0.23/0.18, balance 0.82/0.80), integrity equal, HDS 0.734 (dk 0.733), health PASS, 0 violations. 500-world w500z vs w500n (1 h cap, days ~217): the endless war is gone, but so is the war: seed 1 no war at all (was 1 endless), seed 2 one war day 86 -> peace day 104 (was 2 wars, peace day 172), no war after day 128 in either; inter_ally 0.001/0.008 (was 0.087/0.051), rank-1 0.019/0.018, r1/r10 1.82/1.50, top alliance 0.29/0.30 unchanged. Diagnosis: 160-member blocs barely raid each other unless at war (they raid independents), so a size-fair grievance never reaches the line; the next lever is why blocs avoid each other, not the grievance scale.
- 2026-11-07 20:30 Decision: keep size-aware grievance (reference_members 6; 64-world unchanged: wars 189->196, HDS 0.733->0.734; 500-world stuck war gone). Not adding more war triggers: two local fixes on the 500-world late game without a livelier world (circle rule). Finding: blocs do not raid each other (sim spares only mates and pact/vassal partners - world.rs spares - so the cause is target choice: nearest-by-yield candidates are independents and spared mates), inter-alliance raids 0.001-0.008 of attacks there. Real cause of wars between equal big alliances: NOT FOUND in our sources (DIPLOMACY-BETRAYAL §2). Open item for the target-choice owners (players.rs/errands.rs), not patched here.
- 2026-11-07 21:00 Owner: "decide, save the work, clean up". Decisions: Phase D gate -> (c): no social RL; the engine-driven teacher (Phase C) goes live (Phase E), (b) social-outcome gauges stay as monitoring. Big-world late wars: not forced without a sourced cause. Saved: Modules/AI branch `social-world` (c289e834, includes other sessions' uncommitted module work), host branch `social-world-empire` (83013ade Empire module + slot; 81c3ac30 shield-dome WIP as found). Cleanup: removed per-season raw traces (ready-*.jsonl, battles) of finished social runs except the references `sz` and `w500z`; summaries (social.json, diplomacy, decisions, levels, gates) kept. runs/social 66 GB -> 3.1 GB.
- 2026-11-07 21:20 Phase E started: live smoke run delegated - ai:sim on a sim/in-memory DB with minds, cycles, skill, Empire and human-pressure on for the run only, ≤15 min wall; checks errors, politics counts, deed-vs-speech (new read-only check), human-experience report.
- 2026-11-07 21:45 Owner: commits on main. Done by fast-forward: host main = 81c3ac30, AI module main = c289e834 (not pushed: AI main is 480 commits ahead of origin/main, host main 2 ahead of origin/main and 37 behind upstream/main). Branch review: all old branches are merged or obsolete (pr-1630/pr-1625 fixes already in main; module-system only a merge commit; AI claude/friendly-hypatia only harness state). Stalled work: 17 failing module tests now on main (delegated fix, then commit), Strong-player track stopped by the owner on 6 Nov (STRONG-PLAYER-PLAN last entry), open items: levels.json alliance writer bug, late production booking (frozen days).
- 2026-11-07 22:10 Cleanup (owner): deleted season traces (*.jsonl > 20 MB) across storage/rl/ogsim/runs except references social/sz and social/w500z (173 GB); kept policies, checkpoints, training labels/states (47 GB), summaries; git gc on both repos; docker unused build cache pruned (3.5 GB); old social binaries removed (kept size/dark/notice/lever/bc/raids3). Disk 348 GB -> 113 GB used. Research corpus (storage external sources) kept.
- 2026-11-07 22:40 Owner: a big realistic PHP-world simulation with every social cognition engine live, to see if the work pays off and to list all errors. Extended the Phase E smoke worker: ~300 AI accounts, ≥6 AI-led alliances + independents, real routines, up to 2 h wall (owner-approved), --workers, periodic --save-sqlite; collects errors, engine failures, politics over time, deed-vs-speech, dominance, raids/spies.
- 2026-11-07 22:40 Module suite green on main (host PHP; only environment errors left: no FFI, storage owned by www-data, /var/www, Redis): stale tests updated for deliberate changes (domes lead the doctrines, fleet export v7, ACS needs three sides, pleasantry rule), moon-size reads allowed for the Death Star strike only, deterrence_ratio moved from farm.yaml to alliance-war.yaml, "surrender" quotes reworded, StageAiAccountAction keeps the last field for the field raiser (flaky mid-game test).
