# Classification use plan: where reading meaning changes what the accounts do

Written 4 October 2026. Builds on [hosted-classification.md](hosted-classification.md) (the gateway, the gates,
the do-not-build list) and [the opportunity map](../research/jev-opportunity-map.md) (every hand-made judgement
in the module). This plan adds the **in-game-loop** use cases, the architecture that keeps them safe and
replayable, and the work order. Nothing here is built; it waits on `REV-8`.

## 1. The rule that decides every use case

A classifier answers a question about something the account **reads**: "is this X?", "which of these is it?",
"how much, on these levels?". It returns an answer with a probability and may abstain.

| Use it when | Do not use it when |
| --- | --- |
| The input is **words written by a person** (chat, private messages, alliance texts, applications) and today the module ignores them or matches them with a regex | The answer is a **number or a rule** the host computes (loot, combat, costs, legality, travel time) |
| A veteran would act on what they read ("he said he's going to bed, hit him now") | The decision is **a choice among game actions** (what to build, whom to raid). That is the planners' and later the trained policy's job |
| The answer can be **stored once** and read many times | It would be asked **every login** or every decision |
| A wrong answer costs little and a missing answer (provider off) leaves today's behaviour | The answer would **authorise** an action or pass a validation check |

So inside the game loop, classification is useful **only where other players' words should change
gameplay**. Text written by our own AI accounts is never classified: the module wrote it from a structured
intent and already knows what it means (AI-to-AI exchanges carry the intent, not prose to be re-read).

**Consequence:** the value of every in-loop use scales with the number of **human** players writing to the AI
accounts. On a server of AI accounts and one admin it is close to zero; on a server with real players it is
where the AI stops looking deaf.

## 2. Architecture: read on arrival, store a fact, decide from facts

```
human writes (chat, private message, alliance text, application)
        │ committed host row (never the event, same rule as observations today)
        ▼
ClassifyIncomingTextJob  (own queue lane, after commit, one per message, budgeted)
        │ ClassificationGateway (JEV-2): null when provider off · fake in tests and simulation
        ▼
answer + probability + model version  ──▶  ai_memory_facts (subject, predicate, value, confidence, expires_at)
                                                          │
login (no model call, ever)  ─────────────── reads facts ─┘──▶ planners / managers / later the trained policy
```

Properties this buys:

1. **The login never waits on a provider.** Sessions read stored facts only, so sim speed and live latency are
   unchanged.
2. **Provider off = today's behaviour, byte for byte.** No fact is written; every consumer treats "no fact" as
   it treats the world today.
3. **Replayable.** The fact table is game state. A seeded `ai:sim --in-memory` run replays the stored facts;
   simulations of new text use `Classification::fake()` with authored answers. RL training sees facts as
   features and never calls a model.
4. **Bounded cost.** One call per human message (or per application, per alliance text change), never per
   login. At ~500 tokens and $0.042 per million input tokens, 100,000 messages cost about $2.
5. **Auditable.** The raw probability and model version sit beside the derived value (`jev-1.13.0` pinned,
   never `jev-latest`). An operator can see why an account acted.
6. **Thresholds with abstain.** Each consumer acts only above its own confidence threshold; below it, the
   answer is stored but treated as absent.

## 3. In-game-loop use cases, ranked

### G1 ★ "I'm off to bed" and other declared absences → when to raid, when to wait

| | |
| --- | --- |
| Text read | Chat and private messages from players the account knows (target list, neighbours, alliance). |
| Questions | `Boolean`: does this declare the writer's absence or availability? `Choice`: horizon, from "leaving now", "back shortly", "back tomorrow", "back in days", "unknown". |
| Stored as | `ai_memory_facts`: `(writer, absent_until, confidence)` with `expires_at` = the horizon's upper bound (the column exists and nothing sets it today). |
| Decision it changes | Raid target choice and launch timing (`RaidPlanner`, `IntelBook` priority, the `RaidWave` continuation). A declared-absent target with a fresh report ranks up for this login; the account may also log in shortly after the declared departure (a material-event wake, like the build-finish wake in `SessionDecisionService`). |
| Why it is great | Exactly what a human raider does. Today the module discards the signal: `ActivityIntelReader` models "online on arrival" with one exponential, and "gn, off to bed" places as nothing. |
| Risks | False positives ("my brother is off to bed") → threshold ~0.8; the raid still has to pass `RaidPlanner`'s profit and survival checks. Players may bait with fake declarations, which is also realistic. |
| Measure | Share of human messages carrying a declaration (offline replay), precision on a hand-labelled set of ≥ 200 messages, raid success rate on declared-absent targets vs others. |

### G2 ★ Calls for help → defend allies before the hit, not after

| | |
| --- | --- |
| Text read | Alliance chat and private messages from alliance members and buddies. |
| Questions | `Boolean`: is this a request for military help? `Boolean`: for resources? Coordinates and timing come from the text **only if** they match an existing host mission or planet (validated, never trusted). |
| Stored as | Fact `(requester, help_requested_until, kind)`. |
| Decision it changes | `QueueableDefendPlanner` (ACS defend) today triggers from battle reports, i.e. after the attack. A help request with a hostile mission visible against that ally lets the defend candidate appear before impact. Resource requests feed the ally-gift path under `AllyGiftGuard`. |
| Why it is great | Alliances that answer "help, inbound in 20 min" are what makes AI allies feel real. |
| Risks | Abuse by humans asking AI allies for free resources → `AllyGiftGuard` limits stay authoritative, and help only goes to members/buddies. |

### G3 ★ Threats, ceasefires and deals → whom not to hit, whom to watch

| | |
| --- | --- |
| Text read | Private messages from players the account raided or was raided by. |
| Questions | `Choice` over authored kinds: "threat of retaliation", "plea to stop / offer of tribute", "ceasefire or non-aggression offer", "insult only", "none". `Score`: how credible (paired with the writer's measurable strength from the highscore, never instead of it). |
| Stored as | Facts per writer; a tribute or ceasefire offer becomes an **`AiCommitment` proposal** through the existing commitment actions (`AcceptAiCommitmentAction`), not a direct effect. |
| Decision it changes | `IntelBook` target priority (a credible retaliation threat from a stronger player lowers priority, a plea from a farm may be ignored by a raider persona and honoured by a "fair" one), `GalaxyMap` threat for the home system, fleet-save caution. The coercive regex today only moves relationship numbers. |
| Why it is great | Diplomacy by text is a large part of real OGame play between raids; it gives grudges and truces a cause a human can recognise. |
| Risks | The classifier never accepts a deal; the module's own commitment rules do. Credibility is checked against score, never taken from the text. |

### G4 Alliance-level declarations → war and peace targets

| | |
| --- | --- |
| Text read | Alliance broadcast messages and the alliance's internal text (`alliances.internal_text`). |
| Questions | `Choice`: "war declared on <alliance>", "peace/NAP with <alliance>", "general news". The alliance named must resolve to a real alliance tag in the host (validated). |
| Stored as | Facts `(alliance, relation, since)`. |
| Decision it changes | Raid target permission (skip NAP partners, prefer war targets), defend priorities. |
| Risks | Low volume; only matters on servers where humans lead alliances AI accounts belong to. |

### G5 Alliance choice and applications (from the existing map, items 2.1 and 2.2)

| | |
| --- | --- |
| Text read | `alliances.external_text`, `application_text`; `alliance_applications.application_message`. |
| Questions | `Score`: does the pitch read like a real, selective alliance? `Choice`: serious applicant / farm / spam / unclear. |
| Decision it changes | `AllianceChoice` (today `trim(...) !== '' ? 1.0 : 0.0`) and `ReviewAiAllianceApplicationsAction` (today rank and age only). |
| Cost | One call per alliance text change and per application. Tiny. |

### Not in-loop, on purpose

| Idea | Why not |
| --- | --- |
| Classify the account's own situation ("am I under threat?", "should I save?") | That is structured host state; computing it is exact and free (`ThreatResponsePlanner`, `FleetSlots`). |
| Pick builds, research, targets | Planner and trained-policy territory; latency, cost and replay all fail. |
| Read espionage or battle reports | Already structured data. |
| Trade negotiation | The module cannot execute player trades (map item 2.5). Refuse until a capability exists. |
| Reward or training labels for RL | The policy would learn to please the classifier. |

## 4. Out-of-loop use cases (support the loop, never run inside it)

| # | Use | Input | Output used by |
| --- | --- | --- | --- |
| O1 ★ | **Human-likeness judge** (Gate 3 at scale): "does this day read like an experienced player?", "is this account stuck or looping?", "which archetype does this look like?" | One account's day as human-readable lines (`bash scripts/ogamex account`) | Cohort dashboards and the RL evaluation report; calibrated against ~50 owner-scored days first |
| O2 | **RL win validation**: "smart play, exploit, or simulator bug?" | The top and bottom trajectories of an evaluation | The validity checks in `plan/rl/evaluation-plan.md` |
| O3 | **Harness triage**: "real regression or an expectation pinned to old behaviour?" | Failing test output plus the diff | The handoff and ledger review |
| O4 | **Strategy drafting**: "game rule or advice? which archetype? documented or contested?" | Guide or wiki sentences | Doctrine YAML drafts a human commits (`JEV-7` covers provenance) |
| O5 | **Host admin**: bot / multi-account evidence reading over the three existing bot-detection signals; offensive names | Admin views | An admin decides; never automatic |

## 5. Work order

| Step | What | Done when | Cost / risk |
| --- | --- | --- | --- |
| 0 | `REV-8` decision, key, `config/ai.php` provider entry, model pinned, monthly sub-budget | owner yes | — |
| 1 | `JEV-2`: `ClassificationGateway` contract, null + SDK implementations, ledger reservation; `Classification::fake()` wired for tests and `ai:sim` | provider-off returns a typed disabled result; a sim with `--seed` replays identically with the gateway faked | small |
| 2 | Offline measurement (`JEV-1` extended): hand-label ~300 real human messages from the cohort and test servers for G1–G4 kinds; replay through the gateway | precision ≥ 0.9 at the chosen threshold per question; volume per day per kind known | a few dollars |
| 3 | `ClassifyIncomingTextJob` + fact writing (no consumer yet), lane and budget | facts appear for human messages only; AI-authored text skipped; zero effect on decisions | small |
| 4 | **G1** consumer: declared absence in `IntelBook`/`RaidPlanner` priority and the departure wake | situation test: a human target says "off to bed" → the raider probes and raids within its waking window; provider off → identical to today | the first visible gameplay change |
| 5 | **O1** judge (offline command) | agreement with the owner's scores on 50 days; then a nightly report | supports both cohort and RL work |
| 6 | **G2** help requests → early ACS defend | situation test with a real inbound hostile mission | |
| 7 | **G3** threats/truces → commitment proposals and target priority | situation tests per kind; commitment rules unchanged | |
| 8 | **G5**, then **G4** | as in the opportunity map | low volume |

Each consumer ships behind its own setting, defaults off, and must pass the replay check: the same seeded
simulation with the provider off produces the same digest as before the change.

## 6. How this meets the trained policy later

The facts written by steps 3–7 are exactly the "social signals" the RL plan postpones to v3
(`plan/rl/training-plan.md` section 6): `target_declared_absent_minutes`, `retaliation_threat_from_target`,
`ceasefire_with_target`, `ally_requested_help`. They enter the policy as **features read from the fact table**,
in training and in production alike, so the trained model learns to use what people say without ever calling a
model itself. In simulation they come from recorded human messages replayed with their stored answers, or from
authored synthetic messages with faked answers, which keeps training seeded and fast.

## 7. Stop conditions

- Step 2 finds human messages with these kinds are rare on the servers you run (e.g. fewer than a few per day):
  build only O1 and O3, skip G1–G4.
- Precision below 0.9 at any threshold that still catches half of the cases: drop that question.
- Any consumer changes the provider-off replay digest: it is not behind its switch properly; fix before release.
