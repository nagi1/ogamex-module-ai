# Consolidation plan: from built to proven (10 Oct 2026)

Source: the outside architecture review of 10 Oct 2026 (Codex, written from the technical handoff), checked line by line
against the code on the same day. Where the review guessed, this file says what the code actually does. It sits under
`plan/HANDOFF.md` for order; it does not unfreeze any row.

## The verdict we accept

The module has more machinery than evidence. No new model, cognitive engine or dataset integration goes in until the
existing engine is shown to (1) keep its word, (2) not contradict itself, (3) only know what a player could know, and
(4) play the whole game. Every stage below ends in a check an observer could repeat, not a judge score.

Three rules for everything that follows:

1. Models never replace the host's rules, a player's legal information or a binding commitment.
2. A component stays only if it beats the simplest working alternative in a measured run (Gate 2).
3. Success is what the account does over time (Gate 3), measured on three separate axes, never one blended score.

## What the code says about the review's claims

| Claim | Checked | Finding |
|---|---|---|
| Promises do not constrain behaviour | **True for 1:1, false for alliances** | Alliance NAPs are enforced: `Enemies::spared()` stops raids and scouting on pact partners (`RaidPlanner` line ~194). A 1:1 ceasefire can never be accepted: `NativeSocialCognition::evaluateCeasefireRequest` ends in `CeasefireEnforcementUnavailable` every time. That is the "truce talk with no terms" every judge reading flags, and politics sits at 1/3. |
| Managers can undercut each other | **Plausible, not shown** | `ScheduleAiIntentAction` runs manager decisions beside `DecisionEngine` scoring. The probe shows a saving goal (terraformer) and a yard order (deathstar) in the same session. Needs a test that proves or disproves a spend that breaks a held saving goal. |
| Information hiding is convention only | **Unverified** | Raid targeting reads espionage reports (correct). No audit has run over every planner's queries for reads of another player's planets, fleets or research outside a report. |
| Judge 3/3 is weak evidence | **True** | Judge is an LLM reading of the end state, not validated against human raters; politics has stayed at 1–2/3 while war reads 3/3. |
| Late game is under-trained | **True** | Late-game rows are about 3% of the economy training data; worlds run 12 sim hours from a staged late start, not a lifecycle. |
| Performance is SQL, not the model | **True, deferred** | Alliance life ~820 statements per login; model inference 0.2 ms. Owner direction (16 Sep) is strongest account first, VPS profile later (`budgets.md`). Measured, not acted on. |
| Jev/LLM for human text only | **Already so** | Gameplay makes zero generative calls; the classifier and voice writer sit only on text. |

## Stages, in order

Each stage names its proof. A stage is done when its proof runs green in `bash scripts/ogamex prove`, not when the code
lands.

### 1. Keep the word: 1:1 ceasefires with terms (politics, first)

The one verified, unbuilt mechanic, and the reason politics stays at 1/3.

- A ceasefire request that names (or is offered) a window becomes an `AiCommitment` with both players, start, expiry and
  the violation condition (an attack or probe by either side). The evaluator accepts when threat is under the refusal
  line and the requester is not winning, rejects otherwise; the `CeasefireEnforcementUnavailable` reason goes away.
- `Enemies::spared()` also returns the players this account holds an accepted, unexpired ceasefire with, so raids and
  scouting already honour it with no planner edit.
- A breach (a battle report or espionage notice from the other side inside the window) marks the commitment broken,
  raises threat and drops trust; the account says so once (`ceasefire_broken` line) and the grudge clock restarts.
- The account itself breaks one only through an explicit choice (a war declaration by its alliance), never because a
  planner forgot.
- Proof: `situation:ceasefire-held` (a raider that agreed a 24 h ceasefire does not hit that player inside it, hits after
  it) and `situation:ceasefire-broken` (the other side hits, the account answers and holds the grudge).
- Observer sees: "can we call a truce" → "24h, you stay off me too" → no hits for a day → back to normal, or a betrayal
  that is remembered.

### 2. One plan per session: arbitration against held goals

**Result (10 Oct, read of the code): already closed, no code.** The saving goal is a reservation every spender honours:
`SavingsGoal::reserved()/spendable()` for builds, `QueueableUnitPlanner::starvesSaving()` for the yard, and
`ReserveFloor` for transfers. The one exception is deliberate and measured: a goal more than a day of production away
does not hold the yard (`SAVING_HORIZON_HOURS`, 7 Oct), which is why the probe shows a deathstar order beside a
terraformer goal. The proof below stays as the guard.

- Test first: a session with a held saving goal and an affordable yard or build order. Prove whether the goal is spent
  past. If it is not, close this stage with the test as the guard and move on.
- If it is: the saving goal becomes a reservation every spender subtracts before pricing (one shared reserved amount on
  the session snapshot, no new layer). Managers keep proposing; they cannot spend the reserve.
- Proof: `invariant:saving-goal-held` across a 12 h world (no spend breaks a held goal unless the goal itself is dropped).

### 3. Know only what a player knows

**First pass (10 Oct): no leak found in `app/Domain` and `app/Actions` queries on `planets` and `fleet_missions`.**
Cross-player reads are galaxy-view data (coordinates, owner, vacation mode, destroyed) in the spy, phalanx and
jump-gate planners, phalanx scans gated by the host's `canScanTarget`, and raid planning reads a copy of the planet
rebuilt from the espionage report (`ReportedPlanet`), never the live row. Still to audit: reads of `users`, research
and highscore columns, and the Rust battle-sim inputs. The invariant test below is not written yet.

- One audit pass: every query in `app/Domain/Decision` and the planners that reads `planets`, `fleet_missions`,
  `users_tech` or unit columns for a player other than the account. Each is either through a report/observation the
  account received, public data (highscore, galaxy view), or a leak.
- Each leak is fixed by reading the report or observation instead; a Feature test per fixed path.
- Proof: `invariant:no-hidden-reads`, a test that runs a session with a scripted query log and fails on a read of another
  player's private columns.

### 4. Play the whole game

Already the standing loop (`play-coverage.md`, probe, reach). What changes is the bar:

- Each aspect is read per world from the end database: raids per hour, recycles on own and foreign debris, saves under
  attack (and failed saves), moon shots, phalanx scans, jump-gate transfers, missiles, moon destruction, ACS, domes and
  ABMs built. A world counts only if every aspect is non-zero and not front-loaded into one hour.
- Scale: the 1e13 world (9487) answers whether fleets reach real-server size (OGMem battles: thousands of ships per
  side). If not, the staging budget and fleet templates move, data not code.
- Lifecycle: one long run from start toward late game, read with the same table, before any retrain is installed.

### 5. Three axes instead of one judge score

| Axis | Measured by | Not by |
|---|---|---|
| Competence | growth curve vs the OSTATS 24 h series, saves that succeed, battle loss ratios vs OGMem | judge prose |
| Human realism | uptime shape across the day, reaction latency to probes/attacks, action-sequence self-similarity, imperfect saves | message polish |
| Social continuity | commitments kept vs broken, relationships that change what the account does, distinct lines per world, greeting share | judge politics score alone |

The judge stays as a reader of the world, reported beside these, never as the gate. A small human-rated sample (owner
reads 20 chat excerpts blind, AI vs real) calibrates it once.

### 6. Learned policies, only where they beat the rule

- Economy: keep `ChoicePolicy`/ONNX. Retrain only after stage 4's lifecycle run, with late-game rows weighted up, and
  install only if the closed-loop run beats the teacher on the competence axis.
- Text: the act classifier is retrained on deduplicated, world-split readings (2,866 texts, 0.761 held out on later
  worlds); it is installed only if it beats the live classifier on a hand-checked ambiguous set. Rare acts (bot_question,
  trade, help_request, under 30 examples) are merged or relabelled first. The training corpus is the curated view
  (`rl/scripts/social_curate.py`), never the raw one.
- External human data (Diplomacy, STAC, negotiation corpora): used to evaluate reading of offers, conditions, threats and
  refusals into structured terms (stage 1 needs this), never to train gameplay policy, which needs OGame state and outcome.
- No graph model, no new cognitive engine. FAtiMA/PsychSim/AgentOS stay off until a controlled run shows they change an
  axis above.

### 7. Production scale (after the account is strong)

Measured, queued, not acted on now: alliance life's ~820 statements per login, repeated planning per session, admission
limits on the reference VPS. Opened when the owner moves from strongest account to many accounts.

## What we stop doing

- Treating a 3/3 judge reading as done.
- Adding a social line, voice or classifier label before the mechanic behind it has a consequence in play.
- Launching worlds to re-check code that has not changed since the running ones started.

## Next three actions

1. Stage 1: ceasefire as a commitment, honoured by `Enemies::spared()`, with the two situations. (Strong lane; one PR.)
2. Stage 2 test: does any spender break a held saving goal? Result decides whether stage 2 is code or just a guard.
3. Stage 3 audit: list every cross-player read in the planners; publish the list in this file before fixing anything.
