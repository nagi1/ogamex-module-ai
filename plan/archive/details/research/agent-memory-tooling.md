# How modern AI tooling solves agent memory, and what it costs here

Researched 14 September 2026 from public sources only: primary repositories, documentation,
papers, pricing pages and issue trackers. Vendor claims and independent measurements are
labelled separately, because the difference decides most of the questions below.

Owner: architecture. This extends [cognition and memory driver evaluation](memory-comparison.md)
with the *mechanism* and *resource* evidence that selection needed, and it is the reason the
[reference deployment profile](../specs/budgets.md#reference-deployment-profile) is now a
decision criterion rather than a note.

## The one fact that decides everything

**Every memory product in the current wave pays for memory with generative calls on the write
path, and almost all of them also require an embedding model.**

| System | LLM on write path | Embedder required | Extra datastore | Minimum runtime |
| --- | --- | --- | --- | --- |
| Mem0 OSS | **Yes — one LLM call per `add()`** | **Yes** (default `text-embedding-3-small`) | Qdrant by default | Python + vector store + LLM + embedder |
| Mem0 self-hosted server | **Yes** | **Yes** | Postgres + pgvector | Docker compose stack |
| Letta / MemGPT | **Yes — every step *is* an inference** | For archival search | Postgres or SQLite | Node.js 22.19+ server |
| Zep | **Yes** | **Yes** | Proprietary engine | Cloud or BYOC only |
| Graphiti (Zep's OSS core) | **Yes — one LLM call per episode, structured output required** | **Yes** | Neo4j 5.26 / FalkorDB | Python + a graph database |
| LangMem | **Yes — both hot path and background manager** | **Yes** (1536-dim in the canonical example) | LangGraph store; Postgres in production | Python + LangGraph |
| Cognee | **Yes — "processing and generated answers make provider calls"** | **Yes** | Graph + vector + relational | Python stack |
| A-MEM | **Yes — note construction, keywords, link analysis** | **Yes** | Vector store | Python |
| HippoRAG 1/2 | **Yes — OpenIE extraction** | **Yes** | Graph + vector | Python + **multi-GPU** documented setup |
| Concordia (DeepMind) | **Yes — "requires access to an LLM API"** | **Yes — "you must also provide a text embedder"** | none | Python + LLM API |
| Generative Agents (Park et al.) | **Yes — importance rating, reflection, planning, dialogue** | **Yes** for relevance | none | Python + LLM API + embedder |
| FAtiMA Toolkit | **No** | **No** | none | C# / .NET, headless |
| PsychSim | **No** | **No** | none | decision theory only |
| Utility AI (The Sims 3 and successors) | **No** | **No** | none | in-engine arithmetic |

Sources are the projects' own READMEs, docs and pricing pages (all HIGH as statements of their
own requirements): [mem0](https://github.com/mem0ai/mem0), [Letta](https://docs.letta.com/v1-sdk/concepts/stateful-agents),
[Graphiti](https://github.com/getzep/graphiti), [LangMem](https://github.com/langchain-ai/langmem),
[Cognee](https://github.com/topoteretes/cognee), [HippoRAG](https://github.com/OSU-NLP-Group/HippoRAG),
[Concordia](https://github.com/google-deepmind/concordia).

**No product in this survey is a drop-in, zero-generative, 2 GB component.** That is not a
detail of any one candidate; it is the shape of the category.

## Mem0, specifically

Mem0's own README states the hard dependency: *"Mem0 requires an LLM to function, with
`gpt-5-mini` from OpenAI as the default."* The April 2026 v3 algorithm is described as
"single-pass ADD-only extraction — one LLM call, no UPDATE/DELETE", plus entity linking and
multi-signal retrieval (semantic + BM25 + entity). The v3 change removed a *second* LLM call; it
did not remove the LLM.

Two further findings matter more than the architecture:

- **The published benchmark is not the open-source result.** Mem0's README concedes that
  "scores reflect Mem0's managed platform, which includes proprietary optimizations not
  available in the open-source SDK". In [issue #2800](https://github.com/mem0ai/mem0/issues/2800),
  a maintainer closing the thread states: *"The scores in the paper were from our SaaS platform
  (`MemoryClient`), not the OSS `Memory` class."* Independent reproductions posted in the same
  thread cluster far below the paper's figures.
- **Adopting it would violate this module's own budget rule on the first write.** Ordinary
  memory writes are exactly where the plan requires zero generative calls.

Mem0 stays rejected, and the rejection is now evidence-backed rather than a preference.
AgentOS is a different case and is addressed below.

## The reference-design mechanism, verbatim

Generative Agents is the paper every believable-NPC claim traces back to, so its mechanism is
recorded here precisely ([arXiv:2304.03442](https://arxiv.org/html/2304.03442v2), HIGH).

- **A memory object** is `{natural-language description, creation timestamp, most recent access
  timestamp}`. Observations, reflections and plans all live in the same stream.
- **Retrieval score** = normalised weighted sum of **recency**, **importance** and **relevance**.
  Recency is "an exponential decay function over the number of sandbox game hours since the
  memory was last retrieved. Our decay factor is 0.995" — decayed from **last access**, not
  creation. Importance is a 1–10 integer the language model outputs at write time. Relevance is
  cosine similarity between embeddings.
- **Reflection** triggers when accumulated importance crosses a threshold (**150**), roughly two
  or three times a day, over the 100 most recent records, and the resulting insights are stored
  **with pointers to the memories they cite** ("insight (because of 1, 5, 3)").
- **Planning** is top-down and recursive: day → hour → 5–15 minute chunks.
- **The cost, stated by the authors:** *"The present study required substantial time and
  resources to simulate 25 agents for two days, costing thousands of dollars in token credits
  and taking multiple days to complete."*

Note what that number is attached to: **25 agents, two simulated days.** Our target is 1,000
accounts, indefinitely, on a CPU-only 2 GB server. The paper is a source of *mechanism*, not of
architecture.

Its evaluation is still worth keeping, because it says which parts matter: the full architecture
scored highest, no-reflection was clearly worse, and reflection is what produced synthesis rather
than retrieval. It also reports **1.3% of agent responses about other agents were hallucinated
even with full memory and an LLM judge** — the baseline error rate for "what do I believe about
someone else".

## Established mechanism versus marketing

**Established, cheap, and implementable in PHP with no model** (all HIGH):

| Mechanism | Status |
| --- | --- |
| Exponential recency decay from last access (0.995/hour) | Shipped in the reference design |
| Importance assigned once at write time | Established mechanism; **it is an LLM call in the paper** |
| Weighted-sum retrieval with min-max normalisation | Established |
| Cumulative-importance reflection trigger | Established trigger; body is generated text in the paper |
| Tiered memory with eviction under budget pressure (warn, flush, evict half, keep everything retrievable, rolling summary at the head) | Shipped in MemGPT; the *policy* is arithmetic |
| Bi-temporal validity windows, invalidation instead of deletion | Shipped in Graphiti |
| Provenance: every derived fact points at the raw episode it came from | Shipped in both Graphiti and the Generative Agents reflections |
| Consolidation as an offline batch job | Established pattern; its *content* is LLM summarisation in every product |

**Marketing, not measurement:**

- Any headline "beats OpenAI memory by X%". Mem0's 26% relative improvement paper is
  vendor-authored and, per the project's own maintainer, describes the SaaS pipeline.
- Any LoCoMo headline, in either direction. Zep's original 84% claim against Mem0 was
  [withdrawn and corrected](https://github.com/getzep/zep-papers/issues/5) after Mem0 identified a
  denominator bug that excluded adversarial questions from the denominator while counting their
  correct answers: Zep's reply concedes "the corrected score is 75.14% +/- 0.17". **Both leading
  vendors accused the other of rigged evaluation and one conceded a ~25-point arithmetic error.**
- "Dreaming" and "self-improving memory", where the operation is a scheduled summarisation pass
  that "uses more model tokens".

**Benchmarks worth using as acceptance criteria rather than as scores:**

- **LongMemEval** ([arXiv:2410.10813](https://arxiv.org/abs/2410.10813)) tests five abilities —
  extraction, multi-session reasoning, temporal reasoning, **knowledge updates** and
  **abstention** — and reports a 30% accuracy drop for long-context assistants on sustained
  interaction. *Knowledge updates* and *abstention* are precisely the disciplines a
  zero-generative design must be tested on.
- **MemoryAgentBench** ([arXiv:2507.05257](https://arxiv.org/abs/2507.05257)) names four
  competencies — accurate retrieval, test-time learning, long-range understanding and
  **selective forgetting** — and concludes current methods master none of them all. Forgetting
  is a feature, and no shipped product has it.

## Games: what ships, and what it needs

| System | Needs | Evidence |
| --- | --- | --- |
| NVIDIA ACE on-device NPCs | **RTX GPU**; the smallest shipped model is a multi-GB GGUF (Nemotron Nano 9B v2, Qwen3.5-4B). A "compatible with CPUs" claim exists with no published throughput | HIGH for the requirement, vendor for the benefit |
| Inworld | Cloud API, metered per character-second / per million characters | HIGH for pricing |
| Convai | Cloud API, **30 concurrent sessions on the top self-serve tier** and a 2,500-MAU ceiling on the same tier | HIGH for pricing |
| Mantella (Skyrim) | Cloud LLM by default, STT + TTS for the speech loop | HIGH |
| Herika / CHIM (Skyrim) | **PHP + Postgres server** bridging to an LLM provider, local inference possible via llama.cpp; **one follower NPC** | HIGH for the architecture |
| RimWorld | No LLM: needs, traits, a mood meter, and a "storyteller" that picks events for narrative effect | MEDIUM, secondary |
| Dwarf Fortress | No LLM: NEO PI-R-derived personality traits, per-unit wound and stress simulation, tantrum cascades — **hundreds of individually simulated agents on one CPU** | MEDIUM, secondary |
| Utility AI (The Sims 3) | No LLM: needs × object satisfaction × personality preference, with a **Boltzmann temperature that is low when the character is doing well and high when it is doing badly** | MEDIUM, secondary; traces to Evans' GDC talk |

**Herika is the closest structural precedent to this module** — a PHP+Postgres agent server for a
shipped game — and it serves one companion NPC. **Dwarf Fortress is the closest scale precedent**,
and it is non-linguistic. No published system was found that runs believable LLM-driven
characters at 1,000+ scale on CPU-only hardware; the multi-agent research systems that do reach
that scale (OASIS at 1M, AgentSociety at 10k) are distributed cloud infrastructure.

## Verdicts against the reference profile

The target is an ordinary small VPS: **2 vCPU, 2 GB RAM, no GPU**, already running the Laravel
app, queue workers and the database. That leaves roughly **500–700 MB** for anything new, as an
estimate to be measured rather than a claim. Measured in this workspace: the FAtiMA sidecar idles
at **108.9 MiB** and the CBRKit sidecar at **142.1 MiB**.

| Candidate | Verdict on the reference profile | Reason |
| --- | --- | --- |
| Mem0 (library or server) | **Infeasible** | LLM + embedder per write, plus a second datastore and a second runtime |
| Letta / MemGPT runtime | **Infeasible** | LLM per step; consolidation spawns further model work |
| Zep / Graphiti | **Infeasible** | A JVM graph database plus an LLM and embeddings per episode |
| LangMem, Cognee, A-MEM | **Infeasible** | LLM and embedder on every ingestion |
| HippoRAG | **Infeasible** | Documented multi-GPU reproduction |
| Concordia, OASIS, AgentSociety | **Infeasible as runtime** | LLM API plus embedder; distributed research infrastructure |
| Hosted NPC platforms | **Infeasible at this scale** | Concurrency ceilings in the tens, not thousands |
| Any local LLM on the box | **Infeasible** | GPU-class or unusably slow; a multi-GB download |
| FAtiMA/CiF sidecar | **Feasible but not free** | ~109 MiB resident; measured, already integrated, still disabled pending Gate 2 |
| CBRKit sidecar | **Feasible but not free** | ~142 MiB resident; measured, already integrated, still disabled pending Gate 2 |
| AgentOS memory subset | **Measured, opt-in, still disabled** | Served as a stateless HTTP sidecar; 113.9 MiB resident, 1.69 GB image, no provider SDK and no model runtime in the image |
| Native engines in PHP | **Feasible and the only baseline** | Arithmetic and indexed rows; no extra process |

Two consequences worth recording as decisions rather than observations:

1. **The native path is not a fallback for the reference profile; it is the only path that fits.**
   Drivers are an opt-in for hosts with measured headroom, and the swap-ease rule exists so that
   stays true.
2. **Adding sidecars to a 2 GB server is a capacity decision, not a preference.** Any driver
   enablement on the reference profile needs a measured resident footprint and a stated
   remaining-headroom figure first.

## What we copy, and what we refuse

**Copy — these are mechanisms, not products, and they cost nothing at runtime:** the memory-object
shape (description, created-at, last-accessed-at, importance); recency decay computed from last
access; a weighted-sum retrieval ordered in SQL over an indexed candidate set, with a lexical or
term-overlap signal standing in for the embedding; importance assigned **at write time from
authored game-domain rules** (battle severity, alliance change, fulfilled or missed commitment,
relationship delta, first meeting) instead of an LLM 1–10 prompt; a cumulative-importance trigger
for consolidation; citation pointers on every derived record; MemGPT's eviction policy without its
summariser; Graphiti's validity windows with invalidation instead of deletion; utility AI with a
state-dependent temperature so the same situation produces different behaviour for different
accounts; FAtiMA's separation of appraisal from decision from social appropriateness from
dialogue; PsychSim's recursive belief models for theory of mind; LongMemEval's *knowledge updates*
and *abstention* and MemoryAgentBench's *selective forgetting* as acceptance criteria.

**Refuse — each needs an LLM, an embedder, a graph database, a GPU, or too much RAM:** every
product in the table above, and any semantic or vector retrieval on the baseline path. Embeddings
stay a gated, optional, separately metered escalation exactly as the budget document already says.

## Relationship to the no-duplication rule

This research does not license a PHP reimplementation of any driver.

- The mechanisms above are **design patterns with multiple independent implementations** —
  utility AI, decayed retrieval scoring, validity windows, eviction policy. Adopting a pattern that
  FAtiMA, Graphiti, MemGPT and The Sims 3 each implement differently is not porting any one of them.
- The recall seam stays a seam. Native recall is the default and the absent-driver fallback, which
  the standing rule explicitly preserves; AgentOS remains the candidate for advanced recall and is
  **not** enabled, because it has neither passed Gate 2 nor produced a measured footprint.
- The module's authority — scope, attribution, permission, current validity, validation, budgets,
  persistence, failure mapping and wire-format translation — is unchanged. Nothing here moves any
  of it into a driver, and nothing here moves a driver's job into PHP.

## Gap register

| # | Question | Status |
| --- | --- | --- |
| G1 | Resident footprint of the AgentOS memory subset | **Measured: 113.9 MiB idle**, in the same range as the other two sidecars. The image is 1.69 GB, which is disk rather than memory. |
| G2 | Whether AgentOS cognitive memory needs embeddings for its core operations | **Settled for our use:** the served driver supplies its own deterministic hashing embedder and installs no model runtime, so recall works with no external call. That embedder is lexical, not semantic. |
| G3 | Cost of the reference profile under real Phase 3 load | **Not measured.** The 2/5/10-account runs in 3J are what turn this document's verdicts into figures; at 10 accounts they show per-player cost holding, not a stress-tested capacity ceiling. |
| G4 | Whether a measured, useful capability exists that only a driver provides | **Open, and it is Gate 2.** Until it does, every driver stays disabled. |
