# Strategy knowledge store — YAML is canonical

The machine-readable strategy knowledge base lives here, and **these YAML files are the single
authority** for the strategy mining workstream. The Markdown catalogs under `plan/details/research/`
(`source-registry.md`, `strategy-principles.md`, `strategy-claims.md`, `classical-ai-patterns.md`)
remain the human narrative; where they and the YAML disagree, the YAML wins. Edit the YAML here;
do not hand-edit the Markdown catalogs as a second source.

The original Markdown → YAML migration ran once via `scripts/strategy-export.py`; that script is
retained for provenance and to regenerate the derived views, not as the ongoing write path. The
`claims/` layer below is hand-maintained: it was extracted on 16 September 2026 and has no Markdown
upstream.

## File map

| Path | Holds | Upstream at migration |
| --- | --- | --- |
| `sources.yaml` | the reusable source registry (`FOR-*`, `ORG-*`, …) | `source-registry.md` |
| `principles/<domain>.yaml` | the 107 principles across 13 domains, each with `claim_type`, `confidence`, `status`, `ogamex`, `code` | `strategy-principles.md` |
| `coverage.yaml` | the per-domain coverage matrix (shipped / partial / researched / deferred / gap) | `strategy-principles.md` § Coverage matrix |
| `contradictions.yaml` | the contested principles (confidence `C`), recorded never reconciled | `strategy-principles.md` |
| `open-questions.yaml` | the ten hypotheses H1–H10 and their verdicts | `strategy-mining.md` |
| `classical-ai/<game>.yaml` | the classical-game-AI pattern catalog per game | `classical-ai-patterns.md` |
| `claims/types.yaml` | the eight atomic claim types and their dispositions | `strategy-claims.md` |
| `claims/contested.yaml` | the atomic contested claims (`CLAIM-*`), preserved verbatim | `strategy-claims.md` |

## Pipeline

`source → atomic claim → principle → architecture mapping → algorithm block → integration gate`.

- `sources.yaml` → `claims/` (classification + contested atoms) → `principles/` → `architecture-mapping.md` → `gameplay-algorithms.md` → `strategy-mining.md` (integration gates L).
- Each principle carries exactly one `claim_type`; the type is what allows a sourced observation to
  become executable policy (`DOMAIN_FACT` never a constant, `HARD_SAFETY_POLICY` never out-ranked by
  utility, `ADVANCED_TACTIC` gated behind a reviewed cluster).
- The runtime policy layer (weights, thresholds, behaviour profiles) is **not** derived from this
  store; it is a later, separately-justified step, and nothing here is executable configuration.

Consumers of the strategy research — `gameplay-algorithms.md`, `GAP-REGISTER.md` and the task DB
`doc_refs` — point here rather than at the Markdown catalogs, so there is one authority.
