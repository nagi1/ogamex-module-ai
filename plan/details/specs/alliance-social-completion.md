# Alliance & social life — completion slices

Written 20 Sep 2026 from a multi-agent investigation: the module's alliance/social
implementation, the host alliance surface, and real OGame recruitment practice.

## Why

The module applies to alliances but never sits on the deciding side, so the one alliance it
founds stays at 1 member forever (19 pending applications in grand, 14 in pve), and alliance
chat has no members to serve. The join half is shipped (DEF-003); the leader half is not.

## Findings

### Module — what ships

- Found-first + apply, 10-minute pass (`AdvanceAiAllianceLifeAction`); `AllianceChoice` picks
  by rank-ratio tier (mass / mid / elite core) from host data; applies via
  `AllianceService::applyToAlliance` with an archetype-named message.
- Chat is reaction-only (inbound direct message), authored text; alliance chat is excluded —
  `RecordObservedChatMessageAction` drops any `alliance_id !== null` message, and every
  send/read side repeats the same `whereNull('alliance_id')` guard.
- The only initiation is WP-016 "message a new attacker". The single host write is
  `DeliverAiDirectReplyAction → ChatService::sendDirectMessage`.
- **No action calls the host `acceptApplication` / `rejectApplication`.** AI accounts apply;
  nothing ever decides.

### Host — what the module may drive

- `acceptApplication(id, actingUserId)` / `rejectApplication(...)` require
  `PERMISSION_EDIT_APPLICATIONS`; the founder (`rank_id = null`) holds every permission via
  `AllianceMember::isFounder()`.
- No member cap. Acceptance assigns the newcomer rank = highest `sort_order` (or null). Ranks
  are host data — never duplicated in the module (gate 1).
- Applicant signals: `User->highscore` (general / economy / research / military_built /
  military_destroyed / military_lost + their `_rank`s).
- `BuddyService` (requests / accept / reject) exists and is undriven.
- `sendBroadcastMessage` needs `PERMISSION_SEND_CIRCULAR_MSG`.

### Real-player criteria (sourced)

Sources: FOR-009 Alliance FAQ v2 (board.en.ogame.gameforge.com 450057), Gameforge alliance
guide, Pro Fleeters recruiting thread (board.us.ogame.gameforge.com 55011), wiki
Alliance / Application / Mass Alliance.

- Selectivity is **tier-driven**: mass = accept anyone active; elitist = rank threshold plus
  playstyle plus contact/interview; normal = mixed.
- **Hard declines**: below the points floor, wrong playstyle (a turtle into a fleeter
  alliance), inactive / over-vacation, begging, a fresh spy tell.
- **Leader behaviour**: acceptance is a rank-gated button, not a message; recruitment can be
  switched off (`is_open=false` → "this alliance does not accept members this time"); new
  members land on a probation "recruit" rank; inactives are kicked weekly; mass accepts in
  bulk, elitist requires contact first.
- **NOT FOUND — do not invent**: a growth-rate cutoff, a message-quality rubric, spy/farm
  screening at application time, a language check, or exact accept/decline wording and cadence.

## Slices

### DEF-006 — AI alliance leader reviews and decides applications (impl, P2)

- **Files**: `app/Actions/ReviewAiAllianceApplicationsAction.php`; `app/Domain/Social/ApplicationDecision.php`;
  `config/alliance.php` (new); call from `AdvanceAiAllianceLifeAction` after the apply loop;
  `tests/Feature/AllianceApplicationReviewTest.php`.
- **Behaviour**: for each AI-led alliance, read `AllianceService::getPendingApplications`,
  score each pending application with one sort key (tier fit by rank ratio, message presence,
  age), hard-decline the floor fails (no rank/points, playstyle mismatch against the alliance
  pitch), accept at most 1–2 per pass and only after a minimum application age, leave the
  uncertain middle pending for a later pass. Decide through the founder via
  `acceptApplication` / `rejectApplication`.
- **Proof**: a fit applicant is accepted after the age floor; a zero-point applicant is
  rejected; at most `maximum_accepts_per_pass` are accepted; the decision is deterministic;
  a rejected applicant is never reconsidered (host status); an undecided application stays
  pending to the next pass.
- **Gate**: gate 3 — a tier-selective leader accepting through a rank-gated button; gate 1 —
  thresholds are module taste, every fact read from host data; gate 2 — one action, one sort
  key, no second mechanism.

### DEF-007 — Leader welcomes an accepted member (impl, P3)

- **Files**: extend the DEF-006 accept path (or `QueueAiAuthoredReplyAction`) to send one
  short authored welcome line to the accepted applicant via `DeliverAiDirectReplyAction`;
  `tests/Feature/AllianceApplicationReviewTest.php` (one new case).
- **Behaviour**: after a successful accept, send a single, archetype-appropriate welcome;
  never send it twice; the host already places the member on the newcomer/probation rank, so
  the module writes nothing to ranks.
- **Proof**: accepting a member produces exactly one welcome chat message to them; a second
  pass sends none; a member accepted with no direct-chat availability still records the
  acceptance without error.
- **Gate**: gate 3 — a leader who communicates with a new member; gate 2 — reuse the existing
  authored-reply pipeline, no new transport.

### DEF-008 — Observe and answer alliance chat (deferred, P3)

- **Files**: `RecordObservedChatMessageAction` (drop the `alliance_id !== null` exclusion for
  alliance members), `RunAiConversationCycleAction`, `DeliverAiDirectReplyAction` (the
  `whereNull('alliance_id')` guards).
- **Gate to start**: this reopens the recorded S1 decision ("alliance chat is deliberately
  unobserved"). It is deferred until the owner confirms alliance chat should be read — and
  DEF-006 must land first, so the channel has members to speak.
- **Behaviour**: an AI member observes alliance-channel messages it did not write, classifies
  them with the existing exchange matcher, and answers through the authored path; never
  answers its own broadcast.
- **Proof**: an alliance message from another member is observed and answered; the AI never
  replies to its own messages; broadcasts from the leader are observed but only answered when
  they classify as an exchange.

### DEF-009 — Accept buddy requests from existing contacts (impl, P3)

- **Files**: `app/Actions/ReviewAiBuddyRequestsAction.php`; scheduled beside alliance life;
  `tests/Feature/BuddyRequestReviewTest.php`.
- **Behaviour**: an enabled profile accepts a pending buddy request only from an account it
  already has a relationship or social exchange with (S3: "accepted from an existing contact"),
  through the host `BuddyService::acceptRequest`; rejects the rest after the relationship
  floor.
- **Proof**: a request from a known contact is accepted; a request from a stranger is left
  pending or rejected; no duplicate acceptance; deterministic.
- **Gate**: gate 3 — a player accepts a buddy request from someone they know; gate 1 — the
  contact decision reads module relationship/exchange records, not a hardcoded list; gate 2 —
  one action, one query.

### DEF-010 — Observe an ally under attack (ACS-defend trigger) (deferred, P3)

- **Files**: a listener/observation for inbound fleets to alliance co-members'
  planets (module-side; the host fires the same fleet/battle events the module already
  observes), then the deferred ACS-defend action it unblocks.
- **Gate to start**: this is the trigger DEF-003 named for its ACS-defend deferral ("a defend
  decision needs an ally-under-attack observation first"). It stays deferred until DEF-006
  produces a second member to observe, then unblocks the ACS-defend behaviour itself.
- **Proof**: a committed battle report against a co-member's planet produces an
  `ally_under_attack` observation for the observer profile; no observation for the member's
  own planets.
- **Gate**: gate 3 — a member notices an ally being hit; gate 1 — the observation reads host
  battle data, no hardcoded ally list.

## Explicit non-goals

- A growth-rate cutoff, message-quality rubric, spy/farm screening, language check, or exact
  accept/decline wording: the sources do not document these, so they are not invented.
- Multiple simultaneous leaders / rank assignment: the founder is the single decider; spreading
  leadership is a separate slice if ever wanted.
- No member cap is enforced anywhere, because the host has none.
