# Phase 3 driver decisions

Verdicts against the gates in [phase-3-driver-acceptance.md](phase-3-driver-acceptance.md).
Every claim below was reproduced against the pinned driver; nothing here is taken
from a product's wording.

| Driver | Contract | Gate 1 (conformance) | Standing |
| --- | --- | --- | --- |
| CBRKit `1.6.0` | `ExperienceEngine` | **Pass** | Enabled opt-in; native retained as fallback |
| FAtiMA/CiF affect | `AffectEngine` | **Fail** | Driver disabled; native retained |
| FAtiMA/CiF social | `SocialCognition` | **Partial** | Volition only; not bound |
| AgentOS `0.10.16` | `LongTermMemory` | **Pending** | Blocked on a size decision |

## CBRKit — pass

- **Equivalence.** Five fixtures, including a null query feature, no shared keys,
  a categorical feature and negative/zero numbers, all matched an independent
  replica of `NativeExperienceEngine` **exactly** on both value and order.
- **Single authority.** The module sends its owner-scoped casebase per request and
  keeps the canonical records, the version scoping and the tie-break. The sidecar
  stores nothing and needs no vector database.
- **Determinism.** The driver's own tie order is unspecified and was observed
  differing from the module's, so the module re-applies similarity-descending then
  ascending case id.
- **Degradation, all reproduced.** Transport failure, non-success status, malformed
  payload, non-object payload, a response omitting a sent case, and an open circuit
  each fall back to the native ranking.
- **Measured failure modes.** `casebase` values that are not objects return **500**;
  empty casebase or queries return 200; 2000 cases are accepted with no server-side
  size limit (covered by the separate auth/limits task).
- **Zero generative calls.** No provider, embedding or synthesis extra is installed,
  so the served process has no library that could call a model.

Remaining risk: gate C (demonstrated value) is **not** met. Because the driver
reproduces the module's formula, equivalence is expected and is not evidence of
value. The driver stays opt-in and disabled by default until a held-out-outcome
measurement shows a gain.

## FAtiMA/CiF affect — fail

The engine works and appraises correctly in isolation — a `Smile` perception
produced `Joy` at intensity `3.5` and moved mood from `-5.0` to `-3.95`
(`3.5 x 0.3`), with the autobiographic record and beliefs intact. It fails the
contract, not the smoke test.

**Blocker: character state cannot be read back or written, and does not survive a
restart.** Enumerated from the running server and the source:

| Resource | Methods | Consequence |
| --- | --- | --- |
| `/perceptions` | POST | the only way to move state |
| `/actions`, `/worldmodel` | POST | move state indirectly through authored effects |
| `/beliefs`, `/emotions`, `/memories`, `/decisions` | **GET only** | state can be observed, never set |
| `/instances` | GET, POST, DELETE | a new instance is copied from the scenario template |

`ServerState` holds `ConcurrentDictionary<string, IntegratedAuthoringToolAsset[]>`
in process memory. There is no snapshot or restore endpoint — the only `ToJson`
call is the template copy used when allocating a fresh instance — and instances cap
at `MAX_INSTANCES = 100` per scenario. A restart therefore resets mood, emotions and
beliefs with no way to detect or repair the loss.

That breaks two gates:

- **A2 (one authority).** Disabling or swapping the driver silently discards affect
  state the module cannot reconstruct, because the state only ever existed inside
  the driver's process.
- **Gate 1 snapshot round-trip.** Without load/save there is no revision or
  idempotency handling, so a retry cannot be distinguished from a new appraisal.

`AffectEngine` also receives `relationshipTrust` per call, and the HTTP surface has
no belief-write resource, so trust cannot be supplied without authoring world-model
effects for every exchange.

**What would unblock it.** A state export/import path for a character instance, or a
decision to author OGame appraisal assets whose rules read stimulus magnitudes from
the event arguments and hold no cross-event state. The latter makes FAtiMA a rule
interpreter rather than an integrated character, which is a product decision, not an
engineering one. Until then the native `AffectEngine` remains and FAtiMA stays
disabled.

## FAtiMA/CiF social — partial

CiF loads into the character and registers a `Volition(SocialMove, Step, Target,
Mode)` dynamic property, and it genuinely drives decisions: a controlled pair gave
utilities `{2.0, 10.0}` at rapport 5 and `{2.0}` at rapport 1, so the CiF-gated rule
fires only when the exchange's influence rules allow it.

It still cannot serve `SocialCognition`, which returns *ranked social responses with
reasons*. No HTTP resource exposes social exchanges at all: they cannot be created,
listed, read, advanced or inspected at runtime, exchanges are fixed when the scenario
is authored, and the protocol advances only through authored dialogue carrying
`SE(name, step)`. `VolitionValue` additionally computes **only at the first step**, so
the signal answers "should this exchange start", not "what is the current protocol
state". A module-side stance mapping would be our invention, not driver capability,
so no binding is made.

## AgentOS — pending

The memory subset completes an encode/retrieve cycle with **zero provider
credentials** and no model download, returning traces with provenance, encoding
strength, decay stability and a tip-of-the-tongue bucket. Two things block adoption:

1. **No local embedder ships.** `EmbeddingManager` requires a provider id plus an
   `AIModelProviderManager`, so the stock configuration reaches an external provider.
   Zero-generative is only possible through our own `IEmbeddingManager`, currently a
   deterministic hashing embedder that is lexical, not semantic.
2. **Install size is ~920 MB**, of which `onnxruntime-node` (536 MB),
   `onnxruntime-web` (92 MB) and `@huggingface` (49 MB) are unused by the memory
   subset.

An HTTP sidecar and a size/prune decision are required before this can be evaluated.
