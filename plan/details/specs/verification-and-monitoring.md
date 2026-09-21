# Verification, cooperation testing, budget and monitoring — the situation dashboard

Written 21 Sep 2026. This is the plan that turns "does it behave like a real OGame player,
strategically and socially" into measurable, owned work. It reuses the review loop
(`improvement-loop.md`), the replay harness (`ReplayAiScenarioAction`), the capacity runs and the
owner console; it does not add a new subsystem.

## Why this document exists

The deterministic phase is complete (0 todo tasks). What remains is not more mechanisms — it is
**proving** the shipped mechanisms produce the observable a neighbour or operator would test, on the
existing cohorts (grand = normal, pve = cooperative), including the two generative lanes
(campaign consultation + language chat). The gap register's own rule is the method: *audit against
the goal, never against the plan*.

## 1. The verification matrix — one assertion per signal

Each of the eleven authenticity signals gets one named verification: the decision-engine signals
ship a deterministic replay scenario (`resources/scenarios/`, replayed by `ReplayAiScenarioAction`
at a frozen time and seed), and the signals owned by other mechanisms (social cognition, language,
scheduler, transfers) are verified by their own feature tests, named in the same column. A signal
with no named check is a gap, not an aspiration.

| Signal | Named verification | Assertion | Aggregate check (cohort) |
|---|---|---|---|
| 1 reaction latency + save | `miner-under-visible-raid`, `late-notice-doomed-save` (replay) | the save fires under inbound; a late notice is a doomed save | % reactions inside the 120–180 s window; save-failure rate > 0 |
| 2 uptime shape | `routine-21-days` (replay) | the dark period decides nothing | population wake-time spread; no 18-distinct-hour week |
| 3 growth curve | `economy-chain`, `throttle-mine-when-short`, `colonize-needs-development` (replay) | scarcity makes the mine the answer; mining while short; a colony is withheld until developable | no unexplained hourly spike; no flatline |
| 4 self-similarity | `divergence-two-accounts` (replay) | same observation, different persona → different decision | distinct decision reasons; action-sequence entropy |
| 5 social breadth | `SocialInitiationTest`, `AllianceChatLifeTest`, `AllianceApplicationReviewTest`, `BuddyRequestReviewTest` (feature) | thank-you fires; leader decides | Shannon entropy over interaction types vs 0.84 human baseline |
| 6 never losing a fleet | `late-notice-doomed-save` (replay) | a save that can fail is never 100 % | save outcomes show refusals, never 100 % |
| 7 message content/timing | `ConversationCycleTest`, `DeliverAiDirectReplyTest`, `AiLanguageReconciliationTest` (feature) | authored line from pool, never instant | delivered variant diversity |
| 8 request footprint | `AiActivityMarkerTest`, `DeterministicSessionLoopTest` (feature) | a session that queues nothing leaves `users.time` unmoved | — |
| 9 transfers/timing | `TransferObservationTest`, `TransferDepthTest` (feature) | one transport per hole (in-flight netting) | transfer receipts human-scaled |
| 10 identity at rest | seed-cohort (seed check) | staggered joins, rename pool, varied dark matter | — |
| 11 aggregate statistics | `spy-two-accounts` (replay) | a free slot probes a neighbour | military score not pinned at zero |

## 2. The provocation harness — the adversarial neighbour

The authenticity research says a suspicious neighbour has two instruments: probe/attack, and the
hourly score. So the highest-value test is a scripted neighbour that does what a real prober does
and records the account's answer:

```
probe   → record reaction latency (120–180 s, never instant)
attack  → record save success AND that a save sometimes fails
apologize (cheap)  → assert forgiveness is refused without compensation
offer + deliver compensation → assert trust repair + thank-you
watch 24 h → assert a real sleep window with daily drift
```

It runs against a **capacity universe** (2/5/10 accounts), never the holy `grand` DB, and writes a
machine-parsable report the review loop reads.

## 3. PvE cooperation and the generative lanes

The pve cohort is the cooperation testbed. Verify, not just enable:

- **Alliance cooperation** — one faction alliance, campaign objectives, contribution records; a
  human-side account and an AI account on the same objective resolve without disagreement.
- **Campaign consultation (LLM)** — the `campaign-consultation` lane (`mode: advice`), verified by
  `ai:cognition-conformance` and the opt-in sanitized `ai:language-conformance`: a real call
  returns a validated, profile-bounded recommendation; provider failure preserves the native
  decision.
- **Language chat (LLM)** — the `language` lane answers a substantive message with a validated
  proposal; a timeout settles at the reserved maximum and delivers the authored fallback.
- **Cooperative hostility policy** — two human-side accounts cannot attack each other; an AI-vs-
  human action is allowed. Re-prove on pve after every change.

## 4. The $10 budget

The two generative lanes are bounded by per-day token/attempt ceilings but have **no dollar wall**.
Add one:

- `config/cognition.php`: `monthly_cost_usd` (default **10**, `AI_MONTHLY_COST_USD`).
- `ReserveAiUsageAction` refuses a reservation once the month's **settled** cost reaches the wall.
- The operator page shows month-to-date spend against the wall, so a silently-failing paid lane
  reads as a lane difference, not a surprise bill.

## 5. Monitoring UI

Extend the owner console's monitoring tab with two sections, both read-only over rows that already
exist:

1. **Budget** — month-to-date `$cost / $10`, per provider, plus a "wall reached?" flag.
2. **Situation** — the five review-loop questions answered with figures and evidence class: stage of
   the capability chain, growth explicability, cohort divergence, reaction/save shape, and
   server-aliveness (meaningful interactions per window). This is the standing register, rendered.

## Slices

- `DEF-020` — verification scenario suite (the matrix above; 11 scenarios + their assertions).
- `DEF-021` — provocation harness (scripted neighbour + report, on a capacity universe).
- `DEF-022` — social-entropy and reaction-window aggregate measurement (feed the review loop).
- `DEF-023` — $10 monthly cost wall (`ReserveAiUsageAction` + config + tests).
- `DEF-024` — situation dashboard UI (budget + five-question review, operator page).
- `DEF-025` — pve cooperation + generative-lane conformance runs (documented, not code).

## Acceptance

- Every matrix row has a named scenario, an assertion, and a pass bar.
- The provocation harness report is reproducible under a frozen clock and fixed seed.
- `monthly_cost_usd = 10` refuses a provider call that would cross it, and the page shows the spend.
- The review loop records one dated file per window in `plan/details/reviews/` answering the five
  questions with figures and evidence classes.
