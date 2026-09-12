# Phase 3 driver decisions

Verdicts against the gates in [phase-3-driver-acceptance.md](phase-3-driver-acceptance.md).
Every claim below was reproduced against the pinned driver; nothing here is taken
from a product's wording.

| Driver | Contract | Gate 1 (conformance) | Standing |
| --- | --- | --- | --- |
| CBRKit `1.6.0` | `ExperienceEngine` | **Pass** | Enabled opt-in; native retained as fallback |
| FAtiMA/CiF affect | `AffectEngine` | **Pass** (after a vendored patch) | Adapter being wired |
| FAtiMA/CiF social | `SocialCognition` | **Pass** (after a vendored patch) | Stance mapping still unbound |
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
