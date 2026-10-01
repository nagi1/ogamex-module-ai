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

# How many tries one task may spend in a window, and how long before its budget resets. The window is
# not the stop: a row that fails the same way twice is stuck (record_failure) and waits for a person.
MAX_ATTEMPTS = 3
COOLOFF_SECONDS = 45 * 60
# A stuck row's wait, in seconds: no clock ends it, `task.py unstick` does.
NEVER = 10 ** 9
# Reads older than the grand reseed (1 Oct 2026 15:39 UTC) measured a universe at 90,000x speed.
COHORT_RESET = datetime.datetime(2026, 10, 1, 15, 39, tzinfo=datetime.timezone.utc)
SCORECARDS = os.path.join(MODULE, "plan/research/ogame/scorecards")
EVIDENCE = os.path.join(MODULE, "plan/research/ogame/evidence")
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
MODEL_ATTEMPTS = 5
MODEL_RETRY_CODES = (429, 500, 502, 503)
# deepseek-flash (V4.1) thinks by default at high effort, and a non-streaming answer sends no byte
# while it generates: a 300s read timeout cut long edits off mid-answer and paid for them again. The
# server sends blank keep-alive lines while a request waits and closes it if inference has not started
# within ten minutes, so twenty minutes covers a queued start plus a long answer.
MODEL_TIMEOUT_SECONDS = 20 * 60
# A slot outlives its call by a margin, or a slow call's slot is taken while it is still in flight.
SLOT_STALE_SECONDS = MODEL_TIMEOUT_SECONDS + 5 * 60
MODEL_USAGE = os.path.join(MODULE, "plan/research/ogame/model-usage.jsonl")

# Every claim this process holds. Kept so a signal can release them: Python does not run `atexit` on
# SIGTERM, and the harness stops workers exactly that way (`trap 'kill 0' EXIT`, `pkill`) -- so every
# restart used to leave claims behind that blocked their files for the full stale window.
HELD_CLAIMS = []
# The ledger owns rows, file_ref parsing and lock names; the harness and the agents share them, so a
# file an agent is editing is a file the harness will not write, and the other way round.
sys.path.insert(0, os.path.join(MODULE, "plan/tasks"))
import task as ledger  # noqa: E402
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

ASPECT: <the one aspect of play this change makes the account do more or better, from this list:
  {aspects}; a change that moves none of them is not proposed — answer `ASPECT: none` and stop>
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
            {"role": "system", "content": PROMPT.replace("{aspects}", ", ".join(scorecard_aspects()))},
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
    # The north star at planning time: a plan that moves no aspect of play is not a task.
    aspect = proposal_aspect(blocks)
    if aspect is None:
        failures.append("ASPECT names none of the scorecard's aspects ("
                        + ", ".join(scorecard_aspects()) + "): a change that moves no aspect of play is not proposed")

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


def failure_signature(reason):
    """The reason with what varies per run taken out: colour codes, absolute paths, uuids and hex ids,
    durations, every digit (ids, line numbers, timestamps). Two attempts that failed the same way
    read the same."""
    text = re.sub(r"\x1b\[[0-9;]*[A-Za-z]", "", reason)
    text = re.sub(r"(?<![\w.])/[\w.\-/]+", " ", text)
    text = re.sub(r"\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b", " ", text)
    text = re.sub(r"\b(?=[0-9a-f]*\d)[0-9a-f]{7,}\b", " ", text)
    text = re.sub(r"\d+(?:\.\d+)?\s*(?:ms|s|sec|seconds|m|min|h)\b", " ", text)

    return " ".join(re.sub(r"\d+", "", text).split())


def stuck(code):
    return os.path.exists(os.path.join(ATTEMPTS, f"{code}.stuck"))


def block_row(code, note):
    """Take a row out of the queue with the reason in its notes: the ledger's own `block`."""
    if not os.path.exists(TASKS_DB):
        return
    connection = sqlite3.connect(TASKS_DB, timeout=30)
    ledger.cmd_block(connection, code, note)
    connection.close()


def record_failure(code, reason):
    """Count a failed attempt, keep its output for the next one, and stop on a repeat.

    A failure whose signature equals the previous one's is the terminal state: the row is stuck,
    blocked, and no clock brings it back, because a third attempt with the same input fails the same
    way and costs the same. A different failure is progress and gets another try carrying its reason.
    """
    os.makedirs(ATTEMPTS, exist_ok=True)
    path = os.path.join(ATTEMPTS, f"{code}.count")
    attempts = int(read(path).strip() or 0) + 1 if os.path.exists(path) else 1

    with open(path, "w", encoding="utf-8") as handle:
        handle.write(str(attempts))
    with open(os.path.join(ATTEMPTS, f"{code}.log"), "w", encoding="utf-8") as handle:
        handle.write(reason)

    normalised = failure_signature(reason)
    signature = hashlib.sha1(normalised.encode()).hexdigest()[:12]
    sig_path = os.path.join(ATTEMPTS, f"{code}.sig")
    with open(sig_path, "a", encoding="utf-8") as handle:
        handle.write(signature + "\n")
    signatures = read(sig_path).split()

    if len(signatures) < 2 or signatures[-1] != signatures[-2]:
        print(f"  attempt {attempts} failed; retrying with this output")
        return False

    first = next((line.strip() for line in reason.splitlines() if line.strip()), "")
    with open(os.path.join(ATTEMPTS, f"{code}.stuck"), "w", encoding="utf-8") as handle:
        handle.write(normalised + "\n---\n" + "\n".join(reason.splitlines()[:40]) + "\n")
    block_row(code, f"stuck: same failure twice: {first[:200]}")
    print(f"  attempt {attempts} failed the same way twice; {code} is stuck and blocked")

    return True


def reopen(code):
    """Send a delivered row back to the writer when its live proof failed, with that failure as feedback.

    A delivered row used to wait for ever: its tests passed, so no attempt repeated it, and `task.py
    done` only re-ran a proof that kept failing for the same reason (ECON-001, FLEET-002 and QUAL-008
    sat `in_progress` for hours). A failing situation, invariant or test is the next attempt's input; a
    step that only says `too early` waits for the cohort. The repeat-failure rule still stops a row whose
    proof fails the same way twice.
    """
    log = os.path.join(MODULE, "plan/research/ogame/proofs", f"{code}.log")
    text = re.sub(r"\x1b\[[0-9;]*m", "", read(log)) if os.path.exists(log) else ""
    failing = [line for line in text.splitlines() if line.startswith("FAIL ") and "too early" not in line]
    if not failing:
        print(f"{code}: nothing to send back (no failing step, or only steps that wait for the cohort)")
        return 0

    connection = sqlite3.connect(TASKS_DB, timeout=30)
    connection.execute("update tasks set status='todo', assignee=null, updated_at=datetime('now') "
                       "where code=? and assignee='harness:delivered'", (code,))
    connection.commit()
    connection.close()
    # No longer delivered: the marker is what `status` and the watch page count as awaiting proof, and a
    # fresh delivery writes it again.
    marker = os.path.join(IMPLEMENTED, f"{code}.md")
    if os.path.exists(marker):
        os.remove(marker)
    # After the reset, so a stuck verdict (blocked) wins over it.
    record_failure(code, "the delivered change passed its tests but its live proof failed:\n" + "\n".join(text.splitlines()[:30]))
    print(f"{code}: back to the writer with the failing proof step")

    return 0


def cooling_off(code):
    """How long this task should wait before its next attempt, or 0 when it may be attempted now."""
    if stuck(code):
        return NEVER

    path = os.path.join(ATTEMPTS, f"{code}.count")
    if not os.path.exists(path):
        return 0

    age = int(time.time() - os.path.getmtime(path))
    if age > COOLOFF_SECONDS:
        # A budget that ran out on different failures resets; a stuck row never reaches here.
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


def data_reader(clean):
    """The first line of code (not a comment) that names this behaviour file, or None.

    Data nothing reads looks like policy and changes nothing: seven doctrine files delivered on
    30 Sep 2026 were named by no runtime file (QUAL-6). A docblock mentioning the file is not a reader.
    """
    hits = subprocess.run(["grep", "-rnF", os.path.basename(clean), "app/", "--include=*.php"],
                          cwd=MODULE, capture_output=True, text=True).stdout.splitlines()
    code = [hit for hit in hits if not re.match(r"^[^:]+:\d+:\s*(\*|//|/\*)", hit)]

    return code[0] if code else None


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

    unreachable = [clean for clean in paths if clean.startswith("resources/behavior/") and not data_reader(clean)]

    for clean in paths:
        if not clean.startswith("app/") or not clean.endswith(".php"):
            continue
        full = os.path.join(MODULE, clean)
        if not os.path.exists(full):
            continue

        klass = os.path.basename(clean)[:-4]
        # Whole words: as a substring, SolarSystemSlot counted SolarSystemSlots as its caller (QUAL-7).
        hits = subprocess.run(["grep", "-rlw", klass, "app/", "--include=*.php"],
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
    # A slice holds the lane from its first write to its verdict, and its own runs go through here.
    lane = None if claim_path(VERIFY_LANE) in HELD_CLAIMS else await_claim(VERIFY_LANE)
    if lane is None and claim_path(VERIFY_LANE) not in HELD_CLAIMS:
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
        if lane:
            release_claims([lane])


def proof_report(code):
    """The row's proof as `prove CODE --json` reports it, or None when the proof could not run."""
    env = dict(os.environ, OGAMEX_RUNNER=os.environ.get("OGAMEX_RUNNER", "local-docker-dev"))
    try:
        result = subprocess.run(["bash", os.path.join(MODULE, "scripts/ogamex"), "prove", code, "--json"],
                                capture_output=True, text=True, timeout=30 * 60, env=env)
        return json.loads(result.stdout.strip().splitlines()[-1])
    except (IndexError, ValueError, subprocess.TimeoutExpired):
        return None


def red_first(code):
    """Run the proof before the first paid attempt. True when that settled the row without a model call.

    A proof that already passes means the row is stale; one whose every failing step is suspect cannot
    be moved by code (ECON-001 was changed three times and IDLE_QUEUES read the same). Either way a
    model call would buy nothing. Otherwise the report is the baseline the attempt is judged against.
    """
    report = proof_report(code)
    if report is None:
        print(f"  the proof of {code} could not be run; nothing spent")
        return True

    os.makedirs(ATTEMPTS, exist_ok=True)
    with open(os.path.join(ATTEMPTS, f"{code}.baseline.json"), "w", encoding="utf-8") as handle:
        json.dump(report, handle)
    failing = [step for step in report["steps"] if not step["pass"]]

    if not failing:
        print(f"  the proof of {code} already passes; closing it, nothing spent")
        subprocess.run([sys.executable, os.path.join(MODULE, "plan/tasks/task.py"), "done", code])
        return True
    if all(step.get("suspect") for step in failing):
        print(f"  every failing step of {code} is suspect; nothing spent")
        block_row(code, "proof suspect: only " + ", ".join(step["step"] for step in failing) + " fails; the reviewer judges the proof")
        return True

    return False


def proof_change(before, after):
    """Why an attempt moved nothing, or None when its fast steps pass or one went FAIL to PASS and none
    went PASS to FAIL. Only `test:` and `situation:` steps are judged here: an aspect or invariant is
    measured over an hour of cohort play, cannot flip within one attempt, and is judged later by
    `task.py done` on the delivered row (FLEET-002 went stuck on `aspect:recycle` for that reason)."""
    fast = lambda report: [step for step in report["steps"] if step["step"].split(":")[0] in ("test", "situation")]
    was = {step["step"]: step["pass"] for step in fast(before)}
    now = {step["step"]: step["pass"] for step in fast(after)}
    broke = [name for name, ok in now.items() if not ok and was.get(name)]
    if broke:
        return f"proof regressed: {broke[0]} passed before this attempt and fails now"
    failing = next((step for step in fast(after) if not step["pass"]), None)
    if failing is None:
        return None
    if any(ok and was.get(name) is False for name, ok in now.items()):
        return None

    return f"proof unchanged: {failing['step']} {failing['line']}"


def note_delivery(code, report):
    """Block a row as `proof suspect` when its proof fails on the same step three deliveries running.

    Code changed each time (each delivery is a verified attempt), so the step is not measuring the
    change. The step gets the `?` marker and the reviewer reads the proof. A step that fails only
    because the cohort has not played long enough says nothing about the proof."""
    failing = next((step for step in report["steps"]
                    if not step["pass"] and not step.get("suspect") and not step["line"].startswith("too early")), None)
    if failing is None:
        return False

    signature = hashlib.sha1(f"{failing['step']} {failure_signature(failing['line'])}".encode()).hexdigest()[:12]
    path = os.path.join(ATTEMPTS, f"{code}.deliveries")
    with open(path, "a", encoding="utf-8") as handle:
        handle.write(signature + "\n")
    last = read(path).split()[-3:]
    if len(last) < 3 or len(set(last)) != 1:
        return False

    steps = [step + "?" if step == failing["step"] else step for step in task_row(code)["proof"].split()]
    connection = sqlite3.connect(TASKS_DB, timeout=30)
    ledger.cmd_proof(connection, code, steps)
    connection.close()
    block_row(code, f"proof suspect: {failing['step']} read the same on three deliveries: {failing['line'][:160]}")

    return True


def live_aspects():
    """Aspect -> whether it passes, from the newest grand scorecard written after the cohort reset;
    None when there is none."""
    newest = None
    for path in glob.glob(os.path.join(SCORECARDS, "*-ogamex-grand-*.json")):
        try:
            data = json.loads(read(path))
            at = datetime.datetime.fromisoformat(data["at"])
        except (ValueError, KeyError):
            continue
        if at >= COHORT_RESET and (newest is None or at > newest[0]):
            newest = (at, {name: bool(aspect["pass"]) for name, aspect in data["aspects"].items()})

    return newest[1] if newest else None


def violated_invariants():
    """The invariants the newest saved grand verify read (the evidence run's) names, from after the
    cohort reset; None when there is none."""
    newest = None
    for path in glob.glob(os.path.join(EVIDENCE, "*", "*verify-grand*.txt")):
        text = read(path)
        header = re.search(r"cohort verification . (\d{4}-\d\d-\d\d \d\d:\d\d:\d\d)", text)
        if header is None:
            continue
        at = datetime.datetime.strptime(header.group(1), "%Y-%m-%d %H:%M:%S").replace(tzinfo=datetime.timezone.utc)
        if at >= COHORT_RESET and (newest is None or at > newest[0]):
            newest = (at, set(re.findall(r"^\s+! \[(\w+)\]", text, re.M)))

    return newest[1] if newest else None


def moves_no_failing_aspect(proof):
    """Why no step of this proof currently fails, or None when one does or nothing says. Only an
    aspect: or invariant: step with a read newer than the cohort reset can hold a row back."""
    aspects, invariants = live_aspects(), violated_invariants()
    judged = []

    for step in proof.split():
        kind, name = step.rstrip("?").split(":", 1)
        if kind not in ("aspect", "invariant"):
            continue
        reads = aspects if kind == "aspect" else invariants
        if reads is None:
            return None
        if (not reads.get(name, False)) if kind == "aspect" else name in reads:
            return None
        judged.append(step)

    return f"{' '.join(judged)} pass on the newest read since the cohort reset" if judged else None


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

    # The writer's answer format: an edit lands only where its SEARCH is, once; anything else is a
    # refusal with a reason the retry can act on, and a refused-only answer is not "no blocks".
    target = "app/Actions/ReplayAiScenarioAction.php"
    anchor = "throw new RuntimeException('Scenario file not found: ' . $path);"
    edit = f"### EDIT: {target}\n<<<<<<< SEARCH\n{anchor}\n=======\n{anchor} // probe\n>>>>>>> REPLACE\n"
    changes, refused = parse_answer(edit)
    assert not refused and changes[target].count("// probe") == 1, "an exact SEARCH applies once"
    _, refused = parse_answer(edit.replace(anchor, "nothing like this line", 1))
    assert refused and "is not in the file" in refused[0], "a SEARCH that is not there is refused, by name"
    changes, refused = parse_answer("### FILE: resources/behavior/x.php\n```php\n<?php\n```\n")
    assert not changes and refused, "a refused-only answer has refusals, not an empty answer"
    changes, _ = parse_answer("### FILE: tests/Feature/ProbeShapeTest.php\n```php\n<?php\n```\n"
                              + edit)
    assert set(changes) == {"tests/Feature/ProbeShapeTest.php", target}, "FILE and EDIT blocks mix"
    assert scenario_required_keys() == ["name", "persona", "input", "decision_key"], \
        "the scenario keys are read from the replay action"

    target_full = os.path.join(MODULE, target)
    original = read(target_full)
    with open(target_full, "w", encoding="utf-8") as handle:
        handle.write(original.replace("public function handle(", "private function handle(", 1))
    try:
        assert any("handle()" in line for line in lost_contract({target_full: original})), \
            "removing a public method something still calls is caught"
    finally:
        with open(target_full, "w", encoding="utf-8") as handle:
            handle.write(original)

    assert data_reader("resources/behavior/collector.yaml"), "a data file a class loads has a reader"
    assert unreachable_files(["resources/behavior/__nobody_reads_this.yaml"], {}) == \
        ["resources/behavior/__nobody_reads_this.yaml"], "a data file nothing loads is unreachable"

    # Agents and the harness share one lock namespace: an agent's lock is not taken for stale
    # after the harness's thirty minutes, and the harness reads the ledger's own file parser.
    agent_lock = claim_path(f"self-check-agent:{os.getpid()}")
    os.makedirs(CLAIMS, exist_ok=True)
    with open(agent_lock, "w", encoding="utf-8") as handle:
        handle.write("self-check\nagent someone ROW-1\n")
    os.utime(agent_lock, (time.time() - 3600, time.time() - 3600))
    assert not claim_is_stale(agent_lock), "an agent's hour-old lock still holds its file"
    os.remove(agent_lock)
    assert plan_paths({}, "app/A.php (method); app/B.php, app/C.php") == ["app/A.php", "app/B.php", "app/C.php"], \
        "the harness locks the same paths the ledger does"

    factory_probe = "tests/Feature/__FactoryProbeTest.php"
    with open(os.path.join(MODULE, factory_probe), "w", encoding="utf-8") as handle:
        handle.write("<?php\nAiProfile::factory()->create();\n")
    try:
        assert invented_factories([factory_probe]) == [f"AiProfile::factory() in {factory_probe}"], \
            "a factory the module model does not have is refused"
    finally:
        os.remove(os.path.join(MODULE, factory_probe))

    assert ledger.on_path("test:X aspect:raids") and ledger.on_path("harness:self-check"), "live and loop proofs are on the path"
    assert not ledger.on_path("test:AI") and not ledger.on_path(""), "a test alone is not north-star work"
    assert {"economy", "raids", "fleet_save", "social"} <= set(scorecard_aspects()), "plans name the scorecard's aspects"
    assert proposal_aspect({"ASPECT": ["raids — the account raids a profitable neighbour"]}) == "raids"
    assert proposal_aspect({"ASPECT": ["none"]}) is None, "a plan that moves no aspect is refused"

    # A repeat failure is the terminal state: digits, paths, ids and durations do not make two failures
    # differ, and the second identical one stops the row for good.
    assert failure_signature("Tests: 2 failed 1.04s at /var/www/a.php:32 row 18915 c0ffee12ab") == \
        failure_signature("Tests: 7 failed 2.5s at /tmp/b.php:41 row 99 deadbe3f12"), "volatile parts are not the failure"
    assert failure_signature("no test") != failure_signature("no tests found"), "different words are different failures"
    probe_code = f"__self-check-{os.getpid()}__"
    try:
        assert record_failure(probe_code, "boom 1") is False, "the first failure only counts"
        assert record_failure(probe_code, "boom 2") is True and stuck(probe_code), "the same failure twice is stuck"
        assert cooling_off(probe_code) == NEVER, "no clock ends a stuck row"
    finally:
        for suffix in ("count", "log", "sig", "stuck"):
            if os.path.exists(os.path.join(ATTEMPTS, f"{probe_code}.{suffix}")):
                os.remove(os.path.join(ATTEMPTS, f"{probe_code}.{suffix}"))
    red, green = {"pass": False, "steps": [{"step": "test:X", "pass": False, "line": "boom"}]}, {"pass": True, "steps": []}
    assert proof_change(red, green) is None, "a proof that passes is accepted"
    assert proof_change(red, red).startswith("proof unchanged: test:X boom"), "an attempt that moved nothing is refused"
    two = {"pass": False, "steps": [{"step": "test:X", "pass": True, "line": ""}, {"step": "aspect:a", "pass": False, "line": "n"}]}
    assert proof_change(red, two) is None, "a step that went FAIL to PASS is progress"
    assert proof_change(two, red).startswith("proof regressed"), "a step that went PASS to FAIL is refused"
    assert WRITER_MAX_TOKENS >= 1.5 * 15540 / 3.5 - 100, "the cap follows the largest saved answer"

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
        aspect = proposal_aspect(blocks)
        if aspect is None:
            skipped.append(f"{source_id} (names no aspect of play)")
            continue
        subprocess.run([sys.executable, cli, "add", source_id, title, "impl", "P3",
                        "--file", files, "--notes", notes, "--proof", f"aspect:{aspect}"],
                       check=True, capture_output=True)
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


IMPLEMENT_PROMPT = """You implement ONE task in the OGameX `Modules/AI` module (PHP 8.5, Laravel).

The goal: OGame accounts that play like experienced human players, decided by deterministic rules over
the host's game data. Your change is judged by THE PROOF in the task -- what accounts visibly do on the
live cohorts -- not by your test alone. Make the smallest change that makes the account do that.

WHERE THE CHANGE GOES
- Edit the class that already owns the decision (the task's existing files). Never write a second class
  for a decision the module already makes, and write nothing under `app/Ai/`.
- The rule must run on the path an account runs: the planner, engine or action. A class only your test
  calls is not a delivery.
- Values that decide behaviour (ratios, caps, thresholds, weights) go in a YAML file under
  `resources/behavior/` -- extend one that covers the topic -- loaded by name in that class. Never write
  the plan's numbers into PHP.
- Buildings, ships, defence, research, prices and requirements come from the host (`ObjectService`, the
  planet and player services). Never hardcode an object name or id as a rule.
- Use only host classes from HOST CLASSES, enum cases from ENUM blocks, and columns from MIGRATION blocks.
- Style: no `else`/`elseif` (early return or `match`); module classes via `app()`/`app()->makeWith()`,
  never `new`; small methods; a comment only for a non-obvious why.

THE TEST
- When the task shows THE FAST PROOF, that test is the target: make it pass. It already counts as your
  test. Otherwise:
- One Pest Feature test in `tests/Feature/`, shaped like EXAMPLE TEST (`uses(...)`, no class, no
  `extends`). Never `tests/Unit`.
- Build rows with `Model::create([...])` exactly as TEST THAT ALREADY USES THESE CLASSES does, every NOT
  NULL column included. Module models have no factories. Never insert into a host table by hand.
- Drive the real path (planner, engine or action) and assert the behaviour. Test at the bound, past it
  and at zero, not only the easy middle.

THE SCENARIO (only when the task shows no FAST PROOF; with one, write no scenario)
- Add `resources/scenarios/<situation>.json` shaped like EXAMPLE SCENARIO, with an `expect` block naming
  the action this rule must make the engine choose. Where the rule has a boundary, add the opposite
  situation too.

HONESTY
- The plan's ACCEPTANCE (or the task's notes) is the specification. If it cannot hold as written, say so
  in one line instead of reinterpreting it. Where a ratio and a cap conflict at the extreme, the cap wins;
  add no floor, tie-break or rule the source does not state.
- Name every file for what it does (`RaidProfit`), never after a source id (`WIK-078`).

REFUSED AUTOMATICALLY, BEFORE ANY TEST RUNS (each costs you the attempt)
a path outside the module or starting `Modules/`; `tests/Unit/`; a PHP file under `resources/behavior/`;
anything under `app/Ai/`; a file named after a source id; a second class with an existing class's name;
a FILE block for an existing file you were shown only in part; a SEARCH not found exactly once; PHP that
does not lint cleanly (warnings included); no test; a class or data file no runtime code uses; the
plan's numbers inlined in PHP; `Model::factory()` on a module model; a public method or interface that
other code uses removed; a scenario missing a required key.

ANSWER FORMAT -- blocks only, nothing before the first or after the last:

### EDIT: <path of an existing file>
<<<<<<< SEARCH
<exact lines copied from the EXISTING FILE, unique, a few lines>
=======
<the lines that replace them>
>>>>>>> REPLACE

(several SEARCH/REPLACE pairs may follow one EDIT header; they apply in order)

### FILE: <path of a new file>
```php
<complete file contents>
```

Paths are module-relative: `app/...`, `tests/Feature/...`, `resources/...`. Data files are YAML.
"""

COMPOSE_DIR = os.path.abspath(os.path.join(MODULE, "..", "..", "local-docker-dev"))


def task_row(code):
    if not os.path.exists(TASKS_DB):
        raise SystemExit("no task database")
    connection = sqlite3.connect(TASKS_DB)
    row = connection.execute(
        "select code, title, status, notes, file_ref, proof from tasks where code = ?", (code,)
    ).fetchone()
    connection.close()
    if row is None:
        raise SystemExit(f"no task {code} in the database")
    return {"code": row[0], "title": row[1], "status": row[2],
            "notes": row[3] or "", "file_ref": row[4] or "", "proof": row[5] or ""}


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
    if not blocks.get("FILES"):
        return ledger.file_paths(fallback)

    lines = blocks["FILES"]
    paths = []

    for line in lines:
        for piece in str(line).split(";"):
            clean = re.sub(r"\s*\((new|edit)\)\s*$", "", piece.strip("- ").replace("`", "")).strip()
            if clean:
                paths.append(clean)

    return paths


def reference_context():
    """What every task is shown, identical across tasks and always first.

    DeepSeek caches an identical request prefix and bills a cached token at about 2% of a miss, so the
    shared reference goes before anything task-specific: the system prompt plus this block become one
    cached prefix for every call of the day. Returns the text and the example test's path.
    """
    parts = ["REFERENCE — the same for every task", ""]

    # The real host classes: the model never sees the repository, and without this list it invents
    # names (`OGame\Services\BattleEngineService` cost three attempts on 29 Sep).
    parts += ["HOST CLASSES THAT EXIST (use these exact names; anything not listed does not exist):",
              "\n".join(f"- {name}" for name in host_class_names()), ""]

    for support in test_support_files():
        parts += [f"TEST BASE CASE {os.path.relpath(support, MODULE)} (a Feature test `uses()` it; it already "
                  "makes the player, the account and the planets):",
                  "```php", read(support)[:3000], "```", ""]

    parts += ["TEST KIT (every helper a test can use; anything not listed does not exist):", test_kit(), ""]

    example_test = next(iter(sorted(glob.glob(os.path.join(MODULE, "tests/Feature/*Test.php")))), None)
    if example_test:
        parts += [f"EXAMPLE TEST {os.path.relpath(example_test, MODULE)} (the shape every test copies):",
                  "```php", read(example_test)[:1800], "```", ""]

    example = next(iter(sorted(glob.glob(os.path.join(MODULE, "resources/scenarios/*.json")))), None)
    if example:
        parts += [f"EXAMPLE SCENARIO {os.path.relpath(example, MODULE)} (required keys: "
                  f"{', '.join(scenario_required_keys() + ['expect'])}):", "```json", read(example)[:2500], "```", ""]

    return "\n".join(parts), os.path.relpath(example_test, MODULE) if example_test else ""


def helper_clashes(written):
    """Top-level test functions this slice declares that another test file declares too."""
    clashes = []
    for path in [p for p in written if p.startswith("tests/") and p.endswith(".php")]:
        for name in re.findall(r"^function\s+(\w+)\s*\(", read(os.path.join(MODULE, path)), re.M):
            hits = subprocess.run(["grep", "-rlE", rf"^function\s+{name}\s*\(", "tests/", "--include=*.php"],
                                  cwd=MODULE, capture_output=True, text=True).stdout.split()
            others = [hit for hit in hits if os.path.normpath(hit) != os.path.normpath(path)]
            if others:
                clashes.append(f"{name}() (also in {others[0]})")
    return clashes


def proof_tests(code):
    """The `test:` steps of the row's proof whose file exists: the fast proof the slice must pass."""
    proof = task_row(code)["proof"] if os.path.exists(TASKS_DB) else ""
    names = [step[len("test:"):].rstrip("?") for step in proof.split() if step.startswith("test:")]

    return [name for name in names if glob.glob(os.path.join(MODULE, "tests/Feature/**", f"{name}.php"), recursive=True)]


def test_kit():
    """Every helper a Feature test can call, as one-line signatures: the base cases' methods and
    properties, and the module's shared test functions. Invented helper names were a common failure."""
    root = os.path.abspath(os.path.join(MODULE, "..", ".."))
    lines = []
    for path in sorted(glob.glob(os.path.join(root, "tests/*TestCase.php")) + glob.glob(os.path.join(root, "tests/Traits/*.php")) + glob.glob(os.path.join(MODULE, "tests/Support/*.php"))):
        body = read(path)
        members = re.findall(r"^\s*(?:public|protected)\s+(?:static\s+)?(?:function\s+\w+\([^)]*\)(?:\s*:\s*[\w|\\?]+)?|[\w|\\?]+\s+\$\w+)", body, re.M)
        if members:
            lines.append(f"{os.path.basename(path)[:-4]}: " + "; ".join(" ".join(m.split()) for m in members))
    helpers = []
    for path in sorted(glob.glob(os.path.join(MODULE, "tests/Feature/**/*.php"), recursive=True)):
        for found in re.findall(r"^function\s+(\w+\([^)]*\)(?:\s*:\s*[\w|\\?]+)?)", read(path), re.M):
            helpers.append(f"{' '.join(found.split())}  [{os.path.basename(path)[:-4]}]")
    return "\n".join(lines) + "\nTEST FUNCTIONS ALREADY DECLARED IN OTHER TEST FILES (NOT loaded with your test, so do not call them; every function name is global, so a function you declare must use a name NOT in this list):\n" + "\n".join(helpers)


def implement_context(code):
    """The shared reference, then this task: its notes, proof, plan and the files it touches.

    Bounded on purpose: only the named files are sent, so the model never sees the whole codebase.
    """
    task = task_row(code)
    match = re.search(r"plan/research/ogame/proposals/(\S+?)\.md", task["notes"])
    proposal_path = os.path.join(PROPOSALS, f"{match.group(1)}.md") if match else None
    blocks = sections(read(proposal_path)) if proposal_path else {}
    paths = plan_paths(blocks, task["file_ref"])
    reference, example_test = reference_context()

    parts = [reference, "", f"TASK {task['code']} — {task['title']}", ""]

    # What will judge the slice, so the writer aims at the account's behaviour and not at its own test.
    if task["proof"]:
        parts += ["THE PROOF THAT CLOSES THIS TASK (run on the live cohorts after your change): " + task["proof"],
                  "aspect:X = accounts must visibly do X more; situation:Y = a planted situation must produce "
                  "the expected work; invariant:Z = the cohort must stop violating Z. A green test alone does "
                  "not close it.", ""]

    for name in proof_tests(code):
        path = glob.glob(os.path.join(MODULE, "tests/Feature/**", f"{name}.php"), recursive=True)[0]
        parts += [f"THE FAST PROOF {os.path.relpath(path, MODULE)} (make this test pass through the real path; "
                  "it already counts as your test, so write another only for a case it does not cover; edit it "
                  "only to remove a `->todo()` your change satisfies):", "```php", read(path)[:5000], "```", ""]

    # The row's own evidence: what was measured, what is required, and any default already decided.
    if task["notes"].strip():
        parts += ["THE TASK'S NOTES (authoritative: measurements, constraints, decisions already made):",
                  notes_block(task["notes"]), ""]

    if proposal_path:
        parts += ["THE PLAN (its ACCEPTANCE line is the specification):", read(proposal_path), ""]

    for path in paths[:6]:
        full = os.path.join(MODULE, path)
        if not os.path.exists(full):
            parts += [f"{path} does not exist yet — create it with a FILE block.", ""]
            continue
        body = read(full)
        # Shown whole up to a bound: the writer edits with SEARCH text copied from what it sees.
        cut = "" if len(body) <= EDIT_SHOW_LIMIT else " (TRUNCATED — edit only inside the shown part)"
        parts += [f"EXISTING FILE {path}{cut}:", "```php", body[:EDIT_SHOW_LIMIT], "```", ""]

    # The enums the shown files use: a value not listed in one does not exist (SOC-001 invented one).
    shown = "\n".join(read(os.path.join(MODULE, path)) for path in paths[:6] if os.path.exists(os.path.join(MODULE, path)))
    for enum in sorted(set(re.findall(r"use Modules\\AI\\Enums\\(\w+);", shown)))[:12]:
        enum_file = os.path.join(MODULE, "app/Enums", f"{enum}.php")
        if os.path.exists(enum_file):
            parts += [f"ENUM {enum} (its cases are the only valid values):", "```php", read(enum_file)[:2500], "```", ""]

    # Tests that already build these classes: the house way to make the rows the real tables require.
    for fixture in fixture_examples(paths, skip=example_test):
        parts += [f"TEST THAT ALREADY USES THESE CLASSES {os.path.relpath(fixture, MODULE)} "
                  "(build your fixtures exactly the way this one does):",
                  "```php", read(fixture)[:3000], "```", ""]

    # The tables this task touches, from their migrations: NOT NULL columns are what invented inserts miss.
    for migration in migrations_for(task, paths):
        parts += [f"MIGRATION {os.path.relpath(migration, MODULE)} (NOT NULL columns are required):",
                  "```php", read(migration)[:3000], "```", ""]

    return task, paths, "\n".join(parts), proposal_path


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
    return ledger.lock_path(key)


def claim_is_stale(path):
    """A harness claim dies with its attempt; an agent's lives as long as the agent works the row."""
    owner = ledger.lock_owner(path)
    limit = ledger.AGENT_CLAIM_HOURS * 3600 if owner.startswith("agent ") else CLAIM_STALE_SECONDS

    return time.time() - os.path.getmtime(path) > limit


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
            if attempt or not claim_is_stale(path):
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
    # invariant -> (title, what the cohort is telling us, the file that owns the decision). The file
    # is what makes the row attemptable: a raised row without one was skipped by the implement lane
    # forever, so the cohort kept failing the same invariant and nothing worked on it.
    "NAKED_BESIDE_WALLED": (
        "Defence never reaches every planet: naked planets beside a walled one",
        "The cohort read found accounts with a planet at zero defence while a sibling holds a real "
        "wall, so the wall is being built in one place instead of everywhere it is wanted.",
        "app/Domain/Decision/QueueableUnitPlanner.php",
    ),
    "WALL_CEILING": (
        "Standing wall size is unbounded",
        "The cohort read found single planets holding more defence units than any planet needs. Size "
        "is scaled from exposure with no ceiling, so it grows with production rather than with threat.",
        "app/Domain/Decision/DefenseNeedEvaluator.php",
    ),
    "ALLIANCE_SHARE": (
        "One alliance absorbs the cohort",
        "The cohort read found one alliance holding most of the AI accounts. Nothing in the social "
        "routine spreads founders, so the first club takes everyone who is engaged.",
        "app/Domain/Social/AllianceChoice.php",
    ),
    "IDLE_QUEUES": (
        "Accounts log in and leave most build queues empty",
        "The cohort read found accounts that played this hour with most planets' build queues empty. "
        "A player fills every planet's queue at login; the session's economy fill is "
        "QueueableBuildingPlanner::steps(), so find which planets it returns nothing for, and why.",
        "app/Domain/Decision/QueueableBuildingPlanner.php",
    ),
}


def quality(path):
    """Turn a cohort read's failures into tracked tasks, once each, and reopen what regressed.

    Two verdicts are read: `QUALITY: FAIL` from verify-cohorts (invariants) and `PLAY: FAIL` from
    play-scorecard (aspects of a player's day, named PLAY_<aspect>). Each failing name finds the row
    that answers for it; a done row that fails again is reopened, because a closed row over a failing
    cohort is the report lying. A new row gets the owning file and a proof, so the implement lane can
    take it and `task.py done` can check it.
    """
    text = read(path) if os.path.exists(path) else ""
    fired = []
    for verdict, prefix in ((r"^QUALITY: FAIL ([A-Z_ ]+)$", ""), (r"^PLAY: FAIL ([a-z_ ]+)$", "PLAY_")):
        found = re.search(verdict, text, re.M)
        fired += [prefix + name for name in found.group(1).split()] if found else []
    if not fired:
        print("QUALITY: no failed invariant or aspect in that read — nothing to raise")
        return 0

    owners = dict(re.findall(r"FAIL (\w+) .*\n\s+owner: (\S+)", text))
    cli = os.path.join(MODULE, "plan/tasks/task.py")
    known = quality_task_codes(fired)
    raised, tracked, reopened = [], [], []
    connection = sqlite3.connect(TASKS_DB)

    for name in fired:
        if name in known and known[name][1] != "done":
            tracked.append(f"{name} ({known[name][0]})")
            continue
        if name in known:
            connection.execute("update tasks set status='todo', assignee=null, updated_at=datetime('now'), "
                               "notes=coalesce(notes,'') || ? where code=?",
                               (f" | REOPENED {datetime.datetime.now(datetime.timezone.utc):%Y-%m-%d %H:%M} UTC: "
                                f"{name} fails again on the cohort read.", known[name][0]))
            connection.commit()
            reopened.append(f"{name} ({known[name][0]})")
            continue

        aspect = name[len("PLAY_"):] if name.startswith("PLAY_") else ""
        title, why, owner = QUALITY_TASKS.get(name, (
            f"The cohort does not play {aspect}" if aspect else f"Cohort invariant {name} fires",
            "See play-scorecard.php." if aspect else "See verify-cohorts.php.",
            owners.get(aspect, ""),
        ))
        code = next_quality_code(connection)
        samples = [line.strip() for line in text.splitlines() if f"[{name}]" in line or (aspect and f" {aspect} " in line)][:5]
        subprocess.run([sys.executable, cli, "add", code, title, "impl", "P1", "--gap", name, "--file", owner,
                        "--proof", f"aspect:{aspect}" if aspect else f"invariant:{name}",
                        "--notes", f"{why} Raised from the cohort read's own verdict. "
                                   f"{name} is the dedupe key: do not raise a second task while this one is open.\n"
                                   "Evidence:\n" + "\n".join(samples)],
                       check=True, capture_output=True)
        raised.append(f"{name} ({code})")
        print(f"raised {code} for {name}: {title}")

    connection.close()
    print("quality work: " + ("; ".join(raised) if raised else "nothing new") +
          ("; REGRESSED, reopened: " + ", ".join(reopened) if reopened else "") +
          ("; already tracked: " + ", ".join(tracked) if tracked else ""))
    return 0


def next_quality_code(connection):
    """The next unused QUAL number. `task.py add` replaces a row with the same code, so a guessed
    number could overwrite an unrelated task."""
    numbers = [int(code.split("-")[1]) for (code,) in connection.execute("select code from tasks where code like 'QUAL-%'")
               if code.split("-")[1].isdigit()]

    return f"QUAL-{max(numbers, default=0) + 1:03d}"


def quality_task_codes(names):
    """The row that answers for each name: name -> (code, status), open rows before done ones.

    A whole-word match on gap_ref, title and notes, because that is where a row says what it is
    about. Any name is looked up, not only the ones with a hand-written title: an unlisted name used
    to find nothing, so every pass raised a twin of the row it raised last time.
    """
    if not os.path.exists(TASKS_DB):
        return {}

    connection = sqlite3.connect(TASKS_DB)
    rows = connection.execute(
        "select code, status, coalesce(gap_ref, '') || ' ' || coalesce(title, '') || ' ' || coalesce(notes, '') "
        "from tasks order by status = 'done', id"
    ).fetchall()
    connection.close()

    found = {}
    for name in names:
        for code, state, haystack in rows:
            if re.search(rf"(?<![\w]){re.escape(name)}(?![\w])", haystack):
                found[name] = (code, state)
                break

    return found


def status():
    """What the harness can still do, and what it delivered without proof.

    The queue is the implement lane's own (impl rows at P0-P2 naming a file or a proposal); counting
    only wiki-planned rows read READY: 0 while the P0 rows waited. "Delivered" is a writer marker;
    "proven" is a row `task.py done` closed because its proof passed. Only the second is progress.
    """
    rows = []
    if os.path.exists(TASKS_DB):
        connection = sqlite3.connect(TASKS_DB)
        rows = connection.execute(
            "select code, status, coalesce(file_ref, ''), coalesce(proof, ''), coalesce(notes, '') like '% PROVEN %' from tasks "
            "where kind = 'impl' and priority in ('P0', 'P1', 'P2')").fetchall()
        ready_codes = {code for (code,) in connection.execute("select code from ready_tasks")}
        connection.close()
    else:
        ready_codes = set()

    marked = {code for code, *_ in rows if os.path.exists(os.path.join(IMPLEMENTED, f"{code}.md"))}
    attemptable = [code for code, _, file_ref, _, _ in rows
                   if code in ready_codes and code not in marked
                   and (file_ref or os.path.exists(os.path.join(PROPOSALS, f"{code}.md")))]
    waiting = {code: cooling_off(code) for code in attemptable if 0 < cooling_off(code) < NEVER}
    proofs = {code: proof for code, _, _, proof, _ in rows}
    held = {code: moves_no_failing_aspect(proofs[code]) for code in attemptable if code not in waiting}
    held = {code: why for code, why in held.items() if why}
    ready = [code for code in attemptable if code not in waiting and code not in held and not stuck(code)]
    proven = [code for code, state, _, _, stamped in rows if state == "done" and stamped]
    closed_blind = [code for code, state, _, _, stamped in rows if state == "done" and not stamped]
    unproven = [code for code, state, *_ in rows if code in marked and state != "done"]
    no_proof = [code for code, state, _, proof, _ in rows if state in ("todo", "in_progress", "blocked") and not ledger.on_path(proof)]

    print(f"P0-P2 code rows: {len(proven)} proven, {len(closed_blind)} closed before proofs existed, "
          f"{len(unproven)} delivered but NOT proven, "
          f"{len(ready)} ready now, {len(waiting)} cooling off")
    if unproven:
        print("delivered, not proven: " + ", ".join(sorted(unproven)))
    for code, why in sorted(held.items()):
        print(f"WAITING: {code} — {why}")
    stuck_rows = sorted(os.path.basename(path)[:-len(".stuck")] for path in glob.glob(os.path.join(ATTEMPTS, "*.stuck")))
    if stuck_rows:
        print("STUCK: " + ", ".join(stuck_rows) + "  (same failure twice; `task.py unstick CODE` after a change)")
    if no_proof:
        print("OFF THE NORTH STAR (no aspect, situation or invariant in the proof; cannot be taken or closed): "
              + ", ".join(sorted(no_proof)))
    # Machine-readable for the harness: an empty queue may wait, a queue with attemptable work may not.
    print(f"model today: {usage_today()}")
    print(f"READY: {len(ready)}")
    print(f"UNPROVEN: {' '.join(sorted(unproven))}")

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
                started = time.time()
                with urllib.request.urlopen(request, timeout=MODEL_TIMEOUT_SECONDS) as response:
                    # json.load skips the blank keep-alive lines a queued request receives.
                    data = json.load(response)
                record_usage(purpose, data, time.time() - started)
                return data
            except urllib.error.HTTPError as error:
                if error.code not in MODEL_RETRY_CODES or attempt == MODEL_ATTEMPTS:
                    raise
                wait = retry_after(error) or min(60, 2 ** attempt)
                print(f"  {error.code} from the model on {purpose}; waiting {wait}s "
                      f"(attempt {attempt} of {MODEL_ATTEMPTS})")
                time.sleep(wait + random.uniform(0, 2))
            except (TimeoutError, urllib.error.URLError, ConnectionError) as error:
                # A dropped connection or the server's ten-minute close: retried like a 503, never a
                # crash that loses the worker's whole pass.
                if attempt == MODEL_ATTEMPTS:
                    raise SystemExit(f"model unreachable on {purpose}: {error}")
                wait = min(60, 2 ** attempt)
                print(f"  {type(error).__name__} from the model on {purpose}; waiting {wait}s "
                      f"(attempt {attempt} of {MODEL_ATTEMPTS})")
                time.sleep(wait + random.uniform(0, 2))
        finally:
            if os.path.exists(slot):
                os.remove(slot)

    raise SystemExit("model call fell through every attempt")


def record_usage(purpose, data, seconds):
    """One line per paid call: what it cost in tokens and whether it thought. Peak and off-peak bill
    differently and thinking tokens bill as output, so spend is only visible if it is written down."""
    usage = data.get("usage", {})
    message = (data.get("choices") or [{}])[0].get("message", {})
    line = {
        "at": datetime.datetime.now(datetime.timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
        "worker": os.environ.get("HARNESS_WORKER", ""),
        "purpose": purpose,
        "model": data.get("model"),
        "seconds": round(seconds, 1),
        "prompt": usage.get("prompt_tokens"),
        "cache_hit": usage.get("prompt_cache_hit_tokens"),
        "output": usage.get("completion_tokens"),
        "reasoning": (usage.get("completion_tokens_details") or {}).get("reasoning_tokens"),
        "thinking": bool(message.get("reasoning_content")),
        "finish": (data.get("choices") or [{}])[0].get("finish_reason"),
    }
    with open(MODEL_USAGE, "a", encoding="utf-8") as handle:
        handle.write(json.dumps(line) + "\n")


def model_check():
    """One tiny paid call that says what the API is actually doing: the model it answered with,
    whether it thought, the tokens, the latency, and whether now is peak. Read it before trusting
    any assumption about defaults."""
    now = datetime.datetime.now(datetime.timezone.utc)
    data = model_call({"model": MODEL, "max_tokens": 64,
                       "messages": [{"role": "user", "content": "Reply with the single word: ok"}]},
                      purpose="model-check")
    print(json.dumps({"peak_now": in_peak(now), "concurrency_cap": MODEL_CONCURRENCY,
                      "last_call": json.loads(read(MODEL_USAGE).strip().splitlines()[-1])}, indent=2))
    return 0


def usage_today():
    """Calls and tokens since midnight UTC, from the usage log."""
    if not os.path.exists(MODEL_USAGE):
        return "no model calls recorded yet"
    day = datetime.datetime.now(datetime.timezone.utc).strftime("%Y-%m-%d")
    lines = [json.loads(line) for line in read(MODEL_USAGE).splitlines() if line.startswith(f'{{"at": "{day}')]
    total = lambda key: sum(line.get(key) or 0 for line in lines)  # noqa: E731

    return (f"{len(lines)} call(s), prompt {total('prompt')} (cached {total('cache_hit')}), "
            f"output {total('output')} of which reasoning {total('reasoning')}, "
            f"{sum(1 for line in lines if line.get('finish') != 'stop')} not finished cleanly")


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


# How much of an existing file the writer is shown. Big enough for every planner the module has;
# anything past it can only be reached with EDIT blocks, never rewritten whole.
EDIT_SHOW_LIMIT = 40000
VERIFY_LANE = "verify:shared-test-database"
FILE_BLOCK = re.compile(r"### FILE:\s*(\S+?)\s*\n+```[a-zA-Z]*\n(.*?)\n```[ \t]*(?:\n|$)", re.S)
EDIT_BLOCK = re.compile(r"### EDIT:\s*(\S+?)\s*\n(.*?)(?=^### (?:FILE|EDIT):|\Z)", re.S | re.M)
SEARCH_REPLACE = re.compile(r"<<<<<<< SEARCH\n(.*?)\n?=======\n(.*?)\n?>>>>>>> REPLACE", re.S)


def proposal_aspect(blocks):
    """The scorecard aspect a plan says it moves, or None when it names none that exists."""
    named = " ".join(blocks.get("ASPECT", [])).strip().strip("`").split(" ")[0].lower() if blocks.get("ASPECT") else ""

    return named if named in scorecard_aspects() else None


def scorecard_aspects():
    """The aspects of play the scorecard measures, read from it, so a plan names one that exists."""
    source = read(os.path.join(MODULE, "scripts/play-scorecard.php"))

    return re.findall(r"^\s{8}'(\w+)' => \['", source, re.M)


def scenario_required_keys():
    """The keys the replay refuses a scenario without, read from the replay itself so they never drift."""
    source = read(os.path.join(MODULE, "app/Actions/ReplayAiScenarioAction.php"))
    found = re.search(r"foreach \(\[([^\]]*)\] as \$key\)", source)

    return re.findall(r"'(\w+)'", found.group(1)) if found else []


def scenario_problems(paths):
    """What the replay would refuse in these scenarios, found without starting the app."""
    problems = []
    for clean in paths:
        if not clean.startswith("resources/scenarios/"):
            continue
        try:
            body = json.loads(read(os.path.join(MODULE, clean)))
        except ValueError as error:
            problems.append(f"{clean} is not valid JSON: {error}")
            continue
        missing = [key for key in scenario_required_keys() + ["expect"] if key not in body]
        if missing:
            problems.append(f"{clean} is missing {', '.join(missing)} — copy the EXAMPLE SCENARIO's shape")

    return problems


# The provider counts reasoning tokens inside max_tokens. Measured 1 Oct 2026 over 41 finished writer
# calls: the answer is a median 2.5k tokens (max 8.7k), but total output was a median 45k, 95% of it
# reasoning at the default effort; a 6.7k cap returned finish=length and no answer. With thinking off
# the answers were fast but careless (an EDIT of a file that does not exist, code without its test).
# So thinking stays on at low effort. Low effort still reasons 9k-32k+ tokens, and a truncated call is
# billed in full, so the cap is the provider's own ceiling (65,536, where 24 default-effort calls stopped).
WRITER_MAX_TOKENS = 65536
WRITER_THINKING = {"type": "enabled"}
WRITER_REASONING_EFFORT = "low"


def writer_answer(code, context, answer_file=None):
    """The writer's answer and its token usage: a saved answer when one is given, else a paid call.

    Every paid answer is kept beside the attempt log, so a refusal or a failing check can be re-run
    against the same answer with `implement CODE --answer FILE` for nothing. An answer the model did
    not finish is (None, {"finish_reason": ...}): nothing to write, and a failure the caller records.
    """
    if answer_file:
        return read(answer_file), {}

    payload = {"model": MODEL, "max_tokens": WRITER_MAX_TOKENS, "thinking": WRITER_THINKING, "reasoning_effort": WRITER_REASONING_EFFORT,
               "messages": [
                   {"role": "system", "content": IMPLEMENT_PROMPT},
                   {"role": "user", "content": context},
               ]}
    data = model_call(payload, purpose=f"implementing {code}")
    choice = data["choices"][0]
    if choice.get("finish_reason") != "stop":
        return None, {"finish_reason": choice.get("finish_reason")}

    answer = choice["message"]["content"] or ""
    os.makedirs(ATTEMPTS, exist_ok=True)
    with open(os.path.join(ATTEMPTS, f"{code}.answer.md"), "w", encoding="utf-8") as handle:
        handle.write(answer)

    return answer, data.get("usage", {})


def apply_edits(clean, body, current):
    """The file after its SEARCH/REPLACE pairs, or (None, why) when a SEARCH is not there exactly once."""
    pairs = SEARCH_REPLACE.findall(body)
    if not pairs:
        return None, f"{clean} (EDIT block without a SEARCH/REPLACE pair)"

    for search, replace in pairs:
        count = current.count(search)
        if count != 1:
            where = "is not in the file" if count == 0 else f"matches {count} places"
            return None, (f"{clean} (SEARCH text {where}; copy it exactly from the EXISTING FILE, "
                          f"starting: {search.strip().splitlines()[0][:80] if search.strip() else 'empty'})")
        current = current.replace(search, replace, 1)

    return current, None


def parse_answer(answer):
    """Each path's new contents and every refused block, before anything touches the disk."""
    contents, refused = {}, []

    for path, content in FILE_BLOCK.findall(answer):
        clean = path.strip("`")
        full = os.path.join(MODULE, clean)
        if os.path.exists(full) and len(read(full)) > EDIT_SHOW_LIMIT:
            # The writer was shown only part of this file, so a whole-file answer would delete the rest.
            refused.append(f"{clean} (longer than you were shown — change it with an EDIT block)")
            continue
        contents[clean] = content

    for path, body in EDIT_BLOCK.findall(answer):
        clean = path.strip("`")
        full = os.path.join(MODULE, clean)
        current = contents.get(clean, read(full) if os.path.exists(full) else None)
        if current is None:
            refused.append(f"{clean} (EDIT of a file that does not exist — create it with a FILE block)")
            continue
        edited, why = apply_edits(clean, body, current)
        if why:
            refused.append(why)
            continue
        contents[clean] = edited

    accepted = {}
    for clean, content in contents.items():
        why = path_refusal(clean) or duplicate_class(content, clean)
        if why:
            refused.append(f"{clean} ({why})")
            continue
        accepted[clean] = content

    return accepted, refused


def write_changes(code, changes):
    """Write the accepted contents: (written, test names, backups), or (None, ...) on a claim conflict."""
    written, tests, backups, late_claims = [], [], {}, []

    for clean, content in changes.items():
        full = os.path.join(MODULE, clean)
        # The answer may name a file the plan never listed, and the claim was taken from the plan.
        # Claimed before the write: rolling back after a collision only hides it.
        if not os.path.exists(claim_path(full)):
            if not take_claim(full):
                release_claims(late_claims)
                rollback(written, backups)
                print(f"  left for the next pass: another worker is writing {clean}")
                publish("working", f"{code} waiting on {clean}")
                return None, [], {}
            late_claims.append(claim_path(full))
            HELD_CLAIMS.append(claim_path(full))
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

    return written, tests, backups


def invented_factories(paths):
    """`Model::factory()` calls on module models that have no factory. ATK-001 spent its attempt on
    `AiProfile::factory()`; the module's models are built with create() in every test."""
    invented = []
    for clean in [path for path in paths if path.endswith(".php")]:
        for name in sorted(set(re.findall(r"\b(\w+)::factory\(", read(os.path.join(MODULE, clean))))):
            model = os.path.join(MODULE, "app/Models", f"{name}.php")
            if os.path.exists(model) and "HasFactory" not in read(model):
                invented.append(f"{name}::factory() in {clean}")

    return invented


def lost_contract(backups):
    """Public methods and interfaces an edit removed while other code still uses them.

    A slice rewrote BuildAiPilotReportAction without handle() and QueueAiBuildingAction without its
    interface; their own tests passed and /admin/ai answered 500 (HARNESS-001). A grep finds it.
    """
    lost = []
    for full, before in backups.items():
        if not full.endswith(".php"):
            continue
        after = read(full)
        clean = os.path.relpath(full, MODULE)
        methods = r"public\s+(?:static\s+)?function\s+(\w+)"
        for name in sorted(set(re.findall(methods, before)) - set(re.findall(methods, after))):
            callers = [hit for hit in subprocess.run(
                ["grep", "-rlE", rf"(->|::){name}\(", "app/", "tests/", "--include=*.php"],
                cwd=MODULE, capture_output=True, text=True).stdout.split() if hit != clean]
            if callers:
                lost.append(f"{clean} no longer has public {name}(), which {callers[0]} calls")
        interfaces = r"implements\s+([\w\\, ]+?)\s*\{"
        had = {part.strip() for found in re.findall(interfaces, before) for part in found.split(",")}
        has = {part.strip() for found in re.findall(interfaces, after) for part in found.split(",")}
        lost += [f"{clean} no longer implements {name}" for name in sorted(had - has)]

    return lost


def verify_slice(code, written, tests, backups):
    """Why the written slice is not a delivery, or None. Cheapest checks first.

    The static checks used to run after the Pest suites, so a slice nothing calls spent a full test
    cycle before being told so. They cost a grep; the suites cost minutes in the shared lane.
    """
    broken = []
    for clean in [path for path in written if path.endswith(".php")]:
        lint = subprocess.run(["docker", "compose", "exec", "-T", "ogamex-app", "php", "-l",
                               f"/var/www/Modules/AI/{clean}"],
                              cwd=COMPOSE_DIR, capture_output=True, text=True)
        # php -l exits 0 on a compile warning such as a bare `use FilesystemIterator;` in a file
        # with no namespace, yet Pest turns that warning into an exception that stops the whole
        # suite from being collected. A warning is a refusal.
        output = (lint.stdout + lint.stderr).strip()
        if lint.returncode != 0 or "Warning:" in output:
            broken.append(f"{clean}: {output.splitlines()[0]}")
    if broken:
        return "generated code does not parse:\n" + "\n".join(broken)

    clashes = helper_clashes(written)
    if clashes:
        return ("your test declares functions another test file already declares, which stops the whole "
                "suite loading: " + ", ".join(clashes) + ". Rename them to names unique to your test.")

    # The row's own fast proof (a `test:` step, e.g. its situation test) verifies the slice as well as a
    # test the writer wrote would, so a slice that makes it pass needs no second test of its own.
    tests = tests + [name for name in proof_tests(code) if name not in tests]
    if not tests:
        return "the answer wrote files but no test, so nothing verified the behaviour"

    invented = invented_factories(written)
    if invented:
        return ("these model factories do not exist: " + ", ".join(invented) +
                ".\nBuild the row with Model::create([...]) the way the TEST THAT ALREADY USES THESE "
                "CLASSES does, with every NOT NULL column from the migration.")

    lost = lost_contract(backups)
    if lost:
        return ("your edit removed code other files still use:\n" + "\n".join(lost) +
                "\nKeep every public method and interface; change only the lines the task needs.")

    unreachable = unreachable_files(written, backups)
    if unreachable:
        return ("these files are not called by any runtime code, so no account can ever execute them: "
                + ", ".join(unreachable) +
                ".\nDo not write a new class. EDIT the planner, engine or action that already owns this "
                "decision, and have the Feature test drive that path. A data file under resources/behavior "
                "must be loaded by name in that class.")

    inlined = inlined_policy(written, plan_numbers(code))
    if inlined:
        return ("these values decide behaviour but were written into PHP: " + ", ".join(inlined) +
                ".\nPut them in a YAML file under resources/behavior/ (extend one that exists when it "
                "covers the topic) and read them from there.")

    problems = scenario_problems(written)
    if problems:
        return "the scenario would be refused before it runs:\n" + "\n".join(problems)

    # The slice's own tests, its proof tests and every test that names a class it touched, in ONE
    # parallel Pest run: one boot instead of one per file (a planner edit used to mean ~19 serial runs).
    names = tests + [name for name in affected_tests(written) if name not in tests]
    pattern = "|".join(re.escape(name) for name in names)
    code_rc, output = run_in_app(f"timeout -k 5 300 ./vendor/bin/pest --testsuite=Modules --parallel "
                                 f"--processes=4 --bail --filter='({pattern})'")
    clean = re.sub(r"\x1b\[[0-9;]*m", "", output)
    summary = [line.strip() for line in clean.splitlines() if "Tests:" in line]
    # A summary line only means the suite RAN: six failed tests still print one.
    if not (summary and code_rc == 0):
        print(f"  {len(names)} test file(s): FAIL {' '.join(summary[-1:]) or 'not collectable'}")
        failed = clean.find("FAILED")
        detail = (clean[failed:failed + 2500] if failed >= 0 else clean.strip()[-2500:]).strip()
        return f"tests ({', '.join(names)}): {' '.join(summary[-1:]) or 'suite not collectable'}\n\n{detail}"
    print(f"  {len(names)} test file(s): PASS {summary[-1]}")

    # The tailored proof: a described situation plus the action the engine must choose under it.
    for scenario in [path for path in written if path.startswith("resources/scenarios/")]:
        name = os.path.basename(scenario)[:-5]
        run_rc, run_output = run_in_app(f"php artisan ai:replay-scenario {name}")
        if run_rc != 0:
            detail = re.sub(r"\x1b\[[0-9;]*m", "", run_output).strip()[-1500:]
            print(f"  scenario {name}: FAIL — the engine did not do what the rule says")
            return f"the scenario {name} did not hold:\n{detail}"
        print(f"  scenario {name}: holds under these conditions")

    return None


def claim_row(code, worker):
    """Take the row for this attempt, or say someone else holds it. Guarded like `task.py claim`."""
    connection = sqlite3.connect(TASKS_DB, timeout=30)
    taken = connection.execute(
        "update tasks set status='in_progress', assignee=?, updated_at=datetime('now') "
        "where code=? and (status='todo' or assignee=?)", (worker, code, worker)).rowcount
    connection.commit()
    connection.close()

    return taken == 1


def release_row(code, worker):
    """Give a row back after a failed attempt, only if this worker still holds it."""
    connection = sqlite3.connect(TASKS_DB, timeout=30)
    # Only a row still in progress: one an operator froze or blocked meanwhile stays as they left it.
    connection.execute("update tasks set status='todo', assignee=null where code=? and assignee=? and status='in_progress'",
                       (code, worker))
    connection.commit()
    connection.close()


def mark_delivered(code, worker, kept):
    """A delivered row waits for its proof: still in progress, held by no worker, so no agent takes
    it and no attempt repeats it. `task.py done` closes it when the proof passes."""
    kept.append(True)
    connection = sqlite3.connect(TASKS_DB, timeout=30)
    connection.execute("update tasks set assignee='harness:delivered', updated_at=datetime('now') "
                       "where code=? and assignee=?", (code, worker))
    connection.commit()
    connection.close()


def implement(code, answer_file=None):
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
    if cooldown >= NEVER:
        say(f"{code}: stuck on a repeated failure — waits for `task.py unstick {code}`")
        return 0
    if cooldown > 0:
        # Not parked: the budget ages out and the task is picked up again on its own.
        say(f"{code}: cooling off for another {cooldown // 3600}h {cooldown % 3600 // 60}m")
        publish("working", f"skipped {code} (cooling off, retries itself)")
        return 0

    # The row itself, in the ledger every agent claims from: a file lock alone let an interactive
    # agent and a harness worker take the same task and both write it.
    if not ledger.on_path(task_row(code)["proof"]):
        # Never pay for work that moves no aspect of play: the writer would be busy, not useful.
        say(ledger.off_path_reason(code))
        return 0

    held = moves_no_failing_aspect(task_row(code)["proof"])
    if held:
        say(f"{code}: nothing to move — {held}")
        return 0

    worker = "harness:" + os.environ.get("HARNESS_WORKER", f"pid-{os.getpid()}")
    if not claim_row(code, worker):
        say(f"{code}: claimed by someone else — left alone")
        return 0
    kept = []
    atexit.register(lambda: kept or release_row(code, worker))

    # Red first: a saved answer costs nothing, but a paid first attempt is only bought for a proof that
    # fails and can be moved by code.
    first_attempt = not os.path.exists(os.path.join(ATTEMPTS, f"{code}.count"))
    if first_attempt and not answer_file and red_first(code):
        return 0

    publish("implementing", code)
    task, paths, context, proposal_path = implement_context(code)

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
            mark_delivered(code, worker, kept)
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
        context += ("\n\nYOUR PREVIOUS ATTEMPT WAS REJECTED. The check said:\n"
                    + failure[:2000]
                    + "\nFix exactly that and change nothing else.")

    answer, usage = writer_answer(code, context, answer_file)
    if answer is None:
        record_failure(code, f"unfinished: {usage['finish_reason']}")
        return 1
    changes, refused = parse_answer(answer)

    # What was refused, in the words the retry needs. Without this the retry only saw the test
    # failure it caused: a plan whose data file was refused leaves code that reads a file nobody
    # wrote, and the model repeats the same shape until its attempts run out.
    refusal_note = ""
    if refused:
        refusal_note = "\n\nThese blocks from your answer were refused and NOT written:\n- " + "\n- ".join(refused)

    print(f"{code} [{task['status']}]: {len(changes)} file(s) to write, refused {len(refused)}")
    for reason in refused:
        print(f"  ! {reason}")
    if usage:
        print(f"  tokens: prompt {usage.get('prompt_tokens')} "
              f"(cached {usage.get('prompt_cache_hit_tokens')}) output {usage.get('completion_tokens')}")

    if not changes:
        # Two different mistakes that used to share one message: an answer in prose, and an answer
        # whose every block was refused. Telling the second "you wrote no FILE blocks" made it resend
        # the same refused blocks (ALLY-001 spent its attempts that way).
        if refused:
            record_failure(code, "every block in your answer was refused, so nothing was written." + refusal_note)
            return 1
        print(f"  no blocks in the answer — {answer.strip().splitlines()[0][:100] if answer.strip() else 'empty reply'}")
        record_failure(code, "your answer contained no ### FILE or ### EDIT blocks, so nothing could be "
                             "written. Reply with the blocks only, in the documented format.\n"
                             "--- your previous answer began:\n" + answer.strip()[:1200])
        return 1

    # Writing and verifying happen inside the one verification lane. Files written outside it sat
    # on disk, unverified, while another worker's Pest run collected the whole suite -- one worker's
    # broken file failed the other's slice, and the retry chased an error it never made.
    lane = await_claim(VERIFY_LANE)
    if lane is None:
        print("  left for the next pass: the verification lane stayed held")
        return 0
    try:
        written, tests, backups = write_changes(code, changes)
        if written is None:
            return 0
        for path in written:
            print(f"  + {path}")

        rejection = verify_slice(code, written, tests, backups)
        if rejection:
            print(f"  unverified, restored {rollback(written, backups)} file(s)")
            record_failure(code, rejection + refusal_note)
            return 1
    finally:
        release_claims([lane])

    # The slice's own tests pass; now ask whether it moved the row's proof. The proof takes the same
    # lane, so it runs after the release, with the written files still in place.
    baseline_path = os.path.join(ATTEMPTS, f"{code}.baseline.json")
    after = None
    if os.path.exists(baseline_path) and not answer_file:
        after = proof_report(code)
        if after is None:
            print(f"  the proof of {code} could not be run after the attempt; restored {rollback(written, backups)} file(s), nothing counted")
            return 0
        why = proof_change(json.loads(read(baseline_path)), after)
        if why:
            print(f"  {why.splitlines()[0]}; restored {rollback(written, backups)} file(s)")
            record_failure(code, why)
            return 1

    os.makedirs(IMPLEMENTED, exist_ok=True)
    with open(marker, "w", encoding="utf-8") as handle:
        handle.write(f"# {code} implemented {datetime.datetime.now(datetime.timezone.utc):%Y-%m-%d %H:%M} UTC\n\n"
                     + "\n".join(f"- {path}" for path in written) + "\n")
    mark_delivered(code, worker, kept)
    if after is not None and note_delivery(code, after):
        print(f"  {code}: the same proof step failed on three deliveries; blocked as proof suspect")
    print(f"  verified and wired: {len(written)} file(s) kept, marker written")
    publish("implemented", f"{code} ({len(written)} file(s))")
    return 0


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("command", nargs="?",
                        choices=["bundle", "plan", "validate", "run", "sweep", "promote",
                                 "coverage", "implement", "wait-until-offpeak", "publish", "peak-gate",
                                 "quality", "status", "model-check", "reopen"])
    parser.add_argument("source", nargs="?")
    parser.add_argument("--max", type=int, default=4, help="run/sweep: work allowed this pass")
    parser.add_argument("--shard", help="run: slice k/N of the queue, so N workers cover it once")
    parser.add_argument("--dry", action="store_true", help="promote: report without writing rows")
    parser.add_argument("--self-check", action="store_true")
    parser.add_argument("--answer", help="implement: verify this saved answer instead of paying for one")
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
    if args.command == "model-check":
        return model_check()
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
        return implement(args.source, args.answer)
    if args.command == "reopen":
        return reopen(args.source)
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
