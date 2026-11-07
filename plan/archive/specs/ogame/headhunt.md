# Headhunt — research note (unverified, not implemented)

- Status: unverified, host-to-confirm. No behaviour is implemented from this note.
- Source: the OGame community wiki's "Headhunt" article (community-sourced, medium confidence).
  The tracking reference lives in the originating plan, not in the codebase.
- Module stance: the AI module has no opinion on Headhunt until the host confirms the mechanics.

## What the source actually states

- The variants section lists nothing, so there is no doctrine variant to select between.
- The numbered sections carry no verbatim values: no ratio, no cap, no cost, no threshold.
- What is left is a statement of fact about the game, not a rule an account can act on.

A doctrine statement with no variants and no numbers cannot justify a parameter. Encoding one
regardless would invent a number the source never stated, so the module stays silent: no behaviour
key, no scenario, no scoring term, no planner branch.

## Why nothing was added

- Numbers are policy and belong in `resources/behavior/`; the source supplies none to put there.
- There is no variant dimension, so there is no archetype or skill-band switch to add.
- The source leaves the decision to the host implementation, so the absence of an AI opinion is the
  correct state rather than an oversight to fill.

## Regression guard

`tests/Feature/HeadhuntGapTest.php` fails if this gap fills up silently:

- no PHP file under this module's `app/` directory may reference a headhunt policy key,
- no `resources/behavior/*.yaml` file may gain a headhunt key,
- every `resources/behavior/*.yaml` file must hash identically to the committed golden fixture:
  the fingerprint file when the module ships one, otherwise the committed blob at `HEAD`.

## Where it would go if the host confirms it

- One policy key in `resources/behavior/` would carry the numbers, read by the planner — a single
  knob a modder can edit, not a new class.
- If the mechanic turns out to be event-driven rather than account strategy, it belongs in a
  mission/event handler and not in this module at all.

## Open questions

- What does the host confirm the Headhunt mechanics to be?
- Does Headhunt belong in the AI module at all, or in an event/mission handler?
- Which single behaviour policy key, if any, would carry it once confirmed?
