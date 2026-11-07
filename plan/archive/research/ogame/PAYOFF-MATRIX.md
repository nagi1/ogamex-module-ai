# Payoff matrix between errand policies (T4 item 3, 5 Nov 2026)

Track T4 item 3 of [GAME-THEORY-EVOLUTION-FOR-TRAINING.md](GAME-THEORY-EVOLUTION-FOR-TRAINING.md): an empirical payoff
matrix, Nash averaging (Balduzzi et al. 2018) and alpha-Rank (Omidshafiei et al. 2019).

- Script: `Modules/AI/rl/scripts/payoff.py` (`export`, `run`, `analyze`). Run dir: `storage/rl/ogsim/runs/payoff/`
  (`analyze.txt`, `payoff.json`, `out/`).
- Policies: **teacher** (the scripted errand mix), **g14** `runs/es-late-c3/final-g14.policy.json`, **c4** (the BC clone)
  `runs/bc-late-c4/errand.policy.json`, **g20** exported from `runs/es-late-c3/es/checkpoint-000020.pt` with
  `errand_schema.policy_json`. The export is byte-identical to `es/gate-20.policy.json`, the reload diff is 0, and the
  binary plays it.
- Seasons: `runs/pool/season.bin`, bc-late-c4 arms plus `wide late satcap minerpost anchor freshspy tally`, 256 days,
  64 accounts. Each pair is played with A on accounts 0-31 / B on 32-63, then swapped, on the same seed. Seeds 1-5.
  80 seasons, all rc 0. The `tally` output shows Pool(0) and Pool(1) both playing, with different errand mixes.
- Payoff: M[A][B] = mean ln(1 + final score) of A's accounts minus B's (the es_world `finals` reader), averaged
  over both splits. The 95% CI is a bootstrap over the 5 seeds.

## Matrix (rows A, columns B)

|         | teacher | g14 | c4 | g20 |
|---------|---------|-----|----|-----|
| teacher | *-0.057 [-0.109, -0.004]* | -0.000 [-0.049, +0.062] | -0.020 [-0.050, +0.008] | **-0.124 [-0.156, -0.089]** |
| g14     | +0.000 | *+0.050 [-0.020, +0.165]* | **+0.034 [+0.010, +0.057]** | -0.008 [-0.056, +0.028] |
| c4      | +0.020 | **-0.034** | *-0.026 [-0.056, +0.010]* | **-0.067 [-0.104, -0.020]** |
| g20     | **+0.124** | +0.008 | **+0.067** | *+0.022 [-0.002, +0.043]* |

**Sanity check on the diagonal (A vs A).** A season of A vs A has only one split, so it carries the seed's position
effect: accounts 0-31 score differently from 32-63 by -1.29 to +1.50 ln, depending on the seed. The off-diagonal pairs
measure this effect (raw w0 = d + P, raw w1 = -d + P), so it is subtracted from the diagonal.

After this correction the diagonal is -0.057 to +0.050. Three of the four CIs cover 0; teacher's [-0.109, -0.004]
only just misses it. Read about ±0.05 as the matrix's noise floor at 5 seeds.

## Game-theoretic summary

- **Mean payoff against the uniform population:** g20 +0.050, g14 +0.006, c4 -0.020, teacher -0.036.
- **Max-entropy Nash:** pure g20 (1.0). No policy beats g20: g14 comes closest at -0.008 against it, with a CI that
  crosses 0. Over 300 bootstrap resamples, g20 is in the Nash support 91% of the time, g14 39%, teacher 28% and c4 0.3%.
  So g14 is the only plausible partner of g20 in the support.
- **alpha-Rank** (single population, m = 50): g20 takes all the mass for alpha >= 1/max|M| (0.999). At weak selection,
  alpha = 0.01/max|M|, the order is g20 0.35 > g14 0.26 > c4 0.21 > teacher 0.18. The teacher-vs-c4 place flips with
  alpha because their edge (+0.020) is inside the CI.
- **Cycles:** none, not even by point estimates. The game is transitive: g20 >= g14 > c4 ~ teacher.

## Reading

ES g20 is the strongest errand policy, and it gets there without exploiting a single opponent. Its clear wins are
against the teacher (+0.124) and the clone (+0.067). It ties g14 (+0.008), so ES gens 14 to 20 added little head to
head. The BC clone c4 loses to g14 and does not beat the teacher. There is no non-transitive structure, so this
population needs no PSRO or league yet. More seeds would be needed to separate g14 from g20 and c4 from the teacher.

## Wall time

- Pilot: 1 pair x 1 seed, 2 seasons in parallel, 217 s.
- Full grid: 80 seasons, 20 at a time, 1526 s, alongside the ES/PBT job. Seasons took 216-413 s, mean 361 s.
- Total about 29 min.
