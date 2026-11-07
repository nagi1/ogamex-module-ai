#!/usr/bin/env python3
"""The evidence corpus of anomaly_report.py: a small cached text index plus the real reference numbers.

What is indexed (one passage = one verbatim line or one principle entry, with its source id and path):
* raw wiki / forum / third-party captures, `Modules/AI/plan/research/ogame/raw/**/*.md` (id from the front matter,
  e.g. WIK-175); their bullet lines are verbatim.
* the canonical principles, `Modules/AI/plan/details/research/strategy/principles/*.yaml` (one passage per entry,
  id e.g. FLE-005, title + principle + exceptions, with the entry's own `sources`).
* doctrine and behaviour data, `Modules/AI/resources/{doctrine,behavior}/*.yaml` (comment paragraphs and key lines).
* the English object texts of the GitHub OGame clones in the external sources (`language/en*/TECH.php`,
  `english*.ini`), deduplicated: object descriptions, not code.
Real numbers cached next to the text:
* OGMEM per-side unit stocks (every non-simulated report side, attacker and defender, rl/scripts/ogmem_strategies.py
  `load`), and the Weapons/Shields/Armour percentages of every fleet in the raw OGMEM reports.

The index lives in `storage/rl/ogsim/corpus-index/index.json.gz` and is rebuilt only when an input's size or mtime
changes. Non-OGame corpora of the external sources (Diplomacy, Catan, dialogue sets, SC2) are never read.
"""
from __future__ import annotations

import collections
import gzip
import hashlib
import html
import json
import re
import sys
from pathlib import Path

HERE = Path(__file__).resolve().parent
ROOT = HERE.parents[3]                      # repository root
AI = ROOT / "Modules/AI"
RAW = AI / "plan/research/ogame/raw"
PRINCIPLES = AI / "plan/details/research/strategy/principles"
RESOURCES = [AI / "resources/doctrine", AI / "resources/behavior"]
EXTERNAL = AI / "storage/rl/external/sources"
OGAME_GH = ["GH-7omate-ogame", "GH-arathi-2moons", "GH-balrok-og-api", "GH-chrko-ostats", "GH-darthpinkpapa-oclassic",
            "GH-dlaynes-ogniter", "GH-eracle-ogamebot", "GH-jkroepke-2moons", "GH-jstar88-opbe",
            "GH-shinigallo-ogame-agi", "GH-steemnova-steemnova", "GH-supermopf-galaxytool",
            "GH-theonlybeardedbeast-ogame-agent", "GH-yaro2709-new-star"]
INDEX_DIR = ROOT / "storage/rl/ogsim/corpus-index"
INDEX = INDEX_DIR / "index.json.gz"
VERSION = 3

sys.path.insert(0, str(HERE))


# ---------------------------------------------------------------- inputs

def _gh_text_files() -> list[Path]:
    out = []
    for gh in OGAME_GH:
        tree = EXTERNAL / gh / "raw/tree"
        if not tree.is_dir():
            continue
        out += sorted(tree.glob("*/language/en*/TECH.php")) + sorted(tree.glob("*/app/language/en*/TECH.php"))
        out += sorted(tree.glob("*/languages/**/english*.ini"))
    return out


def _inputs() -> list[Path]:
    files = sorted(RAW.rglob("*.md")) + sorted(PRINCIPLES.glob("*.yaml"))
    for d in RESOURCES:
        files += sorted(d.glob("*.yaml"))
    files += _gh_text_files()
    files += [EXTERNAL / "OGMEM/manifest.json", EXTERNAL / "OGMEM/normalized/battle_unit.jsonl",
              EXTERNAL / "OGMEM/normalized/battle.jsonl", HERE / "anomaly_corpus.py"]
    return files


def _signature(files: list[Path]) -> str:
    h = hashlib.sha256()
    for f in files:
        st = f.stat()
        h.update(f"{f}|{st.st_size}|{int(st.st_mtime)}\n".encode())
    return h.hexdigest()


# ---------------------------------------------------------------- passages

def _rel(path: Path) -> str:
    return str(path.relative_to(ROOT))


def _raw_passages(path: Path) -> list[dict]:
    text = path.read_text(errors="replace")
    meta, body = {}, text
    if text.startswith("---"):
        head, _, body = text[3:].partition("\n---")
        for line in head.splitlines():
            k, _, v = line.partition(":")
            if v:
                meta[k.strip()] = v.strip()
    doc = meta.get("id") or path.stem
    family = "verbatim:" + (meta.get("family") or path.parts[len(RAW.parts)])
    out, offset = [], text[: len(text) - len(body)].count("\n")
    for i, line in enumerate(body.splitlines(), start=offset + 1):
        s = line.strip()
        if not s or s.startswith("#") or s.startswith("Automatically ingested") or s.startswith("are verbatim"):
            continue
        s = s.lstrip("-* ").strip()
        if len(s) < 25:
            continue
        out.append({"id": doc, "path": f"{_rel(path)}:{i}", "family": family, "text": s})
    return out


def _principle_passages(path: Path) -> list[dict]:
    entries, cur, key = [], None, None
    for i, line in enumerate(path.read_text().splitlines(), start=1):
        if line.startswith("- id:"):
            cur = {"id": line.split(":", 1)[1].strip(), "line": i}
            entries.append(cur)
            key = None
            continue
        if cur is None:
            continue
        m = re.match(r"^  ([a-z_]+):\s?(.*)$", line)
        if m:
            key = m.group(1)
            cur[key] = m.group(2).strip().strip("'\"")
        elif key and line.startswith("    "):
            cur[key] = (cur[key] + " " + line.strip()).strip()
    out = []
    for e in entries:
        text = f"{e.get('title', '')}: {e.get('principle', '')}"
        if e.get("exceptions") and e["exceptions"].lower() not in ("none.", "none"):
            text += f" (exceptions: {e['exceptions']})"
        out.append({"id": e["id"], "path": f"{_rel(path)}:{e['line']}", "family": "principle", "text": text,
                    "cites": e.get("sources", "")})
    return out


def _yaml_passages(path: Path) -> list[dict]:
    """Comment paragraphs (joined) and key lines with a value, with the file:line they start on."""
    out, para, start = [], [], 0
    lines = path.read_text(errors="replace").splitlines()

    def flush():
        if para:
            t = " ".join(para)
            if len(t) >= 25:
                out.append({"id": f"{path.parent.name}/{path.name}", "path": f"{_rel(path)}:{start}",
                            "family": "data", "text": t})
            para.clear()

    for i, line in enumerate(lines, start=1):
        s = line.strip()
        if s.startswith("#"):
            if not para:
                start = i
            para.append(s.lstrip("# ").strip())
            continue
        flush()
        m = re.match(r"^[-\s]*([A-Za-z_][\w-]*):\s*(.+)$", s)
        if m and len(m.group(2)) >= 25:
            out.append({"id": f"{path.parent.name}/{path.name}", "path": f"{_rel(path)}:{i}", "family": "data",
                        "text": f"{m.group(1)}: {m.group(2).strip()}"})
    flush()
    return out


def _gh_passages(path: Path, seen: set) -> list[dict]:
    gh = path.relative_to(EXTERNAL).parts[0]
    out = []
    for i, line in enumerate(path.read_text(errors="replace").splitlines(), start=1):
        for lit in re.findall(r"'((?:[^'\\]|\\.){40,})'|\"((?:[^\"\\]|\\.){40,})\"|=\s*(.{40,})$", line):
            t = next(x for x in lit if x).strip()
            t = re.sub(r"<[^>]+>", " ", t).replace("\\'", "'").strip()
            key = t.lower()
            if len(t) < 40 or key in seen:
                continue
            seen.add(key)
            out.append({"id": gh, "path": f"{_rel(path)}:{i}", "family": "game-text", "text": t})
    return out


# ---------------------------------------------------------------- real numbers

TECH_RE = re.compile(r"Weapons\s*(\d+)%\s*\W*\s*Shields\s*(\d+)%\s*\W*\s*Armou?r\s*(\d+)%")


def _ogmem_reference() -> dict:
    import ogmem_strategies as om
    sides, _ = om.load()
    stocks = []
    for (rid, side), units in sorted(sides.items()):
        stocks.append({"report": rid, "side": side,
                       "units": {str(om.ID[u]): int(n) for u, n in sorted(units.items()) if n > 0}})
    keep = {rid for rid, _ in sides}
    tech = []
    for f in sorted((EXTERNAL / "OGMEM/raw").glob("report-*.html.gz")):
        rid = f.name.split("-")[1].split(".")[0]
        if rid not in keep:
            continue
        t = gzip.open(f, "rt", errors="replace").read()
        t = re.sub(r"\s+", " ", html.unescape(re.sub(r"<[^>]+>", " ", t)))
        for w, s, a in TECH_RE.findall(t):
            tech.append([rid, int(w), int(s), int(a)])
    return {"stocks": stocks, "tech": tech}


def build(force: bool = False) -> dict:
    files = _inputs()
    sig = _signature(files)
    if INDEX.exists() and not force:
        with gzip.open(INDEX, "rt") as h:
            cached = json.load(h)
        if cached.get("signature") == sig and cached.get("version") == VERSION:
            return cached
    passages = []
    for f in files:
        if f.suffix == ".md":
            passages += _raw_passages(f)
        elif f.parent == PRINCIPLES:
            passages += _principle_passages(f)
        elif f.suffix == ".yaml":
            passages += _yaml_passages(f)
    seen: set = set()
    for f in _gh_text_files():
        passages += _gh_passages(f, seen)
    index = {"version": VERSION, "signature": sig, "passages": passages, "real": _ogmem_reference(),
             "counts": dict(collections.Counter(p["family"].split(":")[0] for p in passages))}
    INDEX_DIR.mkdir(parents=True, exist_ok=True)
    tmp = INDEX.with_suffix(".tmp")
    with gzip.open(tmp, "wt") as h:
        json.dump(index, h, sort_keys=True)
    tmp.replace(INDEX)
    return index


# ---------------------------------------------------------------- search

FAMILY_WEIGHT = {"verbatim": 3.0, "principle": 2.0, "data": 1.0, "game-text": 0.5}


def name_pattern(names: list[str]) -> re.Pattern:
    """Whole-word, plural-tolerant. All-caps acronyms (IPM, ABM, IRN) stay case-sensitive."""
    parts = []
    for n in sorted(set(names), key=lambda s: (-len(s), s)):
        p = re.escape(n).replace(r"\ ", r"[\s_-]?")
        parts.append(f"(?-i:\\b{p}s?\\b)" if n.isupper() else f"\\b{p}(?:s|es)?\\b")
    return re.compile("|".join(parts), re.I)


def search(index: dict, names: list[str], cues: list[str], context: list[str] = (), k: int = 3,
           exclude_paths: tuple = (), pin: str = "") -> list[dict]:
    """Up to k passages that name the object AND carry at least one cue of the anomaly's kind; best first.

    Score: family weight (verbatim wiki/forum > principle > data > game text) + 1.5 per distinct cue (max 3)
    + 1 per context term + 1 when the full (longest) name matches + 0.5 when the line holds a number. Ties break on
    the passage path, so the ranking is deterministic. One passage per source id. `pin` (a substring of the passage
    that documents the threshold) puts that passage first whatever its score."""
    pat = name_pattern(names)
    full = max(names, key=len).lower()
    cue_res = [(c, re.compile(r"\b" + re.escape(c) + r"\b", re.I)) for c in cues]
    ctx_res = [re.compile(r"\b" + re.escape(c), re.I) for c in context]
    scored = []
    for p in index["passages"]:
        t = p["text"]
        m = pat.search(t)
        if not m or any(p["path"].startswith(x) for x in exclude_paths):
            continue
        hit = [c for c, r in cue_res if r.search(t)]
        if not hit:
            continue
        s = FAMILY_WEIGHT[p["family"].split(":")[0]] + 1.5 * min(3, len(hit))
        s += sum(1 for r in ctx_res if r.search(t)) + (1 if full in t.lower() else 0)
        s += 0.5 if re.search(r"\d", t) else 0
        s += 100 if pin and pin.lower() in t.lower() else 0
        scored.append((-s, p["path"], p, m.start(), hit))
    scored.sort(key=lambda x: (x[0], x[1]))
    out, ids = [], set()
    for neg, path, p, at, hit in scored:
        if p["id"] in ids:
            continue
        ids.add(p["id"])
        out.append({"id": p["id"], "path": path, "family": p["family"], "score": round(-neg % 100 if -neg >= 100 else -neg, 2),
                    "cues": hit, "snippet": snippet(p["text"], at)})
        if len(out) == k:
            break
    return out


def snippet(text: str, at: int, width: int = 260) -> str:
    if len(text) <= width:
        return text
    lo = max(0, min(at - width // 3, len(text) - width))
    s = text[lo: lo + width]
    return ("..." if lo else "") + s + ("..." if lo + width < len(text) else "")


if __name__ == "__main__":
    idx = build(force="--force" in sys.argv)
    print(json.dumps(idx["counts"]), len(idx["real"]["stocks"]), "OGMEM sides,", len(idx["real"]["tech"]), "tech rows")
