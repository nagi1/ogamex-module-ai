<!--
Deviations from the plan, recorded in the artefact that outlives the task:

- The guard test is tests/Feature/CounterespionageSpecTest.php rather than tests/Unit/...:
  this module does not accept unit tests, because a test that drives no host path can pass
  while the behaviour it describes is wrong.
- The file is named for the concept, not for the source page; the source id sits in the front
  matter below, which is where provenance belongs.
- No action, planner input or scenario is added. The source states no ratio, cap, cost or
  threshold, so there is no behaviour to drive and no expectation an engine could honour:
  a scenario written today would only record whatever the engine happens to answer.
-->

# Counterespionage — unconfirmed spec

- source: WIK-161
- claim type: DOCUMENTED
- confidence: medium
- status: unconfirmed — no mechanics, no constants

## Concept

Counterespionage is the defender-side half of espionage. A defender whose planet is being
probed is said to be able to answer with their own espionage capability instead of only
suffering the probe. The source states the idea; it says nothing about how the idea is
priced, resolved or reported.

## Numbers

None. The source states no ratio, no cap, no cost and no threshold, so there is nothing safe
to encode.

## What must be confirmed before this becomes behaviour

- Whether a defender's espionage capability changes the outcome of an incoming probe at all.
- Whether the effect is a chance, a cost, a change to the report, or an extra message.
- Which data file the confirmed figures belong in, so a modder changes the rule by editing
  data rather than code.
- Which runtime path decides it: the planner, an action, or the espionage service.

## What this spec deliberately does not do

- It adds no mechanics and no constants, so the AI module keeps its current silence on
  counterespionage.
- Nothing in the runtime reads it, so it cannot change play by itself.
- It must not be read as confirmation: figures from community wikis are not host-confirmed
  and do not belong in the Numbers section above.
