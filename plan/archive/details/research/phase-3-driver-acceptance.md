# Phase 3 driver acceptance criteria

Defined **before** any adapter exists, so a spike cannot pass while proving nothing.
Every criterion below is a required check with a named artifact. A driver that fails
Gate 1 is disabled and the native implementation stays; that is an acceptable result,
not a failure of the work.

Pinned identities under test:

| Driver | Contract | Coordinates | Runtime | License |
| --- | --- | --- | --- | --- |
| CBRKit | `ExperienceEngine` | `cbrkit[api]==1.6.0` | CPython 3.13 | MIT |
| FAtiMA/CiF | `AffectEngine` + `SocialCognition` | `GAIPS/FAtiMA-Toolkit` @ `56b7cbd9` via `nagi1/FAtiMAtry` | .NET 8 | Apache-2.0 |
| AgentOS | `LongTermMemory` | `framerslab/agentos` (agentos.sh) | Node/TypeScript | Apache-2.0 |

## A. Global gates — every driver

| # | Criterion | Evidence |
| --- | --- | --- |
| A1 | **Absence is free.** The full module suite and the Phase 3 behavioural matrix pass with the driver not running, not configured, and not installed. | Existing suite green with no sidecar; boot log shows native binding. |
| A2 | **One authority.** The module's tables stay canonical. Disabling or swapping the driver preserves persona, relationships, commitments and outcome cases. | Disable/swap test asserting identical module rows before and after. |
| A3 | **Zero generative calls** on the ordinary path, proved by instrumentation or by an image with no provider library present — never by a product's wording. | Recorded request log / image manifest inspection. |
| A4 | **Bounded.** Connect and request timeouts, payload cap, candidate cap, concurrency cap, health check, circuit breaker and a *tested* native fallback. | Timeout, oversized-payload, unhealthy and open-circuit scenarios. |
| A5 | **No truth widening.** Scope, attribution, deletion and current-validity are rechecked in module code. A driver cannot surface a record the module would not. | Scope/leak scenario per driver. |
| A6 | **Module-owned determinism.** The module applies its own ordering and tie-break; the supplied clock and seeded randomness stay explicit. | Frozen-clock/seed trace reproducibility test. |
| A7 | **Measured, not claimed.** p50/p95 latency, request and response bytes, CPU and RAM, and observed failure modes recorded for the real adapter. | Numbers in the decision record; no unmeasured capacity language. |
| A8 | **Coverage holds.** Changed module code reaches 100% PCOV with the driver absent; real-adapter verification is a separate opt-in run. | Coverage gate plus opt-in conformance output. |

## B. Gate 1 — conformance (provable now)

### CBRKit → `ExperienceEngine`

| Scenario | Pass condition |
| --- | --- |
| Contract scenario | Real sidecar returns a ranking for a real owner-scoped casebase over the module's own `BuildingUpgrade` feature family. |
| Equivalence | Similarity and ordering match `NativeExperienceEngine` exactly for every case in the fixture, because the driver reproduces the module's formula. |
| Stateless | The sidecar retains no case between requests; it holds no database and no index. |
| Outage | Sidecar stopped mid-flight yields the native ranking, not an error and not an empty result. |
| Invalid | Malformed body and non-200 response yield the native ranking and a recorded degraded mode. |
| Circuit | Repeated failures open the circuit and stop issuing requests until cooldown. |
| Bounded | An oversized casebase is capped by the module before it is sent. |

### FAtiMA/CiF → `AffectEngine` + `SocialCognition`

| Scenario | Pass condition |
| --- | --- |
| Headless | Pinned image builds and serves on Linux with the `netcoreapp3.0 → net8.0` retarget as the only upstream change. |
| Single session | One integrated character state advances **once** per event; two contracts are not served by two independent character states. |
| Snapshot round-trip | Character state can be loaded and saved, so revision and idempotency handling is possible, and a restart does not silently reset persona. |
| Persona sensitivity | The same stimulus produces persona-differentiated appraisal versus the native baseline. |
| CiF surface | Social-exchange operations are reachable over HTTP and return a typed stance. |

If the CiF surface is not reachable, FAtiMA passes for `AffectEngine` only and is recorded
as a partial result — it does not become a `SocialCognition` implementation by assumption.

### AgentOS → `LongTermMemory`

| Scenario | Pass condition |
| --- | --- |
| Memory-only | Only memory operations are enabled; tools, agent runtime, autonomous personality and extraction/derive/reflection are off. |
| Zero inference | Instrumentation proves no generative and no embedding request on the baseline profile. |
| Retrieval | Index, recall and retention/decay operate over module-approved episodes only. |
| Deletion | Deleting a module source propagates completely to the driver; nothing is recallable afterwards. |
| Rebuild | The projection is disposable and can be rebuilt from module facts without losing persona or obligations. |

## C. Gate 2 — demonstrated value (falsifiable)

Gate 1 alone does not justify a network hop. Each driver must also show a capability or
quality gain the native path does not already provide.

| Driver | Value condition | If it fails |
| --- | --- | --- |
| CBRKit | It enables retrieval-quality measurement over **held-out real outcomes** (`cbrkit.eval`) that the native path cannot express, or improves held-out top-k relevance by ≥5 pp using a per-feature weighted measure the native uniform mean cannot represent. | Driver stays disabled; record states that native equivalence was the only result. |
| FAtiMA/CiF | Documented appraisal/social capability the native baseline does not model, with a behavioural difference trace. | Driver stays disabled; the capability gap is recorded as a known limitation of the native baseline. |
| AgentOS | ≥5 pp required-fact recall improvement, or ≥20 % lower cost at comparable quality, with no scope leaks and no worsened current-fact correctness. | Driver stays disabled; native scoped facts remain the retrieval path. |

Note on CBRKit specifically: because the driver reproduces the native formula, equivalence
is expected and is **not** evidence of value. Without Gate 2 the sidecar adds latency for no
gain, and the decision record must say so.

## D. Failure protocol

A failed spike produces: the exact blocker, pinned coordinates and primary-source URLs, the
reproduced failing check, the capability/behaviour gap, and the retained native fallback.
No silent removal of an integrated capability and no substitution of an unreviewed
framework to make a spike green.

## E. Out of scope for this slice

Deferred by the user: driver authentication and payload-limit hardening (tracked separately).
Not started: embeddings, ML prompt compression, PsychSim, vector/index backends.
