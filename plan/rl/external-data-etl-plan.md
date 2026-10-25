# External OGame data: collection, ETL and native training integration

Date: 9 October 2026. Owner: Nagi. Status: proposed implementation plan.

This document records the requested full plan. Writing it does not start scraping, send emails,
request accounts, alter active training, or promote a model. Implementation starts after the owner
approves this plan. Developer outreach is part of that proposed execution scope.

## 0. Status (9 October 2026)

- Phase A (baseline and source audit) is **in progress**. The ledger was reconciled with three rows:
  `EXT-001` (this audit, `doc`), `EXT-002` (the public-API collector, `impl`, **done and proven**) and
  `EXT-003` (the phase-D seeding slice, `deferred`).
- `EXT-002` ships `scripts/ogame-public-collect.py`: it collects the documented, unauthenticated
  public API (`serverData` / `universe` / `players` / `alliances` / `highscore`), drops published
  names, remaps ids per contribution and writes a provenance manifest. First capture
  (`s1-en.ogame.gameforge.com`, 9 Oct 2026): 26,600 planets, 4,962 players, 692 alliances, 8,610
  scores. Proof: `test:PublicApiSnapshotImportTest harness:self-check`.
- **Universe inventory (9 Oct 2026):** probing the public API host space (`s<N>-<lang>` across the
  communities) found **267 live universes**; the list is in `storage/rl/external/universes.txt` and
  the collector batches them with `--universes-file FILE` and a politeness `--delay`. Raw payloads
  are stored gzipped; a dead host is skipped without losing the batch and the merge-safe index keeps
  a re-run of a subset from clobbering it.
- **First full public capture (9 Oct 2026):** all 267 universes x 5 score slices =
  **650,150 planets, 111,884 players, 15,617 alliances, 379,836 score rows** + 267 server-setting
  records, pseudonymized, indexed in `storage/rl/external/manifest.json`.
- `EXT-004` ships `scripts/ogame-ostats-collect.py`: the public **OStats** JSON API
  (`ostats.eu/api`, robots.txt allows `/api/`), which keeps an **hourly score/rank history** the
  official API cannot give, plus a 438-universe catalogue and rosters. It uses the same
  per-universe salt as `EXT-002`, so a player recorded by both joins on the pseudonym. Proof:
  `test:OstatsSnapshotImportTest harness:self-check`.
- **Public dataset/model survey (9 Oct 2026):** there is essentially nothing to reuse. HuggingFace has
  no OGame gameplay dataset (only `Shinjo/OgameGuide`, a GFDL wiki-derived mechanics Q&A, see
  `EXT-006`) and no OGame model weights (`CrynogarTM/ogameGPT`, `ogameLM`, `ogameDiffusion` are empty
  placeholders with zero files). Kaggle, OpenML and arXiv return nothing for OGame. The
  "OGame AI" landscape is rule-based bots (`ogame-tbot/TBot`, `r4fek/ogame-bot`,
  `shinigallo/ogame-agi`) - scripts, not trained weights. Clones/engines (OGameX, SuperNova,
  XG-Proyect, UniEngine) and combat simulators (`jstar88/opbe`, `klaasvp/trashsim`, `kweimann/cruiser`)
  are already mined in the `RP-*` repo corpus. The module's own collected corpus is therefore the
  largest public OGame gameplay dataset found.
- **Excluded on purpose:** MMORPG-STAT sets `Content-Signal: ai-train=no` and PTRE's terms disclaim
  AI training, so neither is collected.
- `EXT-005` ships `scripts/ogame-ogmem-collect.py`: the public **OGMem** battle archive (no
  robots.txt), the only public source of real combat. Each report yields the numeric outcome and
  both fleets' before/lost, loot and debris, and every player and alliance name is dropped. A
  bounded, resumable sample is pulled politely (`--pages`, `--max-reports`, `--jobs`, `--delay`).
  Proof: `test:OgmemBattleImportTest harness:self-check`.
- Contact verification (the Phase A artifact) is recorded in
  [external-data-outreach.md](external-data-outreach.md): OUT-01/03/04/05 confirmed from published
  pages, OUT-05 resolved to the published `support@ostats.eu`; PTRE's terms disclaim AI training, so
  OUT-03 is held.
- No email has been sent. No private, team-scoped or access-controlled source has been touched: the
  collector refuses any host that is not an OGame public API host.

## 1. Outcome and approach

Collect broad, useful OGame gameplay evidence and turn the usable portion into better training and
evaluation for the existing AI module. ETL means extract source data, transform it into consistent
records, and load accepted records into the module's existing workflows.

Success is visible play: accounts build and unlock capabilities, raid worthwhile targets, recycle,
save fleets, use moons, and participate in alliances across early, mid and late game. Each code
slice must name the aspect or situation it moves before implementation.

Collect broadly, but train selectively. A winning battle does not prove the launch was a good
decision. A rank increase does not reveal the build order. A database may retain final state but
no historical actions. Missing state, choices and outcomes remain missing rather than becoming
invented human labels.

Preferred flow:

```text
APIs / exports / public pages / authorized database extracts
    -> preserved source records + provenance
    -> validated normalized events and strategy evidence
    -> host-compatible seeded situations + reviewed doctrine
    -> real OGameX simulation and existing choice recorder
    -> existing dataset validation and trainer
    -> held-out closed-loop evaluation + long gameplay runs
    -> candidate model and evidence for promotion
```

Actual human observations, reconstructed situations and teacher-labelled simulated choices remain
distinguishable throughout. Collecting more evidence alone does not improve the teacher; strategy
changes must be wired into existing planners and proven before generating their training labels.

## 2. Existing integration points

The checkout inspected on 9 October has encoder version 2 and four learned choice kinds:
buildings, research, yard orders and login errands. Some overview documents still describe v1;
the code and each dataset's schema sidecar determine compatibility.

Reuse these pieces before adding anything beside them:

| Existing piece | Planned role |
| --- | --- |
| `scripts/ogame-source-fetch.py` | Wiki/source fetching and inventories |
| `plan/research/ogame/SOURCE-REGISTRY.yaml` and canonical strategy registry | Source identity and citation; avoid duplicate IDs |
| `resources/behavior/`, `resources/doctrine/` | Reviewed strategies consumed by existing planners |
| `app/Domain/Choice/` | Legal candidates, host-derived features, recording and policy selection |
| `rl/scripts/generate.sh` | Seeded real-game data generation, including staged worlds |
| `rl/scripts/validate_data.py`, `rl/ogrl/data.py` | Data checks and schema-compatible loading |
| `rl/ogrl/train_bc.py` | Existing behaviour-cloning trainer |
| `rl/scripts/closed_loop.sh`, `late.sh`, `reach.py` | Policy comparisons and gameplay reach evidence |
| `rl/scripts/social_corpus.py`, `rl/ogrl/distil_text.py` | Existing social corpus and text-classification training where compatible |
| `rl/scripts/rl_status.py`, `runs_db.py` | Collection/dataset status integrated with existing training reporting |

Keep the host as referee, features free of object identity, teacher mode unchanged, schema versions
isolated, and runs seeded. No generative calls are added to extraction, data generation, training,
rewards or ordinary play. No new database service, distributed ETL framework or dependency is
needed for the first slice: use existing packages, JSONL, compressed files and SQLite where useful.

## 3. Source inventory and priority

The findings below establish candidates, not permission for bulk reuse. First execution verifies
sample access, format, coverage, restrictions and current endpoints. An account is useful only
when it gives authorized access to relevant existing records.

| Source | Observed capability / status | Acquisition and use |
| --- | --- | --- |
| Official OGame public feeds | Rankings, players, alliances, universe layout/settings; scheduled updates | Public API snapshots for realistic populations and longitudinal history |
| OStats | Developer advertises CSV score-history export and alliance history; no registration | Pilot CSV importer; growth and alliance-history evaluation |
| OGMem | Public battle archive; browsing without an account, uploading with one | Pilot report samples; verify bulk export or permitted crawl access |
| OGameDB | Public-report UI, converter, rankings and mission statistics | Verify dynamic data loading and available records/API; do not assume a full archive |
| MMORPG-STAT / GalaxyStats | Statistics and historical-data candidates | Verify export, retention and operator-approved bulk access |
| OGame Tracker / Nexus | Tracker source is public; Nexus documents local storage and Tracker-history import | Inspect actual backup formats; import voluntarily contributed histories |
| PTRE | Spy reports and team intelligence; private team scope | Documented API/export or operator-agreed access for an authorized team only |
| OGotcha | Converter/repost ecosystem; historical URLs and ownership have changed | Discover working service and archive links before making an adapter |
| Community report proxy | Official administrator describes detailed report retrieval | Verify current API, quotas and permission; still needs report identifiers |
| OGame wiki corpus | Existing WIK corpus and fetcher | Refresh useful strategy evidence, retain citations, synthesize reviewed YAML |
| Community forums | Battle narratives, recruitment, trade, diplomacy and war discussions | Public or authorized member-visible pages; preserve thread context |
| OGameX / 2Moons / Nova / other open-source engine operators | Outreach targets; open-source code does not imply public production data | Request selected anonymized historical tables, exports and schemas |
| Own completed simulations | Full host state, choices, actions and outcomes | Direct native examples and gap diagnosis |

Pilot priority: OStats history, OGMem report samples, existing wiki evidence, and available player
backup samples. Developer outreach runs alongside technical verification after approval. Do not
wait for an operator reply to finish independently available sources.

Sources checked during planning:

- [Official API documentation](https://forum.origin.ogame.gameforge.com/forum/thread/44-ogame-api/).
- [OStats developer description and exports](https://forum.origin.ogame.gameforge.com/forum/thread/329-ostats-analytics-and-statistics/).
- [OGMem archive and account requirements](https://ogmem.com/?lang=en_GB).
- [OGameDB public reports](https://ogamedb.com/en/tools/public-reports).
- [Tracker source](https://github.com/ogametracker/ogame-extension).
- [Nexus developer thread](https://forum.origin.ogame.gameforge.com/forum/thread/481-ogame-nexus/?postID=1216).
- [PTRE presentation](https://ptre.chez.gg/) and [collection restrictions](https://ptre.chez.gg/?page=terms).
- [GalaxyStats developer description](https://forum.origin.ogame.gameforge.com/forum/thread/538-tool-galaxystats-stats-rankings-utilities-for-ogame/?postID=1410).
- [OGotcha successor thread](https://forum.origin.ogame.gameforge.com/forum/thread/74-ogotcha-simple-cr-converter/).
- [Administrator clarification about community proxy and historical rankings](https://forum.origin.ogame.gameforge.com/forum/user-post-list/16-riv/?pageNo=3).

The official direct reports API needs a developer key and individual report IDs; it does not
enumerate all battles. Public current-state APIs do not provide arbitrary historical snapshots.
History comes from existing archives or collection going forward.

## 4. Developer outreach and production extracts

Find operators through official project sites, maintained repositories and published contact
channels. Verify the recipient controls the relevant service. Track recipient, source, request
date, response, allowed use, export format, retention limits and redistribution restrictions.
Drafts and tracking can be file-backed; email delivery uses an available owner-authorized email
connection. If no sending capability is available, supply ready-to-send drafts and report the
dependency explicitly. Never claim an email was sent without a delivery result.

Ask for a schema and small anonymized sample first, then the available historical extract. Accept
CSV, JSONL, SQLite or selected SQL tables. Ask for counts, date range, timezone, engine version,
universe settings, event retention, modifications and known missing fields. A current production
snapshot is useful, but it is not an action history.

Requested data, where retained:

- Account progression, building/research/yard queues, resources and score histories.
- Fleet dispatches, arrivals, recalls, missions and relevant outcomes.
- Combat, spy, recycler, expedition and missile reports, including losses and failed actions.
- Moons, phalanx/jump-gate events, fleet saves and fleet losses where recorded.
- Alliance membership, applications, aid, trades and diplomatic exchanges.
- Authorized alliance conversations, with thread/message ordering and enough context to interpret them.
- Relevant schema and game configuration, without operational secrets.

Use the [recipient-specific outreach list](external-data-outreach.md), not a generic bulk email.
Keep initial messages around 50–80 words, name the recipient's actual project, request its most
useful records, state AI-training use, and ask for an export/API or the right operator contact.
Discuss detailed schema, transfer and handling after a positive response. Do not send duplicate
requests to multiple addresses for the same person.

No response means no grant for private data. Honor refusals. A site login or alliance membership
does not by itself establish permission to reuse private conversations for training.

## 5. Anonymization and handling

Prefer anonymization by the operator before transfer. Request selected gameplay tables rather than
an unrestricted production dump. Password hashes, emails, IPs, sessions, tokens, payment records,
admin/audit identity data and credentials are excluded at the source and rejected on import.

Replace account, alliance, planet and message-author identifiers with stable random values scoped
to each contribution. Do not hash public usernames and call them anonymous. Preserve joins within
the dataset; do not link identities across services without an explicit permitted need. Remove
names, profile links and signatures. Scrub free-text chats for identifying details and secrets,
then review a sample; identifiers alone do not anonymize message text.

Stable pseudonyms, exact timestamps and coordinates can still allow re-identification. Describe
the result as pseudonymized unless anonymity is demonstrated. Where necessary, preserve relative
timing and relationships while shifting dates or remapping coordinates; record every transformation
and do not use altered coordinates to calculate original-world flight geometry.

Store any unexpectedly sensitive input in restricted quarantine, separate from accepted corpora,
until sanitized or deleted. Inspect SQL dumps as data; never execute untrusted SQL against the
application database. Any required restoration uses an isolated disposable database with restricted
credentials. Keep raw dumps, chat corpora, access secrets and large data artifacts out of git.

## 6. Extraction methods and collector behavior

Use the first available method that preserves the required fields:

1. Operator-provided export/database extract or supported backup.
2. Documented API, following pagination, quotas and authentication scope.
3. Permitted public-page scraping with stable selectors and saved source evidence.
4. Authorized member-visible access, using the owner's session/account through supported tooling.
5. An alternative export or endpoint agreed with the operator.

Verify bulk access and reuse restrictions per source. Do not bypass access controls, CAPTCHA or
team permissions. A source that cannot be collected through an allowed method stays pending;
continue other collectors. If a manual download is the only supported route, make its import easy.

Collectors need resume cursors, bounded retries/backoff, timeouts, response-size limits, caching,
atomic file completion, checksum-based deduplication and explicit failure counts. Respect feed
update intervals: the official documentation lists hourly highscores, daily players/alliances/
settings and weekly universe/player-data updates. Recheck those intervals during implementation.

Use modest concurrency and explicit storage/download budgets during the pilot. Increase scope
after measuring accepted records, useful coverage and bytes per source. Keep partial downloads
out of accepted datasets. A rerun should add new records without duplicating completed work.

## 7. Storage, normalization and quality

Proposed artifact layout under host `storage/rl/external/`:

```text
sources/<source-id>/manifest.json      access, provenance, cursors and capture metadata
sources/<source-id>/raw/              original allowed payloads, compressed where useful
sources/<source-id>/normalized/       typed JSONL records
quarantine/                          restricted records awaiting repair or deletion
releases/<release-id>/manifest.json   immutable accepted inputs and split assignments
releases/<release-id>/quality.json    coverage, missingness, duplicates and exclusions
```

Reuse canonical source IDs where one exists. The operational manifest references the research
registry rather than creating a second competing source catalogue.

Common metadata: source ID, original record ID, capture/event time and timezone, payload checksum,
engine/game version, universe settings, record type, access/reuse scope, completeness, parser version
and transformation history. Record types include score snapshots, battle/mission events, progression,
alliance membership/exchanges, conversation threads and cited strategy claims.

Validate types, finite/nonnegative values where appropriate, resource units, timestamp ordering,
participant joins and engine-specific object mappings. Keep unknown distinct from zero. Detect
reposted battles by report ID and content fingerprints, and record contradictory versions.

Map imported object identifiers through a versioned import map checked against the host catalogue.
Source IDs/names are provenance and mapping data, never model features or capability rules. Unknown
or host-unsupported objects stay unresolved; host rules determine scenario legality and outcomes.

Quality reports show accepted/rejected counts, rejection reasons, date ranges, missing fields,
engine/settings coverage, early/mid/late coverage, and gameplay aspects. Account for spectacular-
battle selection bias, donated-data bias, premium effects, universe merges, engine differences,
and repeated material. Report gaps instead of silently imputing facts.

## 8. Native training paths

### Structured gameplay and reports

Use observed fleet/resource/technology conditions to construct supported seeded situations. The
host computes prices, prerequisites, legality, production, flight rules and battle outcomes. A
report from another engine is evidence for a situation, not a demand that OGameX reproduce that
engine's outcome. Mark assumed fields and exclude unsupported reconstructions from human labels.

Run the real simulation, record through the current choice seam, and retain a link from scenario
to source record. Keep provenance outside feature vectors. Preserve the existing teacher/chosen
semantics: do not place an observed human choice into `teacher` merely to fit the loader. A direct
human-imitation path is a later, separately proven extension only if sufficiently complete human
state/action examples exist.

### Strategy evidence

Refresh relevant WIK evidence through the existing fetcher. Translate reviewed strategy into YAML
an existing planner reads, retaining citation pointers and generic fallback behavior. Prove the
teacher does the desired thing, then generate fresh labelled examples. No disconnected data files,
new strategy classes beside existing owners, or silent unfreezing of unrelated work.

### Alliance conversations

Store authorized sanitized conversations separately from numeric choice records. Preserve message
ordering and relevant application/trade/aid context without leaking future outcomes into inputs.
Distinguish human text, authored dialogue and model-written text. Add reviewed labels for existing
classification questions where useful; reuse the social corpus/distillation path after format and
permission checks. Raw conversation text does not train the candidate scorer directly. Social
actions remain deterministic unless an existing, proven choice seam owns them.

### Safe dataset handoff

Publish an immutable dataset release after validation. Ongoing collection writes to a new staging
area; an active training run keeps its fixed file list, schema, teacher/code revision and checksums.
Do not append external-derived records to files an active run is reading. Retraining consumes a
new release and produces a separate model candidate; current serving weights remain recoverable.

Split related source material together: reposts, the same conversation, longitudinal account history
and scenario variants must not cross training/evaluation boundaries. Audit the existing filename-
and-seed grouping before importing external-derived batches. Reserve source groups and seeds before
training. Keep broad simulation data alongside enriched scenarios with measured, reported balance.

## 9. Execution phases and deliverables

| Phase | Work | Deliverable / exit condition |
| --- | --- | --- |
| A: baseline and source audit | Read current handoff/steering and ledger, capture current training state, verify source samples and contacts | Baseline manifest, ranked source inventory, concrete permitted pilot sources |
| B: outreach and collection | Prepare/send approved requests, collect API/export/report samples, inspect donated schemas | Delivered-message evidence where sending is available; resumable collectors and representative accepted samples |
| C: normalization | Sanitize, map fields/objects, preserve provenance, reject malformed data, deduplicate | Repeatable import and quality report; no known secrets or identifying fields in accepted data |
| D: first native slice | Choose an observed gameplay gap, build host-compatible scenarios, improve existing doctrine where justified | Source-derived seeded scenarios producing current-schema recorded choices and a passing situation proof |
| E: training integration | Fixed release, leakage-safe split, existing validation/trainer, status visibility | Separate model candidate and reproducible run with source-to-training lineage |
| F: evaluation and expansion | Closed loop and long play; compare baseline/enriched model; prioritize next missing aspect | Reported intervals, fallbacks, failures and gameplay reach; expansion justified by useful coverage |

First vertical slice: one usable history export plus one report source, normalized records, one
source-derived situation, host-recorded choices, validation and a small end-to-end training/evaluation
run. Follow with broader data collection through the same working pipeline. Outreach can continue
through all phases; replies do not determine the critical path.

Implementation tasks follow `python3 plan/tasks/task.py next` and the claim/file-lock workflow.
First reconcile this owner-approved scope with the ledger; do not take a frozen row or bypass the
queue. Proofs name an actual existing `aspect:`, `situation:` or `invariant:` step. Wiki ingestion
in the harness requires `HARNESS_INGEST=1`. Use the RL skill for all choice/training changes.

## 10. Execution runbook

These existing commands are reference entrypoints, not an instruction to run them while planning.
Choose actual claimed rows, supported situation names and reviewed budgets before execution.
Container work runs only through the configured local development stack; tests use `scripts/ogamex`.

From `Modules/AI`, task selection and verification:

```bash
export OGAMEX_RUNNER=local-docker-dev
python3 plan/tasks/task.py next
python3 plan/tasks/task.py show CODE
python3 plan/tasks/task.py claim CODE agent-name
bash scripts/ogamex situation list
bash scripts/ogamex test-one RelevantFeatureTest
bash scripts/ogamex gate
bash scripts/ogamex prove CODE
python3 plan/tasks/task.py done CODE
```

Existing training-machine entrypoints, from the host root in the prescribed environment:

```bash
# Existing generation example; source-derived initial states need the phase-D integration first.
FIRST=20001 PHP=php bash Modules/AI/rl/scripts/generate.sh storage/rl/external-pilot 4 2 2 teacher 0.0 12
python3 Modules/AI/rl/scripts/validate_data.py 'storage/rl/external-pilot/choices-*.jsonl' --epsilon 0.0 --out storage/rl/external-pilot/validation.json
python -m ogrl.train_bc --data 'storage/rl/external-pilot/choices-*.jsonl' --out storage/rl/external-pilot-model --epochs 30
PHP=php bash Modules/AI/rl/scripts/closed_loop.sh storage/rl/external-pilot-model/model.onnx storage/rl/external-pilot-eval 30001 4 2 2 0.25
```

The small example is a plumbing smoke run, not sufficient promotion evidence. Verify seed ranges
are unused and settings match the baseline. New extraction/normalization commands are to be designed
and implemented; no proposed CLI is presented here as already working.

## 11. Acceptance and evaluation

- Resume after interruption produces the same accepted records without duplicates; failures are visible.
- Every training contribution traces to a permitted source, transformations and a dataset release.
- Dataset rows match one exact encoder schema; legal masks and labels pass existing checks.
- Recording remains teacher-neutral; feature changes bump the encoder version and regenerate data.
- Invalid or unsupported imported objects cannot bypass host validation; modded host objects remain reachable.
- Source grouping prevents train/evaluation leakage; held-out seeds and settings are recorded in advance.
- Compare baseline and enriched training under matched code, architecture, seeds and budgets so changes
  can be attributed. Report by kind, phase and source-derived situation, alongside overall metrics.
- Existing BC offline and closed-loop gates apply without changing thresholds after seeing results.
  The skill and older result notes differ in their use of per-account versus aggregate value metrics;
  explicitly settle the applicable metric before evaluation, and report both without retroactive relabelling.
- Run long early-to-late play and report objects, missions, moon actions, battles, saves and alliances
  actually reached. A model that copies teacher mistakes or leaves aspects idle has not met the goal.
- Use required Feature tests, touched-class tests, `ogamex gate` and row proofs. Before a main merge,
  run the whole module suite. Run quality/coverage when the owner asks.

Report acceptance counts and gameplay results, not only downloaded gigabytes or offline accuracy.
Promotion requires the applicable proof and evaluation evidence; preserve the previous model and
dataset for rollback. This project adds no PPO or new cognition work through the back door.

## 12. Dependencies, limits and owner inputs

Needed only when a verified source requires them: owner-created accounts, supported session/API
access, selected player backups, operator permission for restricted contributions, and a configured
email sender for outreach. Keep secrets outside this document and git. Do not ask the owner to
create accounts for every candidate upfront.

Unknowns to resolve in phase A/B: historical depth and export quality; available routine-play and
failure examples; current OGotcha/proxy availability; actual Tracker/Nexus formats; production schemas
and retained events; authorization for alliance chat training; host compatibility; and current run
status on the training machine. Open-source engine names identify outreach targets, not promised data.

No guaranteed collection volume or model improvement is claimed before samples and evaluation.
If a source is unavailable, prohibited or low-value, record why and move to another. If a capability
cannot be learned through the current seam, retain it as evaluation/strategy evidence and schedule
any necessary new choice kind separately under the same north-star gates.
