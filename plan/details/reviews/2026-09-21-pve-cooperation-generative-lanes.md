# Review record — PvE cooperation and the generative lanes (21 September 2026)

Cheap, bounded, machine-parsable record per the [improvement loop](../specs/improvement-loop.md).

```json
{
  "window": "pve-cooperation-generative-lanes",
  "date": "2026-09-21",
  "cohort": "ogamex-pve",
  "artifacts": {
    "language": "ai-language-conformance/20260921-121453.json",
    "cognition": "ai:cognition-conformance (failed — see finding 2)"
  },
  "counters": {
    "language_cases": 1,
    "language_completed": 1,
    "language_latency_ms": 1225,
    "language_input_tokens": 221,
    "language_output_tokens": 94,
    "cognition_conformance": "failed",
    "provider_calls": 1
  },
  "findings": [
    "the language lane answers a sanitized greeting through deepseek-flash: 1/1 completed, 1225 ms, no proposal for a plain greeting",
    "the fatima cognition sidecar is unreachable outside its own loopback: in-container healthcheck passes, but the published port 8092 resets every external connection, so ai:cognition-conformance cannot run and the external affect path is not established (module falls back to native)",
    "cooperative hostility, alliance life and consultation admission are proven by feature tests on pve: 4 + 11 + 4 + 3 + 9 + 12 + 6 tests, all green"
  ]
}
```

## What happened

The pve cohort is the cooperation testbed (normal-mode grand stays the authenticity testbed). This
window read the three generative/cooperative surfaces the plan names:

1. **Language chat (LLM).** `ai:language-conformance --confirm` sent the one sanitized smoke case to
   the live lane and it completed: `deepseek-flash`, 54 characters in, 221 input / 94 output tokens,
   1225 ms, interpretation `none` (a greeting earns no proposal). Sanitized evidence written to
   `ai-language-conformance/20260921-121453.json`.

2. **Cognition sidecars.** `ai:cognition-conformance --confirm` failed before any measurement: the
   fatima driver's HTTP endpoint returns an empty reply (cURL 52) and resets external connections.
   The sidecar container is `healthy` and its own loopback healthcheck
   (`curl 127.0.0.1:8000/scenarios`) passes, so the published port `0.0.0.0:8092->8000` is the broken
   seam — the FAtiMA server answers on its loopback and resets everything else. This is a sidecar
   binding issue, not module code: the module already falls back to the native affect engine, which is
   why the cohort keeps playing.

3. **Cooperation, hostility, consultation admission.** Proven by the feature suite against pve state,
   not re-derived here: `CooperativeHostilityPolicyTest` 4/4, `AllianceLifeTest` 11/11 (29 asserts),
   `AllianceChatLifeTest` 4/4, `SocialInitiationTest` 3/3, `CampaignConsultationWiringTest` 9/9,
   `CampaignConsultationGatewayTest` 12/12, `CampaignConsultationAdmissionTest` 6/6.

## What the cohort failed to do

- The external affect driver (fatima) never answers from outside its container, so the "external
  cognition" mode on both cohorts is a native fallback in practice. The gap is between the configured
  driver and the observed driver, and it is invisible in a session log because the fallback is silent.

## Verification

- Full module suite in the container: 995/995 (31 December-style count: 995 tests, 3163 assertions).
- `bash scripts/ogamex gate` — 0 findings.
- The $10 monthly cost wall (`AI_MONTHLY_COST_USD=10`) and the situation dashboard (the five review
  questions) shipped with DEF-023/DEF-024 and read live.

## What stays unmeasured

- The campaign-consultation LLM lane has no `--confirm` conformance command of its own, so its live
  provider round-trip is proven by wiring/gateway feature tests, not by a sanitized provider call in
  this window.
- The cognition conformance figures (p50/p95 latency, payload bytes) are not established while the
  fatima port seam is broken.
