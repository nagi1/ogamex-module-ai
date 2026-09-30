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
import atexit
import difflib
import glob
import hashlib
import json
import os
import random
import re
import signal
import sqlite3
import subprocess
import sys
import time
import urllib.error
import urllib.parse
import urllib.request

MODULE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
# The host application, one level above the module directory: the model is never shown the repository,
# so the host class names it may call have to be listed for it.
ROOT = os.path.dirname(os.path.dirname(MODULE))
RAW = os.path.join(MODULE, "plan/research/ogame/raw")
CONTEXT = os.path.join(MODULE, "plan/research/ogame/context")
PROPOSALS = os.path.join(MODULE, "plan/research/ogame/proposals")
IMPLEMENTED = os.path.join(MODULE, "plan/research/ogame/implemented")
ATTEMPTS = os.path.join(MODULE, "plan/research/ogame/attempts")

# How many tries one task may spend in a window, and how long before its budget resets. Bounded so a
# hopeless task cannot drain a night, but never terminal: the task returns on its own afterwards.
MAX_ATTEMPTS = 3
COOLOFF_SECONDS = 12 * 3600
STATUS = os.path.join(MODULE, "plan/research/ogame/harness-status.json")
# One heartbeat file per worker. The shared STATUS alone cannot describe parallel shards: every worker
# wrote the same file, so a six-worker pass showed up as a single agent doing a single thing.
WORKERS = os.path.join(MODULE, "plan/research/ogame/workers")

# Exclusive claims, one file per claimed path. Parallel implement workers are not independent: the
# same file can appear in two plans (thirteen files in this corpus do), and the loser's rollback
# restores the copy it backed up, deleting the winner's slice while the winner's marker still says it
# was proved. A claim is taken before the paid call, so a worker that cannot have the file spends
# nothing, and a claim left behind by a killed worker ages out rather than blocking forever.
CLAIMS = os.path.join(MODULE, "plan/research/ogame/claims")
CLAIM_STALE_SECONDS = 30 * 60

# Ceiling on one app-side verification run. Pest has no timeout of its own, so a hung suite would hold
# the shared-database lane until its claim aged out.
APP_TIMEOUT_SECONDS = 15 * 60

# Model-call pacing. The account ceiling DeepSeek publishes is a *concurrency* limit -- 2,500 live
# connections for deepseek-flash -- counted from send until the response completes. The cohorts share
# this key, so the harness keeps its own ceiling far below it and paces every call through a shared
# slot file, because the workers are separate processes.
MODEL_SLOTS = os.path.join(MODULE, "plan/research/ogame/model-slots")
MODEL_CONCURRENCY = int(os.environ.get("MODEL_CONCURRENCY", "4"))
SLOT_STALE_SECONDS = 15 * 60
MODEL_ATTEMPTS = 5
MODEL_RETRY_CODES = (429, 500, 502, 503)
MODEL_TIMEOUT_SECONDS = 300

# Every claim this process holds. Kept so a signal can release them: Python does not run `atexit` on
# SIGTERM, and the harness stops workers exactly that way (`trap 'kill 0' EXIT`, `pkill`) -- so every
# restart used to leave claims behind that blocked their files for the full stale window.
HELD_CLAIMS = []
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
# The section headings this pipeline writes into a raw capture. They hold our own verbatim
# extraction of the source, so they are not the source's structure and must never be read back as
# doctrine variants (see doctrine_variants).
CAPTURE_HEADINGS = ("Rule-bearing lines (verbatim)", "Stated technique (verbatim)")
PROMPT = """You plan ONE change to an OGame AI module from the context bundle below.

Rules:
- Use only the bundle. Copy numbers verbatim; never invent or recompute one.
- Choose exactly ONE doctrine variant from section 7 and name it. Never merge variants.
- The VARIANT text must match section 7 word for word; section 7 is the only place variants are
  listed. If section 7 is empty the source states facts rather than a doctrine, so `VARIANT: none
  (facts only)` is the only correct answer -- never invent a variant name, and never use `none`
  when section 7 does list variants.
- PRINCIPLES may cite only ids listed in section 8, because an id that does not resolve invalidates
  the whole proposal. If none of them applies, write `- none`.
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

VARIANT: <copied word for word from section 7, or `none (facts only)` when section 7 is empty>
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

    The headings this pipeline writes into a capture are skipped: they hold our own verbatim
    extraction of the same page, so reading them back offered the planner a "variant" called
    'Rule-bearing lines (verbatim)' on 71 of 122 sources -- and then rejected every answer for not
    matching a variant the source never stated.
    """
    variants = []
    for chunk in re.split(r"^## ", body, flags=re.M)[1:]:
        title, _, section = chunk.partition("\n")
        title = title.strip()
        if title in CAPTURE_HEADINGS:
            continue
        if title and re.search(r"\bbuild\b|\bbuilds\b|for every|per \d|ratio", section, re.I):
            variants.append(title)
    return variants


LAYOUT = """The target is the OGameX `Modules/AI` module: a Laravel module in PHP 8.5. Real
paths look like `app/Actions/*.php`, `app/Ai/**`, `app/Domain/**`, `app/Enums/**`,
`app/Support/**`, `config/*.php` (you may not add keys), `tests/Feature/**`,
`resources/behavior/*.yaml` (policy values live here, never inline in PHP),
`resources/scenarios/*.json`, and specs under `plan/**`. Tests go in `tests/Feature`
and nowhere else. There is no TypeScript, no JavaScript and no `src/` directory in
this project."""


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
    # The ids that resolve. Without them the planner cites a plausible-looking id the validator
    # rejects, and the whole proposal is thrown away for a naming reason rather than a design one.
    lines += ["", "## 8. Principle ids that resolve (cite only these, or `none`)"]
    lines.append(", ".join(sorted({pid for pid, _, _, _ in blocks})) or "- none")
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
    data = model_call(payload, purpose=f"planning {source_id}")
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


def affected_tests(written):
    """Test files that name a class this attempt wrote or edited, other than its own test.

    A slice checked only by its own test can still break the module. The harness rewrote
    `BuildAiPilotReportAction::handle()` away, the slice's own test passed, and the operator console
    answered 500 to every page while nothing noticed (found 30 Sep 2026). The tests that name the
    touched classes are the ones that can feel the change, so they run with it.
    """
    names = [os.path.basename(path)[:-4] for path in written
             if path.startswith("app/") and path.endswith(".php")]
    if not names:
        return []

    hits = subprocess.run(
        ["grep", "-rlE", "|".join(re.escape(name) for name in names), "tests/", "--include=*.php"],
        cwd=MODULE, capture_output=True, text=True,
    ).stdout.split()

    return [os.path.basename(hit)[:-4] for hit in hits]


def duplicate_class(content, clean):
    """Why this file may not be written because the module already has that class, or None.

    Two classes that own one decision are two authorities, and the pair drifts: the harness wrote
    `app/Ai/Defense/DefenseCompositionPlanner.php` beside the real `Domain/Decision` planner and
    started planning walls from whichever one the test happened to load (found 30 Sep 2026).
    Wiring a rule in means editing the class that owns it, not writing a rival next to it.
    """
    declared = re.search(r"^(?:final\s+|abstract\s+|readonly\s+)*class\s+(\w+)", content, re.M)
    if declared is None:
        return None

    name = declared.group(1)
    hits = subprocess.run(
        ["grep", "-rlE", rf"^[a-z ]*class {name}\b", "app/", "--include=*.php"],
        cwd=MODULE, capture_output=True, text=True,
    ).stdout.split()

    for hit in hits:
        if os.path.abspath(os.path.join(MODULE, hit)) != os.path.abspath(os.path.join(MODULE, clean)):
            return f"{hit} already declares class {name} — edit it instead of writing a second one"

    return None


def path_refusal(clean):
    """Why this path may not be written, or None. One rule, used by the validator and the writer.

    They used to disagree: a plan writing `tests/Unit/**` passed validation, was promoted into a task
    row, and was then refused when the harness tried to write it — so a doomed plan cost a task, an
    attempt and a cool-off that the validator could have rejected for nothing. Checking the shape at
    validation time means the corrective retry, which already carries the validator's complaints, gets
    to fix the path in the same pass.
    """
    if clean.startswith(("/", "..")) or not clean.endswith((".php", ".yaml", ".json", ".md")):
        return "outside the module or not a module file type"
    if clean.startswith("Modules/"):
        # Repo-relative, not module-relative. Written out, it lands in a phantom `Modules/AI/Modules/AI`
        # tree where nothing can collect it: the test run then reads "not collectable" and the slice
        # burns an attempt on a file the module never got (found 29 Sep, eight stray files).
        return "path is relative to the module root, so it must not start with Modules/"
    if re.search(r"[A-Z]{2,4}-\d+", os.path.basename(clean), re.I):
        # A file named after the wiki page it came from says nothing about what the file does, and the
        # source id belongs in the plan, not in the codebase.
        return "named after a source page — name it for what it does"
    if clean.startswith("tests/Unit/"):
        # Not a style preference: a Unit test can bless wrong logic, and on this module that means a
        # live cohort playing by a rule nobody checked.
        return "unit tests are not accepted — write a Feature test instead"
    if clean.startswith("resources/behavior/") and not clean.endswith((".yaml", ".yml", ".json")):
        # A data file a modder edits has to be data. PHP there is code wearing a data file's name.
        return "data must be YAML or JSON so a modder can edit it without code"
    if clean.startswith("app/Ai/") and not clean.startswith("app/Ai/Agents/"):
        # A parallel tree grew here: 16 unreferenced classes, a second DefenseCompositionPlanner, each
        # with a test as its only caller (deleted 30 Sep 2026). The two agent classes the language
        # gateways use are all that belongs here.
        return "app/Ai/ holds only the LLM agent classes — wire the rule into the class that owns it"

    return None


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
        failures.append(f"VARIANT '{chosen}' is not one of the source's variants: {variants}")

    for needed in ("DECISION", "FILES", "PRINCIPLES", "ACCEPTANCE"):
        if not blocks.get(needed):
            failures.append(f"missing {needed}")

    known = {block[0] for block in principle_blocks()}
    for line in blocks.get("PRINCIPLES", []):
        if line.lower().startswith("none"):
            continue
        for pid in re.findall(r"\b[A-Z]{2,6}-\d+\b", line):
            if pid in known:
                continue
            # A near miss is a typo, not a design error (CRASH-006 written as RASH-006). Saying so
            # lets the corrective retry fix it instead of the whole proposal being thrown away.
            near = difflib.get_close_matches(pid, known, n=1, cutoff=0.75)
            failures.append(f"principle {pid} does not resolve"
                            + (f" — did you mean {near[0]}?" if near else " (cite an id from section 8)"))

    module_types = (".php", ".yaml", ".json", ".md")
    for entry in blocks.get("FILES", []):
        for pathish in re.findall(r"[\w./-]+\.[a-z]+|[\w./-]+", entry.strip("- `")):
            if pathish.endswith(module_types):
                refusal = path_refusal(pathish)
                if refusal:
                    failures.append(f"file {pathish}: {refusal}")
                    continue
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
    if re.search(r"(?<!no )(?<!not a )(?<!never a )per-account constant", proposal, re.I):
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


def review_marker(source_id):
    return os.path.join(CONTEXT, f"{source_id}.review")


def pending_sources():
    """Ingested sources that do not yet have a proposal passing every check.

    A source that failed validation is not abandoned: its marker only holds it back until the cool-off
    ages out, and then it is attempted again with the failures from last time.
    """
    ids = {os.path.basename(path)[:-3]
           for path in glob.glob(os.path.join(RAW, "**", "*.md"), recursive=True)}

    def waiting(source_id):
        marker = review_marker(source_id)
        if not os.path.exists(marker):
            return False
        age = int(time.time() - os.path.getmtime(marker))
        if age > COOLOFF_SECONDS:
            os.remove(marker)
            return False

        return True

    return [source_id for source_id in sorted(ids)
            if not waiting(source_id) and check_proposal(source_id)]


def normalize_typo_ids(source_id):
    """Repair a principle id that is one letter away from exactly one real id.

    The planner writes CRASH-006 as RASH-006 often enough that a whole proposal would otherwise be
    thrown away over a spelling, and it keeps doing it when told. Only an unambiguous single near
    match is rewritten, and the repair is written into the file so a reader can see it happened.
    """
    path = os.path.join(PROPOSALS, f"{source_id}.md")
    if not os.path.exists(path):
        return

    text = read(path)
    known = {block[0] for block in principle_blocks()}
    repairs = {}
    for pid in set(re.findall(r"\b[A-Z]{2,6}-\d+\b", text)):
        if pid in known:
            continue
        # A clearly closest candidate, not merely a close one: RASH-004 sits 0.94 from CRASH-004 but
        # also 0.82 from CRASH-014, and rewriting the wrong one would silently change what is cited.
        ranked = sorted(known, key=lambda candidate: difflib.SequenceMatcher(None, pid, candidate).ratio())
        best, runner_up = ranked[-1], ranked[-2]
        best_score = difflib.SequenceMatcher(None, pid, best).ratio()
        second_score = difflib.SequenceMatcher(None, pid, runner_up).ratio()
        if best_score >= 0.85 and best_score - second_score >= 0.05:
            repairs[pid] = best

    if not repairs:
        return

    for wrong, right in repairs.items():
        text = text.replace(wrong, right)

    note = ", ".join(f"{wrong} -> {right}" for wrong, right in repairs.items())
    with open(path, "w", encoding="utf-8") as handle:
        handle.write(text.rstrip() + f"\n\n<!-- ids repaired before validation: {note} -->\n")
    print(f"  repaired spelling: {note}")


def parse_shard(value):
    """`k/N` into `(k, N)`, or None. One queue, N workers, no source planned twice.

    Planning is network-bound: one worker waits on the model for tens of seconds per call, so a
    sixty-source queue is an hour of nothing but waiting. Slicing the same sorted queue by position
    gives every worker a disjoint share with no lock file and no claim table to keep consistent.
    """
    if not value:
        return None
    index, _, count = value.partition("/")
    if not index.isdigit() or not count.isdigit():
        raise SystemExit(f"--shard wants k/N, got '{value}'")
    index, count = int(index), int(count)
    if count < 1 or index >= count:
        raise SystemExit(f"--shard {value}: k must be below N, and N at least 1")
    return index, count


def run(max_calls, retries=2, shard=None):
    """Autonomous off-peak loop: one source at a time, validated, nothing approved.

    Parking is what makes this safe to leave running: inside a peak window no call is
    made at all. A failing proposal gets corrective retries carrying the validator's own
    complaints, then a cool-off -- the source is picked up again later on its own, never
    left behind for someone else to finish.
    """
    assert_window_matches_config()
    queue, calls, passed, failed = pending_sources(), 0, [], []
    if shard is not None:
        index, count = shard
        queue = [source_id for position, source_id in enumerate(queue) if position % count == index]
    print(f"queue: {len(queue)} source(s) without a passing proposal")
    publish("planning", f"{len(queue)} source(s) pending")
    for source_id in queue:
        if calls >= max_calls:
            break
        if in_peak(datetime.datetime.now(datetime.timezone.utc)):
            print("PARK: inside a peak window; stopping so nothing bills at peak rates.")
            break
        plan(source_id)
        calls += 1
        normalize_typo_ids(source_id)
        failures = check_proposal(source_id)
        for _ in range(retries if failures else 0):
            if calls >= max_calls:
                break
            plan(source_id, extra="Your previous answer failed validation:\n"
                 + "\n".join(f"- {f}" for f in failures)
                 + "\nFix exactly those points and keep every other rule.")
            calls += 1
            normalize_typo_ids(source_id)
            failures = check_proposal(source_id)
        if failures:
            failed.append(source_id)
            print(f"FAIL {source_id}: " + "; ".join(failures))
            # Recorded, not retried forever: the next pass skips it until the cool-off ages out.
            with open(review_marker(source_id), "w", encoding="utf-8") as handle:
                handle.write("failed validation twice; retried automatically after the cool-off:\n- "
                             + "\n- ".join(failures) + "\n")
            continue
        passed.append(source_id)
        stamp_validated(source_id)
        print(f"PASS {source_id}")
    print(f"done: {len(passed)} passed, {len(failed)} left for review, {calls} call(s) spent")
    return 1 if failed else 0


def rollback(paths, backups=None):
    """Undo what this attempt wrote, so a failed answer leaves nothing behind.

    A file that existed is restored to its previous contents rather than deleted, because this stage
    edits existing code as well as creating new files: a half-verified answer is not neutral, and one
    that does not parse makes Pest fail to collect the whole suite.
    """
    backups = backups or {}

    for clean in paths:
        target = os.path.join(MODULE, clean)
        if target in backups:
            with open(target, "w", encoding="utf-8") as handle:
                handle.write(backups[target])
            continue
        if os.path.exists(target):
            os.remove(target)

    return len(paths)


def seconds_until_offpeak(now):
    """Seconds until the next off-peak instant, so a parked harness can resume by itself."""
    if not in_peak(now):
        return 0

    step, total = 60, 0
    while in_peak(now + datetime.timedelta(seconds=total)) and total < 86_400:
        total += step

    return total


def report_peak():
    """Exit 3 while the peak window is open, so a driver can stop before any stage, not just mid-task.

    The per-call gates stop a call starting inside a window, but a pass also sweeps and promotes, and
    those stages sit before the first gate. This is the single answer a driver needs at the top of a
    pass.
    """
    assert_window_matches_config()
    now = datetime.datetime.now(datetime.timezone.utc)

    if in_peak(now):
        print(f"PARK: {now:%Y-%m-%d %H:%M} UTC is inside a peak window; nothing spent.")
        return 3

    return 0


def wait_until_offpeak():
    """Sleep out the peak window, re-reading the clock as it goes, then let the caller carry on.

    It re-derives the remaining time every minute instead of sleeping one computed total. A fixed
    total assumes the sleep and the clock agree, and on this machine they do not: the guest clock runs
    slow enough that a `sleep 60` takes about 63 seconds, so a four hour wait overshot by ten minutes
    and the harness sat parked after off-peak had already begun.
    """
    assert_window_matches_config()

    while True:
        now = datetime.datetime.now(datetime.timezone.utc)
        remaining = seconds_until_offpeak(now)

        if remaining == 0:
            print("peak over; resuming")
            publish("resuming", "peak window closed")
            return 0

        resume = now + datetime.timedelta(seconds=remaining)
        print(f"PARK: peak until {resume:%H:%M} UTC")
        publish("parked", f"peak window; resumes {resume:%H:%M} UTC")
        time.sleep(min(60, remaining))


def record_failure(code, reason):
    """Count a failed attempt and keep its output for the next one.

    There is no terminal state: this runs unattended, so a failure means try again carrying the reason,
    not stop and wait for someone. The counter bounds what one bad task can cost in a window, and then
    the budget ages out so the task comes back on its own instead of being abandoned.
    """
    os.makedirs(ATTEMPTS, exist_ok=True)
    path = os.path.join(ATTEMPTS, f"{code}.count")
    attempts = int(read(path).strip() or 0) + 1 if os.path.exists(path) else 1

    with open(path, "w", encoding="utf-8") as handle:
        handle.write(str(attempts))
    with open(os.path.join(ATTEMPTS, f"{code}.log"), "w", encoding="utf-8") as handle:
        handle.write(reason)

    print(f"  attempt {attempts} failed; retrying with this output")


def cooling_off(code):
    """How long this task should wait before its next attempt, or 0 when it may be attempted now."""

    path = os.path.join(ATTEMPTS, f"{code}.count")
    if not os.path.exists(path):
        return 0

    age = int(time.time() - os.path.getmtime(path))
    if age > COOLOFF_SECONDS:
        # The budget ages out on its own, so a hard task is retried rather than left behind.
        os.remove(path)
        return 0

    attempts = int(read(path).strip() or 0)

    return max(0, COOLOFF_SECONDS - age) if attempts >= MAX_ATTEMPTS else 0


def plan_numbers(source_id):
    """The figures the source states, read from the bundle's verbatim lines.

    The bundle prints each line as a backticked capture line number followed by the text, so the
    leading token is where the line sits in the capture and not a value at all. Taking it would have
    the guard looking for the number 30 in PHP and calling a timeout policy. The figures are in the
    text after it: "1-2 per turret", "50-70 missiles", "level 10 or more".
    """
    bundle = os.path.join(CONTEXT, f"{source_id}.md")
    if not os.path.exists(bundle):
        return []

    sections = read(bundle).split("## 2.", 1)
    if len(sections) < 2:
        return []

    body = sections[1].split("\n## ", 1)[0]
    stated = []

    for line in body.splitlines():
        text = re.sub(r"^- `\d+`\s*", "", line)
        for token in re.findall(r"\d+(?:[.,]\d+)?", text):
            # Single digits are structure -- a level, an index -- not tunable policy.
            if float(token.replace(",", "")) >= 3:
                stated.append(token)

    return sorted(set(stated))


def inlined_policy(paths, numbers):
    """Source numbers written straight into PHP instead of a data file.

    A file that reads its values from `resources/behavior` is left alone: that is the point. This is
    deliberately tied to the source's own numbers rather than to every literal in the file, so it
    stays silent about structural values -- a limit, a sentinel date, an initial zero -- and speaks
    only about policy.
    """
    if not numbers:
        return []

    found = []
    for clean in paths:
        if not clean.startswith("app/") or not clean.endswith(".php"):
            continue
        full = os.path.join(MODULE, clean)
        text = read(full) if os.path.exists(full) else ""
        if "resources/behavior" in text or "Yaml::parse" in text:
            continue

        for number in numbers:
            bare = number.rstrip("%")
            if re.search(rf"(?<![\w.]){re.escape(bare)}(?![\w.])", text):
                found.append(f"{clean}: {bare}")

    return found


def unreachable_files(paths, backups):
    """Module code files that no runtime code calls.

    A class nothing calls cannot be executed by a cohort, so it cannot be validated live and is not a
    delivery -- it is dead code. This is the deterministic half of "it must run on the accounts":
    the other half is the Feature test driving the wired path rather than constructing the class.

    A file this attempt created is not a caller. A slice that writes two classes calling each other
    has wired nothing into the account, and counting them kept a whole parallel tree of dead classes
    alive under app/Ai/ (found 30 Sep 2026, including a second DefenseCompositionPlanner shadowing
    the real one). Only code that already existed before the attempt counts as the runtime caller.
    """
    created = {os.path.abspath(os.path.join(MODULE, clean)) for clean in paths
               if os.path.join(MODULE, clean) not in backups}

    unreachable = []

    for clean in paths:
        if not clean.startswith("app/") or not clean.endswith(".php"):
            continue
        full = os.path.join(MODULE, clean)
        if not os.path.exists(full):
            continue

        klass = os.path.basename(clean)[:-4]
        hits = subprocess.run(["grep", "-rl", klass, "app/", "--include=*.php"],
                              cwd=MODULE, capture_output=True, text=True).stdout.split()
        callers = [hit for hit in hits
                   if os.path.abspath(os.path.join(MODULE, hit)) != os.path.abspath(full)
                   and os.path.abspath(os.path.join(MODULE, hit)) not in created]

        if not callers:
            unreachable.append(clean)

    return unreachable


def run_in_app(command):
    """Run one command inside the app container, in the shared-database lane.

    Every Pest run and scenario replay goes through here, the pre-flight included: two suites at once
    contend on the same rows, and a test that fails for that reason reads exactly like a test that
    failed because the generated code is wrong -- which sends the retry off chasing a phantom.
    """
    lane = await_claim("verify:shared-test-database")
    if lane is None:
        return 1, "the verification lane stayed held; nothing ran"

    try:
        result = subprocess.run(
            ["docker", "compose", "exec", "-T", "ogamex-app", "sh", "-lc", f"cd /var/www && {command}"],
            cwd=COMPOSE_DIR, capture_output=True, text=True, timeout=APP_TIMEOUT_SECONDS,
        )
        return result.returncode, result.stdout + result.stderr
    except subprocess.TimeoutExpired:
        # A run that never returns would hold the lane until the claim ages out, which stops every
        # other worker's verification. Bounded instead, and reported as a failure so the attempt is
        # recorded rather than silently swallowed.
        return 1, f"{command} did not finish within {APP_TIMEOUT_SECONDS}s and was killed"
    finally:
        release_claims([lane])


def module_test_passes(relative_path):
    """Run one module test file and say whether it passes. Costs nothing but container time."""
    case = os.path.basename(relative_path)[:-4]

    return run_in_app(f"./vendor/bin/pest --testsuite=Modules --filter={case}")[0] == 0


def previous_failure(code):
    """What the last attempt failed with, or empty when this is the first try."""
    path = os.path.join(ATTEMPTS, f"{code}.log")

    return read(path).strip() if os.path.exists(path) else ""


def publish(phase, detail=""):
    """Say what the harness is doing, so a watch page never has to guess from file mtimes.

    Written inside the module on purpose: the harness runs on the host while the page is served from
    a container, and those two do not share a /tmp namespace -- which is exactly why a live harness
    could read as "quiet" for minutes.

    Also written per process, so parallel shards are visible as a fleet rather than as whichever
    worker spoke last. A worker that dies leaves a file behind, which is why stale ones are pruned
    here and ignored by the page: a heartbeat nobody refreshes must not read as work in progress.
    """
    os.makedirs(os.path.dirname(STATUS), exist_ok=True)
    payload = {
        "pid": os.getpid(),
        "at": datetime.datetime.now(datetime.timezone.utc).strftime("%Y-%m-%d %H:%M:%S"),
        "phase": phase,
        "detail": detail,
    }
    with open(STATUS, "w", encoding="utf-8") as handle:
        json.dump(payload, handle)

    os.makedirs(WORKERS, exist_ok=True)
    # Named after the worker, not the process: the implement stage forks one process per task, so a
    # pid per file turned three implementing shards into thirty rows of "workers" on the page.
    worker = os.environ.get("HARNESS_WORKER") or str(os.getpid())
    with open(os.path.join(WORKERS, f"{worker}.json"), "w", encoding="utf-8") as handle:
        json.dump(payload, handle)

    stale = time.time() - 3600
    for path in glob.glob(os.path.join(WORKERS, "*.json")):
        # Shards sweep the same directory at once, so a file can be gone between the glob and the
        # read, and again between the read and the remove. Losing that race is not an error: it left
        # a traceback in the harness log every pass that two shards ended together.
        try:
            if os.path.getmtime(path) < stale:
                os.remove(path)
        except FileNotFoundError:
            continue


def self_check():
    # Parallel workers are only safe because a claim is exclusive: prove the refusal, not just the
    # happy path, and prove that a released claim can be taken again.
    mine, holder = claim_paths([f"self-check:{os.getpid()}"])
    assert mine and holder is None, "the first claim on a free path must succeed"
    assert claim_paths([f"self-check:{os.getpid()}"])[0] is None, \
        "a second claim on a held path must be refused"
    release_claims(mine)
    again, _ = claim_paths([f"self-check:{os.getpid()}"])
    assert again, "a released path must be claimable again"
    release_claims(again)

    assert rule_numbers("Build 1 Heavy Laser for every 10 Light Lasers."), "number rows"
    assert rule_numbers("- Direct fetch returns 403") == [], "provenance numbers skipped"
    assert [t for t, _ in dependencies("[[Ninja]] [[Category:X]] [[Ninja]]")] == ["Ninja"]
    assert [v for v in doctrine_variants("## Early Game\nBuild 1 Heavy Laser for every 10 Light Lasers.\n")] == ["Early Game"]
    assert doctrine_variants("## Limitations\nNothing buildable here at all.\n") == []

    # The writer is sent the row's evidence and a real fixture, not a bare title: 22 rows spent three
    # attempts each inventing the columns of a table a test in this suite already builds.
    long_notes = notes_block("evidence " * 2000)
    assert long_notes.endswith("(notes truncated)") and len(long_notes) < 4100, "notes are bounded, not dropped"
    assert fixture_examples(["app/Models/AiProfile.php"], skip="tests/Feature/AIRouteTest.php"), \
        "a slice that edits a model must be shown a test that already builds it"
    model_task = {"title": "AiProfile persona", "notes": "", "file_ref": "app/Models/AiProfile.php"}
    assert migrations_for(model_task, ["app/Models/AiProfile.php"]), \
        "a slice that edits a model must be shown the migration that defines its table"
    notes_task = {"title": "exchanges", "notes": "ai_social_exchanges is empty", "file_ref": "app/Actions/X.php"}
    assert migrations_for(notes_task, ["app/Actions/X.php"]), \
        "a table the evidence names must be shown even with no model in the slice"
    assert test_support_files(), "the suite's base test case must be in the context"
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

    # Resume timing is money: a wait that is too short wakes inside the window and pays peak rates,
    # and one that is too long throws away off-peak hours. 01:00 must sleep to 04:00, 03:59 to 04:00,
    # 06:00 to 10:00, and an off-peak moment must not wait at all.
    assert seconds_until_offpeak(at(28, 1, 0)) == 3 * 3600, "01:00 sleeps to 04:00"
    assert seconds_until_offpeak(at(28, 3, 59)) == 60, "03:59 sleeps one minute"
    assert seconds_until_offpeak(at(28, 6, 0)) == 4 * 3600, "06:00 sleeps to 10:00"
    assert seconds_until_offpeak(at(28, 5, 0)) == 0, "off-peak never waits"
    assert seconds_until_offpeak(at(27, 7, 0)) == 0, "a Sunday morning never waits"

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
        heading = f"## {CAPTURE_HEADINGS[0]}"
        if not lines and topic in ("strategy", "concept"):
            # Doctrine and concepts are what a player does and how the game computes, not only the
            # figures they quote: a page with no numbers still states something usable.
            lines = prose_lines(body)
            heading = f"## {CAPTURE_HEADINGS[1]}"
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
            f"## {CAPTURE_HEADINGS[0]}",
            "",
        ])
        if heading != f"## {CAPTURE_HEADINGS[0]}":
            header = header.replace(f"## {CAPTURE_HEADINGS[0]}", heading)
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
        if source_id in known:
            skipped.append(f"{source_id} (row exists)")
            continue
        # The validator decides, not the stamp in the file: a plan that was validated before a rule
        # tightened still carries VALIDATED, and promoting it would put a task row in the database
        # that the next stage refuses (found 29 Sep with a `tests/Unit/**` path).
        failures = check_proposal(source_id)
        if failures:
            skipped.append(f"{source_id} ({failures[0]})")
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
  comments explain *why* only; Pest tests in `tests/Feature` using the module's base test case.
- Paths are relative to the module root: `app/...`, `tests/Feature/...`, `resources/...`. Never write
  `Modules/AI/...` -- a path like that lands outside the module, where no test can collect it and no
  account can run it.
- Numbers come from the plan. Never invent a number, and never add a config key, migration or table.
- The test must fail if the behaviour breaks — assert the behaviour, not that a method exists.
- Tests are Pest, not PHPUnit classes: no `extends`, never invent a base class. See the EXAMPLE TEST
  below for the exact shape this module uses.
- Build fixtures the way the suite already builds them: use the test base case and the tests below that
  already exercise these classes. Never hand-write an insert into a host table (`users`, `players`, any
  `ai_*` table) -- their required columns are not in this prompt, so an invented insert fails before a
  single assertion runs. Measured 30 Sep 2026: 22 rows spent three attempts each on
  `Field 'player_id' doesn't have a default value` and its siblings, every attempt refused and restored.
- A model's required columns come from its migration, which is below when the slice names a model. If a
  table you need is not shown, build it the way the tests below do rather than from memory.
- Every test goes in `tests/Feature`. **Unit tests are not accepted.** A test over a bare value
  object can pass while the behaviour it describes is wrong, and nothing would notice. A Feature test
  drives the real path -- the action, the host services and the database -- so it fails when the
  behaviour is wrong rather than when a helper changes shape. A test written under `tests/Unit` is
  rejected before it is ever run.
- Data-driven by default: a value that decides behaviour -- a ratio, a cap, a cost, a threshold --
  belongs in a data file under `resources/behavior/` that the code reads, never inline in PHP. That is
  what lets a modder change how the account plays without touching code. The validator looks for the
  numbers this source states and refuses an answer that writes them into PHP instead.
- No magic numbers and no magic strings: name the constant, or read the value from the data file. If a
  value genuinely is structural (an array index, an initial zero, a sort sentinel), keep it small and
  obvious rather than hiding policy behind it.
- Not spaghetti: one idea per method, early returns, no nested conditionals, no boolean-flag arguments.
  A reader must be able to find where the behaviour is decided without tracing three layers.
- DX: a person extending this module should find the knob, not the code. Prefer extending an existing
  data file over adding a class, and say in the plan which data file a modder would edit.
- A slice that nothing calls is not a delivery. The rule must be wired into the runtime path that
  acts on it -- the planner, engine or action an account actually runs -- and that file belongs in
  FILES. A Feature test must then drive that path (the engine or action), not construct your class
  directly: a test that only news up the class proves the class exists, not that the account uses it.
- The module already owns most decisions. A new rule goes into the class that owns the decision it
  belongs to -- edit that file, and its data file, rather than writing a class beside it. A second
  class for a decision the module already has is a second authority, it drifts, and the writer
  refuses it by name.
- `app/Ai/` holds only the LLM agent classes the language gateways use. Nothing new is written there
  and nothing under it is edited: a rule that lands under `app/Ai/` is a parallel module, not a
  slice, and the writer refuses that path.
- Ship a scenario under `resources/scenarios/` (named for the situation, never for the source) that
  puts a player in a concrete situation and says what must happen:
  `"expect": {"action": "Build", "reason_contains": "defense"}`. The scenario is replayed through
  the real decision engine and the answer is thrown away when the engine chooses something else, so
  the expectation must be the action this rule is supposed to produce -- not whatever the engine
  currently does.
- Cover the condition that matters, not only the easy one: where the rule has a boundary, add a second
  scenario for the opposite situation (rich and short, threatened and ignored) and say what changes.
- Name every file for what it does (`DefenceValuation`, `RaidProfit`, `AntiBallisticMissile`). Never
  name a file, class or test after the source page it came from: `WIK-078`-style names contain no
  information and are refused. The source id belongs in the plan, not in the codebase.
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


def plan_paths(blocks, fallback=""):
    """The plan's file list, with backticks and the ` (new)` annotation taken off.

    A plan writes a backtick-quoted path followed by ` (new)`. Leaving the annotation attached made
    every lookup miss, so the model was never shown the existing files it was asked to change -- and
    then proposed them as new ones, which is exactly the "edit-only plan" dead end the driver kept
    hitting.

    A hand-written row puts several files in `file_ref`, separated by `;`. Read as one line, that whole
    string became a single path that exists nowhere, so the writer was told to *create* it and never saw
    the contents of any file it was asked to edit: measured 30 Sep 2026 on `PERS-001`, which rewrote
    `AiProfile` from a blank page and failed three attempts on that table's own required columns.
    """
    lines = blocks.get("FILES", []) or ([fallback] if fallback else [])
    paths = []

    for line in lines:
        for piece in str(line).split(";"):
            clean = re.sub(r"\s*\((new|edit)\)\s*$", "", piece.strip("- ").replace("`", "")).strip()
            if clean:
                paths.append(clean)

    return paths


def implement_context(code):
    """The task, its plan, and the *current* contents of the files it names.

    Bounded on purpose: only the named files are sent, truncated, so the model never sees the
    codebase and the prompt stays cacheable.
    """
    task = task_row(code)
    match = re.search(r"plan/research/ogame/proposals/(\S+?)\.md", task["notes"])
    proposal_path = os.path.join(PROPOSALS, f"{match.group(1)}.md") if match else None
    blocks = sections(read(proposal_path)) if proposal_path else {}
    paths = plan_paths(blocks, task["file_ref"])

    parts = [f"TASK {task['code']} — {task['title']}", ""]

    # The row's own evidence. Hand-written rows carry what was measured, what is required and -- where a
    # decision was open -- the default that was decided, and the writer used to be sent none of it: for a
    # row with no proposal it saw a bare title. Two slices then spent three attempts each inventing a
    # schema the row's own notes already described.
    if task["notes"].strip():
        parts += ["THE ROW'S OWN NOTES (authoritative: measurements, constraints, already-decided defaults):",
                  notes_block(task["notes"]), ""]

    parts += ["THE PLAN:",
             read(proposal_path) if proposal_path else "(the task has no proposal attached)", ""]

    # One real test from the module, so the harness copies the house style instead of inventing a
    # base class the module does not have. This is the difference between a test that runs and one
    # that cannot even be collected.
    for example in sorted(glob.glob(os.path.join(MODULE, "tests/Feature/*Test.php"))):
        parts += [f"EXAMPLE TEST {os.path.relpath(example, MODULE)} (copy this shape):",
                  "```php", read(example)[:1800], "```", ""]
        example_test = os.path.relpath(example, MODULE)
        break
    else:
        example_test = ""

    # Tests that already build the very classes this slice touches: the house way to make a profile, an
    # exchange, a work item, with the columns the real tables require. The alphabetical example above is
    # the only shape the writer saw, and it had never built an `ai_profiles` row -- so the writer guessed.
    for fixture in fixture_examples(paths, skip=example_test):
        parts += [f"TEST THAT ALREADY USES THESE CLASSES {os.path.relpath(fixture, MODULE)} "
                  "(build your fixtures the way this one does):",
                  "```php", read(fixture)[:3000], "```", ""]

    for support in test_support_files():
        parts += [f"TEST BASE CASE {os.path.relpath(support, MODULE)} (this is the class a Feature test "
                  "extends; it already makes the player and the account):",
                  "```php", read(support)[:3000], "```", ""]

    # The tables this row's own words name, from the migrations that define them. A required column is what
    # every invented insert got wrong, and the migration is that column's source of record.
    for migration in migrations_for(task, paths):
        parts += [f"MIGRATION THAT DEFINES A TABLE THIS TASK TOUCHES "
                  f"{os.path.relpath(migration, MODULE)} (required columns are NOT NULL here):",
                  "```php", read(migration)[:3000], "```", ""]

    for path in paths[:6]:
        full = os.path.join(MODULE, path)
        if os.path.exists(full):
            parts += [f"EXISTING FILE {path}:", "```php", read(full)[:6000], "```", ""]
        else:
            parts += [f"{path} does not exist yet — create it.", ""]

    # The real host classes, because the model never sees the repository and a service it needs has to
    # be named from somewhere. Without this list it invents names -- one answer used
    # `OGame\Services\BattleEngineService`, which does not exist, so all three tests died with
    # BindingResolutionException and every attempt was spent on the guess (measured 29 Sep).
    parts += ["HOST CLASSES THAT EXIST (use these exact names; anything not listed here does not "
              "exist, so never call it):",
              "\n".join(f"- {name}" for name in host_class_names()), ""]

    return task, paths, "\n".join(parts)


def notes_block(notes, limit=4000):
    """The row's own evidence, trimmed to a bound the prompt can carry."""
    text = notes.strip()

    return text if len(text) <= limit else text[:limit].rstrip() + "\n… (notes truncated)"


def fixture_examples(paths, skip="", limit=2):
    """Tests that already build the classes this slice touches.

    A slice that edits `AiProfile` needs to be shown a test that builds an AiProfile, not whichever test
    sorts first alphabetically: the required columns are visible in a working fixture, and nowhere else
    the writer can see.
    """
    names = [os.path.basename(path)[:-4] for path in paths if path.endswith(".php")]
    found = []

    for path in sorted(glob.glob(os.path.join(MODULE, "tests/Feature/**/*Test.php"), recursive=True)):
        relative = os.path.relpath(path, MODULE)
        if relative == skip:
            continue
        if any(name in read(path) for name in names):
            found.append(path)
        if len(found) >= limit:
            break

    return found


def test_support_files(limit=1):
    """The module's own base test case, so a fixture is made the way the suite makes it."""
    cases = sorted(glob.glob(os.path.join(MODULE, "tests/Support/*TestCase.php")))

    return cases[:limit]


def migrations_for(task, paths, limit=2):
    """The migrations that create the tables this row touches, found from the row's own words.

    Two sources, because the failing insert named a table in both ways: a model among the files the slice
    edits (`AiProfile` -> `ai_profiles`), and a table the evidence names outright (`ai_social_exchanges`
    in a defect about exchanges, with no model file in the slice at all).
    """
    tables = []
    for path in paths:
        if re.match(r"app/Models/\w+\.php$", path):
            body = read(os.path.join(MODULE, path))
            declared = re.search(r"protected\s+\$table\s*=\s*'([^']+)'", body)
            # AiProfile -> ai_profile(s): the class name snake-cased, because Laravel's own inference is
            # what named the table in the first place.
            name = declared.group(1) if declared else re.sub(r"(?<=[a-z0-9])(?=[A-Z])", "_", os.path.basename(path)[:-4]).lower()
            tables += [name, name + "s"]

    everywhere = f"{task['title']} {task['notes']} {task['file_ref']}"
    tables += [name.lower() for name in re.findall(r"\bai_[a-z_]+?\b(?=[^_a-z]|$)", everywhere, re.I)]

    migrations = sorted(glob.glob(os.path.join(MODULE, "database/migrations/*.php")))
    found = []

    # In the order the row names them, because a defect row lists the table it is about first
    # (`ai_social_exchanges` before the relationship tables it also mentions).
    for table in dict.fromkeys(tables):
        if len(found) >= limit:
            break

        for migration in migrations:
            if migration in found:
                continue
            if f"Schema::create('{table}'" in read(migration):
                found.append(migration)
                break

    return found


def host_class_names():
    """Every class the host offers in the areas this module collaborates with, as fully-qualified names.

    Read from each file's own `namespace` and declaration rather than assembled from the path, so a
    class that does not follow its directory (the battle engines do not) is still named correctly.
    """
    names = set()

    for area in ("app/Services", "app/Factories", "app/Models", "app/GameMissions/BattleEngine"):
        for path in glob.glob(os.path.join(ROOT, area, "**", "*.php"), recursive=True):
            body = read(path)
            namespace = re.search(r"^namespace\s+([^;]+);", body, re.M)
            declaration = re.search(r"^(?:final\s+|abstract\s+)?(?:class|interface|trait)\s+(\w+)", body, re.M)
            if namespace and declaration:
                names.add(namespace.group(1).strip() + "\\" + declaration.group(1))

    return sorted(names)


def claim_path(key):
    return os.path.join(CLAIMS, hashlib.sha256(key.encode()).hexdigest()[:16] + ".lock")


def take_claim(key):
    """Create one claim atomically, or refuse. O_EXCL is the whole mechanism: no coordination."""
    path = claim_path(key)
    for attempt in (0, 1):
        try:
            handle = os.open(path, os.O_CREAT | os.O_EXCL | os.O_WRONLY)
            stamp = datetime.datetime.now(datetime.timezone.utc).strftime("%Y-%m-%d %H:%M:%S")
            os.write(handle, f"{key}\npid {os.getpid()} at {stamp} UTC\n".encode())
            os.close(handle)
            return True
        except FileExistsError:
            if attempt or time.time() - os.path.getmtime(path) <= CLAIM_STALE_SECONDS:
                return False
            # A worker that died mid-slice leaves its claim behind; an aged one is not a claim.
            os.remove(path)

    return False


def release_claims(claims):
    for path in claims:
        if path and os.path.exists(path):
            os.remove(path)
        if path in HELD_CLAIMS:
            HELD_CLAIMS.remove(path)


def install_signal_release():
    """Give up our claims when we are asked to stop, instead of until they age out."""
    def release_and_exit(signum, _frame):
        release_claims(list(HELD_CLAIMS))
        raise SystemExit(128 + signum)

    for signal_number in (signal.SIGTERM, signal.SIGINT):
        signal.signal(signal_number, release_and_exit)


def claim_paths(keys):
    """Every key at once, or (None, the key somebody else is holding).

    All or nothing on purpose: holding half a slice's files while another worker holds the other half
    is exactly the interleaving that produces one slice's code inside another slice's feature.
    """
    os.makedirs(CLAIMS, exist_ok=True)
    held = []

    for key in sorted(set(keys)):
        if not take_claim(key):
            release_claims(held)
            return None, key
        held.append(claim_path(key))

    HELD_CLAIMS.extend(held)

    return held, None


def await_claim(key):
    """Take one claim, waiting for whoever holds it.

    The module's Feature tests run inside one shared database with per-test transactions, so two test
    runs at once contend on the same rows (settings, coordinate allocation) and fail each other for
    no reason. Verification therefore goes through one lane while the paid calls stay parallel.

    ponytail: a test run that hangs holds the lane until its claim ages out at CLAIM_STALE_SECONDS.
    Upgrade path: give each worker its own test database and delete the lane.
    """
    deadline = time.time() + CLAIM_STALE_SECONDS

    while time.time() < deadline:
        if take_claim(key):
            HELD_CLAIMS.append(claim_path(key))
            return claim_path(key)
        time.sleep(2)

    return None


QUALITY_TASKS = {
    # invariant -> (title, what the cohort is telling us)
    "NAKED_BESIDE_WALLED": (
        "Defence never reaches every planet: naked planets beside a walled one",
        "The cohort read found accounts with a planet at zero defence while a sibling holds a real "
        "wall, so the wall is being built in one place instead of everywhere it is wanted.",
    ),
    "WALL_CEILING": (
        "Standing wall size is unbounded",
        "The cohort read found single planets holding more defence units than any planet needs. Size "
        "is scaled from exposure with no ceiling, so it grows with production rather than with threat.",
    ),
    "ALLIANCE_SHARE": (
        "One alliance absorbs the cohort",
        "The cohort read found one alliance holding most of the AI accounts. Nothing in the social "
        "routine spreads founders, so the first club takes everyone who is engaged.",
    ),
}


def quality(path):
    """Turn a cohort's failed invariants into tracked tasks, once each.

    The verdict used to be a log line: the harness printed "QUALITY: FAIL" every pass and nothing
    consumed it, so a failing cohort and a fixed one looked the same from the task database -- the
    circle the owner called out. Here each invariant that fires either finds the task that already
    answers for it or creates one, and the caller is told which of the two happened.
    """
    text = read(path) if os.path.exists(path) else ""
    verdict = re.search(r"^QUALITY: FAIL ([A-Z_ ]+)$", text, re.M)
    if verdict is None:
        print("QUALITY: no failed invariant in that read — nothing to raise")
        return 0

    fired = verdict.group(1).split()
    cli = os.path.join(MODULE, "plan/tasks/task.py")
    known = quality_task_codes()
    raised, tracked = [], []

    for name in fired:
        if name in known:
            tracked.append(f"{name} ({known[name]})")
            continue
        title, why = QUALITY_TASKS.get(name, (f"Cohort invariant {name} fires", "See verify-cohorts.php."))
        code = f"QUAL-{len(known) + 1:03d}"
        samples = [line.strip() for line in text.splitlines() if f"[{name}]" in line][:5]
        subprocess.run([sys.executable, cli, "add", code, title, "impl", "P1",
                        "--gap", name,
                        "--notes", f"{why} Raised from the cohort read's own verdict. "
                                   f"Invariant name {name} is the dedupe key: do not raise a second "
                                   f"task while this one is open.\nEvidence:\n" + "\n".join(samples)],
                       check=True, capture_output=True)
        known[name] = code
        raised.append(f"{name} ({code})")
        print(f"raised {code} for {name}: {title}")

    print("quality work: " + ("; ".join(raised) if raised else "nothing new") +
          ("; already tracked: " + ", ".join(tracked) if tracked else ""))
    return 0


def quality_task_codes():
    """Which invariant each existing task already answers for, keyed by the invariant name.

    Read from `gap_ref` and the notes, because that is where the task says what it is about: a task
    exists per invariant, not per violation, so a second pass must find it rather than raise a twin.
    """
    if not os.path.exists(TASKS_DB):
        return {}

    connection = sqlite3.connect(TASKS_DB)
    rows = connection.execute(
        "select code, coalesce(gap_ref, '') || ' ' || coalesce(title, '') || ' ' || coalesce(notes, '') from tasks"
    ).fetchall()
    connection.close()

    found = {}
    for code, haystack in rows:
        for name in QUALITY_TASKS:
            if name in haystack and name not in found:
                found[name] = code

    return found


def status():
    """What the harness is waiting for, so an idle pass explains itself.

    "nothing left to do" every sixty seconds reads as a stall, and it hides the real state: a queue of
    tasks that cannot be attempted yet because their own attempts are cooling off. Saying which is
    which is the difference between a loop and a queue.
    """
    todo = []
    if os.path.exists(TASKS_DB):
        connection = sqlite3.connect(TASKS_DB)
        todo = [row[0] for row in connection.execute("select code from tasks where status = 'todo'")]
        connection.close()

    planned = [code for code in todo if os.path.exists(os.path.join(PROPOSALS, f"{code}.md"))]
    done = [code for code in planned if os.path.exists(os.path.join(IMPLEMENTED, f"{code}.md"))]
    cooling = {code: cooling_off(code) for code in planned if code not in done}
    waiting = {code: seconds for code, seconds in cooling.items() if seconds > 0}
    ready = [code for code in cooling if cooling[code] == 0]

    print(f"planning: {len(pending_sources())} source(s) pending")
    print(f"tasks: {len(todo)} todo, {len(planned)} planned, {len(done)} already proved, "
          f"{len(ready)} ready now, {len(waiting)} cooling off")
    # Machine-readable for the harness: an empty queue may wait, a queue with attemptable work may not.
    print(f"READY: {len(ready)}")

    if waiting:
        print(f"next one free in {min(waiting.values()) // 60} min")

    return 0


def model_call(payload, purpose="a model answer"):
    """One model call, paced by a shared slot and retried on the errors DeepSeek documents.

    The published limit is a *concurrency* limit, not a rate per minute: a request counts as one live
    connection from the moment it is sent until the response completes, and the account's ceiling is
    2,500 live connections for `deepseek-flash`. Workers are separate processes, so pacing has to be
    shared state, not a semaphore -- hence one `O_EXCL` slot file per allowed connection. Staying well
    under the ceiling is deliberate: the cohorts share this key, and 429 is what being wrong looks like.

    429 ("too many requests") and 500/503 ("retry after a brief wait") are retried with the server's own
    `Retry-After` when it sends one, and exponential backoff with jitter when it does not. Without this
    an uncaught HTTPError killed a worker mid-pass, and a slice's whole attempt was lost to a blip.
    """
    request = urllib.request.Request(
        API,
        data=json.dumps(payload).encode(),
        headers={"Authorization": f"Bearer {api_key()}", "Content-Type": "application/json"},
    )

    for attempt in range(1, MODEL_ATTEMPTS + 1):
        slot = take_model_slot()
        if slot is None:
            raise SystemExit("no model slot came free; giving up on this pass")

        try:
            publish("waiting on the model", f"{purpose} ({os.path.basename(slot)})")
            try:
                with urllib.request.urlopen(request, timeout=MODEL_TIMEOUT_SECONDS) as response:
                    return json.load(response)
            except urllib.error.HTTPError as error:
                if error.code not in MODEL_RETRY_CODES or attempt == MODEL_ATTEMPTS:
                    raise
                wait = retry_after(error) or min(60, 2 ** attempt)
                print(f"  {error.code} from the model on {purpose}; waiting {wait}s "
                      f"(attempt {attempt} of {MODEL_ATTEMPTS})")
                time.sleep(wait + random.uniform(0, 2))
        finally:
            if os.path.exists(slot):
                os.remove(slot)

    raise SystemExit("model call fell through every attempt")


def retry_after(error):
    """The server's own `Retry-After`, in seconds, when it sends a usable one."""
    header = error.headers.get("Retry-After") if error.headers else None
    if header is None:
        return None

    return int(header) if str(header).strip().isdigit() else None


def take_model_slot():
    """Hold one of the shared in-flight slots, or None. Cross-process: one file per connection."""
    os.makedirs(MODEL_SLOTS, exist_ok=True)
    deadline = time.time() + MODEL_TIMEOUT_SECONDS

    while time.time() < deadline:
        for index in range(MODEL_CONCURRENCY):
            path = os.path.join(MODEL_SLOTS, f"slot-{index}")
            try:
                handle = os.open(path, os.O_CREAT | os.O_EXCL | os.O_WRONLY)
                stamp = datetime.datetime.now(datetime.timezone.utc).strftime("%Y-%m-%d %H:%M:%S")
                os.write(handle, f"pid {os.getpid()} at {stamp} UTC\n".encode())
                os.close(handle)
                return path
            except FileExistsError:
                # A killed worker leaves its slot behind; the server closes a connection whose
                # inference has not started within ten minutes, so a slot older than that is dead.
                if time.time() - os.path.getmtime(path) > SLOT_STALE_SECONDS:
                    os.remove(path)
        time.sleep(1)

    return None


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
        # Its own exit code, so a loop driving many tasks stops once instead of spinning on the gate.
        return 3

    # A task already implemented and verified is never paid for twice; the loop that drives this is
    # meant to run for hours, and a re-run would spend tokens to be refused file by file.
    marker = os.path.join(IMPLEMENTED, f"{code}.md")
    # Skips are only interesting when they change something. The driver walks every task each pass, so
    # printing them every time buried real progress under ~90 repeats of the same line.
    quiet = os.environ.get("HARNESS_VERBOSE") != "1"
    say = (lambda message: None) if quiet else print

    if os.path.exists(marker):
        say(f"{code}: already implemented and verified — nothing spent")
        publish("working", f"skipped {code} (already done)")
        return 0

    cooldown = cooling_off(code)
    if cooldown > 0:
        # Not parked: the budget ages out and the task is picked up again on its own.
        say(f"{code}: cooling off for another {cooldown // 3600}h {cooldown % 3600 // 60}m")
        publish("working", f"skipped {code} (cooling off, retries itself)")
        return 0

    publish("implementing", code)
    task, paths, context = implement_context(code)

    # Never pay to redo finished work. If every file the plan names already exists and its own test
    # passes, the slice is delivered -- and the previous "edit-only plan" label was hiding finished
    # slices behind a paid call every pass to rediscover them. Only a row that came from a plan can be
    # read this way: a hand-written defect row names the files that exist *because* the behaviour is
    # wrong, so their existence proves nothing, and a row that happens to name its own test would
    # otherwise be marked delivered for free.
    if proposal_path and paths and all(os.path.exists(os.path.join(MODULE, path)) for path in paths):
        delivered = next((path for path in paths
                          if path.startswith("tests/Feature/")
                          and os.path.exists(os.path.join(MODULE, path))), None)

        if delivered is not None and module_test_passes(delivered) and not unreachable_files(paths):
            os.makedirs(IMPLEMENTED, exist_ok=True)
            with open(marker, "w", encoding="utf-8") as handle:
                # The files are recorded here too: a marker without them makes every later check --
                # reachability, inlined policy, the dashboard -- silently see nothing.
                handle.write(f"# {code} was already delivered; {delivered} passes\n\n"
                             + "\n".join(f"- {path}" for path in paths) + "\n")
            print(f"  already delivered: {delivered} passes — nothing spent")
            publish("working", f"{code} already delivered")
            return 0

    # Before anything is paid for: this slice's files, held until the process ends. atexit rather
    # than a try/finally around the whole body, so every existing return path keeps its shape. A path
    # the writer would refuse anyway is not claimed -- blocking another slice on a file nobody may
    # write is a lock held for no reason.
    install_signal_release()
    claims, other = claim_paths([os.path.join(MODULE, path) for path in paths
                                 if path_refusal(path) is None])
    if claims is None:
        print(f"  left for the next pass: another worker is writing {os.path.relpath(other, MODULE)}")
        publish("working", f"{code} waiting on {os.path.basename(other)}")
        return 0
    atexit.register(release_claims, claims)

    failure = previous_failure(code)
    if failure:
        # The last attempt's own output, so the retry corrects the actual error instead of guessing.
        context += ("\n\nYOUR PREVIOUS ATTEMPT WAS REJECTED. The test run said:\n"
                    + failure[:2000]
                    + "\nFix exactly that and change nothing else.")
    payload = {"model": MODEL, "messages": [
        {"role": "system", "content": IMPLEMENT_PROMPT},
        {"role": "user", "content": context},
    ]}
    data = model_call(payload, purpose=f"implementing {code}")
    choice = data["choices"][0]
    if choice.get("finish_reason") != "stop":
        raise SystemExit(f"refusing a {choice.get('finish_reason')} answer; nothing written")

    written, refused, tests, backups = [], [], [], {}
    late_claims = []
    for path, content in re.findall(
        r"### FILE:\s*(\S+?)\s*\n+```[a-zA-Z]*\n(.*?)```", choice["message"]["content"], re.S
    ):
        clean = path.strip("`")
        refusal = path_refusal(clean)
        if refusal:
            refused.append(f"{clean} ({refusal})")
            continue
        shadowed = duplicate_class(content, clean)
        if shadowed:
            refused.append(f"{clean} ({shadowed})")
            continue
        full = os.path.join(MODULE, clean)
        # The answer may name a file the plan never listed, and the claim was taken from the plan. Claim
        # it here, before the write, because an unclaimed write is how two workers get into one file:
        # rolling back afterwards cannot undo the collision, it can only hide it.
        if not os.path.exists(claim_path(full)):
            if not take_claim(full):
                release_claims(late_claims)
                rollback(written, backups)
                print(f"  left for the next pass: another worker is writing {clean}")
                publish("working", f"{code} waiting on {clean}")
                return 0
            late_claims.append(claim_path(full))
            HELD_CLAIMS.append(claim_path(full))
        # An existing file is an edit, not a dead end. The previous contents are kept so a failed
        # verification restores them: this runs unattended, so refusing to touch existing code would
        # simply park every plan that touches it.
        if os.path.exists(full):
            backups[full] = read(full)
        os.makedirs(os.path.dirname(full), exist_ok=True)
        with open(full, "w", encoding="utf-8") as handle:
            handle.write(content.rstrip() + "\n")
        written.append(clean)
        if "/tests/" in f"/{clean}":
            tests.append(os.path.basename(clean)[:-4])

    if late_claims:
        atexit.register(release_claims, late_claims)

    # What was refused, in the words the retry needs. Without this the retry only saw the test
    # failure it caused: a plan whose data file under `resources/behavior/` was refused (PHP is not
    # data) leaves generated code that `require`s a file nobody wrote, so every test dies with "No
    # such file or directory" and the model repeats the same shape until its attempts run out.
    refusal_note = ""
    if refused:
        refusal_note = "\n\nThese files from your answer were refused and NOT written:\n- " + "\n- ".join(refused)

    # Nothing written and nothing refused means the answer carried no FILE blocks at all.

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

    if not written:
        # No FILE blocks at all: the model either declined or answered in prose, and either way it has
        # been paid for. The retry has to see what it actually said -- "could not be verified" tells it
        # nothing about its own answer, so it just answers the same way again.
        answer = (choice["message"]["content"] or "").strip()
        print(f"  no files in the answer — {answer.splitlines()[0][:100] if answer else 'empty reply'}")
        record_failure(code, "your answer contained no ### FILE blocks, so nothing could be written. "
                             "Reply with the FILE blocks only, in the documented format.\n"
                             "--- your previous answer began:\n" + answer[:1200])
        return 1

    if broken:
        print(f"  unverified, restored {rollback(written, backups)} file(s)")
        record_failure(code, "generated code does not parse:\n" + "\n".join(broken))
        return 1

    if not tests:
        print(f"  unverified, restored {rollback(written, backups)} file(s)")
        record_failure(code, "the answer wrote files but no test, so nothing verified the behaviour"
                             + refusal_note)
        return 1
    # One lane for everything that touches the shared test database: the Pest runs below and the
    # scenario replay at the end both go through `run_in_app`, which holds the lane for each one.
    # The slice's own tests first, then every test that names a class the slice touched: a shared
    # action rewritten in place has to keep the pages and flows that already call it working.
    for name in tests + [name for name in affected_tests(written) if name not in tests]:
        code_rc, output = run_in_app(f"./vendor/bin/pest --testsuite=Modules --filter={name}")
        summary = [line.strip() for line in output.splitlines() if "Tests:" in line]
        # A summary line only means the suite RAN: six failed tests still print one. The exit code is
        # what says whether the code works, and a red test must leave nothing behind.
        if summary and code_rc == 0:
            print(f"  {name}: PASS {summary[-1]}")
            continue
        print(f"  {name}: FAIL {' '.join(summary[-1:]) or 'not collectable'}")
        print(f"  unverified, restored {rollback(written, backups)} file(s)")
        # Raw output with the colour codes stripped, not a keyword filter: Pest prints the exception
        # message on its own indented lines, so filtering for "FAIL"/"Error" threw the reason away and
        # left the retry with "7 failed (0 assertions)" and no idea why.
        detail = re.sub(r"\x1b\[[0-9;]*m", "", output).strip()[-2500:]
        record_failure(code, f"{name}: {' '.join(summary[-1:]) or 'suite not collectable'}\n\n{detail}"
                             + refusal_note)
        return 1

    # Live validation starts here: if no runtime code calls this, no cohort can execute it, so the
    # Feature test proves only that the class exists. Refused and rolled back, with the reason the
    # retry needs: name the runtime file that uses the rule and edit it too.
    unreachable = unreachable_files(written, backups)
    if unreachable:
        print(f"  nothing calls {', '.join(unreachable)} — not a delivery, restored")
        record_failure(code, "these files are not called by any runtime code, so no account can ever "
                             "execute them: " + ", ".join(unreachable) +
                             ".\nWire the rule into the planner, engine or action that acts on it, add "
                             "that file to FILES, and have the Feature test drive that path instead of "
                             "constructing your class directly.")
        rollback(written, backups)
        return 1

    # Policy belongs in data, not in PHP: a modder must be able to change how the account plays without
    # reading code. The source's own numbers are the test -- other literals stay untouched so this
    # speaks only about policy and never about a sort sentinel or an initial zero.
    inlined = inlined_policy(written, plan_numbers(code))
    if inlined:
        print(f"  {len(inlined)} source value(s) written inline — policy belongs in a data file")
        record_failure(code, "these values decide behaviour but were written into PHP: "
                             + ", ".join(inlined) +
                             ".\nPut them in a file under resources/behavior/ (extend one that exists "
                             "when it covers the topic) and read them from there, so a modder can change "
                             "the behaviour without editing code. Say which file in FILES.")
        rollback(written, backups)
        return 1

    # The tailored proof: a described situation plus the action the engine must choose under it. A
    # scenario without an `expect` block is skipped rather than counted, so nobody can point at a
    # report as if it were a check.
    for scenario in [path for path in written if path.startswith("resources/scenarios/")]:
        name = os.path.basename(scenario)[:-5]
        body = read(os.path.join(MODULE, scenario))
        if '"expect"' not in body:
            print(f"  scenario {name}: no expect block — not a check, ignored")
            continue

        run_rc, run_output = run_in_app(f"php artisan ai:replay-scenario {name}")
        if run_rc == 0:
            print(f"  scenario {name}: holds under these conditions")
            continue

        detail = re.sub(r"\x1b\[[0-9;]*m", "", run_output).strip()[-1500:]
        print(f"  scenario {name}: FAIL — the engine did not do what the rule says")
        record_failure(code, f"the scenario {name} did not hold:\n{detail}")
        rollback(written, backups)
        return 1

    os.makedirs(IMPLEMENTED, exist_ok=True)
    with open(marker, "w", encoding="utf-8") as handle:
        handle.write(f"# {code} implemented {datetime.datetime.now(datetime.timezone.utc):%Y-%m-%d %H:%M} UTC\n\n"
                     + "\n".join(f"- {path}" for path in written) + "\n")
    print(f"  verified and wired: {len(written)} file(s) kept, marker written")
    publish("implemented", f"{code} ({len(written)} file(s))")
    return 0


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("command", nargs="?",
                        choices=["bundle", "plan", "validate", "run", "sweep", "promote",
                                 "coverage", "implement", "wait-until-offpeak", "publish", "peak-gate",
                                 "quality", "status"])
    parser.add_argument("source", nargs="?")
    parser.add_argument("--max", type=int, default=4, help="run/sweep: work allowed this pass")
    parser.add_argument("--shard", help="run: slice k/N of the queue, so N workers cover it once")
    parser.add_argument("--dry", action="store_true", help="promote: report without writing rows")
    parser.add_argument("--self-check", action="store_true")
    args = parser.parse_args(argv)

    if args.self_check:
        self_check()
        return 0
    if args.command == "run":
        return run(args.max, shard=parse_shard(args.shard))
    if args.command == "sweep":
        return sweep(args.max)
    if args.command == "promote":
        return promote(args.dry)
    if args.command == "quality":
        # Reads the cohort read the harness captured, so a failed invariant becomes a task row.
        return quality(args.source or "/tmp/harness-quality.txt")
    if args.command == "status":
        return status()
    if args.command == "coverage":
        return coverage()
    if args.command == "peak-gate":
        return report_peak()
    if args.command == "wait-until-offpeak":
        return wait_until_offpeak()
    if args.command == "publish":
        # The shell drives stages the pipeline does not own -- a capacity universe takes minutes -- and
        # a heartbeat that stops during them reads as a dead harness.
        publish(args.source or "working")
        return 0
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
