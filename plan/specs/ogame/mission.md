# Mission concepts — spec note

Source provenance: WIK-212, community wiki page, medium confidence.

Claim type: DOCUMENTED

Host status: pending host confirmation

## What the page states

The page is a concept overview. It names its subject and describes it in prose:
no ratios, no caps, no costs, no thresholds, no formulas. It cross-references no
other page and it states no dependency on any other rule.

## Why no mechanic is implemented

Nothing on the page can become a rule without inventing the rule. A mission whose
cost, capacity or outcome were guessed here would contradict the host the moment
the host publishes its own mission rules, and after one refactor the guess would
be indistinguishable from a documented value an author could cite.

## What unblocks this note

Host confirmation of which mission types exist and of what each one carries,
costs and returns. Until that arrives, this note is the artefact: a traceable
stub, so the next author finds where the claim came from instead of guessing.

## The constraint this note carries

No value may be written into this file. A number added here did not come from
this source, so it belongs in `resources/behavior/` beside the evidence that
produced it — not in a spec note whose entire point is that the source has none.

## Scope of the delivery

This note is documentation only: it changes no decision the engine makes, so it
ships without a replay scenario — there is no rule here for a scenario to expect.
The ticket's acceptance asks for a unit test over this file; the module collects
tests only under `tests/Feature`, so the guard over the note lives there and
asserts the provenance line, the claim type, the host status and the absence of
any mechanic value.
