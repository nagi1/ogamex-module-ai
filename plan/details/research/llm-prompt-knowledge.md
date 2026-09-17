# LLM prompt knowledge — what a professional OGame player reasons with

Research note, 16 September 2026. This is the **prompt-safe distillation** of the strategy research
for the Laravel AI agents. It is what a system prompt may hold, and nothing else: each rule is phrased
as *reasoning*, carries its source, and must never contain an object id, machine name, price or
requirement — those are host facts and arrive in the request context, never in authored prompt text
(gate 1). Read it with
[ogame-automation-algorithms.md](ogame-automation-algorithms.md),
[strategy-principles.md](strategy-principles.md) and [veteran-play.md](veteran-play.md).

## The reasoning rules

These are the recurring shapes sixteen independent bot projects converged on, restated as what an
experienced player does. An agent uses them to *judge*, never to *name* an object.

1. **Prefer the upgrade that repays its cost fastest.** Compare what a level adds to what it costs, in
   one currency, and re-rank after every completion, because the ranking moves as levels rise. Stop
   when the next level no longer repays inside a horizon you can afford. *(P1)*

2. **A priority list is taste, not truth.** "First satisfied condition wins" is a reasonable ordering;
   it is never the reason a capability is reachable. Reachability comes from the host's own
   requirements, which is also why a hand-typed list breaks the moment the game adds an object. *(P2)*

3. **Climb prerequisites before the thing they unlock, easiest first.** Walk the requirement graph
   from the goal backwards, guarding against cycles, and never hardcode it — ask the host. *(P3)*

4. **Plan energy one level ahead, not reactively.** Build capacity *before* the next purchase would
   go negative; a one-step margin is enough. The profession genuinely disagrees on the exact doctrine,
   so treat the margin as taste and the need as fact. *(P4, veteran-play §2)*

5. **Do one mutating action, then sleep until the slot frees.** Never double-enqueue against a stale
   read; back the "is something building" check with a cached finish time. *(P5)*

6. **Only raid what pays after the trip.** Profit is loot minus fuel minus expected losses; require the
   loot to be several times the fuel and bound the acceptable loss. Size the cargo to the payload, and
   remember the game splits plunder per resource — a flat ratio is wrong. *(P6, T2)*

7. **Fleet-save before the fleet returns, but a save can fail.** On an inbound threat, wake inside the
   reaction window and pick the first legal route that covers the required duration; if none exists,
   accept the loss rather than pretend. A save that *can* fail is what makes the account human. *(P7, V3)*

8. **Wake at the next material event, never on a fixed period.** A fixed tick with jitter is still a
   tick and leaves a signature; compute the next wake as the earliest of production, slot-free and
   fleet-return times, then jitter the boundary. *(P8, H5)*

9. **Escalate probes, or solve for the minimum outright.** Ask for the fewest probes that reveal what
   you need, and only escalate in steps when the target's counter is unknown. *(P9)*

10. **Availability is the maximum of independent blockers.** The next possible action time is the
    latest of resource ETA, build-slot, fleet-slot and missing-ship return — computed together, never
    with a head-of-line break that freezes the whole group. *(P10)*

11. **Count what is on the planet and in flight together.** The need is target minus on-hand minus
    in-flight, with each flight classified as crediting its destination or debiting its origin. *(P11, U4)*

12. **Reserve before spending, and net the reserve against future production.** Save a per-resource
    floor so a big purchase does not freeze surplus of other resources, and shrink the floor by what
    production will deliver while you wait. *(P12)*

13. **Sample and report the tail, not the mean.** Use paired seeds so differences are composition and
    not luck, escalate samples only when needed, and put a wall-clock deadline on the batch. A mean can
    hide a sign flip; count losing runs instead. *(P13)*

14. **A full warehouse is a spend signal.** At capacity, "store more" and "spend it" compete, and an
    already-full planet should spend rather than discard production. *(E3/E6, veteran-play)*

15. **Never consume the last fleet slot for something that can wait.** A new colony, probe or raid is
    refused at the fleet cap and then starves everything else; read the host's slot ceiling before
    publishing a dispatch. *(W8-L1, DISC-006)*

16. **Develop what the account already owns before founding more of it.** A new body outranks the one
    that pays for it is a mistake; a candidate that cannot be developed should not outrank developing
    the homeworld. *(W8-L5)*

## Negative rules (what an agent must not do)

- Never name an object, ship, research, price, level cap or requirement as if it were true. It is host
  data and must come from the context; the prompt only says *how to weigh it*.
- Never emit a fixed build order for every account. Order is derived from host numbers and persona, not
  from an authored chain.
- Never respond to a mean where a distribution is asked for, and never assert a win probability the
  sample size cannot support.
- Never run an unbounded loop, batch or simulation; every loop has a budget and a deadline.
- Never treat the object universe as closed. A new host object must change the account's options with
  no prompt edit — which is exactly why the prompt names no object.

## How the agents use this

The conversation-reply and campaign-consultation agents may reference these rules as the account's
reasoning style. They receive the *current facts* (host objects, prices, candidates, relationships)
in the request context and apply these rules to them. The rule text is authored and stable; the facts
are host-read and per-request. A prompt that needs to change because the host added an object is a
prompt that was doing gate 1's job, and it is rewritten rather than extended.
