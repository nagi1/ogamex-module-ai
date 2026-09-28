# OGame research — source → doctrine → behaviour

The personality/behaviour half of the research pipeline. This tree exists alongside — not instead
of — the strategy research under `plan/details/research/`. Strategy research answers *what an
experienced player does*; this tree answers *who the account is* (persona, routine shape, social
texture) and carries the provenance from source to behaviour.

## Pipeline

```
queries/ ──▶ raw/ ──▶ synthesis/ ──▶ resources/behavior/
what we      what the    what we       what the account
looked for   source said concluded     does
```

Each step cites the one before it. A behaviour value exists only when the chain behind it resolves.

## Directory map

| Path | Holds |
| --- | --- |
| `queries/` | What was searched and why, logged before the fetch. One bounded pass each. |
| `raw/` | Verbatim evidence, stored by `domain/site/topic/<source-id>.md`, each with a provenance block. |
| `synthesis/` | Doctrine notes per domain; every claim cites its source id(s). |
| `SOURCE-REGISTRY.yaml` | Stable source ids for sources not already in the canonical strategy store. |

## Provenance rules

- **One id namespace.** `plan/details/research/strategy/sources.yaml` is the canonical source-id
  namespace. Reuse its ids verbatim; never renumber. Register only new sources here, and promote
  them to the canonical store when they feed strategy — never the reverse.
- **Raw evidence is verbatim.** Quoted passages, not paraphrase; an inaccessible source is recorded
  as a limitation, never fabricated. Public/indexable material only.
- **Synthesis and behaviour cite the same ids.** A claim whose id does not resolve is not accepted.

## The pipeline (dev tooling, never shipped)

`scripts/strategy-pipeline.py` runs the whole chain on the dev box. It is not app code, never runs
in a universe, and its only model call happens off-peak.

```
sweep     wiki keep set -> raw evidence          no tokens
bundle    raw evidence  -> context bundle        no tokens
plan      bundle        -> plan proposal         one off-peak call
validate  proposal      -> PASS or reasons       no tokens
promote   validated plan-> tasks.db row          no tokens
coverage  what exists, what we hold, what is left
run       sweep/plan/validate loop; parks inside peak hours
```

Rules the tooling enforces:

- **Nothing useful is skipped, and every skip has a reason.** `sweep` takes every namespace-0 page
  except redirects (resolved to their target), the wrong-ruleset categories (`Outdated`,
  `Discontinued`, `Lifeform*`), glossary/UI/social pages, and the **object** pages — the host owns the
  object universe, so a second copy here would drift from the code (gate 1). Everything else,
  including formulas and concepts, is ingested.
- **One source, one row.** A page already registered in the canonical store is never re-ingested
  under a new id.
- **A plan is not a change.** `validate` fails closed and `promote` writes a `todo` row marked
  `UNREVIEWED`; implementation still goes through the normal task workflow, tests and gates.
- **The peak window is read from `config/routing.php`, not copied.** A self-check fails if the two
  ever disagree, so the gate cannot silently drift from the router.

## Task system

Tasks come only from `plan/tasks/tasks.db` — see `plan/tasks/USAGE.md`. This tree adds research
artefacts; it does not invent a second task system.

## Skills and agents

Skills: `ogame-source-ingest`, `ogame-doctrine-synthesis`, `ai-task-execute`, `ai-change-review`.
Agents: `ogame-researcher` (source→doctrine), `ai-implementer` (doctrine→behaviour/code),
`ai-reviewer` (gate + provenance review).
