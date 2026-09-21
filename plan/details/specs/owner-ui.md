# The owner's console — what a server owner needs to see, and why so little of it is new

Written 18 September 2026. Owner: operability. Read with [the review loop](improvement-loop.md),
[budgets](budgets.md), [product](product.md) and the [cognition gates](cognition-gates.md).

> **Superseded in part, 21 September 2026.** The owner has since reversed the "config editor —
> reject" verdict and asked for a full settings control surface with a DB/env split and centralised
> env. See [owner-console-ux.md](owner-console-ux.md) for the current design. The readings
> (OW-1…OW-7) in this file remain valid and are implemented.

**Status: planned, not implemented.** Seven `IMPL` slices in two groups — the console's readings (OW-1
to OW-3) and the monitoring and control surfaces the host cannot answer (OW-4 to OW-7) — plus one
deferred gap. Nothing here adds a table, a job, a dependency, a JS build, a panel framework or a
registry: every figure is read from an action, a column or a table that already exists.

## The owner, and the five questions

A server owner running one universe with a handful of accounts does not want a management suite. They
ask five questions, in this order, and the console exists only to answer them.

| # | The question | Asked | A wrong answer costs |
| --- | --- | --- | --- |
| Q1 | Is the population working right now, and if not, why? | daily | a silent stop reads as a dead server |
| Q2 | Is it playing **well** — a player, or a bot with a schedule? | weekly | the module's entire purpose |
| Q3 | Which account is that, and what did it just do? | on a report | hours of guessing |
| Q4 | What is this costing me? | on the bill | an owner who cannot price the feature |
| Q5 | How do I slow it down safely? | on an incident | an owner who kills the population to be safe |

Q5 ships (the staff switch). Q1 mostly ships (population, action counts, stop reasons). This spec closes
Q2, Q3 and Q4 — and Q4 turns out to be free, because the report already prices the window.

## What already exists — do not rebuild it

| Surface | Route | Audience | Reuse it for |
| --- | --- | --- | --- |
| Operator page | `GET admin/ai` | staff (`admin` middleware) | the switch, caps, today's population and refusals, recent decisions, scenario replay |
| `SummarizeAiOperabilityAction` → `AiOperabilityOverview` | — | — | Q1's figures, unchanged |
| `ai:pilot-report [--days=N] [--json]` → `BuildAiPilotReportAction` → `AiPilotReport` | CLI | operator, review | Q2 and Q4: work outcomes, action states, p50/p95 lateness, language, cost, growth curve, **and its own measured read cost** |
| `ExplainAiDecisionAction::latest()` / `forPlayer()` / `forTrace()` | — | — | Q3, at every level of the drill-down |
| `AiScoreReport` (`ai_score_samples`) | — | — | the growth curve — computed, currently rendered nowhere |
| `ReplayAiScenarioAction` → `AiScenarioReplay` | page form | staff | deterministic what-would-it-do |
| Coalition campaign page | `GET campaign` | any player | the war board |

The overlap is deliberate and bounded: the page shows **today** (Q1), the pilot window shows **N days**
(Q2/Q4). No figure is computed twice and no section recomputes another section's answer.

## Value ranking — every candidate, with its verdict

| Candidate surface | Question | Value | Cost | Verdict |
| --- | --- | --- | --- | --- |
| **Pilot window on the page** | Q2, Q4 | high | **zero new data** — `AiPilotReport` already computes all of it, including read cost | **ship (OW-1)** |
| **Authenticity panel** | Q2 | highest signal | two derivations, both from rows that already exist | **ship (OW-2)** |
| **Unified progress board (one row per account)** | Q2, Q3 | **highest value per unit of work** | `score()` already walks every account and **discards** the per-account deltas; `forPlayer()` already exists; one new GET | **ship (OW-3)** |
| Cost row | Q4 | medium | arrives inside the pilot window | **ship as a row, never a page** |
| Quiet-day diagnosis | Q1 | high | already ships | keep |
| coalition campaign war board | — | medium | already owned by `PVE-002` | **do not duplicate** |
| Driver / sidecar health | Q2 | medium | **attribution is not recorded anywhere** | **defer (`DEF-006`)** |
| Config editor | Q5 | low | a second source of truth for deployment state | **reject** |
| Multi-universe console | — | low | the universe is the boundary; one page reads one universe | **reject** |
| Log viewer / tail | — | low | the log is already on the box; a page would be a second copy | **reject** |

Two rejections are worth their reason in writing. **Config editing** is refused because the module's
configuration is deployment state (env + `config/ai.php`) that a live server operator already controls
and that a web form would duplicate, exposing it in a request. **Multi-universe** is refused because
the grand and pve stacks are separate deployments with separate databases: one page reads one universe,
and wanting two is wanting two tabs.

## The shape — one section, one action, one DTO

The whole console is the existing page, plus sections. The DX rules that keep it that way:

1. **One section = one action returning one readonly DTO with `toArray()`.** No section queries the
   database itself.
2. **The view queries nothing.** It renders DTOs the controller resolved. A Blade file that calls a
   model or an action is a bug, not a style choice.
3. **The page and the CLI are one rendering of one object.** `AiPilotReport::toArray()` is already what
   `ai:pilot-report --json` prints, so the console section and the command cannot drift — and that
   equality is testable without a browser.
4. **Every section declares its window and prints its read cost.** The report already measures
   milliseconds and query count; a section that cannot say what it cost is not finished.
5. **No registry.** Adding a section is four edits — one action, one DTO, one `@include`, lang keys —
   and the page lists its sections literally, so there is nothing to register and nothing to keep in
   sync.
6. **No new front-end.** The host's `ingame.layouts.main`, `defaultTable` / `btn_blue` / `group bborder`
   classes, the module's `partials/admin-nav.blade.php` and `lang/en/t_ai.php`. No Livewire, no Vue, no
   build step, no new CSS.
7. **Read-only, with exactly one POST** — the existing switch. Every other operator action is a GET,
   because an operator reading why the population is quiet must not be able to change the game by
   refreshing a page.

## The readings — what the console shows

### OW-1 — the pilot window on the page (`IMPL-051`)

One new section: the operator picks a window (`1`, `7` or `30` days, defaulting to `1`) and the page
renders `AiPilotReport` — work and action outcomes, p50/p95 session lateness, language attempts and
cache hit rate, **cost**, the growth figures from `AiScoreReport`, and the report's own read cost.

The window is a fixed allow-list rather than a free number so a page view can never become an
unbounded scan. The section is a table whose numbers come from `toArray()`; the JSON stays the
authority.

*Acceptance:* a feature test seeds a window, loads `GET admin/ai?days=7`, and asserts the page's
figures equal `BuildAiPilotReportAction->handle(7)->toArray()` field by field — the two-renderings
invariant — plus that an out-of-list `days` falls back to `1` instead of erroring.

### OW-2 — the authenticity panel (`IMPL-052`)

This is the surface with the highest value in the whole console, because it is the only one that can
answer whether the accounts are distinguishable. Four figures, all derived from rows that already
exist, each reading as a finding rather than a happy number:

| Figure | Derived from | The finding it exists to show |
| --- | --- | --- |
| Reaction delay after a hostile observation | `ai_observations.observed_at` → the account's first `ai_decision_traces.created_at` after it | the schedule is hit inside the 120–180 s window `PlayerObservationService` already plans — a delay that is *always* the same, or never inside it, is the bot signature |
| Save outcomes | `ai_action_receipts` states for save work | a 100 % save rate is the finding (review-loop Q4), so the panel shows refusals and losses, never a success rate that hides them |
| Growth curve | `AiScoreReport` | slope, spread, largest hourly jump, accounts with zero growth |
| Breadth | distinct decision reasons and distinct contacted accounts per account, over the window | a one-action account is the capability-chain failure the review exists to catch |

Bounded like every other window read: an indexed range per account, counters aggregated where the row
is written. If a figure needs a full scan, the missing counter is the bug, not the query.

*Acceptance:* a feature test seeds two accounts — one reacting inside the window with varied actions,
one reacting late with a single action — and asserts the panel's four figures separate them.

### OW-3 — the unified progress board (`IMPL-053`)

There is no unified per-account view anywhere today, and that is the console's biggest hole. Every
existing reading is either a **count** (the operator page: profiles, due work, in-flight sessions,
receipts by state, refusals) or a **cohort aggregate** (`AiPilotReport`: work, actions, p50/p95
lateness, language, cost, and growth as min/median/max/jump/zero-growth). A server owner cannot see
the accounts side by side, cannot tell which one has stopped growing, and cannot answer "is this one
account broken or is the whole cohort?" — the exact question that decides whether to look at an
account or at the plan.

**The data is already being computed and thrown away.** `BuildAiPilotReportAction::score()` groups
samples by `player_id`, computes each account's general delta inside the loop, and then keeps only the
min, median, max and a count of zeros — the per-account rows exist for the length of one `foreach` and
are discarded. So the board needs no new query, no new table and no new sampling pass: it keeps the
row that loop already builds.

One row per enabled profile, ordered so a failure is the first thing on the screen, not something the
owner has to scan for:

| Column | Source | Why it earns the width |
| --- | --- | --- |
| Account, archetype, skill band | `ai_profiles` | who this is |
| State now | `ai_work_items` (due, in flight, retrying, stuck) | is it working |
| Window progress | the per-account delta `score()` already computes | is it growing |
| Last action and outcome | the account's newest `ai_action_receipts` | what it just did, accepted or refused |
| Alerts | zero growth, no action in the window, save refused, stuck lease | why the row is on top |

Rows sort by their alert first, then by staleness, so the board reads as a worklist. A row links to the
per-account page at `GET admin/ai/account/{player}` — one new GET route, named `ai.account`, whose body
is `ExplainAiDecisionAction::forPlayer()` and that account's window figures, under the same read-cost
discipline. No new action: the drill-down has existed all along with nothing linking to it.

`AiScoreReport` grows one field — the per-account deltas it already computed — and the CLI keeps
printing the cohort figures it prints today, so the board and `ai:pilot-report --json` stay one
reading. The alternative, letting the page derive growth itself, would be a second implementation of
the growth rule and is refused.

**Impersonation already exists — link to it, do not rebuild it.** The host ships
`lab404/laravel-impersonate` on `User`, with `canImpersonate()` admin-only and `canBeImpersonated()`
returning `true` for every user, so an AI account is impersonatable exactly like any other:
`ai_profiles.player_id` is an ordinary host user. The endpoints are behind the host's own `admin`
group (`POST admin/developer-shortcuts/impersonate` by username, plus the package's take/leave routes),
and the host's admin menu already renders the "leave" link through `IngameMainComposer`. So a row's
"view as" control posts that account's username to the **existing** host route: no new controller, no
session handling, no permission check of the module's own. Watching the account play from inside is the
fastest way to answer "is it playing well", and it is also the one way an owner can break the module's
own invariant — a human acting while the scheduler drives the same account is two writers, and the
human holds none of the module's work leases. The link is therefore labelled for inspection, and the
population switch is named as the way to stop new work first.

The alternative was checked and refused. `ImpersonateServiceProvider` binds the manager as a singleton
aliased to `'impersonate'`, so module code *could* call `app('impersonate')->take($operator, $target)`
and skip the username lookup — but the module's `composer.json` declares no runtime dependency on
`lab404/laravel-impersonate` and its vendor tree does not carry it, so a class import would be a phantom
dependency and the string alias would still assume a host binding that the module cannot state. It buys
a slightly shorter call for a coupling the host already owns, which is the duplication the module's own
rules forbid. Posting to the host's route keeps take, leave, the session swap and the admin menu in one
place.

One consequence shapes the page. `take()` ends with `quietLogin($target)`, so `Auth::user()` becomes the
AI account for as long as the impersonation lasts — and the module's own route group requires `admin`,
whose check is `hasRole('admin')` on that same user. An AI account has no admin role, so **the console
becomes unreachable while impersonating**, exactly as the host's own admin pages do. "View as" is a
one-way trip until the host's leave link is used, which is the right shape for an inspection tool: the
board is where you decide to look, and the host's menu is where you come back.

*Acceptance:* a feature test seeds three accounts — one growing with varied actions, one flat, one with
no receipts — and asserts the board lists all three with their real deltas, orders the flat and silent
accounts above the growing one, and that the account page shows that account's traces and no other
account's.

## Monitoring and control — what the owner runs and watches

The five questions are what the console *reads*. These are what the owner *runs* and *watches*, and the
order below is the rung ladder: reuse what is already installed, then build only what the host cannot
answer.

**Reuse, with a link and no code.** The host already carries the best monitoring UI on the box.
`laravel/horizon` is installed, its dashboard is gated by `viewHorizon` → `hasRole('admin')`, it takes a
snapshot every five minutes, and the module's own supervisors are registered through
`Modules\AI\Support\HorizonConfiguration` from `Modules/AI/config/horizon.php` (read by the module's
provider, so the host's `config/horizon.php` stays generic). That is queue depth, wait time, throughput,
failures and retries — the worker half of "is it healthy" — already rendered, with job payloads. The
console links to it and re-renders none of it: a second queue view would be a second source of truth for
a number Horizon owns. The same applies to `admin/server-administration` (bans, multi-account
detection), `failed_jobs`, and `ai:pilot-report --json`, which OW-1 already puts on the page.

**Then the four things the host cannot answer.**

| Candidate | The question | Value | Cost | Verdict |
| --- | --- | --- | --- | --- |
| Liveness and quiet diagnosis | is it quiet because the game is quiet, or because we are broken? | high | near zero — `ai_schedules.next_due_at` and `last_activity_at` are written by every completed session, and the worker half is Horizon | **ship (OW-4)** |
| Storage and retention | will the 2 GB box survive? | high on the reference profile | bounded counts and one `min(created_at)` per windowed table | **ship (OW-5)** |
| Provider visibility | what are the lanes costing, and are they failing over? | high | **plan-committed** — R2-R5 names "operator visibility" as remaining, and `ai_language_requests` already stores provider, model, latency, tokens and cost | **ship (OW-6)** |
| Stop one account | stop this account, not the population | high | `ai_profiles.enabled` already exists and admission already reads it | **ship (OW-7)** |
| Admission-cap editing | tune the caps live | medium | needs a hot-path read of a new table, or a fragile write into a cached config | **reject** |
| Alerting and paging | tell me when it breaks | medium | the review loop already rules it out: "not a monitor. Nothing here pages anyone." | **reject (plan)** |
| Scheduled pause | pause over a human event | low | the switch records who and why; an end time nobody watches is a stall with extra state | **reject** |

### OW-4 — liveness and quiet diagnosis (`IMPL-054`)

Today "zero actions" is ambiguous: nothing is running, or nothing needed to run. The section answers it
with three figures that already exist — the newest `last_activity_at` across accounts (when anything last
ran), how many accounts are past `next_due_at` by more than one interval (work waiting that nobody is
taking), and the queued and leased work-item counts the operability summary already computes — beside a
link to Horizon for the worker side.

The distinction is the whole point. A healthy quiet universe shows old activity with nothing overdue; a
stalled worker shows overdue accounts and a stretching gap. That is the review loop's first question
("why was the population quiet today, and is the answer the game's or ours") made readable on the day
rather than a week later.

*Acceptance:* one seeded account due in the past with no activity and one due in the future with recent
activity reports one overdue account and a fresh last run; a second case with everything future-dated
reports quiet-but-healthy.

### OW-5 — storage and retention (`IMPL-055`)

`ai:prune` runs daily at 03:30 and prints "N record(s) pruned" to stdout, where it is lost. On a 2 GB VPS
with hourly score samples and per-session traces, a prune that quietly stopped working is a disk outage
with no warning — and it stays invisible precisely because nothing errors.

The lazy correct read needs no new write at all: for each windowed table, show the bounded row count and
the **oldest row's age against its own declared retention window**. Retention that has stopped keeping up
announces itself — the oldest row falls outside the window it is supposed to be inside — so persisting the
prune's count would be a second source of truth for a fact the data already states. Age is read against
each table's own window, because `ai_score_samples` is deliberately kept for months rather than days.

*Acceptance:* a feature test seeds a row inside the window and one older than it for the same table, and
asserts the section reports the table as behind; a table with only in-window rows reports as healthy.

### OW-6 — provider visibility (`IMPL-056`)

Per vendor and per day: attempts, outcomes, latency, tokens including cached input, and cost — from
`ai_language_requests` and `ai_usage_reservations`, which already carry provider, model, latency, tokens
and cost, with consultation receipts carrying the same fields. The section exists because R2-R5 lists
"operator visibility" as remaining work, and because a paid lane that silently fails over is the
difference between a bill and a bug.

It reports per vendor and never per account: the vendor is a lane, not a persona, and a per-account cost
breakdown would invite tuning the population's personality to the invoice. With both gateways bound to
their `Null` implementations it reports "no provider configured" rather than a table of zeroes — the same
honesty the operator page already applies to its language counts.

*Acceptance:* seeded requests across two vendors report per-vendor attempts, latency and cost without
per-account rows, and an empty ledger reports the unconfigured state.

### OW-7 — stop one account (`IMPL-057`)

The shipped switch stops the whole population. An owner with one misbehaving account has no smaller move
than stopping everybody, which is how a good account gets stopped for a bad one's behaviour.
`ai_profiles.enabled` already exists and is already read by admission and by the social and campaign
gates, so the row gains one form: set it false, or back to true, with a reason recorded the way the
universe switch records its own — who, why, when.

It is deliberately a flag rather than a delete, because a stopped account keeps its memory, relationships
and obligations and resumes where it was. The honest caveat belongs on the control: stopping an account
silences it — no replies, no campaign contribution — so this is a stop, not a pause, and the recorded
reason is what makes that visible to whoever looks later.

*Acceptance:* stopping one of three accounts takes no new work for it while the other two continue;
resuming returns it; the reason and actor are recorded.

## Deferred — `DEF-006`, driver attribution

A server owner on the reference profile needs to know whether FAtiMA, CBRKit, AgentOS or PsychSim is
answering or whether the account has silently degraded to a native engine. **It cannot be shown
today**, and that is a gap rather than a missing widget: `HybridAffectEngine` asks both engines and
keeps the driver's emotion, mood and intensity as evidence, but `ai_emotional_episodes` persists only
the taxonomy's emotion and intensity — so "the driver degraded here" is written nowhere and read
nowhere. The same holds for the experience and social hybrids.

`DEF-006` therefore starts with the field, not the page: record which engine answered, on the row each
hybrid already writes. The health section lands when that column has data behind it. Building a status
page first would produce a page that can only ever say "unknown" — which gate 2 refuses and a review
would read as a pass.

## The module's UI inventory — all of it, in one place

| Surface | Route | Audience | State | Build need |
| --- | --- | --- | --- | --- |
| Staff operator console | `GET admin/ai` | staff (`admin`) | ships | none — host CSS |
| Console readings (OW-1 to OW-3) | same route, plus `?days=` | staff | planned | none — tables and server-rendered SVG |
| Monitoring and control (OW-4 to OW-7) | same route, POSTs for the switch and the account stop | staff | planned | none |
| Per-account drill-down | `GET admin/ai/account/{player}` | staff | planned | none |
| Module nav entry | `partials/admin-nav.blade.php`, registered through the host's module slot | staff | ships | none |
| Coalition campaign page | `GET campaign` | any logged-in player | ships; `PVE-002` extends it | none |
| CLI and JSON twins | `ai:pilot-report --json`, `ai:explain-decision`, `ai:replay-scenario` | operator, scripts | ships | n/a |

Refused and not on this list: a player-facing AI dashboard, a public programme-status page, a config
editor and a log viewer — each with its reason in the ranking table above.

## Frontend — the host's design language, and the one place Vite belongs

**The design language is already reused, and it is CSS rather than JavaScript.** Every module view
extends `ingame.layouts.main`, which the host loads with `@vite(['resources/css/ingame.css',
'resources/js/ingame.js'])`. The console therefore inherits the host's own classes — `maincontent`,
`shortHeader`, `buttonz`, `header`, `content`, `group bborder`, `defaultTable`, `box_highlight`,
`btn_blue`, `textCenter` — and the `fadeBox()` notice global the page already calls. No module CSS and no
new classes are planned; a class is added only when a component has no host equivalent, and it would ride
the single build below rather than a second stylesheet.

**Why the console needs almost no JavaScript.** The planned surfaces are tables, links and small forms:
the window is a GET, the switch and the account stop are POSTs. Two things that look like they need a
client library do not. The growth curve is a data rendering, so it is **server-rendered SVG** from the
same `AiScoreReport` the CLI prints — no chart library, no canvas, and it survives JavaScript being off.
Liveness is a timestamp comparison, so it is a figure rather than a live widget. That is the one-DTO rule
paying for itself: a figure that already exists as a DTO does not need a client to compute it.

**Where Vite belongs — the module's own toolchain.** Owner direction, 18 September 2026: all module
frontend code is separated from the host. The module therefore owns its own toolchain and the host's build
is not touched at all.

| Piece | Where it lives |
| --- | --- |
| `package.json`, `vite.config.js`, lockfile | `Modules/AI/` — its own npm project, its own dependency versions |
| Sources | `Modules/AI/resources/js/ai-console.js`, `Modules/AI/resources/css/ai-console.css` |
| Build | `npm --prefix Modules/AI run build`, output to `public/modules/ai/build` |
| View directive | `@vite(['resources/js/ai-console.js'], 'modules/ai/build')` |

The mechanics are the framework's own, verified rather than assumed. `@vite($entries, $buildDirectory)`
takes the build directory **per call**, and `manifestPath()` resolves it under the host's `public/`, so the
module's views read their own `public/modules/ai/build/manifest.json` while the host's
`@vite(['resources/css/ingame.css', ...])` in `ingame.layouts.main` keeps reading
`public/build/manifest.json`. No global state — a `Vite::useBuildDirectory()` would redirect the host's own
tags and is not used — and no host source file is edited: the host already ignores the module path
outright (`.gitignore` carries `/Modules/AI/`, because the module is its own repository), so module
frontend code cannot leak into the host tree.

Sources live in the module; only **built artifacts** land under the host's `public/`, which is the
unavoidable half — a browser can only fetch what the web server serves. That is why the host ask
([`R11`](host-change-request.md#r11--keep-the-modules-built-assets-out-of-the-host-repository)) shrinks to
a single `.gitignore` line.

**Development uses `vite build --watch`, not a dev server.** The hot-file mechanism is global —
`useHotFile()`, with `hotFile()` defaulting to `public_path('hot')` — so a module dev server would make
the *host's* `@vite` calls look for host assets on the module's server: a broken host dev experience
bought with module HMR. Watching the module's own build keeps its manifest continuously current with no
host interference: edit, refresh, see it. The trade is stated plainly in
[the module's development doc](../../../docs/development.md#frontend-assets): module frontend changes are
not part of `npm run build` at the host root and do not hot-reload.

Separation makes the host's build constraints easy to respect. The ingame bundle is **concatenated legacy
text rather than ESM** (`concatLegacyBundles`, built from jQuery globals and validated by
`scripts/validate-chunks.js`), and a module entry is a separate ES module that loads after the classic
scripts — the ordering the console wants anyway. Module code must not be folded into
`resources/js/ingame.js`, must not add chunks to `resources/js/ingame/chunks/manifest.json`, and must not
`import` from the legacy pile.

**Progressive enhancement is the rule, not a habit.** Every control works as a form first; JavaScript may
only make an existing control faster or prettier. That keeps the whole console usable with scripting off
and keeps accessibility out of the "later" bucket.

**Ownership.** The module side is its own `package.json`, `vite.config.js` and sources plus one `@vite`
directive in its own view (`DEF-007`). The only host change in the whole plan is one `.gitignore` line
([`R11`](host-change-request.md#r11--keep-the-modules-built-assets-out-of-the-host-repository)), and no
host source file is edited. Nothing is built yet, because no planned slice has a client-side requirement —
a build pipeline with no consumer is exactly the machinery gate 2 refuses — so the toolchain lands with
the first slice that needs it.

## Gate check

- **Gate 1 — no static AI.** The console derives nothing about the object universe. Its one host read is
  the score columns in `ai_score_samples`, which are copied verbatim rather than restated — the shipped
  pattern. No id, price or requirement appears in module code, and adding a host object changes nothing
  here.
- **Gate 2 — simplest mechanism.** Seventeen candidate surfaces were considered across both tables. Seven
  become slices, six are rejected with a reason, one is deferred with a named trigger, one already ships,
  one is a row inside OW-1, and one belongs to `PVE-002`; three host tools (Horizon, server
  administration, the pilot-report JSON) are linked rather than rebuilt. Zero new tables, jobs,
  dependencies, JS, CSS or registries — retention health is read off the oldest row inside each window
  instead of written to a counter, and vendor figures come from columns that already exist. The largest
  slice (OW-1) adds a section and a window selector to a page that is already there. No module CSS or
  JavaScript is planned either — the console reuses the host's classes and serves the growth curve as
  server-rendered SVG — so the only client build the module may ever need is one entry in the host's
  existing Vite input (`R11`, `DEF-007`).
- **Gate 3 — what a good player does.** The console is an operator tool, so the naming test applies to
  its *figures*, not its widgets: everything shown must be something an experienced player's play would
  reveal — reaction delay, save outcomes, growth slope, action breadth. A figure that only a machine
  operator would care about, such as tokens per decision, is ops and belongs in the cost row, never in
  the authenticity panel.

## What this does not change

The gameplay path, the scheduler, the provider lanes and every router binding stay untouched: the
console is a read plus one window parameter, and nothing in it runs inside a session, a job or a
worker. The records the panel reads are the ones the game path already writes, so switching the review
off (`ai.review.enabled`) cannot blind the page — the same rule
[the review loop](improvement-loop.md#off-the-hot-path-and-switchable) already holds.

## Evidence

- Reuse claims are code-read: `AIController`, `SummarizeAiOperabilityAction`, `BuildAiPilotReportAction`,
  `AiPilotReport`, `AiScoreReport`, `ExplainAiDecisionAction`, `PlayerObservationService`
  (`REACTION_WINDOW_MIN_SECONDS = 120`, `MAX = 180`), `HybridAffectEngine`, `ai_observations`,
  `ai_action_receipts`, `ai_score_samples`, `ai_usage_reservations`, `resources/views/index.blade.php`.
- The figures' *expected values* are not measured yet: no cohort has produced a review window, so every
  threshold in OW-2 is a shape to look at, not a pass mark. The first review record sets them.
