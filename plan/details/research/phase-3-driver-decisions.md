# Phase 3 driver decisions

Verdicts against the gates in [phase-3-driver-acceptance.md](phase-3-driver-acceptance.md).
Every claim below was reproduced against the pinned driver; nothing here is taken
from a product's wording.

| Driver | Contract | Gate 1 (conformance) | Standing |
| --- | --- | --- | --- |
| CBRKit `1.6.0` | `ExperienceEngine` | **Pass** | Wired opt-in; native retained as fallback |
| FAtiMA/CiF affect | `AffectEngine` | **Pass** (after a vendored patch) | Wired opt-in; native retained as fallback |
| FAtiMA/CiF social | `SocialCognition` | **Pass** (after a vendored patch) | Wired opt-in; native retained as fallback |
| AgentOS `0.10.16` | `LongTermMemory` | **Deferred** | Not adopted; recall stays native plus a projection |

Gate 1 is satisfied for all three evaluated drivers. Gate 2 (demonstrated value) is
**not** met for any of them, so every driver remains opt-in and disabled by default,
and the native path stays the shipped behavior.

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

## FAtiMA/CiF affect — pass, after patching the vendored server

The engine appraises correctly: a `Smile` perception produced `Joy` at intensity
`3.5` and moved mood from `-5.0` to `-3.95` (`3.5 x 0.3`), with the autobiographic
record and beliefs intact.

Unmodified, it failed the contract. `ServerState` holds
`ConcurrentDictionary<string, IntegratedAuthoringToolAsset[]>` in process memory,
the HTTP surface exposed beliefs and emotions as **GET only**, and a new instance
was copied from the scenario template — so character state could be observed but
never set, read back or survive a restart. `MAX_INSTANCES` also caps instances at
100 per scenario.

Upstream did not overlook persistence: `IntegratedAuthoringToolAsset.ToJson()` and
`FromJson()` are public and are what instance allocation already uses. The gap is
that their intended host is an in-process C# game or robot, where the caller holds
the character object and no network boundary exists. State changes were also meant
to flow through the authored world model, which is why `POST /worldmodel` exists and
no arbitrary belief write was offered. Serving an external, swappable cognition
driver simply is not their use case.

Because the toolkit is vendored and maintained here, the two missing operations
were added to `Wrappers/WebServer`:

| Addition | Resource | Verified behaviour |
| --- | --- | --- |
| Instance snapshot | `GET /scenarios/{s}/instances/{i}/state` | returns the instance as scenario JSON |
| Instance restore | `POST /scenarios/{s}/instances/{i}/state` | replaces the instance from that JSON using the template's assets |
| Belief write | `POST /scenarios/{s}/instances/{i}/characters/{c}/beliefs` | `{name, value}` updates the character's knowledge base |

Reproduced end to end: two `Smile` perceptions raised mood `0.0` to `3.135`; a
restore returned it to `0.0` with emotions cleared and the exported payload
byte-identical; and setting `RapportLevel(SELF,Player)` to `80` made the next
appraisal produce `Joy 10.0` (clamped), proving the belief actually drives the
authored rule rather than merely being stored.

That closes gate A2 and the Gate 1 snapshot round-trip for `AffectEngine`.
Remaining limitation: state is still process memory, so the module must treat the
driver as a rebuildable projection and restore from its own record after a restart.

## FAtiMA/CiF social — pass, after patching the vendored server

CiF loads into the character and registers a `Volition(SocialMove, Step, Target,
Mode)` dynamic property, and it genuinely drives decisions: a controlled pair gave
utilities `{2.0, 10.0}` at rapport 5 and `{2.0}` at rapport 1, so the CiF-gated rule
fires only when the exchange's influence rules allow it.

Unmodified, that was the *only* way to reach CiF. No HTTP resource exposed social
exchanges — they could not be listed, created, read, evaluated or advanced at
runtime, so a driver swap was impossible even though the engine had the state. The
surface was added to the vendored `Wrappers/WebServer`:

| Addition | Resource | Verified behaviour |
| --- | --- | --- |
| List exchanges | `GET /scenarios/{s}/instances/{i}/characters/{c}/socialexchanges` | returns `Name`, `Steps`, `Target`, starting conditions and influence rules |
| Evaluate exchanges | `POST .../socialexchanges` with `{target}` | returns each exchange's current step and volition per usable mode |
| Author an exchange | `POST .../socialexchange` | creates or updates an exchange and returns its id |

`Name`, `Target` and `Mode` are `WellFormedName` values, which Newtonsoft serializes
as a property bag rather than text; the list projection converts them to strings so
the wire format is stable.

### Correcting an earlier claim in this record

An earlier revision of this file stated that `VolitionValue` "additionally computes
**only at the first step**". **That was wrong**, and it was reached by reading the
guarded branch and stopping there. `SocialExchange.VolitionValue` branches on
`step == Steps.FirstOrDefault()`: the first step is gated by `StartingConditions`,
while the `else` branch sums the influence rules for every later step. Reproduced
with a gated exchange (`Steps = Start, Give, End`, starting condition
`RapportLevel(SELF, [x]) > 3`, one influence rule worth `7`):

| Rapport | Step | Volitions |
| --- | --- | --- |
| 5 | `Start` | `{"*": 7.0}` |
| 1 | `Start` | `{}` |
| 1 | `Give` | `{"*": 0.0}` |

The third row is decisive: rapport 1 still fails the starting condition, yet the mode
is **present and finite** once the step advances. The gate answers "may this exchange
start", not "is this exchange usable". The step advanced from `Start` to `Give` after
the instance perceived
`Event(Action-End, Player, Speak(*, *, SE(GiveMetal, Start), *), John)`, so progress
is tracked from the character's own autobiographic memory rather than module state.

Gate 1 is therefore met: the driver exposes the exchanges, their multi-step protocol
position and the per-mode volition. Two limits remain before a binding is made:

1. **Ranked responses with reasons are not a driver output.** CiF yields a scalar
   volition plus the resolved step. Turning that into ordered stances with
   justifications is a module-side mapping, so it must be our documented choice — and
   it must not claim to be driver capability.
2. **Target binding is still the author's job.** A constant target makes `VolitionValue`
   throw `BadSubstitutionException`, because it builds a `Substitution` from the target;
   an unbound `[x]` in a decision rule matches no counterparty. The mapping must supply
   a concrete target per counterparty.

## Binding the two FAtiMA contracts

Both contracts are wired behind module-owned seams and are opt-in through one
setting. `AiCognitionDriver` names the supported values and `ai.cognition.driver`
selects one; the default is `native`, so an unconfigured or absent sidecar changes
nothing.

| Piece | Role |
| --- | --- |
| `FatimaScenarioTemplate` | Loads the module-owned scenario fixture, so the driver is served this module's characters rather than a sidecar default. |
| `FatimaClient` | The only transport. Connect and request timeouts, payload handling, and a driver-scoped `DriverCircuitBreaker`. |
| `FatimaCognitionSession` | One shared character state per process, serialized by `Cache::lock('ai:cognition:fatima')`. |
| `FatimaAffectEngine` | `AffectEngine` decorator; falls back to native on any failure. |
| `FatimaSocialCognition` | `SocialCognition` decorator; falls back to native on any failure. |

Serving both contracts from one **shared** session is deliberate: the acceptance
criteria require that two contracts are not backed by two independent character
states, and a lock serializes appraisals so concurrent workers cannot interleave
them.

### Affect — reproduced behaviour

Stimulus values are signed before they are sent, because the driver's rules cannot
negate a term and an ill-formed `-[d]` is rejected.

| Stimulus | Observed emotion |
| --- | --- |
| Aid `0.5` | Gratitude `0.5` |
| Harm `0.4` | Anger `0.4` |
| Threat `0.6` | Fear `0.6` |

Persona differentiation is real: an identical threat of `-0.5` produced
`0.5 / 0.5 / 0.15 / 0.35 / 0.25` for Miner, Turtle, Fleeter, Trader and Casual.

Two module-side limits are recorded rather than hidden. Only Anger, Fear and
Gratitude are representable, so any other emotion the driver returns is **declined**
and the native appraisal is used instead of approximated. Fear is the one branch
that needs a goal and a prospect rule, so the fixture carries a goal; without it the
driver has nothing to appraise fear against.

Each appraisal reloads the scenario, which is what makes the result deterministic —
goal state cannot accumulate across calls and change a later answer.

### Social — reproduced behaviour

| Rapport | Step | Volitions |
| --- | --- | --- |
| 5 | `Start` | `{"*": 7.0}` |
| 1 | `Start` | `{}` |
| 1 | `Give` | `{"*": 0.0}` |

The third row is the one that matters: the influence-rule branch still yields a
finite volition after the starting condition fails, so a scalar volition alone cannot
tell the module whether an exchange is usable.

The decorator therefore has a deliberately narrow authority: it can only **withhold**
an acceptance that the native rules already granted. It never overrides a native
non-acceptance, and it declines to answer at all without a persona and a
counterparty, because the driver needs a concrete target bound per counterparty.

## AgentOS — deferred

The memory subset completes an encode/retrieve cycle with **zero provider
credentials** and no model download, returning traces with provenance, encoding
strength, decay stability and a tip-of-the-tongue bucket. Two things blocked adoption:

1. **No local embedder ships.** `EmbeddingManager` requires a provider id plus an
   `AIModelProviderManager`, so the stock configuration reaches an external provider.
   Zero-generative is only possible through our own `IEmbeddingManager`, currently a
   deterministic hashing embedder that is lexical, not semantic.
2. **Install size is ~920 MB**, of which `onnxruntime-node` (536 MB),
   `onnxruntime-web` (92 MB) and `@huggingface` (49 MB) are unused by the memory
   subset.

This is a **deferral, not a rejection**. The decisive argument against adopting it now
is duplication, not size: the module would be running two systems that both own
persona, goals and recall, with no single authority. AgentOS keeps its place as the
leading candidate if native recall later shows a measured miss that a projection
cannot close. Revisit conditions are recorded below.

## Long-term memory and retrieval

The `LongTermMemory` contract exists and is bound. Its default is native, and a
selector reports an unrecognised driver and falls back rather than failing, so the
contract is genuinely swappable without a caller change.

Retrieval is staged so that the expensive option is only reached on evidence:

| Stage | Mechanism | Activation |
| --- | --- | --- |
| 0 | Native scoped facts: entity, topic, time and current-validity filtering, plus unresolved obligations | Shipped |
| 1 | Exact and structural filtering already covers the common case | Shipped |
| 2 | MySQL InnoDB `FULLTEXT` projection over module-owned fact text, behind `LongTermMemory` | Only if stage 1 leaves a measured miss |
| 3 | Semantic recall | Only with a caller, a measured miss and an approved experiment |

Stage 2 is deterministic and needs no model, which is why it precedes anything
vector-based.

### Embedding decision

Recorded because the plan previously selected nothing, which would have left this
implicit.

| | |
| --- | --- |
| **Chosen** | OpenAI embeddings behind the module's `Embedder` contract, pinned to a dated snapshot |
| **Dimensions** | 1536. **Storage form is still open:** the module runs on MySQL, which has no `halfvec`, so the pgvector sizing this record originally assumed does not apply. At 4 bytes per dimension a float32 vector costs roughly 614 MB at 100k memories; a packed float16 column (2 bytes per dimension, ~307 MB) or an external vector store are the realistic options. Not chosen yet, and nothing is stored until stage 3 has a caller. |
| **Accepted** | A network call on the recall path. A provider outage degrades recall to the lexical path; it never fails a request |
| **Deferred** | A local embedder, for simplicity |
| **Deferred, not dropped** | Arabic and mixed-language recall. The plan currently asks for English/Arabic/mixed measurement, so narrowing to English is a **scope reduction** and is recorded as deferred with its reason rather than quietly dropped |

The snapshot is pinned because an embedding model can change silently underneath a
stable name, and a changed model invalidates every stored vector. Changing the
snapshot is a migration, and vectors from different models must never be compared.

AgentOS would have required a custom embedder to avoid reaching a provider. Choosing
the provider directly removes that work and keeps the contract flexible enough to
swap later, which is the reason this choice does not lock the module to AgentOS.
