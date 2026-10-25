# Using `7omate/ogame` to move the AI module toward the north star

Date: 10 October 2026. Owner: Nagi. Status: **proposed plan (research only — nothing here is started).**
Read order this obeys: `AGENTS.md` → "Direction" and "Owner direction", then `plan/HANDOFF.md`.

Source inspected: `https://github.com/7omate/ogame` (Python 100%, 0 stars, last commit ~1 month
before this note). It is already listed as one row in `plan/ogame_ai_source_catalog.md` line 142 —
"OGame API collection / Data extraction not trained weights / Mentioned in conversation; not
live-checked in this extraction". It has **no** document under `plan/details/research/repos/`
(unlike `alaingilbert-ogame.md`, `r4fek-ogame-bot.md`, …). This plan closes that gap and says what,
if anything, the module should *do* with it.

## 0. Verdict in one paragraph

The repo is small and honest: a public-XML-API intelligence CLI plus a cookie-authenticated
Gameforge bot driven by a handful of deterministic heuristics. It ships **no trained weights and no
dataset** — the interesting runtime files are gitignored — and its bot layer targets the live
Gameforge game over a logged-in session, which our hard limits forbid touching. So this is **not a
code source and not a data dump**. It is useful in exactly three narrow ways, in priority order:
(1) it documents, in working code, **the raid-target ranking and the field set a practising player
reads from a spy report** — the human evidence `ATK-001` / `ARB-001` / `LIFE-001` are blocked on;
(2) its `SPEC.md` carries **verified XML shapes and drift notes** that make a cheap conformance check
of our own public collector; (3) it is an independent second opinion on **the player's day**, to
check we have not missed a step. Everything else it offers we already have, already documented, or
must not take. Expected ledger cost: one doc row plus two or three `impl` rows that extend existing
planners — no new class, no new dependency.

## 1. What the repo actually is (verified 10 Oct 2026)

Two layers, deliberately separated by the author:

| Part | What it is | Relevance to us |
| --- | --- | --- |
| `ogame.py`, `api.py`, `cache.py` | Read-only CLI over the **public, unauthenticated** XML API (`players`, `alliances`, `highscore`, `playerData`, `universe`) with a filesystem TTL cache and CSV export. Commands: `top`, `rank-range`, `inactive`, `targets`, `closest-inactive`, `moons`, `alliances`, `coords`, `empty-slots`, `dashboard`. | Overlaps our `scripts/ogame-public-collect.py` (which is broader: 267 universes, pseudonymized). Its value is the **derived target ranking**, not the collection. |
| `bot/` + `bot_cli.py` | **Authenticated** Gameforge automation (cookie import; programmatic login removed because Gameforge's "blackbox" token returns 404). Commands: `scan-debris`, `dispatch-recyclers`, `probe`, `espionage-reports`, `farm` (with a 3:1 fleet-power gate), `distribute`, `overflow-check`, `expedition`, `status`, `audit-report`. Dry-run by default, jittered requests, capped actions per run. | **Read-only evidence.** We do not run this (see §2). Its heuristics are the interesting part. |
| `SPEC.md` | Living spec: verified live XML schemas (§4), known quirks (§6: multi-char `status`, moons never carry their own coords, `playerData.xml` root shape), endpoint-drift log (§10.6: galaxy endpoint renamed to `fetchSolarSystemData&asJson=1`). | Cheap validation of our own collector's parser assumptions. |
| `ogame-bot-roadmap.txt` | The author's own to-do list of the player's day, with explicit `skip` and `always-on` tags. | An independent checklist of the day's steps — gate-3 evidence. |
| `flows2.txt` (1.43 MB) | A large captured-flow artifact (content not read; it is not documented as a dataset). | Treat as opaque until someone verifies what it holds; low priority. |
| `data/` | `config.json`, `cache/`, plus gitignored `session.json`, `bot_config.json`, `audit_log.jsonl`. | **No shipped dataset.** Nothing to import. |

Two matching positives stand out, both gate-3-nameable:

- **`closest-inactive <coord>`** ranks neighbours by **ascending OGame flight distance**, optionally
  re-sorted by `--sort-by score`, and filters by `--inactive-only`, `--no-alliance`, `--no-vacation`,
  `--min-planets`, `--moons-only`. It attaches a **"Juiciness" 0–100** score = `highscore` economy
  score (category 2) ÷ planet count, plus a bonus for *long*-inactive (more elapsed production against
  the storage cap). The README is candid that this is a **proxy**, not a resource reading.
- **`farm`** fires when the defending fleet/defence is "largely inferior", defaulting to a **3:1
  attack-power** ratio, where power is the **raw attack sum** (shield/hp/rounds ignored) — explicitly
  a go/no-go heuristic, not a combat sim.

## 2. Two hard boundaries (do not cross)

1. **Licence.** The repository root publishes **no `LICENSE` file** (verified 10 Oct 2026), so no
   reuse grant exists — default all-rights-reserved. We may **read it as evidence and cite it**; we
   may not copy code, and we would not anyway (`AGENTS.md` → no porting an external driver's work).
2. **The authenticated layer is out of scope.** It logs into live Gameforge with imported browser
   cookies and sends fleets. Our hard limit (`plan/rl/external-data-etl-plan.md`, `external-data.md`)
   is **public, unauthenticated sources only**, and our runtime host is OGameX, not Gameforge. So
   `bot/` is evidence about *what a human does*, never a thing we run or wire in.

## 3. North-star mapping — what each capability could move

Aspect names are the scorecard's (`scripts/play-scorecard.php`: `raids`, `espionage`, `recycle`,
`fleet_save`, `alliance`, `social`, `chat`, `fleet_breadth`, …). "Owner today" is the file the
decision already lives in — per the lazy-senior rule, behaviour goes there, not into a new class.

| Repo capability | Aspect it can move | Owner today | What actually changes |
| --- | --- | --- | --- |
| `closest-inactive` distance-first ordering + filters (inactive / no-alliance / no-vacation / min-planets) + juiciness prior | `raids`, `espionage` | `QueueableSpyPlanner.php`, `RaidPlanner.php`, `Domain/Perception/ActivityIntelReader.php`, `Domain/Decision/CandidateActionFactory.php` | Feed the **already-computed** host facts (flight distance, owner status, planet count, economy score) into the existing target order. The human ordering — *nearest first, then richest-per-planet, inactives preferred* — is the missing sort key in ARB-001. |
| Farm go/no-go at **3:1 raw attack power** | `raids` | `RaidPlanner` (viability gate) + a doctrine YAML value | A named default ratio for "the defender is largely inferior", as **doctrine data** (gate-1 allows ratios over host data). Our planner keeps its real estimator as the truth; the ratio is the human's tie-breaker, not a replacement. |
| Spy-report field set (`fleet`, `defence`, `resources`; "defenceless" = both present **and** empty; plunder ratio 0.5 / 0.75 / 1.0) | `espionage`, `raids` | `Domain/Intel/IntelBook`, `QueueableSpyPlanner`, `RaidPlanner` | Confirm the report fields our spy→raid path reads are exactly the ones a human reads; `app/Espionage/` is empty today, so this is a **check, not a build**. |
| Jittered, capped, **2–3 runs/day** operator cadence + JSONL audit log + markdown report | `fleet_save`, authenticity (`AUTH-001`), the review loop | `SessionPlanner`, `QUAL-010`, review records under `plan/details/reviews/` | Evidence for the **uptime/session shape** in `AUTH-001` and a format precedent for the audit trail. We do **not** copy "anti-detection" machinery — we run our own server; authenticity is *measured*, not defeated. |
| `SPEC.md` verified XML quirks and endpoint drift | infra (collector) | `scripts/ogame-public-collect.py` | A one-shot **conformance check**: multi-char `status`, moons sharing the parent's coords, `playerData` root shape, 429 handling. |
| `ogame-bot-roadmap.txt` day-steps | all | `plan/details/specs/play-coverage.md` | Cross-check that the "player's day" tables have not dropped a step (it independently lists debris→recycle, probe→farm, overflow guard, expedition, fleet save). |

## 4. Slices to schedule (each names its north-star proof)

All are small; three are `impl` and must carry a `file_ref`, the fourth is `doc`. Proof strings are
the form `task.py` accepts. Take the next free ids from `plan/tasks/task.py`.

### SLICE A — evidence doc + source registration (`doc`, no aspect needed)

- **Deliverable:** `plan/details/research/repos/7omate-ogame.md`, in the same shape as the other
  `repos/*.md` documents; register the repo under the **`BOT-*`** family — in the canonical
  `plan/details/research/strategy/sources.yaml` if the family is used there, else in
  `plan/research/ogame/SOURCE-REGISTRY.yaml` (its header forbids two rows for one source) — plus the
  raw `SPEC.md` / roadmap extracts under `plan/research/ogame/raw/bot/`.
- **Why first:** the follow-on slices cite it; it also replaces the stale "not live-checked" note in
  the catalog row.
- **Proof:** doc row — exempt from `aspect:`/`situation:` per the north-star gate.

### SLICE B — raid/spy target order: nearest first, richest-per-planet, inactives preferred (`impl`)

- **Owner:** the existing target readers (`QueueableSpyPlanner`, `ActivityIntelReader`,
  `CandidateActionFactory`), **not** a new class.
- **Change:** the target order sorts by what the planners already compute — flight distance ascending
  (the repo's primary key), then host economy score ÷ planet count descending, with owner status
  (inactive / long-inactive) as a bonus. This is the human "closest, juiciest, unowned-by-anyone-real"
  rule, expressed as a sort key. No object, price or id is named (gate 1); one loop, one comparator
  (gate 2); it is exactly what `closest-inactive` does by hand (gate 3).
- **Input to:** `ARB-001` and `ATK-001` — this is the "loot per hour of flight" ordering HANDOFF asks
  for, so it should be **folded into those rows' acceptance** rather than duplicated beside them.
- **Proof:** `aspect: raids` — the cohort's `raids` aspect moves and the spy target chosen for a
  seeded cluster is the nearest rich inactive, not the first row.
- **Dependency:** SLICE A.

### SLICE C — farm go/no-go as doctrine data (`impl`)

- **Owner:** `RaidPlanner`'s existing viability gate; the value lives in a `resources/behavior/*.yaml`
  or `resources/doctrine/*.yaml` file, never in code.
- **Change:** encode a named "largely inferior" ratio (defaulting to the human 3:1 attack-power
  reading) as a policy value consumed by the existing gate. The estimator stays the arbiter; the ratio
  is a floor a human would recognise and a knob the owner can move.
- **Proof:** `aspect: raids` — with the ratio in place a seeded shipless/inferior neighbour is raided
  where the current gate refuses with `raid_not_viable`.
- **Dependency:** SLICE A; coordinate with `ARB-001` to avoid two rows editing the same gate.

### SLICE D — collector conformance against the published schemas (`impl`, infra)

- **Owner:** `scripts/ogame-public-collect.py`.
- **Change:** a check (not a rewrite) that our parser handles the quirks the repo verified live:
  `status` is multi-character and unordered; a moon never carries its own coordinate; the
  `playerData` root shape; 429/`Retry-After`. Only fix what the check actually breaks.
- **Proof:** `harness:` — the existing `--self-check` stays green and a fixture with `status="vIb"`
  and a moon row normalises correctly.
- **Dependency:** SLICE A.

### Deliberately **not** sliced

- Running or vendoring `bot/` (authenticated; licence).
- Parsing `flows2.txt` before someone confirms what it contains.
- A "juiciness" score as a new concept: our collectors already hold economy score and planet counts,
  and per-hour loot beats a static proxy. If it is used at all, it is a *feature fed to the existing
  scorer*, never a new class.
- Anything about Gameforge endpoints in the runtime — our host is the referee and the endpoints do
  not exist for us.

## 5. The honest value estimate

Ranked by what it is worth to the north star:

1. **Target ordering (SLICE B)** — high. `ATK-001`/`ARB-001` are the blocker for raids, and therefore
   for battles, moons, debris and fleet saves. A human's own ranking, in code, is directly usable
   evidence for the missing sort key.
2. **Farm ratio (SLICE C)** — medium. A single named number; cheap, but only as good as the
   estimator it sits beside.
3. **Doc + registry (SLICE A)** — low effort, needed to cite the rest.
4. **Conformance check (SLICE D)** — low effort, low risk, protects a dataset we already rely on.
5. **Everything else** — already covered by `repos/alaingilbert-ogame.md` (which `7omate`'s bot is
   adapted from), `r4fek-ogame-bot.md`, or the module's own code. Do not re-mine.

## 6. Ledger additions (to be created after owner sign-off)

| Code | Kind | Prio | Title | Proof step |
| --- | --- | --- | --- | --- |
| `BOT-101` | doc | P2 | Register `7omate/ogame` as a `BOT-*` source with a repo evidence doc | doc (exempt) |
| `BOT-102` | impl | P1 | Fold the nearest/richest/inactive target order into spy+raid target selection | `aspect: raids` |
| `BOT-103` | impl | P2 | Encode the "largely inferior" farm ratio as doctrine data | `aspect: raids` |
| `BOT-104` | impl | P3 | Public-collector conformance vs the published XML quirks | `harness: self-check` |

`BOT-102`/`BOT-103` must **not** be created as new rows if `ARB-001`/`ATK-001` are already open and
owning those files — extend their acceptance instead. `BOT-104` stays P3 (frozen unless the owner
moves it) because it protects data rather than moving an aspect.

## 7. Open questions for the owner

- May `BOT-102` be merged into `ARB-001`'s acceptance (one row, one file) rather than a new row?
- Is the `data/`/`audit_log.jsonl` *format* worth mirroring in the review records, or is
  `plan/details/reviews/` already enough?
- `flows2.txt` is 1.43 MB and undocumented — spend a few minutes identifying it, or ignore it?

## 8. How this file is used

- It is a plan, not work. Nothing here is ticked off until a row exists in `plan/tasks/` and its proof
  passes through `task.py done`.
- When a slice is picked up: read SLICE's owner file end to end, make the smallest change there,
  leave one runnable Feature test, then close via the row's proof.
- Update the catalog row (`plan/ogame_ai_source_catalog.md` line 142) from "not live-checked" to the
  `BOT-*` id once SLICE A lands.
