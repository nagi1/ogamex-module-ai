#!/usr/bin/env python3
"""Migration: the strategy research Markdown -> the structured YAML knowledge store.

The Markdown catalogs stay the human narrative; this writes the machine-readable store
(`plan/details/research/strategy/`) that a later runtime/tooling phase reads. It is a
one-off migration, kept so the mapping from Markdown to YAML stays reproducible and
reviewable.

Reads:
    plan/details/research/source-registry.md        -> strategy/sources.yaml
    plan/details/research/strategy-principles.md     -> strategy/principles/<domain>.yaml
                                                        strategy/coverage.yaml
    plan/details/research/classical-ai-patterns.md   -> strategy/classical-ai/<game>.yaml

Run from the module root:  python3 scripts/strategy-export.py
"""

from __future__ import annotations

import re
import sys
from pathlib import Path

import yaml

MODULE_ROOT = Path(__file__).resolve().parent.parent
RESEARCH = MODULE_ROOT / "plan/details/research"
OUT = RESEARCH / "strategy"

HEADER = (
    "# Generated from {source} by scripts/strategy-export.py — do not hand-edit; edit the\n"
    "# Markdown catalog and re-run, or start editing here once the migration is accepted.\n"
)

BULLET = re.compile(r"^-\s+(.*)$")
KV = re.compile(r"\*\*(?P<key>[^*]+):\*\*\s*(?P<value>.*)$")
CLAIM_TYPES = {
    "DF": "DOMAIN_FACT",
    "HS": "HARD_SAFETY_POLICY",
    "SF": "SCORING_FACTOR",
    "SH": "STRATEGIC_HEURISTIC",
    "BP": "BEHAVIOR_PROFILE_PARAMETER",
    "AT": "ADVANCED_TACTIC",
}
SECTION = re.compile(r"^##\s+(?P<title>.+?)\s*$")
ENTRY = re.compile(r"^###\s+(?P<id>[A-Za-z0-9-]+)\s+[—-]\s+(?P<title>.+?)\s*$")
TABLE_ROW = re.compile(r"^\|(?P<cells>.+)\|$")


def slug(text: str) -> str:
    text = text.split("(")[0].strip().lower()
    return re.sub(r"[^a-z0-9]+", "-", text).strip("-")


def key_slug(text: str) -> str:
    return re.sub(r"[^a-z0-9]+", "_", text.strip().lower()).strip("_")


def split_row(line: str) -> list[str]:
    match = TABLE_ROW.match(line)
    if match is None:
        return []
    return [cell.strip() for cell in match.group("cells").split("|")]


def is_separator(cells: list[str]) -> bool:
    return bool(cells) and all(set(cell) <= {"-", ":", " "} and cell for cell in cells)


def parse_bullets(lines: list[str]) -> dict[str, str]:
    """Collect `- **key:** value` bullets, joining their wrapped continuation lines.

    One bullet may carry several key/values separated by ` · `.
    """
    fields: dict[str, str] = {}
    chunks: list[str] = []

    for line in lines:
        match = BULLET.match(line)
        if match is not None:
            chunks.append(match.group(1).strip())
            continue
        if chunks and line.startswith((" ", "\t")) and line.strip():
            chunks[-1] += " " + line.strip()

    for chunk in chunks:
        for part in chunk.split(" · "):
            part = part.strip()
            kv = KV.match(part)
            if kv is None:
                continue
            value = kv.group("value").strip()
            if value not in ("", "—", "-"):
                fields[key_slug(kv.group("key"))] = value

    return fields


def entries_of(text: str, source: str) -> list[tuple[str, dict[str, str]]]:
    """Walk `## section` / `### ID — Title` / bullets and return (section, fields)."""
    found: list[tuple[str, dict[str, str]]] = []
    section = ""
    current_id: str | None = None
    current_lines: list[str] = []
    order: list[tuple[str, str]] = []

    def flush() -> None:
        if current_id is not None:
            found.append((section, {"id": current_id, **parse_bullets(current_lines)}))

    for line in text.splitlines():
        heading = SECTION.match(line)
        if heading is not None:
            flush()
            current_id, current_lines = None, []
            section = heading.group("title")
            continue

        entry = ENTRY.match(line)
        if entry is not None:
            flush()
            current_id = entry.group("id")
            current_lines = [f"- **title:** {entry.group('title')}"]
            order.append((section, current_id))
            continue

        if current_id is not None:
            current_lines.append(line)

    flush()

    del source
    return found


def export_sources() -> None:
    text = (RESEARCH / "source-registry.md").read_text(encoding="utf-8")
    entries: list[dict[str, object]] = []
    family = ""

    for line in text.splitlines():
        heading = SECTION.match(line)
        if heading is not None:
            family = heading.group("title")
            continue

        cells = split_row(line)
        if len(cells) != 5 or is_separator(cells) or cells[0] == "id":
            continue

        source_id, title, url, domains, priority = cells
        if not re.match(r"^[A-Z]{2,3}-\d+$", source_id):
            continue

        entries.append(
            {
                "id": source_id,
                "title": title,
                "family": slug(family),
                "url": url,
                "domains": [part.strip() for part in domains.split(",") if part.strip()],
                "priority": priority,
            }
        )

    write(OUT / "sources.yaml", "plan/details/research/source-registry.md", entries)
    print(f"sources.yaml: {len(entries)} sources")


def parse_claim_types() -> dict[str, list[str]]:
    """`strategy-claims.md` classifies every principle into one claim type."""
    text = (RESEARCH / "strategy-claims.md").read_text(encoding="utf-8")
    types: dict[str, list[str]] = {}
    inside = False

    for line in text.splitlines():
        if line.startswith("## Classification"):
            inside = True
            continue
        if inside and line.startswith("## "):
            break
        if not inside:
            continue

        cells = split_row(line)
        if len(cells) != 7 or is_separator(cells) or cells[0].startswith("Domain"):
            continue

        for column, values in zip(("DF", "HS", "SF", "SH", "BP", "AT"), cells[1:]):
            for principle in values.split(","):
                principle = principle.strip()
                if re.match(r"^[A-Z]{2,4}-\d+$", principle):
                    types.setdefault(principle, []).append(CLAIM_TYPES[column])

    return types


def export_principles(claim_types: dict[str, list[str]]) -> list[dict[str, object]]:
    text = (RESEARCH / "strategy-principles.md").read_text(encoding="utf-8")
    grouped: dict[str, list[dict[str, object]]] = {}
    flat: list[dict[str, object]] = []

    for section, fields in entries_of(text, "strategy-principles.md"):
        domain = slug(section)
        if not domain or "coverage" in domain:
            continue

        entry: dict[str, object] = {"id": fields.pop("id"), "title": fields.pop("title")}
        entry["domain"] = fields.pop("category", domain)
        entry.update(fields)

        claim_type = claim_types.get(str(entry["id"]))
        if claim_type:
            entry["claim_type"] = claim_type

        grouped.setdefault(domain, []).append(entry)
        flat.append(entry)

    total = 0
    for domain, entries in grouped.items():
        write(
            OUT / "principles" / f"{domain}.yaml",
            "plan/details/research/strategy-principles.md",
            entries,
        )
        total += len(entries)
        print(f"principles/{domain}.yaml: {len(entries)} principles")

    print(f"principles: {total} total")
    return flat


def export_contradictions(principles: list[dict[str, object]]) -> None:
    """A contested entry is one the sources disagree on (confidence C).

    They are recorded here, never silently reconciled: the resolution lives in the
    principle's own catalogue entry and in DECISIONS.md.
    """
    contested = [p for p in principles if str(p.get("confidence", "")).startswith("C")]

    rows = [
        {
            "id": p["id"],
            "title": p["title"],
            "domain": p.get("domain"),
            "principle": p.get("principle"),
            "sources": p.get("sources"),
            "status": p.get("status"),
            "ogamex": p.get("ogamex"),
        }
        for p in contested
    ]

    write(OUT / "contradictions.yaml", "plan/details/research/strategy-principles.md", rows)
    print(f"contradictions.yaml: {len(rows)} contested entries")


def export_open_questions() -> None:
    """The ten hypotheses the brief ordered attacked (H1-H10) and their verdicts."""
    text = (MODULE_ROOT / "plan/details/specs/strategy-mining.md").read_text(encoding="utf-8")
    rows: list[dict[str, object]] = []
    inside = False

    for line in text.splitlines():
        if line.startswith("| H | Claim | Evidence | Status |"):
            inside = True
            continue
        if inside and not line.startswith("|"):
            break
        if not inside:
            continue

        cells = split_row(line)
        if len(cells) != 4 or is_separator(cells) or cells[0] == "H":
            continue

        rows.append(
            {
                "id": cells[0],
                "claim": cells[1],
                "evidence": cells[2],
                "status": cells[3],
            }
        )

    write(OUT / "open-questions.yaml", "plan/details/specs/strategy-mining.md", rows)
    print(f"open-questions.yaml: {len(rows)} hypotheses")


def export_classical() -> None:
    text = (RESEARCH / "classical-ai-patterns.md").read_text(encoding="utf-8")
    grouped: dict[str, list[dict[str, object]]] = {}

    for section, fields in entries_of(text, "classical-ai-patterns.md"):
        game = slug(section)
        if not game or not fields.get("id"):
            continue

        entry: dict[str, object] = {"id": fields.pop("id"), "title": fields.pop("title")}
        entry["source_game"] = game
        entry.update(fields)
        grouped.setdefault(game, []).append(entry)

    total = 0
    for game, entries in grouped.items():
        write(
            OUT / "classical-ai" / f"{game}.yaml",
            "plan/details/research/classical-ai-patterns.md",
            entries,
        )
        total += len(entries)
        print(f"classical-ai/{game}.yaml: {len(entries)} patterns")

    print(f"classical-ai: {total} total")


def export_coverage() -> None:
    text = (RESEARCH / "strategy-principles.md").read_text(encoding="utf-8")
    rows: list[dict[str, object]] = []
    inside = False

    for line in text.splitlines():
        if line.startswith("## Coverage matrix"):
            inside = True
            continue
        if inside and line.startswith("## "):
            break
        if not inside:
            continue

        cells = split_row(line)
        if len(cells) != 6 or is_separator(cells) or cells[0] == "Domain":
            continue

        rows.append(
            {
                "domain": cells[0],
                "sources": cells[1],
                "principles": int(cells[2]),
                "shipped": int(cells[3]),
                "researched_or_gap": cells[4],
                "coverage": cells[5],
            }
        )

    write(OUT / "coverage.yaml", "plan/details/research/strategy-principles.md", rows)
    print(f"coverage.yaml: {len(rows)} domains")


def write(path: Path, source: str, payload: list[dict[str, object]]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    header = HEADER.format(source=source)
    body = yaml.safe_dump(payload, sort_keys=False, allow_unicode=True, width=100)
    path.write_text(header + body, encoding="utf-8")


def main() -> int:
    if not RESEARCH.is_dir():
        print(f"research directory not found: {RESEARCH}", file=sys.stderr)
        return 1

    export_sources()
    claim_types = parse_claim_types()
    principles = export_principles(claim_types)
    export_classical()
    export_coverage()
    export_contradictions(principles)
    export_open_questions()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
