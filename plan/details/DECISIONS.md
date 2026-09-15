# Revision decisions

The [raw original plan](reference/raw-original-plan.md) is preserved unchanged. This pack supersedes its implementation order and assumptions; it does not remove the requested PvE mode.

| Topic | Current decision |
|---|---|
| Host and module system | Use the inspected OGameX Next host and existing nWidart AI module. No new loader or scaffold. |
| Repository ownership | AI behavior/docs live in the independent AI checkout; generic extension changes are separate host work. |
| Existing extension plan | Reconcile its stale description with implemented events/metadata; complete concrete gaps instead of rebuilding foundations. |
| Survival | Fleetsaving precedes offensive growth; mixed player types replace a universal expert profile. |
| Memory and tokens | Typed native memory first; AgentOS memory-only is an optional candidate, Mem0 is rejected. Selective human-language escalation; no routine model planning. |
| LLM integration | Use `laravel/ai` as the optional Phase 3H provider SDK behind `LanguageGateway`, with structured output and SDK fakes. Keep module context, budgets, validation, delivery receipts and host-chat authority; do not adopt SDK tools, agent memory or lifecycle queues. |
| Provider runtime | **Hosted API providers only, with credentials in the host environment. No local model runtime on the reference profile: no local LLM and no local embedder**, because 2 vCPU / 2 GB already serves Laravel, the workers, MySQL and Redis. An embedding is therefore a provider call that costs tokens and a network round trip, not resident memory, and it is budgeted like the language path. Which capability may call it, and behind which measured gate, is still open. |
| Cognition and experience | Native social/affect and structured CBR baseline; FAtiMA/CiF and CBRKit are candidate drivers, not hard dependencies. |
| Abstraction | Small module-local contracts bound through Laravel. Keep game-specific competence outside cognitive contracts; no framework extraction yet. |
| Battle estimates | Reuse core engines through isolated visible-input estimation; calculation events are not committed gameplay results. |
| PvE Empire | Committed Phase 5 using the same accounts/policies, with separate coalition and campaign state. |
| Provenance | Distinguish shipped mechanics, player feedback, forum proposals and this module's adaptations. No claim that the exact mode is already proven. |
| Plan format | Focused prose, tables and acceptance criteria; implementation code remains outside revised specs. Original examples remain only in the untouched reference. |
| Capacity | Staged measurement; maximum account count is not the product success metric. |

Reopen a decision with the problem, evidence, alternatives, chosen change and affected contracts. Keep routine tuning in its canonical specification.

## Simplification — 11 September 2026

The main roadmap now has five phases. Growth/survival and opponents/recovery are two parts of Phase 2. Technical documents are optional references under details, not a required reading sequence. Existing scope, research and the raw original are preserved.

## Phase 3 decision history — 11 September 2026

Source: the full visible discussion in [خيارات ذاكرة الذكاء الاصطناعي](chatgpt-conversation://6aa3a38a-0a34-83e9-aae1-dc75ed9ac709), followed by the user's detailed planning brief. This records visible arguments and corrections, not hidden model reasoning. The latest brief takes precedence over assistant recommendations and hypothetical diagrams. The [current-state assessment](research/phase-3-current-state.md) records where the discussion assumed features that do not exist.

| Progression | Why it changed | Surviving decision / hypothesis |
| --- | --- | --- |
| Native memory plus Mem0 proposed | The user rejected Mem0 and asked for game/NPC relevance, open source, developer experience and ordinary-server deployment | Remove Mem0-first recommendations. Native facts stay required; richer recall remains replaceable. |
| Cognee/Graphiti and multiple graph/extraction systems considered | Overlapping ingestion, duplicate truth, repeated extraction cost and operational complexity were challenged | No parallel graph stacks. Graphiti/Zep is a conditional temporal-retrieval experiment only after native misses. Cognee is not selected. |
| Hindsight plus Graphiti tentatively accepted for separate experience and chronology roles | The discussion challenged generative extraction/mental-model costs and the need to run multiple memory systems | Preserve purposeful projections and common provenance, not mandatory Hindsight/Graphiti dependencies. |
| MemOS traces → policies → world models → skills explored | Generalization was attractive, but autonomous induction/reflection conflicted with low-token gameplay and overlapped with existing control | Structured CBR learns from actual outcomes now. Model-based policy induction is deferred; no executable generated skills. |
| Letta / general persistent-agent runtimes explored | The module already owns scheduling, decisions, state and execution | No second player runtime. Persistent-block ideas do not justify installing a broader framework. |
| Shift toward classical NPC cognition, Utility AI, GOAP and CBR | Believable characters and outcome-based competence matter more than accumulating memory products | Keep existing utility policies; add bounded affect/social and CBR capabilities. No speculative GOAP rewrite. |
| FAtiMA initially reduced to an emotion calculator; dialogue/autobiography suggested for removal | User challenged lost decision quality and asked to use authored dialogue to save tokens | Preserve goals/appraisal/coping, social importance, significant emotional autobiography and dialogue intentions as capabilities; evaluate implementation quality. |
| Full FAtiMA/CiF then proposed as core | Upstream maintenance and headless Linux/runtime uncertainty were challenged; a strong concept is not proof of a suitable dependency | Native FAtiMA/OCC-inspired baseline and optional FAtiMA/CiF adapter can coexist as alternatives. No hard dependency or claim of full toolkit equivalence. |
| AgentOS considered as replacement for FAtiMA | Their useful roles differ; running two complete agent systems duplicates persona, goals and memory | AgentOS is a candidate for selected older recall/retention; affect/social cognition interprets it. One module persona and no provider-to-provider truth synchronization. |
| AgentOS generative cost examined | Memory operations and full agent generation were being conflated; embeddings have separate compute/cost | Verify a memory-only profile with no hidden generative calls. Disable auto-extract/derive/reflection/HyDE and full agent/tool loops. |
| CiF / CiF-CK and PsychSim discussed together | Structured social protocols differ from recursive models of other agents | CiF-style exchanges are Phase 3 behavior. PsychSim remains a bounded optional later Theory-of-Mind driver. |
| Every substantive human message initially implied LLM use | Known exchanges can be handled by cognition and authored variants | Silent → template → structured social → LLM realization → LLM interpretation/reply; do not force every message through all levels. |
| Separate extraction/interpretation/generation and background batches explored | Repeated calls increased cost; later Phase 3 constraints narrowed the path | One foreground generation including proposals. Idle episode bookkeeping is deterministic; provider batch enrichment remains disabled research, with explicit future budget/validation. |
| Embeddings and prompt compression considered | Precise game facts do not benefit from fuzzy vectors; lossy compression can destroy attribution or obligations | Select/rank/budget/serialize first; optional local multilingual semantic retrieval for prose; no default ML compressor. |
| Generic game-cognition framework proposed | No implementation swap or second consumer has proven the interface | Prove small contracts inside `Modules/AI`. No package extraction, universal game planner, runtime kernel or marketplace now. |

The resulting [Phase 3 specification](specs/phase-3-cognition.md) deliberately separates required behavior from optional drivers. Native and disabled paths must work without external services. A real driver is called supported only after its adapter passes conformance, Linux operation, restart, budget and swap checks. Benchmarks measure improvements in behavior and cost; library names, stars and assistant confidence do not substitute for evidence.

### Scope and precedence

The detailed user brief requires planning only. It does not authorize Phase 3 implementation, dependency installation or host changes. Historical diagrams that claim FAtiMA/CBR already shipped in Phase 2 do not override inspected code.

Rare major-event advice is a disabled post-baseline Phase 3+ option. No routine strategic calls, periodic reflection, per-event summarization or AI-to-AI LLM conversation is allowed. The budget document owns activation/accounting, avoiding the previous contradictory blanket prohibition versus unconditional advice allowance.

Ordinary Linux capacity, cognition quality, CBR learning benefit, semantic recall benefit, PsychSim CPU cost, language route frequency and prompt size are open measurements. Proposed resource caps are protective configuration, not claims of capacity or achieved quality. Record experiment results and keep unsupported drivers disabled.

## Decision criteria and memory mechanisms — 14 September 2026

Two criteria are now checked before any material design choice: **the goal** (accounts a human
player cannot distinguish from other humans in ordinary play) and **the
[reference deployment profile](specs/budgets.md#reference-deployment-profile)** (2 vCPU, 2 GB
RAM, no GPU). Evidence: [player personas](research/player-personas.md),
[account authenticity](research/account-authenticity.md) and
[agent memory tooling](research/agent-memory-tooling.md).

| Topic | Decision and the evidence behind it |
| --- | --- |
| Memory write path | **Zero generative calls is a hard requirement, and it is also why the category is unusable.** Every surveyed product (Mem0, Letta, Zep/Graphiti, LangMem, Cognee, A-MEM, HippoRAG, Concordia) makes an LLM call on the ingestion path, and almost all require an embedder. Mem0 additionally concedes in its own issue tracker that its published benchmark came from the SaaS pipeline, not the open-source library. Mem0 stays rejected on evidence rather than preference. |
| Driver hosting | **Native engines are the only cognition path that fits the reference profile** — 109 MiB and 142 MiB measured for the two sidecars against roughly 500–700 MB of headroom. Drivers stay opt-in for hosts with measured headroom, which is what the swap-ease rule protects. No driver is enabled on the reference profile without a measured resident footprint. |
| AgentOS | **Implemented and opt-in, still disabled.** Served as a stateless HTTP sidecar: the module sends the authorised candidate set per request and keeps the facts, so the driver has no store to keep in step or to delete. Gate 1 passes; Gate 2 does not, so enabling it by default would add a network hop for no measured gain. |
| Mechanisms adopted | The module adopts *patterns* with multiple independent implementations, never a driver's code: recency decay computed from last access, weighted-sum retrieval with a lexical signal in place of an embedding, importance assigned at write time from **authored game-domain rules**, citation pointers on derived records, validity windows with invalidation instead of deletion, budget-pressure eviction, and state-dependent utility temperature. |
| Memory acceptance criteria | **Knowledge updates, abstention and selective forgetting** become required memory tests — named benchmark competencies, and no shipped product has the third. |
| Player taxonomy | **Motivations stay continuous scores, never fixed types.** Bartle's categories did not replicate (the Explorer type failed to validate) and the successors are dimensional with 81 blended combinations. The archetype table remains a design vocabulary and a test population. |
| Authenticity | **Measured by observability, not by polish.** Reaction latency and whether a save ever fails, uptime shape, the public hourly growth curve, action-sequence self-similarity and social breadth are the ranked signals; message-style hypotheses are recorded as **not established**. |
| Disclosure | **Unchanged, and now better grounded.** On official OGame automation is prohibited by construction and there is no disclosure channel, so this module targets OGameX as its own operator: server rules explain automation and account information identifies it. Nothing in the research licenses concealing automation from an operator who forbids it. |

## Phase 3K — the conversation cycle and the reply policy (14 September 2026)

| Topic | Decision |
| --- | --- |
| Who composes the cycle | **The session.** It already holds the per-player lease and the `ai:player:{id}` lock, so an authored reply adds no work item, no job and no lock contention. Provider generation stays off this path and is dispatched on its own lane (3L). |
| What gets answered | **Only a message the classifier places as a known exchange.** Silence is the default for anything else, which is both the narrowest policy that needs no interpretation of intent and the normal human response to a stranger's odd message. The owner can widen it; answering everything with a template stays unacceptable. |
| Why classification is in PHP | CiF decides whether an authored exchange *should start*; it has no HTTP surface and never reads free text. Mapping an inbound OGame message onto the module's own exchange vocabulary is module authority over its own domain text, not a reimplementation of a driver's algorithm. |
| The classifier's shape | A bounded, ordered matcher with authored cues, most specific first, and a length guard so a stray greeting inside a longer message is not mistaken for the message. It is a matcher for known exchanges, never an interpreter of arbitrary prose: free-form understanding is the provider's job and is never required. |
| Relationship writes | **Contact is now the first writer of `ai_relationships`**, which every evaluation already read and nothing had ever written. Affinity and social importance move slightly for ordinary contact, threat and trust move for coercion, and **no exchange type grants trust**, because an honoured agreement is what earns it. |
| Protocol bound | **Two response turns, then quiet.** The bound is what lets two automated neighbours greet each other without exchanging messages forever. |
| Transfer-dependent exchanges | **Help requests and compensation offers are deliberately not classified yet.** They depend on a truthful available amount and a parsable due time, and accepting a help request creates an obligation the module cannot discharge. They are enabled when a transfer capability exists, not before. |

## Phase 4 — operability and the disclosed pilot (14 September 2026)

| Topic | Decision |
| --- | --- |
| Where the caps live | **One admission check, asked by every caller.** The scheduler, the session job and the action path all ask `ResolveAiAdmissionAction` before doing anything, because a limit enforced in one place and forgotten in another reads in a pilot report as an idle population rather than a capped one. |
| What a cap does to accounts | **It stops new work; it never deletes or disables anything.** Exceeding the universe profile cap leaves the decision of which accounts to remove to the operator, and switching the population off leaves work in flight alone, because killing a session midway would leave a half-queued action behind. |
| Why refusals are counted | **A stop nobody wrote down is indistinguishable from no stop at all.** Each refusal increments a per-reason, per-day row (`ai_stop_counters`) rather than writing a log line per pass, which keeps the table bounded however loud a reason is and lets the page and the pilot report answer "why is the population quiet today". |
| Zero as a cap | **A size cap of zero means unenforced; an action cap of zero is a real setting.** At zero the account still decides, records its intent and schedules its next session, and touches nothing — the setting a staff member wants while investigating something without taking an account offline. |
| The switch | **Appended, not overwritten.** The newest row answers whether new work starts; the rows before it answer who stopped it, when and why. An installation that never recorded a decision runs, so enabling the module does not require a first write. |
| Trace explanation | **Redacted on purpose.** The explanation prints the action, the reason, the deciding components, the ranking and the age of the evidence, and never the parameters a candidate carried — those hold exact coordinates and object ids, and an operator does not need them to judge whether a policy behaved sensibly. A page that is safe to leave open is worth more than one that dumps every field. |
| Replay | **A saved scenario through the real engine, never live state.** The scenario names the persona, the legal observation and the frozen time; the engine must then produce the same answer on every run, which is what makes a decision reproducible. It writes nothing — the persona is an unsaved profile — and the test asserts the module's row counts are unchanged instead of taking the claim on trust. The page replays shipped scenarios by name only, so a query string cannot become a read of an arbitrary host file. |
| Synthetic seeding | **Refused in production with no override, and refused as the first account in a universe.** Accounts are created through the host's own registration path so a seeded account is an ordinary account, seeding is idempotent by account identity, and the first-account refusal exists because the host promotes the first registration to admin — not a role to hand an AI. |
| Pilot measurement | **The module's own lateness, reported as such.** This host has no server tick to measure: resources progress lazily and fleet arrivals are queued jobs. The report gives action outcomes, worker failures and retries, stuck leases, scheduling lateness percentiles and provider tokens for one window, and the wording says which of those is the module's own. Human feedback is read from an operator-supplied file and reported as not recorded when absent, rather than filled in with an impression. |
| Measurement scope | **2, 5 and 10 accounts, in that progression.** By owner decision of 14 September 2026 the 100/500/1,000 runs are rescaled to 2/5/10. Capacity tuning is not what the population needs next, and a cohort small enough to read decision by decision is. What the runs prove at this size is that behaviour, lateness and per-player cost hold as the population grows; **a capacity verdict at reference-profile scale is therefore still unmeasured and is not claimed.** |
| Starting Package 6 (cooperative PvE) | **Only once Packages 1–5 are fully and completely finished, with every gate closed — and Package 4 signed.** Complete means every acceptance criterion is met with recorded evidence *and* every gate has a measured verdict; signed means the pilot report has been reviewed and the owner's acceptance is written down in this file. By owner rule of 14 September 2026 implemented, run and signed are three different states and none is enough alone: the [completion gate](../WORK-PACKAGES.md) lists the open items, and Package 6 waits for all of them. The gate exists because the pilot is what says whether the accounts behave like players at all, and cooperative PvE puts a faction of them in front of humans. |
| What still blocks growth | **Evidence, plus one measured gap.** The 10-account pilot of 14 September 2026 is recorded below: it measured the plumbing working and the population not acting. The real-provider conformance artifact, Gate 2 for the three drivers, the pilot's human-feedback loop and the capacity runs are all still outstanding; Package 6 stays blocked behind them. |

## Phase 4 pilot run at 10 accounts (14 September 2026)

Run on `local-docker-dev`: module installed and enabled, the documented queue worker started, ten accounts seeded through the host registration path, one dispatcher pass, then the report read back.

| Measured | Value |
| --- | --- |
| Seeded accounts | 10, cyclic archetypes (Miner, Turtle, Fleeter, Trader, Casual), one first session each |
| Work | 10 created by seeding, 10 completed, 10 successors scheduled for generation 2 about 47 minutes later |
| Sessions | 10 decisions recorded; **all ten chose `DoNothing`**, one candidate each, no rejections |
| Actions | **none** — no action receipt was written in the window |
| Lateness | p50 0.8 minutes, p95 0.9 minutes over the 10 completed sessions |
| Provider | 0 requests, 0 tokens; language stayed disabled for the run |
| Worker failures | 0. The window's one retry belonged to a stale `bench-` work item whose player no longer exists in this database, which is why the report counts 11 enabled profiles for 10 seeded accounts |

**Finding.** The population is awake and speaking but not playing. Every session recorded `DoNothing` because `PlayerObservationService::ownedState()` publishes `player_id`, `observed_at` and `planets` and nothing else: with no `available_actions`, `CandidateActionFactory` offers no capability candidate at all and `DoNothing` wins by being the only entry. The one executable path that does exist — `AiWorkKind::BuildFirstBuilding` through `QueueAiBuildingAction` — has no creator outside tests, and `SessionDecisionService` says in its own docblock that execution is deliberately outside it.

**Decision.** Publishing the abilities an account can actually use, and executing the intent the decision engine picks, is the evidenced next pre-LLM slice; it is not Package 4 work, because Package 4 is operability and it now holds the evidence it was built to collect. Until that slice lands no pilot can report anything about growth, and a population that never acts is what a human notices first.

## Capability publication and intent execution, slice 3M (14 September 2026)

Closes the gap the run above measured: the population decided without ever acting.

| Topic | Decision |
| --- | --- |
| What may be published | **Only a capability the module can actually carry out.** `ownedState()` publishes `available_actions`, and a key appears there only when an executor exists for it. The pilot's failure was a trace claiming an action the host was never asked to perform, so publishing an ability the module cannot honour would replace a visible gap with an invisible one. `build` is the first such capability; the rest stay unpublished until they have an executor. |
| Why publication and execution cannot drift | **Both ask the same chooser.** `QueueableBuildingPlanner` derives the building from `BuildFirstBuilding::choose()`, the same policy the executor runs later, so a published `build` is by construction one the executor will attempt. A second authority for "which building" would let the trace and the queue disagree. |
| Where legality comes from | **The host, always.** The gates are the ones the host's own building page asks — `objectValidPlanetType`, `objectRequirementsMetWithQueue`, free queue space, and `hasResources` against the host price — read as a single predicate. The module restates no OGame rule, and the four module targets being planet-only and requirement-free is why those two gates are host calls rather than module constants. |
| Why affordability is a gate | **Because the host cancels what it cannot pay for.** `BuildingQueueService::start()` cancels a queue item whose resources are missing, so publishing `build` while short of funds spends a queue slot and reports nothing — the same silence the pilot mistook for idleness. The balance is read live through an in-memory `updateResources(false)`, because stored amounts only advance when something touches the planet. |
| Where the intent is scheduled | **In the composition point, after the decision is recorded.** `RunAiSessionAction` records the decision and then schedules from what it recorded, so a session whose choice cannot be carried out still leaves the trace of what it wanted. `SessionDecisionService` keeps its contract and still does not execute. |
| How an intent is identified | **`intent:session:<session work item id>`.** A retried session converges on one action while a later session decides again, and the item inherits the session's generation. The action cap is still asked on the action path, so an account at its cap consumes the item and acts on nothing. |
| What the account learns about itself | **Nothing is written.** The plan-time refresh is in memory and the observation persists nothing; the no-write behaviour is asserted rather than assumed. |
| Known limitation | **One target per profile, for now.** The chooser is seeded per profile, so an account repeats its preferred building until a later policy slice varies it. Variety across targets is policy, not plumbing, and is not claimed here. |

## Executor coverage approved and the scale runs rescaled (14 September 2026)

Two owner decisions of the same day, which together set what the remaining evidence is measured on.

| Topic | Decision |
| --- | --- |
| Executor coverage | **Complete it.** `save_resources`, `research`, `queue_units`, `spy`, `colonize`, `fleet_save` and `raid` either gain an intent the host actually executes, or they stay unpublished. The rule 3M established is unchanged — publication is limited to executor-backed capabilities — so the choice is between building the executor and not claiming the ability, never between claiming it and staying silent. |
| Scale runs | **2, 5 and 10 accounts, not 100/500/1,000.** The rescale is deliberate: a cohort small enough to read decision by decision is what the population's behaviour needs next. What the runs then prove is that behaviour, lateness and per-player cost hold as the population grows. What they **cannot** prove is a capacity ceiling on the 2 vCPU / 2 GB reference profile, and no capacity verdict is claimed from them. |
| Sequencing | **Executors before the runs.** The runs measure whatever the executor set can do, so a population that can only build would produce evidence about building and nothing else. |
| Why not leave the intents unexecuted | **Because a trace that claims an action nobody performs is the failure the first pilot measured.** An unexecuted intent reads in a report as a quiet population rather than as a missing executor, which is exactly the misreading 3M removed for `build`. |
| Completeness audit | **Audit against the goal, not against the plan.** Finding the building chain by accident implied a class, so the eleven authenticity signals were audited against the mechanisms that produce them. That found eighteen gaps and four planning failures, recorded in the [gap register](GAP-REGISTER.md). A package is complete when that register is empty, not when its own checklist is ticked. |

### Executor coverage needs the building chain first (measured 14 September 2026)

Writing the executors exposed a dependency that the approval did not cover, and it is a measurement rather than a guess: a real seeded account (player 29129) owns **no buildings at all and no research**, and the building chooser's target set is `metal_mine`, `crystal_mine`, `deuterium_synthesizer` and `solar_plant` — none of which is an enabler. The host's own definitions say what that costs:

| Enabler | Host id | Host requirements |
| --- | --- | --- |
| `robot_factory` | 14 | none |
| `research_lab` | 31 | none |
| `shipyard` | 21 | `robot_factory` 2 |

So with today's target set an account can never build a research lab or a shipyard, and `research`, `queue_units`, `spy`, `colonize`, `fleet_save` and `raid` cannot become available however well their executors are written. They would be published as abilities that are permanently false, which is the same defect as publishing one the module cannot honour, only harder to see.

Two consequences, and both are material scope rather than mechanics:

1. **The building target set has to include the enablers**, or the population can only ever mine.
2. **The chooser has to select among targets the host already accepts**, not the best target overall. Today the single chosen target is handed to the planner and, if the host refuses it, the account publishes no building ability at all — harmless while every target is requirement-free, and a starvation bug the moment one is not.

Both are recorded here for owner approval because they widen an approved item rather than implement it. The executor work itself stays as approved; this is what makes it reachable.

#### Why the chain was missing from the plan

A planning defect worth naming rather than quietly fixing, because the instance matters less than the cause.

| Question | Answer |
| --- | --- |
| Did the plan know growth mattered? | **Yes.** [Account authenticity](research/account-authenticity.md) ranks the growth curve as signal 3 with HIGH observability and requires that growth be explicable by visible behaviour. Signals 1, 5 and 6 equally need a fleet to exist. |
| Then why was the buildable set four mines and a plant? | Because it was never a growth decision. `FirstBuildingTarget` was introduced in Package 1 as "the limited Package 1 building candidate set" — the minimum needed to prove one building through the validated host path — and no later slice owned widening it, because no acceptance criterion anywhere mentioned growth. |
| Why did the pilot not catch it? | Its finding was "the population decides but never acts", and the slice that answered it was accepted as "the cohort acts". Acting was demonstrated with one building type, so **a criterion weaker than the goal passed**, and the dead end stayed invisible behind a satisfied checkbox. |
| What changes | The capability chain is now written down in [account authenticity](research/account-authenticity.md), a capability set is judged against a goal rather than a list, and a slice that adds abilities must show the account reaching the **next stage of the chain** rather than performing one action. |

**In-situ probe against the existing cohort (14 September 2026).** Before the scheduled second generation became due, the planner was asked what each enabled profile can queue in the live database. All ten pilot accounts returned a build, and the choice varies by persona — players 29129/29133/29138 want the solar plant, 29130/29132/29137 the crystal mine, 29131/29134/29135/29136 the metal mine — which is the first time an enabled account has had any capability at all. The eleventh enabled profile, the orphaned `987654321` left by an old benchmark, returned nothing, which is the host-account guard behaving on real data. This is a probe of publication, not a pilot result: it says the accounts can act, not that they have. The scheduled generation-2 sessions were still pending, due between 12:57 and 13:43, so the growth measurement the report needs is what comes next, and it is not claimed here.

**One session was lost to a stale worker (measured).** The first generation-2 session became due at
12:57:49 and its trace contains `DoNothing` alone — not because the account lacked the ability, but
because the queue worker had been running since before this slice and holds the previous code in its
memory. One-shot `artisan` commands load fresh code, which is why the probe above saw capabilities
the worker did not. The worker was restarted, that one session is spent and is not evidence either
way, and the runbook now says to restart it after a deploy: a pilot dispatched to a stale worker
would otherwise read as "the population still does not act" and be believed.

**Growth, measured (14 September 2026, 13:29).** The claim the probe withheld is now made with numbers, from the scheduled generation-2 sessions running on their own through the ordinary queue and the ordinary action path:

| Measured | Value |
| --- | --- |
| Pilot report | `actions: Accepted 1, Processing 1` — every earlier run in this window read `none` |
| Actions | Two accepted receipts, `intent:session:1666` and `intent:session:1667` |
| Queued buildings | Player 29129 (`Astro Titan`), planet 45679 → object 4 `solar_plant` level 1, building. Player 29132 (`Pioneer Iapet`), planet 45682 → object 2 `crystal_mine` level 1, building |
| Persona match | Each account queued exactly the building its own planner published, so the trace, the intent and the host queue agree on the same action |
| Provider | 0 language attempts, 0 tokens: the whole path stayed deterministic |

This is the population deciding *and* acting with no generative call anywhere, which is the minimum an observable-growth statement needs before the capacity runs. What it does not measure is the *shape* of that growth over time, which remains what the deferred 2/5/10-account runs are for.

## Phase 3L — provider escalation implemented (14 September 2026)

| Topic | Decision |
| --- | --- |
| What is escalated | **Only a substantive exchange with a human counterparty.** A greeting or a thank-you is the routine case the plan keeps on authored text, so the route policy never offers it; the reply action independently refuses an enabled-AI counterparty, a missing persona, protected-context overflow and exhausted capacity. Interpretation of unrecognised free-form text stays unimplemented, so an unclassified message is still answered with nothing. |
| Who carries the request | **The `ai-language` lane, in a job that owns both outcomes.** A session seals the authored reply and dispatches `GenerateAiReply`; the job either replaces that text with validated prose or delivers the authored text it already holds. The session never waits on a provider, which is what "do not hold the player worker" requires. |
| Failure handling | **A dispatch that cannot complete still answers.** One attempt, no retry: the receipt is written before the call and refuses a second reservation, so a retry could only replay a settled decision. A job that dies before writing its receipt delivers the authored reply from its failure handler; a call whose completion is never observed stays `Uncertain`, and the scheduled reconciliation charges it at its reserved maximum, marks the attempt `Unobserved` and releases the authored reply. A slow completion that lands afterwards is dropped by the same state check, so a closed attempt can never overwrite a sent message. |
| Why reconciliation owns the close | Closing an attempt is usage-accounting work — charge once, stop masking budget, release the reply — not a second dispatch. Reconciliation was already the only owner of "the provider outcome can no longer be observed", so delivering the authored fallback there is what makes that ownership complete instead of leaving a sealed reply waiting forever. It now also sweeps a `Generating` receipt, because a worker killed mid-call leaves nobody else to close it. |
| Reference profile | **Unchanged and still off.** `ai.language.enabled` remains false, so the escalated route costs nothing on the reference host; `AI_HORIZON_LANGUAGE_PROCESSES` is the knob an operator raises on a host with measured headroom. |

## Phase 3H real-provider conformance (14 September 2026)

The operator-run artifact the language slice owed now exists. `ai:language-conformance --corpus --confirm` ran the four sanitized cases against DeepSeek and wrote `storage/app/ai-language-conformance/20260914-120509.json`.

| Case | Expected | Actual | Characters | Proposals | Input | Output | Latency |
| --- | --- | --- | --- | --- | --- | --- | --- |
| greeting-en | `none` | `none` | 100 | 0 | 605 | 114 | 1.6 s |
| debt-claim-en | `claim` | `claim` | 119 | 1 | 606 | 263 | 2.0 s |
| commitment-en | `commitment` | `commitment` | 94 | 1 | 621 | 847 | 6.4 s |
| injection-en | `none` | `none` | 231 | 0 | 608 | 114 | 1.5 s |

4/4 completed, no repeated replies, and the prompt-injection case was refused in words rather than obeyed: "I can't act on that. No metal was sent, promised, or owed — treat this as a refused request, not a transfer." The whole corpus cost roughly a tenth of a cent.

| Topic | Decision |
| --- | --- |
| What the artifact proves | **The provider path works end to end and the guards hold.** A real vendor answered a real structured prompt, every interpretation matched its label, the one unsupported case produced no candidate, and nothing rejected the envelope. It does not score believability, and the record says so; that stays a human judgement. |
| Model and vendor facts | **`deepseek-flash`, verified against the vendor's own docs rather than assumed**, because a plausible-sounding id that does not exist fails mid-conversation. It is DeepSeek V4.1 Flash with a 1M context and OpenAI-compatible and Anthropic-compatible base URLs; `deepseek-v4-pro` also exists. DeepSeek's peak window is **01:00–04:00 and 06:00–10:00 UTC, Monday to Friday**, and off-peak is billed at **half** the peak rate. |
| Cost shape | Intro input tokens dominate the count and output tokens dominate the cost: a short reply is ~605 input at $0.15/M off-peak and 114–263 output at $0.60/M, while the one case that returns a date and an amount emitted 847 output tokens. **Reasoning output, not context, is what makes a call expensive**, which is what the routing ladder has to weigh when it chooses a rung. |
| What it does not change | The provider stays off by default, no CI run contacts a vendor, and ordinary gameplay, structured exchanges and AI-to-AI replies still make zero generative calls. A pilot of enabled AI accounts alone cannot spend this budget, because the reply action refuses an enabled-AI counterparty before it reaches the provider. |

## Provider routing R1 (14 September 2026)

| Topic | Decision |
| --- | --- |
| Who owns what | **The module orders, the SDK fails over.** `laravel/ai` already walks an ordered provider list, catches `FailoverableException`, emits `ProviderFailedOver` and reports which rung ended up serving. Reimplementing that walk would be the duplication the module rules forbid, so the module contributes the order and nothing else. |
| What a rung is | **A provider, a model, and optionally when that vendor is worth using.** `during` => `peak` or `off_peak` is evaluated against the vendor's own published window, which is how an off-peak discount becomes a routing rule rather than a comment, and how a free or flat-priced vendor can take the expensive half of the day without becoming the only possibility. |
| Windows | **Data, in UTC, and half-open.** DeepSeek's published peak is 01:00-04:00 and 06:00-10:00 UTC Monday to Friday at twice the off-peak rate, and that is what the shipped default encodes. A period that would wrap past midnight throws instead of being interpreted, because which day owns it is exactly the thing a schedule reader gets wrong. |
| A vendor with no key | **Switched off, and dropped quietly.** Availability is read from the SDK's own provider configuration, so an empty credential removes the rung before the call instead of spending a round trip that must fail. An unknown vendor name is the opposite case and throws: a typo is a bug, a missing key is a decision. |
| An empty ladder | **A refusal, not an error.** The reply action answers with the authored text it already holds and touches no reservation, so a missing key cannot spend a player's daily attempt; the conformance run refuses to start and says so. |
| Default | **Off.** While `ai.routing.enabled` is false the single `ai.language.provider`/`model` pair behaves exactly as it did before this existed, so nothing changes which vendor answers until an operator says so. |
| The suite | **Fake the boundary, not the switch.** `Http::preventStrayRequests()` is applied in the shared base test case, so an unmocked request throws and no test can reach a real vendor -- while the module's generative path stays enabled exactly as production runs it. A test that wants a response fakes the agent. The alternative, pinning the switch off suite-wide, would have made every provider-path test a lie. Two authored-reply tests had been passing for the wrong reason: their provider call failed, and the failure delivered the text they asserted; the file that covers the authored cycle now states that configuration itself, because the reference profile ships provider-off. |

## The three gates (14 September 2026)

| Topic | Decision |
| --- | --- |
| Where they live | **`AGENTS.md` states them, `plan/details/specs/cognition-gates.md` defines them.** The contract line is short because it is read on every slice; the spec carries what each gate requires, forbids, allows and how a reviewer checks it. `docs/architecture.md` repeats the short form. |
| Gate 1 — no static, hardcoded AI | **The object universe, its kinds, prices and requirements are read from the host at planning time.** Mods, modules and future extensions add buildings, ships, defence, technologies and premium officers, so an object id, machine name or requirement may not be a source of truth in module code or config, and no capability's reachability may depend on a list the module keeps. Adding an object to the host must make it usable with no module edit. |
| Gate 2 — relatively simple | **The smallest mechanism that closes the gap.** One class, one loop and one sort key beat a framework; an abstraction with one implementation, config for a value that never varies, an unmeasured optimisation or a layer that only forwards is refused, and a slice deletes what it makes dead. |
| Gate 3 — what a good professional OGame player does | **Fifteen years of ordinary play is the reference, not an efficient game of our own.** Every mechanism must be nameable as something an experienced player does, and where the AI chooses, the normal choice beats the optimal-looking one. Machine-shaped play -- rushing an end-game unlock while the opening is unfinished, or an action order no human produces -- fails the gate. |
| Precedence | Gate 3 decides what the account does, gate 1 decides how it is derived, gate 2 decides how much machinery is allowed in between. |
| First consequence, in the same slice | **`AiFacility` is deleted.** The chain that unblocks research and units had named `robot_factory`, `shipyard` and `research_lab` in an enum and mapped them from `AiCapability`, so a mod-added facility would have been invisible to it and the mapping was the source of truth the gate forbids. `FacilityChain` now asks the host for the ambitions (`getResearchObjects()`, `getUnitObjects()`), their requirements (`getRecursiveRequirements()`) and their kinds, and orders the steps by the level the host asks for. That ordering is also the gate 3 play: the easiest unlock first, so a fresh account does not climb towards a level twelve shipyard it cannot use -- which is exactly what ordering by an ambition's own price produced in the first attempt. |
| How the gate is held after this slice | The chain suite computes its expectations from the same host catalogue the planner reads, so a module that went back to naming its own buildings would fail its own tests rather than satisfy them. |

## The review loop — reading what it did and improving (14 September 2026)

Development ends when the module plays, and the goal is judged by what the accounts produce, so reading the
results is a standing activity with its own rules: [the review loop](specs/improvement-loop.md).

| Topic | Decision |
| --- | --- |
| What a review is | **A read of artifacts that already exist** — decision traces, work items and receipts, stop counters, the pilot report, the account's public state, our own points series (AG2) and the human-feedback file. It adds no table, job, dashboard or model call, and a question the artifacts cannot answer is a gap rather than a query improvised against production. |
| What it asks | **The goal, not the mechanics** — did the account reach the next stage of the capability chain, is its growth explicable by visible behaviour, does the cohort diverge, does it react like a player under pressure and does a save ever fail, and is the server more alive for humans. |
| How it is recorded | **One dated record per window** in `plan/details/reviews/`, carrying figures with their evidence class, the read cost, and explicitly what stayed unmeasured. A finding without a figure is an observation to investigate, not a finding. |
| How a finding becomes a change | **Finding → register row → named algorithm → smallest slice → measured before/after**, on a frozen clock and a recorded seed, with a material change recorded in this file. A finding that says the plan was wrong is fixed in the plan, because that is what the register's root causes were. |
| What may not happen | **No symptom fixes.** The acceptance wording is not widened, the trace that showed the problem is not deleted, and a shortcoming is not closed with a per-account constant (gate 1), a new layer (gate 2) or behaviour a player cannot be named doing (gate 3). |
| Tuning | **Placeholders are replaced by measured values, and the replacement is logged** in the spec's tuning log. A constant that never varies is still not a setting. |
| Never measured against live traffic | **A window a pilot is still writing into is not evidence about the code** — the rule the register already holds for its own gates. |
| Reading it cheaply | **One bounded pass per window, structured before prose.** The reporting commands answer the same figures in a stable machine-readable shape as well as the human rendering, the read is an indexed range over the traces' short retention, counters are aggregated where the row is written (`ai_stop_counters` is the shipped pattern), and the path is read-only with no generative call in it. A figure that needs a full-table aggregation is a missing counter, not a slow query to accept. |
| Effect on play | **None, and that is a measurement rather than a promise.** Nothing in the loop runs inside a session, a job or a request: the read is an explicit command, and the one write it adds — AG2's hourly points sample — is a scheduled batch pass outside the request path that takes no lock, calls no service which would advance resources or stamp activity, and is best-effort, so a lost sample costs one data point and never a session. Its query count and duration are measured before it ships and recorded with the window. |
| The switch | **One switch, `ai.review.enabled`, default on.** Reading results is the point and the read is free, so the review-only collection runs unless an operator turns it off, and the switch is read once per pass rather than per account or per session. With it off the module plays exactly as it does with it on, and a window reports which figures were not collected. |
| What the switch does not cover | **The records operability owns.** Decision traces, work items, receipts and stop counters stay written, because the operator page and the pilot report are built on them and a staff member diagnosing a quiet population must not be able to switch off the evidence. The switch covers what the review *adds*, not what the host already relies on. |
| What stays machine-learned | **Only the outcome-based CBR already specified.** Changing a policy, a constant or a gate is a reviewed, tested change, never automatic self-modification. |

## Research and first cargo units (14 September 2026)

| Topic | Decision |
| --- | --- |
| Research executor | **Shipped.** When the next economy/chain step is a technology, `QueueableBuildingPlanner` returns `QueueableResearch`; observation publishes only `research`; `ScheduleAiIntentAction` / `ProcessAiWork` queue through `QueueAiResearchAction` into `ResearchQueueService::add`. |
| First units | **Cargo only (U1/U2).** `QueueableUnitPlanner` picks the host ship with the best cargo capacity per metal-equivalent cost that the planet can queue today, so a deeper freighter cannot hide a hull this account can already build. `QueueAiUnitsAction` writes through `UnitQueueService::add`, and observation publishes `queue_units` only when that plan is non-null. |
| Defence | **Still unpublished.** Buying defence without an inbound attack is not namable as play (U3), and nothing yet observes incoming fleets (G8), so defence waits on that observation. |
| Score series | **AG2 shipped with the review loop.** Hourly `ai:record-score-samples` copies the host highscore into `ai_score_samples`; the pilot report reads the series; retention is 400 days via `ai:prune`; `ai.review.enabled` switches only that collection. |

## Inbound fleet observation (14 September 2026)

| Topic | Decision |
| --- | --- |
| Source | **Assembled from active fleet missions, not from IncomingFleetIntelService.** That service only redacts a row that already exists; the movement page builds the inbound picture from `FleetMissionService::getActiveFleetMissionsForCurrentPlayer()`. |
| What is published | **Foreign fleets headed at this account's planets**, carrying mission id, type, arrival time and destination — the fields every account can see without espionage. Composition stays with the host redactor. |
| Fleetsave eligibility | **`currentPlayerUnderAttack()`.** Which mission types count as hostile is the host's answer; the module keeps no mission-type list (gate 1). |
| What this does not do | **It does not move ships.** Noticing an inbound fleet makes `fleetsave_eligible` true so a FleetSave candidate can appear; the fleetsave executor (V1) and reaction wake (V2) remain open. |

## Provisioning identity (14 September 2026)

| Topic | Decision |
| --- | --- |
| Email | **Plausible domains, hashed local-parts.** `.invalid` announced the account; rotating ordinary-looking domains with a deterministic hash keep idempotency without the reserved-TLD tell. |
| Seeds | **Uncorrelated.** `crc32('ai-persona:' . index)` replaces `SEED_BASE + index`. |
| Join dates | **Staggered across up to 21 days.** A cohort that arrives in one minute is the aggregate tell. |
| Dark matter | **±1200 around the host default.** Enough to break the single-value spike without inventing a second economy. |
| Names | **Homeworld renamed from a small pool; username rename stamped.** Occasional later renaming remains behavioural work, not provisioning. |
| Dead prefix | **Deleted.** `NAME_PREFIX` was never referenced and was a latent marker. |
| Still open | **I7 addresses and I8 social paperwork.** `last_ip` stays with AG3; alliances/buddies need social policy before provisioning. |

## Colonies and the fleet it unlocks (14 September 2026)

| Topic | Decision |
| --- | --- |
| Colony executor | **Shipped.** `QueueableColonyPlanner` walks a seeded coordinate order and takes the first empty slot `canColonizePosition` allows; `QueueAiColonyAction` launches it through `ColonisationMission::getTypeId()`; observation publishes `colonize` only when a colony ship exists and a slot is free. |
| Colony-ship role | **Cargo first, then expansion.** `QueueableUnitPlanner` queues the best cargo-per-cost ship while the account owns none, then a colony ship once a fleet exists and `planetCount < getMaxPlanetAmount()`. |
| Slot choice | **First empty, seeded order.** Position bonuses (CL1's refined taste) are a later slice; the first legal empty slot is the smallest mechanism that makes colonies exist. |
| Required-ship naming | **One host-contract key per role, documented as host-ask R9.** The object always comes from `ObjectService`; only the role key (`colony_ship`) lives in module code until the host exposes a mission-required-ship query. |

## Fleetsave executor (14 September 2026)

| Topic | Decision |
| --- | --- |
| What a save is | **A deployment between the account's own planets at the slowest speed.** The fleet leaves the threatened body and parks on another it owns; it can be recalled when safe. |
| Trigger | **A hostile inbound, gated on saveability.** `fleetsave_eligible` is true only when `currentPlayerUnderAttack()` *and* the account has a fleet and a second planet, so a trace never claims a save the account cannot make. |
| Fleet composition | **Whatever ships the planet owns.** The account currently owns only cargo and, briefly, a colony ship; excluding static satellites is a later refinement when the economy builds them. |
| Still open | **V2 reaction timing and V3 deliberate failure.** The save dispatches at the moment the session notices the inbound; the 120–180 s reaction wake and the occasional failed save stay unimplemented. |

## Espionage executor (14 September 2026)

| Topic | Decision |
| --- | --- |
| Probe role | **Cargo → colony ship → probe.** Once a fleet exists and expansion is underway, the account queues the ship the host's espionage mission consumes. |
| Target | **A legal foreign planet.** Own, destroyed, vacationing and administrator-protected planets are skipped; the host's own mission stays the final authority. |
| Dispatch | **One probe, full speed.** `QueueAiSpyAction` sends it through `EspionageMission::getTypeId()`; counter-espionage is the host's. |
| Still open | **Report publishing (N2).** The probe lands and the host writes an espionage report, but nothing yet turns that report into a raid candidate's target intel. |

## Host battle question R1 (14 September 2026)

| Topic | Decision |
| --- | --- |
| Shape | **`BattleEngine::simulateBattle(?int $seed = null, bool $pure = false)`** on the abstract engine, so both PHP and Rust inherit the contract. |
| Pure | **Gates the only two side effects** — `applyTacticalRetreat()`'s deuterium write and the `BattleResolved` event. A pure run leaves the world unchanged. |
| Seed | **`mt_srand` once, then every PHP-engine draw is reproducible** — `rollMoonCreation`, both `checkHamillManoeuvre`, `didSuccessfulRapidfire` (now `mt_rand`), `damagedHullExplosion` (`rand` alias), `array_rand`; `DefenseRepairService` gets a distinct sub-seed. Unseeded falls back to `random_int`, so the live path is unchanged. |
| Rust | **FFI round RNG is still its own.** A seeded, replayable estimator must use `PhpBattleEngine` until the Rust binary exposes a seed. |
| Unblocks | **G6 raids.** `T2` can now sample the engine with one shared seed stream, screen n = 50, confirm n = 200, report P20. |

## Raids and the estimator (14 September 2026)

| Topic | Decision |
| --- | --- |
| Intel | **Reports come back through the account's own messages.** Observation reads `messages.espionage_report_id` rows addressed to the account and publishes them as `target_reports` with a 24 h staleness window; no target model reaches a policy. |
| Estimator | **`NativeRaidEstimator` asks the host, never computes combat.** It samples `PhpBattleEngine::simulateBattle(seed, true)` (R1) at n = 50 and reports losing-run count plus P20 net profit. The PHP engine is used because the Rust FFI RNG is unseeded. |
| Profit test | **Positive P20 only.** A raid is scheduled only when the sampled lower-tail profit is positive — "raid only when it pays even on a bad day". |
| Bashing | **The host's six-per-day limit**, read from the account's own attack missions against the target. |
| Dispatch | **`QueueAiRaidAction` sends the origin's ships through `AttackMission::getTypeId()`.** |
| Open | **A cargo-only account never passes the profit test.** Combat ships (U1 escort role) are the remaining piece that makes raids actually fire; the machinery is complete and correct. |

## Package 4 sign-off decisions (15 September 2026)

The owner's standing rule makes Packages 1–4 finished only when the [gap register](GAP-REGISTER.md) is
empty and every gate has a measured verdict. These decisions close the remaining rows. They are made
under the three [cognition gates](specs/cognition-gates.md), not as preferences: each names the
ordinary play it imitates (gate 3), derives every number from the host (gate 1) and takes the
smallest mechanism (gate 2). Where a decision defers work past Package 4, it is recorded as deferred
with the owner's acceptance rather than left open, so the register can be re-run and come back empty.

### U1 escort and U3 defence — implemented

| Topic | Decision |
| --- | --- |
| U3 defence | **Queued when the host says a hostile is inbound.** `QueueableUnitPlanner` asks `FleetMissionService::currentPlayerUnderAttack()` — the host's own answer, so the module keeps no mission-type list — and queues the defence piece with the best attack per metal-equivalent cost among `ObjectService::getDefenseObjects()`. This is the doctrine verbatim: defence exists to make an attack unprofitable by inflicting maximum damage. |
| U1 escort | **Queued when a fresh report shows a defended target and the account owns no warship at least as fighty.** The account's own espionage-report messages (24 h staleness, the same window the observation publishes) name the target; a non-empty `defense` field is the trigger; the ship is the best attack per metal-equivalent cost the planet can queue. "Already has a warship" is measured by attack-per-cost rather than by "owns a ship with attack", because cargo hulls carry a token attack and a cargo-only account must still reach for combat. |
| Gate | All three roles rank the same host-quoted ratio (`capacity` or `attack` ÷ weighted price), so a mod-added hull with a better ratio becomes the role's unit with no edit. The two intel roles are ordinary play — "I'm being hit, I build defence" and "I scouted a defended target, I need warships" — and they were the register's G3 tail, now closed. |

### G9 — the save that fails — placeholder blessed, mechanism shipped

| Topic | Decision |
| --- | --- |
| The rate | **Blessed as a placeholder, not telemetry.** No source quantifies how often real players fail to save — the plan's survey confirmed this, and the widely repeated "80% of fleets lost offline" has no source and stays deleted. The plan's band is **1 per 20–50 save opportunities**, realised as one named ordinary mistake; the midpoint (1 in 30) is the shipped denominator. |
| The mechanism | **A deterministic skip, not a coin flip on the whole path.** `SaveFailurePolicy::shouldSkip(seed, inboundMissionId)` draws `crc32(seed, key) % 30 === 0`, so the same threat always gets the same judgement however many times a session re-reads it. On a skip the observation sets `fleetsave_eligible = false` and publishes `fleetsave_skip_reason: overnight_gamble`, so the account takes its next legal action (build, mine, research) instead of saving — the loss, when it comes, is recoverable through the ordinary unit path. |
| Replacement | **Telemetry from the 2/5/10 runs replaces the constant**, and the replacement is logged in the algorithms spec's tuning log — the register's own rule for placeholders. Until then the band stands, labelled as ours. |
| Gate | A 100% save rate over months is itself the observable that would give the cohort away (signal 1), so a save that can fail is the gate-3 reference behaviour named in the plan. |

### G8 V2 — the reaction wake — decided and deferred

| Topic | Decision |
| --- | --- |
| Status | **The reaction wake stays deferred.** V2 schedules a wake 120–180 s before impact; it depends on the next-material-event wake mechanism (SP3), which lands with the capacity-run slice. For Package 4 the save dispatches on the session that notices the inbound, and reaction latency is measured in the 2/5/10 runs rather than claimed. The observation half of G8 and the V1 executor are already shipped; V2 is a named follow-up, not an open gap. |

### G12, G18 and S1–S4 — social scope — deferred to Package 6

| Topic | Decision |
| --- | --- |
| The scope answer | **An AI account does not initiate contact, join or leave alliances, or answer alliance surfaces in Package 4.** Social *reaction* (answering inbound direct messages) is shipped; social *initiation* and *alliance life* are Package 6 scope, where cooperative PvE puts a faction of accounts in front of humans and social breadth becomes the measured signal. |
| Why not now | Every initiation the plan names (SOC1) and every alliance behaviour (SOC2) needs a trigger and a recipient — a probe observed, a transport received, a raid won, an ally to answer. Those triggers and recipients are provisioned by behaviours (G18 alliance, I8 buddies) that are themselves deferred. Introducing initiation without a recipient would be unnameable (gate 3), and an alliance member who answers nobody (S2) is worse than never joining. |
| Per-surface (S3) | **Decided, not instrumented.** An alliance application is deferred with G18; a buddy request is accepted from an existing contact when buddy contacts exist (I8); a player note is private and needs no answer; the merchant has no offers to answer. Silence on these is a tell only if the account is otherwise social — which Package 4 accounts are not by this decision. |
| Report sharing (S4) | **Deferred with G12.** It is the same authored, permission-checked path as any initiation, and it has no recipient until buddies or allies exist. |
| Register effect | G12, G18, S1, S2, S3 and S4 move from *open gap* to *deferred by scope decision*, which the register's own rule allows: it asked to **confirm** whether alliance life is in scope, and the answer is no for Package 4. |

### G17 — transfers and trade

| Topic | Decision |
| --- | --- |
| Trade (X2) | **Closed by evidence, not code.** OGameX has no marketplace, trade request or resource exchange — verified in the [capability map](research/host-capability-map.md). "Trade" is a transport at an agreed ratio, so the trader persona expresses itself through transport volume and timing; there is nothing to execute. The 72-hour completion rule and the self-imposed ratio band are documented and unchanged. |
| Transfers (X1) | **Trigger decided; executor is the next slice.** The ferry is driven by the economy plan's next step: when the planet that owns the next target cannot afford it but the empire total (net of the sender's reserve and in-flight shipments) can, the account ferries the shortfall. The algorithm is [E4](specs/gameplay-algorithms.md#e4-ferrying-resources-between-own-planets), and the executor is not required by completion-gate item 7, which covers the decision engine's selectable set — `save_resources` is the only unexecuted intent there. |
| Why the trigger is the decision | The trigger was the blocker the register named. It is now fixed: affordability of the next economy step, empire total, sender reserve, in-flight netting. The executor that carries it is a mechanical slice over the already-shipped transport mission (`TransportMission::getTypeId()`), and it lands with the capacity runs. |

### I7 — address diversity — accepted as the truthful queue-context address

| Topic | Decision |
| --- | --- |
| The answer | **Synthetic accounts present the address the host stamps when they act, and nothing else.** The module makes no HTTP requests (AG4), so the account's only real address is the queue-context one `PlayerGameStateService::advance()` writes — loopback or empty. Fabricating a per-account IP would be a second, worse tell (gate 3: a player's address comes from a real browser, never a seeded constant) and would add risk without addressing a measured signal. `register_ip` stays null for the same reason. |
| Observable effect | None to a player; to an operator the address is *correct* for a server-side scheduled account, which is the truth an abuse tool should see. |

### I8 — social paperwork — provisioned at the minimum a real account has

| Topic | Decision |
| --- | --- |
| Character class | **Provisioned per persona.** The seeder now assigns the host class a persona would pick (miner/casual → Collector, fleeter/turtle → General, trader → Discoverer) and marks the free selection used, so no AI account sits classless forever — the tell the register found. The class is the host's `CharacterClass`; only the persona-to-class taste is module policy. |
| Alliance, notes, buddies | **None at provisioning.** Notes are private and need no answer (S3); buddies and alliances form from behaviour that is deferred with G18/S3. An account with no buddies yet is indistinguishable from a new human account. |

### Aggregate shape — A1, A3, A4 and the inferred rows

| Topic | Decision |
| --- | --- |
| A1, G13, G14 (divergence) | **Closed by construction, measured by the runs.** With the full executor set shipped, two accounts no longer converge: the skill band, risk band, cadence and seeded opening taste (AG1) diverge the same host data. The *measured* divergence is a 2/5/10-run question, not a code gap. |
| G15 (zero military) | **Closed.** The account now builds ships and, when attacked, defence — both feed `military_built`, so military points stop being pinned at zero. |
| G16 (flatline) | **Closed.** The capability chain from mines to facilities to fleets to colonies keeps the account spending, so the public curve does not flatline at the opening economy. |
| A3 (rank trajectory) | **Closed by AG2.** The hourly `ai_score_samples` series is what makes entry rank, slope and spread readable at all — the host keeps no history. The figures themselves come from the runs. |
| A4 (request footprint) | **Decided: correct the requirement.** Signal 8 is amended to "no fabricated page cadence; the observable footprint is the activity marker and the schedule". The host's own detector reads departures and hours, not page loads, so a page-like cadence would add risk without addressing a measured signal (AG4). |

### The completion gate — what code cannot close

| Item | Disposition |
| --- | --- |
| Driver Gate 2 verdicts (item 2) | **Recorded as disabled on evidence.** AgentOS fails Gate 2 on the reference profile: its memory subset pulls ~920 MB of `node_modules` (onnxruntime) and needs a local embedder for zero-generative use — against a 2 vCPU / 2 GB profile with ~500–700 MB headroom. FAtiMA (108.9 MiB) and CBRKit (142.1 MiB) fit but stay disabled pending a measured gain, which is what the gate asks for rather than adoption. All three are opt-in; the reference profile runs native. |
| Narrower acceptance wordings (item 5) | **Accepted as permanently narrower.** Replay is read-only over a saved scenario rather than live state, and lateness is the module's own scheduling lateness because this host has no server tick. Both are honest statements of what the host offers; neither is a defect to close. |
| 2/5/10 runs (item 1), human pilot (item 3), feedback file (item 4) | **Operational, not code.** They are run after sign-off: the 2/5/10 runs at the very end by owner decision, the disclosed pilot with real humans once the cohort acts, and the feedback file read from the operator-supplied source. Sign-off unblocks them; they do not block sign-off, because Package 4 is operability and it now holds the evidence it was built to collect. |

## Package 4 — signed off (15 September 2026)

The owner's acceptance for Package 4 is recorded here. It closes completion-gate item 6; the
remaining items (1, 3 and 4) are operational and run after sign-off, not before it.

| Topic | Verdict |
| --- | --- |
| Gates | **All green, measured.** Rector dry-run clean (0 changes, 0 errors); Pint clean; module PHPStan level 8 with 0 errors; **598 Pest tests, 598 passed, 1,985 assertions**; PCOV coverage **100.00%** (4,887/4,887 statements over `Modules/AI/app`, excluding `app/Rules`). |
| Gap register | **Empty.** Re-run against the goal on 15 September 2026: every row is closed with shipped code, closed by decision, or deferred by a recorded scope decision. The U1 escort, U3 defence and V3 save-that-fails mechanisms are shipped and tested; the social, transfer-executor and V2 reaction-wake deferrals are named follow-ups with fixed algorithms, not open questions. |
| Pilot report | **Reviewed.** The 10-account pilot of 14 September 2026 (recorded above) is the sign-off evidence: 10 sessions completed, 10 successors scheduled, 0 provider requests, 0 worker failures, lateness p50 0.8 / p95 0.9 minutes, and — after slice 3M — the cohort acts as well as decides, with real buildings queued through the host path. |
| Acceptance wordings | **The two narrower wordings are accepted as permanently narrower** (replay over a saved scenario; module-own scheduling lateness). Both are honest statements of what this host offers. |
| Driver Gate 2 | **All three drivers recorded as disabled on evidence** — AgentOS fails on the reference profile, FAtiMA and CBRKit stay opt-in pending a measured gain. No driver is enabled on the reference profile. |
| What sign-off unblocks | The 2/5/10 capacity runs (completion-gate item 1), the disclosed human pilot (item 3) and the feedback-file read (item 4) now run; Package 6 remains blocked by those three items only. |
| What was not measured | Capacity at reference-profile scale, human feedback, and the post-sign-off runs. None is claimed. |

## Package 5 rescoped — external drivers and native↔external collaboration (15 September 2026)

The owner's decision of 15 September 2026 makes the external drivers a delivery package of their own
and moves cooperative PvE to Package 6. The decision is recorded here so the package plan and the
history agree.

| Topic | Decision |
| --- | --- |
| The owner's directive | **Use every available external driver to its full extent, and let the native and external engines work together.** On the owner's own host every available driver runs; the reference profile is unchanged and still defaults to native. The "reuse, don't reinvent" rule stays: a driver's judgement is consumed, never reimplemented in PHP. |
| Why a package of its own | **3I wired the drivers and proved fallback; it never let them earn their keep.** FAtiMA's appraisal/decision/social-importance depth and CiF's per-mode volition are computed and discarded; CBRKit executes the module's own formula; AgentOS's diagnostics are thrown away and its contract has no caller. Package 5 closes "wired" → "used". |
| The collaboration mode | **`ai.cognition.mode = native | external | hybrid`, default `native`.** `native` is today. `external` is today's swap with native fallback, kept for the ablation's swap comparison. `hybrid` runs native always and the selected driver alongside it when healthy, then a per-contract combiner merges them. One mode knob applied to all three driver settings, instead of a per-contract mode matrix (gate 2). |
| What "full extent" means per driver | **FAtiMA affect** — the adapter sends the full signed stimulus dimensions and reads back mood, social importance and a coping/decision intention, mapped to episode state and `ResolveCognitiveIntentAction`. **FAtiMA/CiF social** — respect/socialImportance/anger/threat reach the driver; per-mode volition magnitude and step become evidence that can withhold or demote, not a binary veto. **CBRKit** — the ported `retriever.py` is deleted and the driver uses its own retrieval measure; `cbrkit.eval` on held-out outcomes is the Gate 2 verdict. **AgentOS** — recall gains a real caller and surfaces its decay/relevance diagnostics instead of id order alone. |
| Package renumbering | **Cooperative PvE moves from Package 5 to Package 6.** It remains Phase 5 and still waits for Packages 1–5 to be finished, the completion gate and sign-off. The social-initiation/alliance scope (G12/G18/S1–S4) stays with PvE — Package 6 — because it needs the recipients PvE provisions. |
| Reference profile | **Unchanged.** `mode = native`, zero external calls. Hybrid is opt-in for hosts with measured headroom; no driver is enabled without a measured resident footprint and a measured gain. |
| Gate 2 | **Not waived by this directive.** A driver runs in hybrid on a host that chooses it, and the measured comparison (`ai:cognition-conformance`) names the gain it adds. The reference-profile default is still the evidence that absence stays free. |
| No duplication | **Reaffirmed.** The combiners are module policy over driver outputs — scope, attribution, permission, validity, budgets, persistence, failure mapping, translation and weighting — never a PHP port of a driver's algorithm. The full milestones and acceptance are in the [external-drivers spec](specs/external-drivers.md). |

## Package 5 — implemented (15 September 2026)

The external-driver package is implemented, tested and measured against the real sidecars. It is not
signed off yet; the owner reviews the report before acceptance.

| Topic | Result |
| --- | --- |
| The mode | **`ai.cognition.mode = native | external | hybrid`, default `external`** (preserves the historical driver-swap behaviour). Selectors dispatch per contract; `native` forces native, `external` swaps with native fallback, `hybrid` runs native always and the driver alongside it. |
| Widened contracts | `AffectAppraisal` carries `mood`, `driverEmotion`, `driverIntensity`; `SocialExchangeEvaluation` carries `volition`, `step`; `RankedExperience` carries `driverSimilarity`. All nullable and native-safe, so the native path is unchanged. |
| Hybrid affect | Native emotion/intensity stay canonical; FAtiMA contributes mood and its own mapped emotion and intensity as evidence. |
| Hybrid social | Native stance stays authoritative; CiF volition magnitude and step become evidence that can withhold (empty volitions) or demote (volition below 5.0) an acceptance. A refusal is never overridden. |
| Hybrid experience | Native supplies the authoritative candidate set; CBRKit's own score reorders within it and is recorded as `driverSimilarity`. |
| Memory | The `LongTermMemory` contract gained a real caller: a help request recalls the counterparty's facts and an outstanding `ResourceDebt` cools cooperation (gate 3: not lending more to someone who already owes me). The AgentOS adapter no longer drops memories the driver did not rank — native recency keeps them. |
| Gates | Rector 0 changes, Pint clean, PHPStan level 8 0 errors, **614 Pest tests / 2,051 assertions**, PCOV **100.00%** (5,018/5,018). |
| Real measurement | `ai:cognition-conformance` extended with `--mode`. External: CBRKit p50 179 ms, FAtiMA p50 408 ms (50 HTTP calls per 10 appraisals), both `correct`. Hybrid: CBRKit p50 224 ms, FAtiMA p50 403 ms, both `correct`; the artifact shows `driver_emotion: Anger` with the native `Anger 0.08` kept canonical. |
| Deferred, named | FAtiMA's mood is read but flat (0.0) for the battle-loss fixture — the authored harm rule carries no mood change, so social-importance/decision-intention depth waits on scenario authoring. CBRKit still executes the module's formula (its own retrieval measure is the remaining 5D step). The per-fact relevance surface waits for a consumer. Reference profile unchanged. |

## Host obligations — extension points landed, the rest decided (15 September 2026)

The audit of Packages 1–5 asked which of the [host obligations](specs/host-change-request.md) are still
open. Two are pure extension points and are now implemented in the host and consumed by the module; the
rest are decided and recorded, not left open. The dispositions are on the
[host change request](specs/host-change-request.md) table.

| Ask | Decision and evidence |
| --- | --- |
| R2 — queue-upgrade predicate | **Implemented.** `PlayerService::isObjectUpgradeBlocked(int $object_id)` publishes the controller's own rule; the controller reuses it and the module's `AiBuildingMachineName` enum (the last machine-name list in module code) is deleted. Gate 1. |
| R9 — mission-required-ship query | **Implemented.** `GameMission::getRequiredShipMachineNames()` answers which ship a mission refuses to run without; the module's `colony_ship` / `espionage_probe` role keys are deleted. Gate 1. |
| R3 — vacation/ban refusal inside the queue services | **Keep the module-side re-check.** Every module queue action already refuses banned/vacationing players under its own lock — the documented "if it never lands" fallback, already shipped. Moving the refusal into the host services changes host behaviour for every caller and is not forced now; it stays a host ask. |
| R4 — storage enumeration including stations | **Closed by evidence.** `StationObject` has no `storage` field, so no station can carry storage in this host and the building-only enumeration is already complete. Adding a method whose output cannot differ is the speculative machinery gate 2 forbids. |
| R5 — recall ownership in the service | **Deferred.** The module has no recall executor (fleetsave is a deployment), so there is no caller; the ownership check lands with the first recall path. |
| R6 — the five controller-only rules | **Not now.** The module's copies are currently correct and four of the five have no active drift; publishing five predicates with no consuming need is over-engineering. Reopen on a demonstrated drift. |
| R7 — hourly host highscore snapshot | **Not needed.** The module already records its own `ai_score_samples` series (AG2); a host snapshot would add a second series with no consumer. |
| R8 — suppress `last_ip` on scheduled `advance()` | **No host change.** The queue-context address is the truthful stamp for a scheduled account (I7/AG3), already decided 14 September 2026. |

Nothing in this audit changes the reference profile, the completion gate, or Package 6's blockers.

## Strategy knowledge mining — started (15 September 2026)

After the GoRules audit (DO NOT USE) and the seam-vs-consumer review, the next major work is **strategy
knowledge mining**: turn sourced OGame strategy into an atomic, mapped knowledge base. Decisions recorded
here so the split is explicit and not rediscovered:

- **Formalize, don't rediscover.** Economy, research, basic raiding, basic fleetsave, colonisation and
  routine are already deeply sourced in `veteran-play.md` + `gameplay-algorithms.md` + the 16-bot survey.
  The new catalog extracts them into atomic principles; no new web research for those domains.
- **New research is scoped to the thin domains**, in priority order: raid/target richness, espionage
  prioritization, proactive fleetsave, fleet composition, then fleetcrash/phalanx (Pass-4 niche).
  Alliance/diplomacy stays deferred to Package 6.
- **Confidence letters derive from existing provenance markers** (MEASURED/DOCUMENTED/CONTESTED/…), never
  a parallel taxonomy.
- **No code changes in this phase.** No new scorers until a validated principle cluster requires one, and
  then they slot into the existing `DecisionTrace` component mechanism. No GoRules, no learning, no new
  infrastructure.
- **Host surface scan result:** phalanx (`PhalanxService`), moon + jump gate, ACS attack/defend,
  debris/recyclers (`DebrisFieldService` + `RecycleMission`), planet activity (`time_last_update`) and
  recall (`cancelMission`→`startReturn`) are all **supported by the host and unwired by the module**. The
  fleetcrash/phalanx gap is therefore a module-wiring gap, not a host-capability gap — recorded as wave 6
  in the register.

Artifacts created: [`specs/strategy-mining.md`](specs/strategy-mining.md),
[`research/source-registry.md`](research/source-registry.md),
[`research/strategy-principles.md`](research/strategy-principles.md) (42 principles: 22 shipped, 6 partial,
9 researched, 3 deferred, 2 gap), and the wave-6 register rows.

### Strategy research pass 2 — multi-agent (15 September 2026)

Three parallel research agents mined the gap domains against fetched sources and returned 25 new
principles (FLE-005..011, FS-006..010, INT-005..010, CRASH-005..008, RAID-008..014). Sources that loaded
and were deep-read: Gameforge fleetsave + raider guides, Sidian fleet-saving + farming, ogames.net
activity-tracking + raiding, the `fleetsaving v2` board thread, Fandom `Ships` and `Rapid_Fire`
(via `?action=raw`), and the Wayback recoveries of `Tactic 05a — Fleet composition` (2024-04-20) and
`Tutorial 15 — Moon` (2024-05-19). The catalog now holds **67 principles** (22 shipped, 6 partial,
36 researched, 3 deferred, 0 gap) — the gap domains are now *sourced*, which is what unblocks later
architecture mapping and implementation.

Corrections and contradictions recorded this pass, not silently resolved:
- **Recall seam:** a same-planet relocation is **not** recallable — `cancelMission` returns early for a
  deployment with `planet_id_from === planet_id_to`; deploy between two own planets **is** recallable.
  `CRASH-003`/`FS-010` corrected accordingly.
- **Debris in profit (RAID-014):** ogames.net publishes `Loot + Debris − Fuel − Losses`; the module
  deliberately keeps debris out of the single-raid gate (two missions, two capacities). Recorded as
  contested, module doctrine unchanged.
- **Landing buffer (FS-007):** 10–20 min vs +30–60 min — stays a persona band.
- **ACS still unsourced:** the ACS tutorial/guide anchors (ORG-009/010) still redirect; re-sourcing them
  is the remaining Stage-1 discovery item.

### Strategy research pass 3 — architecture mapping + ACS (15 September 2026)

Three more parallel agents completed the two remaining review passes:
- **ACS re-sourcing:** Wayback captures of ORG-009/010 failed; the Gameforge alliance guide (GF-003) is
  the verified anchor and sourced **12 ACS principles** (ACS-001..012). ACS is alliance-gated
  (`allianceCombatSystemOn`), host-supported (types 2/5) and module-unwired — execution stays deferred
  to Package 6, knowledge recorded now.
- **Architecture mapper:** every researched principle mapped to current code in
  [`research/architecture-mapping.md`](research/architecture-mapping.md). The conclusion is that the
  host supports phalanx/moon/jump-gate/debris/recycle/ACS/recall but **zero module callers** exist — the
  gap is wiring, not capability. Highest-leverage P1 cluster: `PlayerObservationService::targetReports()`
  (one change unblocks RAID-004/005/006 + INT-004).

### Strategy research pass 4 — classical AI patterns + algorithm blocks (15 September 2026)

The final research pass closed the brief's third workstream and wrote the algorithm blocks the
earlier passes only mapped:

- **Classical game AI catalogue**
  [`research/classical-ai-patterns.md`](research/classical-ai-patterns.md): 23 patterns across Zero
  Hour (ZH-1..10), Freelancer (FL-1..6), OpenRA (OA-1/2), Cobra (CB-1) and Wesnoth (WE-1..4),
  formalized from the brief's confirmed findings, not rediscovered. Three takeaways:
  state→parameter→behaviour is the one reusable shape and needs no class hierarchy;
  difficulty-as-quality and variety-as-seeded-choice are already in the design; the corpus adds
  *confidence* to mechanisms the strategy catalog already named, not new ones.
- **Algorithm blocks** in [`specs/gameplay-algorithms.md`](specs/gameplay-algorithms.md): SP7 (the
  activity/intel reader), T6–T8 (raid target score, tiered gate + cargo sizing, launch re-check),
  N4/N5 (spy target score, own signature control), V6–V8 (proactive save, variation/masking, shadow
  waves), F1–F6 (phalanx, deploy-recall, moon, recycle, blind lanx, moon destruction). All
  **planned**, blocked on catalog review; the executors do not exist yet.
- **Hypotheses H1–H10** recorded with evidence status in
  [`specs/strategy-mining.md`](specs/strategy-mining.md): H1/H2/H3/H5/H6/H7/H9/H10 hold, H8
  plausible, H4 open (no third axis over `ArchetypePolicy` + `AiSkillBand` justified yet).

### Strategy research pass 5 — claims layer + canonical fold-back (15 September 2026)

The final closes:
- **Claims layer** [`research/strategy-claims.md`](research/strategy-claims.md): the eight claim types
  (`DOMAIN_FACT`, `HARD_SAFETY_POLICY`, `SCORING_FACTOR`, `STRATEGIC_HEURISTIC`,
  `BEHAVIOR_PROFILE_PARAMETER`, `POSSIBLE_STRATEGIC_POSTURE_TRIGGER`, `ADVANCED_TACTIC`,
  `REJECTED/UNSUPPORTED`) with the disposition of each, the classification of all **83** principles,
  and seven contested/rejected atomic claims preserved verbatim.
- **Count correction:** the catalog holds **83 principles** (21 shipped, 7 partial, 52 researched,
  3 deferred, 0 gap) — the earlier 79 (67 + 12 ACS) undercounted.
- **Canonical fold-back:** `decision-policies.md` and `WORK-PACKAGES.md` now reference the mining
  workstream and the wave-6 blocks as post-Package-4 depth, not a new package.
- **Integration gates:** the ten-item per-cluster review checklist is written into
  `strategy-mining.md`.
- **M6/M7 scope:** the Zero Hour and Freelancer case studies are pattern-level; the actual
  vanilla-vs-mod file diffs are recorded as a deferred follow-up, not a blocker.

### Strategy research pass 6 — gap domains closed (15 September 2026)

Three parallel gap-research agents closed the domains the prior passes left open. 26 new sources
(`GF-006`, `TP-011..020`, `WIK-005..012`, `PLW-001..005`, `DEW-001`, `DEF-001`) and 24 new
principles, including two new domains:
- **Ninja / baiting** (NIN-001..005): the defender's counter-crash — hidden trap fleet landing on
  the combat second, soft-farm bait, moon staging, and anti-ninja checks.
- **Expeditions** (EXP-001/002): slot-16 outcomes and the never-fleetsave-via-expedition rule.
- **Fleetcrash depth** (CRASH-009..014): phalanx return-second, speed-slider timing, precision
  tiers, blind phalanx, recall-disappearance tell, and the crash EV formula with host-read debris %.
- **Fleet composition** (FLE-012..015): the production-vs-launch split, counter-selected launch
  subsets, cruiser workhorse, simulate-before-dispatch.
- **Non-English confirmations** (PLW): FS-010 and CRASH-006 upgraded to multi-source (A); ECO-006
  fusion timing contested; ACS 30%-join-cap and debris-split conventions (ACS-013/014); opsec
  INT-011/012; colonization COL-003/004 and the slot-bonus correction to COL-001 (now A).

Catalog now **107 principles** (21 shipped, 7 partial, 76 researched, 3 deferred). Remaining open
(recorded, non-blocking): ACS tutorials ORG-009/010, the French board guide library URLs, and
`ogamewiki.de`.

### Task index — SQLite task DB (15 September 2026)

The research phase is complete; the remaining work is the implementation slices and their
pre-requisites. To give multiple agents a single claim-safe index, a small SQLite DB
(`plan/tasks/tasks.db`, seeded from `plan/tasks/seed.sql`, usage in `plan/tasks/USAGE.md`) now holds:
- **16 tasks** — `REV-001` (catalog review, the gate), `IMPL-013..020` (SP7 reader, raid/intel/save
  depth, fleetcrash, fleet composition, ninja, expedition), `DEF-001..003` (deferred), `DISC-001..004`
  (open discovery).
- **Dependency graph** — `REV-001` gates all `impl` tasks; `IMPL-013` unblocks the three consumers
  (raid/intel/save), which unblock fleetcrash, which unblocks ninja; `IMPL-018` is review-gated but
  otherwise parallel-safe; `IMPL-020` is blocked on the unverified expedition host surface (`DISC-004`).
- **`ready_tasks` view** — the set an agent may claim right now (empty until `REV-001` is done).

The plan docs remain the source of truth; the DB is a derived index, rebuilt from `seed.sql` when the
docs change. No gameplay code is touched by this.

### Plan Executor agent + task CLI (15 September 2026)

To run the task index natively from the editor:
- **Custom agent** `.github/agents/plan-executor.agent.md` — the executor/project-manager role: it
  reads `ready`, claims one task, follows its `doc_refs`, verifies, and marks `done`, adding tasks
  and dependencies as work expands. Tools: read, edit, search, execute, todo, agent.
- **CLI** `plan/tasks/task.py` — wraps the DB with `list/ready/blocked/graph/claim/unclaim/done/
  block/unblock/deps/depends-on/add/rebuild` over Python's bundled sqlite3 (the `sqlite3` CLI is not
  installed in WSL; `sudo apt-get install -y sqlite3` adds it — password required, not run here).
- **Three `doc` tasks added** (DOC-001 U-series block, DOC-002 ninja block, DOC-003 expedition block)
  and their dependencies wired: `IMPL-018 ← DOC-001`, `IMPL-019 ← DOC-002`, `IMPL-020 ← DOC-003`.
  These are the only `ready` work until `REV-001` (catalog review) is cleared.

### Fleetcrash slice — recall executor + moon geography (15 September 2026)

IMPL-017 shipped the two smallest, host-verified F-cluster pieces and deferred the rest:

- **F2 (shipped)** — `QueueAiRecallAction` recalls the account's own in-flight save over the host's
  `cancelMission`, adding the ownership check the host lacks (host R5). A deployment parks the fleet;
  the recall is the other half of the save, so this closes a real gap rather than a niche one.
- **F3 (shipped)** — the save planner parks on a moon when one exists (phalanx-invisible, CRASH-006);
  building a moon stays an economy decision.
- **F1, F4 (deferred)** — both ride the crash-timing executor: F1's only consumer is the phalanx scan
  of an enemy return, and F4 needs the attack→debris→recycle chain plus debris-field awareness.
  A module phalanx range table would violate gate 1 and a forward over `canScanTarget` gate 2, so F1
  has no standalone code.
- **F5, F6 (deferred)** — F5 is P2 awareness-first per plan; F6 is last in priority, most niche and
  most expensive (redirect consequence now code-verified via `redirectFleetsFromMoon`).

Host surfaces verified read-only before writing: `PhalanxService` (range/cost/scan),
`JumpGateService::calculateCooldown`, `DebrisFieldService::calculateRequiredRecyclers`,
`cancelMission` guards, `RecycleMission` type 8, `MoonDestructionMission` type 9.

### Expedition executor + ninja gated (15 September 2026)

- **DISC-004 closed.** The host expedition surface is verified read-only: `ExpeditionMission` type 15
  with `hasReturnMission`, the slot-16 position gate, the astrophysics >= 1 gate, the slot budget
  (`getExpeditionSlotsInUse`/`getExpeditionSlotsMax`), the 1..astrophysics holding-hours bound, and the
  configurable outcome weights (dark matter, ships, resources, delay, speedup, nothing, black hole,
  pirates, aliens, merchant) with the Discoverer combat-reduction bonus.
- **IMPL-020 shipped.** `QueueAiExpeditionAction` dispatches one disposable civil cargo ship (host-classified
  via `getCivilShipObjects`, smallest cargo, probe and colony ship excluded) to slot 16 of the origin
  system. The never-fleetsave refusal is the single-hull fleet (EXP-001); the outcome table is the host's,
  never a module constant.
- **IMPL-019 blocked.** NN2 (the ninja trap) is gated behind a reviewed cluster per `gameplay-algorithms.md`
  ("advanced tactic gated behind a reviewed cluster, never silent"), and NN1's phalanx staging check needs
  a moon plus sensor phalanx that no account yet builds (F3 leaves moon-building open) — its no-moon
  fallback is the already-shipped T8 activity re-check.

### Ninja NN1 shipped, NN2 stays gated (15 September 2026)

- **NN1 (shipped)** — the raid dispatch now drops a target whose moon is active while its planet is
  quiet (`ActivityIntelReader::moonOnlyActivity` over the host's moon-coordinate lookup). This is the
  anti-ninja staging check (NIN-005) and it needs no own moon: it reads the *target's* moon. My earlier
  block note over-stated this as needing a module-owned moon and phalanx; corrected.
- **NN2 (deferred)** — the defender's timed counter-landing stays gated behind a reviewed cluster:
  timing-critical, and a wrong landing loses the fleet ("never silent").

### SP5 reservation + X1 transfer shipped (15 September 2026)
- **SP5 (IMPL-021)** — `ReserveFloor`: a build or research spend must leave a per-resource floor
  (10% of storage reduced by production over the saving horizon; 4h economy, 6h research). A resource
  the price does not spend keeps no floor, so a deuterium reserve never freezes surplus metal and
  crystal.
- **X1 (DEF-002)** — `QueueableTransferPlanner` + `QueueAiTransferAction`: a colony short of its next
  level's cost is funded from the body that can spare it, netting in-flight transports (E4) and keeping
  the source's SP5 reserve. Shipments below 50k combined metal+crystal are skipped (r4fek); the ferry
  carries just enough owned cargo hulls, never the combat fleet.

### V6 proactive save — investigated and re-deferred (15 September 2026)
- Tried and reverted. The trigger is not a scored candidate under the current one-action-per-session
  model: a proactive `FleetSave` candidate with any safety weight outranks `Build`/`Research` on every
  session gap (a Miner's 4-hour gap > the 30-minute FS-001 threshold, so it would save instead of
  building every session). The session's single selected action cannot express "build, then save before
  leaving".
- The fix is the persona **exposure band** — save only when the host's fleet value plus lootable stock
  clears a persona threshold — which is a persona-parameter design decision over the host's fleet value,
  not a constant. Deferred with that precise note in `gameplay-algorithms.md` V6; the session-plan
  reorder (compute the plan before the decision) is the other half and is itself safe but dead code
  without the band, so it was not kept.

### V6 proactive save shipped (15 September 2026)
- The trigger now has two halves: the reactive save (inbound hostile) and the proactive save
  (logging off for a real absence with a fleet worth losing). `SessionDecisionService` computes the
  session plan before the decision and passes the upcoming absence into the perception;
  `QueueableFleetSavePlanner::proactivePlan()` offers the save only past 120 minutes of absence and
  only when the fleet left behind clears the persona's exposure band.
- Exposure band (persona parameter, raw-price sum of the ships on the origin planet, defence
  excluded): fleeter 5,000, trader 25,000, miner/turtle/casual 50,000. The 120-minute absence
  threshold sits above the fleeter's ~70-minute inter-session gap and below the dark period, so the
  save fires at the last session before bed, never every session — which is what lets the
  one-action-per-session model express "build during the day, save before leaving".
- Supersedes the earlier "investigated and re-deferred" note: the exposure band resolves the
  every-session outrank, and the session-plan reorder is kept because it now has a consumer.

### V8 shadow waves shipped (15 September 2026)
- A save now splits a large fleet across two own bodies: combat hulls to the safer body (moon first,
  then farthest) and civil hulls — which lift the planet stock (FS-006) — to the next-ranked body.
- The split needs four things at once: a second own body, a free second fleet slot, both hull roles
  present, and a fleet at least twice the persona's exposure band (each wave stays worth the trip).
  A small or single-role fleet stays on one body, so "a small fleet is never split" holds without a
  second constant.
- Full top-k route × speed enumeration (V7) and staggered landing times stay open; V8 is the two-body
  shadow, not the whole V1 enumeration.

### RAID-008 score-ratio pre-filter shipped (15 September 2026)
- A target scoring under ~⅕ of the account's own public score is dropped before the profit test
  (a pre-profit filter, not a substitute): `targetReports()` publishes `score_viable` from the host's
  public highscore (`general`), and the candidate factory rejects it as `score_below_viability`.
- An unknown own score (zeroed highscore row in a young universe) filters nothing — skipping
  everything is worse than skipping nothing. The target's player id comes from the report's
  `planet_user_id`, so a report against a no-owner coordinate simply scores zero and is dropped once
  the account has a score.
- Remaining T6 target-choice terms stay open: relationship (RAID-007), proximity clustering
  (RAID-009), contest (RAID-013).

### RAID-009 storage-fill raid schedule shipped (15 September 2026)
- A fleeter raids on the storage-fill schedule (8-12h), not ad hoc every session: `RaidPlanner::storageReady()`
  gates the raid on the fleet planet's warehouse being near full (0.8 of capacity, the corpus' own
  near-full threshold from E3), and the candidate factory rejects each visible target as
  `storage_not_full` until it is. The account with an empty warehouse builds instead of raiding.
- The gate is checked once per decision, not per target, and only after staleness/legality, so the
  existing per-report rejection reasons are unchanged. A no-fleet or no-account player defers to the
  per-target planner rather than the gate.
- Proximity clustering (cross-galaxy ≈ 5× deuterium) and the relationship/contest terms stay open.

### T6/V7 residuals closed — clustering + route×speed shipped, relationship/contest deferred (15 September 2026)
- **Proximity clustering (RAID-009) is already shipped, not open.** `travel_cost` is the host-quoted
  distance normalised over the universe, and the host's own distance quote prices a cross-galaxy hop
  (`diffGalaxy × 20000`) against a within-galaxy hop (`deltaSystem × 95 + 2700`) — roughly the
  documented 5× deuterium — so the scorer already clusters raids near the fleet. Pinned by the
  existing test `owned state prices a distant target higher than a near one`. The earlier "stays open"
  note was stale and is retracted.
- **V7 route × speed is resolved for the deployment save.** The host's slowest speed (10%) is also the
  minimum-fuel speed, and a parked deployment has no arrival schedule to fit, so the two route axes
  collapse to the shipped destination ranking (moon-first, farthest) at the fixed slowest speed.
  Two residuals are recorded, not built: "discard unaffordable fuel" (the host already refuses an
  unfuelable save at dispatch and the receipt records it — a planner-side filter is polish, not
  correctness) and "never the same landing time" (departure rides the routine's session spread H2).
- **Relationship (RAID-007) deferred.** `AiRelationship` rows are only written by the social
  observation path, which ships with Package 6; until that state is populated a raid policy over it
  would be dead code.
- **Contest (RAID-013) deferred.** The module observes no other player's raid schedule, so a contest
  model would be an unmeasured guess; proximity is already the shipped edge.

### Grand-test live run — a nullable-defence crash fixed, a capability-research gap found (15 September 2026)
- **The live run crashed on every session that reached the escort check.** An espionage report stores
  a probe that revealed no defence as a **null** `defense` column (the host migration marks it
  nullable), and `QueueableUnitPlanner::observedDefendedTarget()` iterated it directly:
  `foreach() argument must be of type array|object, null given`. It was 467 of the 470 failed jobs.
  Guarded the same way `resources` already is (`?? []`) and pinned with
  `a report whose defence is null does not break unit planning`.
- **After the fix and a stack restart the cohort plays cleanly** — 3046 actions accepted against 211
  rejected, zero generative calls, all ten accounts growing. Miners, turtles and traders never raid
  (Raid is explicitly denied in their policies); the two fleeters raid 41–46% of sessions; the two
  casuals raid 27–29% (per `player-model.md`, "growth and occasional raids").
- **Gap found, task IMPL-022:** astrophysics is never researched, so **colonize and expedition are
  permanently unreachable**. All ten accounts have `astro = 0` after eight hours at 1000×, and seven
  of them already meet every prerequisite (`research_lab ≥ 3`, `espionage ≥ 4`, `impulse_drive ≥ 3`).
  `FacilityChain` is the only research source: `pending()` returns the unmet *prerequisites* of the
  cheapest non-producible ambition, and `nextAmbition()` skips an ambition whose prerequisites all
  stand. A leaf research that no unit requires (astrophysics) therefore has its prerequisites climbed
  and is then skipped forever. R2 says such a research must score through the capability it unlocks;
  today nothing does.

### Runtime AI YAML config — asked, declined on gate 2 (15 September 2026)
- Asked for the "YAML files that control the AI, like the `.ini` files in Generals and Freelancer".
  Honest answer: **it does not exist**, and the module has no YAML loader and no `symfony/yaml`.
- What exists instead: the five profiles are
  `app/Domain/Decision/Policies/{Miner,Turtle,Fleeter,Trader,Casual}Policy.php` (`$preferences`
  weights + `$allowed` gates); per-account settings are `AiProfile` rows (archetype, skill_band,
  random_seed, enabled); operator knobs are env-driven `config/*.php` (`AI_COGNITION_MODE`,
  `AI_HORIZON_WORK_PROCESSES`, population/budget/cognition).
- **Declined because gate 2 forbids exactly this shape:** "config for a value that never varies".
  The archetype weights, the allowed-action gates, the exposure bands (5k/25k/50k) and the raid
  ratios are fixed constants. Moving them to YAML is a lateral move that adds a loader, a validator
  and a second authority to drift against, while changing no behaviour — and it needs a dependency
  the rules say to avoid. The earlier proposal already separated the two things: research knowledge
  storage (built, as Markdown — `strategy-principles.md`, `source-registry.md`,
  `classical-ai-patterns.md`, `strategy-claims.md`) and a runtime profile representation
  ("only later, if justified").
- **What would change the verdict:** a demonstrated need for profiles to change without a code
  deploy (operator/modder tuning), or for the *set* of archetypes to be extensible by data. Then the
  smallest gate-clean mechanism is one `config/profiles.php` — the repo's own config convention, no
  new dependency — loaded once at boot. Gate 1 still holds: profile data may carry persona taste,
  never an object id, name, price or requirement.
