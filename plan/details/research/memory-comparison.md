# Cognition and memory driver evaluation

Revised 11 September 2026 from the [referenced design conversation](chatgpt-conversation://6aa3a38a-0a34-83e9-aae1-dc75ed9ac709). This is the current selection and verification plan. Product/API, licensing, maintenance, Linux-support and token-cost statements made by the earlier chat assistant are unverified until checked against a pinned primary source and exercised locally. No external driver is currently implemented.

## Concrete direction

Keep exact structured facts, persona, relationships, obligations and current cognitive state in AI-module tables. Build small module contracts and native/disabled defaults. The first external cognition candidates to test are **FAtiMA/CiF** and **CBRKit**. The preferred advanced-memory candidate is the **AgentOS cognitive-memory subset** discussed in the conversation, with generative memory features disabled. These are hypotheses to test, not hard dependencies.

This replaces the earlier Mem0-first recommendation. The user explicitly rejected Mem0. Hindsight plus Graphiti was an intermediate choice, subsequently superseded by the focus on NPC cognition, ordinary Linux hosting and lower generative cost. Do not reopen an open-ended product search or install all candidates together.

| Candidate / implementation | Bounded responsibility | Current decision |
| --- | --- | --- |
| Native module SQL + authored social rules + structured CBR | Authoritative observations, claims, obligations, selected recall, social/experience baseline | Required baseline; no generative calls or embeddings required. |
| FAtiMA/CiF | Integrated appraisal, goals/emotions, social importance, emotional autobiography, exchange/dialogue intentions | Candidate behind affect/social contracts. Verify useful integrated capabilities and headless operation; do not install separate overlapping FAtiMA and CiF brains. |
| CBRKit | Structured feature similarity and retrieve/reuse/revise/retain support | Candidate behind the experience contract. Use non-generative structured operations; compare with native cases and real outcomes. |
| AgentOS cognitive-memory subset | On-demand older personal/conversation recall, relevance and bounded retention mechanics | Preferred advanced-memory candidate, not a required Phase 3 runtime. Verify exact project identity; several products use this name. No full agent loop, tools or autonomous persona. |
| PsychSim | Bounded estimates of others' beliefs/goals and likely social responses | Optional advanced diplomacy/coalition experiment. No all-player/per-tick invocation; not Phase 3 critical path. |
| Graphiti / Zep | Temporal social chronology where native facts demonstrably fail retrieval requirements | Secondary conditional research, not a second mandatory graph and extraction pipeline. |
| Mem0 | Former conversational-memory proposal | Rejected for this plan; do not retain it as the default or first bake-off candidate. |
| Cognee | Graph/document retrieval considered earlier | Not selected. Its proposed ingestion/retrieval role overlaps with native facts and the optional memory driver; no additional graph/extraction pipeline without a distinct measured need. |
| Hindsight / MemOS / Letta | Broader memory/agent approaches explored in the discussion | Not selected for Phase 3. Automatic extraction/reflection, policy induction and agent-managed gameplay are outside the baseline. |
| Cognitiv, Affect Kernel, TDRS/Anansi, Ensemble and other alternatives | Design references or possible future substitutions | No implementation commitment or maturity claim. Revisit only for a specific failed capability, not because another library exists. |
| Utility AI / GOAP | Game-specific competence and planning | Keep existing utility policies. GOAP is not implemented and is not a new Phase 3 dependency; add only a concrete missing planner capability if needed later. |
| Embeddings / prompt compressors | Optional prose retrieval / context reduction | Native selection and budgets first. Neither is required for structured memory, CBR or ordinary gameplay. |

## Verify identity and compatibility before installing

For each selected spike, record exact repository/package coordinates, maintainer, license, pinned commit/release, primary documentation URL, runtime, transitive dependencies and supported deployment. The supplied AgentOS discussion identifies it with the Paracosm/Wilds ecosystem but does not provide sufficient literal package coordinates; resolving that ambiguity is the first AgentOS task. Do not choose an unrelated AgentOS product by name.

Use one focused spike per selected driver: a real OGame-shaped contract fixture, headless startup on the project's Linux environment, persistence/restart, timeout and disable/swap. Distinguish native library portability from GUI/authoring-tool portability. Do not claim that a language runtime's Linux support proves the package runs or that a CPU implementation supports a thousand active characters.

Fail the spike when the pinned driver cannot satisfy the contract within the agreed runtime/cost boundary. Record the blocker and specific behavior gap, keep the tested native fallback, and leave that driver disabled. Do not silently remove integrated cognition features or substitute another unreviewed framework to make the spike green.

## AgentOS profile to verify

Evaluate only direct memory operations for selected episodes: indexing, recall, retention/decay, relevance, reinforcement, source-confidence handling and optionally affect-informed ranking. These are desired capabilities, not certified package APIs. Provenance and current truth are rechecked in module storage regardless of provider score.

Require zero generative calls for the baseline memory profile. Where the pinned version offers the options described in the discussion, select deterministic/keyword feature detection and disable LLM/hybrid detection, auto-ingest extraction, LLM derive, observation compressor/reflector, HyDE, autonomous tools and full agent orchestration. Instrument requests to prove no hidden generation occurs. Do not assume an inference-disable flag, no configured API key or a product's zero-token wording also means zero embeddings or zero network activity.

Embeddings, if required by a supported operation, use a separately metered, explicitly enabled embedder. Measure recall and CPU/RAM before selection, in English only, matching the [language decision](../specs/memory-and-language.md). The initial disabled/native semantic path must still work. If the package cannot provide the desired operation without unwanted inference/infrastructure, reject that configuration and report it.

AgentOS does not own goals or personality. Project the one module persona into any retrieval biases. An emotional memory is subjective evidence, not an independent fact or a new event merely because it was recalled. Do not synchronize FAtiMA and AgentOS databases or let one provider's inference reinforce itself through another.

## Evaluation corpus and experiments

Use synthetic or consented, sanitized cases with stable source IDs and expected facts/allowed decisions. Split authoring/tuning cases from held-out scenarios. Every run records module/host revision, ruleset, persona, seeds/clock, driver versions, configuration, hardware and request accounting.

The corpus covers betrayal and later repair, fulfilled/expired ceasefire, source-attributed gossip, changed alliance membership, unfulfilled/partially affordable promises, ambiguous names/conditions, missing/stale intel, provider outages, deletion and isolation. Include English conversations, short routine turns and long histories. Compare the same authorized information and context ceilings for each driver.

Run configurations A–G on identical fixtures, distinguishing capability ablation from implementation replacement. A is a reduced experimental baseline: native facts, obligations and authored social rules remain, while affect and experience enrichment are disabled. B enables native affect; separately replace it with FAtiMA/CiF while holding enabled capabilities fixed. C adds native structured CBR to B; separately substitute CBRKit. C corresponds to the full native cognitive baseline rather than implying it needs those external drivers.

D adds advanced recall to C. E and F are independent additions to C, respectively bounded Theory of Mind and semantic retrieval, so their gains/costs can be attributed; test a combined configuration only after those individual results. G varies the language provider against one explicitly recorded fixed cognition configuration. Also remove one component from a combined setup to measure its contribution. These are experiments, not dependencies that must all ship.

Keep LLM disabled when evaluating deterministic gameplay/social changes. When measuring language quality, hold the reader/generator and prompt budget constant so a provider is not credited for extra model calls or a larger prompt.

| Evaluation | Measure / required evidence |
| --- | --- |
| Behavioral usefulness | Different justified persona responses, agreement handling, sensible recovery, repeated-error reduction, authoring effort and baseline comparison. No claim of optimal play from a few successful traces. |
| Memory quality | Required-fact recall, stale/current confusion, contradiction handling, attribution preservation, source validity and owner/channel leakage. |
| CBR | Retrieval relevance, cold-start/missing-feature behavior, success/failure calibration, version filtering and improvement from held-out real outcomes. |
| Dialogue | Correct route selection, known-exchange coverage, repetition, inappropriate escalation/silence, promise accuracy and human-rated persona consistency. |
| Cost and runtime | Every generative/embedding/reranking/summary request, average/p95 prompt size, token/cost per active human conversation, route distribution, p50/p95/p99 latency, CPU/RAM, database work, storage growth and retries. |
| Operational safety | Restart/swap without losing persona or obligations, idempotency, bounded timeout/backlog, complete deletion propagation and disabled-driver behavior. |

Test 100, 500 and 1,000 registered players with separately specified active fractions, history sizes, simultaneous messages and event rates. Record named ordinary Linux hardware, warm/cold calls, sustained evaluations/second, queue lag, CPU/RAM and serialization/sidecar overhead. Stop increasing load when correctness or the measured response SLO fails. These are test populations, not promised capacity.

For optional external recall, retain the existing proposed acceptance target: at least a 5-percentage-point improvement in required-fact recall or 20% lower total cost at comparable quality, with no observed scope leaks, no worsened current-fact correctness and acceptable operations. Fix corpus, sample size and thresholds before tuning; report uncertainty. This numerical target does not replace correctness scenarios or automatically select a cognition driver.

## Delivery result and future extraction

Each spike ends with a decision record: selected driver/configuration or documented fallback, primary sources, reproduced checks, known gaps, cost, export/delete behavior and enable/disable procedure. Keep module-native IDs and versioned data so changing a provider does not erase relationships.

An actual external driver replacement must work through Laravel bindings and the same contract scenarios before calling that driver supported. Future framework extraction requires evidence of a real swap without OGame domain changes and stable contracts proven by use. A second consumer strengthens that case but no hypothetical consumer justifies scaffolding today. This plan creates no framework repository, universal planner, driver marketplace or host cognition service.
