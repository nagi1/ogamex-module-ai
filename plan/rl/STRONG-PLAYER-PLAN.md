# Strong player plan (owner, 3 Nov 2026)

Owner direction, 3 Nov: use what proven game AIs do (`proven-game-ai-research.md`); make sure the game is fully
simulated; use the real-player data; build a very strong player and judge every training candidate against it as a
side score; aim at strong PvE intelligence.

## Measured facts this plan stands on (3 Nov)

- **Throughput.** A whole Discoverer season (365 days, 64 accounts, scripted) takes 189 s on one idle thread (887 MB,
  most of it the shared catalogue). The 22-minute ES generations were load and the model, not the simulator.
- **The world can be copied.** `Universe` and `World` derive `Clone`. The test
  `season::tests::a_copy_of_the_world_leaves_the_original_untouched` shows that a copy run forward with fresh dice
  (`World::reseed`) leaves the original playing exactly as a never-copied world does.
- **Look-ahead beats the script by a wide margin early.** `Universe::lookahead` is the rollout algorithm: every legal
  build, and waiting, is tried in copies of the world with fresh dice, every account then plays its persona for 12
  hours, and the pick whose account ends worth the most wins. On 4 seats over 10 days it ended 7-13x the points of the
  same seats played by the script (seed 1, 3.5 min wall).
- **Strength ladder from the real data** (`rl/scripts/strength_ladder.py`, `storage/rl/ogsim/strength-ladder.json`):
  per age x speed, the points of real rank 1, top 1%, top 10% and median.
  - **Early game:** our scripted accounts are far below real strong players. At 80 speed-days (day 10 of a speed-8
    season) the scripted median is 7k against the real rank 1's 4.2M; the champion's 68k is still far short. The
    opening is where the strength is missing.
  - **Late game:** the scripted active median is above the real top 1% at day 365 (284M against 273M, at 2 920
    speed-days). Either the world is generous or real ages are overstated (a universe is aged from its oldest
    alliance). Open question, see F3.

## Compute split (owner, 3 Nov: Rust to the fullest, lean on the GPUs)

- **Rust (CPUs):** every season, every look-ahead and every label is played in `rl/ogsim`. PHP is out of the training
  loop; it stays an occasional rule spot-check only.
- **GPUs:** the student is trained on the 4070 (`ogrl.train_exit`, `cuda:1` under `CUDA_DEVICE_ORDER=PCI_BUS_ID`).
  Next on the GPU, a value network that predicts an account's worth some hours ahead. It replaces most of each
  rollout (AlphaZero's value head), so the champion costs a fraction of today's ~20 CPU-hours per season.

## Work order

### Strong player and side score
- **S1 Champion.** `champion=STRIDE:OFFSET:HOURS:ROLLOUTS` (examples/season.rs) hands the builds of every account
  whose index is OFFSET modulo STRIDE to `Universe::lookahead`. Output lines carry `champion: true`.
- **S2 Side score.** `champjudge.sh` (train4) plays the champion on 1 seat in 8 against the best model on the rest,
  full season. The candidate's side score is its result on the same seats in the candidate-only judge (paired, same
  seed): the share of seats where it beats the champion, and the paired log-ratio. Every judged candidate gets it.
- **S3 Expert iteration (AlphaZero / ExIt).** Record the champion's choice at states the student visits (DAgger), clone
  it into the policy, judge, and repeat with the improved policy as the rollout policy. Proven route on a known
  simulator (Kore, StarCraft build orders, Hungry Geese in the research).
- **S4 Stronger champion.** Rollout with the best model instead of the persona; tune the horizon and rollout count by
  the side score, not by feel; extend look-ahead to the fleet errands and expeditions.

### Fidelity
- **F1 Whole-world replay against PHP, longer** (deprioritised by the owner's Rust-first direction, 3 Nov). The 28-day
  W14 v9 replay holds 0 differ for 3 seeds but has not flown recalls, missiles, moon shots or moons. Rerun only as a
  spot-check when the rules change.
- **F2 Champion as exploit finder.** Anything the champion does far above real rank 1 at its age is checked for a
  simulator bug before it is trusted. The ladder is printed for every champion run.
- **F3 Late-game generosity.** Find why the scripted median passes the real top 1% late (world settings, expedition
  yield against rank-1 points, the ladder's ages) before reading late-game strength.

### Real data
- **D1** The strength ladder judges strength by age (done). HDS and the Turing AUC stay the realism measures.
- **D2** Real late battles (OGMEM, 599) give fleet compositions and sizes for the yard and the champion's fleet play.

### PvE
- **P1** Champion seats are the strong opponents of the world. Later a league: past champions and past models in
  seats, so candidates train against players that adapt (research point 3).

## Log

| Date | What | Result |
| --- | --- | --- |
| 3 Nov | World copy, `lookahead`, `champion=` arm, strength ladder | test passes; 10-day champion 7-13x the script on its seats |
| 3 Nov | Full-season match, champion 8:0:12:2 vs best-es3-s10 (sampled, expofull), seeds 1-3 (`champ-h12r2`) | stopped at day ~41: champion seats x4.1 day 10, x6.4 day 20, x3.9 day 30, x1.7 day 45 (one seed): the 12 h champion fades |
| 3 Nov | Label pipeline smoke: 3 days, 8 seats, 799 decisions; the best model agrees with the champion on 30.4% | works; GPU training 6 s |
| 3 Nov | ExIt labels iteration 1: 30 days, champion 4:1:12:2, seeds 101-106 (`exit-it1`) | stopped at ~18k decisions: labels are myopic (below) |
| 3 Nov | Label read: half the decisions have the two best rows within 0.01%, so agreement is the wrong measure; regret (worth given up against the champion's pick) is used. The best model picks robot factory / astrophysics / shipyard / lab most; the champion picks metal mine / energy tech / crystal mine / stores | regret 1.57%, median 0, top 5% of decisions carry 53% |
| 3 Nov | Student w256 cloned from the 12 h labels, 30-day judge vs best-es3 on seeds 1-10 (`paired_days.py`) | **day 3 x3.9 (10/10 seeds), day 10 x0.96, day 30 x0.22 (0/10)**: it learned "mines now" and never builds the facilities that pay later. Not kept |
| 3 Nov | Side score so far (champion seats vs the same seats under best-es3, seeds 1-3) | day 10 x4.1, day 20 x6.4, day 30 x3.4: the champion's lead also shrinks after day 20 |
| 3 Nov | Root cause: a 12 h rollout scored by worth at the horizon favours what pays back inside 12 h. Fix (AlphaZero's value network): `champion-value=` scores a rollout's end by a critic's estimate of the season's end; `value-out=` records states, `ogrl.train_value` trains the critic on the GPU | data running (`value-es3`, 12 seasons) |
| 3 Nov | Critic (points 30 days ahead): held-out error 6%, R2 0.98 (year-end target: R2 ~0, every active account ends within ~10%: late game is build-time bound, 13 planets at mine levels 36-44, 23 G unspent, expedition income ~ production) | trained |
| 3 Nov | Critic-scored champion (`champion-value=`), 12 h and 6 h, width 4, seeds 1-3, 30 days | **worse than the model**: x0.25 / x0.45 at day 10, x0.84 / x0.78 at day 30. Rows differ by 1-2% after a few hours, inside the critic's 6% error, so its bias picks. Not kept |
| 3 Nov | Longer look-ahead: champion 24/36/72 h, 1 rollout, width 4, seeds 1-3 (`champ-h24/h36/h72`) | stopped, confounded (three knobs at once; width 4 lets the weak model choose which rows are tried): h36 x2.4 day 10, x2.2 day 20, **x1.17 day 30** against h12 full width's x3.9. Short-sightedness is not shown; the fade also fits the late-game convergence |
| 3 Nov | Champion fixed at 12 h, 2 rollouts, full width (the strongest measured). The student's collapse is BC's compounding error (it reaches states the champion never labelled); fix is DAgger: `champion-label` makes the champion's seats record labels while the policy plays | round 1 running: student-w256 plays, 45 days, seeds 301-306 (`dagger-r1a/b`) |
| 3 Nov | DAgger round 1 -> student 2 (w256, 44.6k labels: champion states + student-1 states), 30-day judge vs best-es3, seeds 1-10 | **day 3 x4.8, day 10 x1.70 (10/10), day 20 x1.01, day 30 x0.80 (3/10)**; student 1 was x0.96 / x0.28 / x0.22. DAgger works; more rounds needed for later days |
| 3 Nov | DAgger round 2: student 2 plays to day 60, seeds 311-316 (`dagger-r2a/b`) | stopped at ~day 15 for the fast champion; its labels kept |
| 3 Nov | Owner: every player plays all of the game, early, mid and most of all late. Cost measured per 12 h rollout (64 accounts): copy 4 / 28 / 118 ms and play 289 / 445 / 381 ms at days 30 / 120 / 250; with the other accounts only running their economies the play is 11 / 55 / 27 ms | `champion-solo` and log-free copies (`World::take_logs`, ledger cursors rebased; test: a look-ahead leaves the world as it found it) |
| 3 Nov | Fast champion (12 h, 2 rollouts, full width, solo) vs best-es3, seeds 1-3 (`champ-solo12`) | **x4.2 day 10, x5.9 day 20, x3.5 day 30** (full world: x4.1 / x6.4 / x3.9), ~6x faster: adopted |
| 3 Nov | Champion plays the fleet too: `champion-errands` (`Universe::lookahead_errand`: every offered errand tried in copies, then the login's build), labels by `champion-errand-out=` | built |
| 3 Nov | Full-season DAgger round 3: student 3 (78k labels) and bc-errand play every seat, the fast champion labels builds and errands on 1 seat in 8 from day 1 to 365, seeds 321-326 (`full-r3a/b`); student 3 full-year judge seeds 1-10 (`short-s3-365`) | DAgger running |
| 3 Nov | Student 3 full year vs best-es3 (sampled, seeds 1-10) | **x0.93 day 10, x0.22 day 30, x0.26 day 60, x0.28 day 120, x0.31 day 240, x0.38 day 365 (0/10 seeds)**; against student 2 itself x0.71 day 3, x0.55 day 10: more labels made it worse. Suspect: the judge samples the student, whose soft targets (tau 1%, half the decisions near-tied) leave it spread over ~10 rows (held-out loss 2.3 nats), while regret measures its argmax. Greedy judges of students 2, 3 and best-es3 (`short-*-g30`) |
| 3 Nov | Greedy (argmax) vs best-es3 sampled, 30 days, seeds 1-10 | **student 2: x7.3 day 3, x3.0 day 10, x2.2 day 20, x1.20 day 30 (8/10)**; student 3: x4.2 day 3 then x0.004 day 30; best-es3 greedy x0.32 day 30. Judging a student sampled undersold it. Root cause of student 3's collapse (traced on seat 0, seed 1): it never builds the deuterium synthesizer, so the robot factory, lab and all research stay unaffordable; from day 1.1 its only legal row is waiting, and one-row decisions are not labelled, so DAgger never sees the trap |
| 3 Nov | Why it missed deuterium: 77% of label decisions have their two best rows within 1% (median 0.03%), inside two rollouts' noise; trained equally the student agrees with the champion on ~22% even of its training data, deuterium included. Fix: `train_exit` weights each decision by its stakes (the worth a uniform legal pick gives up, advantage-weighted imitation) and reports high-stakes regret | student 4 (160k labels incl. full-r3 to ~day 75): held-out regret 1.60%, high-stakes 4.50% (student 3's data: 2.1%); greedy judges 30 and 365 days, and student 2 greedy 365 (`short-s4-g30/g365`, `short-s2-g365`) |
| 3 Nov | Student 4 greedy, 30 days, seeds 1-10 | **vs best-es3: x7.6 day 3, x3.2 day 10, x4.0 day 20, x1.78 day 30 (10/10 at every day)**; vs student 2 greedy: x1.03 / x1.08 / x1.80 / x1.49 (9/10 at day 30, the gain carried by the accounts that grow most). Kept as the best student. Students are judged greedy from now on |
| 3 Nov | Full year, greedy, seeds 1-10, vs best-es3 | **student 4: x3.2 day 10, x1.78 day 30, x0.63 day 60, x0.43 day 120, x0.36 day 240, x0.37 day 365 (0/10 from day 60)**; student 2: x0.14 at day 365; student 4 vs student 2 x2.6 at day 365 (10/10). Late game is lost: student 4 stops researching after ~day 30 (research points 191k at day 365 against 29M), stays on 7 planets (best-es3 12), and from ~day 100 its build queue is idle with 26 G unspent at day 120. Trace of seat 0 to day 90 running |
| 3 Nov | Trace, student 4 greedy, account 1 (median at day 90), champion labels on its states | after day 30 the student waits on **~50%** of its decisions (champion ~5%) and over-picks lab, terraformer, silo, shipyard; the champion picks stores and mines. The champion itself almost never researches (about 2 legal research rows; research is only a stock-to-points swap inside 12 h). The late-game labels the student lacked are being collected by the full-year DAgger; whether the champion itself wins the late game was never measured past day 45: full-year champion match, 8:1:12:2 solo, seeds 1-3 (`champ-year12`) running |
| 3 Nov | Speed: a label round searched every decision of 8 seats (~70 a day per late account, ~12 h a season). `champion-every=N` (label mode: a hashed 1 in N of the seat's decisions) and `champion-from=DAY`; the full-r3 round stopped at days 124-194 (its labels kept). Round 4: student 4 greedy on every seat, champion 4:1:12:2 solo, every 8, from day 20, errands too, seeds 331-342 (`full-r4`) | running |
| 4 Nov | Inference speed: a 30-day season took 300 s with student 4 choosing against 28 s scripted (inference ~90%). The GPU does not fit (one tiny call per sequential decision); the Rust forward pass now keeps each layer in one flat buffer with an 8-lane dot product and sums joint.0's state half once per decision | **80 s, identical points on all 1,920 account-days**; `season-exit` (the judge binary) swapped for it |
| 4 Nov | Full-year champion match (`champ-year12`, 8:1:12:2 solo, seeds 1-3) vs best-es3 on its seats | **x4.1 day 10, x2.8 day 30, x1.36 day 60, x1.09 day 90, x1.00 day 120 (1/3)**: the 12 h champion is even by day 120 (student 4 x0.43 there). Stopped. Copying it cannot win late; the champion needs a longer window late |
| 4 Nov | Hand-written weights experiment (a Sonnet subagent, no hints): its own payback rules score ~0.001x of the students; weights that copy the `advised` row (the persona's pick, waits included) beat student 4 x1.9 at day 365 but trail best-es3 x0.71. Letting rules override the persona's waits cost a lot (x0.50): the persona's waits fund its yard, fleet and expeditions (one seat, untested) | `handmade/`, `short-hand-v3` |
| 4 Nov | `champion-late=DAY:HOURS`: the same match, 48 h look-ahead from day 60 (`champ-late48`) | stopped by the owner's ten-minute rule |
| 4 Nov | Owner: no run over ten minutes, late game only. **Late bench** (`examples/late`): each seed's world played by the personas to day START once, copied per candidate, the same WINDOW with the same dice; paired log-ratio of points, production, planets, research. Day 120 -> 365 on 3 seeds in 6.5 min | best-es3 1.000, persona 0.961 (planets 12.5, research 27.9M), student 4 0.727 (planets 11.5, research 14.4M): the full-year ranking |
| 4 Nov | Champion late with best-es3 playing its rollouts (`rollout_policy`): 12 h worth x0.991 on its seats over days 120-122 (worth is the next hours' production, which late no build moves); scored to the season's end (`value_to_end`: worth + hourly production x time left) x1.000; pruned to width 4 over 5 days x0.997. No late headroom found by look-ahead | parked |
| 4 Nov | Late ES in the bench (24 day-120 positions, 30-day windows, per-account noise, antithetic, Adam; 41 s a generation): 11 generations, judged 120 -> 365 on seeds 1-6 | x0.998 (2/6): no gain |
| 4 Nov | Handover (bench from day 0 to 200, seeds 1-3): student 4 then best-es3 from day 30 **x1.000**, from day 45 x0.939, student 4 alone x0.281. Student 4's early lead (x1.78 at day 30) is gone by day 200 even under the best model: the late game converges to one ceiling, build time, and best-es3 sits on it. Headroom left: stock unspent late (~20 G a median account at day 365, ~7% of its points) and what the yard and the scripted parts do | next |
| 4 Nov | Tactics on the late bench (`with:`), days 120-365, seeds 1-6: yard cap removed (best-es3 x0.969, persona x0.965): military 82M -> 146M but research 27.6M -> 18.0M and production x0.81; the year-end stock is not free surplus | not kept |
| 4 Nov | Late income, seed 1, top 10 accounts at day 120: expeditions 147-172 G each over days 120-365, loot 0-0.7 G, no cargo lost. Late game is an expedition race and the strong never fight | handed over: `STRONG-PLAYER-HANDOFF.md` |

## War direction and baseline — 4 Nov 2026

Owner: late-game alliance wars and strong opponents, using real tactics; do not train a policy to exploit a reward or
simulator loophole. Speed work must be measured and runs remain under ten minutes. Points are descriptive, not the
war objective.

Real examples (selected public reports, not a representative statistical sample): the 22 June 2025 Imai ACS report
records three players watching a target, relocating, destroying its moon and timing a fleet interception, with
53,777,391,000 total units lost; the report breaks that into 3,844,144,000 attacker and 49,933,247,000 defender units.
https://board.en.ogame.gameforge.com/index.php?postID=7251146&thread%2F852099-acs-top-07-tot-53-777-391-000-silent-agony-sage-shadex-vs-blessed-by-thor-a-3-84%2F=
OGMem ranks its public reports by total units lost, not fleet size or player points; these records are selected
extremes, not a population distribution: https://ogmem.com/cr/rankings

Current structural blocker: world.rs mates() treats every active alliance member as part of the same alliance;
season.rs maintains membership but no alliance identity. Therefore the simulator cannot represent two opposing
alliances. join_raid in players.rs adds cargo for loot, not combat fleets for defeating a stronger opponent; raid_plan
is restricted to one fighter and one cargo type. Fleet saving uses a transport and an empty hold. These limitations
must be resolved and validated against host rules before declaring war tactics simulated or training against them.
The expedition simulator mirrors the host, but parity with the host is not independently verified parity with current
OGame. In particular, the host find function has no apparent fleet structural-integrity cap; check source/version and
host policy before changing it.

Added optional war-report to examples/late.rs: reports attack and moon-destruction fights, fights involving the player
ranked first at the start, battle debris, and ACS join arrivals. The boss is fixed at START; counts include ordinary
raids and moon shots and are not a measure of alliance war or training reward. On one seed, day 120 to 150, standard
persona arms in shortjudge.sh: 224 fights, zero involving the starting rank-1 account, 143,358,000 debris units, zero
ACS join arrivals. Baseline 55 s; same world with reporting 56 s; one measurement each is not a speed comparison.
The printed economy aggregates matched. Data under storage/rl/ogsim/r8w, seed 1, 64 accounts; no model trained.

Next: model distinct alliance identity and hostile teams through the existing world and ACS rules, with seeded tests
for friendly and hostile actions; then a controlled late-game boss scenario that exercises scouting, mixed-fleet ACS,
moon destruction, defensive saves and recycling. Report event traces and ship/resource losses separately, and profile
the scenario before optimizing. Compare multiple seeds and check host parity before treating outcomes as credible.

### Multi-alliance foundation (4 Nov, later)

The single-alliance limitation above has been addressed for an experimental scenario: alliance_at retains membership
and join time, and alliance_id identifies the alliance. With alliances=N, account IDs are assigned round-robin to N
alliances; this is a controlled experiment, not observed social behavior. Without the option, historical behavior
remains. ACS support now respects identity. In that scenario, the raider and errand candidate menu avoid attacking
allies, and moon-shot assistance only targets allies; direct dispatch retains host legality. The existing rules still
need real membership dynamics, diplomacy, explicit enemies and coordinated combat-fleet tactics.

Three alliance membership and ACS Rust tests passed using the local catalogue, as did the hostile errand test.
With the standard persona arms, alliances=2, seed 1, days 120-150: 287 attack/moon-destruction fights, 131 between
accounts assigned different teams, 9 involving the account ranked first at day 120, 162,541,500 debris units and zero
ACS join arrivals; 53 seconds wall. Team assignment does not establish actual membership at each historical fight, and
moon shots and ordinary raids are included. These numbers do not demonstrate a coordinated war or show a speedup.
Host PHPStan reports eight errors in unrelated host files; the parallel PHP test cannot connect to MySQL. A host
parity replay remains outstanding. No reward or model training was changed.
The same seed replayed without alliances=N matched the pre-change reported fight, boss, debris, ACS and economy
aggregates (224, 0, 143,358,000, 0 and 75.3M average points respectively; 56 s); this is a limited aggregate check,
not a complete digest or host parity proof. A five-second profiler capture was collected in /tmp/ogame-war-perf.data;
the release report showed raw addresses and requires symbolization and a longer representative capture before
choosing an optimization. Timing 53 vs 56 seconds on different scenarios is not a speed benchmark.

### Combat fleet support (4 Nov heartbeat)

For the opt-in multi-alliance scenario, a player can now join an ally's already-flying attack with its available
military ships when a fresh shared espionage report includes fleet, defence and research, the original union is
estimated unable to defeat that force, and the combined fleet passes the existing three-round attack/hull safety
estimate. The target's research comes from the report, not the world's hidden current state. The existing world
join validates timing, fuel and union limits. The cargo-for-loot path and legacy scenario remain available. This is
an explicitly approximate estimate, not a battle simulator or a guarantee of victory, and currently sends all
available military ships. Existing raiders normally only launch attacks they already consider safe, so an ordinary
season is not yet expected to create this opportunity reliably; initiating coordinated attacks is still missing.

A seeded integration test sets up an outmatched attack and verifies that a combat fleet joins and actually participates
in the resolved battle. It also checks missing research, a stale report and a different alliance do not trigger support;
it passes using the local host-derived catalogue. Host parity for this player behavior is not established. Rector and
CS completed; unrelated PHP formatting changes were discarded. PHPStan again reported eight host errors and the parallel
PHP test could not connect to MySQL. No policy or reward was trained.
The existing two-alliance bench replay (same seed and arms, days 120-150) completed in 52 s and retained the earlier
aggregates: 287 fights, 9 boss fights and zero ACS join arrivals. Thus the integration test proves the new support path
can work in a constructed encounter, while the normal season still does not demonstrate coordinated attacks. This
single replay and the previous 53 s run do not establish a performance change.

### Find a strong rival (4 Nov heartbeat)

In the opt-in multi-alliance world, the daily existing highscore refresh now supplies a public ranked list. Raiders
scout the highest-ranked active non-allied rival before their nearby targets, including its moon before fleet slots
fill; the old scenario does not change. This closes the selection gap where a distant leader could never be offered to
the scripted raider. Rank and inactive status are public game information; this is a deliberately simple policy, not
evidence of human target selection or a guarantee that the stronger account is vulnerable. It does not initiate a
coordinated attack. A test demonstrates scouting a distant rival and its moon, skipping a higher-ranked ally and
preserving the legacy behavior. Another test checks that the list follows the daily score refresh. Both pass using the
host-derived catalogue. The release build succeeds. Rector and CS completed with unrelated PHP formatting reverted;
PHPStan still reports eight host errors and the parallel PHP test still cannot connect to MySQL. No reward was changed.
Seed 1, days 120-150, the same late-bench arms and two assigned alliances: 59 s, 11 espionage arrivals against the
starting rank-1 account, 102 fights overall, zero involving that account, and zero ACS join arrivals. The previous
version reported 287 fights and 9 involving its starting rank-1 account. The changed policy plays from day zero, so
the positions and battle trajectories diverged; this one seed does not establish comparative strength or identify why
fights fell. The result proves neither a boss attack nor an alliance war. Next, build an explicit communication and
coordination mechanism so a leader can plan an attack with a confirmed ally, exercise it in a controlled encounter, and
profile before claiming speed changes.

### Defending a moon (4 Nov heartbeat)

The host MoonDestructionMission collects ACS defenders, but the Rust world had omitted them entirely: a moon-destroying
fleet fought the moon owner alone, so allies could not save it. The world now includes fleets actually holding at the
moon during the strike, keeps their surviving units separate from the moon owner, applies their losses, and accounts for
their return. The scripted alliance defender can also answer an incoming moon-destruction mission and target the moon
itself. Battle draws no longer appear as an attacker victory when a defender survives.

A seeded scenario uses the real linked battle engine: an allied fleet is dispatched by the defender policy to a moon
under attack; it takes part in the battle, surviving defenders return, and their units are not added to the moon owner.
A member of another alliance is refused. The moon-focused Rust suite passed eight tests, including 300/300 pre-existing
host-recorded moon destruction cases (those cases have no ACS defenders). A direct host-vs-simulator replay for moon
destruction with ACS is still needed. The host calls startReturn for surviving ACS defenders with zero resources but
does not visibly mark that parent mission processed on this path; the simulator removes the holding fleet from further
battles once its return is created, rather than deliberately duplicating it. Verify that lifecycle against a live host
before claiming exact parity. No model, reward, or training run was changed.

Rector and CS completed (unrelated formatter edits discarded); PHPStan again reported eight unrelated host errors and
the targeted parallel PHP test could not connect to MySQL. No performance improvement has been measured. Next, build
explicit ally coordination and a controlled strong-opponent encounter; this fix makes moon defense in that encounter
possible without assuming the ally is absent.

### Asking an ally before an attack (4 Nov heartbeat)

The experimental multi-alliance, ACS-enabled season now has an explicit time-limited request/acceptance step. A leader
with a fresh report showing ships, defenses and research asks up to four alliance mates for help against a ranked
opponent it estimates it cannot defeat alone. A mate decides at its own login whether its available military fleet is
strong enough, fueled, and can travel at least as fast as the proposed lead fleet. The leader may launch at its own
next login after an acceptance less than one hour old; the mate must still log in and join through the normal world ACS
validation. Requests expire after six hours and are discarded if membership changes. The estimate uses only the shared
spy report for enemy composition and research, with the existing three-round heuristic. It does not read the target
current fleet or simulate the battle before deciding.

A controlled seeded Rust test exercised request, no launch before acceptance, acceptance at the mate login, leader
launch, ally join and the linked battle engine resolving one union. It also checked refusal when the report lacks
research, the mate is too far, an acceptance is stale, or the target becomes an ally. Existing alliance tests pass.
Acceptance is only a promise: the mate can be delayed, spend its fleet or fail the actual ACS validation, and the leader
does not yet cancel automatically when support fails. The time windows and four-mate limit are scenario assumptions,
not measured human behavior. This is not a host parity claim, a guarantee of victory, or proof that organic seasons
produce an alliance war. No rewards or model training were changed.

Rector and CS completed with unrelated PHP changes reverted. PHPStan still reports eight errors in host files; the
parallel PHP test still cannot connect to MySQL. The independent host replay for ACS moon defense remains open.

The ordinary two-alliance seed-1 bench, days 120-150, took 60 s and produced 5,663 coordinator logins with warships,
282 observations of a fresh full report showing ships, but zero where the report exceeded the leader three-round
estimate; consequently there were zero requests, acceptances, launches or ACS join arrivals. These are login-level
observations, not unique accounts or reports. The result is consistent with the handoff finding that scripted raiders
concentrate on easy targets; it does not prove why the starting leader was not a viable target. The forced encounter
proves the message and battle path works, but ordinary play has not produced a coordinated attack. Next inspect why
reports on top-ranked players lack a usable outmatched fleet opportunity, and compare controlled start positions rather
than turning thresholds to force a score.

### Scouted moons and colonies (4 Nov heartbeat)

A concrete target-selection bug limited coordination to capital planet reports: a rival could be scouted on its moon,
but the highscore iteration named only the capital, so the moon report was never considered. A seeded scenario with a
scouted moon failed before the change at the request step. The coordinator now examines the current account own recent
reports, orders their targets by the public rank of the owning account, and can use planets, colonies and existing
moons. It skips allied bodies and moons that have since been destroyed. Reports remain the source of enemy fleet and
research knowledge; the coordinator does not read the current enemy fleet.

The same scenario now passes through request, ally acceptance, dispatch, ACS join and a battle on the moon. The existing
planet-coordination test also passes. This establishes the missed target path and its repair, not that this omission was
the only reason the ordinary season had no coordinated attacks. The host PHPStan and MySQL-backed test issues remain;
no reward, training model or victory criterion was changed.
The same seed-1 two-alliance late bench, days 120-150, completed in 59 s: 5,663 warship-ready logins and 292 usable
fleet-report observations (previously 282), but still zero outmatched choices, coordination requests, or ACS joins;
its battle totals remained 102 fights and zero against the starting leader. These are observations, not unique
reports, and a single seed cannot establish a speed or strength difference. Next inspect the contents and completeness
of reports on the leading account and its moons, and whether fleets were actually present, before changing behavior.

### Boss reports and moon saves (4 Nov heartbeat)

A report-depth breakdown for the existing seed-1 two-alliance run, before the save fix below, found 6 espionage reports
on the starting leader planets (2 showing military ships) and 5 on its moons (all 5 showing military ships). All 11
revealed ships but none contained all three sections needed by the coordination planner. In this simulator the report
reveal thresholds are nested, so a missing full report here implies missing research; the reports do not establish the
exact fleets or prove what a human could have discovered. These are reports, not unique accounts or targets. The
planner correctly refuses to use unseen research. This points to shallow scouting as one concrete blocker rather than
a reason to weaken combat safety or reward combat.

While tracing the moon behavior, found and fixed two independent fleet-save errors. Normal attacks name the moon slot,
but the saver only watched attacks naming the parent planet plus a moon-destruction flag; it therefore ignored normal
attacks on its moon. A test failed before the fix and now verifies a normal moon attack triggers a save, an attack on
the parent planet does not, and a moon-destruction threat still does. Separately, a successful jump-gate escape was
mistakenly recorded as a transport save and could schedule a recall using an unrelated last fleet. A failing gate test
now passes: the fleet jumps away, and no recall is scheduled. Ten moon-related Rust tests passed, including 300/300
previously recorded host moon-destruction cases; the host cases do not validate the behavioral fixes. A same-seed
late-game run before the fixes took 60 s; the post-fix run is separate and may diverge in trajectory. No training or
reward changes.

The post-fix replay with the same seed and arms completed in 59 s and had the same printed battle and economy
aggregates as the pre-fix run; this is a narrow aggregate comparison, not a proof that the trajectories are identical
or that the changes improve speed. Direct report counts confirmed that all 11 leader reports revealed ships and
defenses but none revealed research; all 5 moon reports showed military ships. The next step is to investigate an
adaptive follow-up spy based on the report received and available probes, while respecting the risk of losing probes
and continuing to require observed technology for coordinated forecasts. Full host PHPStan still reports eight
unrelated errors and the parallel PHP test cannot connect to MySQL.

### Follow-up reconnaissance (4 Nov heartbeat)

The previous late-game run showed military ships and defenses in every leader moon report but no research in any of
11 leader reports. The raider previously treated every fresh report as complete enough and kept at most four probes,
so it never pursued the information the coordinated battle estimate requires. In the experimental multi-alliance
scenario, after a fresh partial report with observed military ships, a raider can now request more probes and send a
larger follow-up once the previous mission cooldown and actual availability permit. It doubles the previous count up
to 32; the fleet and the counter-espionage resolution still go through the host-derived world rules. A follow-up is
not sent when the visible military value is no greater than the estimate of probe loss using the prior reported
counter-espionage chance. The chance and the visible fleet may change after the report; this is a bounded heuristic,
not a guarantee of safety or a claim about exact human behavior.

A seeded test using actual world missions failed before the change and now sends four probes for a partial report,
then eight after the cooldown and gets research from a second report. A second test checks the risk refusal and that a
scout asks for more probes and waits for them rather than sending a short follow-up. Four raider tests passed,
including the longer seeded mixed-persona exercise. No combat outcome, reward or model was optimized.

Additional fidelity gap discovered while tracing this path: the existing scripted moon-destruction tactic reads the
current target moon inventory directly, and its phalanx decision also consults current hidden state. Treat those as
simulator omniscience, not credible player intelligence; replace them with information from spy reports or legitimate
observations and test that changing unseen enemy state does not change a decision before trusting boss-war results.

The first organic replay of that follow-up policy completed in 68 s but still produced zero coordinated attacks.
All 32 reports on its starting leader had ships, defense and research hidden, so the visible-military prerequisite
prevented any follow-up on that opponent. Since the modified policy plays from day zero, this is a different trajectory
from the previous seed-1 run, not a controlled strength comparison.

The policy now also permits a follow-up when the ships section is hidden for the highest-ranked public non-allied
rival, provided the previous reported counter-espionage risk times the probe cost is no more than one hour of the
scout own mine output. This is a simple explicit risk-budget assumption, not knowledge that a fleet exists. It retains
the previous 32-probe ceiling, waits for available probes and uses the reported chance, which can become stale. A
focused test failed before this change and passes afterwards; it also checks that a hidden non-leading target and an
unknown target over the production budget do not trigger a follow-up. Whether this produces full boss reports or real
coordinated battles in an organic season must still be measured.

Seed 1, days 120-150, with the top-rival hidden-ships follow-up enabled from day zero: 98 s; 47 reports on the
starting leader planets (13 full) and 50 on its moons (16 full), 9 outmatched choices, 36 alliance requests, 5
acceptances, 2 coordinated launches and 1 ACS join arrival. There were 160 fights, 2 involving the starting leader;
these counters do not attribute the leader fights to the coordinated launches. The earlier run had no full leader
reports or coordinated launches, but the world trajectory differs from day zero, so this one seed is not a controlled
causal, strength or speed comparison. The longer runtime requires profiling. It remains a simplified human model, with
unverified host parity and the previously identified moon-destruction information leak. The PHPStan and MySQL test
blockers remain unchanged.

### Moon-strike information boundary (4 Nov heartbeat)

The raider moon-destruction policy previously checked the target moon current hidden fleet, defenses, technology and
phalanx directly. A targeted test demonstrated that unseen ships triggered an attack without any espionage report.
The policy now requires a recent report with ships, defenses and research, evaluates fleet and combat strength from
that report, and only treats a phalanx as observed when the report includes its buildings. A known fleet or an observed
in-range phalanx can justify the existing strike heuristic. The world still resolves the real later battle using actual
state, so an outdated report can mislead the attacker; this is not a guarantee of safety or victory.

The failing test now passes: no report means no attack, changing unseen fleet numbers leaves the decision unchanged,
and stale reports are rejected. A second test checks that hidden phalanx changes do not decide the attack and a
reported in-range phalanx does. The moon-focused Rust suite passed 12 tests, including the 300 previously recorded
host moon-destruction cases; those cases do not constitute host parity for the AI policy. This closes the identified
moon-strike leak only. Other paths still consult target live research, including raid_plan, the cargo-ACS helper and
missile targeting; audit and replace those with observed research or a documented uncertainty treatment before
claiming the simulated player is non-omniscient. The previous results should not be treated as a fair intelligence
benchmark. No model or reward changed.
The same seed-1 two-alliance bench, days 120-150, completed in 98 s with the same printed aggregates as the previous
run: 2 coordinated launches, 1 ACS join arrival and 2 fights involving the starting leader. Those counters do not
attribute the leader fights to the coordinated launches, and matching aggregates do not prove identical event traces
or speed. Rector and CS completed with unrelated formatter edits discarded; PHPStan still reports eight host errors and
the parallel PHP test still cannot connect to MySQL. Next remove the remaining observed-target research leaks, then
profile real war scenarios and validate against host behavior.

### Feature-schema validation end to end (4 Nov heartbeat)

The version-2 guards were exercised against real artifacts, not synthetic tensors. A three-day and a ten-day
seeded season (`champion=1:0:2:3 champion-errands champion-errand-out=`) wrote champion errand labels plus their
sidecar; a two-account behaviour-clone checkpoint (`train_bc_world --season --errands`, CPU) carried the new
candidate names; `train_exit` accepted the labels and wrote a student; `export_policy` stamped
`_errand_feature_version: 2`, the season flew the exported policy, and a copy with that field stripped was
refused with "errand policy requires feature version 2". Four refusals behave as intended: sidecar version 1,
no sidecar, sidecar without a width, and a checkpoint with no feature names each fail with their own message,
and the control case still trains.

That exercise found a real gap in the champion errand path, not a schema one: `train_exit` inferred the
candidate width from a legal last row, which holds for build labels (their last row is the always-legal wait)
but never for errand labels, whose wait row is first and whose padding is zero. Every champion errand label
file therefore failed with "no row count fits the label files" unless `--k 32` was passed by hand — the raw
label writer had never been driven before. The writer now puts the width in the same sidecar as the feature
version, and `train_exit` reads it there; the manual flag still overrides. In the ten-day run 169 of 3,043
labelled logins had two or more legal errands and finite worths, which is what a young world looks like: a
login with no target in range is `wait` alone and is not looked ahead.

The simulator suite is green (107 tests). What this does not show: no model was retrained, no old economy
checkpoint or label set was migrated (they are separate schemas and stay rejected), and no benchmark was run
after the change. The remaining observed-target research leaks (`raid_plan`, the cargo-ACS helper, missile
targeting) and the ally-state reads in allied defence and moon-shot planning are still unaudited work.

### The errand loop did not run at all (4 Nov, found by running it)

`rl/scripts/errand-loop.sh` died before behaviour cloning: `pyo3_runtime.PanicException: index out of bounds:
the len is 24 but the index is 30`. `VecEnv.planet_count` returned `Catalogue::planets.len()`, and a catalogue made
with moons lists the moon configs after `first_moon` (24 homes + 24 moons = 48), so it handed out moon indices as
home planets and every `VecEnv`/`VecWorld` caller panicked on any moon catalogue — `train_bc_world` (non-season),
`train_world`, `train_ppo`, `transfer` and `econ.py` alike. `planet_count` now returns `first_moon`, the homes only.
The Python extension was rebuilt with maturin (`maturin develop --release`) before this run.

The loop then ran end to end: BC 40,000 rows, 98.2% agreement with the teacher, then PPO — **FAIL**, value versus
the scripted teacher −0.30% with 95% CI [−0.54%, −0.08%], 45% of 256 accounts better, 2% of 94,384 decisions
differing from the teacher, worst account −12.44%, settled at update 111 of 111. That is a deliberately shortened
run (ROWS 40,000 / MINUTES 3 / ACTORS 8 / WORLDS 16 against the script defaults 120,000 / 6 / 16 / 32) and it is a
failure, not a result: the student hardly leaves the teacher and what little it changes costs value. Output went to
`storage/rl/ogsim/runs/loop4nov` (the historical `runs/loop` is owned by the container user and cannot be
overwritten from the host). Nothing was committed; no checkpoints, labels or catalogue files were changed.

### The errand loop at its reference settings (4 Nov, evening)

First full-length run since the moon catalogue (`rl/scripts/errand-loop.sh` defaults: ROWS 120,000, MINUTES 6,
ACTORS 16, WORLDS 32, KL 0.5, seed 1; capped unit, `timeout 540`, learner `cuda:1`). **FAIL**: value versus the
scripted teacher −0.32%, 95% CI [−0.67%, +0.04%]; 43% of 256 accounts better; economy −0.55%; worst account −14.93%;
455 updates. Unlike the shortened run (2% of decisions differing) the student now differs from the teacher on 45% of
94,315 decisions, so it does move; what it changes does not pay. Periodic evaluations: −0.06% (update 20), −0.28%
(80), −0.32% (120), −0.45% (180), −0.48% (360), −0.38% (420), −0.32% (455). BC reached 100.0% top-1 against the teacher
in 3 s of training. Per-update approx KL printed 0.0000-0.0004 and clip fraction 0.00 throughout; explained variance
0.68-0.81. A Rust test build overlapped the last ~90 s of PPO, so throughput is not comparable. One seed: not a
result by the diagnostics rule (≥3 seeds). Read: the learner barely steps (KL ≈ 0, clip 0) yet drifts below the
teacher; per `rl-diagnostics` §1.2/§3 the next measurement is errand headroom over the teacher, not learner tuning.
Output: `storage/rl/ogsim/runs/loop4nov-ref`.

### Allied defence no longer reads the raider (4 Nov, evening)

Audit of the hidden-state reads named in the war handoff: `raid_plan`, the cargo-ACS join and missile targeting
(`defended_loot`) already read reported research (`report_research`) in the working tree; the ledger's post-fight
reads mirror a combat report; `FleetSaver` reads only its own event list (attacker and arrival); `moon_shot` reads a
mate's own guns (shared). The one omniscient read left was `defend_mate`: an ally sized its hold from the raider's
live research and the raid's exact composition. It now uses what the mate relays: the raid's amounts only when the
mate's espionage reaches the host's full-intel tier (`IncomingFleetIntelLevel::Full`, level 8; below it the host shows
total or types only and the ally does not commit), and the raider's research from the newest report the ally or the
mate holds on any of the raider's planets, else equal to the ally's own. New test
`a_mates_defence_does_not_read_the_raiders_unreported_research`: an ally sized at the margin holds against a raider
whose research is unreported even when that research is high, and is turned away when the mate's report shows it; the
L5 test now also checks the espionage tier. Seven alliance/defence tests pass. Not shown: no host replay of allied
defence, and whether the tier gate removes holds organic seasons used to make.

### Late-game war on three seeds (4 Nov, evening)

First multi-seed war bench, after the `defend_mate` change: `examples/late` on `r8w`, days 120-150, seeds 1, 2, 3,
`cand=persona=persona`, the `shortjudge.sh` arms plus `alliances=2 war-report`; 146 s, capped. Output
`storage/rl/ogsim/runs/war4nov-3seed/bench.log`.

| seed | usable fleet reports | outmatched | requests | accepts | coordinated launches | ACS join arrivals | fights | boss spies | boss fights |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | 86 | 26 | 104 | 12 | 1 | 0 | 59 | 22 | 0 |
| 2 | 49 | 4 | 16 | 0 | 0 | 0 | 63 | 0 | 0 |
| 3 | 42 | 19 | 76 | 14 | 0 | 0 | 263 | 46 | 0 |

No seed produced a fight with the starting leader or an ACS join arrival. The funnel narrows at accept → launch: 26
acceptances gave one launch. Seed 2's leader was never spied. Counts are login-level observations, not unique targets;
these are not victory scores and say nothing yet about why accepted plans do not fly.

### Why accepted war requests do not fly (4 Nov, evening)

`coordinate_war` now counts, per leader login, why an accepted request did not launch (`Ledger::war_launch_refused`,
printed by the `war-report` bench). Same 3-seed bench as above, aggregates unchanged (instrumentation only):

| seed | accepts | expired unread | ships away | leader login after the 1 h window | report gone | recheck failed | dispatch refused | launches |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | 12 | 11 | 0 | 31 | 0 | 0 | 11 | 1 |
| 2 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 |
| 3 | 14 | 14 | 0 | 20 | 0 | 0 | 5 | 0 |

Window and refusal counts are leader logins, not unique requests. A run that printed each refusal's reason (seeds 1
and 3, including the play up to day 120) gave 41 of 41 as "not enough resources": the leader could not pay the
deuterium for its own fleet. The ally's acceptance checked its own fuel; the leader's request never did. The request
now carries the same fuel check, so a leader that cannot fly asks nobody (test: a dry leader in
`allies_ask_accept_and_fight_a_coordinated_attack`; suite 108 green). That stops wasted requests and creates no
launch. Two causes remain: (1) late warship-holders do not keep deuterium for their fleet — `kept()` reserves the
builds being saved for plus two hours of production, with no fuel term — and (2) the one-hour handshake: the
simulator's ask → accept → launch is its own protocol, whereas in OGame the leader flies first, opens the union and
mates join within its delay limit. Next measure the leader's deuterium against its fleet's fuel at those logins
before choosing; do not widen the window to make a number move.
Measured (seeds 1 and 3, every outmatched leader login from day 0 to 150, temporary print since removed): 1,782 of
1,913 leaders could not fuel the attack; median fuel 9.4M deuterium against a median 242 held, the gap about 120 hours
of the capital's deuterium production. The store is spent to nothing at every login (the yard takes what the queues
do not need), so no warship-holder ever accumulates strike fuel; the handshake window is secondary. With the request
fuel check, the same bench gave 12 + 4 requests (was 104 + 76), 1 accept, 0 launches. The gate-3 behaviour is a
fleeter that keeps fuel for its fleet; that is a change to the scripted miner's reserve (`kept()`), the teacher every
economy result is judged against, so it waits for the owner.

### Where the deuterium goes, and the `fuelkeep` arm (4 Nov, evening)

Temporary sink counters (removed), seed 1, all accounts, days 120-150: building and research starts 46.9G deuterium
(83%), dispatch fuel 5.0G (9%), unit orders 2.8G (5%), dispatch cargo 1.5G (3%). The store is emptied by the build
queues, not the yard.

New world arm `fuelkeep` (off by default; teacher unchanged): a capital's persona build that pays deuterium waits
while it would leave the store below `strike_fuel` (the fuel to fly the home warships at full speed to the farthest
of the five nearest accounts). A first version put the reserve in `saving()` (unit purchases): it starved the
deuterium-priced probes and usable fleet reports fell 198/57/128 → 8/0/7 — reverted. In the build pick (bench
`cand=fuelkeep=with:fuelkeep:persona`, seeds 1-3, days 120-150, same day-120 positions):

| seed | fights off → on | cross-team fights | outmatched choices | requests | accepts | launches |
| --- | --- | --- | --- | --- | --- | --- |
| 1 | 61 → 75 | 47 → 66 | 134 → 212 | 12 → 4 | 1 → 0 | 0 → 0 |
| 2 | 63 → 187 | 4 → 39 | 8 → 58 | 0 → 8 | 0 → 0 | 0 → 0 |
| 3 | 263 → 282 | 6 → 0 | 79 → 57 | 4 → 4 | 0 → 0 | 0 → 0 |

Points ×1.003 (1/3 seeds up), production ×1.000: keeping the fuel costs nothing measurable. More fighting, still no
coordinated attack: the bottleneck moved to the ally's acceptance. Leaders that ask get no yes; the ally accepts only
if its fleet lands no later than the leader's (`flight > request.lead_flight` refuses), stricter than OGame's ACS join
window (a join may delay the union by up to 30% of its remaining flight). Next: count the ally's refusal reasons
before changing that rule. Test `a_fleeter_keeps_the_fuel_its_warships_strike_with`; suite 109 green. Output
`storage/rl/ogsim/runs/war4nov-fuelkeep2`.
Ally refusals, now counted (`Ledger::war_accept_refused`), same bench: with `fuelkeep` the allies refused 4 times for
a stale or missing leader report and 14 times on the strength test, never for landing after the leader or for fuel
(without the arm: 2 / 18 / 0 / 13). Each ally is judged as leader plus itself alone, while OGame's ACS pools up to
five fleets and the leader already asks four mates: the joint strength of several mates is never summed. The sample
is small (16 requests), and the larger gap is upstream: 327 outmatched choices gave 16 requests. Next split that gap
(the leader's fuel for the real target distance, which can exceed the five-neighbour reserve, versus no mate within
reach) before touching the strength rule.

### North-star measure: teacher against the best model (4 Nov, evening)

Owner redirect: judge by the north-star number, not by war counters. `examples/season`, 10 seeds × 365 days, 64
accounts, the `judge_policy.sh` world arms, run twice in parallel under the cap: the scripted teacher alone, and
`best-es3-s10` deciding builds (sampled) with the personas on everything else. 172 s and 242 s; health PASS both.
`hds.py` (output `storage/rl/ogsim/runs/hds4nov/{teacher,model}/hds.txt`):

| arm | HDS (target ≥ 0.75) | Turing AUC per cell (target ≤ 0.6) | violations | top giveaway |
| --- | --- | --- | --- | --- |
| teacher | 0.824 | 0.999 | 17,624 | growth, 78% out of band |
| model | 0.846 | 0.999 | 17,621 | growth, 80% out of band |

Both FAIL. The model is slightly closer on the bands (planets 19 → 14% out of band, military share 7 → 3%), but a
classifier still tells every cell from real players. Two causes stand out, neither of them war: (1) **growth** —
bottom and middle accounts grow far past real ones (bottom 128-256 days: ours p50 31,028 against real 157; middle
64,242 against 8,241) while the top ten per cent stay below (74,464 against 98,579); (2) **17,462 days without a
six-hour dark period**: the login schedule has no sleep the yardstick accepts. One seed set per arm; the HDS
difference is not yet a result.
Joint arm (builds model `best-es3-s10` + today's errand model, exported as feature version 2, flying 21 accounts'
fleets): HDS 0.845, AUC 0.999, 231 s — the errand model adds nothing over the builds model alone (0.846).

**The yardstick's two login constraints were inverted; `hds.py` now follows the settled rules.** (1) Dark period:
ESCALATIONS 26 Oct item 5 settled it as "every active day holds a dark stretch of at least 6 h", but `hds.py` still
counted a week *with* a 6 h gap as a violation, so its 17,462 "violations" were people sleeping. Now
`day_without_6h_dark` counts active days touched by no login-free stretch of 6 h, a night crossing midnight being one
stretch. (2) Login spread: §4 and ESCALATIONS (row 37 reading) say the host flags logins over 18 or more hours, and the
host does (`ServerAdministrationController::getBotActivitySignals`: fleet departures over ≥ 18 distinct hours of the
day in 7 days, with ≥ 50 missions and a per-slot rate); `hds.py` counted fewer than 18 *absolute* hours of the week.
Now `login_hours_18_or_more` counts account-weeks whose logins touch ≥ 18 hours of the day — stricter than the host,
which also needs the mission rate. Both are gated. Self-test unchanged: PASSED, HDS 0.859, AUC 0.540. Rescored:
teacher, model and joint all `day_without_6h_dark` 0, `login_hours_18_or_more` 525, `action_under_10s` 0; HDS and AUC
unchanged (0.824 / 0.846 / 0.845, AUC 0.999). Measurement only: no simulator or trainer change, no cost to a season.
Earlier rows quoting `dark_period_6h` or `login_hours_under_18` read the old definitions.

### Allies know only what a mate has seen (4 Nov, evening)

`World::online_at` (indexed by account) records the last moment each account decided, set on both login paths of
`next_decision` — one store per login. `defend_mate` considers a raid on a mate only when the raid left no later than
the mate's last time online (the mate saw it in its event list and could pass it on); `moon_shot` rules a mate's
planet out for an incoming attack under the same condition, except the shooter's own shots, which it always knows.
L5 test extended: a mate offline since the raid left has told nobody, so no hold; once it was online, the hold goes.
Suite 109 green. Teacher season, 10 seeds × 365 days, same arms: HDS 0.822 (0.824 before, inside the noise of one seed
set), AUC 0.999, hard constraints unchanged; mean season 140 s against 162 s before — not slower (the earlier run
shared the CPU with the model arm, so the gain is not a speed claim). Output `runs/hds4nov/teacher-told`.

### The growth gap: activity is not it (4 Nov, evening)

Per cell (model arm), growth is in band for top10 from day 64 (ours 55-99k against real 65-113k) and out everywhere
below: mid 46-89k against real 2.8-8.2k, bottom 26-74k against 63-157. Early top10 (0-14 days) is the model's own
gap: 259 against 10,137.

A first arm, `pace=LO:HI` (each account waits a log-uniform multiple of the shared real gap), scored teacher HDS 0.847
(1:4), **0.882** (1:16), 0.869 (1:64), seasons 136 / 110 / 92 s. It was then checked against what the deployed module
does and dropped: the module already gives each AI account an activity band (`RoutineProfile::presenceBand`:
casual 1-3, regular 3-7, active 7-12, hardcore 12-18 sessions a day, mixed per archetype in `AiPersonaFactory`), and
pace 1:16 sits far below it — it fitted the yardstick, not the player. It was replaced by the `bands` world arm (off by
default): each account draws the module's band for its persona (miner → Miner, raider → Raider, saver → Fleeter) and
a diligence inside it, and waits `(awake hours / mean real gap) / sessions` times the shared gap; the shared gaps give
about 7.6 logins a day, so every account sat between regular and active before. Result, 10 seeds × 365 days:
teacher HDS **0.819** (0.822 without), model **0.840** (0.846), growth out of band 75% / 78%, `login_hours_18_or_more`
557; seasons 163 s (teacher, against 140) and 223 s (model). **The module's own activity mix does not close the
growth gap**: mid accounts still grow 10-25x faster than real ones. The likely cause is the world's shape (64 accounts,
all full players from day 0, against real universes of thousands with late joiners), not yet checked against data.
Suite 109 green. Output `runs/hds4nov/{pace4,pace16,pace64,bands-teacher,bands-model}`.
Student 4 (greedy, 3 Nov seasons `train4/short-s4-g365`, shortjudge arms incl. `expofull`, the code of that day):
HDS **0.648**, AUC 0.998, res_share 97% out of band (it stops researching after ~day 30). Its early top10 grows 727 a
speed-day at 0-14 days against best-es3's 299 and the real 10,137 — 2.4x better, still 14x short. best-es3 stays the
north-star best (0.846). Two questions before any change to the early game or the world: (1) is the real top10 at
0-14 days (10,137) reachable under OGameX's mechanics at all (the plan's caveat: v13 lifeforms and dark matter are not
modelled), and (2) do real mid accounts look slow because many joined late (a 64-account world where all start on
day 0 cannot show that)? Both are data questions, minutes each.
Data checks (minutes, no simulation): (2) the late-joiner hypothesis cannot be tested from PUB — player ids are
anonymised hashes, so registration order is gone; OSTATS holds only 13,464 score points. What the data does show:
real universes hold a median 209 players (p10 65, p90 1,405; OSTATS catalogue, 438 universes) against our 64, all
of them full AI players from day 0, so our rank bands are cut from a population that no real universe has. (1) All
267 PUB universes run engine 13.x (lifeforms), whose highscore includes lifeform points OGameX cannot earn, so the
real top10 early growth (10,137) is in part out of reach; how much is not measured. Reading for the north star: the
remaining growth gap below top10 is mostly the world's shape and the yardstick's population, not the deployed
player; the model's own gap is the early top10, and its target is uncertain until lifeform points are separated.
Two more checks: PUB holds score types 0-3 only, and total minus economy+research+military (lifeform and other) is a
median 6% of the total at the top 100 (p90 28%) and 0% below — lifeforms explain little of the early top10 gap. The
universe age estimate (oldest alliance) matches OSTATS's opening dates on 264 universes, est/real 1.00 at every age.
So the yardstick's real numbers stand. The decisive reading is the spread: real top10 / mid / bottom at 128-256 days
grow 98,579 / 8,241 / 157 a speed-day (~600x top to bottom), ours 94,494 / 67,777 / 39,738 (~2.4x). Our weakest
account outgrows a real mid one: every AI account plays near expert level. Real populations range from expert to
barely playing; activity alone (the `bands` arm) did not reproduce that, so the next lever is skill spread in the
deployed model itself (`skill=LO:HI`, per-account sampling heat, an existing arm).
`skill=1:4` / `1:16` on the model (per-account sampling heat): HDS 0.849 / 0.848 (0.846 without), seasons 215 / 182 s;
bottom 128-256 growth 56k → 43k / 24k, mid unchanged (70k / 62k against real 8k). Third local lever in a row (pace,
bands, skill) that leaves the growth gap open, so stop and re-read the goal.

**Re-read:** the owner's north star (AGENTS.md, 6 Oct) is *one trained player that plays like a good professional
human*; such a player lands in the real top10 band. `hds.py`'s HDS averages every account of a 64-account world,
most of them scripted opponents in bands a good professional never occupies, so closing the mid/bottom growth gap
would mean making opponents weak, not making the player good. The deployed model's own distance is its top10 cells.
Model arm, top10 in-band share per measure: 64-128 days growth 1.00, eco 1.00, res 0.76, mil 1.00, planets 0.98,
moons 0.94, galaxies 0.73, alliance 0.97; 128-256 all ≥ 0.95 except galaxies 0.84; 256-512 all ≥ 0.96 except galaxies
0.73. **0-14 days: growth 0.00 (259 against 10,137 a speed-day), planets 0.00, res 0.40, mil 0.80.** The player's gaps
are the first two weeks and the spread over galaxies; the world's population shape is a separate yardstick question
(the opponents are scripts and the deployed AI accounts' population is the module's persona mix). Next: the early
game, measured on 14-day seasons (seconds each), top10 cells.

### The model's early gap is colonisation (4 Nov, evening)

Real top10 at 0-14 days (`real-bands.json`, n 122): growth p10/p50/p90 3,305 / 10,137 / 32,017 a speed-day, **planets
4 / 6 / 8, galaxies 1 / 3 / 5**, military share 0.33 / 0.48 / 0.63, research share 0.04 / 0.07 / 0.14. The model's top10
at day 10 (`hds4nov/model`, n 55): growth 261, **planets 1, galaxies 1**, military share 0.43, research share 0.04 — in
band on the mix, out on planets. Its five best accounts (seed 1) reach a second planet on days 16-30 and a sixth on
days 28-44, against six by day 14 for a real top player: colonisation is about three times slower, and with one
planet's mines the early growth cannot follow. Next: find what delays the second planet (the astrophysics level the
slot needs, chosen by the model, or the colony ship and site after it) on a short traced season, before changing
anything.

### What delays the colonies, and what the early gap really is (4 Nov, night)

Traced 20-day seasons (model, seeds 1-3; `levels` arm): astrophysics was never the limit (astro 4-8 on day 10, 11-13
on day 20, slots for 3-7 planets) while the top accounts held 1-2. Two persona faults in the deployed player's fleet
rule: (1) the colony ship was the last entry of `Miner::order_plans`, so probes, cargo and expedition ships spent its
price at ~1,000 logins it was due (49 colony ships ordered in a season); (2) colonising ran after `expedition_trips`,
which had taken every fleet slot (583 refusals "maximum number of fleets"). Both fixed in `players.rs` (colony ship
first in the plans; founding first in `act`): colonies founded per 20-day season 15-34 → 250-285, top10 planets on day
20 from ~2 to ~5. 109 tests pass.
10 seeds × 365 days (`hds4nov/colony`, mean season 208 s): HDS 0.848 (model before 0.846); top10|0-14 planets in band
0.00 → 0.18, growth still 0.00 (p50 211 against real 10,137); later top10 cells unchanged or better (64-128 res 0.76 →
0.93, galaxies 0.73 → 0.62).
**The early gap is not colonisation.** The top account (seed 1) on day 10 holds 1.66M metal, 311k deuterium and 11k
crystal on its capital, its queue idle 20 of 24 hours, every next build waiting on crystal: the model's mines stand
metal 20 / crystal 16 / deuterium 16 (an experienced player keeps crystal about two levels under metal). The merchant
(3,500 DM a call) barely trades. That mine mix is the model's own build choice, so the fix belongs in the model
(training signal for the first two weeks), not in a script. Next: confirm the mine ratio against the real early top
players' data and measure how much growth a balanced mix buys on 14-day seasons before touching training.

### The real 0-14 cell is not a target for a fair player; the galaxy spread is closed (4 Nov, night)

**Verifying the measure (question 3) before chasing the 35x.** The real `top10|0-14` cell is five speed-8 universes of
one simultaneous launch (br182, hr147, nl185, pt183, ro171; first alliances within the same hour), all captured at
age 7.3 days, compared against our rows of days 1-14. Their leaders hold 2-6M points (nl185: 120M) at day 7.3; the
cell's top10 p10 / p50 is ~192k / ~590k points. Mining cannot produce that: all three mines at level 20 from hour zero
at economy speed 8 give ~11M resources (11k points) in 7 days, level 25 ~22k, level 30 ~42k. The cell measures income
from outside mining (dark matter bought with money, expedition finds scaled by a rank-1 score such purchases inflate).
A fair player cannot reach it and ours must not try (no-advantage rule), so **the 0-14 growth cell is not a model
target**; its counts are also a launch-week snapshot. Not changed in `hds.py` (owner's yardstick); recorded here.
**Galaxy spread, the next valid gap.** Top10 galaxies in band 0.62-0.84 after the colony fix: every out-of-band top10
account held 1-2 galaxies (real p10 / p50 / p90 at 64-128 days: 3 / 5 / 6), from the `reach` arm's uniform 1..7 draw
("an assumption") with 3 planets filled per galaxy. The deployed PHP planner (`QueueableColonyPlanner::emptySlot`)
is worse: it takes the largest slot, and equal sizes (every position-8 slot) keep the walk's first galaxy, so an
account fills ~8 systems of one galaxy before opening a second (600 checks / (12 x 6 galaxies) = 8 systems a galaxy;
no test covers the own spread). Rule measured in the simulator as arm `spread` (`world.rs::colony_site`): largest
slot by the host's field range, then the galaxy holding the fewest of the account's own planets, nearest system;
reads only the account's own state.
10 seeds x 365 days (`hds4nov/spread`, model, colony fix + `spread`; mean season 215 s against 208 s):
top10 galaxies in band 64-128 / 128-256 / 256-512 **1.00 / 1.00 / 1.00** (0.62 / 0.84 / 0.74 before), growth p50
56.5k / 81.1k / 99.7k (in band 1.00, unchanged), planets 0.99 / 0.99 / 0.97, res 0.97 / 1.00 / 1.00. All-account
HDS 0.848 → 0.835: the scripted opponents now spread like top players while real mid/bottom hold 1-3 galaxies;
not the north-star number. Every top10 cell from day 64 is now ≥ 0.91 on every measure.
Next: carry the rule into the deployed player (`QueueableColonyPlanner`: among equal-size slots prefer the galaxy
with the fewest own planets) with a test, through the module tool chain; then the model's top10 has no open gap
outside the launch-week cell.
**Carried into the deployed player (4 Nov, night).** `QueueableColonyPlanner::emptySlot`: among slots of equal size
the galaxy holding the fewest of the account's own planets wins, then the quieter system, then the seeded walk (own
planets only; no other account's state). New test `successive colonies open the galaxy the account holds fewest
planets in` (`ColonisationScanSpreadTest`): fails on the old rule, passes on the new. The eight colonisation test
files pass (42 tests). pint and rector clean on both files; phpstan's only module findings are pre-existing `?Type`
lines the change does not touch. Not run: the full suite. The app image does not build here (`COPY storage` not
found) and the host PHP 8.5 refuses to load an unrelated test file (`CombatCatalogueExportTest.php:19`,
`use ReflectionMethod;`). The full `composer run rector` / `cs` with the host's tools rewrote 83 unrelated files;
each was verified as tool-only (its HEAD formatted by pint equals the working copy) and restored.

### N0b starts from S2a, not from zero (4 Nov, night)

The sim-to-PHP replay N0b asks for was run on 25 Oct as S2a (`ogrl.transfer`, `runs/flow/e5`, RL-FLOW row 5) and
judged **broken**: the economy-only replay misses the PHP value by F 41% (gate 10%), because it puts the fleet,
defence and errand budget (56% of the recorded decisions) into compounding mines, and `value` is read at the last
decision rather than at the horizon. The 137k/129k match quoted in NORTH-STAR-PLAN is a single account from M5's
cause-1 check. So the step before any transfer ratio is a world replay: every recorded kind (building, research, yard,
errand) replayed in `VecWorld` at the recorded logins, value read at the horizon, F ≤ 10% first. Served model of
M5's policy run (`storage/rl/build-model4/model.onnx`) is no longer on disk; the policy run's recorded choices are.

### PHP speed and the late-game bridge (4 Nov, night; owner: make PHP fast, focus on the late and end game)

**PHP speed.** `ai:sim` with the cognition sidecars down waits out every call's timeout: 24 h of a fresh 12-account
universe did not finish in 540 s (14 h played). The stopped sidecar containers (`ogamex-ai-cognition-{fatima,fatima2,
cbrkit,agentos}-1`, exited 2 days ago) restarted with `docker start`: **24 h in 78 s (x1103)**, `--native-cognition`
65 s (x1334); 124 sessions, ~0.6 s a session. A PHP year at that rate is ~8 h a universe, so the late game cannot be
reached by playing from day 0. Host PHP 8.5 (yerd) has no FFI: the Rust battle engine and the CBRKit Rust similarity
fail (`Class "FFI" not found`, 126 work items in the first hour of a late world). The image
`ogamex-local-docker-dev:latest` has PHP 8.5.10 with FFI, pdo_sqlite and OPcache (CLI OPcache and JIT off by default).
**Late-game bridge.** Arm `levels` (`examples/season.rs`) now dumps what PHP needs: coordinates, ships and defence by
host id (a fleet in flight counts at its origin), resources, moons with their levels and units, class, dark matter,
alliance, persona. New dev command `ai:rl-import-sim {path} {levels} --accounts=N` (`ImportAiRlSimState`, registered
in `AIServiceProvider`): a fresh `ai:rl-universe` on the simulator's 6 x 499 galaxy, the dump's N strongest active
accounts rebuilt on its AI accounts, highscores generated. Day 256 of seed 1 → 12 accounts, 253 bodies (108 moons)
in 29 s. `ai:rl-universe --stage-budget` was rejected for this: it gives a late account 2 planets and 2 moons.
**The late-game finding.** The imported day-256 accounts hold ~8-9.5M espionage probes, ~2M small cargos and ~1.3M
solar satellites each (top10; day 64: ~1M probes, ~0.25M cargos). PHP cannot play that state: in the container both
plain and JIT runs held a core at 100% for 11 minutes without finishing the first simulated hour. Source isolated on
64-day seasons (seed 1, model, ~40 s each): without `expedition` the top10 hold 1 probe and 8 cargos and the leader
ends at 0.83M points against 27M with it; without `doubler` 0.89M probes; with the escort arms (`exposhield`,
`expofull`, both) 0.75-1.27M probes. **Expedition finds are ~97% of the late-game growth and arrive as millions of
the cheapest ships, which no rule ever uses.** The top10's in-band growth from day 64 rests on that, so it has to be
checked against the original game before anything is tuned: the find brackets by rank-1 points, which ships a find
can hold, and how many expeditions a day the slots allow. Next: that check (host `ExpeditionMission` and the sim's
`expedition.rs` against OGame's published expedition rules), then PHP late windows from the bridge.

### 4 Nov: PHP late-game speed (state imported from sim day 256, 12 accounts, one login each)
- Host PHP 33.3 s -> 22.2 s, same actions and same query counts (checked per phase):
  `Support/HostProduction` (production memo per planet, object, level, percent, second; cleared per login; ~95k calls were 35%),
  `ObjectService` lookups by id/name indexed + locale read without container lookup (host), FacilityChain queued-level and producer caches.
- Container 70 s -> 21.8 s: pcov was loaded and enabled in `ogamex-local-docker-dev` (2x slowdown). RL runs must pass
  `-d pcov.enabled=0 -d opcache.enable_cli=1` (added to storage/rl/late-w.sh, long.sh). JIT: no gain.
- Tests: 823 passed (module planner/colonisation files + host Unit + building/techtree features), container with FFI.
- Open: `ai:sim --in-memory` from the imported day-256 state never finishes its first instant (>11 min, even for 2 h).
  Suspect a same-instant reschedule loop or a backlog in drainDueWork; next step is a 15-minute-capped diagnosis
  (count rows per drain pass). `--workers` is disabled with --in-memory, so parallelism = independent universes.
- Diagnosis (5 min, container, pass counter): the first instant takes ~30 s and runs fine, then the run hangs
  executing 4 RaidWave rows. The imported day-256 planets hold millions of units each (example planet 12: 2.0M light
  fighters, 2.0M small cargo, 1.1M solar satellites, 1.2M rocket launchers, 0.58M light lasers), so one battle fights
  millions of units for 6 rounds. This is a sim realism gap, not only a PHP speed gap; real day-256 fleets must be
  checked before changing the sim's late unit mix.
- Real reference (OGMEM, 648 non-simulated battle sides, round-number sim reports dropped): fleets worth 1e10-1e11
  are BC 38%, cruiser 17%, deathstar 8%, LC 7%, reaper 7%, BS 6%, destroyer 5%; light fighters + small cargo median 0.
  Sim day-256 top10 (base bench, fleet median 4.2e10, value in range): BS 18%, LF 18%, LC 17%, cruiser 16%, PF 13%,
  SC 10%, BC 5%. Cause: expedition finds are capped at one tier above the best ship sent (host rule, mirrored), and
  the sim sent cargo only. Arm `exposhield` (one strongest hull rides along, existing code) at 10 seeds x 256 days:
  BS 12%, LF 12%, LC 11%, cruiser 10%, bomber 10%, BC 10%, destroyer 10%, PF 9% -- closer to real; top10 cells
  64-128 / 128-256 stay in band (growth 1.00 / 0.98), HDS 0.820 -> 0.826; 0-14 cell eco/mil shares drop
  (0.18 -> 0.02, 0.23 -> 0.03; that cell is not a target). Wall time unchanged (~200 s per season both).
  Still millions of LF/SC: next, late-game scrap of obsolete small hulls beyond the doctrine template, then re-import.
- Arm `trim` (new, off by default; with `scrap`, doctrine hulls kept up to 2x their template value share instead of
  without limit), 10 x 256 days with exposhield: no effect on the mix (LF 14%, SC 9%, BS 14%, BC 12%), ships per top10
  account ~3.3M (esc 3.2M, base 5.0M), HDS 0.825, top10 128-256 growth 0.95. Wall 173 s mean. Not adopted.
  Suspect: scrap is bounded by free storage (35% paid into stores that are full late), and the largest per-planet
  counts (up to 7.5M units) include defence and satellites, which scrap never touches. Two local fixes in a row:
  re-measure the cause (scrapped count and storage room per login) before a third.
- Cause measured from the trim dumps (no new run, top10 x 10 seeds, day 256): units are 57% ships, 37% solar
  satellites, 6% defence; per account on average 1.57M satellites, 0.86M small cargo, 0.83M light fighters, 0.31M
  large cargo. 31 of 100 capitals cannot scrap even 1,000 light fighters (metal or crystal store full; deuterium
  often above its cap). So the two levers are (1) the satellite count (the largest single unit, from arm
  `satellites`), (2) scrap blocked by full stores. Next: check what the satellites arm answers (energy target) against
  real late energy sources, before changing anything.
- Real strategies from OGMEM (`rl/scripts/ogmem_strategies.py` -> storage/rl/ogsim/r8w/ogmem-strategies.json; 222
  reports after dropping every report with round-thousand counts = simulator inputs; the archive favours big fleet
  crashes, so it is a fleet-fight sample, not a farming sample):
  attack fleets 1e9-1e11: BC 52-53%, cruiser 24-27%, BS 6-9%, reaper 5-7%, destroyer 1-6%, LF/SC ~1-2%;
  attacks start at attacker/defender value p10 1.6x, median 3.4x, p90 14x; attacker loses a median 7% of what the
  defender loses; defenders caught hold cargo-heavy fleets (LC 13-29%); 56% of hit planets have no defence;
  satellites on 20% of hit planets, median 482 (sim: 1.4M per top10 account).
- Doctrine from that data (data dir storage/rl/ogsim/r8w-real, doctrine.json real templates, rest symlinked to r8w),
  10 x 256 days with exposhield: HDS 0.822, top10 cells in band, but the day-256 mix stays flat (~10% each hull,
  ships 9.0M per top10 account vs 3.2M) -- the yard template does not set the late fleet; expedition finds do.
  Wall 175 s. Not adopted. Three local fixes (exposhield, trim, real doctrine) -> stop and measure: ships by source
  (expedition find vs yard vs scrap) per account, and the satellites arm's target, before any further change.
- Documented defence rule applied to the sim: `standing_wall_ceiling` 20,000 defence units per planet
  (resources/behavior/defence-doctrines.yaml; the PHP player already follows it). `Doctrine.wall_ceiling` (0 = off),
  emitted by rl/scripts/doctrine_json.py; past it the yard buys the fleet template. 10 x 256 days (data
  storage/rl/ogsim/r8w-wall, exposhield): rocket launchers per top10 account 178,618 -> out of the top 8, max units per
  planet 7.6M -> 6.6M, HDS 0.826, top10 64-128 / 128-256 growth 1.00 / 0.97, wall 173 s. Adopted.
  Satellites (1.6M per account) are now the largest unit; a full audit of every docs/wiki principle vs the sim is running.
- Satellites root cause: top10 accounts research graviton to 6-8 (energy-only price, 300k x 3^(L-1) energy), and the
  energy is bought as satellites (a capital with mines 42/36/38 and solar 41 held 1.09M). Real players stop at 1 (the
  deathstar unlock). Arm `paidlab` v1 (skip energy-only research in the cheapest-payable lab pick) changed nothing
  (graviton 6-8 still; 1.49M satellites): another chooser (the model or the baseline) picks it. v2 gates it in
  `World::legal` for every chooser: an energy-only research is legal only up to the highest level any object requires.
- Capability matrix (host vs sim vs model): the sim covers nearly every unit and mission (alliance depot missing), but
  the models choose little: the build model picks buildings/research; the errand model picks spy/raid/recycle/save/
  recall/order/wait only, with scripted fleets (raids = cheapest shooter + one cargo hull, 100% speed). Design to let
  the model use everything: per-hull order rows, raid fleet variants (role x speed, predicted loss from the report),
  new errand kinds (strike, moon destroy, moon shot, ACS join/defend, expedition variants, deploy/gate save, phalanx).
  Invalidates the errand model only (KIND 7 -> ~15, CAND 13 -> ~20, MAX_CANDIDATES 32 -> 64, FEATURE_VERSION 3;
  PHP ErrandChoiceEncoder to match): behaviour cloning on the widened teacher, then ES -- needs the owner's go.

### 5 Nov: program "the model uses everything" (owner: trusts my call on long training; fear = wasted effort)
Each stage has a go/no-go measured in a <=15 min run before the next stage starts; a long run only after a short one
moved its measure.
- A. Teacher + action space in the sim (no model change yet). Errand head widened behind arm `wide` (off = today's
  behaviour, so best-es3-s10 and current benches are untouched):
  A1 raid fleet variants: cheapest-shooter (today) and war fleet (doctrine hulls, sized from the latest report so
     attacker/defender value >= the OGMEM p10 1.6x, aiming at the median 3.4x); features add predicted loss from the
     report only. A2 per-hull order rows (every allowed unit, Now/batch sizes; features from host tables).
  A3 errand kinds wrapping existing rules: strike, moon destroy, moon shot, ACS join, ACS defend, expedition variants,
     deploy/gate save, phalanx. A4 missing documented principles in the teacher (FS-001/006/009, RAID-002, RAID-008).
  Go/no-go A: teacher-driven 10 x 256: top10 64-128 / 128-256 cells stay >= 0.95; attack fleet value shares move toward
  OGMEM (BC+cruiser >= 50% of raid value at 1e9+); attacks below 1.6x drop; wall <= +10%.
- B. hds.py cells from real data: raid fleet composition, attack odds ratio, satellites per planet, attacker/defender
  loss ratio. Go/no-go B: self-test (real accounts score in band) before any model is judged on them.
- C. Behaviour cloning on the widened teacher (warm start: new columns appended, zero-initialised). Go/no-go C: label
  agreement >= current bc-errand's, HDS within 0.01 of the teacher on 10 x 256.
- D. ES/self-play league fine-tune (scripted personas + past snapshots as opponents), long run only if a 15-min ES slice
  moves the top10 cells or the new real-data cells. Then PHP ErrandChoiceEncoder/DecideAiErrandAction to match
  (MAX_ROWS 64, FEATURE_VERSION 3) and the PHP late-state run as the integration test.
- 5 Nov, Stage C prep (Python trainers): errand trainers now read widths and column names from the data header
  (`ogrl/errand_schema.py`; header without names = today's 12 state / 13+7 candidate names, version 2, K 32) and
  warm-start by column NAME (`train_bc_world --init <pt|policy.json>`, also `train_world --init`, `train_exit` on wider
  labels): old columns copy weights + normalisation, new columns get weight 0 (BC then fills their mean/std from data,
  which changes no score). Contract expected from the Rust exporter (`<labels>.schema.json`, and the same keys in
  `--save-rows` npz): `errand_feature_version`, `candidates` (K), `state` (STATE_NAMES), `candidate` (CAND_NAMES then
  KIND_NAMES, as written; or `candidate` = CAND_NAMES plus a separate `kinds`); names unique, only appended. Exported
  errand policies now carry `_names` next to `_errand_feature_version`; `es_world --errand` stamps both (its gen
  policy.json was unstamped, which `load_errand` refuses). Unnamed AND unstamped checkpoints (train4/bc-errand.pt) are
  refused, not guessed. Proof: `rl/tests/test_errand_widen.py` (7 pass, CPU): widened model gives identical logits on
  old candidates (atol 1e-5) with random new columns; on 68,863 real teacher rows re-laid to 17 x 64 x 35, the widened
  joint errand policy starts at the narrow one's exact held-out loss 162.5735 / top-1 73.9%. GPU: CUDA_DEVICE_ORDER=
  PCI_BUS_ID set in `ogrl/__init__`, default cuda:1 = RTX 4070 Ti SUPER, CPU fallback; data kept on the device, batch
  16384 / lr 2e-3 on GPU (`--batch 4096 --lr 1e-3` = old run). Smoke: 4 x 90-day seasons collect 70 s, 4 epochs at
  K=64 1 s on cuda:1 vs 58 s CPU. Note: hds4nov/joint/errand.policy.json agrees with today's teacher only 73.9% (held-out
  loss 162: very large logits) -- Stage C should warm-start from a fresh version-2 clone of today's teacher, not from it.

### 5 Nov: stage B, war cells from real battles (`hds.py --war-cells`, rl/scripts/war_cells.py)
- Real reference: `ogmem_strategies.py` now also writes `battles` (186 reports with an attacker; units at the fight's
  start and units lost per side, host ids) and `war_bands` into storage/rl/ogsim/r8w/ogmem-strategies.json; the old
  keys are byte-identical, the output is deterministic.
- Cells (top10 attackers / top10 planets on their last day; band = real p10-p90): `odds` 1.63-12.5 (contested attacks
  only: defender >= 1% of attacker value, 181/186 real), `loss_ratio` 0.0033-0.21 (median 0.064), `line_share` by
  attack-fleet tier (1e8-1e9 0.62-1, 1e9-1e10 0.88-1, >=1e10 0.85-1), `light_share` (0-0.30 / 0 / 0-0.10),
  `satellites` 0-500, `defence` 0-56,778 (20,000 ceiling shown: 29/186 real hit planets exceed it). Archive bias (big
  crashes, no farming): uncontested raids are counted, not scored; tiers under 1e8 (8 real) not scored.
- Go/no-go B: PASSED. Leave-one-out on real battles: odds 0.80, loss_ratio 0.80; point-mass cells 0.89-0.93.
  hds.py without the flag reproduces wall/hds.txt line for line (HDS 0.826 / AUC 0.999); with it the verdict is the same.
- wall/ (teacher today): satellites per top10 planet median 29,600, in band 2%; defence median 1,430, in band 100%
  (12/709 over 20,000). Per-attack cells: no data -- the sim emits no per-fight record. Rust must write
  `<season>.jsonl.battles.jsonl`, one line per Attack/ACS-attack fight: seed, day (as the season row), time, mission,
  attacker (slot of the fleet owner), defender (target slot), target_moon, attacker_units / defender_units ({id: n} at
  the fight's start; defender = ships + defence + satellites + ACS defenders), attacker_lost / defender_lost ({id: n}
  destroyed, before defence repair), outcome, loot [m, c, d].
- 5 Nov, graviton/satellite source (3 seeds, no code change, r8w-wall): graviton is taken by BOTH the scripted lab
  (no policy: levels 6-7, satellites median 0.91M per top10 account) and the model (7-8, 1.37M); already 5-6 by day 128
  (0.35M). paidlab v2 had no effect because the choosers ask `Catalogue::queue_legal`/`cat.legal`, not `World::legal`.
  The gate belongs in the catalogue's legality (energy-only research legal only up to the highest level any object or
  unit requires), as data, so the model, the scripts and the PHP mask agree. War cell `satellites` (0-500 per planet,
  2% in band today) is its measure. Queued after Stage A (the Rust files are the Stage A agent's until it reports).
- 5 Nov, owner idea: the model as a state of ministries (mixture of experts) with a foreign ministry for alliances.
  Design answer: one shared state encoder (what the account sees); ministries = heads over typed candidates (economy,
  defence/fleet, intelligence, logistics, foreign affairs: found/join/leave alliance, ask ACS defence, war/NAP, all from
  public info + messages; the language module only speaks the chosen intent); a learned router ("prime minister")
  splits each login's slots/resources between heads; one value = the account's interest. Sparse MoE routing only if
  heads are too many for one net. Order: economy + defence heads (Stages A-D) first, router once >= 3 heads, foreign
  ministry after D (it needs opponents that answer: self-play league + alliances=N arm).
- 5 Nov, sourced: graviton cap = 1. WIK-175 (verbatim): Graviton Technology "costs 300,000 energy and is not useful over
  level 1"; WIK-149: every level three times as expensive; WIK-139: the "graviton colony" (position 1, research lab 12+,
  many solar satellites, abandoned after the research). So the sim's graviton 6-8 and 0.9-1.6M satellites per account
  are unsupported by our sources. Fix after Stage A: catalogue legality data (energy-only research up to the highest
  level required, i.e. 1). Owner, 5 Nov: I must look up the sources myself (forums, wikis, battle data) to judge what is
  realistic -> corpus-backed anomaly detector (rl/scripts/anomaly_report.py) being built.

### 5 Nov — Deathstar: when it is built, how it is used, how it fights (sources + OGMEM)

Sources: WIK-013 (endgame against rips), WIK-020, WIK-037, WIK-054, WIK-064, WIK-083, WIK-085, WIK-187, WIK-196,
WIK-017/018 (turtle), WIK-077, TP-021, principles ACS-013, EXP-002, CRASH (fleetcrash-phalanx-moon), ninja-baiting,
fleet-composition, contested CLAIM-TP019-ERROR; OGMEM battle_unit (563 reports).

OGMEM (attacker = fleet that attacked):
- Attackers bring Deathstars in 11/563 battles, ALWAYS as a near-pure Deathstar stack (≥90% of value in 10/11),
  600–16,000 hulls, against huge defence walls (median 88% of the defender's value is defence). They lose ~0 hulls
  and wipe the wall (Bouncing effect + rapid fire on RL/LL/HL/Gauss/Ion; WIK-013 "up to 160 shots a piece").
  Several stacks are round (600/1000/1500/3000/16000) → likely simulator inputs; 2850/4223/4506 look real.
- The one mixed fleet (579 DS inside a full LF/cruiser/... fleet) hit a defender holding 10,904 DS + a plasma wall
  and lost EVERYTHING.
- Defenders hold Deathstars very often: 18% of planets at 1e8–1e9 value, 62% at 1e9–1e10, 92% at 1e10–1e11,
  96% above 1e11; median count 1–2 under 1e10, 28 at 1e10–1e11, ~1,200 above 1e11. When crashed, they die (100%).

Rules to encode (gate 1: read the catalogue's rapid fire/shield/speed, never name the hull):
1. Build: late (shipyard 12, graviton 1, hyperspace drive 7); first 1–2 as a moon-destruction/anchor hull, mass
   stacks only at 1e10+ account value. Expeditions never find it (EXP-002).
2. Offence A — "wall breaker": a pure stack (the slowest hull, unaffected by General speed, WIK-077) against a target
   whose value is mostly fodder defence and whose plasma/domes are few. Count from WIK-013: a wall with plasma:fodder
   ≈1:10 needs ~155 DS; 7 plasma draw one DS; 37 plasma kill one.
3. Offence B — moon destruction (mission 9): chance (100−√diameter)·√n %; returns redirected, no debris (WIK-187/196,
   CRASH); not in ACS (ACS-013). Used against a moon that hides a fleet/phalanx threat.
4. Defence: keep DS at home as mobile defence/anchor behind fodder (WIK-017/018) — but fleet-save it like any fleet;
   OGMEM shows docked DS are lost when crashed.
5. Counter: plasma turrets + both domes (no rapid fire against them; each hit ends the DS's rapid-fire chain, WIK-013/
   WIK-085, TP-021), ~1 plasma per 10 fodder against a DS player; mixed hulls dilute rapid fire (fleet-composition).
6. Do not mix a few DS into a fodder fleet as attackers — it neither breaks walls nor survives a DS defender.
Realism cells to add (war_cells): attacker-DS share (pure stack or none), defender DS presence by value tier.
Sim today: DS only in Raider::destroy_moon + templates → missing rule 2 (wall breaker) and rule 5 weighting.
Queued after Stage A releases players.rs/errands.rs.

### 5 Nov check-in
- Stage A (Rust `wide`) still running (its `cargo test --release --lib` in flight); anomaly detector still running.
  Stage B and C verified earlier. No bench or training running; cuda:1 idle — nothing is ready to train on until
  Stage A delivers the wide teacher + battles.jsonl (C's BC input).
- Added war cell `ds_mixed` (rl/scripts/war_cells.py): 1 when Deathstars are 5–90% of an attack's value (real: pure
  stack or none). Self-test PASSED, ds_mixed LOO 0.99 / split 0.99. Measure for the Deathstar teacher rules above.
- Gate-1 fix (PHP): `ResearchCostRule` named Graviton and capped it only in the ambition list; the model's research
  candidates (QueueableBuildingPlanner::queueableResearch → `legal`/`teacherOk`) had NO cap. Replaced by
  `app/Domain/Research/ResearchCeiling.php`: a research priced in energy alone is legal only up to the highest level
  any catalogue object requires of it (catalogue read, no name; WIK-175/WIK-139). Used by queueableResearch (teacher
  and model alike) and EconomyUpgrades::ambitionCeiling. ResearchCostRule deleted (no other caller).
  Test: tests/Unit/ResearchCeilingTest.php. Selected module tests (24 files, 207 tests): 206 pass; the one failure,
  StageAiAccountTest "late game" (building count == field max), also fails on module HEAD code → pre-existing, not ours.
  Rust side of the same cap still queued behind Stage A (world.rs/players.rs owned by the agent).

### 5 Nov: weird-behaviour detector (`rl/scripts/anomaly_report.py`)
`.venv-rl/bin/python Modules/AI/rl/scripts/anomaly_report.py RUN_DIR [--top 10] [--pattern 'model-*']` writes
RUN_DIR/anomalies.{md,json} (deterministic, ~6 s on wall/, ~2 s on diag-grav). Judges the top10-by-total accounts of
every world from `*.levels.json` (+ season event totals from the day rows, + `battles.jsonl` fights when present):
magnitude vs OGMEM per-side unit stocks and fleet Weapons/Shields/Armour %, account counts vs real-bands.json;
documented caps (Graviton > 1 WIK-175, sats beyond the Graviton-L1 need WIK-237/139, 20,000 wall ceiling, one dome
per size); never-used units/objects/events; principle checks (banked resources > 24 h, parked fleet next to a moon,
silo without missiles, depot without alliance, odds < 1.6, light hulls). Each row carries 1-3 verbatim passages from
a cached corpus index (`anomaly_corpus.py` -> storage/rl/ogsim/corpus-index/: raw wiki/forum, principles, doctrine/
behaviour yaml, English TECH texts of the GitHub OGame clones; rebuilt on input change, 0.6 s) or reads
"unsupported - needs owner".
wall/ top: Graviton 7-8 on 100/100 (WIK-175), 1.39M sats per account vs a ~9k Graviton-L1 need (WIK-139), up to 13
small / 11 large shield domes per planet (impossible: WIK-051/085, simulator bug), ~820 h of production banked,
~8.8e9 fleet parked on planets that have moons, no Colony Ship / Crawler held, silo 15 with no missiles (79/100).
Dump findings for the owner: moons carry their own (lower) research ids; levels.json `alliance` is 0 for every account
while the day rows say member.
- Anomaly detector (agent) verified: rerun on wall/ byte-identical, 6.1 s. Top: Graviton 7–8 (fixed in PHP, Rust
  queued), 1.39M satellites/account, >1 shield dome on 262 bodies, 820 h banked resources, fleet parked beside a moon,
  silo 15 with no missiles, no moon destruction / relocation ever.
- Dome cause found: the cap counts only BUILT domes, not queued ones, so every login while a dome is still in the
  shipyard queue orders another. The host has the same bug (app/Services/ObjectService.php getObjectMaxBuildAmount:
  `getObjectAmount ? 0 : 1`, no queue look) and the sim mirrors it (rl/ogsim/src/units.rs max_amount,
  `limit - counts`). Fix = host counts queued units too (separate host PR, owner to approve) + sim parity
  (`limit - counts - queued`), after Stage A.
- Side research started: RL/IL game-agent pitfalls from labs → plan/rl/RL-PITFALLS.md (agent, docs only).
- Dome fix (host, owner approved "fix problems anywhere"): app/Services/ObjectService.php getObjectMaxBuildAmount
  counts unprocessed UnitQueue rows of the dome too. New tests/Feature/ShieldDomeQueueTest.php fails without the fix
  (2 queued) and passes with it. rector/pint/stan clean. Host Feature+Unit suites in the container: 1373 passed
  (ModuleDoctor/ModuleLifecycle excluded: they depend on the local modules_statuses.json, AI enabled here).
  Sim parity (units.rs max_amount: limit − counts − queued) queued after Stage A.
- Env fixes: Modules/HelloWorld/docker was root-owned (old container) → chowned back; removed a no-op
  `use ReflectionMethod;` in non-namespaced tests/Feature/CombatCatalogueExportTest.php that aborted the whole run.
- Pre-existing module test failures found (not from today's diffs; queued): DiameterIsNotAnAiInputTest (lists
  DrawAiMoreMissionCasesAction, ReplayAiWorldAction, DrawAiMissionCasesAction…), SurrenderColonyNotEncodedTest
  (alliance-war.yaml, grudges.yaml), StageAiAccountTest late game (fields full).
- RL pitfalls research done → plan/rl/RL-PITFALLS.md. Adopted into the program: (1) pause ES when the anomaly detector
  leaves real bands (sim exploits); (2) keep some HDS/war cells out of any reward (held-out judge); (3) audit the
  teacher for hidden-state reads before BC; (4) BC: mask the teacher-pick column + DAgger relabel with the scripted
  teacher; (5) ES/RL with a KL/drift anchor to the BC policy; (6) league with frozen snapshots + exploiter slice;
  (7) 10 seeds, IQM + bootstrap CI; (8) router load-balancing loss for the ministries.
- Check-in (pitfall #4 audit): deployed economy model's 13 candidate columns (economy.rs CAND_NAMES) and the errand
  model's CAND_NAMES carry NO teacher-pick/order/pass column → no copycat leak in the sim-trained models (the PHP
  EconomyChoiceEncoder does carry `order`/`teacher_ok`/`pass_*`, but the sim-trained policies never read them).
  Pending on Stage A's report: confirm the wide columns (odds, predicted_loss, predicted_win…) are computed from
  the spy report a player holds, not from the target's true state (pitfall #3 / no-cheating).

### 5 Nov: stage A1-A3 in the sim (arm `wide`)
- Built behind arm `wide` (`World::wide`; off = byte-identical: 10 seeds x 256 days of the run4 bench `cmp`-equal to the
  old binary, and the narrow errand door too). `errands.rs`: `Plan` variants (raid `War`/`WarSlow`, expedition
  `Cargo`/`Doubler`/`Escort`, order `Hull`), 9 new kinds appended to `KIND_NAMES` (strike, moon_destroy, moon_shot,
  acs_join, acs_defend, expedition, deploy, gate_save, phalanx), `WIDE_TEACHER_ORDER`, `Menu` (mates, moon-shot
  receivers, station), 64 rows, feature version 3, 5 state + 18 candidate columns appended after today's (narrow export
  = prefix by name). `players.rs`: `war_plan` (shelf warships less the moon destroyer, sized from the last report to
  3.4x reported value, never under 1.6x, plus cargo for the loot), `forecast` (host engine on the report scaled to 200
  units: own loss, win), and option/plan splits of `defend_mate`, `join_raid`, `moon_shot`, `destroy_moon` (originals
  call them; identity held). With an errand model the scripted moon shots, ACS and expeditions of those accounts stop.
  Exports: `champion-errand-out` schema and `SeasonWorld.feature_names/dims/errand_feature_version` carry the names.
  New diagnostic `<out>.battles.jsonl` (arms `battles`/`levels`). Note: `hds.py ready-*.jsonl` now also globs the
  battles file; use `ready-[0-9].jsonl ready-[0-9][0-9].jsonl`.
- Bench (runs/wide, 10 x 256, run4 arms, `errand-policy=teacher fleet-model=64`): wall base 312 s (old 310), door 338 s,
  wide 353 s (+4.5% over the door). HDS base 0.826, door 0.823, wide 0.849; top10 64-128 / 128-256 growth: door
  1.00/1.00, wide 1.00/0.99. Raids (moon shots dropped): defended raids below 1.6x base 5.6%, door 0.2%, wide 0.3%
  (the below-1.6x attacks are moon shots, by design). Raid value at 1e9+: only 25 raids (door 6); BC+cruiser 37%
  (door 0%), BS 13%, LF 12%. Go/no-go A: cells, wall and odds pass; BC+cruiser >= 50% at 1e9+ not met (n=25; the war
  fleet flies the shelf's own mix, so the mix is the yard's/expeditions'). A4 not started.
- Stage A verified by me: `wide` off = old binary byte-identical on all 10 run4 seeds (cmp); cargo test 112 pass incl.
  the unseen-state invariance test for the new kinds; wall wide +4.5% over the door (the agent's bench ran 40 seasons
  at once uncapped, so absolute walls are inflated, relative ones hold). Go/no-go A: cells, wall, 1.6x pass;
  BC+cruiser 37% < 50% at 1e9+ (n=25) → not a blocker for C (the mix comes from the shelf, not the raid rule).
- War cells on runs/wide: moon shots (fighter fleets lost on purpose against a mate) dominated the "contested"
  attacks (odds median 0.44, loss_ratio 10). battles.jsonl now carries `mate_target` (season.rs) and war_cells skips
  those (self-test still PASSED).
- Sim fixes (5 Nov): (1) Catalogue `ceiling`: a research priced in energy alone is illegal past the highest level any
  object or UNIT requires (economy.rs stall_of "past need"; set in Universe::catalogue from the units' requires) — every
  chooser (scripts, baseline, model mask) reads Catalogue::legal, unlike the old `paidlab` arm. (2) units.rs max_amount:
  unfinished queued units count against an owned limit (domes), test `caps_follow_the_host` updated (it asserted the
  bug). 112 tests pass. Bench runs/fix5 (base + wide, 10 seeds, capped) vs runs/wide.
- runs/fix5 (10 seeds, capped; vs runs/wide same arms): Graviton top10 6–8 → 1 on 100/100 accounts; bodies with >1 dome
  82/115 → 0/0. HDS unchanged (base 0.822 vs 0.826, wide 0.849 vs 0.849); top10 64-128/128-256 growth 1.00/0.99.
  Wall (capped, 20 at once): base 231 s, wide 264 s (+15% over base, it includes the door). KEEP both fixes.
  NOT fixed: satellites/planet mean ~30k (p90 60k; real max 10.5k); top planets of Miners hold 2.3–2.6M satellites,
  1.5M small cargo, 1.4M light fighters (expedition finds + scrap blocked, see 4 Nov) → satellites were not caused by
  Graviton. Next realism gap. Top10 contested attacks without moon shots: base 35, wide 9 (odds in-band 0.54/0.56).
- BC smoke on the wide teacher (runs/bc-wide-smoke): 366k rows, v3 (state 17, cand 47, 64 rows), top-1 92.9% after 2
  epochs on cuda:1; teacher kind shares: wait 52%, expedition 23%, spy 7.6%, raid 7.5%, phalanx 2.4%, moon_shot 2.1%,
  deploy 1.3%, ACS 0.6%, strike 0.02%; moon_destroy and gate_save are NEVER offered → gap for the late-game teacher.
- ES (Stage D) protections from RL-PITFALLS added to rl/ogrl/es_world.py: `--anchor β` (pull toward the cloned weights,
  #5), `--gate-every N --gate-hds X` (judge seeds 1-10 scored by hds.py, never part of the vote; exit 3 under X, #1/#2),
  `--data` (r8w-wall), `--extra-arms` (spread scrap exposhield wide late), `drift` logged per generation. Smoke
  (runs/es-gate-smoke, 1 gen, 2 seed pairs, 32 days, capped): runs end to end, gate HDS 0.726 (32-day seasons, not
  comparable). Note: one Adam step at lr 0.01 already gives drift 0.094 (|Δθ|/|θ0|) → for D start at lr ≤ 0.003 or
  anchor > 0, and watch drift + gate HDS each generation.
- Late-game tactics agent (arm `late`, owns the Rust files) running: gate_save, moon_destroy, DS wall breaker, ninja,
  deception (FS-002/008/009/010, INT-012); then the unit-bloat cause.
- Stage C pipeline check (runs/bc-wide-c1, ~10 min): BC 12 epochs on the 366k wide-teacher rows (7 s on cuda:1, top-1
  98.9%) → export v3 → 10 seeds × 256 days with `errand-policy=<bc> fleet-model=64 wide` (fix5 binary). Rollout:
  HDS 0.849 (teacher fix5/wide 0.849), top10 64-128 growth 1.00 / 128-256 0.99 (same), attacks 251k vs teacher 249k,
  moon shots 15.9k vs 15.7k, ACS 148 vs 134. Wall 211 s/season. → the clone PLAYS like the teacher (judged by
  rollout, not top-1); the C pipeline is verified end to end. Real C run waits for the `late` teacher.
- Stage D slice 1 (runs/es-errand-slice1, ES on the cloned wide errand model, builds fixed, 21 voting accounts, 4 seed
  pairs, lr 0.003, anchor 0.5, 209 s/gen at 8 threads): gen 1 drift 0.025, gate HDS 0.830 vs the start's own gate
  (gen 0, same seeds/arms) 0.828 → no realism loss from a step; top10 cells 1.00/1.00 both. es_world.py now has
  `--gate-drop d` (stop when HDS < start − d; the start is measured once as gate-0). Gate works; ES on the errand head
  is ready for a ≤1 h run once the `late` teacher is cloned (score gain is the go measure there, HDS drop ≤ 0.01 the stop).

## 5 Nov — late-game tactics (arm `late`)

Arm `late` (needs `wide`; off = byte-identical: final binary vs runs/fix5 base + wide, 10 seeds × 256 days, every file
`cmp`-same). Every new row is offered in the wide menu (64-row v3 features unchanged; plans ride on the row), taught by
`late_pick` ahead of the wide order, and covered by `late_errands_do_not_read_unreported_enemy_state` (offer, teacher
and features invariant to the target's and its moon's hidden research/units). 117 lib tests pass.
- Gate saves (CRASH-004, FS-011): `GateSave/Base` from any gated moon under attack; `GateSave/Night` = jump, then the
  night save flies on from the other moon. Fleets post on the first gated moon (`post_moon`) when the capital's has
  none; the moon raises its phalanx only after the other moon-only buildings (gate first, CRASH-004).
- Night save (FS-001/002/007/009): before the sleep window, only when the account is EXPOSED (FS-001: a probe from or
  an attack by another account on any of its bodies in the last 24 h — what its messages show). Away until wake + its
  own flight, landing drawn from 3 routes by (account, day), split in two waves over two destinations with ≥2 free
  slots. Ungated nightly saves cost the top10 8% of score (ablation runs/late/abl-*: night was the only rule whose
  removal restored 128-256 growth) → the exposure gate.
- Hidden save (FS-010): deploy to an own planet whose one-way is > 2× the attack's remaining time (off the phalanx).
- Moon strike (WIK-187/196, CRASH-005/007, Deathstar rules 3/6): destroyers only; odds from the host's StrikeRules on
  the public diameter (no object names); taught when destroy ≥ loss or the hiding fleet + destroy% × our phalanx-
  watched warships ≥ loss% × the stack. Moons are spied with 2× probes (buildings/research) and not re-spied within
  REPORT_HOURS (INT-012).
- Wall breaker (Deathstar rules 1/2): a pure destroyer stack + cargo when the reported defence is ≥ half the target's
  value, host engine says win with loss ≤ 5% of the stack.
- Ninja (NIN-001/002/004/005): the moon fleet deploys down to land ≤ 300 s before a raid when espionage ≥ 8 shows the
  ships and the host engine says the raid dies; a raid waits for the moon spy when the target's moon is unscouted or
  shows a staged fleet.
- Bench runs/late c/d (10 seeds × 256 d, capped, 20 at once), late vs wide: HDS 0.850 vs 0.849; top10 64-128 / 128-256
  growth 1.00/0.98 vs 1.00/0.99; ds_mixed 1.00; health PASS; wall 239 s vs 238 s mean (max 257 s; fix5/wide 264 s).
  Teacher choices (1.198M): GateSave/Night 176, MoonDestroy 27 (of 27 offered), Raid+Phalanx/Breaker 639,
  Save/Hidden 804, Save/Night 689, Deploy/Ninja 0 offered. War cells: 6 contested top10 attacks vs 9 — all the same
  834-light-fighter raid pattern present in wide too (not a late rule). → GO 1.
- Stage 2 (unit bloat), arm `satcap` (Miner, separate from `late`): instrumented seeds 1-2 show power_by_units orders
  39–42M satellites per world, 39.1M still held at the end (195k lost in battle); the shortfall is `chain_energy` — the
  terraformer field chain's energy price doubling per level (1M, 2M, 8M, 15M) bought in satellites. Fix: the chain's
  energy is bought only while held + coming + needed satellites ≤ 10,500 (OGMEM max per planet). Bench cap-d vs late-d:
  satellites per planet max 2.62M → 10.0k, mean 96.6k → 5.4k (top10 p50 29.5k → 7.1k); HDS 0.850 → 0.844, 128-256
  growth 0.98 → 0.97 (planets out-of-band 10% → 13%). Bloat cause fixed; kept OFF by default (owner decides: −0.006
  HDS; real satellites p50 is 0–500, ours 7k because the chain still buys up to the cap).
- Open: FS-008 (fake activity) needs an activity model the sim lacks; Deploy/Ninja never offered (no account has
  espionage 8 + a moon fleet + an incoming raid it beats); moon strikes are rare (few Deathstars per account);
  betrayal would plug into `Menu.alive`/`World::mates` (out of scope); the night-ablated sim once showed clock_ahead
  (planet clock 46–285 s past world time at a day end, 2/10 seeds) — not seen with the shipped rules, cause unknown.
- Late agent verified by me: ident.txt 60/60 same (late off = fix5 byte-identical); cargo test 117 pass; HDS late-c
  0.850 vs wide-c 0.849, late-d 0.850, cap-d 0.844; satellites cell mean late-d 3e4 → cap-d 7.07e3. DECISION: adopt
  `late satcap` (a 2.6M-satellite planet is a state the real game never shows; pitfall #1 says the optimiser will farm
  it; the 0.006 HDS is the top10 128-256 growth 0.98 → 0.97). Teacher arms from now: run4 arms + `wide late satcap`.
- Stage C real started (runs/bc-late-c2): 16 worlds × one 256-day season, keep 0.3, ≤700k rows, 12 epochs on cuda:1,
  then 10-seed rollout vs the teacher (go: rollout HDS within 0.01 of the teacher's, top10 cells ≥ 0.95).
- Stage C real DONE (runs/bc-late-c2): 16 seasons × 256 days of the full teacher (wide late satcap), 550,813 rows,
  12 epochs (8 s, cuda:1), top-1 99.3%; collection+training 3 min 43 s. Rollout 10 seeds (204 s/season):
  HDS 0.844 = teacher cap-d 0.844; top10 64-128 growth 1.00 (teacher 1.00), 128-256 0.98 (0.97); satellites mean
  7.12e3 (7.07e3); war in-band 0.505 (0.513). GO → the clone is the D start.
- Stage D 15-min slice started (runs/es-late-s15): gate-0, 2 gens (8 seed pairs, 32 threads, lr 0.003, anchor 0.5),
  gate-2. Go for the ≤1 h run: gate-2 score above gate-0 with HDS drop ≤ 0.01.
- Stage D 15-min slice RESULT (runs/es-late-s15, 13.5 min): gate HDS 0.822 → 0.822 (no drop); 21 model accounts' mean
  ln final score on judge seeds 1-10: 18.4476 → 18.4847 (+0.037 ≈ +3.8%), 8/10 seeds up, bootstrap 95% CI
  [0.002, 0.090] (one seed +0.255); drift 0.038 after 2 gens. GO → ≤1 h run started (same dir, resumes at gen 3,
  gens to 14, gate every 4, stop on HDS drop > 0.01), started 03:44, ends by 04:44.
- D 1 h run, gate at gen 4 (04:00): HDS 0.823 (start 0.822); 21 model accounts' ln score vs gate-0 on seeds 1-10:
  +0.076 (≈ +7.9%), 10/10 seeds up, 95% CI [0.037, 0.129] (gen 2 was +0.037, 8/10). Drift 0.071 at gen 6. Improving
  and realism held.
- PHP v3 errand encoder agent started (PHP only, ≤16 threads): deployment gap — the trained v3 model cannot run in PHP yet.

## 5 Nov — PHP v3 errand encoder

The deployed game can now run the simulator's errand model (feature version 3, arms `wide late`, e.g.
runs/bc-late-c2/errand.policy.json). Finding first: PHP never had the simulator's v2 layout (WORLD-SIM-PLAN W12 was
blocked): today's `ErrandChoiceEncoder` writes the economy schema (~55 state, ~60 candidate columns) for the socket
policy. That path is untouched; the v3 path sits beside it.
- `app/Domain/Choice/ErrandPolicyModel.php`: the season `Policy` network in PHP (state_net 2 layers, cand_net, joint 3,
  ReLU, last-of-equal argmax as Rust `max_by`). Loads `config('ai.rl.errand_model')` only when
  `_errand_feature_version` is 3 AND `_names` equal the encoder's columns; anything else (v2, unnamed) → null → today's
  path (test). NEEDS ONE CONFIG LINE (config/rl.php is outside this agent's files):
  `'errand_model' => env('AI_RL_ERRAND_MODEL'),`.
- `app/Domain/Choice/WideErrandEncoder.php`: 17 state + 47 candidate columns, ≤64 rows, row 0 wait. A row is one of the
  engine's own candidates whose type is a simulator kind, encoded as `errands::candidate_features_in` encodes that kind;
  its index in the trace is the row's objectId, so the pick runs that very candidate through the normal schedule/gates.
- `DecideAiErrandAction`: with a v3 model, the model picks (learner share respected); wait = the engine's quiet login
  (economy still runs). A hostile inbound / owed save stays the engine's reflex (no choice), as before. Not recorded.
- Gate 1: roles from the host (espionage/recycle/moon-destruction mission hulls, catalogue ship lists, unit properties);
  the missile count reads `QueueableMissilePlanner::MISSILE` (the module's one existing name, made public).
- No-cheating: target research = the report's research section, else our own (a detached "pictured" owner, never saved);
  the forecast battle runs on `ReportedPlanet::of($report, $pictured)` (new optional owner) so the host engine reads no
  live research. Test: the target's live fleet/defence/stock/research change after the report → every column identical.

Kinds: done = spy (spy planner's target, priced as 1 probe as the sim), raid, moon_destroy (raid planner's moon-destruction
mission), recycle, save (proactive save only; reactive is the reflex), recall, order (the unit planner's one order, as
`Size::Want`), wait, strike (missile planner), acs_defend (defend planner, half fleet), expedition (one row; PHP fleet =
one hull per role), gate_save (jump-gate planner), phalanx (scan + the raid row on the same planet, else distance only).
Illegal-for-now (no module planner/executor, so no row; an illegal row is never scored by `Policy::scores` anyway):
moon_shot, acs_join, deploy (moon staging/ninja); and the sim's plan variants raid War/WarSlow/Breaker, order
Now/Batch/Hull rows, expedition Cargo/Doubler/Escort trio, Save Night/Hidden, GateSave Night (PHP offers one row each).
Impossible-without-cheating: none found.

| column | Rust (`errands.rs`/`players.rs`) | PHP source | status |
|---|---|---|---|
| stock, stock_ratio | capital weighted stock (1/1.5/2), / Σ storage | capital resources, storages | exact |
| probe cargo fighter harvester | `Kit` roles on capital shelf | espionage hull, best cargo/price, cheapest shooter held-or-buildable, recycle hull | exact roles; "owned" ≈ shelf+buildable |
| hull_home | Σ hull of fighting units | `DefenderFleet::fromPlanet(capital)` | exact |
| hour_sin/cos | t mod 86400 | server time of day | exact (tz) |
| hours_since_attack | last Attack fight at capital | last battle report on capital (defender) | exact |
| incoming_hours | soonest Attack landing on capital | event list, mission 1 | exact |
| idle | login with no build decision | capital build queue busy | approx |
| warship_value, has_moon, missiles | capital | capital | exact |
| expedition_free, fleet_slots_free | free/max slots | host slot counts | exact |
| distance, flight_hours, fuel_over_stock | from capital, host formulas | host distance/duration/consumption from the planner's origin | exact formulas; origin may differ |
| expected_loot, loot_over_fuel | report stock × loot% by hold | `LootService::distributeLoot` on the launch hold | exact |
| target_ratio, report_age_hours, target_stock | newest report, reported (else own) research | same (messages → reports) | exact |
| target_inactive | galaxy 'i' | owner `isInactive()` | exact |
| field, field_over_capacity | `harvest_plan` from capital | debris field + capital recyclers, same gates | exact |
| attack_in_hours, order_share | save / order | same | exact |
| fleet_value, speed, odds | flown fleet vs reported value | same | exact |
| predicted_loss, loss_share, predicted_win | host engine on report, 200 units, seed 1 | host Rust engine, same scaling/seed | same procedure; RNG streams differ |
| moon_destroy loss_share/win | host moon odds (WIK-196) | same two formulas (host keeps them private) | exact |
| doubler, escort, expedition_free | plan variant | PHP fleet holds fast civil / combat hull | approx |
| unit_* (8) | host tables at own research | host properties at own research | exact (parity 0.0) |
| order_over_stock | Hull rows only | 0 (no Hull row) | exact for Base |

Parity: header names identical to bc-late-c2 `_names` (17/47). Network vs the trainer's torch model (float64): small
same-layout model max |Δscore| 4.2e-17 (101 rows), trained bc-late-c2 1.4e-14 (473 rows), argmax 32/32 points;
~10 ms per 24-row point. Order-row unit columns vs the sim's own tables (combat-u1/units-u1, Collector, research
109:3 110:2 111:4 114:5 115:6 117:4 118:2): 27 units × 8 columns, max |Δ| 0.0. Situation columns: no same-situation
cross-check is possible without a sim hook (Rust read-only here) — open. Fixture tests/Fixtures/errand-v3-parity.json
(generator: scratch script using `ogrl.model.CandidateScorer` + `errand_schema.policy_json`, 6-decimal inputs).
Tests: tests/Feature/WideErrandEncoderTest.php 8/8 (container, FFI); with the touched classes' tests 82/82.
Open: (1) config line above; (2) PRE-EXISTING no-cheating leak: `NativeRaidEstimator` (raid planner screen) fights on
`ReportedPlanet::of($report)` whose owner is the LIVE account, and `RustBattleEngine` reads defender research from
`defenderPlanet->getPlayer()` → the teacher's raid accept/launch reads hidden research (fix: pass a pictured owner, as
the encoder now does — owner decision, it moves today's raids); (3) a sim hook (e.g. dump raw situation + row) for
situation-level column parity; (4) kinds/variants listed illegal-for-now; (5) PHP late-state run with the model.
- PHP v3 agent verified + finished by me: added `ai.rl.errand_model` (env AI_RL_ERRAND_MODEL, off by default) to
  Modules/AI/config/rl.php. Cheating leak the agent found FIXED (owner's top rule): RaidPlanner::target built the
  report's planet but the battle engine read the defender's LIVE research; now ReportedPlanet::seenBy() owns the copy
  by the report's pictured owner (research the probes read, else the account's own) — shared with WideErrandEncoder
  (ReportedPlanet::pictured). This changes which raids the engine offers (intended). rector/pint/phpstan clean;
  277 related module tests pass (container).
- D 1 h run, gate at gen 8 (04:14): HDS 0.824 (start 0.822); top10 64-128 / 128-256 growth 1.00 / 0.99; model
  accounts' ln score vs gate-0: +0.159 (≈ +17%), 10/10 seeds, 95% CI [0.105, 0.224]; drift 0.083. Steady gain
  (gen 2 +0.037, gen 4 +0.076, gen 8 +0.159) with realism held.
- D 1 h run, gate at gen 12 (04:28): HDS 0.820 (start 0.822, drop 0.002 ≤ 0.01); top10 growth 1.00/1.00; model
  accounts' ln score vs gate-0 +0.259 (≈ +30%), 10/10 seeds, 95% CI [0.180, 0.353]; the 43 scripted accounts +0.008
  (the gain is not taken from the others); drift 0.102. Pending: anomaly check (gate runs lack `levels`) → a
  confirmation bench (start vs final, 10 seeds, `levels`) after the run ends.
- Confirmation bench (`runs/es-late-s15/confirm`, 10 seeds, `levels`): model accounts +0.318 ln score, 10/10 seeds,
  CI [0.230, 0.416]; scripted +0.014; HDS start 0.822 → final 0.815 (drop 0.007 ≤ 0.01). New anomalies on final:
  never held a colony ship, defence magnitude, light share. Verdict: the gain is real, but NO longer run yet: the sim
  has a bigger gap than the model's step.
- Sim gap (verified on levels.json): with `satcap` graviton = 0 on all top10 (cap-d, confirm), so no Deathstar can be
  built; and even without `satcap` (fix5, late-d) only mid raiders (40-60M) hold 1-5 Deathstars at day 256, no top10
  account does (real data: Deathstars on 62-92% of large planets). Top10 are savers/miners whose capitals meet every
  Deathstar requirement (yard 21, hyperspace tech 17, drive 15, graviton 1).
- Fix 1 (`players.rs` `power_by_units`, arm `satcap`): energy a research asks for (requirements met, below the
  catalogue ceiling) is bought on the capital within the 10,500-unit cap (`research_energy`, `research_within_cap`,
  gate 1 clean). Bench `runs/late/cap-e` vs cap-d running; go: graviton ≥ 1, satellites ≤ cap, HDS ≥ 0.844.
- Fix 2 (in progress): why top savers/miners never buy a Deathstar. Suspect: under `cap` the surplus goes to the
  military only while military share < persona share, and cargo/satellites/pathfinders fill that share; debug run
  (one seed, temporary `OGSIM_DEBUG_SPEND` print, to be removed) running.
- Fix 1 bench (`runs/late/cap-e` vs cap-d, 10 seeds): HDS 0.845 (cap-d 0.844); top10 graviton ≥ 1 in 45/100 (cap-d
  0/100); max satellites per planet 10,492 (cap held); wall 215-218 s per seed. Partial go: the cap still blocks
  the rest. Top10 holding a Deathstar: 0 in both.
- Debug seed (day 250): top capitals sit over their military share (saver 0.23-0.27 vs 0.20, miner 0.18-0.19 vs 0.06)
  so `cap` spends no surplus; a miner capital held 3.6B metal idle (keep = 0). When short, savers pick their
  template's battlecruiser, not the Deathstar; second debug run reads which template hulls the yard cannot start.
- Second debug: on top saver capitals the Deathstar is buildable (not blocked); the yard simply never spends (over
  share). Measured the hoard: top10 hold a median 87 days of production (19B; p90 123 days) at day 256 (cap-e). Source
  TP-022: stores ~18-24 h of production. This is the largest gap: an idle economy no player keeps.
- Fix 2, arm `store` (`players.rs`: `store_hours`, `account_rate`, `spend_surplus(.., hours)`): a capital holding more
  than 24 h of its account's production beyond the saved-for build spends the excess on its template even over its
  military share. Debug print removed; 117 lib tests pass. Bench `runs/late/store-a` (cap-e + store) running.
  Go: store median ≤ 48 h, top10 holding Deathstars > 0, HDS ≥ 0.844 (watch mil_sh), wall ≤ cap-e + 5%.
- Fix 2 bench `runs/late/store-a`: NO-GO. HDS 0.611 (cap-e 0.845); top10 growth 0.98 → 0.00, res_sh 0.99 → 0.14,
  top10 points 69k → 27k; the store did not fall (median 2,166 h vs 2,087 h). So the capital's spend starved its own
  research and building saves, and the hoard sits elsewhere (colonies; the capital listing at day 256 held 1.8B of a
  ~6B account). Top10 with a Deathstar 0 → 2 (14 Deathstars), not worth the economy. Arm `store` removed from the
  code (build clean). Wall 162-166 s per seed (cap-e 215-218 s, faster only because the economy stalled).
- Next (re-measured, not a fourth blind fix): where the 19B sits per body (capital vs colonies vs moons) and why
  `bring_home` / the colony queues leave it; then decide. Deathstars stay an open gap; the ES model is not retrained
  until the sim holds them.
- Re-measure (cap-e top10, day 256): 60% of the store is on the capital (median 1,233 h of account production), 39% on
  colonies, ~0 on moons. The `store` no-go shows the capital store pays the lab's big research (the spend's keep held
  only the deciding queue's pick), so the hoard is mostly saving, not idleness; a fleet buy must keep every queue's
  next price, not one. Not fixed now: an open question for the real-data check (no source yet on late-game store size
  beyond TP-022's mid-game 18-24 h).
- Fix 2b, arm `store` re-made from the re-measure (not blind): the capital keeps, per resource, the dearest next step
  of ANY queue (requirements met, below the ceiling: `next_steps`) plus 24 h of account production, and spends only
  the rest on its template while over its share. Bench `runs/late/store-b` running. Go: HDS ≥ 0.844 with top10
  growth/res_sh held, top10 with a Deathstar > 0, wall ≤ cap-e + 5%. No-go → drop `store` for good, Deathstars by
  another route.
- Fix 2b bench `store-b`: NO-GO. HDS 0.716; top10 growth 0.98 → 0.01, planets/moons cells 1.00 → 0.00 (the spend
  took the colony and moon steps' resources too), store median rose to 3,606 h, top10 with a Deathstar 0. Wall
  150-153 s. `store` dropped for good and removed from the code. Lesson: in this sim the late store is the economy's
  saving; a fleet buy forced from it breaks growth. Deathstars need another route: why the top capitals' share is met
  without them (their template is filled with cheap hulls and the counts-based gap), to be measured before any code.
- Re-measure (cap-e top10 unit value, host prices): civil 45%, warships 55%, defence ~0% (3.7T in units over 100
  accounts). The warship value is mostly light-fighter stacks on miners (1.1-1.5M each). Next measure before code:
  the per-account warship mix vs the real-data mix (count share vs value share of the doctrine template: a Deathstar
  at 3% by count is ~60% by value), to see whether `template_pick`'s count gap keeps buying the cheapest hull.
- Measured cause (cap-e top10, value by hull): the 46 top10 savers hold ~0B of warships per account, the 54 miners
  37B; the saver template is 84% Deathstar by value (raider 66%). Savers reach their 0.20 military share with the
  civil ships' half alone (cargo sized to the store: 420k large cargo, 200k pathfinders), so the yard never spends.
- Fix 3, arm `warshare` (`score::civil`, `players::war_share`): the persona's yard share leaves the civil half out.
  Bench `runs/late/war-a` (cap-e + warshare) running. Go: HDS ≥ 0.844 (mil_sh held), top10 with a Deathstar > 0,
  saver warships > 0, wall ≤ cap-e + 5%.
- Fix 3 bench `war-a`: weak, NO-GO as a step: HDS 0.839 (cap-e 0.845, -0.006), top10 growth/res/mil held (0.97-1.00);
  top10 with a Deathstar 0 → 2; saver warships 0 → 1B per account (miners 37 → 38B). Wall 202-212 s (cap-e 215-218).
  The arm stays in the code off by default (one flag, `warshare`), not in the bench set. Third fix this cycle with
  small or negative moves: STOP the Deathstar direction here. Measured remaining reason, for later: the savers' yard
  money goes first to the cargo plan sized to the store (`order_plans`: stock x cargo_share / hold), the warship buy
  gets the rest.
- Next largest gap, by the confirm anomalies: top10 defence ~0% of unit value (real large planets hold walls), the
  model accounts' "never held a colony ship", light share. Re-measure these on cap-e before any code.
- CORRECTION (re-measure including moon units): savers are not fleetless; they hold ~39B of warships per account on
  their moons (1,792B over 46 top10 savers; miners 1,977B on planets). The "savers hold ~0B" line above read planet
  units only; `warshare`'s premise was wrong (its bench stays a no-go). Deathstars incl. moons: 0/100 in late-d, cap-d,
  cap-e; 2/100 (19) in war-a.
- Measured cause: `template_pick` counts only the yard's slot (`owned(w, a, ..)`); the stationed fleet is on the moon,
  so every hull reads "not held" (+1) and the pick goes to the biggest count share (battlecruiser 35/111), never the
  Deathstar (3/111). Fix 4, arm `postcount`: the fleet template counts the account's planets and moons. Bench
  `runs/late/post-a` running. Go: top10 with a Deathstar > 0 (real OGMEM: 23% of sides, median 2), HDS ≥ 0.844,
  wall ≤ cap-e + 5%.
- Anomaly report on cap-e (top 5): fleet ≥ 1e9 parked on a planet with a moon (99/100; miners' warships, 1,977B, and
  civil stacks), banked resources (874 h), no Deathstar, satellites per account 83k (Graviton needs ~9k on the hottest
  slot), no Crawler.
- Fix 4 bench `post-a`: HDS 0.844 (= cap-e), wall 209-211 s, top10 with a Deathstar 0/100: alone it does not move the
  measure, because top capitals are over their share and the yard does not spend at all (the pick never runs). The
  mechanism now has both halves measured (spend gate, pick count), so ONE combined bench `wp-a` (warshare +
  postcount) runs; no-go there = the Deathstar direction stops and both arms stay off.
- Combined bench `wp-a` (warshare + postcount): NO-GO. HDS 0.840 (cap-e 0.845), top10 with a Deathstar 1/100 (4
  ships), wall 203 s. The Deathstar direction STOPS here (store, store-b, warshare, postcount, combined: none reaches
  the real 23%). Both arms stay in the code off by default, out of the bench set. Kept: fix 1 (graviton energy within
  `satcap`, cap-e). Open for later, with a source first: how real top fleeters time their first Deathstar (the
  doctrine's count shares may be the wrong unit: 3% by count is a lone ship the yard can always postpone).
- Next largest gap (anomaly #1, the fleet ministry's own job): miners' warships (≈2T over 54 top10 accounts) parked on
  planets that have a moon; real players keep them on the moon or in flight. Measure first: which persona/arm keeps
  them there (`station` runs only for savers).
- Measured: `station` (warships to the post moon) runs only in `FleetSaver::act`; miners never call it. Fix 5, arm
  `minerpost` (`Player::act`, Miner branch): a miner capital stations its warships on its post moon too. Bench
  `runs/late/mp-a` (cap-e + minerpost) running. Go: anomaly #1 (warships ≥ 1e9 on a planet with a moon) falls by
  half or more, HDS ≥ 0.844, wall ≤ cap-e + 5%.
- Fix 5 bench `mp-a`: GO. Planets with a moon holding ≥ 1e9 of warships 54 → 3 (warships on them 1,980B → 17B; on
  moons 1,792B → 3,908B); HDS 0.845 (= cap-e); top10 growth/res/mil 0.97-1.00; wall 208-209 s (cap-e 215-218).
  `minerpost` joins the bench set (teacher world = cap-e arms + minerpost).
- FOUND (verification of Stage C): the Python `ogsim` extension that `train_bc_world` imports was built 4 Nov 16:14,
  before the `late`/`satcap` arms (5 Nov); `SeasonWorld` ignores unknown arm names silently. So `bc-late-c2`'s rows
  (03:21) came from a teacher WITHOUT the late tactics; "Stage C clones the late teacher" was wrong. The rollouts and
  ES used the `season` binary (current), so D's measured gain stands, but its init was a non-late clone. Rebuilt with
  `maturin develop --release` (06:09). Guard for later: an arm name the sim does not know should fail, not pass.
- Stage C re-clone `runs/bc-late-c3` running (collect + BC on cuda:1, ≤ 1 h): teacher = cap-e arms + minerpost.
  Go: top-1 ≥ 99% and rollout HDS (10 seeds, `roll.sh`) ≥ teacher mp-a's 0.845 - 0.01. Then a 15-min ES slice.
- C3 collect (rebuilt extension, teacher cap-e + minerpost): 545,176 rows; 12 epochs top-1 97.3%; 80 epochs on the
  saved rows (49 s) 98.0%, flat from epoch 40 (97.9-98.1%): the late teacher is harder to copy than c2's (99.3% on
  the non-late teacher). Below the 99% target; the rollout HDS decides (`roll80`, 10 seeds) — running.
- C3 rollout (`bc-late-c3/roll80`, 10 seeds, clone flies the errands): HDS 0.845 = teacher mp-a 0.845; top10 growth
  0.98/1.00; wall 209-211 s. GO (top-1 98.0% < 99% target, but play matches the teacher). Errand model for D:
  `bc-late-c3/bc80.pt`.
- Stage D slice on C3 `runs/es-late-c3` running (8 seed pairs, 32 procs, 2 gens, gate-0 and gate-2, lr 0.003, anchor
  0.5, arms + minerpost, ≤ 25 min cap). Go: model accounts' score up at gate-2, HDS drop ≤ 0.01. Then ≤ 1 h.
- D slice on C3 (`es-late-c3`, gens 1-2, 194 s/gen): gate HDS 0.826 → 0.826; model accounts +0.010 ln score, 5/10
  seeds, 95% CI [-0.043, 0.062]; scripted -0.009; drift 0.025. INCONCLUSIVE (c2's slice was +0.037 at gen 2 and
  clear only at gen 4). One extension to gen 4 (~15 min, gate at 4) decides: no clear gain there → stop and look at
  why the late clone's votes do not improve (vote_sd 0.12 → 0.36).
- D slice extension (gens 3-4): gate-4 HDS 0.823 (gate-0 0.826, drop 0.003); model accounts +0.045 ln score, 8/10
  seeds, 95% CI [0.004, 0.087]; scripted +0.024 (so the model's own edge ≈ +0.02; c2 was +0.076 at gen 4). Moved its
  measure: GO for ≤ 1 h (`es-late-c3/run1h.sh`, gens 5-14, gate every 4, stop on HDS drop > 0.01). Then a
  confirmation bench (start vs final, 10 seeds, `levels`, anomaly report) before anything longer.
- Guard added (`ogrl/train_world.py` `ensure_fresh_sim`, called by `season_world`): a Python `ogsim` build older than
  any `rl/ogsim/src/*.rs` stops the run with the rebuild command. Positive check passes on the 06:09 build; negative
  check deferred (touching a source would block BC until a rebuild, and the 1 h ES run is live).
- D 1 h run on C3 (`es-late-c3/run1h`): at gen 7 (gate-4 0.823, gen ~194 s); gates 8 and 12 pending.
- D 1 h on C3, gate-8: HDS 0.822 (gate-0 0.826, drop 0.004); model accounts +0.118 ln score (≈ +12.5%), 10/10 seeds,
  95% CI [0.078, 0.165]; scripted -0.007 (the gain is the model's own); drift 0.055 at gen 8, 0.064 at gen 11. Gate-12
  pending, then the confirmation bench.
- D 1 h on C3 ended (14 gens, exit 0). Gate-12: HDS 0.818 (gate-0 0.826, drop 0.008 ≤ 0.01); model accounts +0.162
  ln score, 8/10 seeds, 95% CI [0.076, 0.234]; scripted -0.007. Final exported: `es-late-c3/final-g14.policy.json`.
  Confirmation bench `es-late-c3/confirm` (start gate-0 vs final, 10 seeds, `levels`, anomaly report) running.
  Watch: HDS drifts down each gate (0.826 → 0.823 → 0.822 → 0.818), so a longer run needs the gate held, not assumed.
- Confirmation C3 (`es-late-c3/confirm`, start gate-0 vs final g14, 10 seeds, `levels`): model accounts +0.179 ln
  score (≈ +19.6%), 9/10 seeds, 95% CI [0.103, 0.249]; scripted +0.004. HDS 0.826 → 0.817 (drop 0.009 ≤ 0.01). The
  anomaly set is identical (18 = 18, no new one). Wall 211-216 s per seed. CONFIRMED.
- Where HDS falls (cell diff): top10 cells equal or better (128-256 growth 0.98 → 0.99, 64-128 res 0.98 → 0.99); mid
  equal; the drop is the BOTTOM band (bottom|128-256 median 14.8k → 42.9k points, eco/res/mil shares out of band;
  bottom|64-128 eco 0.94 → 0.68): model accounts that would sit at the bottom now grow out of it. Real bottoms are
  near-abandoned (median 157 points), so a better model always costs this cell. A longer run gated on whole-HDS vs
  gate-0 will stop on this drift, not on a realism loss in how the model plays.
- Next: gate a longer run on the top10 + mid cells (the bands the model is judged in) with whole HDS still logged;
  then ≤ 2 h. Decision logged here before the gate code changes.
- `es_world --gate-bands` (`band_hds`: mean of the in-band values of the named bands' cells in the gate's hds.txt; the
  drop check reads it instead of whole HDS). On C3's past gates, top10+mid: g0 0.840, g4 0.838, g8 0.837, g12 0.834
  (-0.006); bottom: 0.561 → 0.511 (-0.050). So the bands the model is judged in drift too, slowly; the gate holds them.
- Stage D ≤ 2 h on C3 `es-late-c3/run2h` started (resumes gens 15-34, gate every 4, stop on top10+mid drop > 0.01).
  Then a confirmation bench (g14 vs final) before anything longer.
- 2 h run, gate-16: top10+mid 0.834 (gate-0 0.840, -0.006), whole HDS 0.815; see the score line below.
  gate-16 score: model accounts +0.169 ln vs gate-0, 10/10 seeds, CI [0.119, 0.218]; scripted +0.013 (gate-12 was +0.162: the gain is flattening; drift 0.076). Watch gate-20/24: no rise there = stop early, not waste the 2 h.
- 2 h run, gate-20: model accounts +0.222 ln vs gate-0 (≈ +25%), 10/10 seeds, CI [0.145, 0.299]; scripted -0.009; top10+mid 0.833 (-0.007), whole HDS 0.815. Still rising (g12 +0.162, g16 +0.169, g20 +0.222): the run continues.
- 2 h run, gate-24: model accounts +0.170 up 9/10 CI [0.096, 0.245] | scripted -0.001 ln vs gate-0; top10+mid 0.832 (-0.008), whole HDS 0.816.
- 2 h run STOPPED early at gen 25 (~55 min of it): the gain flattened (g12 +0.162, g16 +0.169, g20 +0.222, g24 +0.170;
  CIs overlap) while top10+mid slid 0.840 → 0.832, near the 0.01 stop. Best gate: g20. Confirmation `confirm2`:
  start = g14 (confirmed) vs final = gate-20, 10 seeds, `levels`. Go to deploy g20: model accounts up vs g14 with CI > 0,
  no new anomaly; else g14 stays the model.
- confirm2 (g20 vs g14, 10 seeds): model accounts +0.042 ln, 7/10 seeds, CI [-0.021, 0.105] (not > 0); scripted
  -0.013; HDS 0.817 → 0.815. Anomaly set: g20 adds "light hulls above the real p90 share in a big attack fleet" and a
  levels/day-row alliance mismatch, drops "light laser per planet" and "no moon_shot". NO-GO: g14
  (`es-late-c3/final-g14.policy.json`) stays the confirmed model: +0.179 over its start, 9/10, CI [0.103, 0.249].
  ES on this setup has plateaued near +0.18-0.22; more generations only add noise and drift.
- Next options (decide by largest gap): (a) wire g14 into the PHP side (`AI_RL_ERRAND_MODEL`) and run the PHP
  same-situation parity hook + a PHP late-game run (the deployed path is untested with a late model); (b) the
  alliance mismatch anomaly (a sim bookkeeping bug, cheap to check); (c) the light-hull attack anomaly, which ES
  itself pushes toward (a reward that favours cheap swarms over real fleet mixes).
- PHP parity on the confirmed model: `tests/Fixtures/errand-v3-parity.json` regenerated with `trained_policy` =
  `es-late-c3/final-g14.policy.json` (was bc-late-c2's non-late clone; generator: scratchpad fixtures_g14.py, the
  trainer's own torch scores). `WideErrandEncoderTest` + `ErrandChoiceSeamTest`: 11 passed (114 assertions) in the
  container: PHP loads g14 and scores every fixture row as torch does (gap < 1e-6, argmax agrees). Deploy path:
  `AI_RL_ERRAND_MODEL=storage/rl/ogsim/runs/es-late-c3/final-g14.policy.json`. Still open: a PHP late-game run with it
  (kinds PHP lacks: moon_shot, acs_join, deploy, so the model's late errands there fall back to the engine).
- Anomaly "levels.json says no alliance, the last day row says member" (97/100): a dump bug, not play. levels.json
  wrote `alliance_id`, which is 0 for the one shared alliance, so every member read as "none". `examples/season.rs`
  now writes `alliance` = member (bool) and `alliance_id` (null when none). Check (40-day season, seed 1): levels 29
  members = day rows 29. No sim behaviour or speed change; no PHP reader of the field (hds.py reads day rows).
- Light-hull attack anomaly: absent on g14 (confirm/final) and on its start; present only on g20 (6/100 accounts) —
  one more reason g20 was rejected. Nothing to fix for the deployed g14.
- Agent started (background): same-situation errand parity sim vs PHP (dump the sim's errand point for one account,
  import the same state with ai:rl-import-sim, encode in PHP, compare the 17 state + 47 candidate columns). Owns new
  files + WideErrandEncoder/ImportAiRlSimState fixes + an off-by-default arm in examples/season.rs only. Its claims get
  verified before use.
- Owner (5 Nov): stop circling on stuck items; local data first, then intensive web research, then act. Three research
  agents started (read-only, one report file each under plan/research/ogame/): LATE-GAME-FLEET-STORE.md (Deathstar
  timing/count/value share, late-game store size, top fleet mixes), LATE-GAME-TACTICS-USAGE.md (missiles, moon shot,
  phalanx, spying, relocation, crawler, ninja/ACS/deploy/gate escapes, deception, FS-008), DIPLOMACY-BETRAYAL.md
  (alliances, pacts, cooperation, betrayal, the game rules that bound them). No code on these items until the reports
  give testable rules with sources; then one bench per rule.
- Research report LATE-GAME-FLEET-STORE.md (agent) read and its key table re-computed by me on
  OGMEM normalized/battle_unit.jsonl (all defender sides, host prices): Deathstar held by 8/84 (<1e8), 35/165
  (1e8-1e9), 132/188 (1e9-1e10), 93/100 (1e10-1e11), 20/21 (>=1e11) — matches the report (9/20/57-71/93-95%).
  Mostly defenders (attackers 1%), held for fleet saves; attackers use pure Deathstar stacks. Late "store": sources
  give 24-48 h EXPOSED; long saves fly (fleet save) — our 87-day figure counts planet stores, so the gap is that our
  savings sit on planets, not their size. Real attack fleets 1e9-1e11: ~52% battlecruiser, 24-27% cruiser by value.
- Fix 6, arm `anchor` (`Miner::anchor_plan`, ANCHOR_VALUE 2e9): the capital keeps its template's dearest hull once
  allowed, one per 2e9 of account warship value (min 1), paid in `order_plans`. Bench `anc-a` (mp-a + anchor)
  running. Go: top10 holding the hull ≥ 80% and count in the OGMEM band (1-27 by tier), HDS ≥ 0.844, wall ≤ +5%.
- Research report LATE-GAME-TACTICS-USAGE.md (agent) read; key claims checked by me:
  - `moon_shot` in the tally counts moon DESTRUCTION (examples/season.rs), so "never moon_shot" is not a gap (real:
    8/5,402 big-hit threads). The anomaly label is misleading — fix the label, not the play.
  - `spy_sent` counts probe arrivals (examples/season.rs:887) and is NOT zero: mp-a seed 1 = 2,505 (all raiders).
    But raids/spies: raiders 31,218 raids per 2,505 spies (≈12:1), miners 547 and savers 213 raids with 0 spies.
    Cause: a report stays "fresh" 24 h (`players::REPORT_HOURS`, errands.rs raid rows), so one probe feeds a day of
    raids; real raiders probe before each hit (200-500 reports/day). Not a leak (the report was seen) but unreal.
    Candidate fix (changes the menu the model was trained on → re-clone + ES after): raid needs a report from the
    same login window (≤ 1 h); measure raids:spies → ~1:1 for raiders, HDS held.
  - Silo without missiles is normal for fleeters (flag turtles only); Crawler is Collector-only (3.5% of OGMEM sides,
    median 1,100, all miners); ninja/ACS 6% of big hits, gate escapes 1.1%; FS-008 = one extra activity stamp
    1-14 min after a save. Relocation, fake attacks, betrayal: too rare/undocumented for a frequency target.
- Research report DIPLOMACY-BETRAYAL.md (agent; Gameforge public API, 70 live universes, + rules/boards). Checked by
  me on its API dump: active players in an alliance 10,795/17,851 = 60.5% (report 62.4% on 17,289 — a slightly
  stricter "active"; same picture). Rank gradient in old universes: top10 98%, 11-100 93%, 101-500 74%, >1000 40%.
  Hard rules for the foreign ministry: pushing (no profit from lower-ranked; trades/help settled in 72 h; ratio
  3/2/1-2/1/1; gift cap), bash limit 6 attacks/day enforced in game (read from host config), one account, no sitting
  combat. Betrayal: legal but taboo, no frequency source → not a target; pacts are honour-only with 24-48 h notice.
  These feed the foreign ministry design (after D); nothing coded now.
- Fix 6 bench `anc-a`: NO-GO but informative. Top10 with a Deathstar 0 → 18/100 (all 18 among the 43 with graviton;
  graviton is the cap: 43-46/100); whole HDS 0.845 → 0.860 (wall 199-200 s) but top10 growth 0.97/1.00 → 0.71/0.72
  and early cells collapsed (mid|0-14 median 101 → 18 points). Cause (my bug): `allowed` is the class's permission,
  not the requirements, so every account kept the dearest hull's price from day 1. Fixed (`requirements_met`),
  re-run `anc-b` (one re-run of a verified bug, not a new attempt).
- `anc-b` (requirements fixed): HDS 0.847 (mp-a 0.845), top10 growth 0.96/1.00, mid|0-14 back to 101; median total
  153M (156M); wall 212 s (208, +2%). Top10 with a Deathstar 9/100 — all among 42 with graviton; holders' median 15
  (OGMEM 1e10-1e11 band 11-27: in band). Moves the measure without harm → `anchor` KEPT.
  Still short of the band (93% of such fleets): (1) only 9 of 42 eligible reach an ordering login (`order_ships`
  gate) — closed in `anc-c` (a missing anchor is an order of its own), last run on this arm; (2) graviton 42%.
- Same-situation parity (agent; verified by me: WideErrandSituationParityTest + WideErrandEncoderTest +
  ErrandChoiceSeamTest = 20 passed, 190 assertions, container). New arm `errand-dump=DAY[:ROWS[:KIND]]`
  (examples/season.rs, off = one Option check) + 9 dump fixtures. Matching (≤ 2.5e-5): 13 of 17 state columns and
  the raid/spy/recycle/strike candidate columns, unit_* and the 16 kind one-hots. Differing, each with a cause:
  idle / expedition_free / fleet_slots_free (the import carries no build queue nor fleets in flight); expedition,
  order/hull and night-save rows (the host's own menu differs). Fixed in PHP: strike-row crash with missiles,
  expedition rows priced at the own slot and saves at full speed (both mirror sim quirks, so g14 sees what it was
  trained on — kept that way; if the sim is ever fixed, revert both and retrain), moon-target fields, and the import
  (fleet speeds/consumption from fleet-settings-u1, home coordinate, storage/production recompute, exact stock,
  officers). Not yet exercised: Save/Base, Phalanx, MoonDestroy, GateSave, AcsJoin, an incoming attack. Open: the
  narrow menu offers spy/raid rows with no free slot; `levels` writes the donor's position, not the world's.
- `anc-c` (a missing anchor triggers the yard order; last run on this arm): HDS 0.846, top10 growth 0.97/1.00, median
  total 154M, wall 214 s; top10 with a Deathstar 13/100 (anc-b 9), holders' median 16 (in the OGMEM band). Kept. The
  arm stops here: 13 of 45 graviton holders; the binding limit is now graviton itself (45/100) and the energy cap.
  Teacher world for the next clone = mp-a arms + anchor.
- Fix 7, arm `freshspy` (world `fresh_spy`, `own_hits`; `players::last_report` drops a report older than the
  account's own last attack landing on that body — the account read its own combat report): raids need a new probe
  after each hit, as the scripted raider's `fired` check already does. 117 lib tests pass. Bench `spy-a` (anc-c arms
  + freshspy) running. Go: raids per spy for raiders from ≈12 toward ≤ 2, HDS ≥ 0.844, top10 growth held, wall ≤ +5%.
- `spy-a` (anc-c + freshspy), 10 seeds: raiders 245,934 raids / 22,185 spies (11.1:1) → 100,643 / 58,129 (1.7:1),
  raiders' loot 51.7B → 58.3B (fewer, better-aimed raids). Whole HDS 0.846 → 0.826, but the drop is again the
  bottom band (bottom|128-256 median 14.9k → 29.6k points: weak accounts farmed less, grow out of it) and mid growth
  (0.34 → 0.15, 0.10 → 0.01); top10+mid band score 0.846 → 0.841 (-0.005, inside the 0.01 band gate); top10 cells
  equal or better; wall 206 s. GO on its measure and on the band gate → `freshspy` KEPT.
- New measured gap (from the same run): raiders probe ≈ 0.8 times a day each (58,129 spies / (10 seeds × ~19
  raiders × 256 days)); real farmers 200-500 reports a day (LATE-GAME-TACTICS-USAGE.md). Raider activity, not the
  spy rule, is what keeps bottoms unfarmed. Next: measure what caps a raider's day (logins, slots, probes, targets)
  before any code.
- Teacher world for the next clone = mp-a arms + anchor + freshspy (both change the errand menu → C re-clone and a
  D slice are needed before g14 is replaced).
- Measured (spy-a seed 1, raiders, day ≥ 30): logins median 4 (mean 5.2) a day, raids median 1 (2.7), spies 1 (1.5).
  Cap: the season runs ONE errand a login (examples/season.rs). Arm `errands=N` (binary only for now): up to N
  errands a login until the pick is the wait row. Bench `err-a` (spy-a arms + errands=4) running. Go: raider raids
  and spies a day up ≥ 2x, bottom cells back toward band, top10+mid ≥ spy-a - 0.01, wall ≤ +15% (more decisions are
  real work, not slowness). If go, python.rs's SeasonWorld must get the same loop before any clone (C4, running now
  with 1 a login, would be redone).
- C4 collect running (`bc-late-c4`, teacher mp-a + anchor + freshspy, Python ogsim rebuilt 5 Nov, 60 epochs).
- err-a result (5 Nov, 10 seeds × 256 d): raider raids/day 2.07 → 10.40 (5.0x), spies/day 1.20 → 3.81 (3.2x),
  loot 58.3 → 61.4B; bottom band scores up (64-128 eco_sh 0.29 → 0.49, mil_sh 0.25 → 0.44; 128-256 eco_sh 0.84 →
  0.95); top10+mid 0.841 → 0.843; HDS 0.826 → 0.828. Behaviour: GO on every measure. Wall 206 → 371 s (+80%):
  FAILS the ≤ +15% gate. Not adopted yet. Next: perf profile errands=1 vs 4 on seed 4 (one process each) to see if
  the cost is the extra decisions (observe/score per loop) or the real extra battles; fix only a measured hotspot.
- C4 clone done: top-1 98.6% (518,498 rows). Rollout `bc-late-c4/roll80` running (go: HDS ≈ teacher 0.826).
- C4 rollout (roll80, 10 seeds): HDS 0.828 vs teacher 0.826, wall 208 s → GO (C4 = new BC start for a D slice).
- errands perf (seed 4, perf record): one function 0x108b30 goes 1.5% → 18.9% of samples with errands=4; rest flat.
  A single hotspot → fixable. Next: symbolised build to name it.
- Study: game theory / evolution for training → research/ogame/GAME-THEORY-EVOLUTION-FOR-TRAINING.md (order A-E).
- 5 Nov, game-theory/evolution plan (research/ogame/GAME-THEORY-EVOLUTION-FOR-TRAINING.md, "Revised plan") started in
  parallel: agents T3 (rl/ogsim: per-account policy pool), T1 (ogrl/pbt_es.py: PBT + adaptive sigma, smoke only),
  T2 (scripts/replicator.py: persona ESS vs real mix), T7 (foreign-ministry repeated-games design doc).
  CPU meanwhile: D slice on C4 (`es-late-c4/run.sh`, 4 gens, gate-4 vs gate-0 on top10+mid, unit rl-es-c4).
- T7 done: research/ogame/FOREIGN-MINISTRY-REPEATED-GAMES.md (S0-S5 after D). Checked: cited real numbers (top10 98%
  in alliances, 57% founded by day 30) match DIPLOMACY-BETRAYAL.md. Key design points: hide season end (random length,
  else learned end-game betrayal), grudge broadcast needed for reputation, hard rules as masks, first check that
  neighbour play is really a prisoner's dilemma (T>R>P>S) in the sim. 0.3% betrayal floor is from Diplomacy, not OGame.
- T1 done: ogrl/pbt_es.py (PBT, shared seeds/noise, exploit at paired t ≥ 1, ×0.8/1.25 explore, cross-fit SNR adaptive
  sigma). Verified myself: 1-member no-adapt vs es_world 1 gen → max|Δθ| 0.0 (weights moved 0.003 mean, not a no-op);
  smoke2 shows 2 exploit events. No HDS gate inside → judged afterwards by paired score + HDS on seeds 1-10.
  Slice `runs/pbt-c4/slice.sh` (3 members, 6 seeds, 39 thr, 4 gens) queued to start when rl-es-c4 ends (CPU).
- T2 done: rl/scripts/replicator.py + research/ogame/REPLICATOR-PERSONAS.md. Verified myself (spy-a, 10 seeds, 5
  lowest/highest active accounts at day 256): bottom = 39 raider / 8 saver / 3 miner; top = 31 miner / 19 saver /
  0 raider. Findings: (1) persona-vs-persona flows are tiny (loot 0.1-3% of production), so no mixed ESS: each phase's
  rest point is one persona; (2) season expedition income 2.4-3.1x mine production for every persona (log, both runs)
  — swamps raiding; (3) raiding is a losing strategy in the sim, while real military-heavy players are 61-66% of
  top10. Largest gap found by T2: expedition income share and raider economics. Next (no CPU): research agent on the
  real expedition income share (our data first, then web) before any sim change.
- T3 done: per-account policy pool (`errand-pool=A,B,teacher errand-split=LO-HI:K`; src/season.rs, examples/season.rs,
  src/python.rs; ext rebuilt; binary runs/pool/season.bin). Verified myself: md5 of base-1 (pre-change binary) ==
  new-1 (pool form) for ready + battles; mixed-1 == mixedperm-1 (pool order swapped) → groups really get their policy.
  Wall +2.5% (8 runs each, noisy machine; within ±3%). Mixed tallies: C4 raids 3073 vs g14 1572 vs teacher 1536.
- T4 started (agent, ≤ 20 threads): payoff matrix teacher/g14/C4/g20, split-swapped pairs, seeds 1-5, Nash avg +
  alpha-Rank → research/ogame/PAYOFF-MATRIX.md.
- es-late-c4 slice: training mean_ln 18.625 → 18.666 → 18.689 (gens 1-3, ~285 s/gen, slower than C3's ~190 s);
  its 1500 s timeout ends before the gen-4 gate → judged by `es-late-c4/confirm/run.sh` instead (start gate-0 vs
  latest.pt, seeds 1-10, HDS + paired score). Order on CPU: confirm, then PBT slice (both chained, unit names
  rl-c4-confirm, rl-pbt-c4). T4 payoff pilot running (teacher vs g20, seed 1).
- Expedition research done: research/ogame/EXPEDITION-VS-RAID-INCOME.md. Verified myself in code: host
  ExpeditionMission::determineMaxResourceFind (highscore max × Discoverer eco×1.5 × Pathfinder ×2) then × variant
  1 / 2-3 / 5-10 (89/10/1%) — the sim copies it (ogsim/src/expedition.rs resources_found). Real (wiki/forum): factor
  10-50 / 50-100 / 100-200 of the points cap, i.e. the max is the 1% huge find → host/sim ≈ 4.1× too generous per
  find. Plus `class=3` makes every persona a Discoverer. Raid income real 0.5-2× own production vs sim 2-4%.
  This is a HOST game-accuracy bug (AGENTS.md); host change needs owner approval. First a sim-only arm bench (agent):
  `realexpo` (real factor draw) and `realexpo` + persona classes; go: raiders leave the bottom (persona of 5
  lowest/highest), top10+mid band ≥ spy-a − 0.01, HDS not down, wall ≤ +3%.
- es-late-c4 slice confirmed (confirm/, seeds 1-10, gen 4 vs C4 start): paired +0.109, 10/10, CI [0.078, 0.147];
  top10+mid 0.837 → 0.833 (−0.004, inside 0.01); HDS 0.823 → 0.818; wall ≤ 321 s/season. GO for the ≤ 1 h step.
  CPU is full now (PBT slice 39 thr + payoff ≤ 20 + realexpo bench ≤ 20), so the hour runs after the PBT slice, as
  plain ES or as PBT depending on which wins the same seeds-1-10 check. Caveat: if `realexpo` is adopted the world
  changes → re-clone (C5) before replacing g14; the ES hour is then a method check, not a deploy candidate.
- 2Moons cross-check added to EXPEDITION-VS-RAID-INCOME.md: factor × fleet points (classes ARE the factor), but a
  max(200, factor) bug makes every find huge. Not a numbers reference; confirms the structure.
- T4 done: research/ogame/PAYOFF-MATRIX.md, rl/scripts/payoff.py (80 seasons, 29 min, ≤ 20 thr). Verified myself:
  teacher vs g20 from out/*levels.json = −0.123 (agent −0.124). Order g20 ≥ g14 > c4 ≈ teacher; g20 vs g14 −0.008
  CI [−0.056, 0.028] (same as confirm2); no cycles; Nash = pure g20; seating effect ±1.5 ln per seed → splits must
  be swapped. Meaning: ES gains are transitive (not over-fitted to the teacher), so the league (T5) is NOT the
  largest gap now; keep it behind the expedition fix. g14 stays deployed (g20 not significantly better).
- realexpo bench done (agent; arms `realexpo`, `personaclass`, off by default, md5-identical when off). Verified
  myself from hds.txt: spy-a HDS 0.826 band 0.841; expo-a (realexpo) HDS 0.877 band 0.805; expo-b (+personaclass)
  HDS 0.793 band 0.767. expo-a: expedition/mines 2.4-3.0 → 0.8-1.0 (real ≈ 1.1), but top10 128-256 median 68k → 21k
  (real 99k), growth cell 0.98 → 0.00, planets 0.99 → 0.10; raiders still bottom; wall +16% (unexplained).
  NO-GO for both. Finding: the 4× expedition excess was HIDING a missing top10 income source (two errors cancel).
  Host change stays parked. Next (research, no CPU): decompose real top10 growth at 64-256 d (mines/planets,
  expeditions with fleet-points formula and slots, raids, lifeforms?) from our data first, then web.
- Owner (5 Nov): OGameX will NEVER support lifeforms. If real-bands.json has lifeform universes, correct the reference (non-lifeform split), never add lifeforms.
- PBT slice (pbt-c4): timed out in gen 3 (384 s/gen under load, not 240). Gens 1-2: members within ±0.012, exploit
  never triggered (t 0.94 < 1.0) → no evidence hyperparameters are the limit; 3× the CPU of plain ES. PARKED.
- Owner (5 Nov): focus on training; "PvE": best model against 10 strong players. Started `pve-g20/run.sh` (unit
  rl-pve-g20): ES from g20 (best by T4 Nash), accounts 21-30 fly frozen g20 via the pool, rest teacher; 4 gens,
  gate-4 vs gate-0 on top10+mid. Then confirm in BOTH worlds (PvE and standard) vs g20 start, seeds 1-10, plus
  behaviour: hits taken by model accounts, fleet lost, fleet saves, counter-raids.
- PvE gen 0 gate: HDS 0.815, band 0.834; gen 1 vote_sd 0.077 (C4 slice 0.27). Spare CPU used: g20 baseline sides for the confirmation (pve-g20/confirm/side.sh: start-pve, start-std, seeds 1-10).
- Top10 growth research done: research/ogame/TOP10-GROWTH-SOURCES.md. All 267 reference universes have lifeforms
  (no clean split); real_bands.py already strips lifeform points (median 12% of top10); lifeform bonuses inside
  the other scores est. ×1.3-1.6 → reference should divide growth by ~1.4 (provisional). Even then real top10 ≈ 3×
  sim. Verified myself: sim top10 (expo-a s1) 10-11 planets, astro 19, ~10k solar sats, 0 crawlers; crawlers are 0
  for all personas under class=3 (spy-a) and 253k for Collector miners under personaclass (expo-b) → crawlers come
  only with the Collector class. Missing sources ranked: (1) mine production 2.5-4× low (class/crawlers/planets),
  (2) expedition COUNT (~28/day vs 45-56), (3) raids. Backlog (sim realism, after the training step): hds.py
  lifeform factor; production arm; expedition waves. Owner's priority now: training (PvE run).
- PvE slice (pve-g20, 4 gens) result: gate-4 HDS unreadable — hds.py health BROKEN on `clock_ahead` (seed 4 day 252,
  slot 52, planet clock 28 s ahead of world time); the same flag also hit the g20 standard-world baseline
  (confirm/start-std seed 1, day 159, slot 47). The ES exited 3 on the NaN gate (a NaN gate counts as a drop).
  Paired score from finals (my calc): PvE gate-4 vs gate-0 +0.050, 6/10, CI [−0.005, 0.110] → INCONCLUSIVE (C4
  slice was +0.109, 10/10). vote_sd 0.08-0.09 vs 0.27 on C4: g20 among g20 opponents gives a weaker ES signal.
  Checking whether clock_ahead is old: start-std seed 1 rerun on the pre-pool binary (bc-late-c4/season.bin).
- clock_ahead is PRE-EXISTING: start-std seed 1 on the pre-pool binary (bc-late-c4/season.bin) is byte-identical to
  the pool binary's output, same flag (day 159, slot 47). Agent fixing the root cause in rl/ogsim (builds in
  runs/clockfix/). Meanwhile the ≤ 1 h ES on C4 started (`es-late-c4/run1h.sh`, gens 5-16, unit rl-es-c4-1h), no
  in-run gate (would read NaN); judged after by paired score + HDS on seeds 1-10 with the fixed binary.
- PvE: inconclusive slice (+0.050, CI crosses 0) and 3× weaker vote signal → not extended. Owner's PvE idea kept for
  a variant with a weaker-than-self or mixed opponent group (pool: teacher raiders + g20) after the 1 h result.
- PvE-mix slice started (pve-mix/run.sh, 5 frozen g20 at 21-25, 24 thr, 4 gens, no gate) next to the 1 h run (gen 8: mean_ln 18.789, rising).
- Owner (5 Nov): do NOT change the host's expedition code; keep it as it is. Sim mirrors the host → `realexpo` stays off everywhere; host change proposal dropped.
- Owner idea (5 Nov): a big world (1000 accounts of mixed strength fighting). Timing, 30 days, seed 1, pool binary
  (runs/big/): 64 acc 35 s; 256 acc 158 s (4.5×); 1000 acc only day 21 in 531 s (≈ 22× per day). Full 256-day
  season ≈ 5 min (64) / ≈ 23 min (256) / ≈ 2 h+ (1000). Plan: 1000 = occasional overnight tournament only; 256 =
  candidate "arena" (our versions + teacher + many quitters as farm targets) for evaluation; training stays at 64.
- PvE-mix slice done (4 gens). Confirmation running (pve-g20/confirm: mixfinal-std, mixfinal-mix, start-mix; start-std exists) → paired from finals. 1 h ES on C4 at gen 15/16.
- PvE-mix confirmation (seeds 1-10, paired from finals vs g20 start): in its own world +0.030, 7/10, CI [−0.016,
  0.076]; in the standard world −0.001, 5/10, CI [−0.037, 0.036]. With PvE-10 (+0.050, CI crosses 0): NO-GO,
  PvE direction stopped. Likely cause: g20 is already at the ES plateau (g20 ≈ g14), so a new opponent mix does not
  give it new signal; opponents attacked model accounts 137×/season vs 813× model-on-model.
- 1 h ES on C4 done (gen 16, mean_ln 18.786). Confirmation running: es-late-c4/confirm/run16.sh (final16 vs the
  existing C4 start side, seeds 1-10).
- Profile (symbolised build of the current tree, seed 2, C4 world, 64 acc, 196 s; perf + addr2line): flat. Top:
  Universe::observe 7.2%, HashMap<usize,(f64,i64)> rehash 5.7%, Planet::clone 4.0%, BTreeMap collect/sort 4.6%,
  Catalogue::refresh 3.2%, World drop 2.7%, energy_unit 3.3%; libc (malloc/memcpy) 13%. The errand MLP is not in
  the top → GPU inference would NOT speed the season; the cost is allocation/cloning bookkeeping. errands=4:
  World::wreck_lands 19.3% (the hotspot behind +80% wall). Next (agent after the clock fix, same files): fix
  wreck_lands, cut clones/rehash/alloc (pre-sized maps, reuse buffers, faster allocator), each step md5-identical.
- Clock fix done (agent): root cause = lazy planet clocks re-reading next events from a stale second → world time
  stepped back (src/world.rs:434, 539-548 new `clock` + catch_up; src/economy.rs:880, 944, 948 horizon.max(t)).
  Verified myself in runs/clockfix/out: seed 1 old 2 flags → new 0; PvE seed 4 old 1 → new 0; seed 2 17-day md5
  identical; full seed 2 differs at 34 silent back-steps (fix changes those results, as it should); wall +0.3%.
  New reference binary runs/clockfix/season.bin. Python ext rebuilt. Speed-up agent started on rl/ogsim (rule:
  md5-identical to clockfix binary per step, wall table).
- confirm16 first run INVALID (my sed missed the season's errand-policy path → it replayed gen 4, byte-identical to final/). Fixed run16.sh, rerunning (rl-c4-confirm16b).
- Owner (5 Nov): optimise hard ("every second"), then pick the most effective training direction and continue under
  the agreed rules. Direction chosen: train the ECONOMY head (build model). Evidence: research shows the largest
  real-vs-sim gap is production (planets, production units), the build model (train4 best-es3-s10) was never
  trained in the late world, and the fleet head is at its ES plateau (g20 ≈ g14, PvE no-go). Started
  `eco-es/slice.sh` (rl-eco-es): ES on the build model, errand fixed g14 for 0-20, clock-fixed binary, 4 gens, gate-4.
  Then alternate heads (economy ↔ fleet), each new version scored in the payoff matrix.
- 1 h ES on C4 confirmed (corrected run, seeds 1-10, old binary like `start`): gen 16 vs C4 start +0.173, 10/10,
  CI [0.125, 0.220]; vs gen 4 +0.064, 8/10, CI [0.016, 0.111] → the hour kept improving. top10+mid 0.837 → 0.834
  (−0.003); HDS 0.823 → 0.812. Deploy question: g16-C4 vs g14 head-to-head on the same seeds running (rung14.sh).
- DEPLOY DECISION: C4-ES gen 16 vs g14 head-to-head (same seeds 1-10, same binary/arms): +0.113, 9/10, CI [0.065,
  0.158]; top10+mid 0.8342 vs 0.8367 (−0.0025, inside 0.01); HDS 0.812 vs 0.820; same feature version (v3) and
  column names; anomaly report: no new major anomaly (contested attacks under real odds 11/100 → 5/100; only a
  1/100 Plasma Turret line swapped in at 0.34). → NEW DEPLOYED FLEET MODEL:
  storage/rl/ogsim/runs/es-late-c4/final-g16.policy.json (+ final-g16.pt). Deploy via AI_RL_ERRAND_MODEL (not set in
  .env today). g14 kept as fallback. Next ES starts from g16-C4.
- Fleet ES hour 2 started (es-late-c4/run2h.sh, gens 17-28, clock-fixed binary, 24 thr, gate every 4 on top10+mid; gate-0 of this resume = gen-16 weights? NO: es_world's gate-0 file exists from the first slice → drop is read vs that (C4 start, band 0.837)).
- Economy ES slice (eco-es, 4 gens) → NO-GO. gate-4 vs gate-0 (seeds 1-10, finals): all accounts −0.025, 4/10, CI
  [−0.058, 0.006]; model accounts 0-20 (g14 fleet) −0.077, 1/10, CI [−0.114, −0.040]; teacher accounts +0.001.
  Band 0.8373 → 0.8371, HDS 0.821 → 0.817. Training mean_ln flat (18.725 → 18.729). Reading: small ES nudges of the
  build model don't reach the measured economy gap (crawlers come only with the Collector class; class=3 forces
  Discoverer for all; planets capped by astro) and the fleet head was trained against the old builds. Direction
  stopped; the economy gap needs a teacher/world-rule change (class choice) + re-clone, not ES on the build model.
- Fleet ES hour 2 running (gen 19, mean_ln 18.78-18.81).
- personaclass-alone bench started (late/pc: base vs +personaclass, spy-a world, clock-fixed binary, seeds 1-10). Fleet hour-2 gate-20: band 0.8348 (−0.002), HDS 0.817.
- personaclass-alone bench (late/pc, clock-fixed binary, seeds 1-10, my calc): HDS 0.825 → 0.868; top10+mid band
  0.840 → 0.858; bottom 128-256 median 28.0k → 2.1k (real 157; planet cell 0.19 → 0.89); top10 128-256 growth
  0.97 → 0.91; crawlers 0 → 961k; bottom-5 raiders 37 → 27 (miners 4 → 23), top-5 all savers (Discoverer);
  wall 346 → 325 s (−6%). GO → `personaclass` joins the teacher world. Consequence: re-clone C5 (teacher world
  + personaclass, clock-fixed + sped-up binary) once the speed agent has finished (it rebuilds the Python ext),
  then rollout → ES slice → hour, compared head-to-head with g16.
- Speed agent done (runs/speed/season.bin): 9 steps, each md5-identical to the clockfix binary (plain s1/s2,
  errands=4 s4); final side by side plain 223 → 118 s (1.89×), 212 → 130 s (1.64×), errands=4 376 → 147 s (2.55×).
  Its finding: my perf+addr2line profile was off by 0x1000 (the hotspot was `last_report`, not wreck_lands). Verified
  myself on an unseen seed (7, 120 d, personaclass + g16 errand): all four outputs md5-identical, 77.4 → 38.5 s
  (2.0×). Note: mimalloc dev-dependency → ogsim Cargo.toml/Cargo.lock changed (sim only). Python ext rebuilt.
- C5 collect started (bc-late-c5/collect.sh, teacher = C4 world + personaclass, sped-up ext, unit rl-bc-c5).
- errands=4 re-timed on the sped-up binary (personaclass world, seeds 3-4, side by side): 128 s → 151 s (+18%).
  Still above the +15% gate → stays OFF (behaviour was GO). Revisit if a later speed step lands.
- Fleet ES hour 2 done (gen 28; gate-28 band 0.8327 vs gate-0 0.837, −0.004). Confirmation g28 vs g16 (seeds 1-10,
  C4 world, sped-up binary) running: es-late-c4/confirm28.
- C5 BC done: top-1 99.5% (607,215 rows). Rollout roll80 running (go: HDS ≈ teacher in the personaclass world,
  pc bench 0.868).
- Hour-2 confirmation: g28 vs g16 +0.028, 8/10, CI [−0.028, 0.076] → not significant; band 0.8327 vs 0.8338. Fleet
  ES on C4 plateaus after ~16 gens (as C3 at 14). g16 stays deployed; C4 line closed.
- C5 rollout (roll80): HDS 0.865 vs teacher 0.868; band 0.852 vs 0.858 → GO. D slice on C5 started
  (es-late-c5/run.sh: 6 gens on the sped-up binary, gate-6 vs gate-0 top10+mid; unit rl-es-c5).
- Speed round 2 agent started (rl/ogsim; targets next_decision search, errands loop cost to ≤ +15%, inference; md5-identical rule; builds in runs/speed2).
- C5 slice (6 gens, ~100 s/gen on the sped-up binary): gate-6 vs gate-0 (seeds 1-10, finals) model accounts +0.026,
  7/10, CI [0.010, 0.043]; teacher accounts +0.007 (n.s.); band 0.844 → 0.846; HDS 0.857 = 0.857 → GO (smaller than
  C4's first slice). Note: the personaclass world is far more unequal (model median ln 15.4 vs 18.4 in C4 world;
  pc bench bottom 128-256 median 28k → 2k, closer to real) — absolute scores are not comparable across worlds.
  ≤ 1 h started: es-late-c5/run1h.sh (gens 7-30, gate every 6, unit rl-es-c5-1h).
- C5 hour: gate-12 band 0.850 (gate-0 0.844). Spare CPU: g16 side in the C5 world (es-late-c5/confirm/side.sh g16) for the head-to-head.
- Interim, C5 world (seeds 1-10, finals, model accounts): g16 side HDS 0.862, band 0.851. C5 line vs g16: gate-0
  −0.071 (2/10, CI < 0), gate-12 −0.062, gate-18 −0.031 (CI [−0.073, 0.007]) → the C5 clone line is still BELOW the
  deployed g16 in the new world. Better start: ES from g16 in the C5 world. Started es-g16-c5/run.sh (6 gens, 24 thr,
  anchor to g16, gate-6) in parallel with the C5 hour.
- C5 hour gate-24: vs g16 (C5 world) −0.019, 5/10, CI [−0.074, 0.027]; band 0.8525. Sanity: es-g16-c5 gate-0 == g16 side on all 10 seeds (diff 0.0) → gates and side.sh play the identical world. g16-in-C5 slice at gen 5.
- C5 hour final (gate-30) vs g16 in the C5 world: −0.030, 2/10, CI [−0.058, 0.001]; band 0.848. ES-from-g16 in C5
  world slice (gate-6): −0.002, 5/10, CI [−0.035, 0.029]; its gate-6 HDS NaN: clock_ahead STILL fires on the fixed
  binary (seed 5, day 135, slots 38/486/55/80, planets ~420 s past world time) → sent to the speed-2 agent (owns
  ogsim). Verdict: g16 stays deployed; both runs NO-GO. The fleet head is at an ES plateau in both worlds.
- Plateau test (cheap, ≤ 15 min): ES from g16 in the C5 world with 16 seed pairs/gen instead of 8 (halves vote noise;
  sim is 2× faster so a gen costs the same wall) — es-g16-c5s16/run.sh. Go: gate-4 vs g16 CI > 0. If no-go, the
  ceiling is the teacher/menu, not the optimiser → next lever = teacher behaviour (errands=4 once ≤ +15% wall).
- Plateau test NO-GO: 16 seed pairs/gen, gate-4 vs g16 (C5 world) −0.018, 4/10, CI [−0.048, 0.012]; band 0.851,
  HDS 0.861. Optimiser noise is not the ceiling → the teacher/menu is. Next lever: teacher behaviour. Bench err-c
  (errands=4 in the C5 world, sped-up binary, seeds 1-10) running for behaviour + HDS; adoption waits on the
  speed-2 agent's errands-cost result (gate +15% wall).
- err-c (errands=4, C5 world, sped-up binary, seeds 1-10) vs pc/pc (errands=1, same world): raider raids/day 3.56 →
  13.19 (3.7×), loot 18.5B → 20.0B (season, all raiders: yield per raid falls — few worthwhile targets), HDS 0.868
  → 0.870, top10+mid 0.8579 = 0.8579, bottom-5 raiders 27 → 21. Behaviour GO again; wall gate waits on speed-2.
  Raid yield (not count) is the remaining raider gap (research: real farmers 0.5-2× own production).
- Machine restarted ~21:35 with the speed-2 agent unreported (last build s12, 16:55). Verified myself: the tree
  builds byte-identical to runs/speed2/s12.bin (= speed2/season.bin); 117 lib tests pass. Second clock fix (s10 on):
  the clock_ahead repro (es-g16-c5 gate-6 world, seed 5) old clockfix binary 2 flags → s12 0 (seeds 1, 2, 4 also
  PASS); outputs differ from the clockfix binary from s10 (intended: a behaviour fix), md5-identical s10 → s12.
  Wall: that repro 160 s → 61 s. NEW REFERENCE BINARY: runs/speed2/season.bin.
- errands wall gate on s12 (teacher, C5 world, seeds 1-10 side by side): errands=1 755 s total, errands=4 804 s
  (+6.5%; worst seed +18%) → PASSES the +15% gate. With err-c's behaviour GO: `errands=4` joins the teacher world.
- FOUND: `SeasonWorld` (python.rs) ran `errands=N` only for pool pilots; controlled accounts (the BC/ES rows) still
  took one errand a login. Fixed (`Seat::answer`: up to per_login errands until the wait row, each a decision of its
  own: close, ticket, teacher label). errands=1 path unchanged. Smoke (4 worlds × 40 d, teacher answers): decisions
  50.8k → 92.4k, non-wait 16.1k → 38.8k. Python ext rebuilt.
- Started: `late/err-d` (teacher reference on s12, C5 world + errands=4, seeds 1-10; unit rl-err-d) and C6 collect
  `bc-late-c6` (teacher = C5 world + errands=4, 60 epochs, cuda:1; unit rl-bc-c6). Then: C6 rollout (go: HDS ≈ err-d),
  D slice from C6, head-to-head vs g16 in the same world (g16 was trained one errand a login).
- err-d (teacher, C5 world + errands=4, s12 binary, seeds 1-10): HDS 0.869, health PASS; wall 87-112 s per seed
  (10 at once). This is the C6 rollout's reference.
- Spare CPU used (the machine sat at load ~9/72 during C6 collect): `err4-side/side.sh g16` (deployed g16 in the
  err-d world, seeds 1-10; unit rl-side-g16-e4) and `es-g16-e4` (ES from g16 in the err-d world, 6 gens, gate-6 on
  top10+mid, 24 procs; unit rl-es-g16-e4) — a parallel line to C6; the head-to-head picks between them.
- C6 collect+BC done: 1,260,850 rows (C5 607k: errands=4 doubles the decisions), 60 epochs top-1 99.4% (452 s). Rollout bc-late-c6/roll80 running (unit rl-roll-c6; go: HDS ≈ err-d 0.869).
- C6 rollout (roll80, clone flies all 64): HDS 0.871 vs err-d 0.869, health PASS → GO as a clone.
- Head-to-head in the err-d world (`err4-side/side.sh`, fleet-model=21, seeds 1-10; `scripts/paired_sides.py`):
  g16 HDS 0.860, C6 0.857; C6 − g16 model accounts −0.013 ln, 3/10, CI [−0.065, +0.042] → no difference; g16 stays.
  A raw clone ties the ES-trained g16: more evidence the fleet head sits at the teacher's ceiling.
- Owner (5 Nov, evening): apply the advisor plan, use the whole machine. Order: (1) close C6 vs g16 (done: g16);
  (2) errand ExIt (look-ahead labels, never trained for errands; build ExIt lost the late game 3-4 Nov);
  (3) PHP late-game run with g16 (agent); (4) held-out seeds 11-30 for final calls; (5) sim economy fidelity later.
- Errand ExIt code: `champion-errands-only` (builds stay the policy's) and the champion errand path now loops
  `errands=N` (examples/season.rs; off = unchanged). Binary runs/exit-e4/season.bin. Tools: scripts/errand_labels.py
  (spreads, kinds, a model's regret), scripts/errand_noise.py (2 vs 4 rollouts on the same decisions → noise floor).
  First 87 labels: top-quartile row gaps 1e-4..2e-3 of worth → train_exit's tau 0.01 would be flat; g16 agrees 33%,
  mostly phalanx where the look-ahead spies or waits. Pilot (seeds 101-102, d 120-160) + noise run (seed 101, r=4).
- Errand ExIt pilot (seeds 101-102, days 120-160, 8 champion seats, 1 in 4, 12 h × 2 rollouts, full world): 1,963
  labels (1,427 with ≥ 2 rows) in 436-571 s. Gaps best−second p75 1.4e-4, p90 3.5e-4 of worth; look-ahead picks
  wait 488, expedition 362, spy 330, deploy 124, raid 117; g16 agrees 27%, regret 2.9e-4 (phalanx → spy/wait most).
- NOISE FLOOR (errand_noise.py, same 430 decisions — prefix verified identical — rollouts 1-2 vs independent 3-4):
  halves agree on the best row 50.9%; even at half-A gaps ≥ 1e-3 only 43.5%; row-wise |A−B| p50 1.8e-4, p90 8.7e-4
  = the size of the gaps. With 2 rollouts the errand labels are mostly noise; g16's "regret" is inside it. NO-GO for
  training on these labels. One measured hypothesis tested (not a fix chain): the noise is the other accounts' play
  → same test with `champion-solo` (solo2/solo4, seed 101, to day 140). Still noisy → errand ExIt stops.
- g16 held-out baseline started: err-d world, seeds 11-30 (`err4-side/g16-held`) for final calls from now on.
- Solo noise test (champion-solo, seed 101 to day 140, 623 identical decisions): halves agree 51.5%, row-wise |A−B|
  p50 1.8e-4, p90 8.8e-4 — unchanged. The noise is the account's OWN dice (expedition finds, battles; a different
  errand reorders its draws), not the others' play. Errand ExIt STOPPED (labels ≈ coin flips at 2 rollouts; ~80
  rollouts would be needed to resolve 1e-4 gaps). Kept: arms `champion-errands-only` + the errands=N champion loop,
  scripts errand_labels.py / errand_noise.py. If revisited: per-event common random numbers (each expedition/battle
  draws from a stream keyed by its own id, so rows share dice) — a sim change to measure first, not now.
- ES from g16 in the err-d world, slice (es-g16-e4, 6 gens, 24 procs): gate-6 vs gate-0 (seeds 1-10, paired_sides):
  model accounts +0.054 ln, 7/10, CI [+0.018, +0.091]; other accounts −0.022, 2/10, CI [−0.039, −0.004] (part of
  the gain is taken from the others — watch); HDS 0.860 → 0.858, band 0.849 → 0.846. GO → (a) held-out check:
  gate-6 on seeds 11-30 (`err4-side/g6-held`) vs g16 (`g16-held`); (b) ≤ 1 h resume `es-g16-e4/run1h.sh` (gens 7-30,
  gate every 6, 32 procs; unit rl-es-g16-e4-1h).
- HELD-OUT (seeds 11-30, err-d world): es-g16-e4 gate-6 vs g16: model accounts +0.014 ln, 13/20, CI [−0.005, +0.031]
  (seeds 1-10 said +0.054, CI [0.018, 0.091]); others +0.003 (the −0.022 on 1-10 does not repeat). NOT confirmed:
  most of the judge-seed gain was fit to seeds 1-10, which every gate and confirmation has reused. RULE from now: a
  model is adopted only on held-out seeds 11-30 (CI > 0); seeds 1-10 stay the in-run gate only. The 1 h resume
  continues; its final is judged on 11-30.
- Held-out vs the TEACHER (err-d world, seeds 11-30, fleet-model=21): g16 − teacher +0.018 ln, 14/20, CI [−0.041,
  +0.075]; gate-6 − teacher +0.031, 13/20, CI [−0.026, +0.087]. HDS held-out: g16 0.864, gate-6 0.866.
  CORRECTION to the line above: es_world trains on fresh seeds 1000+ (never 1-10); 1-10 is gate-only. So the 1-10 vs
  11-30 gap is winner's curse (the best gate is picked on 1-10) + n=10 noise, not training on judge seeds. And in the
  errands=4 world the teacher is stronger (raids/spies ×3-4) while g16 was trained one errand a login (old world:
  g20 beat the teacher by 0.12, T4). Per-seed spread: a 20-seed CI is ±0.06 → final calls use seeds 11-50 (40).
- 40 held-out seeds (11-50), err-d world: g16 − teacher +0.040 ln, 28/40, CI [+0.001, +0.083]. g16 IS better than
  the teacher here, by ~4% (not the ~20% the 1-10 gates suggested).
- Owner (5 Nov): "we are standing still, search the web for solutions". Found: common random numbers per event
  (Veness et al., NeurIPS 2011, Variance Reduction in MCTS: CRN/control/antithetic variates on stochastic games;
  RLJ 2026 "Using CRN for simulation-based planning with rollouts"); AIVAT (Burch et al., AAAI 2018) for evaluation
  with a value baseline (10-44× fewer games in poker); Stochastic MuZero (afterstates + chance nodes).
- Cause of the look-ahead noise, measured in code: every fleet event of an account (expedition, raid, spy, even a
  return home) takes the NEXT draw of one keyed stream (`World::draw(owner, 1)`), so one extra errand shifts every
  later expedition's and battle's luck. Arm `eventdice` (src/world.rs `event_dice`, with `keyed`): a stream per
  mission kind and one for returns. Off = md5-identical (60-day season, seed 3); 117 lib tests pass. Binary
  runs/crn/season.bin. Test: the same noise-floor check (seed 101, r2 vs r4, to day 140) with `eventdice`.
- `eventdice` noise test: halves agree 51% → 74.7%, row-wise noise median 1.8e-4 → 5.6e-7 (300×), p90 8.8e-4 →
  4.0e-4. Most of the look-ahead noise was the shared dice stream. Research notes + Kaggle winners' writeups (fetched
  with Playwright): research/ogame/COMMUNITY-SOLUTIONS-PLATEAU.md. Next measured step: does `eventdice` narrow the
  policy comparison? g16 vs teacher on seeds 11-30 in the eventdice world (`err4-side/side-crn.sh`, crn-teacher /
  crn-g16), compared with the same pair without it (CI ±0.06).
- PHP late-game run with g16 (agent; files storage/rl/php-errand/, untracked; NOT yet verified by me): day-256
  import (late/cap-e seed 1, 12 accounts, savers/miners only), AI_RL_ERRAND_MODEL=g16 via container env. Model path:
  0 exceptions in 26 logins; picks became real missions, processed by ProcessFleetArrival without errors; no leak
  seen. Problems: (1) BLOCKER, pre-existing (same with the model off): a late login hangs in RaidPlanner::plan /
  the native battle forecast on huge fleets (32k bombers, 20k reapers) — no PHP stack trace (stuck in FFI); fix idea:
  cap units in the raid estimator as the encoder's forecast does (~200 units). (2) SUSPICIOUS: the model picked
  expedition 26/26 (engine: FleetSave 7, QueueUnits 4, Build 2, Recycle 1); score gap −27 vs −42..−57 → check the
  PHP expedition row vs the sim's (PHP fleet = one hull per role). (3) Encoder 160-340 ms per decision (99% of it;
  planners called twice); inference 2 ms. (4) No raid rows: the test state has no raiders.
- eventdice does NOT narrow season-level comparisons: g16 − teacher on seeds 11-30 with eventdice +0.005, CI [−0.052,
  +0.061] (±0.057) vs without ±0.058. Two policies diverge the whole 64-account world over 256 days; CRN helps the
  12 h look-ahead, not 256-day A/B. Season A/B = more seeds (cheap: ~2 min a season).

## 5 Nov night — PLAN after the research (owner: make a plan from what was gathered, run it, use the machine)

Evidence: g16 ≈ teacher +4% (40 held-out seeds); ES on the student plateaus; Kaggle rule-bot winners (Kore, Lux S2,
Halite) improved the RULE BOT — loss review, many versions each beating the last 1v1, joint parameter tuning last;
"IL caps at the rule bot". (research/ogame/COMMUNITY-SOLUTIONS-PLATEAU.md)
1. Evaluation protocol: seeds 1-10 = in-run dev gates only; 11-60 = promotion (paired, 50 seeds); 61-100 =
   quarantine, one shot per finalist. World for A/B: err-d (no eventdice). eventdice only inside look-ahead.
2. Teacher first. 2a loss review from the 40 held-out teacher seasons (where value leaks per persona: battles lost,
   raids received, idle stock, expedition losses, fuel). 2b `params=FILE` override for the personas' hand-set
   constants (survey running), then black-box tuning (ES/CMA over ~20-40 named constants; fitness = paired ln score
   of tuned vs untuned accounts in one world, splits swapped), promotion on 11-60, quarantine 61-100.
3. Student on the better teacher: re-clone + ES from g16; errand ExIt with eventdice r=4 on high-stakes decisions only.
4. PHP: late hang fix + expedition-26/26 check (agent), then a multi-round PHP late run.
- Step 2a loss review (`scripts/leaks.py`, 40 held-out teacher seasons, 1,280 active accounts; per-day fields summed):
  store held at season end = miners 24-30 days of production, raiders 22-25, savers 102-121 (real TP-022: ~1 day);
  in every persona the top quarter holds LESS. Raider loot 1.3-1.6% of own production (real 50-200%); weak raiders
  spend 24% of production on units vs 5% for the top. Top miners build more (66% vs 58%) and research less.
- Step 2b: `src/tuning.rs` — 13 unsourced persona numbers (SURPLUS_DAYS 60, PAYS, SAFE_SHARE, RAID_COOLDOWN,
  MISSILE_SALVO, EXPOSED_HOURS, overflow 0.8, keep 2 h, raider military share 0.30, cargo shares, raider fighters)
  per account via `params=FILE` / `params-es=NOISE:SIGN:SIGMA`; sourced constants stay fixed. No arm, `{}` and an
  explicit-defaults file: md5-identical to the s12 binary (80 d, seed 5); 118 lib tests pass. Survey of all ~55
  constants: agent report (this session). `ogrl/teacher_es.py`: antithetic ES, per-account nudges, persona numbers
  voted by their persona only, centred ranks, normalised step. Started `runs/tune/es` (8 gens × 16 seeds × 2,
  err-d world; unit rl-teacher-es). Note: in the err-d world the errand teacher flies every account's fleet, so the
  raider's own raid numbers may be inert; ES will show which numbers move.
- PHP late hang FIXED (agent; verified: RaidDepthTest + WideErrandEncoder/Situation/Seam tests 57 passed in the
  container): cause = NativeRaidEstimator fought the whole stock (~1.36M ships, up to 7M-unit targets) 50× per target
  in the native engine. `NativeRaidEstimator::shrink()` (200-unit cap, shared with WideErrandEncoder) + whole-fleet
  loot asked once. Late PHP run after fix (php-errand/fix1): 6 rounds, 72 logins, 0 errors, login median 4.3 s; picks
  Raid 24, Expedition 22, FleetSave 13, … Expedition 26/26 earlier = g16's own preference (fixtures: sim g16 also
  picks escorted expedition +20..+40), not a PHP encoding bug. Open: encoder cost (logins 4-13 s); WarTest flaky?
- Held-out: ES-1h final (es-g16-e4 gate-30) vs g16, seeds 11-30: −0.007, 7/20, CI [−0.046, +0.034] → no gain; the
  student-ES line is closed (g16 stays). Teacher tuning gen 4 (`tune/judge/g04`, accounts 0-20 tuned, seeds 11-35)
  vs teacher: +0.015, 14/25, CI [−0.005, +0.035]; others −0.005. Promising, not confirmed; judge gen 8 on 11-60.
- PROMOTION PASS: teacher tuning gen 8 (`tune/es/params-g08.json`: raider military share 0.30 → 0.12, overflow 0.80 →
  0.65, cargo shares, safe_share 0.285, pays 3.28, …), accounts 0-20 tuned vs teacher, seeds 11-60: +0.022 ln,
  32/50, CI [+0.003, +0.041]; others −0.002 (not taken from them). Quarantine 61-100 still to run once.
- Economy model A/B (held-out 11-40, all 64 accounts, builds by best-es3 vs by the personas): +0.224 ln (≈ +25%),
  30/30, CI [+0.197, +0.252]. The economy model is far above the persona builds (the earlier "+4%" was the late bench).
- Big world (owner, night): `runs/big1000/run.sh SEED [DAYS]`, 1000 accounts, pilots g16 / g14 / C6 / gate-30 /
  teacher-tuned / teacher / personas. 10 days = 90 s, 780 MB.
- QUARANTINE (seeds 61-80, once): tuned g08 vs teacher +0.005, 11/20, CI [−0.025, +0.034]. With promotion (+0.022,
  50 seeds) the tuning gain is real but small (~1-2%). Continuing teacher ES to gen 16 (same run, resumes).
- Teacher tuning gen 16 (params-g16: overflow 0.50, raider military share 0.09, safe_share 0.35, …) vs teacher, seeds 11-60: +0.030 ln, 32/50, CI [+0.014, +0.047]; others +0.002. Quarantine 81-100 running.
- QUARANTINE 81-100: tuned g16 vs teacher +0.013, 14/20, CI [−0.011, +0.035]. Promotion +0.030 → pooled ~+2%: real, small (winner's curse again). Next: search-as-player test (look-ahead plays 8 seats' errands from day 120, eventdice) vs g16, and HDS of an all-tuned world.
- Natural selection (owner, 6 Nov): ogrl/evolve.py — genomes = 13 tuning numbers per account, niches = personas, shared 64-account worlds, keep top 25% per (world, niche), mutation 0.08 log; smoke OK (persona mismatch 0). Main run: runs/evolve/main, 5 worlds/gen on 5 cores (AllowedCPUs 0-4), 12 gens, unit rl-evolve.
- Search-as-player (champion-errands-only + eventdice, 8 seats, days 200-256, seeds 11-20): only 3/10 seasons finished under load (timeouts); champion seats vs g16 +0.002 ln, CI [−0.008, +0.009]. No late headroom from errand look-ahead (as for builds on 4 Nov); ~10× slower. STOPPED. paired_sides now skips unfinished seasons.
- Evolution gen 7 niche means (accounts 0-20 by persona) vs teacher, seeds 11-40: +0.013 ln, 16/30, CI [−0.012, +0.039]; others −0.006. Same size as teacher-ES; waiting for gen 12.
- Realism: all-64-tuned world (params-g16, teacher flying, seeds 11-30) HDS 0.861 = teacher 0.861, health PASS → tuned numbers are safe to adopt as the teacher default.
- Evolution gen 12 (niche means: raider military share 0.40 ↑, miner surplus_days 45 ↓, saver keep 1.4 h ↓; log-sd
  0.1-0.4 kept) vs teacher, seeds 11-60: +0.001 ln, 30/50, CI [−0.016, +0.018]. NO GAIN as a stronger teacher: the
  niche-relative optimum (scored against co-evolving peers) is not better against a default world, and it moved the
  raider share OPPOSITE to teacher-ES (0.09). Verdict: stop as a strength method; keep evolve.py as a generator of
  diverse opponents (league / big world), where its diversity is the point.
- 6 Nov morning: economy-model ES in the err-d world started (eco-e4, 16 pairs, 48 procs, 8 gens, from best-es3-s10, fleets g16 on 0-20); judge on held-out after.
- Big world (4 seeds × 1000 accounts × 256 d, all finished): mean ln final score of active accounts by fleet pilot ×
  persona — g16 16.31/16.24/18.82 (miner/raider/saver), g14 16.32/16.32/18.75, C6 16.41/16.20/18.79, gate-30
  16.46/16.15/18.84, teacher-tuned 16.41/16.19/18.78, teacher 16.38/16.17/18.79, personas 16.27/16.28/18.75. All
  pilots within ~0.1 ln: in a big mixed world the fleet errand pilot barely matters; the economy (same model for all)
  sets the score. → focus economy: hoarding (store 22-120 days of production) and illogical builds.
- Big world, what actually happened (seed 7 unless noted): 500/1000 active at day 256 (by design: half quit,
  QUIT_MEAN_DAYS 15); alliance members 424/500 (85%) — but ONE alliance (single-alliance default; `alliances=N`
  off), so no diplomacy; levels.json `alliance` reads false for all in this world while day rows say member (writer
  bug under `standing`, to fix). Top 50 of every seed = 50/50 savers; #1 only ~10-15% above #10 (no dominant player);
  #1 pilots vary (g16, g14, personas). War: 615k raids, loot 62B vs ships lost 134B (every group loses more ships than
  it loots) — war destroys value and decides nothing; the season is an expedition race won by savers.
- Hoarding cause (teacher-held top10, 200 accounts): 99% of the store is free surplus (keep 0.26B of 26B); build
  queues busy 24 h; yard orders 9/day, never share-blocked, capacity 64B; 82% of their planets at the field cap (free
  fields median 1; terraformer median 9, nanite 6). The `cap` arm stops surplus spending above the military share,
  economy builds are field-bound → the store piles up (fed by expedition income 2.65× production for savers).
- 6 Nov: eco-e4 economy ES finished 8 gens (mean_ln 17.056→17.089 in-run, train seeds). Judging now: err4-side/eco-base vs eco-e4 (side-eco.sh, ECO env), seeds 11-40, g16 errands.
- 6 Nov: real-server profile done → plan/research/ogame/REAL-SERVER-PLAYER-PROFILE.md (267-universe API dump). Top gaps: raid loot 0.02× prod vs 0.5-2× real; no inactive farms (quitters stay tiny); probes 3-4/day vs 200-500; miners/savers raid with zero probes (CHECK: possible cheating); one alliance 84% vs largest 19-30%; no churn after d45, no vacation, no late joiners; rank1 share 10% vs 17-34%, top10% spread 1.3× vs 3.4-4.5×; store ~2000 h vs ≤24-48 h unprotected; top10% 100% Discoverer; DS holders 55% vs 94%.
- 6 Nov: PHP errand encoder sped up ~8× (median 334→42 ms) via LoginPlans per-login reuse of engine planner answers; 0/48 decision diffs; 58 tests pass (verified). Login total only −3%: real login cost = 50-sample raid battle estimates (~40 s/72 logins), QueueableBuildingPlanner::steps (~0.4 s/call), spy planner rebuilding PlayerService. Uncommitted.
- 6 Nov: eco-e4 vs best-es3 (seeds 11-40, all 64 accounts): +0.009 ln, 20/30 up, CI [-0.019, +0.035] → NO-GO, keep best-es3-s10.
- 6 Nov: cheating check of "miners/savers raid with zero probes": NOT cheating. Their Attack fights are moon shots at alliance mates (players::moon_shot): raid_sent≈moon_rolls (saver 1417/1327, miner 1213/1041), loot ~0.3-0.6M vs raiders 14B. Profile report item 3 corrected.
- 6 Nov OWNER DECISION: no farms/inactives/churn work now; target world = everyone keeps playing (new arm `churn=SHARE:MEAN` in season.rs, default 0.5:15 unchanged; `churn=0:1` = no quitters). Priority: maximum strength first. Running err4-side/noquit (churn=0:1) vs churn-base, seeds 1-10, g16 errands, speed2/churn.bin, to set the new reference world. New gauge scripts/reality.py.
- 6 Nov: hoarding → strength test: arm set without `cap` (surplus spending ungated), err4-side/nocap (default world) vs churn-base and nocap-nq vs noquit, seeds 1-10 dev.
- 6 Nov reality.py baseline: teacher-held / eco-base: raid loot ÷ prod 0.021 / 0.017 (real 0.5-2), rank-1 share 0.107 / 0.104 (real 0.17), top-10% store **106 / 105 days** of production (target 1-2).
- 6 Nov OWNER DIRECTION (overrides "balanced personas"): the world must GRIND — real spying/probes, attack planning, brutal wars until ONE player dominates (later one alliance); that world is where PvE and comeback get measured later. New arms: `personas=M:R:S` (share override), `noalliance` (nobody joins; also disables `standing`). Bench (speed2/war.bin, seeds 1-10, churn=0:1): war-all (personas=0:1:0 noalliance) and war-mix (0.2:0.6:0.2 noalliance) vs noquit. Gauges: spies & raids per active/day, ships destroyed, rank-1 share, r1/r10, Gini.
- 6 Nov: no-`cap` NO-GO: nocap vs churn-base −0.163 ln (0/10), HDS 0.860→0.782; nocap-nq vs noquit −0.271 (0/10), HDS 0.723→0.681. noquit world HDS 0.723 (default 0.860); war-all HDS 0.695, war-mix 0.721.
- 6 Nov reality gauges (seeds 1-10): churn-base raid/day 1.64 spy 0.71 r1 0.105 r1/r10 5.2 store 115 d; noquit raid 0.114 spy 0.47 r1 0.053 r1/r10 1.22 store 100 d; war-mix raid 0.058 spy 0.90 r1 0.074 store 73 d; war-all raid 0.287 spy 1.51 r1 0.021 r1/r10 1.10 store 19 d lost 0.45B. FINDING: raids live on quitters; with everyone active, raiders almost never attack (defended, alert targets fail the gates) → no war, no dominance. Next: per-gate refusal counts in Raider::raid.
- 6 Nov: Ledger.raid_gates[8] added (players.rs Raider::raid: no report, stale/cooldown, no plan, pays, risky, fleet-home, send fail, launched) → OUT.gates.json per season (speed2/war2.bin). Running gates-war (war-all world) vs gates-base (default), seeds 1-5.
- 6 Nov raid gates (sum 5 seasons, 64 acc): war-all world: no report 359k, stale/cooldown 616k, NO PLAN 615k, pays 98, risky 13, send fail 1.9k, launched 26k (≈0.45 raids/raider-day). Default world: no report 73k, stale 23k, no plan 33k, launched 16k. Main blocker in war = no raid plan. Split it (no_plan: partial report / fighters short / fuel) → gates3 running.
- 6 Nov: war-mil60 / war-mil90 (raider_military_share 0.6 / 0.9 via params=, war-all world, seeds 1-10) running; compare with war-all on reality.py + paired.
- 6 Nov: gates3 no-plan split (5 seasons war-all): partial report 194k, FIGHTERS SHORT 406k, fuel 15k. raid_plan sizes only Kit.fighter (cheapest shooter) → a raider's cruisers/battleships never count. war-mil60/90 (raider_military_share): no change (raid/day 0.28/0.23 vs 0.29; paired −0.016/+0.028 n.s.) — confirms the gate, not fleet size. Fix arm `warfleet` (World.war_fleet): raid_plan falls back to war_plan (whole warship mix, OGMEM odds floor 1.6). Running war-wf (war-all world) and base-wf (default world), seeds 1-10, speed2/war4.bin.
- 6 Nov: OWNER STOP. war-wf/base-wf killed mid-run (partial, discard); 20-min loop cancelled. Code left in place: arms churn=, personas=, noalliance, warfleet; Ledger raid_gates/no_plan + OUT.gates.json; scripts/reality.py. Uncommitted.
- 7 Nov (social session, owner asked to continue this handoff in parallel): reran the warfleet bench on war4.bin, seeds 1-10, LIMIT 1800 s: war-all2 / war-wf2 (churn=0:1 noalliance personas=0:1:0 [+warfleet]) and churn-base2 / base-wf2 ([warfleet]); old baseline traces were removed in the disk cleanup, so both sides rerun on the same binary.
- 7 Nov: owner: use the best available models everywhere, social included. Added best-all: newest binary (social-size.bin) with deployed economy (train4/best-es3-s10) and fleet (es-late-c4/final-g16) policies plus the social model (live engine minds, cycles, skill, raids, notice breaks, dark9) and warfleet, churn=0:1, seeds 1-10, LIMIT 1800 s; compare with base-wf2 / war-wf2.
- 7 Nov warfleet bench (war4.bin, seeds 1-10, all seasons complete): war-all2 -> war-wf2: raids/active-day 0.287 -> 0.414, loot/prod 0.005 -> 0.052, ships lost 0.45B -> 22B, r1 0.021 -> 0.023, r1/r10 1.10 -> 1.17, store 19 -> 23 d, HDS 0.695 -> 0.692; paired (model accounts) -0.079 ln, 3/10 up, CI [-0.134, -0.029]. Default world churn-base2 -> base-wf2: no change (-0.003 ln, CI crosses 0; HDS 0.860/0.861). Verdict: warfleet unlocks real fighting (+44% raids, 10x loot, 50x losses) but war destroys more than it takes, nobody dominates, and the fighters end weaker. Not a strength win; keep as the grinding-world arm. Next per handoff: probe escalation on partial reports, strongest-hunts-rivals.
- 7 Nov best-all (social-size.bin, all deployed models + social minds + raids + warfleet + dark9, churn=0:1, alliances=6, seeds 1-10): loot/prod 0.226, raids/active-day 0.31, spy/day 1.11, r1 0.104, r1/r10 3.88, store 63 d (base-wf2 108 d), lost 34B, HDS 0.745 with 0 violations. Paired vs base-wf2 is not comparable (base world has quitters). Gates: partial report 662k and stale/cooldown 3.47M dominate; launched ~40k. Delegated: root cause of partial reports and stale/cooldown, faithful fix, war-world bench (war5.bin).
- 7 Nov OWNER DIRECTION: a real player attacks without a complete report; the raider must decide under uncertainty (estimate the hidden fleet/defence from public highscore military points, older reports, typical levels; size with a margin for the uncertainty; expected loot - expected loss rule; test attacks). Sent to the spy-report worker; one rule set with PHP RaidPlanner.
- 7 Nov: why raids fail on "partial report" and "stale/cooldown" (war world, `raids` arm, seeds 1-10, new counters in
  OUT.gates.json: `stale_split` [stale, cooldown, beaten], `spy_gates` [fresh, probe out, no idle probe, short volley,
  full volley, re-scout], `probe_diag`, `guess_gates`/`guess_outcomes`/`guess_value`). Root causes, in order:
  (1) stale/cooldown is 97% STALE (6.3M of 6.5M; cooldown 77k, beaten 147k): reports age past 24 h because the scout has
  no idle probe (no-probe 10.3M of 12M scout looks). Two port bugs in `raids`: the yard counted flying probes toward the
  stock (QueueableUnitPlanner keeps 15 IDLE per planet past the opening) and, decisive, late accounts ordered no probe at
  all because `order_ships` held every unit order behind the saving for a building dearer than the stock (debug, seed 1
  acct 30 day 120: keep 38.8M metal vs 11M held, plans [(probe 15 → 0 payable)]; spying died after day ~100 for most
  persona raiders). PHP's `queueable` asks only whether the yard can pay. Fixed inside `raids` (probe stock idle, probes
  exempt from the build saving). (2) Partial reports persist because ANY report <24 h counted as fresh intel, so the
  escalated volley flew only after the redacted report expired (same rule in QueueableSpyPlanner). New arm `rescout`:
  a fresh redacted report on a defended/rich body is re-scouted as soon as the next, larger volley is idle (volley
  doubles from the floor, cap 40; stops once the largest volley came back redacted). (3) Owner direction: a player does
  not need a full report. New arm `guess` (`players::guessed_sections` = PHP `ReportedPlanet::estimated`): the hidden
  section is the worst case a player can read — all public highscore military points the visible sections do not
  explain stand on this body, in the mix of the account's own last report that read that section, else as the cheapest
  real-weapon unit of the missing kind; the forecast fight and loss tolerance then decide as on a full report.
  PHP ported: QueueableSpyPlanner re-scout + own-reports-only reads (it read OTHER players' reports at a coordinate — a
  cheat; the sim already read own reports only), ReportedPlanet::estimated used by RaidPlanner (seenBy). Tests:
  SpyDepthTest 16/16, ReportEstimateTest 5/5, cargo 143/143; RaidPlanner battle tests need FFI (absent on host).
- 7 Nov results (speed2/war7.bin = war5.bin, war world churn=0:1 noalliance personas=0:1:0 warfleet raids, seeds 1-10,
  dirs err4-side/war-f-*; raids3 = old port): raid/day 0.697 → 0.814 (probe fix) → 0.778 rescout / 0.897 guess / 0.891
  both (war-wf2 without `raids` 0.414); spy/day 1.75 → 2.90 / 3.03 / 2.85 / 2.76 (real farmers 200-500); full volleys
  221k → 360k; loot/prod 0.069 → 0.066 / 0.062 / 0.064 / 0.064; r1 share 0.026 / 0.026 / 0.026 / 0.027; r1/r10 1.22 for
  all; ships lost 26.9B → 27.7 / 21.9 / 20.4 / 22.8 B; HDS 0.687 → 0.691 / 0.686 / 0.696 / 0.692. Guess: of 1.35M
  redacted looks 16.2k planned on the estimate, 14.6k launched; 96.2% won, 2.9% draw, 0.9% beaten; loot 56.3B vs loss
  2.2B (both: 14.6k, 95.9/3.2/0.8%, 44.7B vs 2.8B). Paired (vs f-raids): rescout all +0.040 n.s.; guess model
  accounts (0-20) +0.025 n.s., persona raiders −0.144 (CI<0); both model +0.031 (8/10, CI [+0.008, +0.050]), persona
  −0.172, all −0.105. Probe fix vs old port: all −0.023 n.s., model −0.059 (CI<0). Verdict: probe-stock fix and `guess`
  are real (more spying, +10% raids, guessed raids win 96%); `rescout` is neutral; still no dominance (r1/r10 1.22):
  raids move value between equals and the war destroys more than it decides. Next: target choice (hit the weakest
  reported neighbour repeatedly / the leader hunts rivals), and a cheap test attack on targets whose estimate is too
  uncertain (owner's fallback, not built yet).
- 7 Nov, why nobody dominates (war world churn=0:1 noalliance personas=0:1:0 warfleet raids guess, seeds 1-10,
  dirs err4-side/war-g-*; binary speed2/war6.bin). Step 0: battle tests run in docker with FFI (`docker run --rm
  --network host -u 1000:1000 -e DB_DATABASE=ogamex-test-ffi ... ogamex-local-docker-dev:latest php -d pcov.enabled=0
  vendor/bin/pest --parallel --testsuite=Modules --filter=...`): 389 raid/battle/fleet/spy tests pass, nothing broken by
  a7c1a2b4 (2 failures on a brand-new database were Situation's alliance tag `'U' . id % 10000` under 3 characters for
  small ids, not ours). Hypotheses read on battles.jsonl: (a) target choice spreading raids evenly is FALSE: 76% of
  raids already hit accounts under half the raider's points, an attacker's top target takes 66% of its raids; (c)
  symmetric losses FALSE: attackers win 96%, lose 2.2B against 82B on weaker targets; (b) the leader raids 1.8x the mean
  but cannot fly more. Real blockers, three sim-only divergences from the module: (1) `Raider::act` stopped at a fixed
  SLOTS=4 fleets while the account holds ~17 host slots (module: Login\FleetSlots); (2) the raider's candidates were the
  capitals only, while 73% of the stock sits on colonies (module: QueueableSpyPlanner reads every planet and moon, no
  bash cap in the sim); (3) no newbie protection in the sim (module: RaidPlanner isNewbie, PlayerObservationService
  score_viable), and the host's isNewbie had no ceiling. Real servers (PUB serverData, 257 of 267 universes):
  newbieProtectionHigh 50,000 / newbieProtectionLimit 500,000 = 1:5 under 50k, 1:10 to 500k, none above (WIK-045).
  New arms `slots`, `bodies` (+ bash limit 6 per body per 24 h), `newbie` (bands above, also in the errand menu).
  Host: PlayerService::protectsNewbie (bands) behind isNewbie/isStrong; module score_viable uses it.
  Results vs war-g-base2 (r1 share / r1/r10 / raids/day / spy/day / loot/prod / ships lost B / HDS):
  base 0.026 / 1.20 / 0.90 / 2.85 / 0.064 / 20.4 / 0.696; bodies 0.025 / 1.18 / 0.98 / 2.71 / 0.065 / 19.5 / 0.702;
  slots 0.055 / 1.89 / 3.75 / 7.39 / 0.223 / 67.5 / 0.691; bodies+slots 0.042 / 1.45 / 4.35 / 7.88 / 0.16 / 34.5 / 0.713;
  +newbie flat 1:5 (host's old rule) 0.038 / 1.63 / 3.57 / 8.51 / 0.154 / 45.9 / 0.656; **all three with the real bands
  (war-g-all3c) 0.039 / 1.56 / 3.91 / 8.70 / 0.163 / 49.4 / 0.658**. Paired ln: slots alone model −1.26, persona −1.40
  (crushes half the world to ~0 points: without protection the strong farm the small from day 1); all3c model −0.27
  (CI<0, 0/10), persona +0.12 (CI>0). r1 income all3c: loot 9.7B on 15.5B production (base 2.4B); the leader flies
  26k raids, 51B loot. HDS drops with `newbie` in the bottom cells only (bottom|64-256 grow to 0.9-1.7k against real
  84-157: real bottoms are quitters, absent by owner rule), top10/mid cells unchanged or up. Verdict: the fixed four
  slots were the main brake on war (raids x4, loot/prod x2.5, r1/r10 1.2 → 1.56); dominance still far from real
  (r1/r10 5.5, share 0.17). The deployed errand model loses −0.27 in the harder world: retrain/tune it there next.
  Next: the leader hunting rivals' fleets (crashes, debris), and ACS/alliance focus for alliance dominance.
- 7 Nov, the leader hunts rivals' fleets (arm `hunt`, binary speed2/war7.bin; war world churn=0:1 noalliance
  personas=0:1:0 warfleet raids guess slots bodies newbie, seeds 1-10, dirs err4-side/war-h-base / war-h-hunt; base
  reproduces war-g-all3c exactly, so defaults are unchanged). Visible info only: the public highscore's military column
  (refreshed at day end), own reports (body where a fleet was seen parked; hour-of-day it was seen docked, ±2 h) and
  the gain rule loot + harvestable debris − forecast loss (debris counted only on a report ≤ 3 h old and with a
  harvester held, = RaidPlanner `p20Debris`/canHarvest); hunted bodies are re-probed after 3 h instead of 24 h; a crash
  flies the whole warship stock when that loses less. Results base → hunt: r1 share 0.039 → 0.045, r1/r10 1.56 → 1.99,
  raids/day 3.91 → 2.66, spy/day 8.7 → 8.3, fleet crashes/day (defender lost ≥ 1e7 of ships, `rl/scripts/crashes.py`)
  1.43 → 3.68 (destroyed 14 → 43 B, attacker won 84% → 73%), loot/prod 0.163 → 0.272, ships lost 49 → 116 B, HDS 0.658
  → 0.610; paired ln model −0.271 (0/10, CI [−0.35, −0.20]), persona −0.079 (CI < 0). hunt_gates (10 seasons): rival
  looks 1.23M, hunt probes 259k, off-hour skips 241k, raids launched with debris 41.6k, of them on a parked fleet 10.2k.
  Verdict: NOT adopted. It moves dominance the right way (r1/r10 +28%, crashes ×2.6) but the world burns twice the
  ships, a quarter of crashes are lost (3 h-old reports miss returning fleets or ninjas), both groups end weaker and
  HDS drops 0.05. Kept behind `hunt` (sim) and `ai.rl.hunt` / AI_RL_HUNT (PHP QueueableSpyPlanner, default off;
  FleetHuntTest 4/4 incl. unseen-state invariance, SpyDepthTest unchanged; cargo 148/148). The deployed errand model
  still loses in this world (−0.27 here too): retrain there is a separate step. Next if resumed: hunt only on a report
  from the same login (probe then strike, as real hunters do), and a phalanx check before the crash.
- 7 Nov, newbie protection check (host cf1eaf24 `PlayerService::protectsNewbie`): matches the real default. Sources:
  OGame wiki Newbie Protection (local WIK-045: "0 to 50.000 points: 1:5 ratio; 50.000 to 500.000 points: 1:10") and
  the live serverData newbieProtectionHigh 50,000 / newbieProtectionLimit 500,000 on 257 of 267 universes
  (REAL-SERVER-PLAYER-PROFILE dump). Inactive players lose it (host isInactive guard). Not modelled: older universes'
  flat 1:5 with a 5,000-point floor (WIK-045 legacy line), a per-universe override from settings.
