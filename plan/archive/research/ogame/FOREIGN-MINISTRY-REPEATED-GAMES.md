# Foreign ministry: a repeated-game design (T7, item 4 of GAME-THEORY-EVOLUTION-FOR-TRAINING.md)

Written 2026-11-05. Design only. Evidence on real OGame diplomacy is in `DIPLOMACY-BETRAYAL.md` (cited as DB §n). The
late-game frequencies are in `LATE-GAME-TACTICS-USAGE.md` (LGT). The gates are in `Modules/AI/AGENTS.md`. The model
layout (one shared encoder, ministries as heads, a router later) is in `plan/rl/STRONG-PLAYER-PLAN.md` ("5 Nov, owner
idea"). The sim already has `alliances=N`, ACS defend (`World::defend`) and ACS attack (`World::join`):
`plan/rl/WORLD-SIM-PLAN.md` L5.

## 1. What game theory says, and what it means for OGame
| Result | Source | Consequence for the ministry |
|---|---|---|
| Folk theorem: in an infinitely repeated game, any outcome that is individually rational can be sustained when the continuation probability δ is high enough. For a prisoner's dilemma the condition is δ ≥ (T−R)/(T−P). | Fudenberg & Maskin 1986, *Econometrica* 54:533 | OGame universes have no announced end, so δ is close to 1 and honouring a NAP is an equilibrium. **Our 365-day season is a fake horizon.** Hide it from the model, or the model will learn end-game defection that real players cannot use (§4). |
| End-game unraveling: in finitely repeated PDs, people cooperate until a threshold round. With experience, the first defection moves earlier. | Embrey, Fréchette & Yuksel 2018, *QJE* 133:509 ([pdf](https://faculty.econ.ucsb.edu/~sevgi/EmbreyFrechetteYuksel_June2017.pdf)) | The only real OGame "end" is a player quitting. The legal end-game act there is gifting loot to a mate (DB §4, FR board). Merges are announced, but no source shows defection before a merge (searched, NOT FOUND). |
| TFT is nice, retaliatory and forgiving (Axelrod 1984). Under noise, generous TFT beats TFT when others have not adapted to noise (Wu & Axelrod 1995, [link](https://deepblue.lib.umich.edu/handle/2027.42/66566)). WSLS/Pavlov corrects errors and exploits AllC (Nowak & Sigmund 1993, *Nature* 364:56, [pubmed](https://pubmed.ncbi.nlm.nih.gov/8316296/)). | as cited | OGame is noisy. A mate's moon shot, a misread probe or an attack by a non-tagged wing all look like defection. The head needs per-counterpart memory (last outcome, mine and theirs) so that it *can* represent TFT, GTFT or WSLS. We do not hardcode any of them. They become **opponents** in the pool. |
| Indirect reciprocity: cooperation through image or standing is stable only when the probability of knowing a reputation is greater than cost/benefit (Nowak & Sigmund 1998, *Nature* 393:573, [repec](https://ideas.repec.org/a/nat/nature/v393y1998i6685d10.1038_31225.html)). Ohtsuki & Iwasa 2004 ("leading eight") show that justified punishment must not cost the punisher standing. | as cited | OGame enforces NAPs **only** by reputation: "no official diplomacy interface that enforces treaties", and breaking a NAP brings "community ostracism ... permanent loss of reputation" ([Gameforge](https://gameforge.com/en-GB/games/browser-game-with-diplomacy.html); DB §2, §4). The sim needs one reputation channel, a grudge broadcast (§3), or betrayal stays free. |
| Sanctioning: in Diplomacy, agents that punish contract breakers made a learned deviator honour more than 99.7% of contracts. The deviator still broke some late in the game. | Kramár et al. 2022, *Nat. Commun.* ([PMC9726752](https://www.ncbi.nlm.nih.gov/pmc/articles/PMC9726752/)) | This is our pool design: sanctioners (grudge-holders) sit in the pool, and the target "surprise NAP break ≈ 0" should emerge from training, not from a mask. |
| Pure self-play fails when the agent must cooperate with humans. Regularising RL toward a human-imitation policy (KL, piKL) gives top human-level play. | Bakhtin et al. 2022, [arXiv 2210.05492](https://arxiv.org/abs/2210.05492); CICERO, Meta 2022 ([pdf](https://noambrown.github.io/papers/22-Science-Diplomacy.pdf)), which predicts others' intents from dialogue; Anthony et al. 2020 BRPI, [arXiv 2006.04635](https://arxiv.org/abs/2006.04635) (fictitious play) | Reward = own interest + a KL anchor to the rule prior (DB "What the foreign ministry should do" J1–B4). CICERO was still found to lie and break deals ([Park et al. 2023, arXiv 2308.14752](https://arxiv.org/abs/2308.14752)), so this measure counts betrayals; it does not assume honesty. |
| Coalitions: the size principle says a minimum winning coalition is enough (Riker 1962). | Riker, *The Theory of Political Coalitions* | Real alliances are small: median 1 active member, p90 5, p99 20. Players join "slightly above your level" (DB §1). So the join choice is "smallest alive alliance at or above my points", not "biggest". |

## 2. What it decides
- **Actions** are typed candidates (action kind, counterpart), scored by one small head, the same seam as the other heads.
  - **Kinds:** none; apply to alliance; accept or deny an applicant (leader); leave alliance; kick an inactive member (leader); buddy request or accept; propose, accept or cancel a NAP (cancelling starts a 24–72 h notice); call ACS defence; answer a call (the fleet head sizes the hold); share an espionage report with mates; broadcast a grudge.
  - **Betrayal:** "betray" is **not** a separate kind. It is an ordinary attack on someone who is still a mate or still inside a NAP notice window. The fleet head can choose it, and the ministry's cancel or leave can make it legal later.
- **Gate 1:** counterparts are players or alliances, never objects. No game object is named.
- **Hard constraints are masks, not penalties** (DB §6 C1–C6):
  - bash limit read from host `bashlimit` (6 per planet or moon per 24 h);
  - no transfer that pushes value from a lower-ranked to a higher-ranked account;
  - trades, splits and help settled within 72 h at 3/2/1–2/1/1;
  - one account per identity, so AI accounts never ACS, trade or attack among their own;
  - no sitting-style actions.
- **Gate 2:** no new class. It is one head plus one message kind in the sim.

## 3. Inputs (only what a real player sees: no-cheating rule, unseen-state invariance test)
- **Own state:** the shared encoder.
- **Public tables:** highscore rank and points; galaxy status flags `i`/`I`/`v`/`b`; alliance pages (active members, founded day, open or closed, median points).
- **Social state:** own buddy list and own pact list with their notice clocks.
- **Received messages:** applications, ACS calls, grudge broadcasts.
- **Own reports:** espionage and combat reports, including reports forwarded by mates.
- **Per-counterpart memory** (the last 3 interactions: helped / raided / spied, each side) and a received-reputation flag. Reputation only travels by grudge broadcasts from the victim's alliance to its pact partners. This sets the "probability of knowing" from Nowak–Sigmund.
- **Never:** another account's hidden fleet, intent or policy, or **days until season end**.
- **Test:** perturb other accounts' unseen state and require identical ministry choices.

## 4. Reward and training (ES / league, `GAME-THEORY-EVOLUTION-FOR-TRAINING.md` T3–T5)
- **Reward:** the same account value as the other heads (gate 3: diplomacy is instrumental), minus β·KL(head ‖ rule prior). There is no bonus for being "social". β is tuned in S2.
- **Horizon:** each season's length is drawn at random (geometric tail after day 200, mean as today), and the reward is evaluated at that cut. This gives δ < 1 without a visible end. The end-game check in §5 then measures whether defection still clusters near the end.
- **Order:** BC on the rule prior first. Then ES on the ministry head only, with the encoder and the other heads frozen. The head is small, so ES works in a low dimension, with antithetic pairs on common seeds.
- **Pool** (needs T3 per-account policy):
  - scripted ministries: AllC, AllD, Grim/sanctioner, TFT, GTFT (forgives with p=1/3), WSLS, end-defector (defects after day X), and the rule prior;
  - frozen checkpoints and a hall of fame (Anthony 2020; AlphaStar);
  - exploiters: a short ES run rewarded only for surprise breaks against the current ministry.
- **Payoff matrix** (T4): the mean account value of row against column in `alliances=N` seasons, with CIs, then Nash averaging or α-Rank.
  - **Check first** that OGame neighbour play is really a PD, i.e. T > R > P > S, over 7-day pair windows:
    - R: both keep the NAP;
    - T: I raid a partner who does not raid back;
    - S: I am the one raided;
    - P: we raid each other.
  - If P < S (raiding a defended neighbour loses value), the game is a stag hunt or harmony game. Then the ministry is about coordination (joining, ACS), not about policing betrayal, and §5 M5 shrinks.

## 5. The measure: plays like real players (bands from our real data; floors block)
| # | Measure | Real band (source) |
|---|---|---|
| M1 | In an alliance, by rank band (top10 / 11–100 / 101–500 / 501–1000 / rest) | old universes 98/93/74/63/40%; new 96/90/70/49/10% (DB §1, API 70 universes) |
| M2 | Alliance size among active players | median 1, p90 5, p99 20; 29% have ≥ 2 (DB §1) |
| M3 | Founding and joining timing | 57% of lasting alliances founded by day 30, median day 23.5; account applies by day 30 (DB §1, J1) |
| M4 | Share of ACS hits, and who joins them | about 6% of hits (326/5,402 showcase CRs, LGT §7); 100% of ACS partners are mates or buddies |
| M5 | Surprise breaks (attack on a mate or NAP partner before notice) | taboo; no real rate exists (DB §4 NOT FOUND) → floor ≤ 0.3% of pacts (the 99.7% sanctioning benchmark) |
| M6 | Membership by status | active 62%, `v` 51%, `i` 26%, `I` 17% (DB §5) |
| M7 | Weekly switching rate | NOT FOUND. Get it from a second `players.xml` snapshot about 7 days after 2026-11-04 (DB gaps) |
| G1 | Game-theory sanity | not exploited by AllD beyond round 1; cooperates with TFT/GTFT; restores cooperation within 2 interactions after a noise hit; betrayal hazard flat over season time (no end spike) |
| F | Floors | 0 bash or pushing breaches; HDS not down beyond the 3-seed spread; value vs teacher ≥ −5% (CI) (`plan/rl/NORTH-STAR-PLAN.md`) |

## 6. Stages (each has a go/no-go in ≤ 15 min, nothing over 1 h until confirmed, all after Stage D)
| Stage | Work | Go / no-go (≤ 15 min) |
|---|---|---|
| S0 | Payoff table from the sim: scripted ministries, `alliances=4`, 3 seeds, days 0–150 | T/R/P/S measured with CIs; the PD or stag-hunt verdict written down; AllD beats AllC; the rule prior lands M1–M4 in band or the gap is named |
| S1 | Sim: grudge broadcast and NAP notice clocks; randomised horizon; ministry candidates exported | Same-policy run identical to today with the flags off; invariance test passes |
| S2 | BC of the ministry head on the rule prior | Held-out agreement within 2 pts of the economy head's BC; M1–M6 no worse than the prior; floors hold |
| S3 | ES on the head only, pool = scripted + C4/g14 + prior, KL β sweep (3 values) | Paired value vs prior CI ≥ 0; M5 ≤ floor; G1 holds |
| S4 | Exploiter (surprise-breaker), then retrain the ministry against it | Exploiter's edge shrinks after retrain (as in Kramár 2022); M5 still ≤ floor |
| S5 | Payoff matrix + α-Rank over checkpoints; replicator shares vs real membership (T2) | No dominated cycle that includes the shipped head; then one ≤ 1 h long run read on M1–M7 |

Stop rule: a stage that misses its measure stops the track. No new kind and no new class is added to rescue a stage.
