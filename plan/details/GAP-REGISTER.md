# Gap register — the goal against the plan

Produced 14 September 2026. A capability gap was found by accident: the buildable set could never
reach a shipyard or a research lab, so six of the seven remaining capabilities were permanently
unreachable. **A gap found by accident implies a class**, so this register audits the *goal* against
the plan rather than auditing the plan against itself.

## Method

1. Take the observables the goal is judged by — the eleven signals in
   [account authenticity](research/account-authenticity.md), highest-observability first.
2. For each, ask which **gameplay** produces it.
3. Check whether the module can produce that gameplay.
4. Record the gap with its evidence and how the evidence was obtained: **measured** (seen in the live
   database or a run), **code-read** (absent from the code as written), **inferred** (follows from
   another gap and is not yet separately confirmed).

An absence is only a gap if it is **observable**. Where a missing mechanism is invisible to a player
it is recorded as deliberate rather than as a defect.

## Gaps

| # | Gap | Signal it breaks | Evidence | Established | Closing it needs |
| --- | --- | --- | --- | --- | --- |
| G1 | **No enabler buildings.** The buildable set is four requirement-free buildings, so a research lab or shipyard can never be built. | 1, 3, 5, 6, 9, 10 | Player 29129 owns no buildings and no research; `robot_factory` (14) and `research_lab` (31) have no host requirements and `shipyard` (21) needs `robot_factory` 2 | measured + code-read | Widen the target set to the enablers, and choose among targets the host already accepts |
| G2 | **No research.** No executor; the research capability is unpublished. | 3 | No research queue path in the module | code-read | A research executor |
| G3 | **No units.** No executor, so no ships and no defence, ever. | 1, 3, 6, 9 | No unit queue path in the module | code-read | A units executor |
| G4 | **No fleets, so no fleetsave.** Nothing can be saved because nothing exists to save. | 1 | No dispatch path in the module | code-read | A dispatch executor |
| G5 | **No probes.** Nothing scouts, and no target intel is published. | 5, 10 | No spy executor; `ownedState()` never publishes target reports | code-read | A spy executor plus legal target selection |
| G6 | **No raids.** `Raid` candidates are built only from published target reports, which ordinary play never publishes, so a raid cannot even be chosen. | 4, 5, 6, 10 | `CandidateActionFactory` builds raid candidates from `targetReports`; `ownedState()` publishes none | code-read | A raid executor plus legal target intel |
| G7 | **No colonies.** The account stays on its starting planets forever. | 3, 4 | No colonise path in the module | code-read | A colonise executor |
| G8 | **The account cannot notice being probed or attacked.** The only observations are chat, alliance membership, battle reports and building completion; nothing observes incoming fleets. | **1 — the highest-ranked signal, and the only one a player can *test*** | `app/Observers/` holds those four and no fleet observer; only Install/Uninstall hooks exist | code-read | An observation of own incoming fleets plus a reaction path. The host already has `IncomingFleetIntelService`, so the data exists |
| G9 | **No save ever fails.** A 100% save rate over months is itself the outlier, and with no fleet there is nothing to fail. | 1, 6 | Follows from G4 and G8 | inferred | Same as G4 and G8, plus deliberate imperfect judgement |
| G10 | **No sleep window and no diurnal shape.** The account is active at every hour of every day. | **2** | `AiProfileSettings` exposes only `timezone`, `session_minutes`, `session_gap_minutes`; `SessionPlanner::plan()` takes no active-hours input | code-read | An active-hours model the planner honours |
| G11 | **Never absent.** Nothing models a day off or a multi-day gap, and the plan asks for "genuine week-to-week irregularity". | 2 | No absence modelling anywhere in the routine domain | code-read | Irregularity design, including absence |
| G12 | **The account never speaks first.** Every social action is a reply; nothing initiates contact. | 5 | Every social action found is classification or reply machinery: `ClassifyInboundSocialExchangeAction`, `BuildAuthoredSocialReplyAction`, `DeliverAiDirectReplyAction` | code-read | Outbound initiation |
| G13 | **The five personas are observably identical.** With one executable action they all queue a mine or a plant and differ only in their traces. | persona design, 4 | Follows from G1–G7 | inferred | Capability variety |
| G14 | **Self-similarity is trivially maximal.** One action type on a jittered schedule is the most self-similar sequence possible. | 4 | Follows from G13 | inferred | Same as G13 |
| G15 | **Zero military score forever.** The public highscore carries military points. | 3 | Follows from G3 | inferred | Same as G3 |
| G16 | **Resources saturate and the curve flatlines.** With nothing to spend on, the economy caps out, so "growth explicable by visible behaviour" becomes "growth stops". | 3 | Follows from G1–G7 | inferred | Same as G1–G7 |
| G17 | **No transfers and no trade.** Nothing dispatches a transport or touches the market. | 9 | No transport or trade path in the module | code-read | A dispatch executor plus trade |
| G18 | **No alliance life of its own.** Membership changes are observed but the account never joins, leaves or acts on them. | 5 | The module observes `ObserveCommittedAllianceMembership`; nothing initiates | code-read, intent unconfirmed | Confirm whether joining an alliance is in scope, then an executor if it is |

## Root causes

The gaps are not eighteen separate mistakes. They come from four planning failures, and fixing the
failures is what stops the nineteenth:

1. **No artifact connected the observables to the gameplay that produces them.** The authenticity
   research said the growth curve matters; the work packages said which mechanisms to build; nothing
   said "therefore an account must reach a shipyard".
2. **Acceptance criteria were mechanism-shaped, never goal-shaped.** Traces, idempotency, caps, lanes
   and delivery were all accepted as complete against their own mechanics.
3. **One acceptance wording was weaker than the goal.** The pilot found "decides but never acts"; the
   fix was accepted as **"the cohort acts"**, which one building type satisfies.
4. **Observables were assumed rather than owned.** Nothing in the plan had to name the mechanism
   producing a signal, so signals 1, 2 and 5 could be listed as goals while having no implementation
   at all.

## Rules that follow

- **A capability set is complete against a goal, never against a list.**
- **A slice that adds abilities must show the account reaching the next stage of the chain**, not
  merely performing one action.
- **Every observable the plan claims to control must name the mechanism that produces it.** A signal
  with no named mechanism is a gap, not an aspiration.
- **Audit against the goal, not against the plan.** This register is the artifact; re-run it whenever
  a slice closes, and treat an empty list as the evidence that a package is complete.

## Deliberate, and not gaps

- Provider off by default, zero generative calls on the ordinary path.
- No AI-to-AI generated chat; authored dialogue for known exchanges only.
- Unrecognised free-form text answered with nothing.
- Sidecar drivers disabled until a Gate 2 verdict; embeddings and PsychSim behind measurement gates.
- No second player runtime, no generic game planner.

## Sequencing

G1 is the prerequisite for G2–G7, G13–G17. G8, G10 and G11 are independent mechanisms and do not wait
on the chain. G12 is independent of the chain but needs a social policy decision first.
