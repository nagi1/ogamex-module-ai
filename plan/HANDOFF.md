# Handoff — where the AI module stands and how to keep it moving

Written 1 October 2026 at the end of the course correction. `AGENTS.md` → "Direction" is the standing
rule; this file is the state of play and the order of work. Update the "State" table after every
evidence run; replace the file when the work order changes.

## The loop that keeps the work honest

```
harness / agents  ──write──▶  code + tests  ──prove──▶  task.py done (only if the proof passes)
        ▲                                                     │
        └──── review ◀── evidence run (Copilot) ◀── cohorts play on the new code
```

1. **Work** — the harness (`scripts/harness-live.sh`) and the DeepSeek agents take rows with
   `python3 plan/tasks/task.py next`, following `.github/skills/ai-task-execute/SKILL.md`.
2. **Prove** — a row closes only through `task.py done CODE`, which runs its proof
   (`bash scripts/ogamex prove CODE`). The harness tries this itself every pass.
3. **Evidence** — on the cohort machine, Copilot runs `.github/prompts/cohort-evidence.prompt.md` and
   pushes `plan/research/ogame/evidence/<stamp>/`. Wait the full hour in its step 8.
4. **Review** — the reviewer reads the evidence, fixes what it shows and re-plans this file.

## The north-star gate (enforced, not advice)

Code work is judged by what an account visibly does. A row's proof must contain an `aspect:`,
`situation:` or `invariant:` step, or a `harness:` step for the loop that does the judging. Without
one, `task.py add`, `claim`, `proof`, `next` and `done` refuse the row, the harness queue skips it and
the writer will not pay for it; `strategy-pipeline.py status` lists it as OFF THE NORTH STAR. A wiki
plan must name the scorecard aspect it moves (its ASPECT line) or it is never promoted, and a promoted
row carries that aspect as its proof. Rows that could not name one were frozen on 1 Oct 2026
(`PERS-001`, `DEF-39`, `CAMPAIGN-001`, the reason in each row).

## Tools (all from `Modules/AI`, `export OGAMEX_RUNNER=local-docker-dev`)

| Command | What it answers |
| --- | --- |
| `bash scripts/ogamex scorecard` | every aspect of a player's day, PASS/FAIL, with the owning file |
| `bash scripts/ogamex situation NAME\|all\|list` | plant a situation, let the live workers act, read the answer back |
| `bash scripts/ogamex economy PLAYER_ID` | per planet: building, would queue X, or the gate that refuses it |
| `bash scripts/ogamex prove CODE` | the row's proof steps; `PROOF: PASS` or `PROOF: FAIL` |
| `bash scripts/ogamex test-one Name` | one test file, through the shared test lane |
| `python3 plan/tasks/task.py next \| show \| claim \| lock \| done \| reap` | the ledger; claim locks the row and its files |
| `python3 scripts/strategy-pipeline.py status \| model-check` | harness queue, proven vs delivered, DeepSeek spend today |

`PROVE_UNIVERSE=pve` points the cohort tools at pve instead of grand.

## Reset (1 Oct 2026 15:39 UTC): read this before the State table below

Both cohorts ran at **90,000×** (not the 1000× grand-test protocol), which saturated them: 14
billion metal per planet, full planets, 54 million defence units. Every number in the State table
below was measured on that world and is void. Grand was backed up
(`~/cohort-backups/ogamex-grand-90000x-20261001.sql.gz`) and reseeded fresh at 1000×: one human
account, 20 AI accounts at 1:1–1:9, and 12 inactive neighbours at 1:9–1:14
(`local-docker-dev/seed-inactive-neighbours.php`) so raids have something worth flying for. **pve is
stopped**; it is not an evidence cohort until the harness recovery lands. The harness is stopped
until `plan/HARNESS-RECOVERY.md` W1–W2 are merged. The work order is in that file.

## State (evidence run 2, 1 Oct 2026 12:21 UTC; VOID, measured on the 90,000× world)

| Aspect | grand | pve | Row |
| --- | --- | --- | --- |
| economy (every build queue busy) | situation 0/12 planets | 2/12 | `ECON-001` |
| raids / espionage | FAIL | FAIL | `ATK-001`, `ARB-001` |
| fleet save | situation PASS, ordinary play 0 | same | `FLEET-003` (proactive save missing) |
| recycle | FAIL (situation not yet run) | FAIL | `FLEET-002` |
| social / chat | FAIL (grand backlogged) | PASS | `SOC-001` |
| alliance | FAIL | FAIL | `ALLY-001`, `SIM-001` |
| defence shape | NAKED_BESIDE_WALLED, WALL_CEILING | same | `QUAL-003`, `DEF-001` |

Proven rows: 0. Delivered, awaiting proof: `ECON-001`, `HARNESS-002`, `HARNESS-004`, `QUAL-6`.

## Work order

Strong lane (owner or a strong model — not the unattended writer):

1. **`ECON-001`** — read `scripts/ogamex economy <player>` from the next evidence run. Expected cause:
   the price-plus-reserve gate (`QueueableBuildingPlanner::refusal`) on planets that transfers keep
   draining. Fix the gate or the transfer pressure, not the situation.
2. **`ARB-001`** — the shipyard wins ~70% of sessions from a fixed score table
   (`CandidateActionFactory::features`). Feed the existing components with what the planners
   already compute (loot per hour of flight, debris value, expedition yield).
3. **`ATK-001` + `SIM-001`** — add the `inactive-neighbour` situation (host rule: `users.time` older
   than 7 days) and the `alliance-application` situation (`alliance_applications`: alliance_id,
   user_id, application_message, status 0); then the raid ladder change already decided in ATK-001.
4. **`FLEET-003`** — the proactive save before an absence; the reactive one works.
5. **`AUTH-001`** — the per-account authenticity read (uptime shape, reaction latency, save failure
   rate, growth vs median, action repetition, contacts).

Harness lane (DeepSeek, unattended): whatever `task.py next` returns among the P0–P2 rows that name a
`file_ref`. The writer refuses invented factories, enum values, lint warnings, unread data files,
removed public methods and unwired classes before spending a test run.

## Owner actions still open

- **Host:** `HARNESS-003` — replace `schedule:run --verbose; sleep 60` with `php artisan schedule:work`
  in the host's `docker/entrypoint.sh` (scheduler role), then rebuild the image.
- **Auto-commit watcher (`init-watcher`):** stop it, or limit it to `plan/research/ogame/evidence/`.
  The harness writes code before it verifies it; a commit in that window publishes unverified code.
- **`tasks.db` in git:** both the machine and the reviewer commit this binary file, so every push
  conflicts. Recommended: stop tracking it and keep `seed.sql` (text, mergeable) as the shared record,
  regenerated with `python3 plan/tasks/dump_seed.py`. Awaiting the owner's yes.
- **Log noise:** 19.5k/day of the host warning "redis does not support fleet arrival job tracking";
  decide with the host side before touching `QUEUE_CONNECTION`.
- **Frozen work** (`deferred`, reason in each row): every P3, `WIK-*`, `JEV-*`, `PIPE-*`, the
  cognition refactors. Unfreeze only by setting a row back to `todo`.
