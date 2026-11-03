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
| 3 Nov | Full-season match, champion 8:0:12:2 vs best-es3-s10 (sampled, expofull), seeds 1-3 (`champ-h12r2`) | running, ~3 days of play per 5 min with the machine full |
| 3 Nov | Label pipeline smoke: 3 days, 8 seats, 799 decisions; the best model agrees with the champion on 30.4% | works; GPU training 6 s |
| 3 Nov | ExIt labels iteration 1: 30 days, champion 4:1:12:2, seeds 101-106 (`exit-it1`) | running |
