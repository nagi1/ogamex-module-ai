# Review record — PvE cooperation and the generative lanes (21 September 2026)

Cheap, bounded, machine-parsable record per the [improvement loop](../specs/improvement-loop.md).

```json
{
  "window": "pve-cooperation-generative-lanes",
  "date": "2026-09-21",
  "cohort": "ogamex-pve",
  "artifacts": {
    "language": "ai-language-conformance/20260921-121453.json",
    "cognition": "ai-cognition-conformance/20260921-122624.json"
  },
  "counters": {
    "language_cases": 1,
    "language_completed": 1,
    "language_latency_ms": 1225,
    "language_input_tokens": 221,
    "language_output_tokens": 94,
    "fatima_calls": 20,
    "fatima_http_calls": 100,
    "fatima_p50_ms": 79,
    "fatima_p95_ms": 88,
    "fatima_correct": "yes",
    "cbrkit_calls": 20,
    "cbrkit_correct": "no",
    "provider_calls": 1
  },
  "findings": [
    "the language lane answers a sanitized greeting through deepseek-flash: 1/1 completed, 1225 ms, no proposal for a plain greeting",
    "fixed: the fatima sidecar was unreachable over its published port — the long-running sidecar containers lost their Docker Desktop port forwarding (healthcheck and same-network calls passed, host and cohort calls timed out); docker compose up -d --force-recreate re-registered the mapping and ai:cognition-conformance now passes fatima 20/20 calls, p50 79 ms, correct",
    "open: cbrkit experience conformance reports correct=no with only 3 HTTP calls over 20 iterations — the driver is mostly not reached; separate from the fatima port seam and left for a follow-up",
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

2. **Cognition sidecars.** `ai:cognition-conformance --confirm` first failed because the fatima
   sidecar was unreachable over its published port: its own loopback healthcheck and same-network
   calls passed, but host and cohort calls to `host.docker.internal:8092` timed out. The root cause
   was stale Docker Desktop port forwarding on the long-running sidecar containers (up 18 h), not
   module code — the module's native fallback is why the cohort kept playing. Recreating the sidecar
   (`docker compose up -d --force-recreate --no-deps fatima agentos`) re-registered the mapping, and
   the re-run passes: fatima 20/20 calls, 100 HTTP requests, p50 79 ms / p95 88 ms, correct. One
   residual: cbrkit reports `correct=no` with only 3 HTTP calls over 20 iterations, a separate
   experience-driver short-circuit left open.

3. **Cooperation, hostility, consultation admission.** Proven by the feature suite against pve state,
   not re-derived here: `CooperativeHostilityPolicyTest` 4/4, `AllianceLifeTest` 11/11 (29 asserts),
   `AllianceChatLifeTest` 4/4, `SocialInitiationTest` 3/3, `CampaignConsultationWiringTest` 9/9,
   `CampaignConsultationGatewayTest` 12/12, `CampaignConsultationAdmissionTest` 6/6.

## What the cohort failed to do

- Nothing for the affect lane after the fix: fatima now answers over its published port and the
  conformance proves the external affect path is live (20/20, p50 79 ms). The experience lane
  (cbrkit) still reports `correct=no` with only 3 HTTP calls over 20 iterations — a driver
  short-circuit, not a port seam, left open.

## Verification

- Full module suite in the container: 995/995 (995 tests, 3163 assertions).
- `bash scripts/ogamex gate` — 0 findings.
- `ai:cognition-conformance --confirm` — fatima 20/20 correct, p50 79 ms / p95 88 ms; cbrkit
  `correct=no` (open).
- `ai:language-conformance --confirm` — 1/1 sanitized case completed (deepseek-flash).
- The $10 monthly cost wall (`AI_MONTHLY_COST_USD=10`) and the situation dashboard (the five review
  questions) shipped with DEF-023/DEF-024 and read live.

## What stays unmeasured

- The campaign-consultation LLM lane has no `--confirm` conformance command of its own, so its live
  provider round-trip is proven by wiring/gateway feature tests, not by a sanitized provider call in
  this window.
- The cbrkit experience-driver short-circuit (why only 3 HTTP calls over 20 iterations and
  `correct=no`) is uninvestigated.
