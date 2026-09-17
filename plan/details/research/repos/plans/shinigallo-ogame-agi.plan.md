# Enhancement plan — Shinigallo/ogame-agi

## Verdict summary (one line)

Mostly REFUSE — a Playwright-scraping demo whose hardcoded objects, fake "live" output and broken
seams violate all three gates — with one small ENHANCE (a confidence gate on the consultation nudge)
and one narrow ADOPT-IDEA (host-read game-phase context).

## ADOPT-IDEA

| Mechanism | What it does | Where in our module | Why worth it |
|---|---|---|---|
| M20 — game-phase classification (idea only, not their impl) | Bucket the account opening/mid/late from host research completion (their version hardcodes `research_level <5/<15`, which we forbid) | New read-only tool beside `app/Infrastructure/Language/Tools/CampaignFactsTool.php`, surfaced to `OgameCampaignConsultationAgent` | A pro player shifts advice by phase (opening economy vs. fleet economy); host-read fraction of research objects keeps gate 1 clean. Hold for a measured need — see risks. |

## ENHANCE

| Our current | What the repo does better | Proposed change | Files touched |
|---|---|---|---|
| Consultation schema returns `candidate_id` + `risk` + `reason` + `evidence_ids`, no self-confidence; a bad nudge can still move ranking within its profile bound | `EnhancedGeminiBrain._calculate_confidence` scores the analysis and falls back to safety-first when low | Add an optional `confidence` (0..1) to the consultation schema; below a config floor, `ApplyCampaignConsultationRankingAction` treats the recommendation as "no nudge" (keep the native decision) instead of applying it | `app/Ai/Agents/OgameCampaignConsultationAgent.php`, `app/Actions/RecordCampaignConsultationAction.php` (validator), `app/Actions/ApplyCampaignConsultationRankingAction.php`, `config/campaign-consultation.php` |
| Consultation emits one immediate candidate; no time horizon | `StrategicPlanner` emits now/hour/day/week-horizoned goals (`immediate_priorities`, `next_hour/day/week`) | Tag the recommendation with a horizon enum; non-now nudges are deferred rather than forced into the current decision | `app/Ai/Agents/OgameCampaignConsultationAgent.php`, `app/Actions/ApplyCampaignConsultationRankingAction.php`, `app/Enums/` (new `AiCampaignConsultationHorizon`) |

## ALREADY-DONE (confirmed by grep)

- **Native deterministic fleetsave fallback (beats M13/M16's prompt-level fleetsave rule)** —
  `app/Domain/Decision/QueueableFleetSavePlanner.php`, `app/Actions/QueueAiFleetSaveAction.php`,
  `app/Enums/AiCandidateActionType.php` (`FleetSave`), `app/Domain/Perception/PlayerObservationService.php`.
- **Schema-allowlist decision validation (beats M14)** — `app/Ai/Agents/OgameConversationReplyAgent.php::schema()`,
  `app/Ai/Agents/OgameCampaignConsultationAgent.php::schema()`; deterministic re-validation in the
  record actions (`RecordAiLanguageProposalAction`, `RecordCampaignConsultationAction`).
- **Authored/native fallback on provider failure (beats M13's hardcoded fallback)** —
  `app/Actions/GenerateAiReplyAction.php` (sealed authored reply via `DeliverAiSealedReplyAction`),
  native engines as the floor (AGENTS.md).
- **Read-only tools instead of prompt-stuffing (strictly better than M17/M18/M19 keyword "RAG")** —
  `app/Infrastructure/Language/Tools/CampaignFactsTool.php`, `LegalCandidatesTool.php`;
  `app/Actions/BuildCampaignConsultationBriefAction.php` (brief carries only campaign id + admitted evidence).
- **Token discipline: native gameplay makes zero LLM calls (beats M04/M05 "AI every 30min")** —
  `app/Actions/ResolveAiAdmissionAction.php`, `ResolveCampaignConsultationAdmissionAction.php`,
  `app/Actions/RequestCampaignConsultationAction.php` (admission → cooldown → concurrency cap).
- **Human reaction latency / arrival jitter (beats M03's flat 2–8s sleep)** —
  `app/Domain/Routine/SessionPlanner.php` (SP3 jitter), `app/Domain/Scheduling/SessionDecisionService.php` (`materialArrivalDelay`).
- **Prompt reasoning without object lists (gate 1) (beats M16's hardcoded rules)** —
  `app/Ai/Agents/OgameCampaignConsultationAgent.php::instructions()` ("prerequisite before the thing it
  unlocks"), `plan/details/research/llm-prompt-knowledge.md`, `PromptGateOneTest`.
- **Latency + settled-cost measurement (beats M37's flat-JSON `/metrics`)** —
  `app/Actions/BuildAiPilotReportAction.php`, `app/Domain/Operability/AiPilotReport.php`,
  `app/Console/Commands/ReportAiPilot.php` (S6 `ResolveAiUsageCostAction`).
- **Confidence-gated escalation on the reply lane** — S5 (thinking-model retry on `interpretation === Uncertain`),
  `OgameCampaignConsultationAgent::providerOptions()` (thinking mode for consultations).

## REFUSE

- M35/M36/M38/M39 — Playwright browser login, DOM resource parsing, stealth flags, hardcoded
  selectors/universe IDs: **no browser scraping in our module**; we drive host services via `QueueAi*` contracts.
- M01/M02/M04/M30 — fixed poll-sleep loops, 60s "ULTRA-AGGRESSIVE" polling, 8h sessions: machine-shaped (gate 3).
- M25/M26 — hardcoded placeholder game state and build costs: fake data, gate 1.
- M27/M28/M32/M33/M40 — hardcoded build/research queues, phase targets, fleet-power formula, fallback
  build order: gate 1 (object names + weights as source of truth).
- M08/M12/M29 — hardcoded resource-full thresholds and "spend list" (`metal>50000 → metal_mine`):
  gate 1 (thresholds hardcoded) and gate 3 (a human has no such fixed list).
- M31 — 0.7 win-probability attack threshold hardcoded: gate 1; our `UtilityScorer` already weights
  `target_confidence` host-read.
- M17/M18/M19 — keyword-only fake "RAG" (no embeddings, substring scoring): gate 2; we already have
  structured retrieval + S2 authored knowledge.
- M21/M22/M23 — planner `_needs_*` all `return True`, fixed `resources_required`/`estimated_time`:
  placeholders, not real; gate 1 hardcoded resources.
- M34 — `subprocess.run(['gemini', ...])` CLI call: we use the Laravel AI SDK gateway.
- M05/M11 — cron scheduling via an absent `tools` module (broken seam): gate 2, non-functional.
- M15 — hardcoded model names in code: we already route per-task via `config/routing.php` (S3/S5).
- M37 — `/health` + `/metrics` over flat JSON: YAGNI; operator page and pilot report already aggregate counters.

## Priority recommendation

Ship the confidence gate on the consultation nudge (ENHANCE row 1). It is the one mechanism the repo
has that we half-miss: our consultation schema can currently apply a weak nudge, while the repo scores
its own output and walks away when it is not sure. The change is small — one optional schema field, one
floor check in `ApplyCampaignConsultationRankingAction`, one config default — and it reuses the S5
cascade discipline, so it is bounded, priced, and does not touch the deterministic native decision.

## Open questions / risks

- **Is game-phase context actually needed?** The native planner already orders by host-read
  prerequisites (`FacilityChain`), and the campaign lane has its own phase (`NewPhase`). Adopting
  M20 now risks a gate-2/YAGNI finding; build it only when a consultation case measurably wants it.
- **Confidence is self-reported.** A model's confidence is not calibrated; treat it only as a
  fail-safe (suppress the nudge below the floor), never as a reason to override the native score.
- **Horizon tagging widens the schema** and needs a validator for the new enum plus a rule for how a
  deferred nudge is re-applied later — more machinery than the confidence gate, so it ranks second.
- **Policy risk:** nothing in this repo is importable as code (Gameforge policy); all three non-REFUSE
  rows are "see a feature, build our own host-read version", never a port.
