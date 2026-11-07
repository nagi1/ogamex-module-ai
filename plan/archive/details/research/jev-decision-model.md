# Jev (TypeSafe) — the new "decision model", and how it fits Modules/AI

Research note, 29 September 2026. Written to be read by a human, in plain English.

Every claim below is either (a) read from TypeSafe's official documentation, (b) read from the Laravel
AI SDK v1.0 documentation or source, or (c) verified against this repository. Nothing here is inferred
from marketing text, and nothing here changes behaviour on its own. Sources are listed at the end.

Read with: [Laravel AI SDK integration](../specs/laravel-ai-sdk.md) (our rules for using the SDK),
[budgets](budgets.md) (the model-call policy), and
[laravel-ai-tools.md](laravel-ai-tools.md) (the same kind of study for SDK tools). For how Jev composes
with the cognition drivers we already run — FAtiMA/CiF, CBRKit, AgentOS and PsychSim — see
[jev-with-cognition-drivers.md](jev-with-cognition-drivers.md); for the whole-module inventory of
hand-made judgements, see [jev-opportunity-map.md](jev-opportunity-map.md).

---

## 1. The short answer

- **Jev is a different kind of model.** It does not write text. You hand it some material ("state") plus
  labelled questions, and it hands back numbers: a chosen option, a set of probabilities, and a
  confidence. TypeSafe calls this class of model a **System One model**.
- **Laravel AI SDK v1.0 (23 September 2026) added it as a first-class capability** called
  *Classification*, next to text, images, audio and embeddings. Under the hood it is a `typesafe`
  provider driver. OpenRouter also serves classification behind the same API.
- **We cannot use it today.** The host application pins `laravel/ai: ^0.11`, and our installed copy is
  **v0.11.2** — verified in `vendor/laravel/ai`: there is no `TypeSafeProvider` and no `Classification`
  class. Jev, and the whole Classification API, arrive in **v1.0**, which also carries breaking changes
  that touch our language gateway (see §6).
- **Where it is genuinely a good fit for us:** bounded judgment calls about text we already hold, where
  today we either use a hand-written keyword list or ask a generative model to interpret something, and
  where a *probability plus confidence, with an explicit "I don't know" path* is better than a guess.
  The strongest candidates are language interpretation on the authored-dialogue path, appraisal as an
  optional enrichment driver, and the strategy harness's own triage work.
- **Where it must not go:** choosing what to build (that needs the host object universe and arithmetic),
  counting, date comparisons, generating reply text, per-tick decisions, or any path where a paid call
  is not reserved and budgeted. All of those are documented failure modes of Jev itself, not opinions.

The one-line framing: **Jev's answer is evidence, never authority.** It can change which authored line
the module picks, or how heavy a nudge is; it can never make an action legal, grant a proposal, or skip
validation.

---

## 2. What Jev actually is

### 2.1 Three question types, answered in parallel

You define the answer space yourself. There is no free text, so there is nothing to parse.

| Type on the wire | Laravel class | Question | Answer |
| --- | --- | --- | --- |
| `noul` | `Laravel\Ai\Classification\Boolean` | Is this statement true? | `0.0`–`1.0`, the probability of "yes" |
| `choice` | `Laravel\Ai\Classification\Choice` | Pick one option from a list | the option, a probability per option, plus `confidence` |
| `score` | `Laravel\Ai\Classification\Score` | Rate against ordered levels you describe | a probability-weighted position that may land between levels, per-level probabilities, plus `confidence` |

All three can be mixed in **one request**. Each question is evaluated independently against the same
state, so adding questions barely changes the response time, and one question cannot poison another.
The answer under each key is exactly what you asked for, keyed by the same name.

Useful bounds from the API reference: a Choice takes at most **255 options**; a Score takes **2–10**
levels.

### 2.2 It is calibrated, which is the whole point

TypeSafe trains these models against real outcomes, so probabilities mean something across many answers:
a `0.9` should be right roughly nine times out of ten over a batch. That is what makes a threshold a
usable design tool instead of a magic number.

Two honest caveats from the docs, both worth repeating because they change how we would write code:

1. **Calibration is a batch property.** It does not guarantee that any individual answer is right.
2. **Noul answers carry no confidence.** Only Choice and Score do. For a yes/no question the number
   *is* the confidence.

### 2.3 What it costs, how fast it is, what it accepts

From TypeSafe's Models page (Jev 1.13, model id `jev-1.13.0`):

| Property | Value |
| --- | --- |
| Price | **$42 per billion** input tokens = **$0.042 per million**. Output tokens are **free**. |
| Rate limits | 250,000 tokens/second and 1,200 requests/minute; either one exceeded returns `429`. TypeSafe states these are "adjusting dynamically" and can change without notice. |
| Context | 64k tokens per request; at most 32k for the state *plus the longest question*. |
| Input | Text only — a string, a JSON object, or an array of text values. No images, audio or video. |
| Language | English is the primary training language and where accuracy is best. Other languages are accepted but weaker. |

Independently measured in production (Freek Murze, there-there.app, 18 September 2026): three questions
over one email took **639 ms**, ~**48 classifications per second** when run in parallel, and worked out
at about **four hundredths of a cent per email**. That is the kind of order of magnitude to expect: one
short HTTP round trip, not a long generation.

For our own arithmetic, using the budget numbers we already store:

- A 2,000-token state costs `2,000 × $0.042 / 1,000,000` = **$0.000084** (about 0.008 cents) — roughly
  **8,400 calls per dollar**.
- The same 2,000 tokens through `deepseek-flash` costs `2,000 × $0.15 / 1,000,000` = $0.0003 **plus**
  output at $0.60/million. So Jev is roughly 3.6× cheaper on input and free on output.
- Volume is not the binding constraint for us. 1,200 requests/minute is far above anything our session
  scheduler produces. Our own monthly dollar wall in `config/cognition.php`
  (`monthly_cost_usd`, default `$10`) and the "no paid call on the ordinary gameplay path" rule are the
  real limits.

### 2.4 Confidence: the "I don't know" signal, and how to use it

`confidence` is computed from how the probabilities are spread — all the weight on one option is `1.0`,
an even spread is `0.0`. TypeSafe's recommended shape is three bands, which maps cleanly onto policy we
already write by hand:

| Confidence | What the docs suggest | What that would mean for us |
| --- | --- | --- |
| High | Act automatically | take the classified interpretation / nudge |
| Medium | Proceed with caution | take it but flag it, or require a second signal (authored rule, exact terms) |
| Low | Do not act | fall back to the authored path: clarification, deferral, or silence |

The docs also warn that thresholds must scale with risk, and — importantly for us — that a threshold
tuned on one question should **not** be reused on another question type, because the numbers are not
comparable. Store the raw probability next to the boolean you derived from it, so a threshold change can
be replayed against history instead of guessed.

### 2.5 Documented failure modes (jev 1.13 "jaggedness")

TypeSafe publishes its own known weaknesses. These are the ones that matter to us, and the rule each one
implies:

| Failure mode | Meaning | The rule for us |
| --- | --- | --- |
| Literal reading | It answers the words you wrote, not the intent behind them | Put the exact condition and the boundary cases into `instructions`/`criteria`; split anything ambiguous into two literal questions |
| Counting | It does not count reliably (items, occurrences, characters) | Count in code. Ask one question per item only if a judgment per item is genuinely needed |
| Numbers and math | Poor at numeric work, weaker on hex/binary than on names | Do the conversion in code and pass the computed value or a named bucket |
| Dates and times | Reads dates as text, not as ordered quantities | Extract the parts, assemble and compare in code |
| Indirection | Double negatives and multi-hop reasoning lose accuracy | Ask one direct question; name the state fields it must use |
| Large, noisy state | Unrelated material is a distractor and lowers accuracy ("context rot") | Filter first: send only the fields this question needs |
| Adversarial content | Text written to steer the answer can move it | Our state includes player-authored chat: never let the answer authorise anything, and never rely on it to detect manipulation of itself |
| Contradictory instructions | Instruction and criteria disagreeing confuses it | Align the two; keep the "true"/"false" descriptions reading the natural way round |
| No structural invariants | Asking the same thing as a Noul and as a Choice can disagree; `P(x) + P(not x) ≠ 1` | Never hold it to arithmetic identities between separate questions |
| No generation | It cannot write text | Never ask it to phrase a reply |

Two more properties worth knowing: Jev is **not fine-tuned or LoRA-adapted per customer** — every account
uses the same weights, and you steer it through state, instructions and criteria — and it is **not
trained on customer requests or responses**.

---

## 3. What the Laravel AI SDK adds on top

The SDK wraps the whole thing in Laravel idioms, which matters because it keeps our existing test and
budget discipline usable.

```php
use Laravel\Ai\Classification;
use Laravel\Ai\Classification\Boolean;
use Laravel\Ai\Classification\Choice;

$result = Classification::of([
    'message' => $inbound->body,
    'terms'   => $existingCommitment,
])
    ->questions([
        'wants_terms'   => new Boolean('Does this message ask to change an existing agreement?'),
        'intent'        => new Choice('Which of these best matches what the sender wants?', [
            'greeting'   => 'A greeting or small talk, nothing to transact',
            'resource'   => 'A request for resources',
            'commitment' => 'An offer or acceptance of a promise',
            'probe'      => 'A question about my fleet, planets or activity',
        ]),
    ])
    ->timeout(5)
    ->classify();
```

- **Answer access:** `$result['wants_terms']->probability`, `->isTrue(0.8)`;
  `$result['intent']->choice`, `->probabilityOf('resource')`, `->probabilities`, `->confidence`;
  a Score adds `->score`, `->level()`, `->label()`, `->normalized()`.
- **One-liners:** `Str::of($message)->decide('Is this spam?', criteria: [...], threshold: 0.9)` returns a
  boolean, and `$collection->decide('Which category?', $text)` picks one item out of your own list.
- **Configured like any provider:** a `typesafe` entry in the host `config/ai.php` with a
  `TYPESAFE_API_KEY`, plus `default_for_classification`, and the model defaults to `jev-latest`. A
  provider `url` in that config is honoured by the gateway, so a proxy or gateway in front of TypeSafe is
  possible even though TypeSafe is not in the docs' named "custom base URL" list.
- **Failover is built into the call.** `classify()` walks a provider list (a string, an array, or an
  associative provider ⇒ model map) and only fails over on *failoverable* errors — rate limit,
  overloaded, insufficient credits — not on ordinary validation errors. The gateway treats
  `529, 502, 503, 504, 520, 522, 524` as "overloaded".
- **Usage is reported** as input/output tokens (the text-usage object also carries cache and reasoning
  counts).
- **Testing is first class:** `Classification::fake([...])`, `assertClassified`, `assertNotClassified`,
  `assertNothingClassified`, with typed fake answer objects. The gateway uses Laravel's HTTP client, so
  the `Http::fake` fixture-replay discipline we already use for DeepSeek works here too.
- **Events:** `Classifying`, `Classified`, and `ProviderFailedOver` — enough to enrich the same receipt
  record we already keep for language.
- **Status: experimental.** The docs say Classification "is currently experimental and its API may change
  in future minor releases of the AI SDK". That is a real stability caveat for anything we put on the
  gameplay path.

---

## 4. What we already have (verified)

| Piece | Where | Why it matters for Jev |
| --- | --- | --- |
| SDK pin | Host `composer.json`: `laravel/ai: ^0.11`; installed **v0.11.2** | No Jev, no Classification. Upgrade required first |
| Language lane | `Contracts/LanguageGateway.php`, `Infrastructure/Language/LaravelAiLanguageGateway.php`, `NullLanguageGateway` | This is the established "real driver + null driver + explicit binding" pattern a classification seam would mirror |
| Provider ladders | `Domain/Language/AiProviderLadder.php`, `config/routing.php`, `AiSettings` | Per-task ordered rungs, peak/off-peak gating, a rung whose credential is missing is dropped before the call |
| Lane caps | `config/language.php`, `config/campaign-consultation.php` | Timeouts, context characters, max in/out tokens, per-universe/player/conversation daily limits |
| The dollar wall | `config/cognition.php` `monthly_cost_usd`, reservation + settlement actions | Any paid call — Jev included — should reserve before dispatch and settle once |
| Cognition drivers | `AffectEngineSelector`, `ExperienceEngineSelector`, `SocialCognitionSelector`, `NativeAffectEngine`, `NativeExperienceEngine`, `NativeSocialCognition`, sidecars behind `AiSettings` | The "native is the floor, external driver contributes, outage degrades per call" pattern that an optional Jev enrichment would follow |
| Appraisal | `Domain/Perception/PlayerObservationService.php`, `Domain/Cognition/NativeAffectEngine.php`, the `affect` block in `config/cognition.php` | The clearest *judgment* we currently hand-code; see §5(b) |
| Behaviour data | `resources/behavior/defence-doctrines.yaml` and friends | Where taste lives today, with every number cited to a source id. Jev answers would have to live *on top of* this, not replace it |
| Harness | `scripts/strategy-pipeline.py`, `verify-cohorts.php`, `harness-live.sh` | Pure Python, dev-side, never ships — see §5(c) |

Two existing rules constrain any proposal here, and both are worth quoting because they are the reason
the shortlist in §5 is short:

- From `budgets.md`: "Normal decisions, scheduling, recovery, game-event reducers, native memory,
  appraisal, authored social exchanges and structured CBR: **0 generative calls**."
- From `budgets.md`: "Generate text and any fact/intent/commitment proposals together. **No separate
  classifier, extractor, realization, judge or repair-model chain for the same reply.**"

A Jev call is a paid provider call, so the first rule means it belongs to the same budgeted, opt-in
world as the language lane — not to the ordinary gameplay loop. The second means a Jev call may **replace**
an interpretive step, but must not be bolted on as an extra call beside the existing reply request.

---

## 5. Where it fits, and where it does not

### Fits — ranked by evidence, best first

**(a) Interpretation of an inbound message, on the authored path.**
Today the module recognises social exchanges structurally, uses authored lines for anything it is sure
about, and only when language is enabled does a generative call emit an `interpretation` alongside the
reply. The doctrine is explicit that ambiguous sarcasm, names, quantities and conditions "must not be
confidently guessed from a keyword". This is exactly the problem Jev exists for: a `Choice` over the
handful of intents the module already knows how to handle, or a `Noul` per intent, returning a
calibrated number with a confidence that lets us *abstain into the authored path* when the model is
unsure. Freek's production write-up describes replacing precisely a hand-maintained, multilingual
keyword list this way.

The constraint that makes this legal under our rules: it must **replace** the interpretive part of one
reserved request, or stand in for a reply the module can already author. It must never become a second
billed call in the same reply, and it never decides the action — the module still maps intent to policy.

**(b) Appraisal enrichment, as an opt-in driver.**
`PlayerObservationService` decides things like whether an account "came off worse" in an exchange, using
explicit thresholds; the affect engine then turns that into an emotional episode. That judgment is a
System One question — "did this account come out of this worse than the other side?" as a `Score` or
`Noul`, over a prepared summary. It would be a **hybrid-mode enrichment** in the same shape as the FAtiMA
driver: native stays the floor, an absent or failing Jev degrades to native per call, and the affect
weight stays bounded by the existing `ai.cognition.affect.decision_weight`.

Two honest caveats: this is not free (a call per appraisal), and we have no measurement showing the
native thresholds are wrong. Under our own gate 2 rules ("no optimisation without a measurement") this
should wait until there is an observed defect to point at — which is how the planet-selection and wall
defects were justified, not by taste.

**(c) The harness's own triage — the cheapest place to prove value.**
The strategy pipeline is dev tooling that never ships: it fetches OGame sources, promotes plans,
implements them, and verifies cohorts. It has text-heavy classification work where a calibrated number
would help and no gameplay risk exists:

- Bucket a newly ingested source into the corpus vocabulary it already uses (`claim_type` like
  `DOCUMENTED` / `CONTESTED` / `INFERENCE`, doctrine family), which today is a human/LLM judgement.
- Ask a gate-1 question about a generated plan: does this proposal name object ids, prices or
  requirements instead of taste? (`Noul`, with the criteria written to say exactly what counts.)
- Group failing implementer attempts into failure classes so the harness can see whether a *third*
  systematic failure mode exists.

This path needs no module seam and no Laravel code — it is a `POST /v1/systemone` from Python. If the
answers are good, that is the evidence that justifies touching the game path; if they are not, we have
spent a few cents learning it.

### Does not fit — and why

| Tempting use | Why not |
| --- | --- |
| Choosing what to build next (`Domain/Decision/DecisionEngine.php`, `UtilityScorer`, the planners) | Gate 1: the object universe, its kinds, prices and requirements come from the host, and the choice is arithmetic over scored candidates. Jev cannot count, cannot do maths, and must never be a source of truth. Its own docs say: never ask it something code can compute exactly |
| Any legality, price, requirement, or "is this stale" check | Same reason, plus the module is the authority. These stay deterministic |
| Writing the reply text | It does not generate text. That is a documented non-capability, not a tuning problem |
| A per-tick or per-session decision call | "0 generative calls" for ordinary gameplay; a paid call per session across two cohorts is a budget and authenticity question, not just a cost one |
| Detecting manipulation of itself in player-authored chat | Documented failure mode: adversarial content in the state can move the answer. Authorisation stays with the module's own permissions and validation |
| A second "judge" call beside the existing reply request | Forbidden by the budgets model-call policy; it would also double the provider attempts we must reserve and reconcile |
| Replacing `resources/behavior/defence-doctrines.yaml` | That data is source-cited taste. Jev may express a *judgment about* a shortlist the code already produced; it may never become where doctrine lives |

---

## 6. What adopting it would take (the smallest version)

**Step 0 — the prerequisite, which is real work.** Upgrade the host to `laravel/ai: ^1.0`. In v1.0 the
usage object renamed `promptTokens` / `completionTokens` to `inputTokens` / `outputTokens`, which our
`LaravelAiLanguageGateway` currently reads (verified in the installed v0.11.2 source:
`src/Responses/Data/Usage.php` still uses `promptTokens`). v1.0 also changes conversation storage, agent
middleware timing, and stream protocols. We deliberately do not use the SDK's conversation store, tools,
streaming or middleware in the reply agent, so most of that list does not touch us — but the upgrade,
the published backfill migration and the fixture tests (`LaravelAiHttpFixtureTest`,
`LaravelAiLanguageTest`) need to be run and kept green before Jev is even available.

**Step 1 — config, in the two places we already put config.**
Host `config/ai.php`: a `typesafe` provider with `TYPESAFE_API_KEY` from the host `.env` (secrets never
live in the module). Module `ai-settings.yaml` (the `AiSettings` schema): a `classification` block — off
by default, pinned model, short timeout, a state character cap, daily limits, and a threshold per
question — plus one row in the pricing table so the ledger costs it (`typesafe.jev-1.13.0`: input
`0.042`, output `0`).

**Step 2 — one seam, three files.** Mirror what the language lane already does, and nothing more:
a `ClassificationGateway` contract, a null implementation that returns "disabled" without touching a
provider, and the SDK-backed implementation that builds the state, maps answers into module types, and
classifies transport versus schema failure. Bind it in `AIServiceProvider`. No registry, no manager, no
per-lane gateway. Gate 2 says: build the seam when the first consumer exists, not in advance.

**Step 3 — budget and evidence discipline, unchanged from language.** Reserve the state's maximum input
cost before dispatch, settle actual usage once, count uncertain attempts, keep the monthly wall. Store
the raw probability next to the derived boolean. Log provider, model, latency and answer — never the
player's private text by default.

**Step 4 — tests.** `Classification::fake()` for behaviour and thresholds, one `Http::fake` fixture
replaying the real `/v1/systemone` wire shape (the same S7 discipline we use for DeepSeek), one opt-in
sanitised real-provider conformance run, and a provider-off test proving the authored path still answers.

**Step 5 (optional, cheapest) — the harness.** A `judge` command in `strategy-pipeline.py` posting
directly to `/v1/systemone`, with the same peak-gate and no new dependency: this is Python stdlib HTTP,
not Laravel.

**What to pin.** `jev-1.13.0`, not `jev-latest`. The docs are explicit that an alias moves when a new
release ships, so answers behind it change without a change on our side — and that if thresholds were
tuned against a version, the version should be pinned and moved on our own schedule. Reproducibility of
behaviour is worth more to us than automatically getting a better model.

---

## 7. Risks

| Risk | Why it matters | Mitigation that already exists in the module |
| --- | --- | --- |
| Classification API is experimental | A minor SDK release may change it | Keep it behind our own contract, so the SDK shape is one class's problem |
| Alias drift changes answers under us | A "0.9" tuned last month is not the same 0.9 today | Pin the version; store raw probabilities so thresholds can be replayed |
| TypeSafe rate limits are explicitly dynamic, and pricing/limits can change without notice | A previously cheap call may stop being cheap or available | Ladder + failover (429/529 are failoverable), budget reservation, monthly wall, provider-off fallback |
| English-first accuracy | Our universes have non-English chat | Confidence gating with the authored fallback; never let a single answer authorise anything |
| Adversarial player text | A player can write text designed to steer the judgment | It can only propose; permissions, legality and validation stay in module code |
| Cheapness inviting new paid calls on the ordinary path | "It's only 0.008 cents" is how a zero-call architecture quietly stops being one | The rule stays the rule: ordinary gameplay makes zero paid calls. Jev goes where a paid call is already allowed, replacing something, or into dev tooling |

---

## 8. Decisions I need from you

1. **Scope.** Keep Jev to (a) the language lane's interpretation and (b) harness/dev triage — or accept a
   new paid call on the appraisal path as an opt-in enrichment driver? My recommendation: start with
   (a) and (c), because (b) has no measured defect behind it yet.
2. **The SDK upgrade.** `^1.0` is required for Jev at all, and it brings breaking changes to our language
   gateway. Do it now as its own slice, or keep v0.11.2 and reach TypeSafe with raw HTTP from the harness
   first? My recommendation: harness first — it proves value with none of the upgrade risk, and the SDK
   upgrade then becomes its own reviewed change.
3. **Model pinning.** `jev-1.13.0` (my recommendation) or `jev-latest`.
4. **Where the answers may act.** I would hold the line that a classification answer selects among
   options the module already authored, and never authorises a host action or a proposal on its own.
   Confirm.
5. **Harness integration surface.** Is a raw-HTTP TypeSafe call in `strategy-pipeline.py` acceptable, or
   do we keep "provider calls go through the Laravel AI SDK" absolute even for dev tooling?

---

## Sources

- TypeSafe documentation: [Introduction](https://docs.typesafe.ai/introduction), [System One](https://docs.typesafe.ai/concepts/system-one),
  [State](https://docs.typesafe.ai/concepts/state), [Primitives](https://docs.typesafe.ai/primitives),
  [Confidence](https://docs.typesafe.ai/confidence), [Models](https://docs.typesafe.ai/models),
  [API reference](https://docs.typesafe.ai/api), [Jev 1.13 jaggedness](https://docs.typesafe.ai/model-jaggedness/jev-1.13),
  [documentation index](https://docs.typesafe.ai/llms.txt). Fetched 29 September 2026.
- Laravel: [Introducing Laravel AI SDK v1](https://laravel.com/blog/introducing-laravel-ai-sdk-v1),
  [AI SDK documentation](https://laravel.com/docs/ai-sdk) (§ Classification, Provider Support, Failover,
  Testing, Usage, Events), and the `1.x` source: `config/ai.php`, `src/Enums/Lab.php`,
  `src/Classification.php`, `src/PendingResponses/PendingClassification.php`,
  `src/Providers/TypeSafeProvider.php`, `src/Gateway/TypeSafeGateway.php`. Fetched 29 September 2026.
- Laravel News: [Laravel AI SDK 1.0 Adds Classification and Tool Approvals](https://laravel-news.com/laravel-ai-sdk-1-0)
  (23 September 2026).
- Freek Murze: [Detecting spam and auto-replies with Jev and the Laravel AI SDK](https://freek.dev/3194-detecting-spam-and-auto-replies-with-jev-and-the-laravel-ai-sdk)
  (18 September 2026) — the production latency, throughput and cost figures.
- Repository: verified in `vendor/laravel/ai` (host) that the installed SDK is **v0.11.2** with no
  `TypeSafeProvider` and no `Classification`, and that its `Usage` value object still exposes
  `promptTokens`; read `Modules/AI/plan/details/specs/laravel-ai-sdk.md`,
  `plan/details/specs/budgets.md`, `plan/details/specs/llm-full-utilisation.md`,
  `plan/details/research/laravel-ai-tools.md`, `config/cognition.php`, `config/language.php`,
  `config/routing.php`, `app/Support/AiSettings.php`, `app/Infrastructure/Language/LaravelAiLanguageGateway.php`,
  `resources/behavior/defence-doctrines.yaml`.
