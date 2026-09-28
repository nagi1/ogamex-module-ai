#!/usr/bin/env python3
"""Strategy -> context bundle -> plan -> validated proposal. DEV TOOLING ONLY.

Runs on the dev box, never in a universe, never inside the app. The model is asked
only to turn one already-ingested strategy source into an implementation plan for
the AI module; everything a regex or a query can answer is answered here, so the
model never reads the codebase and never transcribes a number.

    scripts/strategy-pipeline.py bundle WIK-013
    scripts/strategy-pipeline.py plan   WIK-013      # no-op inside peak hours
    scripts/strategy-pipeline.py validate WIK-013
    scripts/strategy-pipeline.py run --max 6         # autonomous off-peak pass
    scripts/strategy-pipeline.py sweep --max 40      # ingest the wiki keep set (no tokens)
    scripts/strategy-pipeline.py promote              # validated plan -> tasks.db row
    scripts/strategy-pipeline.py implement WIK-020    # harness writes the code, test run locally
    scripts/strategy-pipeline.py --self-check

Config it does NOT own: the DeepSeek credential comes from the host `.env`; the
peak window lives in `config/routing.php` and is only mirrored here for the gate,
with a self-check that fails if the two drift. Nothing here ships as app code.
"""

import argparse
import datetime
import glob
import hashlib
import json
import os
import re
import sqlite3
import subprocess
import sys
import urllib.error
import urllib.parse
import urllib.request

MODULE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
RAW = os.path.join(MODULE, "plan/research/ogame/raw")
CONTEXT = os.path.join(MODULE, "plan/research/ogame/context")
PROPOSALS = os.path.join(MODULE, "plan/research/ogame/proposals")
REGISTRY = os.path.join(MODULE, "plan/research/ogame/SOURCE-REGISTRY.yaml")
STORE = os.path.join(MODULE, "plan/details/research/strategy/sources.yaml")
PRINCIPLES = os.path.join(MODULE, "plan/details/research/strategy/principles")
TASKS_DB = os.path.join(MODULE, "plan/tasks/tasks.db")
ROUTING = os.path.join(MODULE, "config/routing.php")
HOST_ENV = os.path.abspath(os.path.join(MODULE, "..", "..", ".env"))

API = "https://api.deepseek.com/chat/completions"
MODEL = "deepseek-flash"
WIKI_HOST = "ogame.fandom.com"
# The wiki answers plain GETs and ?action=raw with a Cloudflare 403; its own api.php does not.
UA = ("Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 "
      "(KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36")

# Mirror of config/routing.php 'deepseek_peak' — asserted equal by self-check and
# before every planning call, so the gate cannot quietly drift from the router.
PEAK_WEEKDAYS = [1, 2, 3, 4, 5]  # ISO: Mon-Fri
PEAK_WINDOWS = [("01:00", "04:00"), ("06:00", "10:00")]

NUMBER_MARKERS = (
    "build", "for every", "per ", "ratio", "cost", "total", "%", "requires",
    "reach", "shield", "attack", "capacity", "level",
)
PROMPT = """You plan ONE change to an OGame AI module from the context bundle below.

Rules:
- Use only the bundle. Copy numbers verbatim; never invent or recompute one.
- Choose exactly ONE doctrine variant from section 7 and name it. Never merge variants.
- If section 7 lists no variants, the source states facts rather than a doctrine: write
  `VARIANT: none (facts only)` and apply its rules to code that already exists. Do not invent a
  variant name in that case, and do not use `none` when section 7 does list variants.
- Prefer editing code already named in section 4, and name the principle id you
  supersede if you supersede one.
- Every FILES path must be a candidate path listed in section 5, or a new path that
  follows the layout section 5 states. Never invent a path, and never name a file in
  another language or another project.
- A file that does not exist yet must be listed as `- <path> (new)`; a path that
  exists must be listed without the marker. The validator enforces both.
- Do not propose a new abstraction, layer, service or config key when section 4 shows a
  principle already covers the concept.
- Never propose a per-account constant, and never encode the object universe.
- The change must be something an experienced OGame player can be seen doing.

Answer with exactly these headings, nothing before them and nothing after:

VARIANT: <the one variant, copied from section 6>
DECISION: <2-4 sentences on what to change and why>
FILES: <one `- path` line per file touched; existing paths or a new path>
PRINCIPLES: <one `- ID` line per principle superseded or extended, or `- none`>
ACCEPTANCE: <the test or scenario that proves it; not "manual check">
RISKS: <one line each>
OPEN QUESTIONS: <one line each, or `- none`>

Be short.
"""


def read(path):
    with open(path, encoding="utf-8") as handle:
        return handle.read()


def front_matter(text):
    if not text.startswith("---\n"):
        raise SystemExit("raw file has no front matter")
    block = text.split("---\n", 2)[1]
    fields = {}
    for line in block.splitlines():
        match = re.match(r"^([a-z_]+):\s*(.*)$", line)
        if match and match.group(2).strip():
            fields[match.group(1)] = match.group(2).strip()
    return fields, text.split("---\n", 2)[2]


def raw_path(source_id):
    hits = glob.glob(os.path.join(RAW, "**", f"{source_id}.md"), recursive=True)
    if not hits:
        raise SystemExit(f"no ingested raw file for {source_id}")
    return hits[0]


def rule_numbers(body):
    """Numbers on lines that state a rule or a cost, deduplicated by line."""
    rows, seen = [], set()
    for number, line in enumerate(body.splitlines(), 1):
        stripped = re.sub(r"https?://\S+", "", line).strip()
        lowered = stripped.lower()
        if not re.search(r"\d", stripped):
            continue
        if not any(marker in lowered for marker in NUMBER_MARKERS):
            continue
        if lowered.startswith(("- ", "* ")) and not any(
            marker in lowered for marker in ("for every", "per ", "ratio", "cost", "total")
        ):
            continue
        if stripped in seen:
            continue
        seen.add(stripped)
        rows.append((number, stripped))
    return rows


def dependencies(body):
    titles, seen = [], set()
    for target in re.findall(r"\[\[([^\]|#]+)", body):
        title = target.strip().replace("_", " ")
        if not title or ":" in title or title in seen:
            continue
        seen.add(title)
        titles.append(title)
    known = (read(REGISTRY) + read(STORE)).lower().replace("_", " ")
    return [(title, title.lower() in known) for title in titles]


def concepts(body):
    labels, seen = [], set()
    for target in re.findall(r"\[\[([^\]|#]+)", body):
        label = target.strip()
        if not label or ":" in label or label.lower() in seen:
            continue
        seen.add(label.lower())
        labels.append(label)
    return labels


def principle_blocks():
    blocks = []
    for path in sorted(glob.glob(os.path.join(PRINCIPLES, "*.yaml"))):
        for block in re.split(r"\n(?=- id: )", read(path)):
            pid = re.search(r"^- id: (\S+)", block)
            if pid:
                code = re.search(r"^\s*code: '?([^'\n]+)'?", block, re.M)
                blocks.append((pid.group(1), path.rsplit("/", 1)[-1][:-5],
                               code.group(1).strip() if code else "", block.lower()))
    return blocks


def existing_implementation(labels, blocks):
    """Which shipped principles already cover each concept on this page."""
    rows = []
    for label in labels:
        needle = label.lower()
        for pid, domain, code, text in blocks:
            if needle in text:
                rows.append((label, pid, domain, code))
    return rows


def open_tasks(labels):
    if not os.path.exists(TASKS_DB):
        return []
    rows, seen = [], set()
    connection = sqlite3.connect(TASKS_DB)
    for label in labels:
        for code, title, status in connection.execute(
            "select code, title, status from tasks where lower(title) like ? "
            "or lower(coalesce(notes,'')) like ? limit 4",
            (f"%{label.lower()}%", f"%{label.lower()}%"),
        ):
            if code not in seen and status in ("todo", "in_progress", "blocked"):
                seen.add(code)
                rows.append((code, status, title))
    connection.close()
    return rows


def scenario_hooks(labels):
    hooks = set()
    for label in labels:
        for word in re.findall(r"[a-z]{4,}", label.lower()):
            for path in glob.glob(os.path.join(MODULE, "resources/scenarios/*.json")):
                if word in os.path.basename(path):
                    hooks.add(os.path.basename(path))
    return sorted(hooks)


def doctrine_variants(body):
    """Headings that actually state a build rule.

    A page's H2 list is mostly prose sections ('Mechanics claims', 'Limitations'). A
    variant is a section whose body states something buildable, otherwise the planner is
    offered headings as if they were doctrines to choose between.
    """
    variants = []
    for chunk in re.split(r"^## ", body, flags=re.M)[1:]:
        title, _, section = chunk.partition("\n")
        title = title.strip()
        if title and re.search(r"\bbuild\b|\bbuilds\b|for every|per \d|ratio", section, re.I):
            variants.append(title)
    return variants


LAYOUT = """The target is the OGameX `Modules/AI` module: a Laravel module in PHP 8.5. Real
paths look like `app/Actions/*.php`, `app/Ai/**`, `app/Domain/**`, `app/Enums/**`,
`app/Support/**`, `config/*.php` (you may not add keys), `tests/Feature/**`,
`tests/Unit/**`, `resources/scenarios/*.json`, and specs under `plan/**`. There is no
TypeScript, no JavaScript and no `src/` directory in this project."""


def candidate_files(labels, limit=20):
    """Existing files whose name matches what the page talks about.

    The model never sees the repository, so without this it invents a plausible layout
    from memory. Name matching is crude on purpose: it is a shortlist to edit, not a
    ranking.
    """
    words = {word for label in labels for word in re.findall(r"[a-z]{4,}", label.lower())}
    hits = []
    for pattern in ("app/**/*.php", "tests/**/*.php", "resources/scenarios/*.json"):
        for path in glob.glob(os.path.join(MODULE, pattern), recursive=True):
            if any(word in os.path.basename(path).lower() for word in words):
                hits.append(os.path.relpath(path, MODULE))
    return sorted(set(hits))[:limit]


def build_bundle(source_id):
    path = raw_path(source_id)
    fields, body = front_matter(read(path))
    labels = concepts(body)
    blocks = principle_blocks()
    lines = [
        f"# Strategy context bundle — {source_id}",
        "",
        "Deterministic extraction. Sections 2 and 6 are authoritative: copy numbers from 2,",
        "and choose exactly one option from 6.",
        "",
        "## 1. Provenance",
        f"- source: {fields.get('id', source_id)}",
        f"- url: {fields.get('url', 'unknown')}",
        f"- topic: {fields.get('topic', 'unknown')}",
        f"- claim type: {fields.get('claim_type', 'unknown')}",
        f"- confidence: {fields.get('confidence', 'unknown')}",
        f"- raw evidence: {os.path.relpath(path, MODULE)}",
        "",
        "## 2. Numbers stated by the source (verbatim, with line)",
    ]
    for number, line in rule_numbers(body):
        lines.append(f"- `{number}` {line}")
    lines += ["", "## 3. Pages this one leans on"]
    for title, known in dependencies(body):
        lines.append(f"- {title} ({'ingested' if known else 'not yet ingested '})")
    lines += ["", "## 4. What already exists (do not duplicate or override silently)"]
    map_rows = existing_implementation(labels, blocks)
    if not map_rows:
        lines.append("- nothing matched; treat the module as having no opinion here")
    for label, pid, domain, code in map_rows[:40]:
        suffix = f" — `{code}`" if code else ""
        lines.append(f"- {label} -> {pid} ({domain}){suffix}")
    tasks = open_tasks(labels)
    if tasks:
        lines += ["", "Open tasks touching these concepts:"]
        lines += [f"- {code} [{status}] {title}" for code, status, title in tasks]
    lines += ["", "## 5. Where this code lives"]
    lines += LAYOUT.split("\n")
    candidates = candidate_files(labels)
    if candidates:
        lines += ["", "Candidate existing files (matched by name):"]
        lines += [f"- `{path}`" for path in candidates]
    hooks = scenario_hooks(labels)
    if hooks:
        lines += ["", "## 6. Scenario hooks"]
        lines += [f"- {hook}" for hook in hooks]
    lines += ["", "## 7. Doctrine variants in this source — pick exactly ONE"]
    for variant in doctrine_variants(body):
        lines.append(f"- {variant}")
    lines.append("")
    markdown = "\n".join(lines)
    os.makedirs(CONTEXT, exist_ok=True)
    out = os.path.join(CONTEXT, f"{source_id}.md")
    with open(out, "w", encoding="utf-8") as handle:
        handle.write(markdown)
    return out, markdown


def routing_window():
    """Read the peak window out of config/routing.php so the mirror can be checked."""
    text = read(ROUTING)
    periods = re.findall(r"\['(\d\d:\d\d)', '(\d\d:\d\d)'\]", text)
    days = re.search(r"'days' => \[([\d,\s]+)\]", text)
    return [tuple(p) for p in periods], [int(d) for d in days.group(1).split(",")]


def assert_window_matches_config():
    periods, days = routing_window()
    if periods != PEAK_WINDOWS or days != PEAK_WEEKDAYS:
        raise SystemExit(
            f"peak window drifted: config/routing.php says {periods} days {days}, "
            f"this script mirrors {PEAK_WINDOWS} days {PEAK_WEEKDAYS}"
        )


def in_peak(now):
    if now.isoweekday() not in PEAK_WEEKDAYS:
        return False
    clock = now.strftime("%H:%M")
    return any(start <= clock < end for start, end in PEAK_WINDOWS)


def api_key():
    text = read(os.path.abspath(HOST_ENV))
    match = re.search(r"^DEEPSEEK_API_KEY=(.+)$", text, re.M)
    if not match or not match.group(1).strip():
        raise SystemExit("DEEPSEEK_API_KEY is not set in the host .env")
    return match.group(1).strip()


def plan(source_id, extra=""):
    assert_window_matches_config()
    now = datetime.datetime.now(datetime.timezone.utc)
    if in_peak(now):
        print(f"PARK: {now:%Y-%m-%d %H:%M} UTC is inside a peak window; nothing spent.")
        return 0
    bundle_path, markdown = build_bundle(source_id)
    payload = {
        "model": MODEL,
        "messages": [
            {"role": "system", "content": PROMPT},
            {"role": "user", "content": markdown if not extra else f"{markdown}\n\n{extra}"},
        ],
    }
    request = urllib.request.Request(
        API,
        data=json.dumps(payload).encode(),
        headers={"Authorization": f"Bearer {api_key()}", "Content-Type": "application/json"},
    )
    with urllib.request.urlopen(request, timeout=300) as response:
        data = json.load(response)
    choice = data["choices"][0]
    if choice.get("finish_reason") != "stop":
        raise SystemExit(f"refusing a {choice.get('finish_reason')} answer; nothing written")
    usage = data.get("usage", {})
    reasoning = (usage.get("completion_tokens_details") or {}).get("reasoning_tokens")
    digest = hashlib.sha256(markdown.encode()).hexdigest()[:12]
    os.makedirs(PROPOSALS, exist_ok=True)
    out = os.path.join(PROPOSALS, f"{source_id}.md")
    header = "\n".join([
        f"# Plan proposal — {source_id}",
        "",
        f"- generated: {now:%Y-%m-%d %H:%M} UTC (off-peak)",
        f"- model: {data.get('model')}",
        f"- bundle: {digest} ({len(markdown)} bytes)",
        f"- tokens: prompt {usage.get('prompt_tokens')} (cached {usage.get('prompt_cache_hit_tokens')}), "
        f"output {usage.get('completion_tokens')} (reasoning {reasoning})",
        f"- status: UNVALIDATED — run `validate {source_id}` before it can become a task",
        "",
        "---",
        "",
    ])
    with open(out, "w", encoding="utf-8") as handle:
        handle.write(header + choice["message"]["content"].strip() + "\n")
    print(f"wrote {os.path.relpath(out, MODULE)}  (bundle {os.path.basename(bundle_path)})")
    return 0


def sections(text):
    """Split a proposal into its labelled blocks so only the right lines are checked."""
    out, current = {}, None
    for line in text.splitlines():
        head = re.match(r"^([A-Z][A-Z ]+):\s*(.*)$", line)
        if head:
            current = head.group(1).strip()
            out[current] = [head.group(2).strip()] if head.group(2).strip() else []
            continue
        if current and line.strip():
            out[current].append(line.strip())
    return out


def check_proposal(source_id):
    """Every deterministic reason this proposal may not become a task."""
    path = os.path.join(PROPOSALS, f"{source_id}.md")
    if not os.path.exists(path):
        return ["no proposal yet"]
    text = read(path)
    blocks = sections(text)
    failures = []

    variants = doctrine_variants(front_matter(read(raw_path(source_id)))[1])
    chosen = " ".join(blocks.get("VARIANT", [])).strip()
    facts_only = chosen.lower().startswith("none")
    if not chosen:
        failures.append("no VARIANT line")
    elif variants and facts_only:
        failures.append(f"VARIANT says 'none' but the source states {len(variants)} variant(s)")
    elif not variants and not facts_only:
        failures.append(
            f"VARIANT '{chosen}' is not one of the source's variants "
            f"(this source states no variants, so it must say 'none (facts only)')"
        )
    elif variants and not any(chosen.lower() in variant.lower() for variant in variants):
        failures.append(f"VARIANT '{chosen}' is not one of the source's variants")

    for needed in ("DECISION", "FILES", "PRINCIPLES", "ACCEPTANCE"):
        if not blocks.get(needed):
            failures.append(f"missing {needed}")

    known = {block[0] for block in principle_blocks()}
    for line in blocks.get("PRINCIPLES", []):
        if line.lower().startswith("none"):
            continue
        for pid in re.findall(r"[A-Z]{2,4}-\d+", line):
            if pid not in known:
                failures.append(f"principle {pid} does not resolve")

    module_types = (".php", ".yaml", ".json", ".md")
    for entry in blocks.get("FILES", []):
        for pathish in re.findall(r"[\w./-]+\.[a-z]+|[\w./-]+", entry.strip("- `")):
            if pathish.endswith(module_types):
                if not os.path.exists(os.path.join(MODULE, pathish)) and "new" not in entry.lower():
                    failures.append(f"file {pathish} does not exist (mark it 'new' if it should)")
                continue
            if re.search(r"\.(ts|tsx|js|py|go|rs)$", pathish):
                failures.append(f"file {pathish} is not a module file type")

    acceptance = " ".join(blocks.get("ACCEPTANCE", []))
    if acceptance and not re.search(r"test|scenario|expect|cohort", acceptance, re.I):
        failures.append("ACCEPTANCE names no test or scenario")

    # Only the decision and the files it touches can add config; a RISKS line may legitimately
    # say that no config key should be added.
    # Structural, not prose: a config change shows up as a config path in FILES. Matching
    # phrases instead fired on a decision that explicitly said "no new config key".
    for entry in blocks.get("FILES", []):
        if re.search(r"config/[\w-]+\.php", entry):
            failures.append("proposal edits module config; the module owns that")
    proposal = " ".join(blocks.get("DECISION", []))
    if re.search(r"(?<!no )per-account constant", proposal, re.I):
        failures.append("proposal adds a per-account constant (gate 1)")
    return failures


def stamp_validated(source_id):
    """The proposal file is the handoff artefact, so it reports its own state.

    Validated is not approved: it means the deterministic checks passed, nothing more.
    """
    path = os.path.join(PROPOSALS, f"{source_id}.md")
    stamp = datetime.datetime.now(datetime.timezone.utc).strftime("%Y-%m-%d %H:%M")
    text = re.sub(
        r"^- status: .*$",
        f"- status: VALIDATED {stamp} UTC (deterministic checks only — not approved)",
        read(path),
        count=1,
        flags=re.M,
    )
    with open(path, "w", encoding="utf-8") as handle:
        handle.write(text)


def validate(source_id):
    failures = check_proposal(source_id)
    for failure in failures:
        print(f"FAIL {failure}")
    if failures:
        return 1
    stamp_validated(source_id)
    print(f"PASS {source_id}: variant, refs, files and acceptance all resolve")
    return 0


def pending_sources():
    """Ingested sources that do not yet have a proposal passing every check."""
    ids = {os.path.basename(path)[:-3]
           for path in glob.glob(os.path.join(RAW, "**", "*.md"), recursive=True)}
    return [source_id for source_id in sorted(ids) if check_proposal(source_id)]


def run(max_calls, retries=1):
    """Autonomous off-peak loop: one source at a time, validated, nothing approved.

    Parking is what makes this safe to leave running: inside a peak window no call is
    made at all. A failing proposal gets one corrective retry, then the source is left
    for a human rather than looped on.
    """
    assert_window_matches_config()
    queue, calls, passed, failed = pending_sources(), 0, [], []
    print(f"queue: {len(queue)} source(s) without a passing proposal")
    for source_id in queue:
        if calls >= max_calls:
            break
        if in_peak(datetime.datetime.now(datetime.timezone.utc)):
            print("PARK: inside a peak window; stopping so nothing bills at peak rates.")
            break
        plan(source_id)
        calls += 1
        failures = check_proposal(source_id)
        for _ in range(retries if failures else 0):
            if calls >= max_calls:
                break
            plan(source_id, extra="Your previous answer failed validation:\n"
                 + "\n".join(f"- {f}" for f in failures)
                 + "\nFix exactly those points and keep every other rule.")
            calls += 1
            failures = check_proposal(source_id)
        if failures:
            failed.append(source_id)
            print(f"FAIL {source_id}: " + "; ".join(failures))
            continue
        passed.append(source_id)
        stamp_validated(source_id)
        print(f"PASS {source_id}")
    print(f"done: {len(passed)} passed, {len(failed)} left for review, {calls} call(s) spent")
    return 1 if failed else 0


def rollback(paths):
    """Delete files the harness itself wrote, so a failed answer leaves nothing behind.

    A half-verified answer is not neutral: a generated file that does not parse or reference a class
    that does not exist makes Pest fail to collect the whole suite, which would silently break every
    other test in the module.
    """
    for clean in paths:
        target = os.path.join(MODULE, clean)
        if os.path.exists(target):
            os.remove(target)
    return len(paths)


def self_check():
    assert rule_numbers("Build 1 Heavy Laser for every 10 Light Lasers."), "number rows"
    assert rule_numbers("- Direct fetch returns 403") == [], "provenance numbers skipped"
    assert [t for t, _ in dependencies("[[Ninja]] [[Category:X]] [[Ninja]]")] == ["Ninja"]
    assert [v for v in doctrine_variants("## Early Game\nBuild 1 Heavy Laser for every 10 Light Lasers.\n")] == ["Early Game"]
    assert doctrine_variants("## Limitations\nNothing buildable here at all.\n") == []
    assert_window_matches_config()
    def at(day, hour, minute):
        return datetime.datetime(2026, 9, day, hour, minute, tzinfo=datetime.timezone.utc)

    # Windows are half-open [start, end): work must stop the minute one opens and may resume the
    # minute it closes. 28 Sep 2026 is a Monday, 27 Sep a Sunday.
    assert in_peak(at(28, 1, 0)), "01:00 sharp must park"
    assert in_peak(at(28, 3, 59)), "03:59 must still be parked"
    assert not in_peak(at(28, 4, 0)), "04:00 must resume"
    assert not in_peak(at(28, 5, 30)), "between the two windows must run"
    assert in_peak(at(28, 6, 0)), "06:00 sharp must park"
    assert in_peak(at(28, 9, 59)), "09:59 must still be parked"
    assert not in_peak(at(28, 10, 0)), "10:00 must resume"
    assert not in_peak(at(28, 21, 7)), "an ordinary evening must run"
    assert not in_peak(at(27, 2, 0)), "Sunday is off-peak all day"

    probe = "app/Support/__rollback_probe.php"
    with open(os.path.join(MODULE, probe), "w", encoding="utf-8") as handle:
        handle.write("<?php\n")
    assert rollback([probe]) == 1 and not os.path.exists(os.path.join(MODULE, probe)), \
        "a failed verification must delete what the harness wrote"
    print("self-check ok (window matches config/routing.php; parks at 01:00/03:59/06:00/09:59, "
          "resumes 04:00/10:00, weekends free; rollback removes unverified output)")


def wiki_api(params):
    url = f"https://{WIKI_HOST}/api.php?" + "&".join(
        f"{key}={urllib.parse.quote(str(value))}" for key, value in params.items()
    )
    request = urllib.request.Request(url, headers={"User-Agent": UA})
    with urllib.request.urlopen(request, timeout=60) as response:
        return json.load(response)


def wiki_wikitext(title):
    data = wiki_api({"action": "parse", "page": title, "prop": "wikitext",
                     "format": "json", "formatversion": 2})
    if "parse" not in data:
        raise LookupError(data.get("error", {}).get("info", "page unavailable"))
    return data["parse"]["wikitext"]


def wiki_pages():
    """Every namespace-0 page with its categories and redirect flag (no bodies)."""
    pages, params = [], {"action": "query", "generator": "allpages", "gapnamespace": 0,
                         "gaplimit": 500, "prop": "categories|info", "cllimit": 500,
                         "format": "json", "formatversion": 2}
    while True:
        data = wiki_api(params)
        for page in data["query"]["pages"]:
            pages.append((page["title"], bool(page.get("redirect")),
                          [c["title"].removeprefix("Category:") for c in page.get("categories", [])]))
        params.update({k: v for k, v in data.get("continue", {}).items() if v != "-||"})
        if not data.get("continue"):
            return pages


def sift(pages):
    """The keep decision, exactly as reasoned in queries/wiki-corpus.md.

    A keep-category outranks a quality tag, so `Article stubs` and `Terms` never delete a
    doctrine page; only the wrong-ruleset categories are hard drops. Object pages stay out:
    the host owns them, and copying them here would create a second authority. A page that
    is neither doctrine, mechanics, object, nor noise is a concept (Formulas, Fuel
    Consumption, Distance) and is worth having for the numbers it states.
    """
    hard = {"Outdated articles", "Discontinued", "Lifeform", "Lifeform Research"}
    soft = {"Article stubs", "Terms", "Community", "Ogame User Interface",
            "Premium Service", "Den", "Humans", "Browse"}
    objects = {"Buildings", "Ships", "Technologies", "Resources", "Facilities", "Civil Ships"}
    topics = {"Strategy": "strategy", "Combat": "combat", "Rules": "rules",
              "Moon": "moon", "Defenses": "defence", "Class": "class"}
    keep = []
    for title, redirect, categories in pages:
        found = set(categories)
        if redirect or title == "Strategy" or found & hard:
            continue
        matched = [topic for category, topic in topics.items() if category in found]
        if matched:
            keep.append((title, matched[0]))
            continue
        if found & (objects | soft):
            continue
        keep.append((title, "concept"))
    return keep


def ingested_ids():
    return {os.path.basename(path)[:-3]
            for path in glob.glob(os.path.join(RAW, "**", "*.md"), recursive=True)}


def next_ids(count):
    used = [int(m.group(1)) for m in
            (re.match(r"WIK-(\d+)$", source_id) for source_id in ingested_ids()) if m]
    start = (max(used) if used else 0) + 1
    return [f"WIK-{number:03d}" for number in range(start, start + count)]


def evidence_lines(body):
    """Verbatim lines that state a rule, a ratio or a cost."""
    out = []
    for line in body.splitlines():
        text = line.strip().lstrip("*#").strip()
        if not text or text.startswith(("[[Category:", "{{", "|")):
            continue
        if re.search(r"\bbuild\b|\bbuilds\b|for every|per \d|ratio|rapid fire|"
                     r"requires|cost|total|\d%", text, re.I):
            out.append(text)
    return out


def canonical_wiki_titles():
    """Titles the canonical strategy store already registers.

    One source, one row: a wiki page already registered there must not be ingested a second
    time under a new id, so this is checked alongside the corpus registry.
    """
    text = read(STORE)
    return {match.replace("_", " ")
            for match in re.findall(r"ogame\.fandom\.com\s+`([^`?]+)", text)}


def prose_lines(body, limit=12):
    """Substantive prose, for a doctrine page that states a technique and no figures."""
    out = []
    for line in body.splitlines():
        text = line.strip().lstrip("*#").strip()
        if len(text) < 60 or text.startswith(("[[Category:", "{{", "|", "==")):
            continue
        out.append(re.sub(r"\s+", " ", re.sub(r"\[\[([^\]|]+)(\|[^\]]+)?\]\]", r"\1", text)))
        if len(out) == limit:
            break
    return out


def sweep(max_pages):
    """Ingest the wiki's keep-set pages as raw evidence. Deterministic, no model.

    A community wiki can never carry a mechanics claim, so everything lands as
    DOCUMENTED and the planner is told which claims still need the host to confirm them.
    """
    already = {field["title"] for field in registry_fields()} | canonical_wiki_titles()
    todo = [(title, topic) for title, topic in sift(wiki_pages()) if title not in already]
    print(f"keep set: {len(todo)} page(s) not yet ingested")
    ids = next_ids(len(todo))
    written = []
    for (title, topic), source_id in zip(todo[:max_pages], ids):
        try:
            body = wiki_wikitext(title)
        except (LookupError, urllib.error.URLError) as error:
            print(f"SKIP {title}: {error}")
            continue
        lines = evidence_lines(body)
        heading = "## Rule-bearing lines (verbatim)"
        if not lines and topic in ("strategy", "concept"):
            # Doctrine and concepts are what a player does and how the game computes, not only the
            # figures they quote: a page with no numbers still states something usable.
            lines = prose_lines(body)
            heading = "## Stated technique (verbatim)"
        if not lines:
            print(f"SKIP {title}: no rule-bearing lines")
            continue
        directory = os.path.join(RAW, "wiki", WIKI_HOST, topic)
        os.makedirs(directory, exist_ok=True)
        path = os.path.join(directory, f"{source_id}.md")
        now = datetime.datetime.now(datetime.timezone.utc).strftime("%Y-%m-%d")
        header = "\n".join([
            "---",
            f"id: {source_id}",
            f"title: {title}",
            "family: wiki",
            f"site: {WIKI_HOST}",
            f"topic: {topic}",
            f"url: https://{WIKI_HOST}/wiki/{title.replace(' ', '_')}",
            f"capture: verbatim wikitext via api.php, {now}",
            f"retrieved_at: {now}",
            "license: public page (CC-BY-SA)",
            "claim_type: DOCUMENTED (community wiki — never official mechanics)",
            "confidence: medium; mechanics here still need the host to confirm them",
            "---",
            "",
            f"# {title}",
            "",
            "Automatically ingested from the wiki by `strategy-pipeline.py sweep`. Lines below",
            "are verbatim and state a rule, ratio, cost or threshold; nothing was paraphrased.",
            "",
            "## Rule-bearing lines (verbatim)",
            "",
        ])
        if heading != "## Rule-bearing lines (verbatim)":
            header = header.replace("## Rule-bearing lines (verbatim)", heading)
        with open(path, "w", encoding="utf-8") as handle:
            handle.write(header + "\n".join(f"- {line}" for line in lines) + "\n")
        registry_append(source_id, title, topic, path, now)
        written.append((source_id, title, topic, len(lines)))
        print(f"{source_id} {topic:9s} {title} ({len(lines)} rule line(s))")
    print(f"swept {len(written)} page(s) into raw evidence")
    return 0


def registry_fields():
    """Registered sources, so the sweep can skip what is already ingested."""
    text = read(REGISTRY)
    return [{"id": match.group(1), "title": match.group(2)}
            for match in re.finditer(r"- id: (\S+)\n  title: (.+)$", text, re.M)]


def registry_append(source_id, title, topic, path, day):
    text = read(REGISTRY)
    marker = "# Example (delete before use"
    row = "\n".join([
        f"- id: {source_id}",
        f"  title: {title}",
        f"  url: {WIKI_HOST} `{title.replace(' ', '_')}`",
        "  capture_url: ''",
        "  family: wiki",
        f"  domains: [{topic}]",
        f"  retrieved_at: {day}",
        "  license: public page (CC-BY-SA)",
        f"  raw: {os.path.relpath(path, MODULE)}",
        "",
    ])
    with open(REGISTRY, "w", encoding="utf-8") as handle:
        handle.write(text.replace(marker, row + marker, 1))


def task_codes():
    if not os.path.exists(TASKS_DB):
        return set()
    connection = sqlite3.connect(TASKS_DB)
    codes = {row[0] for row in connection.execute("select code from tasks")}
    connection.close()
    return codes


def first_sentence(text, limit=110):
    headline = re.split(r"(?<=[.;])\s", text.strip(), maxsplit=1)[0]
    return (headline[:limit] + "…") if len(headline) > limit else headline


def promote(dry=False):
    """Give every validated proposal a task row, so the harness's output cannot be skipped.

    Uses the task CLI rather than touching the database: the framework enters the same workflow
    as every other piece of work, and the row says in its notes that it is unreviewed.
    """
    cli = os.path.join(MODULE, "plan/tasks/task.py")
    known, created, skipped = task_codes(), [], []
    for path in sorted(glob.glob(os.path.join(PROPOSALS, "*.md"))):
        source_id = os.path.basename(path)[:-3]
        if "VALIDATED" not in read(path):
            skipped.append(f"{source_id} (not validated)")
            continue
        if source_id in known:
            skipped.append(f"{source_id} (row exists)")
            continue
        blocks = sections(read(path))
        title = first_sentence(" ".join(blocks.get("DECISION", []))) or f"Apply {source_id}"
        files = " ".join(line.strip("- `") for line in blocks.get("FILES", []))
        notes = (f"auto-promoted from {os.path.relpath(path, MODULE)}; UNREVIEWED — the plan is "
                 f"deterministically validated but no human has agreed to it yet. Approach by "
                 f"variant: {' '.join(blocks.get('VARIANT', []))[:80]}")
        if dry:
            created.append(source_id)
            continue
        subprocess.run([sys.executable, cli, "add", source_id, title, "impl", "P3",
                        "--file", files, "--notes", notes], check=True, capture_output=True)
        created.append(source_id)
    print(f"promoted {len(created)}: {', '.join(created) if created else 'none'}")
    print(f"skipped {len(skipped)}: {', '.join(skipped[:8])}{' …' if len(skipped) > 8 else ''}")
    return 0


def coverage():
    """What exists on the wiki, what we hold, and what is left — with the reason for each gap."""
    pages = wiki_pages()
    keep = sift(pages)
    by_topic = {}
    for _, topic in keep:
        by_topic[topic] = by_topic.get(topic, 0) + 1
    rows = registry_fields()
    proposal_ids = {os.path.basename(path)[:-3] for path in glob.glob(os.path.join(PROPOSALS, "*.md"))}
    validated = {os.path.basename(path)[:-3] for path in glob.glob(os.path.join(PROPOSALS, "*.md"))
                 if "VALIDATED" in read(path)}
    promoted = task_codes() & (validated | proposal_ids)
    print("coverage")
    print(f"  wiki namespace-0 pages : {len(pages)}")
    print(f"  redirects (dropped)    : {sum(1 for _, redirect, _ in pages if redirect)}")
    print(f"  keep set               : {len(keep)}  by topic: {by_topic}")
    print(f"  raw sources held       : {len(rows)}")
    print(f"  proposals / validated  : {len(proposal_ids)} / {len(validated)}")
    print(f"  promoted to tasks.db   : {len(promoted)}")
    unproposed = sorted({source_id for source_id in ingested_ids() if source_id not in proposal_ids})
    print(f"  ingested but unplanned : {len(unproposed)} {unproposed[:6]}{' …' if len(unproposed) > 6 else ''}")
    return 0


IMPLEMENT_PROMPT = """You implement ONE task in the OGameX `Modules/AI` module: PHP 8.5 on Laravel.

Rules:
- Return COMPLETE file contents. Never a diff, never a fragment, never a placeholder comment
  such as `// ... rest unchanged`.
- Touch ONLY the files listed in the task. Do not add files the task did not name.
- Follow the module's conventions: actions in `app/Actions` resolved through `app()`; no `new` for
  module collaborators; no `else`/`elseif` (early returns, `match`); enums for stable values;
  comments explain *why* only; Pest tests in `tests/Unit` using the module's base test case.
- Numbers come from the plan. Never invent a number, and never add a config key, migration or table.
- The test must fail if the behaviour breaks — assert the behaviour, not that a method exists.
- Tests are Pest, not PHPUnit classes: no base class, no `extends`, no `uses(...)`. See the
  EXAMPLE TEST below for the exact shape this module uses.
- Read the plan's ACCEPTANCE line as the specification. If the code cannot satisfy it as written,
  say so in the answer instead of reinterpreting it — a test that encodes your own reading of the
  rule will happily pass while the behaviour is wrong.
- A limit the source states is a limit. Where a ratio and a cap cannot both hold at the extreme, the
  cap wins and you say so in one line. Never add a floor, a tie-break or a precedence rule that the
  source does not state just to keep both halves of the rule true.
- Test the stated bound explicitly — at it, past it, and at zero. A test that only covers the easy
  middle of a range is how a wrong implementation passes.

Answer with exactly this shape, one block per file, nothing before the first and nothing after the
last code block:

### FILE: <path exactly as the task writes it>
```php
<complete file contents>
```
"""

COMPOSE_DIR = os.path.abspath(os.path.join(MODULE, "..", "..", "local-docker-dev"))


def task_row(code):
    if not os.path.exists(TASKS_DB):
        raise SystemExit("no task database")
    connection = sqlite3.connect(TASKS_DB)
    row = connection.execute(
        "select code, title, status, notes, file_ref from tasks where code = ?", (code,)
    ).fetchone()
    connection.close()
    if row is None:
        raise SystemExit(f"no task {code} in the database")
    return {"code": row[0], "title": row[1], "status": row[2],
            "notes": row[3] or "", "file_ref": row[4] or ""}


def implement_context(code):
    """The task, its plan, and the *current* contents of the files it names.

    Bounded on purpose: only the named files are sent, truncated, so the model never sees the
    codebase and the prompt stays cacheable.
    """
    task = task_row(code)
    match = re.search(r"plan/research/ogame/proposals/(\S+?)\.md", task["notes"])
    proposal_path = os.path.join(PROPOSALS, f"{match.group(1)}.md") if match else None
    blocks = sections(read(proposal_path)) if proposal_path else {}
    paths = [line.strip("- `") for line in blocks.get("FILES", [])] or [task["file_ref"]]
    paths = [path for path in paths if path]

    parts = [f"TASK {task['code']} — {task['title']}", "", "THE PLAN:",
             read(proposal_path) if proposal_path else "(the task has no proposal attached)", ""]

    # One real test from the module, so the harness copies the house style instead of inventing a
    # base class the module does not have. This is the difference between a test that runs and one
    # that cannot even be collected.
    for example in sorted(glob.glob(os.path.join(MODULE, "tests/Unit/*Test.php"))):
        parts += [f"EXAMPLE TEST {os.path.relpath(example, MODULE)} (copy this shape):",
                  "```php", read(example)[:1800], "```", ""]
        break

    for path in paths[:6]:
        full = os.path.join(MODULE, path)
        if os.path.exists(full):
            parts += [f"EXISTING FILE {path}:", "```php", read(full)[:6000], "```", ""]
        else:
            parts += [f"{path} does not exist yet — create it.", ""]
    return task, paths, "\n".join(parts)


def implement(code):
    """Have the harness write one task's code, then verify it locally.

    Fail-closed on purpose: it only creates files that do not exist yet (editing live code by hand
    stays a human act), refuses anything outside the module, and runs the new test itself. A task is
    never marked done here — that still needs the full gate.
    """
    assert_window_matches_config()
    now = datetime.datetime.now(datetime.timezone.utc)
    if in_peak(now):
        print(f"PARK: {now:%Y-%m-%d %H:%M} UTC is inside a peak window; nothing spent.")
        return 0

    task, paths, context = implement_context(code)
    payload = {"model": MODEL, "messages": [
        {"role": "system", "content": IMPLEMENT_PROMPT},
        {"role": "user", "content": context},
    ]}
    request = urllib.request.Request(
        API,
        data=json.dumps(payload).encode(),
        headers={"Authorization": f"Bearer {api_key()}", "Content-Type": "application/json"},
    )
    with urllib.request.urlopen(request, timeout=300) as response:
        data = json.load(response)
    choice = data["choices"][0]
    if choice.get("finish_reason") != "stop":
        raise SystemExit(f"refusing a {choice.get('finish_reason')} answer; nothing written")

    written, refused, tests = [], [], []
    for path, content in re.findall(
        r"### FILE:\s*(\S+?)\s*\n+```[a-zA-Z]*\n(.*?)```", choice["message"]["content"], re.S
    ):
        clean = path.strip("`")
        if clean.startswith(("/", "..")) or not clean.endswith((".php", ".yaml", ".json", ".md")):
            refused.append(f"{clean} (outside the module or not a module file type)")
            continue
        full = os.path.join(MODULE, clean)
        if os.path.exists(full):
            refused.append(f"{clean} (already exists — this step only creates new files)")
            continue
        os.makedirs(os.path.dirname(full), exist_ok=True)
        with open(full, "w", encoding="utf-8") as handle:
            handle.write(content.rstrip() + "\n")
        written.append(clean)
        if "/tests/" in f"/{clean}":
            tests.append(os.path.basename(clean)[:-4])

    # A new file that does not even parse is worse than no file: Pest fails to collect the whole
    # suite, so one bad answer silently breaks every other test in the module.
    broken = []
    for clean in written:
        if not clean.endswith(".php"):
            continue
        lint = subprocess.run(["docker", "compose", "exec", "-T", "ogamex-app", "php", "-l",
                               f"/var/www/Modules/AI/{clean}"],
                              cwd=COMPOSE_DIR, capture_output=True, text=True)
        if lint.returncode != 0:
            broken.append(f"{clean}: {(lint.stdout + lint.stderr).strip().splitlines()[0]}")

    usage = data.get("usage", {})
    print(f"{code} [{task['status']}]: wrote {len(written)}, refused {len(refused)}")
    for path in written:
        print(f"  + {path}")
    for reason in refused:
        print(f"  ! {reason}")
    print(f"  tokens: prompt {usage.get('prompt_tokens')} "
          f"(cached {usage.get('prompt_cache_hit_tokens')}) output {usage.get('completion_tokens')}")
    for reason in broken:
        print(f"  X does not parse — {reason}")

    if not tests or broken:
        print(f"  unverified, rolled back {rollback(written)} harness file(s)")
        return 1
    for name in tests:
        result = subprocess.run(
            ["docker", "compose", "exec", "-T", "ogamex-app", "sh", "-lc",
             f"cd /var/www && ./vendor/bin/pest --testsuite=Modules --filter={name}"],
            cwd=COMPOSE_DIR, capture_output=True, text=True,
        )
        output = result.stdout + result.stderr
        summary = [line.strip() for line in output.splitlines() if "Tests:" in line]
        if summary:
            print(f"  {name}: {'PASS' if result.returncode == 0 else 'FAIL'} {summary[-1]}")
            continue
        # No summary means the suite could not be collected at all — a bad sibling file is the usual
        # cause, and leaving this answer behind would keep the whole module unrunnable.
        complaint = [line.strip() for line in output.splitlines() if line.strip()][-1:] or [""]
        print(f"  {name}: FAIL not collectable — {complaint[0][:140]}")
        print(f"  unverified, rolled back {rollback(written)} harness file(s)")
        return 1
    return 0


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("command", nargs="?",
                        choices=["bundle", "plan", "validate", "run", "sweep", "promote",
                                 "coverage", "implement"])
    parser.add_argument("source", nargs="?")
    parser.add_argument("--max", type=int, default=4, help="run/sweep: work allowed this pass")
    parser.add_argument("--dry", action="store_true", help="promote: report without writing rows")
    parser.add_argument("--self-check", action="store_true")
    args = parser.parse_args(argv)

    if args.self_check:
        self_check()
        return 0
    if args.command == "run":
        return run(args.max)
    if args.command == "sweep":
        return sweep(args.max)
    if args.command == "promote":
        return promote(args.dry)
    if args.command == "coverage":
        return coverage()
    if args.command == "implement":
        return implement(args.source)
    if not args.command or not args.source:
        parser.error("give a command and a source id, or --self-check")
    if args.command == "bundle":
        out, _ = build_bundle(args.source)
        print(f"wrote {os.path.relpath(out, MODULE)}")
        return 0
    if args.command == "plan":
        return plan(args.source)
    return validate(args.source)


if __name__ == "__main__":
    sys.exit(main())
