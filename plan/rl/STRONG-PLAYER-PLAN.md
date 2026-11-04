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
| 4 Nov | `champion-late=DAY:HOURS`: the same match, 48 h look-ahead from day 60, 200 days (`champ-late48`; identical to `champ-year12` to day 60) | running |
