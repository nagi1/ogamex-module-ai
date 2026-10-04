# Improving the model (in this order)

Change one thing at a time, keep everything else fixed (seeds, data, encoder version), and compare with the
closed-loop twin test. Record every attempt in `plan/rl/next-steps.md` even when it fails.

## 1. More and better data (cheapest)

- More universes beat more days of the same universe (diversity of starts, positions, personas).
- Mix economy speeds (`ai:rl-universe --speed=4|8|16`) and starting points (fresh vs. a saved day-30 state:
  `ai:sim --save-sqlite` then use the file as the next source).
- `--choice-policy=epsilon --choice-epsilon=0.1`: the state distribution covers recoveries from non-teacher
  choices; the label stays the teacher's.

## 2. DAgger (fixes "good offline, bad in the game")

1. Serve the current model, run `ai:sim --choice-policy=socket --learner-share=1.0 --record-choices=...`.
2. Every recorded row still carries the planner's `teacher` index for the state the model reached.
3. Add these files to the training data and retrain. Repeat 2–3 rounds; closed-loop ΔV should converge to 0.

## 3. Features and ablations

- Add one feature (features.md), bump the version, regenerate, retrain, compare `top1_3plus_legal` and
  closed loop. Keep it only if both do not get worse.
- Ablate: zero one feature group at train time (state groups or candidate groups) to see what the model uses.
  `order` and `pass_*` encode the planner's priority; a model leaning only on them is copying the list order.

## 4. Model and loss

- Width 256 → 512, depth +1; dropout 0.1; label smoothing 0.05; longer training with cosine LR.
- A pairwise ranking loss (teacher row vs. each other legal row) when top-1 stalls but MRR is high.
- Per-archetype weighting if one archetype dominates the data.
- Keep the forward signature `(state, cands, mask) -> (logits, value)` so serving and export keep working.

## 5. Reinforcement learning (only after both BC gates pass)

Plan: `plan/rl/training-plan.md` §5 and `plan/rl/reward-design.md`.

- Policy: start from the BC network; PPO with a KL penalty to the BC policy decaying over the first 10⁶ choices.
- Acting: `ogrl.serve --temperature 1.0` (seeded sampling) for learner accounts; the recorded `chosen`, the
  logits/log-prob (add to the server reply when building PPO) and `value` per row are the rollout.
- Reward: per account, Δ`value` between consecutive choices, divided by the account's production per hour;
  discount per game hour (γ ≈ 0.995/h), GAE λ 0.95. No hand bonuses for building or raiding.
- Population: 16 universes × 24 accounts, 6 learners each (`--learner-share=0.25`), the rest the planner.
- Success: G-RL in SKILL.md. Stop conditions: `plan/rl/implementation-roadmap.md`.

## 6. A new choice point (shipyard, raid targets, saves, colonies)

1. The planner must produce an ordered candidate list and its own pick, legality from the host's own gates.
2. Add a `choiceSets`-style method beside the existing planner method, never inside its decision path.
3. Encode with host-derived numbers only; give the point its own `kind`.
4. Apply the pick through the existing executor; prove teacher neutrality with a test like
   `EconomyChoiceSeamTest` and a digest comparison.
5. One model with a `kind` one-hot is preferred over one model per point while data is small.

## 7. What not to do

- Do not tune evaluation seeds until a result is significant; do not report validation numbers as play quality.
- Do not add object ids, machine names, other players' live state or language-model outputs as features.
- Do not change the planner to make the model look better; the planner is the baseline.
- Do not start Rust, distributed training or self-play leagues without the gates in `plan/rl/benchmark-plan.md`.
