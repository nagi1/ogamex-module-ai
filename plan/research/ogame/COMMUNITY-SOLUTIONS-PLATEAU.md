# Breaking the plateau: what research and the bot-competition community do (5 Nov 2026)

Our state: the fleet model (g16) beats its scripted teacher by ~4% on 40 held-out seeds (CI [+0.001, +0.083]);
ES gains of +18-25% measured on seeds 1-10 were mostly winner's curse + small n; errand look-ahead labels at 2
rollouts agree with an independent copy 51% of the time (own-dice noise).

## Community (Kaggle simulation competitions: Halite IV, Kore 2022, Lux AI S2/S3)

- Rules won Halite, Kore and Lux S2. Kore 2022's top three were rule-based with forward simulation, route scoring
  and threat accounting ([Kore 1st](https://www.kaggle.com/competitions/kore-2022/writeups/harm-buisman-1st-place-solution),
  [5th: rules](https://www.kaggle.com/competitions/kore-2022/writeups/1-musketeer-1-musketeer-5th-place-solution-rules-b),
  [20th: rule-based economic model](https://www.kaggle.com/competitions/kore-2022/writeups/all-your-base-20th-place-solution-rule-based-econo),
  [overview](https://www.kaggle.com/writeups/zoli800/winning-kaggles-reinforcement-learning-competitio)).
- Lux S2: 1st and 2nd rule-based ([1st](https://github.com/ryandy/Lux-S2-public)); 3rd a C++ rule skeleton with
  ~1,500 tunable parameters fitted by learning; 4th deep RL, small RL model as teacher of a larger one
  ([4th](https://www.kaggle.com/competitions/lux-ai-season-2/writeups/flg-flg-s-approach-deep-reinforcement-learning-wit)).
- Lux S3 (2025): 1st place deep RL (IMPALA, self-play against the latest and older versions, simple reward); many
  top entries imitation learning of top teams ([summary](https://zenn.dev/kurupical/articles/61dbeedf89a29d),
  [3rd IL](https://www.kaggle.com/competitions/lux-ai-season-3/writeups/adg4b-imitation-learning-3rd-place-solution)).
- Halite IV teams: "imitation learning from a greedy rule-based agent can bootstrap a bot, but this approach
  ultimately limits the agent's performance to that of the rule based agents it is trained on"
  ([repo](https://github.com/Edouard360/Halite-Python-RL), [0Zeta bot: 100% rules, 100+ parameters](https://github.com/0Zeta/HaliteIV-Bot)).
- Hybrid (rules + learned layer) beats either alone in tower defense ([arXiv 2406.07980](https://arxiv.org/abs/2406.07980v1)).
- Rule-bot thresholds tuned by evolution beat expert hand-tuning; parameters interact, tune jointly
  ([GA for FPS bots](https://www.cse.unr.edu/~miles/papers/cec2004fps.pdf),
  [CMA-ES for MCTS params](https://dke.maastrichtuniversity.nl/m.winands/documents/New-AdaptiveMCTSforGGP.pdf),
  [NTBEA vs CMA-ES](https://arxiv.org/pdf/1904.01883.pdf)).

## Research on luck (variance)

- Common random numbers per event: share the chance outcomes across the actions compared (Veness et al., NeurIPS 2011,
  [Variance Reduction in MCTS](http://papers.neurips.cc/paper/4288-variance-reduction-in-monte-carlo-tree-search.pdf);
  [CRN for rollout planning, RLJ 2026](https://arxiv.org/pdf/2605.04732)).
- AIVAT: unbiased evaluation with a value baseline subtracting luck; 10-44x fewer games in poker
  ([AAAI 2018](https://ojs.aaai.org/index.php/AAAI/article/view/11481)).
- Stochastic MuZero: search with chance nodes (afterstates) ([ICLR 2022](https://iclr.cc/virtual/2022/poster/6832)).

## What this means for us (ranked)

1. **Raise the teacher, not only the student.** The clone + ES line is capped by the teacher (community: IL caps at
   the rule bot). The winners spent their effort on the rule bot and tuned its many parameters jointly by black-box
   search against a ladder. Our personas have many hand-set constants: tune them (CMA-ES/ES over a few dozen named
   parameters, judged on held-out seeds), then re-clone.
2. **Remove luck from comparisons.** Per-event dice (`eventdice`, measured now), then a luck-corrected score
   (AIVAT-like: subtract expected-vs-actual expedition and battle outcomes) so 40 seeds detect 1-2% gains.
3. **Search only where the variance is under control.** Errand look-ahead becomes usable only if `eventdice` lifts
   the halves' agreement well above 51%.
4. **Self-play league later.** Lux S3's winner: plain RL against latest + older selves with a simple reward. Our
   payoff matrix found no cycles yet, so this is behind 1-3.

## Full writeups read (headless Chromium via Playwright, 5 Nov)

- Kore 2022 1st (Harm Buisman): software-product loop — issue list, a replay viewer to see WHY each loss happened,
  a few fixes per version, 146 versions; each new version had to beat the previous one 1v1 (V92 → V118 took two
  weeks and 26 updates); the last ~20 versions only tuned parameters. Inside the bot: a space-time ledger of damage
  and damage potential (both sides) and scoring of almost all routes by tree search with caching. A rival found one
  structural weakness (blocking the first shipyard) late and farmed all his agents: exploiters matter.
- Lux S2 1st (Ryan Anderson): forward-simulates every unit 5-50+ steps inside the 3 s budget; ~10 persistent roles
  (miner, attacker, blockade, transporter…) with goals, a priority order for locking actions, cell claims.
- Kaggle field guide ("Winning Kaggle's RL competitions", Aug 2026): dev / promotion / quarantine splits ("hundreds
  of tweaks on the same seeds select noise"); paired seeds = common random numbers; payoff matrix + worst family;
  Orbit Wars (planets + fleets): top three were PPO self-play with SEMANTIC target actions (which planet, how many
  ships), fast rewritten simulators, opponent populations; Hungry Geese: pure self-play learned conventions that
  outsiders broke — diverse opponents fixed it; Lux S2 3rd: parameter correlations from noisy games did not survive
  causal tests.

## Our measurement: per-event dice (`eventdice`)

Same 2-vs-4-rollout noise test (seed 101, to day 140, 663 identical decisions): halves agree 51% → 74.7%; row-wise
|A−B| median 1.8e-4 → 5.6e-7 of worth (p90 8.8e-4 → 4.0e-4). Decisions with gaps 1e-4..1e-3 agree 59% (n=39): the
remaining noise is in the consequential decisions (battles), so labels there still need more rollouts.
