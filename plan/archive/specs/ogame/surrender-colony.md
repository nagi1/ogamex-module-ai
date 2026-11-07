# Surrender colony

- Source: WIK-239, community wiki, medium confidence
- Status: unverified — documentation only. The host has not confirmed the mechanic exists.
- Encoded: nothing. No behaviour key, no config key, no branch, no scenario.

Nothing in this file may be encoded in behaviour data, config or PHP.

## Why it is recorded

WIK-239 describes a *surrender colony*: a colony that is given up rather than defended.
The page is a community-wiki concept page, not host-confirmed behaviour, and the sections
that would carry player-facing rules (2, 3 and 7) state no numbers and no doctrine.
A mechanic with no numbers has no data to live in, so there is nothing to encode.

## What the source does not provide

- No trigger threshold: the source does not say when a colony becomes eligible for surrender.
- No cost, no cap, no cooldown: the source states no numeric limit of any kind.
- No doctrine: the source does not say whether surrender is player-initiated or automatic,
  nor what happens to the colony, its buildings or its fleet afterwards.

## Decision

The AI module keeps no opinion on surrender colonies:

- no key under `resources/behavior/` — behaviour is data-driven, and there are no values to declare;
- no new config key;
- no branch in `app/` — inlining surrender logic in PHP is exactly the outcome this spec must not cause;
- no scenario under `resources/scenarios/` — a scenario asserts the action a rule must produce,
  and there is no rule to assert.

The two guard tests in `tests/Feature/Ai/SurrenderColonyNotEncodedTest.php` pin this state:
they fail the moment a surrender key, config entry or code branch appears in the module.

## If the host ever confirms the mechanic

1. Record the trigger, the cost and the cap as keys in the existing `resources/behavior/` data file
   that already owns colony defence. Do not inline them in PHP.
2. Add a scenario under `resources/scenarios/` named for the situation (a colony under attack),
   stating the action the mechanic must produce.
3. Only then add the decision branch: one method in the existing planner/engine that reads those keys.

Until all three exist, the concept stays documentation-only.

## Open questions

- Is surrender colony player-initiated or automatic, and at what trigger?
- Does the host confirm any numeric threshold, or does the concept stay documentation-only?
