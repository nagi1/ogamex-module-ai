# Social cognition in AI: what the field did, its rules, its measures (6 Nov 2026)

A game-independent survey made before mapping anything onto OGame. It covers what projects trained or built, the rules
they followed and the yardsticks they used. Section 5 holds my own anchor proposal.
Tags: [known] = a published result I am confident of; [verify] = read the primary paper before citing a number.

## 1. Families of work

### A. Diplomacy (the closest analogue: alliances, betrayal, negotiation, 7 players, general-sum)

| Work | What | Rule it followed | Measure |
| --- | --- | --- | --- |
| DipNet, Paquette et al. 2019 | Imitation of ~150k human no-press games | Start from humans | Win/draw rate vs rule bots and humans |
| Gray et al. 2021; **piKL**, Jacob et al. 2022 (arXiv 2112.07544) | Search regularised toward a human imitation policy | **KL anchor to the human policy**: pure equilibrium search is strong but plays alien moves humans punish | Score vs humans, *and* prediction accuracy of human moves (human-likeness) |
| **Cicero**, Meta FAIR, *Science* 2022 (doi 10.1126/science.ade9097) | Full-press Diplomacy with dialogue; top 10% of humans on webDiplomacy across 40 games, rarely identified as an AI [known] | (1) Dialogue is generated **from planned intents** (actions), not free text. (2) Planning with piKL against modelled other players. (3) Filters drop messages that are inconsistent, nonsensical or strategically harmful. (4) **Mostly honest**: lying burns trust and loses long games | Score and rank vs humans; humans' failure to detect the bot; message–intent consistency |
| Kramár et al. 2022, *Nature Communications* ("Negotiation and honesty in AI for Diplomacy") | Agents that propose and accept contracts | Commitment alone is exploitable. **Betrayers must be sanctioned** (agents that punish defection) for cooperation to pay | Payoff with and without sanctioning; deviation rate |
| Welfare Diplomacy, Mukobi et al. 2023 [verify] | General-sum variant scoring welfare | Measure cooperation capacity separately from winning | Social welfare plus exploitability |
| **Niculae et al. 2015**, "Linguistic harbingers of betrayal" (ACL) | 500 Diplomacy friendships, half ending in betrayal | — (descriptive human data) | Betrayal is **predictable before it happens**: the betrayer turns suddenly more positive and polite, and planning language shifts between the partners |
| **Peskov et al. 2020**, "It Takes Two to Lie" (ACL; local corpus `DOC-cornell-deception-in-diplomacy`) | ~17k messages, each labelled with the sender's intent to deceive and the receiver's perception | — (human data) | Lies are a **small minority** of messages, and receivers detect them poorly (close to chance) [verify exact %] |

### B. Cooperation, alliances and populations in multi-agent RL

| Work | Finding / rule | Measure |
| --- | --- | --- |
| Leibo et al. 2017, sequential social dilemmas | Cooperation depends on resource scarcity and agent capacity | Collective return, sustainability, equality |
| **Hughes et al. 2020**, "Alliance dilemmas in many-player zero-sum games" (arXiv 2003.00799) | **Naive MARL does not form alliances**. Alliance formation is itself a social dilemma. A peer-to-peer **contract** mechanism lets agents form and enforce alliances | Win rate of the alliance and of individuals, contract uptake |
| **Baker et al. 2020, RUSP** (OpenAI, arXiv 2011.05373) | Randomised, *uncertain* social preferences (how much I value you) make **reciprocity and team formation emerge** | Team formation rate; welfare in iterated PD |
| So Long Sucker as a MARL benchmark (arXiv 2411.11057) | A game built on temporary coalitions and inevitable betrayal; deep RL learned legal play but only partial strategy | Win rate; legal-move rate |
| AlphaStar league (Vinyals et al. 2019) | **League training**: main agents plus *exploiters* that hunt their weaknesses, so the main agent becomes robust | Elo against the whole league, exploitability |
| **Melting Pot** (Leibo et al. 2021, arXiv 2107.06857; 2.0 in 2211.13746) | Test on **co-players never seen in training** ("background population"). Resident mode: the tested agents are the majority. Visitor mode: they are the minority | Focal per-capita return in held-out scenarios |
| Overcooked-AI, Carroll et al. 2019 (arXiv 1910.05789); Other-Play, Hu et al. 2020 (arXiv 2003.02979) | Self-play agents fail with humans because they invent private conventions. Training against a **human model** or with symmetry-breaking fixes this | Score with a human proxy; **cross-play** (paired with independently trained agents) |
| Axelrod tournaments (1980s) | Successful strategies are **nice, retaliatory, forgiving and clear** (tit-for-tat and variants) | Round-robin score against a diverse field |
| Jaques et al. 2019, social influence | Intrinsic reward for causally influencing others raises coordination | Collective return, influence measured as causal effect |

### C. Theory of mind (modelling others' beliefs and intentions)

| Work | Rule | Measure |
| --- | --- | --- |
| ToMnet, Rabinowitz et al. 2018 (arXiv 1802.07740) | Learn a model of another agent from a few observed episodes | Prediction of the other's next action; **false-belief tests** (Sally-Anne) |
| PsychSim (Pynadath & Marsella), I-POMDP (Gmytrasiewicz & Doshi) | Recursive beliefs about others, **bounded depth** | Fit to human decisions |
| Cognitive hierarchy / level-k (Camerer et al.) | Humans reason only about **1-2 levels** deep (average ≈1.5) [known] | Fit of the level distribution to human choices |
| LLM ToM benchmarks (ToMi, BigToM, FANToM, OpenToM); Ullman 2023 (arXiv 2302.08399) | Passing ToM tests is fragile: **trivial perturbations break it** | Accuracy *and* robustness under perturbation |

### D. Generative and LLM social agents

| Work | Rule | Measure |
| --- | --- | --- |
| **Generative Agents**, Park et al. 2023 (arXiv 2304.03442) | Memory stream, then **reflection**, then planning; each part ablated | **Believability** judged by humans (TrueSkill over interviews); emergent diffusion of information and relationships |
| Park et al. 2024, agents of 1,000 real people [verify] | Agents seeded from interviews | Replication of the people's own survey answers, normalised by their test-retest consistency (~85%) |
| **SOTOPIA**, Zhou et al. 2024 (arXiv 2310.11667) | Social goals in role-play | Seven dimensions: goal completion, believability, knowledge, **secret keeping**, **relationship**, social rules, financial. LLM judge validated against human raters |
| **MACHIAVELLI**, Pan et al. 2023 (arXiv 2304.03279) | Reward-driven agents become power-seeking and deceptive | Counts of deception, power-seeking and ethical violations, plotted **against reward** (a Pareto front) |
| **The Traitors**, 2025 (arXiv 2505.12923) | Hidden minority of deceivers | Deception success, **trust network stability** (vote consistency across rounds), collective inference. Stronger models deceive better *and* are easier to manipulate |
| DSGBench [verify]; Elimination Game (lechmazur) | Cross-game strategy | **Betrayal rate**, alliance formation and **duration**; "buddy betrayal" from both sides; TrueSkill |
| Werewolf, Avalon, Secret Hitler agents (2023-26) | LLM reasoning plus RL for action choice | Win rate against diverse opponents; deduction accuracy; deceptive consistency |

### E. Cognitive architectures for social NPCs

FAtiMA (OCC appraisal → emotion), CiF / Prom Week (social exchanges chosen by volition over social facts), Versu
(social practices). These are evaluated mainly through player-perception studies. OGameX already wraps FAtiMA,
PsychSim and CiF behind `SocialCognition`.

### F. Human-likeness testing in games

- BotPrize (Unreal Tournament, 2008-12): judges rate "humanness". The winners passed by copying human *imperfection*
  (hesitation, errors), not by playing better [known].
- **Classifier two-sample test** (C2ST, Lopez-Paz & Oquab 2017): train a classifier to separate bot traces from
  human traces. AUC ≈ 0.5 means the two are indistinguishable. This is what our HDS/AUC already is.

### G. Human social-science anchors (real-world regularities a believable society should show)

| Regularity | Source | Usable as a band? |
| --- | --- | --- |
| **Structural balance** in signed networks ("my enemy's enemy is my friend": balanced triads dominate) | Heider; Cartwright & Harary; observed in the **Pardus MMO** (Szell, Lambiotte & Thurner, *PNAS* 2010) | Yes: share of balanced triads in the ally/enemy graph |
| Friend and enemy networks differ: friend ties are reciprocal and clustered, enemy ties are not | Szell & Thurner 2010 (Pardus) | Yes |
| Group size layers ~5 / 15 / 50 / 150 | Dunbar | As a prior on alliance core sizes |
| **Endgame effect**: cooperation collapses near a known end | Iterated PD experiments (Selten & Stoecker; Andreoni & Miller) | Yes: betrayal timing |
| Trust game: people send about half and return about as much; ultimatum offers 40-50%, low offers rejected | Berg et al. 1995; Güth et al. 1982 | As a sanity range for trust and fairness |
| Indirect reciprocity: reputation drives who gets help | Nowak & Sigmund | Yes: help goes to the well reputed |

## 2. The rules the field converged on

1. **Anchor to humans.** Pure self-play or reward maximisation drifts into alien conventions and exploitative
   behaviour (piKL, Cicero, Overcooked, MACHIAVELLI). Keep a KL or constraint to a human-grounded policy.
2. **Evaluate against strangers.** Self-play scores mislead. Measure against held-out populations (Melting Pot),
   cross-play and a league that contains exploiters and betrayers (AlphaStar).
3. **Intent first, language second.** Decide the action and the commitment, then render text from it (Cicero). Check
   that what the agent says matches what it then does.
4. **Mostly honest, rarely lying, and only when it pays over the long game.** Repeated games punish liars (Cicero,
   Kramár). Deception is a low-rate tool, not a style.
5. **Alliances need commitment plus sanction.** Without contracts, reputation or punishment of betrayers, learned
   agents do not form stable alliances (Hughes 2020, Kramár 2022).
6. **Shallow, explicit theory of mind.** Model others one or two levels deep, the way humans do, and test the model
   under perturbation (level-k, Ullman).
7. **Memory and reflection give consistency.** Who did what to whom must persist and shape later choices
   (Generative Agents).
8. **Show the trade-off, not one number.** Plot strength against human-likeness or ethics as a Pareto front
   (MACHIAVELLI).
9. **Validate the judge.** A judge (an LLM rater or a classifier) must first agree with humans or with ground truth
   (SOTOPIA).
10. **Social preference diversity creates social structure.** Uncertain or varied preferences lead to reciprocity and
    teams (RUSP), the same way personas do.

## 3. The yardstick catalogue (game-independent)

| Axis | Measures |
| --- | --- |
| Strength | Win or rank against a held-out population; Elo/TrueSkill across a league; exploitability |
| Generalisation | Return with unseen co-players (resident and visitor); cross-play gap |
| Human-likeness | C2ST AUC on behaviour traces; human judge detection rate; distribution match to human bands |
| Social structure | Group size distribution; concentration; structural balance share; reciprocity; clustering of friend vs enemy ties |
| Trust dynamics | Alliance duration; trust network stability; trust repair time after harm |
| Betrayal | Rate; **timing** (endgame, partner weakness); **predictability** from prior signals; retaliation rate; forgiveness lag |
| Deception | Rate; success; detection by others; message–action consistency |
| Commitments | Promise-keeping rate; contract uptake; sanction rate after defection |
| Leadership | Role emergence; follower retention; success of coordinated operations the leader calls; succession |
| Theory of mind | Accuracy predicting others' next action or stance; robustness under perturbation; depth used |
| Cost of the social layer | Strength with versus without it (ablation); welfare |

## 4. What does not transfer cleanly

- Cicero and piKL had **human action data** (webDiplomacy). We do not. Our anchor must be outcome bands plus a
  teacher, not BC on human moves (as `NORTH-STAR-PLAN.md` already says).
- LLM social benchmarks measure **language**. Our first need is **decisions** (who to join, attack or spare).
  Language comes later, from intents.
- Several 2025-26 benchmark numbers are from aggregators. Mark them [verify] before using any.

## 5. My proposed anchors for us (before OGame mapping)

Three north-star gauges, all three required (a Pareto view, never one number):
1. **Strength**: rank or survival against a held-out league that includes betrayers and exploiters (Melting Pot
   visitor/resident, AlphaStar league).
2. **Indistinguishability**: C2ST/HDS AUC → 0.5 on social features, plus social-structure bands from real data.
3. **Integrity**: say–do consistency and promise-keeping are high, and deception is a low-rate tool (Cicero rule).

Behavioural anchors I would hold the agent to:
- **Axelrod profile**: cooperates first with peers, retaliates when harmed, forgives after real amends, and is
  readable.
- **Betrayal is rare, purposeful and timed**: it happens when the partner is weak or the end is near (endgame
  effect), and it shows harbingers first (Niculae).
- **Structural balance** holds in the ally/enemy graph.
- **Alliances rest on commitment plus sanction plus reputation** (Hughes, Kramár, Nowak).
- **Theory of mind level 1-2**, built from visible information only (our no-cheating rule).
- **Intent → action → text**, with text never the decision.
- **Training anchored** (KL or constraint to the teacher and the bands) **in a league** with diverse social
  preferences (RUSP-style personas), never pure self-play.

Search sources used today: arXiv 2505.12923 (The Traitors), arXiv 2411.11057 (So Long Sucker), arXiv 2003.00799 (Hughes
2020), arXiv 2011.05373 (RUSP), arXiv 2107.06857 and 2211.13746 (Melting Pot), the DSGBench summary on emergentmind.com
[verify]. Everything else is from prior knowledge; the arXiv ids above are the ones I am sure of.
