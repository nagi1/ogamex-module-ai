# Trust repair and forgiveness — how a raider becomes (or never becomes) a friend

Written 20 Sep 2026. Grounds DEF-011/012/014 (`relational-memory.md`) in what a real
player does, and lists what is still genuinely open.

## The exploit this closes

Before DEF-014, an apology was accepted when `standingWeight >= 0.5`, and
`standingWeight = trust + affinity + (respect + socialImportance)/2 − anger`. The
`ContactImpactPolicy` granted `affinity +0.02, respect +0.05, socialImportance +0.05` for
every inbound apology — accepted or not. So the loop a competent attacker runs is:

1. wreck the AI's fleet → DEF-011 scars: `trust −0.20`, `threat +0.30`;
2. spam apologies/greetings → each message inflates affinity/respect/socialImportance;
3. once anger has decayed a little, `standingWeight` crosses 0.5 → the apology is accepted,
   without a single unit of compensation ever delivered.

The wound was forgivable by the same cheap talk that should never repair it. DEF-014 fixes
it at the root: forgiveness reads `trust` (which only a kept promise raises), so no volume
of words forgives a fleet wipe.

## The model (what a pro player actually does)

- **Cheap talk (apology, greeting, thanks) warms a relationship but never forgives.** Being
  spoken to raises affinity/respect; it does not make a raider trustworthy again.
- **Trust is only earned by a kept promise.** The one upward path is DEF-012: the
  counterparty promised compensation and actually delivered. That is the human standard —
  you trust the person who paid you back, not the person who said sorry.
- **Anger is transient, the debt is not.** Anger decays and stops blocking, but it never
  settles the trust debt (`phase-3-cognition.md` line 118). A grudge reads as cold
  decisions, not a permanent reject: at high threat the AI refuses; at low trust it demands
  compensation; only earned trust accepts the apology.
- **Threat is the danger ceiling, not a punishment.** A single raid raises `threat +0.30`;
  three raids push it past the `0.75` refuse line. There is no apology fast-path down.

## Open questions (need research, not assumptions)

1. **Threat decay.** `threat` currently only falls via DEF-012's `−0.15`. Does a player's
   wariness of a past raider fade with a long quiet interval? Almost certainly yes, but
   slowly — "don't forget" and "eventually stop fearing" must coexist. A time-based decay
   (much slower than anger's `0.25/day`) with a floor is a candidate; it must NOT erase the
   `AttackReceived` fact, only cool the *current* threat read.

2. **Proactive befriending.** "Foes become friends" needs a first move. The AI only ever
   reacts today (`AttackerNotice`, replies). A genuine repair sequence usually starts with
   the *offender* reaching out — which means modelling the offender's own volition (offer
   compensation unsolicited), not just the victim's forgiveness. This is the CiF/PsychSim
   social-volition territory already reserved for drivers.

3. **Per-person emotion vs one scalar.** `AiAffectState` is a single anger per account, so
   a grudge against one raider cannot persist while the AI is warm to everyone else. The
   relationship score is the per-person signal that already reaches decisions, so this is
   tolerated today, but "angry at X specifically" is the difference between a grudge and a
   bad mood. Open: whether the per-person signal needs its own store or the relationship
   `threat` + `AttackReceived` fact is enough.

4. **The live fulfilment loop — SHIPPED 20 Sep 2026.** DEF-012 now has a production caller:
   `ObserveCommittedFleetMessage` observes the committed `transport_received` message and
   `RecordObservedTransferAction` reduces it to a `TransferReceived` observation, then fulfils
   the oldest accepted `ExpectedFromCounterparty` commitment whose full promised resource and
   amount the shipment covers (`FulfillAiCommitmentAction` → trust repair). A
   `CompensationOffer` is now recognised from an inbound message (harm acknowledged + a stated
   resource and amount), and a compensation promise without a stated deadline gets a 48-hour
   policy default at evaluation, so the commitment that a delivery settles can actually be
   created on the ordinary zero-token path. A partial delivery settles nothing.

## Non-goals (already decided)

- No LLM/generative calls in this path — native structured cognition, zero tokens.
- No second forgiveness mechanism beside the trust floor + threat ceiling.
- No apology-volume cap or "message quality rubric": the sources don't document one, so none
  is invented (same rule as `alliance-social-completion.md`).
