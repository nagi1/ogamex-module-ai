# Phase 3 driver decisions

Verdicts against the gates in [phase-3-driver-acceptance.md](phase-3-driver-acceptance.md).
Every claim below was reproduced against the pinned driver; nothing here is taken
from a product's wording.

| Driver | Contract | Gate 1 (conformance) | Standing |
| --- | --- | --- | --- |
| CBRKit `1.6.0` | `ExperienceEngine` | **Pass** | Wired opt-in; native retained as fallback |
| FAtiMA/CiF affect | `AffectEngine` | **Pass** (after a vendored patch) | Wired opt-in; native retained as fallback |
| FAtiMA/CiF social | `SocialCognition` | **Pass** (after a vendored patch) | Wired opt-in; native retained as fallback |
| AgentOS `0.10.16` | `LongTermMemory` | **Pass** | Wired opt-in over HTTP; native retained as fallback |

Gate 1 is satisfied for all three evaluated drivers. Gate 2 (demonstrated value) is
**not** met for any of them, so every driver remains opt-in and disabled by default,
and the native path stays the shipped behavior.

**Global gates A1–A8, for the evaluated drivers:** A1 by `DriverAbsenceTest`, which shows
every optional contract resolving to its native implementation with nothing configured and no
request attempted; A2 and A5 by `DriverSwapAuthorityTest`, which shows a swapped driver changing
its answer while changing no module record and refusing to surface a case the module never sent;
A3 by the pinned images, which carry no provider or embedding library; A4 by the module-owned
response bound, the casebase bound and their oversized-payload tests; A6 by the module
re-applying its own ordering after the driver answers; A7 by the measured run recorded below;
A8 by the 100% module PCOV gate running with every driver absent. A2 has a second, stronger
form: because Appraisal writes nothing, the whole `ai_` table set is compared before and after
— it is not merely the four named families that stay untouched.

### AgentOS — `LongTermMemory` (14 September 2026)

The driver is no longer deferred. It is served as a sidecar in the same shape as CBRKit and
reached over the module's own HTTP contract:

| Route | Request | Answer |
| --- | --- | --- |
| `POST /recall` | `{scopeId, query, limit, memories:[{id,text,tags}]}` | `{ranking:[{id, …numeric evidence}], considered, discarded, partiallyRetrieved}` |
| `GET /health` | — | `{status}` |

**It stores nothing.** Each request carries the memories the module authorised for that recall,
so A2 holds by construction rather than by assertion: there is no second copy of a memory to
keep in step, and swapping the driver back cannot strand state in the sidecar.

| Gate | Evidence |
| --- | --- |
| A1 absence is free | `DriverAbsenceTest` and `LongTermMemoryDriverTest` resolve the native implementation with the sidecar absent, and a recall with the driver selected makes no request at all when the candidate set is empty |
| A2 one authority | `ExportDeleteAcceptanceTest` compares the whole canonical table set before and after a driver swap and after a failed driver call; both are identical, while the returned *order* changes |
| A3 zero generative calls | the image installs no provider SDK and no model runtime; the embedder is the module's own `local-embedding-manager.mjs`; the A7 run recorded zero outbound requests beyond the driver itself |
| A4 bounded | module-owned connect/read timeouts, the shared response-byte cap, the candidate cap and the circuit breaker, each with its own test |
| A5 no truth widening | `AgentOsClient` discards any answer naming an id the module never sent, or repeating one |
| A6 module determinism | the module ranks only by the driver's returned order and keeps its own scope, validity, redaction and limit |
| A7 measured | below |
| A8 coverage | 100% module PCOV with the driver absent; the real sidecar is an opt-in operator run |

Measured on 14 September 2026 against the built image:

| Observation | Value |
| --- | --- |
| Resident memory, idle | **113.9 MiB** (CBRKit 142.1 MiB, FAtiMA 108.9 MiB) |
| Image size | **1.69 GB** (FAtiMA 295 MB, CBRKit 878 MB) |
| Attack memory ranked first for an attack query | yes, with `considered: 3` |
| Empty candidate set | `{ranking:[], considered:0}` and no graph work |
| Malformed JSON / unknown route / health | `400` / `404` / `{"status":"ok"}` |

**Gate 2 is unmet, measured (15 September 2026).** The approval test is
`scripts/e2e-agentos-recall-benchmark.php`, an operator run over the real database, the real sidecar
and the real decision action (`php scripts/e2e-agentos-recall-benchmark.php --confirm --trials=30 --facts=30`
inside the application container; it writes only rows it then deletes). Each trial gives the
counterparty 30 facts and moves the debt fact one recency position, so 30 trials walk the whole
corpus:

| Observation | Native | AgentOS | Same ranking, promotion bounded to the native cut |
| --- | --- | --- | --- |
| Required-fact (live `ResourceDebt`) recall at the module's 20-fact cut | **66.7 %** (20/30) | **100 %** (30/30) | **66.7 %** (20/30) |
| Newest fact about the counterparty kept in the cut | **30/30** | **1/30** | **30/30** |
| Order differs from native | — | 30/30 | 30/30 |
| Scope leaks (an id the module never sent) | 0 | 0 | 0 |
| Recall latency p50 | 88.4 ms | 223.4 ms | n/a |
| `HelpRequest` answer, production amount | `Counter: insufficient_available_amount` | identical, 30/30 | identical |
| `HelpRequest` answer with a caller-supplied query text | `Clarify` | `Reject`, 10/30 differ | identical to native |

**The gain and the eviction are the same act.** Replaying the driver's own real ranking with the
promotion bounded — the ranked memories move to the front of the *same* cut instead of ahead of the
native floor — returns native's 66.7 % and keeps the newest fact 30/30. So the driver does not widen
what the consumer can see; it decides which 20 of 30 facts survive a fixed budget, and the +33.3 pp
is what it buys by dropping the newest facts. Value here is *substitution*, not addition, which is
why the "no worsened current-fact correctness" clause is not a caveat beside the gain but its price.

**The value is also confined to the truncating regime.** Below 20 live facts the native recall
returns everything, so ordering cannot change what the consumer sees — and the module's own fact
universe is two predicates, so a counterparty only exceeds the cut after a long relationship (live
`ResourceDebt` facts never expire and dedupe per source observation, so they accumulate). The
driver's ordering is worth something only to a consumer that needs *relevance* rather than presence,
and the module has one consumer, which tests presence.

The numeric target is met and the verdict is still **disabled**, for three recorded reasons:

1. **No production-reachable consumer.** The only reader of a recall is
   `NativeSocialCognition::outstandingDebtPenalty()` (a presence test over the recalled list), and
   `RunAiConversationCycleAction::respond()` calls the evaluator with `availableAmount = 0` because
   no transfer capability is wired. `evaluateHelpRequest()` therefore answers `Counter` for every
   real help request *before* the debt penalty is read. Measured: 30/30 identical answers under both
   drivers.
2. **The caller sends no query text.** `EvaluateAiSocialExchangeAction::recalledHistory()` passes
   `queryText: null`, and both `AgentOsLongTermMemory` and `AgentOsClient` return early on an empty
   query, so the production path never contacts the sidecar. Measured: the unwired order equals the
   native order 30/30, i.e. the driver is not consulted at all.
3. **Where it is consulted, it trades current facts for relevant ones.** The driver returns a full
   `topK`, and `AgentOsLongTermMemory::ranked()` places everything it ranked ahead of the native
   floor, so the driver replaces the whole 20-fact cut rather than reordering it: the newest fact
   survives 1/30 against 30/30 natively. That is the "no worsened current-fact correctness" clause
   failing in the same run that shows the +33.3 pp recall gain. Reordering rather than replacing the
   cut is module policy in `ranked()`, not a driver defect.

Revisit when a production-reachable, order-sensitive consumer of recalled memory exists *and*
promotion is bounded so a ranking cannot evict current facts. Until then the driver stays opt-in and
disabled, and the reference profile pays nothing for it.

**Deletion is complete by construction.** Because the module sends the candidate set per request
and the sidecar persists nothing, there is no provider index to tombstone or delete: the driver's
soft-delete-only limitation stops being a limitation, and `ExportDeleteAcceptanceTest` proves a
deleted source produces no driver request at all.

**Standing rule for all driver work:** integration and swap evidence only. Do not write PHP
that duplicates a capability a supported driver already provides, and do not add a parallel
native implementation to compare against one. See
[no duplicated driver capability](../specs/phase-3-cognition.md#no-duplicated-driver-capability).

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

**Consequence of the standing rule.** The pinned retriever mirrors the module's own
similarity formula in Python, which is the duplication the rule now forbids and is also
the reason equivalence is the only result. Any further CBRKit work must let the driver use
its own measures — per-feature weighted similarity, `cbrkit.eval` over held-out real
outcomes — instead of reproducing the module's arithmetic. If it cannot show a gain the
module's uniform mean cannot express, it keeps earning a network hop for nothing and stays
disabled. No native reimplementation is to be written to close that gap either.

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

### Measured adapter cost (13 September 2026)

`php artisan ai:cognition-conformance --confirm --iterations=120` against the running pinned
sidecars, module enabled, both drivers selected. The command installs Laravel's
`globalRequestMiddleware`/`globalResponseMiddleware`, so these are the module's own counts of
its own traffic rather than anything a driver reports about itself.

| | CBRKit 1.6.0 → `ExperienceEngine` | FAtiMA/CiF → `AffectEngine` |
| --- | --- | --- |
| Calls measured | 120 | 120 |
| HTTP requests | 120 (1 per call) | 600 (5 per call) |
| p50 / p95 latency | 176 ms / 183 ms | 404 ms / 412 ms |
| Latency range | 175–184 ms | 395–455 ms |
| Request bytes | 281 B per call | ~25.7 KB per call |
| Response bytes | ~2.5 KB per call | ~296 B per call |
| Conformance | ranking identical to native | `Anger` at the driver's clamp, distinct from the native `0.08` |
| Idle / under-load CPU | ~0.1% / 1.2–1.4% (25% peak) | 0% / 8–15% |
| Steady memory | ~142 MiB | ~108 MiB |

CPU and memory come from `docker stats --no-stream` sampled on the host during a longer run,
because the application container cannot read the sidecar's cgroup.

Two things this measurement makes plain that no amount of documentation could establish:

1. **FAtiMA's cost is the scenario re-send.** Five round trips per appraisal, of which ~25.6 KB
   is the authored scenario re-posted every time. Reloading the scenario is exactly what makes
   the driver deterministic — it discards accumulated mood — so the cost is the mechanism
   rather than a defect, but it is a real ~400 ms on the appraisal path.
2. **A native fallback is invisible in the answer.** CBRKit's ranking is identical to the native
   one whether it answered or degraded. FAtiMA is worse: the module's own engine answers the
   fixture stimulus with the *same emotion*, so the pre-fix run also returned `Anger` — at the
   native `0.08`. A run is therefore only credited when the request count reaches the iteration
   count **and** the observed figure differs from the native engine's, which is the only way an
   operator can tell a driver answer from a silent fallback.

Observed failure modes, re-measured rather than restated: CBRKit answers **500** for a casebase
whose values are not objects. FAtiMA answers **200** for an emotions read taken before any
perception and returns an empty pool, so a status code carries no meaning for that driver —
which is why its adapter inspects the payload shape instead.

### Defect found while measuring: the scenario path never resolved

`config('ai.cognition.fatima.scenario_path', $default)` did not do what it appeared to do.
Laravel's `Arr::get` returns a stored `null` and never falls back to the default argument, and
`config/cognition.php` set that key from a bare `env('AI_COGNITION_FATIMA_SCENARIO_PATH')`.
With the module enabled the key exists holding `null`, the module-relative default never
applied, and the fixture path collapsed to `/ogame-cognition.json`.

`FatimaScenarioTemplate` then threw, `FatimaCognitionSession` degraded to the native engine, and
**every** appraisal returned a native result. The driver was unreachable in an enabled
installation while appearing correctly bound and configured.

The module's own suite could not see this. With the module disabled the key is absent, so
`config()`'s default argument *does* apply and the tests pass against a path that production
never uses. It surfaced only because the measured run reported `intensity = 0.08` — the native
value — alongside `http_calls = 0`.

Fixed by treating an unset or blank setting as the module's own fixture inside
`FatimaScenarioTemplate`, which keeps one authority for that default, with tests for the unset,
blank and overridden cases.

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
| **Excluded** | A local embedder. The owner's standing rule is hosted providers with no local model runtime, so this is ruled out rather than deferred, and the 2 vCPU / 2 GB reference profile is why the rule exists. |
| **Out of scope by decision** | Arabic and mixed-language recall. The owner chose English-only on 14 September 2026, so this is a decision rather than a silent narrowing, and reinstating it is a feature with its own content and tests rather than a configuration value |

The snapshot is pinned because an embedding model can change silently underneath a
stable name, and a changed model invalidates every stored vector. Changing the
snapshot is a migration, and vectors from different models must never be compared.

AgentOS would have required a custom embedder to avoid reaching a provider. Choosing
the provider directly removes that work and keeps the contract flexible enough to
swap later, which is the reason this choice does not lock the module to AgentOS.
