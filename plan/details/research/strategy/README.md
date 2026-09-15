# Strategy knowledge store (YAML)

The machine-readable form of the strategy research, per the
[Strategy Knowledge Storage Format](#the-storage-format) the owner specified. It is **research
knowledge**, not runtime configuration: nothing here is executed by the module, and no runtime policy
is derived from it yet. The module's behaviour stays in `app/Domain/Decision/`.

## Layout

```
strategy/
  sources.yaml                 one entry per reusable research source
  principles/<domain>.yaml     13 domains, 107 normalised principles
  classical-ai/<game>.yaml     5 source games, 23 reusable patterns
  contradictions.yaml          the 14 entries the sources disagree on (confidence C)
  open-questions.yaml          the H1–H10 hypotheses and their verdicts
  coverage.yaml                per-domain coverage matrix
```

## Schema

Every file is a top-level YAML **list** of entries.

**`sources.yaml`** — `id, title, family, url, domains[], priority`
**`principles/*.yaml`** — `id, title, domain, confidence, status, principle, inputs, affects,
archetypes, exceptions, sources, ogamex, code, priority`, plus `claim_type[]` where
`strategy-claims.md` classifies it
**`classical-ai/*.yaml`** — `id, title, source_game, game, confidence, problem, vanilla, mechanism,
why, cost, failure_modes, generalizable, ogamex_mapping, sources`
**`contradictions.yaml`** — `id, title, domain, principle, sources, status, ogamex`
**`open-questions.yaml`** — `id, claim, evidence, status`
**`coverage.yaml`** — `domain, sources, principles, shipped, researched_or_gap, coverage`

`confidence` is the catalog's own scale — **A** host-derivable/multi-source · **B** documented ·
**C** contested · **D** anecdotal. `status` is `shipped | partial | deferred | researched | gap`.

## Provenance — one source, not two

Every file here is **generated** from the Markdown catalogs by
[`scripts/strategy-export.py`](../../../scripts/strategy-export.py):

| YAML | generated from |
| --- | --- |
| `sources.yaml` | `research/source-registry.md` |
| `principles/*.yaml`, `coverage.yaml`, `contradictions.yaml` | `research/strategy-principles.md` |
| `classical-ai/*.yaml` | `research/classical-ai-patterns.md` |
| `open-questions.yaml` | `specs/strategy-mining.md` |
| `claim_type` on a principle | `research/strategy-claims.md` |

Re-run it from the module root:

```
python3 scripts/strategy-export.py
```

Because the YAML is derived, the Markdown stays the editable source **during the migration**. Editing
the YAML directly would drift from it. Flipping the source so the YAML becomes canonical (and the
Markdown is regenerated from it) is the open follow-up — it needs the consumers of the Markdown
(`gameplay-algorithms.md`, `GAP-REGISTER.md`, the task DB) repointed first.

## Open gap — the atomic-claims layer

The format specifies a `claims/*.yaml` layer of atomic claims exactly as extracted from sources. That
layer was never built: the repo goes from sources straight to **principles**. What exists is a
*classification* of the 107 principles into the eight claim types (`research/strategy-claims.md`),
which this migration carries as `claim_type[]` on each principle instead of inventing claim rows.
Extracting atomic claims from the sources is real research work, not a migration, and stays open.

## Validation

No schema framework was added (the format says not to, during research). Consistency is enforced by
the generator: stable ids come from the catalogs, entries are replaced rather than duplicated, and
`python3 -c "import yaml,glob; [yaml.safe_load(open(f)) for f in glob.glob('plan/details/research/strategy/**/*.yaml', recursive=True)]"`
parses every file.
