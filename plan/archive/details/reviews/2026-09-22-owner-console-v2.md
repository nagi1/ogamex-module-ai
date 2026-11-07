# Review record — owner console v2 (22 September 2026)

Cheap, bounded, machine-parsable record per the [improvement loop](../specs/improvement-loop.md).

```json
{
  "window": "owner-console-v2",
  "date": "2026-09-22",
  "artifacts": {
    "settings": "app/Support/AiSettings.php; app/Support/AiRuntimeSettings.php; app/Actions/BuildAiSettingsPanelAction.php",
    "operations": "app/Enums/AiOperation.php; app/Enums/AiCampaignControl.php; app/Jobs/RunAiOperationJob.php; app/Jobs/RunAiCampaignControlJob.php; app/Models/AiOperationLog.php",
    "roster": "app/Actions/BuildAiPlayerRosterAction.php",
    "frontend": "resources/js/ai-console.js; package.json; vite.config.js; resources/views/partials/*",
    "tests": "tests/Feature/AiSettingsDeployTest.php; AiOperationsTest.php; AiDefinitionCardsTest.php; AiPlayersRosterTest.php; AiCampaignControlsTest.php",
    "spec": "plan/details/specs/owner-console-ux.md"
  },
  "counters": {
    "slices": "UX-001..014 (14)",
    "full_suite": "1040/1040",
    "gate2_findings": 0,
    "phpstan": 0,
    "rector_changed_files": 0
  },
  "findings": [
    "settings split into live (host settings table, ai_* keys) and deployment (one YAML file)",
    "deployment YAML derived into a Docker services matrix plus copy-paste up/down commands",
    "operations and campaign controls are audited, queued jobs, never inline artisan in a request",
    "every knob and operation carries a four-line definition card (what/why/effect/restart)",
    "the console collapsed to six tabs: Health, Players, Settings, Operations, Campaigns, Why",
    "Health merges overview + pilot + monitoring + authenticity into one situation dashboard",
    "Players roster replaces the board with search, filters, View-as (host impersonate) and Stop/Resume",
    "copy buttons and reset-to-default are the module's first justified JS, behind its own Vite build"
  ]
}
```

## What happened

The owner asked for a console a non-technical operator can actually run: informational copy, full
control over settings with a hard DB/env split, deployment config in one place, console buttons for
the commands, and per-player control. The previous page was developer-facing — tabs named after
components, machine strings for alerts, no way to stop one account or run a command.

Fourteen slices rebuilt the surface: `AiSettings` (one YAML schema) and `AiRuntimeSettings` (typed
accessors over the host settings table) own the two halves of configuration; the deployment half
renders as a Docker services matrix plus generated apply commands; operations and campaign controls
run as audited queued jobs over the existing commands and actions; every control has a definition
card; and the six tabs match the questions an owner actually asks. The only deferred pieces are the
full live-YAML editor (the copy/reset enhancement ships instead) and the server-rendered SVG growth
curve, both recorded in the spec.
