# Review record — IMPL-051, the pilot window on the operator page (18 September 2026)

Cheap, bounded, machine-parsable record per [the improvement loop](../specs/improvement-loop.md).

```json
{
  "window": "impl-051-pilot-window",
  "date": "2026-09-18",
  "slice": "IMPL-051",
  "spec": "../specs/owner-ui.md",
  "changed": [
    "app/Http/Controllers/AIController.php",
    "resources/views/index.blade.php",
    "lang/en/t_ai.php",
    "tests/Feature/AIRouteTest.php"
  ],
  "checks": {
    "pest": "8 passed / 44 assertions (Feature/AIRouteTest.php)",
    "pint": "passed",
    "phpstan": "0 errors",
    "gate2": "exit 0, allowed notices only"
  },
  "measured": {
    "cohort": "ogamex-grand (20 enabled profiles, 73810 completed sessions in the 1-day window)",
    "read_cost_milliseconds": 2441.1,
    "read_queries": 9
  },
  "finding": "The one-day window read costs 2.4 s and 9 queries on the accelerated grand cohort, because lateness is computed from every completed work item inside the window. Acceptable for an operator page there, and the reference profile's handful of accounts will not approach it; aggregating lateness at write time is the fix if a real cohort ever does, which is the review loop's own rule that a figure needing a scan is a missing counter.",
  "code_change": true
}
```

## What happened

The first owner-console slice shipped: the operator page now renders the pilot window instead of only
shipping it as a command. `AIController::index()` reads a `days` query parameter against a fixed
allow-list of `1`, `7` and `30`, hands it to the shipped `BuildAiPilotReportAction`, and passes the
report's own `toArray()` to the view. The view renders it and computes nothing.

That last sentence is the slice's whole design claim, and the test holds it: the page's growth line is
asserted as the report renders it (`min 60 · median 60 · max 60`), so a view that derived growth for
itself would fail. An unknown or non-numeric `days` falls back to one day rather than erroring, because
the selector is a convenience and a mistyped URL should still return the page.

Two figures are deliberately on the page rather than in a log: the report's own **read cost**, as the
spec requires, and the window the figures cover, so a screenshot is self-describing.

## What the cohort shows

Read live in `ogamex-grand` at one day: 20 enabled profiles, 25 290 accepted and 3 116 rejected action
receipts, session lateness p50 0.12 min and p95 1.13 min over 73 810 completed sessions, no provider
attempts in the window, and a growth spread of min 2 931 / median 95 112 / max 727 155 general points
across 20 accounts with 70 samples. The read cost is the honest part of that picture: **2.4 s and 9
queries**, almost all of it the lateness computation walking every completed work item.

## What stays unmeasured

The page has no test for the *rendering* of an empty window (no samples, no receipts) beyond the
language and action rows falling back to zero, and none for a window where review sampling is switched
off, which the view handles with its own message. Both are cheap to add when a slice touches them.
