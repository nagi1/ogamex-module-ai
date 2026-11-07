# What proven game AIs on modest hardware actually do (3 Nov 2026)

Owner asked to stop all runs and read what works before planning again. Everything below is from the sources
listed; where a source was a secondary summary or the primary text could not be read, it says so.

Our machine: 72 threads (61 usable under the cap), 55 GB RAM, RTX 3090 24 GB + RTX 4070 Ti Super 16 GB.

## 1. Cases on comparable hardware

| Game | Result | Method | Compute | Source |
| --- | --- | --- | --- | --- |
| microRTS (full RTS) | beat every past competition bot, 91% win rate | PPO, full invalid-action masking, action decomposition, IMPALA-CNN, mix of fixed scripted opponents; shaped reward (+win, +resources, +units) | 1 GPU, 3 vCPU, 16 GB, ~63 h, 300M steps | Huang et al. 2021, arXiv 2105.13807 |
| Lux AI S3 (2025), 2nd place | 2nd of the field | PPO, gamma 0.9999-1, **win/loss reward only**, self-play latest-vs-latest, **env rewritten in Rust** | Ryzen 9950X, 64 GB, **RTX 3090 + 2070 Super**, 8 days, ~10M steps | zenn.dev summary (secondary) |
| Lux AI S3, 1st place | 1st | IMPALA, big ResNet+ConvLSTM+Transformer, win/loss only, opponents mixed from old and new checkpoints, KL loss against forgetting, imitation of 2nd-place replays early then dropped | 8x H100, 3-4 days (not our class) | same (secondary) |
| Lux AI S3, 10th | gold | PPO, 75% self-play + 25% prioritized fictitious self-play | Ryzen 7950X, RTX 4090, 7 days | same (secondary) |
| Lux AI S3, 3rd | 3rd | imitation learning from top replays; beat the author's own rule bot | not read (page body not retrievable) | Kaggle writeup title only |
| Lux AI S1 (2021), 1st | 1st | IMPALA + UPGO + TD(λ); shaped reward for the first 20M steps only, then win/loss | not found | Kaggle writeup, speakerdeck summary |
| DouDizhu (DouZero) | 1st of 344 on Botzone | Deep Monte-Carlo self-play, no search, no human data | 1 server, 48 cores, 4x 1080Ti; beat heuristics in 0.5 day, all programs in ~10 days | Zha et al., ICML 2021 |
| Hungry Geese (Kaggle) | 1st of 875 | MCTS with a learned evaluation, ensembled with a NN; places 2-5 also used search over a learned model | not found | speakerdeck summary (secondary) |
| **Kore 2022 (Kaggle) — economy, shipyards, fleets in space** | 1st, 4th, 5th, 20th | **rule-based**, forward simulation of fleets, route scoring, threat accounting; no RL in the top | CPU, 3 s per move | 1st-place thread (body not readable), 4th-place repo, 20th writeup |
| Lux AI S2, 1st | 1st of 646 | not stated; repo has a look-ahead length setting (FUTURE_LEN), suggesting forward simulation (unconfirmed) | — | github ryandy/Lux-S2-public |
| StarCraft build orders | professional-quality build orders in real time | depth-first branch-and-bound search over a build-order simulator (BOSS), used by all major StarCraft bots | CPU | Churchill & Buro 2011 |
| Freeciv (CivRealm) | RL fine on mini-games, **RL and LLM agents both fail on the full long game** | PPO baselines, LLM agents | — | ICLR 2024 |
| StarCraft II (AlphaStar) | Grandmaster | supervised on 971k human replays first, then RL league with a KL anchor to the human policy | thousands of TPUs (not our class) | DeepMind 2019 |

## 2. What the winners have in common

1. **A fast simulator and many samples.** The learned winners ran 10M to billions of decisions. The Lux S3 2nd place,
   on our hardware class, first rewrote the game in Rust and trained for 8 days.
2. **Reward is the outcome itself** (win/loss, or final score), sometimes with shaping only to bootstrap and then
   removed. A Lux S3 author names shaped rewards as the main reason his RL failed. microRTS notes agents overfit shaped
   rewards.
3. **Opponents that adapt.** Self-play against current and past versions, or a mix of scripted bots. Not one frozen
   world.
4. **Masking illegal actions** and a decomposed action space (all teams).
5. **Start from imitation when good replays exist** (AlphaStar, Lux S3 1st/3rd), then improve with RL.
6. **In economy/build-order games with a known simulator, search wins.** Kore (the closest game to OGame we found),
   StarCraft build orders and Hungry Geese were won by forward simulation or tree search, not a pure learned policy.
7. **Long full games are where learning fails** (CivRealm). Winners shorten the problem: short horizons, mini-games,
   value bootstrapping.

## 3. How we compare

- **Samples.** An ES generation is about 60 one-year seasons and takes about 22 minutes on the whole CPU budget. That
  is orders of magnitude fewer decisions than any learned winner above. ES gains came in +0.9% steps and plateaued,
  which is what this budget predicts.
- **Reward.** We use final score against a fixed scripted world. That matches point 2. The realism reward we tried was
  shaping, and it was gamed, as point 2 warns.
- **Opponents.** All other accounts are scripted and do not adapt (point 3 is missing).
- **Imitation.** We imitate our own scripted teacher, not human players. The real-player data we hold is aggregate
  (scores, planets, battles), not per-action replays, so the AlphaStar route to "plays like a human" is not open to us
  as it stands.
- **Search.** We proved headroom by search once (the best of 2048 build orders beat the rule by +17%), then trained
  learners instead of using search. Point 6 says search is what wins this kind of game.

## 4. Recommended direction (for the owner to approve before anything restarts)

1. **Search-based player first, learning second (Expert Iteration, as in AlphaZero and the Kore/StarCraft winners).**
   Build a look-ahead planner that, at each decision, tries the candidate builds in copies of the simulator for a
   short horizon and picks the best by net worth. Measure it against the ES model on the judge seeds. Then train the
   network to imitate the planner (cheap at play time), and repeat. This needs the sim to clone a world state; check
   that first.
2. **Short episodes instead of whole seasons.** Start training from saved mid-game states and score a fixed horizon
   with a value estimate at the end. This multiplies the samples per CPU-hour, which is the gap in section 3.
3. **Measure throughput before choosing.** Decisions per second of the sim per core. If PPO-style learning needs
   ~10M+ decisions (Lux S3 2nd place on our hardware class), compute how many days that is for us.
4. **Keep the reward as the outcome** (net worth / final score). Human-likeness stays a constraint measured against the
   real bands, not a shaped reward.
5. **Adaptive opponents later.** Once a learned player beats the script, let part of the world play past versions of
   it.

## Sources

- Gym-μRTS: https://arxiv.org/abs/2105.13807 (HTML v3 read in full)
- Invalid action masking: https://arxiv.org/abs/2006.14171
- Lux AI S3 RL solutions summary: https://zenn.dev/kurupical/articles/61dbeedf89a29d
- Lux AI S3 standings: https://clist.by/standings/neurips-2024-lux-ai-season-3-games-artificial-intelligence-reinforcement-learning-custom-metric-56115841/
- Lux AI S3 3rd place: https://www.kaggle.com/competitions/lux-ai-season-3/writeups/adg4b-imitation-learning-3rd-place-solution
- Lux AI S1 1st place: https://www.kaggle.com/competitions/lux-ai-2021/writeups/toad-brigade-toad-brigade-s-approach-deep-reinforc
- Lux AI S2 1st place: https://github.com/ryandy/Lux-S2-public
- DouZero: https://proceedings.mlr.press/v139/zha21a.html
- Hungry Geese summary: https://speakerdeck.com/nagiss/kagglesimiyuresiyonkonpenodong-xiang?slide=27
- Kore 2022 1st: https://www.kaggle.com/competitions/kore-2022/discussion/340035 ; 4th: https://github.com/qihuazhong/kore-2022 ; 20th: https://www.kaggle.com/competitions/kore-2022/writeups/all-your-base-20th-place-solution-rule-based-econo
- Build-order search: https://ojs.aaai.org/index.php/AIIDE/article/view/12435
- CivRealm: https://proceedings.iclr.cc/paper_files/paper/2024/hash/76d2f8e328e1081c22a77ca0fa330ca5-Abstract-Conference.html
- AlphaStar: https://deepmind.google/en/blog/alphastar-grandmaster-level-in-starcraft-ii-using-multi-agent-reinforcement-learning/
