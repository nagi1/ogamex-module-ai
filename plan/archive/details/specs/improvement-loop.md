# Reading the results — the review loop

Written 14 September 2026. Development ends when the module plays; the goal is judged by what it
produces, not by what it implements. This is the standing activity that reads what the accounts
actually did, decides whether it was good play, and turns each shortcoming into a change to an
algorithm, the plan, or the way the accounts work — while the three [cognition gates](cognition-gates.md)
still decide what an acceptable change is.

It is **not** a sixth phase and **not** a new subsystem. It adds no table, job, dashboard or model
call. It is a repeated read of artifacts the module already writes, plus the register re-run the
[gap register](../GAP-REGISTER.md) already asks for, written down in a form the next review can compare
against.

## What a review reads

Everything below already exists or is already planned. A review that has to write a new query against
production to answer a question has found a **gap**, not a measurement.

| Artifact | Where it comes from | The question it answers |
| --- | --- | --- |
| Decision traces | `ai_decision_traces`, `ai:explain-decision` | Why did a session choose that — action, reason, deciding components, ranking, age of the evidence |
| Work and receipts | `ai_work_items`, `ai_action_receipts` | What was actually accepted by the host, what was refused, what was retried or left stuck |
| Stop counters | `ai_stop_counters`, the operator page | Why was the population quiet today, and is the answer the game's or ours |
| Pilot window | `ai:pilot-report` | Actions, worker failures, retries, stuck leases, scheduling-lateness percentiles, provider tokens for one window |
| The account's public state | host `highscores`, planets, buildings, research, fleets | What a neighbour sees: level, rank, fleet, military points, growth |
| Our own points series | AG2's recorded hourly series ([gameplay algorithms](gameplay-algorithms.md#ag2--the-growth-curve-is-ours-to-record)), once shipped | The shape of the curve — slope, spread, any spike without a visible cause |
| Human feedback | the operator-supplied pilot file plus reports, opt-outs and retention from [validation](validation.md#measure-humans-separately) | Did the humans find it worth playing against |

## The questions a review answers

Goal-shaped, per account and per cohort; each answered with a figure, its window and its evidence class
(**measured**, **code-read** or **inferred**), never with an impression.

1. **Did the account reach the next stage of the capability chain?** Not "did it act" — the pilot that
   satisfied *acted* with one building type is the failure this question exists to catch.
2. **Is the growth explicable by visible behaviour?** A jump with no visible cause, or an economy that
   is mechanically optimal week after week, is a finding even though nothing errored.
3. **Does the cohort diverge?** Two accounts on the same host data converging, or a population that is
   uniform in aggregate, is a freshness failure that no single trace shows.
4. **Does it react like a player under pressure, and does a save ever fail?** Reaction latency after a
   probe or attack, and a save rate that is not 100%.
5. **Is the server more alive?** Meaningful interactions per human-week, reciprocal contact, return
   rates, and whether losses stayed recoverable.

## The record

One dated file per review, in `plan/details/reviews/`, shaped like this:

- **Window and scope** — the dates and the accounts the figures cover.
- **What was read** — the artifacts above, and anything that could not be read.
- **Read cost** — seconds and query count to produce this window at the current cohort size.
- **What the cohort did** — the figures, per question, with the evidence class.
- **What the cohort failed to do** — the gap between the goal and the observation.
- **Surprises** — anything nobody predicted, kept even when it is unexplained.
- **Findings** — each one a register entry with its evidence and how it was obtained.
- **What stays unmeasured** — explicitly, so a later review does not read silence as a pass.

A finding without a figure is an observation to investigate, not a finding. An observation that cannot
be reproduced under a frozen clock and a fixed seed is recorded as **not established** — the same
wording the earlier research used when a claim had no source.

## Reading it cheaply

A review that is slow or expensive does not happen, so the read path is part of the design rather than an
afterthought. It is the same discipline the module already applies to gameplay: bounded, aggregated at
write time, and free of generative calls.

- **One pass, one window.** A review reads through the reporting commands over a bounded window; it never
  walks the accounts one at a time or runs a command per player. `ai:pilot-report --days=N` is the shape
  both the review and the operator use.
- **Structured first, prose second.** Every reporting command answers in a stable, documented,
  machine-readable form — fixed field names, fixed decimal places, UTC timestamps, no free text — so a
  review parses fields and diffs two windows mechanically. The human rendering is a rendering of that same
  answer, never a second computation. The redacted decision explanation is read the same way: its fields,
  not its sentences.
- **Aggregate where the row is written.** Counters are per reason per day (`ai_stop_counters` is the
  shipped pattern), so the common question is a lookup rather than a scan. A figure that needs a
  full-table aggregation is a missing counter, and the fix belongs on the write path.
- **Bounded by construction.** Traces carry short retention and every window query is an indexed range
  inside it, so read cost does not grow with the age of the universe. A table with neither an owner nor a
  bound is a register entry, not a query to run anyway.
- **Zero tokens on the read path.** No log, trace or transcript is ever sent to a model to be summarised,
  and reading a window costs no provider call at all. A provider-assisted evaluation stays a separate,
  explicitly budgeted experiment ([budgets](budgets.md)), never the way a review is read.
- **Read-only, and uncontended.** No locks, no queued work, and no call into a game service that would
  advance resources or stamp activity. The replay rule already holds this bar; the review path holds the
  same one.
- **Read cost is a figure, not an assumption.** Seconds and query count for one window at the configured
  cohort size are recorded in the record, so a read that quietly becomes slow shows up in the next record
  rather than in the decision to stop reviewing.

## Off the hot path, and switchable

The loop must be invisible to the game, and that is a property of where it runs and what it writes rather
than of how carefully its queries are written.

- **Nothing in a session, a job or a request.** The read is an explicit command an operator or the
  coordinator runs, never a hook, a listener or a step inside a session, so an account's work does not
  change because a review exists.
- **The only write it adds is one scheduled sample.** AG2's hourly points series is a batch pass outside
  the request path — one row per account per hour — that takes no lock and calls no service that would
  advance resources or stamp activity. Everything else a review reads is a record the game path was
  already writing.
- **Best-effort, and it fails toward play.** A failed sample loses one data point and is reported as a gap
  in the window; it never fails, retries into, or delays a session, and it never blocks a queue worker.
- **One switch, `ai.review.enabled`, default on.** On, because reading the results is the point and the
  read itself costs nothing. Off, the collection stops and a window reports which figures were not
  collected. The switch is read once per pass, never per account and never per session.
- **The gameplay records are not switchable.** Decision traces, work items, receipts and stop counters are
  what the operator page and the pilot report are made of, so switching the review off must not be able to
  blind a staff member diagnosing a quiet population. The switch covers what the review *adds*, not what
  operability already owns.
- **"No performance impact" is measured before it is claimed.** The sampling pass reports its query count
  and duration, and the claim is supported by a session-cost comparison with the switch on and off. A cost
  that cannot be shown to be negligible does not ship — and an optimisation with no such measurement is
  refused by gate 2 regardless.

## From a finding to a change

```
finding → gap-register row (evidence class)
        → named algorithm in gameplay-algorithms.md (host inputs, constants and provenance, accept)
        → the smallest slice that closes it, with its tests
        → measured before/after on a frozen clock and a recorded seed
        → a DECISIONS.md entry when the change is material
```

Rules that keep the loop honest:

- **Fix the mechanism, never the symptom.** Deleting the trace that showed the problem, or widening
  an acceptance wording until it passes, is the root cause the register already names.
- **A tuning change replaces a `placeholder` constant with a measured one**, updates its provenance
  marker and is entered in the tuning log below. A constant that never varied is still not a setting.
- **No shortcoming is closed by a per-account constant or list** (gate 1), by a new layer, dashboard
  or service (gate 2), or by behaviour a player cannot be named doing (gate 3).
- **Never tune against a window a live pilot is still writing into** — the register's gate rule: a
  measurement that can change because a live pilot wrote a row is not evidence about the code.
- **Some findings are about the plan, not the module.** Weak acceptance wording, an unaudited axis,
  a mechanism nobody named — those are fixed in the plan, because the register's root causes were
  process failures and every one of them produced gaps.

## Tuning log

Append-only; this is the ledger that shows a `<number>` in the algorithms is derived rather than
guessed. It is the first table of its kind in the plan, and it stays small because only placeholders
enter it.

| Date | Algorithm | Constant | Was | Now | Evidence (window, figure) |
| --- | --- | --- | --- | --- | --- |
| — | — | — | — | — | none yet |

## When a review runs

- **After every closed slice** — re-run the register, as it already requires, and refresh the questions
  above that the slice touched.
- **After every pilot window** — one record. The disclosed pilot asks for at least four weeks
  ([validation](validation.md#measure-humans-separately)); a review record per window is what the
  fourth week is compared against.
- **Before increasing the population, and before Package 6** — the latest review record is part of the
  sign-off evidence, alongside the pilot report and the owner's acceptance.
- **When something is visibly wrong in play** — a player report, a stuck queue, a suspiciously perfect
  account. Read first, change second.

## What this is not

- **Not autonomy.** The only machine learning that stays in scope is the outcome-based CBR already
  specified; changing a policy, a constant or a gate is a reviewed, tested change.
- **Not a monitor.** Nothing here pages anyone. The operator page answers "why is it quiet"; the review
  answers "was the play any good", which is a slower question.
- **Not a promise.** An unmeasured question stays unmeasured and is listed as such. The register exists
  because an unowned observable passed as an aspiration, and the same failure here would be a review
  that reports only what it happened to read.
