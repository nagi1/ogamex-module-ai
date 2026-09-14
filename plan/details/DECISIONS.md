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
| Measurement scope | **10 accounts now; 100/500/1,000 at the very end.** Capacity tuning is not what the population needs next, and a cohort small enough to read decision by decision is. The large runs stay owner-deferred and are not claimed as done. |
| Starting Package 5 | **Only once Package 4 is complete and signed.** Complete means every acceptance criterion is met with recorded evidence; signed means the 10-account pilot report has been reviewed and the owner's acceptance is written down in this file. The gate exists because the pilot is what says whether the accounts behave like players at all, and cooperative PvE puts a faction of them in front of humans. |
| What still blocks growth | **Evidence, plus one measured gap.** The 10-account pilot of 14 September 2026 is recorded below: it measured the plumbing working and the population not acting. The real-provider conformance artifact, Gate 2 for the three drivers, the pilot's human-feedback loop and the capacity runs are all still outstanding; Package 5 stays blocked behind them. |

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

**In-situ probe against the existing cohort (14 September 2026).** Before the scheduled second generation became due, the planner was asked what each enabled profile can queue in the live database. All ten pilot accounts returned a build, and the choice varies by persona — players 29129/29133/29138 want the solar plant, 29130/29132/29137 the crystal mine, 29131/29134/29135/29136 the metal mine — which is the first time an enabled account has had any capability at all. The eleventh enabled profile, the orphaned `987654321` left by an old benchmark, returned nothing, which is the host-account guard behaving on real data. This is a probe of publication, not a pilot result: it says the accounts can act, not that they have. The scheduled generation-2 sessions were still pending, due between 12:57 and 13:43, so the growth measurement the report needs is what comes next, and it is not claimed here.

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
