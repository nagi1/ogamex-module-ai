# Hosted classification (TypeSafe Jev) — side work package

**Status: studied, gated, not adopted.** Research 29–30 September 2026. Queued as `REV-8` and
`JEV-1…JEV-7` in the task DB. Nothing in this package runs today, and nothing here changes gameplay
until `REV-8` is decided.

**Owner direction:** none yet. This document records the boundary and the slices so that adoption is a
decision rather than a discovery exercise; the decision itself is `REV-8`.

**Read first:** [what Jev is, what it costs, what it cannot do](../research/jev-decision-model.md) ·
[how it composes with the cognition drivers we run](../research/jev-with-cognition-drivers.md) ·
[every hand-made judgement it could serve, and the ones it must not](../research/jev-opportunity-map.md).

## What it is, in one paragraph

Jev (TypeSafe) is a *System One* model: it does not write text. You hand it a bounded state plus typed
questions — pick one option, yes/no, or rate against levels you describe — and it returns the answer
with a probability distribution and, for Choice and Score, a confidence. Laravel AI SDK v1.0 exposes it
as the `typesafe` driver behind a *Classification* capability. It is charged on input only: $0.042 per
million tokens, output free, so a call costs whatever its state costs and nothing more.

Its value to this module is narrow and specific: it replaces a **reading of a situation** that is
currently a regex, a cutoff or a non-empty test, and it gives that reading a number the module can
threshold — plus an explicit way to abstain when it is unsure.

## Prerequisites — both must be done before any slice here runs

1. **`REV-8`** — owner approval for paid hosted classification on a **named** lane; `TYPESAFE_API_KEY`
   in the host `.env` with a `typesafe` provider entry in the host `config/ai.php`; the model pinned to
   `jev-1.13.0` rather than the moving `jev-latest` alias; and a sub-budget inside
   `ai.cognition.monthly_cost_usd`. It also requires the amendment to the model-call policy in
   [budgets](budgets.md), because an enabled lane can touch ordinary play — which is otherwise
   guaranteed **zero** generative calls.
2. **`IMPL-65`** — `laravel/ai` `^1.0` in the host. The pinned `v0.11.2` ships neither a
   `TypeSafeProvider` nor a `Classification` class, and v1.0 renames the usage fields our language
   gateway reads.

## Gates applied to every slice

- **Gate 1** — the state is read from the host or from module records at call time and is never
  encoded; a question names a *kind* ("an apology that offers a named resource and amount"), never an
  object id, machine name, price or requirement.
- **Gate 2** — one seam: the `ClassificationGateway` contract, its null implementation and the SDK
  implementation. No registry, no manager, no per-lane gateway. An answer must **replace** an existing
  heuristic or have exactly one named consumer; a question without a consumer is deleted, not kept.
- **Gate 3** — every question must be nameable as something an experienced player perceives: "is this
  an apology", "did I come off worse", "did he say he was leaving", "is this pitch real".
- **The model's own rule, adopted** — the answer is **evidence, never authority**. It may select among
  options the module authored. It may never authorise an action, grant a proposal, satisfy a validation
  boundary, or be the reason a capability becomes reachable.

## Slices

| Slice | Task | What lands | Acceptance |
| --- | --- | --- | --- |
| J1 | `JEV-1` | The offline reading run: recorded messages and engagements replayed through `POST /v1/systemone`, compared against the incumbent rules *and* against their `null`s | agreement, coverage gain, confidence-versus-correctness, p50/p95 latency and settled cost, against the pass bar in the [opportunity map](../research/jev-opportunity-map.md#5-the-measurement-that-settles-family-1) |
| J2 | `JEV-2` | `ClassificationGateway` (null + SDK), the `ai-settings.yaml` / `config/cognition.php` block, the `config/pricing.php` row, reservation and settlement through the existing ledger | provider-off returns a typed disabled result without reading provider config; one reserved attempt settled once; the raw probability stored beside the derived boolean |
| J3 | `JEV-3` | The exchange cascade: classification only where `ClassifyInboundSocialExchangeAction` returns `null` | a model answer can never invent a resource or an amount; low confidence, provider-off and budget exhaustion are byte-for-byte today's silence |
| J4 | `JEV-4` | Availability/absence declarations read from chat and stored as an expiring fact | raid behaviour identical with the provider off; one stored answer per message; raw probability retained; **needs `REV-8`'s explicit nod** — it is a new behavioural input |
| J5 | `JEV-5` | The alliance pitch/application read, replacing `trim(…) !== '' ? 1.0 : 0.0` | the non-empty test is gone — replaced or deleted — and the decision is explainable in one sentence |
| J6 | `JEV-6` | Appraisal variables for the affect driver, mapped onto the signed OCC beliefs | the driver still computes emotion intensity and mood; `mood`, `driverEmotion` and `driverIntensity` stay the driver's; blocked by `DEF-34` |
| J7 | `JEV-7` | The harness provenance check: does the cited source support this constant, and is the claim documented or contested | a reviewable report over `resources/behavior`; it never edits that data and never becomes a value |

## Do not build

A cascade or router framework; a second gateway per lane; SDK tools, MCP, sub-agents, streaming or
human approval; a classification call on the memory **write** path — authored rules own importance, and
paying a model per write is the mistake we rejected when we refused the memory products; a model
anywhere in `UtilityScorer`'s weights, because that is a **calibration** problem for recorded outcomes
and not a classification one ([opportunity map §4](../research/jev-opportunity-map.md#4-family-3--calibration-is-not-a-model-problem));
a third similarity or memory-ranking authority beside CBRKit and AgentOS; per-tick invocation; or prose
parsing where structured data would do — `DEF-37` fixes that with a schema, not a model.

## Reference profile and cost

A hosted call adds no resident memory to the 2 vCPU / 2 GB reference profile, so the constraint is the
API bill, not RSS. A ~500-token state costs about two hundred-thousandths of a cent. The reason to keep
this package narrow is therefore **behavioural and doctrinal** — "zero paid calls for ordinary
gameplay" is a decision the owner makes deliberately, not an accident that erodes because a call looks
cheap — not the size of the bill.
