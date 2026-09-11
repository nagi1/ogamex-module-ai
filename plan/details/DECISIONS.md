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
