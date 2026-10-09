# AI research context (canonical brief)

The one shared source of truth for anyone working on the OGameX AI player: the owner, Claude, Codex, the harness and the
reviewer. Short on purpose; details live in the files it links. Update the "Verified results" and "Open experiments"
tables when a run finishes, and nothing else without the owner. Created 10 Oct 2026.

## Research objective

Could an experienced OGame player interact with one of our AI players for weeks, fight it, negotiate with it, become
its ally or enemy, and find its behaviour strategically credible and socially consistent?

Everything below serves that question. Intelligence infrastructure that does not move it is not progress.

## Who works on it

- **Owner:** experienced software engineer (architecture, distributed systems, databases, performance, testing,
  production). New to machine learning: explain loss functions, training, evaluation, leakage, RL and reward design
  with OGame examples when they come up; do not explain engineering basics.
- **Hardware:** RTX 3090 24 GB and RTX 4070 Ti Super 16 GB for training and simulation inference; WSL host with 24
  cores (keep load under ~14, memory under 60%). Production target is separate: CPU-only VPS, ~2 vCPU / 2 GB, ambition
  of 1,000+ accounts. Training is not limited by the production box; production inference is.

## Architecture boundaries (fixed)

| Layer | Role | Rule |
|---|---|---|
| Host (OGameX) | game rules, validation, legality | The AI never restates or overrides a host rule. |
| Deterministic intelligence | planners, doctrine YAML, Rust battle sim, search | Default for every decision; a model replaces it only by beating it in a measured run. |
| Small learned models | economy choice policy (ONNX), text classifiers (distilled) | Behind `app/Domain/Choice` and `DistilledTextModel`; never sees object identity; teacher path stays. |
| Persistent cognition | relationships, memories, commitments, affect | Commitments are game-state constraints planners must honour, not flavour. |
| Jev + external LLM | reading ambiguous human text, writing human-facing replies | Zero generative calls in ordinary play; authored lines first. |

Three gates (`AGENTS.md`): code reads the object catalogue and names nothing, keep it simple, behave like a
fifteen-year player.

## How an experiment is written

Every ML or behaviour experiment gets a record here (or a linked file) before it runs:

- **Hypothesis**: what changes in play, in one sentence.
- **Data**: where it comes from, how it is cleaned, how train and test are split (by world, never by row).
- **Baseline**: the simplest working alternative it must beat (usually the current rule).
- **Measure**: on one of the three axes below, decided before the run.
- **Failure modes looked for**: leakage, weak baseline, reward hacking, overfitting to simulated players, teacher
  mistakes copied.
- **Result and decision**: install, discard or rerun, with the number.

A falling loss or a high offline accuracy is never the result. What the account does in a closed-loop world is.

## Three axes (never blended into one score)

| Axis | Measured by |
|---|---|
| Competence | growth vs real OSTATS curves, saves that succeed, battle loss ratios vs real OGMem battles, every aspect of play reached |
| Human realism | uptime shape, reaction latency to probes and attacks, imperfect saves, varied action sequences |
| Social continuity | commitments kept vs broken, relationships changing what the account does, distinct lines per world, greeting share |

The LLM world judge (`ai:judge-world`) is a reader reported beside these, not a gate; it has not been validated against
human raters.

## Verified results

| Date | What | Number | Source |
|---|---|---|---|
| 6 Oct | Economy BC v4 closed loop vs teacher | +3.8% total value (16 pairs × 12 accounts × 12 days, mostly early game; late rows ~3% of data) | `plan/rl/next-steps.md` §4 |
| 6 Oct | BC v4 offline agreement | top-1 0.921 on 3+-legal-choice states (offline only) | same |
| 9–10 Oct | Late worlds: every ship, defence and mission reached | 9484: ACS 501, recycle 499, missile 189, moon destruction 10, deathstars 14 | `storage/rl/late/9484/reach.txt` |
| 10 Oct | Act classifier, distilled | 0.767 on later worlds, **0.711 on templates it never saw**; acs_call recall 0.00, warning 0.44, pact_break 0.45 (2,866 unique texts); not installed | this file |
| 10 Oct | Act classifier training text | every text is one of our own authored lines labelled by the live classifier; the external data holds no human chat, so the model learns our wording, not human wording | corpus.jsonl `reading` kind |
| 10 Oct | Social corpus cleaned | dialogue 23,445 → 7,498 lines, greetings ~25% → 3.7% | `rl/scripts/social_curate.py` |
| 10 Oct | Fleet scale vs real servers | real battles field thousands of ships per side; our best world peaked at 172 battleships | `plan/details/specs/play-coverage.md` |

## Open experiments

| Experiment | Hypothesis | Baseline | Status |
|---|---|---|---|
| Ceasefire as a commitment (stage 1) | Agreed, enforced 24 h truces lift social continuity: truces kept, raids paused, betrayals remembered | old behaviour: every truce answered "we'll see" | code in (2107e5b); needs a world read |
| Channel greeting guard | Stopping answers to channel pleasantries removes the greeting flood without silencing the channel | 9484: 1,676 greetings of 1,788 channel messages | code in (0b96d44); worlds 9492–9493 running |
| Fleet scale at 50× budget (9487) | A larger staged budget gives real-server fleet sizes and battle losses | 2e11 budget worlds | running |
| Act classifier on human-style text | A distilled act model trained on DeepSeek-written human-style messages (label known by construction) reads human-style text far better than one trained on our own lines | live Jev/LLM classifier; own-lines model | first run done 10 Oct (`ai:write-human-texts`, 1,358 messages, 17 acts): own-lines model **0.174**, human-style-only **0.740**, both 0.681 on 408 held-out human-style lines; own-lines test 0.767 → 0.754 when human lines are added. Not installed: the test text is also model-written. Next: owner's ~100 hand-written lines as the real test, a second writer model for style diversity |
| Live classifier vs distilled, same 151 human-style lines (10 Oct) | The distilled model can replace the live one | live DeepSeek classifier | **No.** live 0.834, distilled (trained on the other lines) 0.709; on the 55 disagreements the live one is right 32 times, the distilled 13. Distilled stays the no-key fallback. The live one's main error is cooperation read as acs_call (6 of 25): the two act descriptions overlap, so the fix is in the descriptions in `ReadAiHumanMessageAction::ACTS`, not in a model. |
| Economy retrain with late rows weighted | A policy trained on a lifecycle run keeps its edge into late game | BC v4 / teacher | held until every aspect of play moves (consolidation stage 4) |

## Where things are

- Work order: `plan/HANDOFF.md`, `python3 plan/tasks/task.py next`.
- Current plan: `plan/details/specs/consolidation-plan.md`.
- Play coverage per aspect: `plan/details/specs/play-coverage.md`.
- Economy RL: `plan/rl/next-steps.md`, skill `.github/skills/ogame-rl-training/SKILL.md`.
- Real-game data: `storage/rl/external/` (OGMem battles, OSTATS series, public API), plan `plan/rl/external-data-etl-plan.md`.
- Judge readings: `plan/research/judge-<seed>.md`.
