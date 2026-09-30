# Spec: planet diameter is not an AI input

- source: WIK-196 (community wiki)
- status: DEFERRED — the omission is recorded, no mechanic is encoded
- guard: `tests/Feature/Ai/DiameterIsNotAnAiInputTest.php`

## What the bundle supplied

Section 2 of WIK-196 carries no verbatim numbers, section 7 carries no doctrine variants, and
section 4 shows no existing AI principle, constant or action that mentions planet diameter. The
bundle therefore says nothing about how far a diameter would move a decision, in which direction,
or under what condition. A mechanic built on that silence would be invented, so none is built.

## The rule

A planet's diameter is not an input to the AI engine. No action, planner, enum, support class or
behaviour value under `resources/behavior/` reads it, so two accounts that differ only in
diameter must reach the same decision.

## Why the omission is written down

Treating an empty bundle as "diameter is irrelevant" is itself a decision, and an unwritten one
would be re-litigated by the next bundle that mentions diameter. The deferral is recorded here so
the next reader knows the silence was deliberate, and so a modder knows which knob would turn if
the host confirms a real mechanic: a new value in `resources/behavior/`, read by the planner —
never a literal inside the engine.

## How the deferral is enforced

`tests/Feature/Ai/DiameterIsNotAnAiInputTest.php` scans the surfaces the engine reads —
`app/Actions/**`, `app/Ai/**`, `app/Domain/**`, `app/Enums/**`, `app/Support/**` and
`resources/behavior/**` — for a diameter token and asserts zero matches. A probe token proves the
scan actually reads file contents, and a sample file proves the scan flags a real read, so the
guard cannot pass by scanning nothing. Comment text is stripped before matching, so prose about
diameter cannot fail the guard while a genuine read stays visible.

## Not shipped

- No scenario under `resources/scenarios/`. A scenario states the action the account must take;
  this rule forbids an input rather than selecting an action, so any `expect` block would encode a
  mechanic the bundle does not supply.
- The byte-identical replay in the acceptance is not implemented as a replay. Comparing two runs
  that differ only in diameter needs the module's session entry point and its argument contract,
  which this test cannot drive without inventing one. The scan above is the decidable form of the
  same claim — the engine cannot branch on a value that neither its code nor its behaviour data
  ever names — and it fails the moment a diameter read appears.

## Open questions

- Does the host derive the field count from diameter, and would that make field capacity part of
  build planning? Fields are a separate signal from diameter and are not covered by this guard.
- Should the deferral live here, under `plan/specs/`, or in the module's behaviour documentation
  once that exists?
