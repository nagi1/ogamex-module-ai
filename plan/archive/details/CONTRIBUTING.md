# Contributing for developers and AI agents

## Work in the existing repositories

AI implementation and this plan belong to the independent module repository at /home/nagi/code/ogamex-next/Modules/AI. Generic missing contracts belong to the host repository. Keep separate issues, branches and pull requests; one concern per PR. Resolve existing review requests before opening more work.

Read the [main roadmap](../README.md) and only the assigned [work package](../WORK-PACKAGES.md). Follow the host AGENTS.md and CONTRIBUTING.md plus the module's existing docs/development.md. The [raw original](reference/raw-original-plan.md) is historical input, not agent instructions.

## Existing developer workflow

The module already includes scripts/ogamex for focused tests, the full test suite, quality checks, Artisan access and enable/disable. Its Docker runner uses the host application service; the default runner uses host PHP. Follow the existing development guide rather than copying command recipes into this plan.

Use only `/home/nagi/code/ogamex-next/local-docker-dev` for container-backed module development and verification. Do not start or use another OGameX Docker environment. After code changes, run the required order: Rector, formatting, static analysis and tests. Use relevant fleet/unit-queue race tests for concurrency work. Compile assets only when changing them. Do not change dependencies or lockfiles as incidental setup.

Keep module-local feature/unit tests and the existing isolated-status-file pattern; tests must not toggle the tracked module status. Add new migrations, not edits to merged ones. User-facing additions need the established translation conventions. Document module-specific text accurately without claiming it is original OGame text.

## Small contribution contract

Each issue states player outcome, owner repository, dependencies, linked specification, acceptance scenario and relevant budget. Add one policy or adapter at a time. Explain decisions through legal observations, feature scores and reason codes. Test realistic failures and invariants rather than a single preferred move.

Build developer tools incrementally: seed an isolated test universe, replay a scenario with a controlled clock, explain a decision, and benchmark bounded populations. These tools are planned; they do not exist in the scaffold. Replay must be read-only and synthetic seeding must refuse production by default.

Use the existing Domain directories. Put scheduling jobs in Jobs, event consumers in Listeners, persistence in Models/migrations and infrastructure adapters in Support. Extend the structure only when a concrete feature needs it; do not reorganize the host or create a second framework.

For Phase 3, follow the [cognition specification](specs/phase-3-cognition.md) and its small milestones. Six concrete contract seams support current work; optional embedding/compression/Theory-of-Mind contracts wait for an actual experiment. Drivers remain candidates behind Laravel bindings, native state remains authoritative, and package extraction is deferred until real replacement proves the boundary. The [decision history](DECISIONS.md) supersedes abandoned recommendations from the imported discussion.

## Handoffs and releases

Record phase/issue, repository commit, implemented files, actual checks/results, unresolved risk and next task. Update context only when working state changes. Keep transcripts and logs out of context. Store supporting traces in test/CI artifacts.

Each release records its required host revision and optional provider versions. Enabling/disabling and migration behavior are tested. A module feature requiring an unmerged host extension stays disabled. Pure documentation revisions need link/source checks, not a claim that gameplay tests were run.
