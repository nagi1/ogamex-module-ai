# Handoff — 4 November 2026, evening

Supplements `plan/rl/WAR-SIM-HANDOFF-2026-11-04.md` and `plan/rl/STRONG-PLAYER-HANDOFF.md`; the dated log is
`plan/rl/STRONG-PLAYER-PLAN.md` (read its last two entries first). Goal and owner constraints are unchanged from the
war-sim handoff: faithful late-game war in `rl/ogsim`, root cause before tuning, no run over ten minutes
(`rl/scripts/capped.sh` + `timeout 540`, 85% CPU/RAM caps), GPU at `cuda:1` with `CUDA_DEVICE_ORDER=PCI_BUS_ID`,
preserve artifacts, commit only named files when asked. Nothing in this session is committed.

## What this session did

Both items are written up in the plan log; the short version:

1. **The version-2 errand schema was validated end to end on real artifacts** (season labels → CPU behaviour-clone
   checkpoint → `train_exit` → `export_policy` → season flies the policy), not on synthetic tensors. Four refusals
   were exercised (sidecar version 1, no sidecar, sidecar without a width, checkpoint without the new feature name)
   plus a control that trains.
2. **That exercise found the champion-errand label path was broken**: `train_exit` inferred the candidate width from
   an always-legal last row, which holds for build labels (wait last) but never for errand labels (wait first,
   padding zero), so every `champion-errand-out=` file needed a hand-passed `--k 32`. The writer now puts the width
   in the sidecar (`{"errand_feature_version": 2, "candidates": 32}`) and `train_exit` reads it there.
3. **The errand loop could not run at all**: `VecEnv.planet_count` returned `Catalogue::planets.len()`, and a moon
   catalogue appends moon configs after `first_moon` (24 homes + 24 moons), so moon indices were handed out as home
   planets and every `VecEnv`/`VecWorld` caller panicked — `train_bc_world` (non-season), `train_world`,
   `train_ppo`, `transfer`, `econ.py`. It now returns `first_moon`. The Python extension was rebuilt.
4. **The loop then ran end to end and FAILED**: BC 40,000 rows at 98.2% teacher agreement, then PPO value versus the
   scripted teacher −0.30% (95% CI [−0.54%, −0.08%]), 45% of 256 accounts better, 2% of 94,384 decisions differing,
   worst account −12.44%, settled at update 111 of 111. Deliberately shortened (ROWS 40,000 / MINUTES 3 / ACTORS 8 /
   WORLDS 16 against the script defaults 120,000 / 6 / 16 / 32), so read it as "the loop runs again", not as a
   measurement.

## Files changed (all uncommitted, on top of pre-existing work)

- Code: `rl/ogrl/train_exit.py`, `rl/ogsim/examples/season.rs`, `rl/ogsim/src/python.rs`.
- Docs: `plan/rl/STRONG-PLAYER-PLAN.md`, `plan/rl/errand-spec.md`, `plan/rl/WAR-SIM-HANDOFF-2026-11-04.md`.
- Binaries rebuilt: `.venv-rl` extension (`maturin develop --release` from `rl/ogsim`) and
  `rl/ogsim/target/release/examples/season`.
- The rest of `git status` (players/world/strike/season/errands `players.rs` war work, the Python schema guards,
  `late.rs`) is the earlier session's uncommitted work, untouched here. The host repo still shows only its
  pre-existing `.gitignore` change and untracked `storage/`.

## Evidence and limits

- Rust suite green: 107 tests (`cargo test --release` in `rl/ogsim`).
- Schema round trip: `/tmp/e2e/` (labels `labels4.f32` + sidecar, `errand-bc.pt`, `exit4.pt`, `errand.json`) and
  logs `/tmp/e2e-*.log`. Temporary — they disappear on reboot.
- Loop output: `storage/rl/ogsim/runs/loop4nov/` (`bc.pt`, `ppo/config.json`, `ppo/checkpoint-*.pt`,
  `ppo/eval.jsonl`, `ppo.log`). The historical `runs/loop` is owned by the container user (`www-data`) and cannot be
  overwritten from the host — always pass `OUT=` at a directory you create.
- Not shown: no model was retrained from champion **errand** labels, no old labels or economy checkpoints were
  migrated (they stay rejected by design), no late-game benchmark ran after the research-information change, and no
  host parity work was done.
- Nothing is running now: no `rl-*` units, no cargo/python processes, `rl.slice` idle.

## Recommended next work

1. **Re-run the loop at its reference settings** (defaults: ROWS 120,000, MINUTES 6, ACTORS 16, WORLDS 32) and record
   the verdict with its CI, judged by `.claude/skills/rl-diagnostics/SKILL.md`. The previous "loop passes" claims
   predate the moon catalogue; nothing has run since. Do not tune anything until a full-length run exists.
2. **Smoke the other `planet_count` callers** (`train_world`, `train_ppo`, `transfer`, `econ.py` non-season path) —
   they were all broken by the same bug, so no economy data from those paths can be trusted to have been generated
   after the moon merge. Check whether any existing dataset was made with a non-moon catalogue before reusing it.
3. **Audit the remaining hidden-state reads** (war handoff item 2): `raid_plan`, the cargo-ACS helper and missile
   targeting still read live target research; allied defence and moon-shot planning read ally state. Separate shared
   information from omniscience, and test that changing unseen enemy state does not change a decision.
4. **Capped, reproducible late-game benchmark** on ≥3 seeds after the research-information change, recording report
   depth, raid/ACS outcomes, fleet losses and real attacker/defender identities before attributing any leader fight
   to an alliance (war handoff item 3).
5. **Decide the champion-errand label budget.** The path now works, but a label costs ~70 rollout sets a late day;
   pick a day range and `champion-every=` stride before starting a long round, then train a student with
   `train_exit --init <errand checkpoint> --labels <file>`.

## Useful paths

- Simulator `rl/ogsim/` (`src/season.rs`, `src/players.rs`, `src/world/strikes.rs`, `examples/season.rs`,
  `examples/late.rs`); trainers `rl/ogrl/` (`train_bc_world.py`, `train_exit.py`, `train_world.py`,
  `export_policy.py`); loop `rl/scripts/errand-loop.sh`, row runner `rl/scripts/row.sh`, caps
  `rl/scripts/capped.sh`.
- Reproduction of the schema round trip: `train_bc_world --season --errands --rows 200 --accounts 2 --horizon 48
  --device cpu` (≈1 s) gives an errand checkpoint; a 10-day/10-account season with `champion=1:0:2:3
  champion-errands champion-errand-out=FILE` gives labels with 169 of 3,043 logins carrying two or more legal
  errands.
- Preserved data: `storage/rl/ogsim/r8w`, `runs/train4` — do not delete or overwrite.
