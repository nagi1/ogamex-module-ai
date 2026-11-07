# What went wrong for others: RL / imitation pitfalls in game agents (5 Nov 2026)

Research note, no code change. Purpose: the mistakes labs and individuals made when training game agents with RL and
imitation learning, ranked by how likely they are to hit **our** pipeline: Rust simulator -> scripted teacher personas
-> behaviour cloning (shared encoder + ministry heads) -> ES / self-play league, judged by HDS against real data.

Stages referenced (STRONG-PLAYER-PLAN.md, 5 Nov program): **A** teacher + action space in the sim, **B** HDS cells
from real data, **C** behaviour cloning on the widened teacher, **D** ES / self-play league fine-tune. Ministries:
economy, defence (fleet), intelligence, logistics, foreign affairs, plus the router.

Marking: **[verified]** = checked against the primary source or its abstract/blog during this research;
**[memory]** = from the primary source as I know it, URL known, detail not re-read today; **[unverified]** = secondary
report or detail I could not confirm. Treat [unverified] lines as leads, not facts.

---

## 1. Top 12 pitfalls for us (ranked)

| # | Pitfall | Who hit it | Our countermeasure | Affects |
| --- | --- | --- | --- | --- |
| 1 | **The simulator allows states the real game does not**, and the optimiser finds them first | OpenAI hide-and-seek (box surfing, ramp launch), ES on Atari Q*bert (score bug), Krakovna's list; *us already*: graviton 6-8 and 1.6M satellites | Legality lives in catalogue data shared by sim, teacher, model and PHP mask; run `anomaly_report.py` against the corpus after every training slice; any behaviour far outside the real bands stops training until a source says it is legal | A, D; all ministries |
| 2 | **Reward hacking of the proxy** (HDS cells, learned discriminator D, shaped terms) | CoastRunners boat, GT Sophy's collision penalties, GAIL-style discriminators, Krakovna | Hold out HDS cells that are never in the reward and report them separately; cap λ; read what the policy *does* (stock, shares, fleets) before believing a score; a gain on rewarded cells with a loss on held-out cells = hacking | B, D |
| 3 | **Information leaks = cheating** (the agent sees more than a player) | AlphaStar Jan 2019 (whole-map raw view), OpenAI Five (API reads exact values), FTW (perfect aim/reaction) | Every feature is computable from what the PHP UI shows that account (own empire, reports it received, galaxy, messages); the unseen-state invariance test on every feature-version bump; no candidate column computed from hidden state (incl. the teacher's pick if the teacher reads hidden state) | A, C; intelligence, defence |
| 4 | **BC looks good offline and fails in rollout**: compounding error, copycat / causal confusion, weak teacher | DAgger (Ross 2011), de Haan 2019 causal confusion, AlphaGo SL vs RL policy; *us*: 93.6% top-1 yet 1 planet all year | Judge BC only by rollouts (HDS over 10 seeds), never by top-1; DAgger-style relabelling on the model's own states; ablate the teacher-pick column (if the model copies it, it learned nothing) | C |
| 5 | **RL fine-tuning destroys the cloned skills and the human style** | VPT (needed KL to the BC policy), AlphaStar (KL to human policy + build-order statistic), CICERO/piKL | Anchor ES to the BC policy: fitness = objective − β·divergence from BC on a fixed probe set; HDS is a hard gate on every ES generation, not only at the end | D |
| 6 | **Self-play cycles, collapses or overfits to its opponents** | AlphaStar (needed league + exploiters), PSRO (joint-policy correlation), Balduzzi/Czarnecki (non-transitive games) | League = scripted personas + frozen past snapshots + dedicated exploiters, sampled by prioritised fictitious self-play; evaluate against a held-out opponent pool that is never trained against | D; defence, foreign |
| 7 | **A strong agent with a blind spot any exploiter finds** | KataGo cyclic attack (>97% wins), AlphaGo game 4 move 78, OpenAI Five Arena teams | Before "done": a 15-min exploiter slice (a raider/scout persona trained against the frozen agent); if it wins easily, the agent is not done | D; defence, intelligence |
| 8 | **Evaluation that cannot tell signal from noise**: few seeds, one opponent, Elo inflation | Henderson 2018, Agarwal 2021, Balduzzi 2018 | ≥10 season seeds, IQM + stratified bootstrap 95% CI (already the S2 rule); fixed opponent pool; Nash-averaged or per-opponent tables, never one Elo number; seeds for eval never used in training | all |
| 9 | **Implementation details and silent bugs dominate results** | Engstrom 2020 (PPO's gains = code-level tricks), 37 PPO details, Irpan, OpenAI Five reruns | One change per row (RL-FLOW); reproduce the previous row's number before a change; unit tests on trainer pieces (normalisation, masks, warm-start by name) | C, D |
| 10 | **Long-horizon credit assignment** (a 256-day season, rewards months later) | OpenAI Five (horizon tuning, team spirit), NetHack (no RL agent ascended), Irpan | Decide per login over typed candidates (macro actions), bootstrap V at the horizon, short-horizon shaped terms that sum to the long goal; curriculum by season age; do not expect raw RL to find a 100-day plan | C, D; economy, logistics |
| 11 | **A good scripted bot beats the learned one; hierarchy is the hard part** | NetHack 2021 (symbolic ~3x neural), Lux S2 / Halite / Kore (rule bots won), StarCraft BW bot ladders, CivRealm (RL fails on full Freeciv) | Keep the teacher as the baseline per ministry; replace a ministry only when the learned head beats it beyond the seed spread; hybrid (scripted macro + learned choice) is a valid end state | all; router |
| 12 | **Multi-player diplomacy: self-play invents non-human conventions; talk drifts from action** | CICERO / DORA / piKL (Meta), CICERO honesty critiques | Foreign ministry trained only after D, regularised toward human-data behaviour; language module only voices the chosen intent; a check that no message contradicts the planned action; evaluate with human-like personas, not only copies of itself | foreign affairs (after D) |

Also watch (not top 12): router collapse in mixture-of-experts (Shazeer 2017: add a load-balancing term and log
per-head usage); architecture "surgery" bugs when widening a trained net (OpenAI Five; our warm-start by column name
already tests identical logits); catastrophic forgetting of early-season play when later stages train on late states
(EWC; mix all season ages in every batch); ES noise with small populations (Salimans 2017; antithetic sampling,
fitness shaping, ≥3 seeds before a keep).

---

## 2. Per-source notes

### 2.1 DeepMind AlphaStar (StarCraft II)
- **Vinyals et al., "Grandmaster level in StarCraft II using multi-agent reinforcement learning", Nature 2019**,
  https://www.nature.com/articles/s41586-019-1724-z ; blog
  https://deepmind.google/discover/blog/alphastar-grandmaster-level-in-starcraft-ii-using-multi-agent-reinforcement-learning/
  [memory]
  - Naive self-play **chased cycles and forgot** how to beat old strategies. Fix: a league of main agents, *main
    exploiters* (beat the current main agents) and *league exploiters* (find weaknesses of the whole league), sampled
    with prioritised fictitious self-play (PFSP).
  - RL from scratch explored badly. Fix: supervised start on human replays, a KL term to the human policy during RL,
    and a "z" statistic (sampled human build order / unit counts) the agent is rewarded for following, which kept
    strategic diversity.
  - **Fairness / interface**: the January 2019 showmatch agents used the raw interface that saw the whole visible map
    at once and had superhuman burst APM; MaNa beat the camera-restricted version in the live game. The Nature version
    used a camera interface and APM limits agreed with a professional player.
  - **Ours**: league with exploiters is the D design; the "z" idea maps to doctrine/persona conditioning; the interface
    lesson = our no-cheating rule, enforced in features, not in reward.
- **"AlphaStar Unplugged: Large-Scale Offline RL", Mathieu et al. 2023**, https://arxiv.org/abs/2308.03526 [verified]
  - Several offline-RL methods **failed to beat plain behaviour cloning**; only one-step offline RL methods did, reaching
    90% vs the published BC agent. **Ours**: a strong BC is a hard baseline; any C->D step must beat it beyond spread.

### 2.2 OpenAI Five (Dota 2)
- **Berner et al., "Dota 2 with Large Scale Deep Reinforcement Learning", 2019**, https://arxiv.org/abs/1912.06680
  [memory]
  - **Surgery**: 10 months of continuous training while the game patch, observation and network changed; they built
    tools to transfer weights across changes. A rerun from scratch ("Rerun") matched the surgery-trained agent with
    far less compute, i.e. surgery saved wall time but accumulated hidden baggage. **Ours**: warm-start by column name
    is surgery; keep a from-scratch control run at least once per feature version.
  - **Long horizon**: rewards were shaped (last hits, towers, kills) and the discount horizon was raised during
    training; **team spirit** τ annealed from selfish to shared reward so early learning had dense credit. **Ours**:
    per-day shaped economy reward early, account-level value later; ministries share one value (team spirit = 1).
  - **Restricted game**: 17 heroes, items and mechanics removed; capability did not transfer to the full game.
    **Ours**: every disabled mechanic (alliance depot, ACS) is a place the agent's behaviour is unrealistic; list them.
  - In the public **Arena** the agent won ~99.4% of ~7k games but some teams found repeatable strategies to beat it
    [memory, numbers unverified]. **Ours**: pitfall 7.

### 2.3 AlphaGo / AlphaGo Zero / AlphaZero / MuZero
- **Silver et al., "Mastering the game of Go with deep neural networks and tree search", Nature 2016**,
  https://www.nature.com/articles/nature16961 [memory]
  - The **SL policy (human imitation) worked better inside search than the stronger RL policy**, attributed to humans
    picking a diverse beam of moves. **Ours**: the "strongest" ES policy is not automatically the best prior or the
    most human; keep diversity (entropy) and judge by HDS.
  - Game 4 vs Lee Sedol (move 78): the value net misjudged a rare position and play collapsed [memory].
- **Silver et al., "Mastering the game of Go without human knowledge", Nature 2017**,
  https://www.nature.com/articles/nature24270 ; **AlphaZero**, https://arxiv.org/abs/1712.01815 ; **MuZero**,
  https://arxiv.org/abs/1911.08265 [memory]
  - Tabula rasa works for **perfect-information, two-player, short-horizon** games with an exact, cheap simulator.
    None of these hold for OGame (partial info, many players, months). **Ours**: do not copy the "no human data" lesson;
    imitation start is needed (AlphaStar, VPT, CICERO all needed it).
- **Wang, Gleave et al., "Adversarial Policies Beat Superhuman Go AIs", ICML 2023**, https://arxiv.org/abs/2211.00241 ;
  https://goattack.far.ai [verified]
  - Adversaries using <14% of KataGo's training compute win >97% vs superhuman-search KataGo with a cyclic-group trick
    that amateurs beat easily; adversarial training only partly fixes it. **Ours**: pitfall 7; exploiters in the league.

### 2.4 DeepMind Capture the Flag (FTW)
- **Jaderberg et al., "Human-level performance in 3D multiplayer games with population-based RL", Science 2019**,
  https://arxiv.org/abs/1807.01281 [memory]
  - A population (PBT) with **learned internal rewards** per agent from game events, instead of hand-weighted rewards;
    **procedurally generated maps** so agents did not overfit one map.
  - Agents had **superhuman reaction and aim**; with an artificial ~267 ms reaction delay they still beat humans, but
    the critique stands that an unconstrained interface inflates results. **Ours**: universe seeds = maps; train on
    many universes/galaxy layouts; humans log in with gaps, so the agent must too (login gaps already in S1).

### 2.5 Meta CICERO (Diplomacy) — foreign ministry
- **Meta FAIR, "Human-level play in the game of Diplomacy by combining language models with strategic reasoning",
  Science 2022**, https://www.science.org/doi/10.1126/science.ade9097 [verified: summary]
  - Dialogue is **conditioned on a planned intent**; filters remove messages inconsistent with plan/state. Played
    blitz online, >2x average human score over 40 games.
  - **Bakhtin et al., "No-Press Diplomacy from Scratch" (DORA), NeurIPS 2021**, https://arxiv.org/abs/2110.02924
    [memory]: self-play from scratch reached strong play against itself but learned **conventions that did not work
    with humans** (cooperation norms differ). **Jacob et al., "Modeling Strong and Human-Like Gameplay with
    KL-Regularized Search" (piKL), ICML 2022**, https://arxiv.org/abs/2112.07544 [memory]: regularising toward a
    human-imitation policy kept play both strong and human-compatible; CICERO builds on it.
  - **Honesty**: Meta says CICERO was largely honest (trained on a "truthful" subset); critiques (Park et al., "AI
    Deception: A Survey", https://arxiv.org/abs/2308.14752) document broken deals and lies; experts reportedly
    rated ~10% of messages inconsistent with plan or state [unverified figure].
  - **Ours**: the foreign ministry must (a) anchor to human alliance behaviour (forum/wiki data: NAPs, ACS calls), not
    pure self-play; (b) only voice the chosen intent; (c) be tested on whether its actions match what it said. In a
    general-sum, many-player game, self-play alone produces alien diplomacy.

### 2.6 DeepNash (Stratego)
- **Perolat et al., "Mastering the game of Stratego with model-free multiagent RL", Science 2022**,
  https://arxiv.org/abs/2206.15378 [memory]
  - Huge imperfect-information game where search was impractical; plain self-play **cycled**. Regularised Nash
    Dynamics (R-NaD: reward penalised by divergence from a regulariser policy, regulariser updated periodically)
    converged toward an approximate Nash equilibrium and learned bluffing.
  - At test time they still needed post-processing to remove low-probability blunders. **Ours**: a regulariser policy
    (the BC teacher) inside the objective is the same trick as pitfall 5; hidden information (unseen fleets) means the
    agent must reason from reports, never from state.

### 2.7 Sony Gran Turismo Sophy
- **Wurman et al., "Outracing champion Gran Turismo drivers with deep RL", Nature 2022**,
  https://www.nature.com/articles/s41586-021-04357-7 ; Sony AI blog "Don't Cross That Line! How Our AI Agent Learned
  Sportsmanship", https://ai.sony/blog/dont-cross-that-line-how-our-ai-agent-learned-sportsmanship [verified: blog]
  - Early agents were penalised for collisions they **did not cause**, and testers said they would have been
    disqualified for poor sportsmanship. Etiquette had to be **hand-written penalty rules** after talking to stewards,
    because no data defined it; too-large penalties produced timid drivers.
  - **Ours**: "plays like a human" norms (no farming the same newbie 50 times, bashing limits, honour points) are
    rules of conduct, not of physics; encode them from sources as constraints and measure them as HDS cells; a penalty
    that blames the agent for the opponent's choice teaches avoidance, not etiquette.

### 2.8 OpenAI hide-and-seek
- **Baker et al., "Emergent Tool Use From Multi-Agent Autocurricula", ICLR 2020**, https://arxiv.org/abs/1909.07528 ;
  https://openai.com/index/emergent-tool-use/ [memory]
  - Agents exploited the physics engine: **box surfing**, launching themselves with ramps, pushing objects through
    walls, running out of the arena (fixed with boundaries). Measuring progress in self-play was hard, so they added
    transfer tasks.
  - **Ours**: pitfall 1. Every "surprising strategy" from D is a bug report against `ogsim` until a source shows a human
    does it; parity tests against PHP are the arena walls.

### 2.9 Minecraft VPT, MineRL and BASALT
- **Baker et al., "Video PreTraining (VPT)", NeurIPS 2022**, https://arxiv.org/abs/2206.11795 [memory]
  - BC foundation from 70k h of video labelled by an inverse-dynamics model trained on ~2k h of contractor data. RL
    fine-tuning **without a KL penalty to the BC policy forgot** earlier skills; with it, reached diamond tools.
- **Guss et al., MineRL 2019 competition report**, https://arxiv.org/abs/2003.05012 [memory]: no team obtained a
  diamond; top solutions combined demonstrations with hierarchy; sample limits forced priors.
- **Shah et al., "The MineRL BASALT Competition on Learning from Human Feedback"**, https://arxiv.org/abs/2107.01969
  [memory]: tasks without reward, judged by humans, because reward-designed benchmarks get gamed.
- **Ours**: pitfall 5 (KL anchor); BASALT supports HDS as a human-likeness judge, but a judge we also optimise against
  needs held-out cells (pitfall 2).

### 2.10 NetHack Challenge
- **Hambro et al., "Insights From the NeurIPS 2021 NetHack Challenge", PMLR 176**, https://arxiv.org/abs/2203.11889
  [verified]
  - Symbolic bots took the top 3; best symbolic median ~3x the best neural; no agent ascended in >500k games. Neural
    agents lacked high-level strategy; hierarchical RL is open. Deep RL still improved ~5x over the 2020 baseline.
  - **Ours**: pitfall 11. Long, sparse, partially observed games favour structured agents; keep the macro structure
    (errands, ministries) scripted where learning has not beaten it.

### 2.11 Lux AI / Kaggle simulation competitions
- **Lux AI S1 (Kaggle 2021)**: won by "Toad Brigade" with deep RL (win/loss reward ±1); the team first tried rules
  because rules won Halite, then dropped them [unverified: secondary slide deck;
  code https://github.com/IsaiahPressman/Kaggle_Lux_AI_2021].
- **Goodfriend, "A Competition Winning Deep RL Agent in microRTS", 2024**, https://arxiv.org/abs/2402.08112 [verified:
  claim]: rules-based agents won Halite, Kore and Lux AI S2.
- **Ours**: RL won where the sim was fast, the game short and the reward a clean win/loss; rules won otherwise.
  OGame is the "otherwise" case, so the teacher is a serious baseline (pitfall 11).

### 2.12 4X / strategy: Civilization, Freeciv, StarCraft bots, Honor of Kings
- **Branavan, Silver, Barzilay, "Learning to Win by Reading Manuals in a Monte-Carlo Framework", JAIR 2012**,
  https://arxiv.org/abs/1401.5390 [memory]: Civilization II agent improved strongly by grounding the game manual's
  text. **Ours**: supports turning wiki/forum doctrine into teacher rules and features.
- **Qi et al., "CivRealm", ICLR 2024**, https://arxiv.org/abs/2401.10568 [verified: summary]: RL handles focused
  mini-games but **struggles on the full Freeciv game**. **Ours**: curriculum by sub-problem (S0..S8) is right; the
  full game end-to-end is not a realistic first target.
- **StarCraft: Brood War bot competitions (AIIDE / SSCAIT)**, e.g. https://www.cs.mun.ca/~dchurchill/starcraftaicomp/
  [memory]: for a decade scripted bots dominated; humans beat top bots by finding one exploitable habit and repeating
  it. **Tencent TStarBot**, https://arxiv.org/abs/1809.07193 [memory]: macro actions + hierarchy made SC2 RL tractable.
- **Ye et al., "Mastering Complex Control in MOBA Games with Deep RL" (Honor of Kings 1v1), AAAI 2020**,
  https://arxiv.org/abs/1912.09729 and **"Towards Playing Full MOBA Games with Deep RL", NeurIPS 2020**,
  https://arxiv.org/abs/2011.12692 [verified: abstract]
  - Lineup combinatorics broke plain self-play; fix = curriculum: small fixed-lineup teachers, then **multi-teacher
    policy distillation** into one student; action masks from game knowledge; **multi-head value** (one per reward
    component); dual-clip PPO for stale off-policy data.
  - **Ours**: this is our ministries design: train/clone heads separately, distil into one encoder; multi-head value
    per ministry objective helps diagnose which reward moved; legality masks from the catalogue.

### 2.13 Essays and method papers
- **Alex Irpan, "Deep Reinforcement Learning Doesn't Work Yet", 2018**,
  https://www.alexirpan.com/2018/02/14/rl-hard.html [memory]: sample inefficiency; reward design is hard and gets
  exploited; local optima (HalfCheetah flipping on its back); results vary wildly by seed (a run that fails ~30% of
  seeds); often a domain-specific method beats RL. **Ours**: pitfalls 8, 9, 11.
- **Victoria Krakovna, "Specification gaming examples in AI"** (list + spreadsheet),
  https://vkrakovna.wordpress.com/2018/04/02/specification-gaming-examples-in-ai/ ; DeepMind, "Specification gaming:
  the flip side of AI ingenuity", 2020,
  https://deepmind.google/discover/blog/specification-gaming-the-flip-side-of-ai-ingenuity/ [memory]
  - Many game cases exploit simulator bugs or reward loopholes rather than the intent. **Ours**: pitfalls 1, 2.
- **OpenAI, "Faulty Reward Functions in the Wild", 2016**, https://openai.com/index/faulty-reward-functions/
  [memory]: the CoastRunners boat circles forever collecting respawning targets instead of finishing.
  **Ours**: a per-day loot or "points" reward will find the OGame equivalent (endless farming of one inactive).
- **Chrabaszcz, Loshchilov, Hutter, "Back to Basics: Benchmarking Canonical ES for Playing Atari", IJCAI 2018**,
  https://arxiv.org/abs/1802.08842 [memory]: **ES found a Q*bert game bug** yielding near-unlimited points. **Ours**:
  directly relevant: our D stage is ES; ES is especially good at finding sim bugs because it optimises whole-episode
  return without caring how.
- **Salimans et al., "Evolution Strategies as a Scalable Alternative to RL", 2017**, https://arxiv.org/abs/1703.03864
  [memory]: needs antithetic sampling, rank-based fitness shaping, large populations; small populations = noise.
- **Engstrom, Ilyas et al., "Implementation Matters in Deep Policy Gradients: A Case Study on PPO and TRPO", ICLR
  2020**, https://arxiv.org/abs/2005.12729 [memory]: much of PPO's edge over TRPO came from "code-level
  optimisations" (value clipping, reward scaling, LR annealing, orthogonal init), not the clipped objective.
- **Huang et al., "The 37 Implementation Details of Proximal Policy Optimization", ICLR Blog Track 2022**,
  https://iclr-blog-track.github.io/2022/03/25/ppo-implementation-details/ [memory]: advantage normalisation,
  observation normalisation, invalid-action masking, terminal vs truncation handling. **Ours**: truncation at the
  season horizon must bootstrap (TRAINING-RUN already says so); masks must match the PHP legality.
- **Henderson et al., "Deep Reinforcement Learning that Matters", AAAI 2018**, https://arxiv.org/abs/1709.06560
  [memory]: two sets of 5 seeds of the *same* algorithm gave statistically different curves; codebases and
  hyperparameters changed rankings.
- **Agarwal et al., "Deep RL at the Edge of the Statistical Precipice", NeurIPS 2021**,
  https://arxiv.org/abs/2108.13264 [memory]: with 3-5 runs point estimates mislead; use IQM, stratified bootstrap CIs,
  performance profiles. **Ours**: our ≤15-min rule pushes toward few seeds; keep 10 season seeds and report CIs.
- **Balduzzi et al., "Re-evaluating Evaluation", NeurIPS 2018**, https://arxiv.org/abs/1806.02643 [memory]: Elo is
  distorted by redundant/weak agents in the pool (Elo inflation); Nash averaging is invariant to duplicates.
- **Balduzzi et al., "Open-ended Learning in Symmetric Zero-sum Games", ICML 2019**, https://arxiv.org/abs/1901.08106 ;
  **Czarnecki et al., "Real World Games Look Like Spinning Tops", NeurIPS 2020**, https://arxiv.org/abs/2004.09468
  [memory]: real games have large non-transitive parts; self-play against the latest agent cycles; population
  diversity is needed to climb.
- **Lanctot et al., "A Unified Game-Theoretic Approach to Multiagent RL" (PSRO), NeurIPS 2017**,
  https://arxiv.org/abs/1711.00832 [memory]: independently trained agents **overfit to their training partners**
  (joint-policy correlation); evaluate against partners never seen in training.
- **Gleave et al., "Adversarial Policies: Attacking Deep RL", ICLR 2020**, https://arxiv.org/abs/1905.10615 [memory]:
  a victim can be beaten by an opponent doing something "nonsensical" that shifts its observations off-distribution.
- **Ross, Gordon, Bagnell, "A Reduction of Imitation Learning and Structured Prediction to No-Regret Online Learning"
  (DAgger), AISTATS 2011**, https://arxiv.org/abs/1011.0686 [memory]: BC error compounds quadratically with horizon
  because the learner visits states the expert never did; fix = query the expert on the learner's states.
  **Ours**: the teacher is code, so DAgger is cheap: roll out the model, label its states with the teacher, retrain.
- **de Haan, Jayaraman, Levine, "Causal Confusion in Imitation Learning", NeurIPS 2019**,
  https://arxiv.org/abs/1905.11979 [memory]: giving the imitator *more* information can make it worse when a feature
  correlates with the expert action without causing it (copycat). **Ours**: the "teacher's pick" candidate column
  (TRAINING-RUN, 1 Nov) is the textbook copycat feature; measure the model with the column masked.
- **Ho & Ermon, "Generative Adversarial Imitation Learning", NeurIPS 2016**, https://arxiv.org/abs/1606.03476
  [memory]: learned discriminators as reward are unstable and exploitable. **Ours**: TRAINING-RUN's detector D
  retrained each round is GAIL-like; watch for D being fooled by one off-distribution trick (pitfall 2).
- **Shazeer et al., "Outrageously Large Neural Networks: The Sparsely-Gated Mixture-of-Experts Layer", ICLR 2017**,
  https://arxiv.org/abs/1701.06538 [memory]: gating collapses onto a few experts without a balancing loss. **Ours**:
  the router ("prime minister") will starve a ministry the same way; log usage per head.
- **Kirkpatrick et al., "Overcoming catastrophic forgetting in neural networks" (EWC), PNAS 2017**,
  https://arxiv.org/abs/1612.00796 [memory]: sequential training erases earlier tasks. **Ours**: a model fine-tuned on
  late-season war forgets early-season economy unless every batch mixes ages.
- **Tobin et al., "Domain Randomization for Transferring Deep Neural Networks from Simulation to the Real World",
  2017**, https://arxiv.org/abs/1703.06907 ; **OpenAI, "Solving Rubik's Cube with a Robot Hand" (ADR), 2019**,
  https://arxiv.org/abs/1910.07113 [memory]: policies overfit simulator quirks; randomising sim parameters makes them
  robust to the real system. **Ours**: the PHP host is our "real"; randomise what the sim is unsure of (login gaps,
  player counts, inactivity rates, universe speed) and keep the S2 transfer gap measured (Y, F, G).

---

## 3. Mapping to our open questions

- **Simulator exploits = impossible states.** Graviton 6-8 with 1.6M satellites (STRONG-PLAYER-PLAN, 5 Nov) is
  exactly the hide-and-seek/Q*bert pattern, found by scripts before any RL. ES in D will find more. Rule: a D slice
  that improves its score while an anomaly cell leaves the real band is reverted and the sim fixed first.
- **League exploiters.** D's league needs at least one exploiter persona whose only job is to beat the current main
  agent (raids on its fleet-saving gaps, spying patterns). Exploiters are not scored for HDS.
- **BC on a weak teacher.** BC caps at the teacher; it also inherits the teacher's sim exploits. Fix the teacher's
  anomalies before C, or the clone learns them (Stage C warm-start note already avoids an old disagreeing clone).
- **Reward hacking on HDS cells.** Split cells into "train" and "held-out"; held-out includes war cells from Stage B.
- **Partial-info leaks.** The teacher may read hidden state (it is a script); then its labels leak hidden information
  into the clone. Either restrict the teacher to player-visible state or drop labels that depend on hidden state.
- **256-day credit assignment.** Macro decisions per login, value bootstrap at horizon, and curriculum by season age;
  OpenAI Five's horizon schedule is the closest precedent.
- **Few seeds.** ≤15-min runs tempt 1-3 seeds. Keep the 10-seed judge and IQM+CI; a "keep" needs a gain beyond the
  seed spread (already RL-FLOW's rule; Henderson and Agarwal are why).
