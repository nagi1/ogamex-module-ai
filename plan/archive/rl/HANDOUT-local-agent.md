# Handout: training machine (Xeon Gold 6254, 18 cores, 64 GB, RTX 3090)

Copy everything below the line into the local agent.

---

You are working on the OGameX AI module's learned economy policy on the training workstation. Read
`Modules/AI/.github/skills/ogame-rl-training/SKILL.md` and its `references/` first and follow its rules. Design
and reasons are in `Modules/AI/plan/rl/` (start with `README.md` and `next-steps.md`). Work on `main` in both
repositories (host `nagi1/ogamex-next`, module `nagi1/ogamex-module-ai` checked out at `Modules/AI`). Commit
only files you changed, by name; small fixes only. Do not change planner behaviour, the feature encoder or the
model design beyond what a step below asks; if a step needs a bigger change, stop and report it.

## Step 0 — setup

1. `git pull` both repos on `main`. In the host: `composer install`, `bash rust/compile.sh`,
   `php artisan migrate --force`, restart queue workers / php-fpm if they run.
2. Python 3.11+ venv; install the CUDA build of PyTorch that matches the driver
   (`pip install torch --index-url https://download.pytorch.org/whl/cu128` or the right `cuXXX`), then
   `pip install -e Modules/AI/rl`. Check: `python -c "import torch; print(torch.__version__, torch.cuda.is_available())"`.
3. Report: PHP version, PyTorch version, CUDA available yes/no, both commit hashes.

## Step 1 — tests, and fix the small failures

1. Module suite (`bash Modules/AI/scripts/ogamex test` or `vendor/bin/pest --testsuite=Modules`). Known failing
   on `main` before this work (not caused by it): `AiExploitationGuardTest` ×2, `AiOperationsTest`,
   `ColonySpyExecutorTest`, `CoverageCompletionTest`, `ExpeditionDispatchSlotTest`, `SpyDepthTest`,
   `TransferDepthTest`. Fix the ones that are stale expectations or small bugs; for each, one line: cause and fix.
   `EconomyChoiceSeamTest` must pass.
2. Host suite (`vendor/bin/pest tests`); `ModuleDoctorCommandTest` fails when the AI module is enabled in the
   test environment (expected).

## Step 2 — smoke and determinism (15 min)

```bash
php artisan ai:rl-universe storage/rl/smoke.sqlite --accounts=12 --seed=7
for r in a b; do
  DB_CONNECTION=sqlite DB_DATABASE=$PWD/storage/rl/smoke.sqlite php artisan ai:sim --in-memory --native-cognition \
    --seed=7 --hours=24 --from=2026-10-05T00:00:00Z --save-sqlite=$PWD/storage/rl/smoke-$r.sqlite \
    --record-choices=$PWD/storage/rl/smoke-$r.jsonl
  cp Modules/AI/plan/rl/bench/digest.php storage/digest.php
  DB_CONNECTION=sqlite DB_DATABASE=$PWD/storage/rl/smoke-$r.sqlite php storage/digest.php > storage/rl/digest-$r.txt
done
diff storage/rl/digest-a.txt storage/rl/digest-b.txt && echo DETERMINISTIC
wc -l storage/rl/smoke-a.jsonl
```

Expect `0 error(s)`, `DETERMINISTIC`, ~100–200 choice points. If not, use `references/troubleshooting.md`.

## Step 3 — throughput (30 min)

`PHP=php bash Modules/AI/plan/rl/bench/xeon-benchmark.sh 6 <a daytime SIM_NOW of a cohort copy> "1 4 8 12 16 18"`
(or point it at `storage/rl/smoke-a.sqlite` with `DB_CONNECTION=sqlite DB_DATABASE=...` and
`--from=2026-10-05T06:00:00Z`). Report `per_second` per level and pick the parallelism `P` at the knee
(expected ~16).

## Step 4 — behaviour-cloning data (a few hours)

```bash
PHP=php bash Modules/AI/rl/scripts/generate.sh storage/rl/bc 32 30 <P> epsilon 0.1 24
```

32 universes × 30 simulated days × 24 accounts, 10% exploration. Report: wall time, choice points, sim errors
(`grep -L "0 error" storage/rl/bc/sim-*.log` must list nothing). If fewer than 300k choice points, add universes
(`generate.sh storage/rl/bc 64 ...` resumes: existing universes are reused, new seeds are added).

## Step 5 — train (minutes on the 3090)

```bash
python -m ogrl.train_bc --data 'storage/rl/bc/choices-*.jsonl' --out storage/rl/bc-model --epochs 30
```

Report from `storage/rl/bc-model/metrics.json`: parameters, validation `top1`, `top1_3plus_legal`, `mrr`,
`baseline_random_top1`, `wait_rate_model` vs `wait_rate_teacher`, `payback_log_regret`, and the weakest
`top1_by_archetype` / `top1_by_phase` entries. Gate: `top1_3plus_legal ≥ 0.90`, `mrr ≥ 0.93`. If it fails,
follow `references/improving.md` §1–4 (more universes, a wider model) at most twice, then stop and report.

## Step 6 — closed loop (a few hours)

```bash
PHP=php bash Modules/AI/rl/scripts/closed_loop.sh storage/rl/bc-model/model.onnx storage/rl/eval 1001 30 30 <P/2> 0.25
grep -h "fell back" storage/rl/eval/policy/sim-*.log | sort | uniq -c    # must all say 0
```

Report `storage/rl/eval/report.json`: mean relative ΔV, 95% CI, n, share_better, by archetype. Gate: mean
within ±5% and the CI includes 0 or is positive. If the model is clearly worse, run one DAgger round
(`references/improving.md` §2) and repeat steps 5–6 once.

## Step 7 — write it down

Append one row per step to the Results table in `Modules/AI/plan/rl/next-steps.md` (date, both commits, what,
numbers). Commit that file and any small fixes by name, push to `main`. Do **not** start PPO, Rust, or
self-play; stop after step 7 and report.
