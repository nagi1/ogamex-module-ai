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
import fnmatch
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
import threading
import time
import urllib.error
import urllib.parse
import urllib.request

MODULE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
# The host application, one level above the module directory: the model is never shown the repository,
# so the host class names it may call have to be listed for it.
ROOT = os.path.dirname(os.path.dirname(MODULE))
CONTEXT = os.path.join(MODULE, "plan/research/ogame/context")
PROPOSALS = os.path.join(MODULE, "plan/research/ogame/proposals")
IMPLEMENTED = os.path.join(MODULE, "plan/research/ogame/implemented")
ATTEMPTS = os.path.join(MODULE, "plan/research/ogame/attempts")


# The writer works like an engineer at a terminal: it reads, searches and edits a working copy and runs
# the slice's checks itself, so a misquoted SEARCH or a guessed column costs one tool call, not an
# attempt. The working copy outlives a failed check and a failed attempt (ATTEMPTS/CODE.work.json); the
# bounds below stop a writer that circles. One attempt: at most this many model turns, checks, seconds.
# Past this prompt size the conversation restarts from the task plus the working copy's diff and the last
# check, instead of growing until the provider truncates it. The work is kept; only the chatter goes.
AGENT_CONTEXT_TOKENS = 800_000
# The same call with nothing changed in between, or the same check failure, this many times ends the attempt.
# Consecutive read-only calls before reading is refused and the writer must edit or give up.
AGENT_READ_STREAK = 80
TOOL_RESULT_CHARS = 80_000
READ_LINES = 3_000
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
# A wall-clock limit on one call. The socket timeout never fires on a queued request, because the provider
# sends keep-alive blank lines while it waits, and then gives up itself at 900 s with an error body (twice
# on 1 Oct 2026, 15 minutes of a writer each). 108 finished calls took at most 263 s (p50 122 s).
MODEL_DEADLINE_SECONDS = 8 * 60
# A key that stalled or was refused is skipped for this long (a stall is the provider queueing the account's
# requests: retrying at once queues again). Auth and balance failures cool for hours.
KEY_STALL_SECONDS = 10 * 60
KEY_RATE_LIMIT_SECONDS = 2 * 60
KEY_REFUSED_SECONDS = 6 * 3600
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
TASKS_DB = os.path.join(MODULE, "plan/tasks/tasks.db")
ROUTING = os.path.join(MODULE, "config/routing.php")
HOST_ENV = os.path.abspath(os.path.join(MODULE, "..", "..", ".env"))

# Three writers share one log. A line a writer prints is tagged with its shard and flushed at once, or the
# overview shows an unlabelled mix that arrives minutes late (the call to the model is silent for 2-10).
_print = print


def print(*args, **kwargs):  # noqa: A001
    worker = os.environ.get("HARNESS_WORKER")
    if worker and not kwargs.get("file"):
        args = (f"[{worker}]",) + args
        kwargs["flush"] = True
    _print(*args, **kwargs)
KEYS_ENV = os.path.join(MODULE, ".env")
KEY_COOLDOWNS = os.path.join(MODULE, "plan/research/ogame/key-cooldown")

API = "https://api.deepseek.com/chat/completions"
MODEL = "deepseek-flash"
# Mirror of config/routing.php 'deepseek_peak' — asserted equal by self-check and
# before every planning call, so the gate cannot quietly drift from the router.
PEAK_WEEKDAYS = [1, 2, 3, 4, 5]  # ISO: Mon-Fri
PEAK_WINDOWS = [("01:00", "04:00"), ("06:00", "10:00")]

def read(path):
    with open(path, encoding="utf-8") as handle:
        return handle.read()


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
    # Owner switch: the peak window is a price gate, not a correctness one. Off unless asked for.
    if os.environ.get("HARNESS_IGNORE_PEAK") == "1":
        return False
    if now.isoweekday() not in PEAK_WEEKDAYS:
        return False
    clock = now.strftime("%H:%M")
    return any(start <= clock < end for start, end in PEAK_WINDOWS)


def api_keys():
    """Every DeepSeek key the harness may use, in order, without repeats: DEEPSEEK_API_KEY_1.. and
    DEEPSEEK_API_KEY from the module's own .env (Modules/AI/.env, ignored by git), then the host's
    DEEPSEEK_API_KEY. A blank value is skipped, so a half-filled file is fine."""
    keys = []
    for path in (KEYS_ENV, os.path.abspath(HOST_ENV)):
        if not os.path.exists(path):
            continue
        found = re.findall(r"^DEEPSEEK_API_KEY(?:_\d+)?=(.*)$", read(path), re.M)
        keys += [value.strip().strip("\"'") for value in found if value.strip().strip("\"'")]
    unique = list(dict.fromkeys(keys))
    if not unique:
        raise SystemExit("no DeepSeek key: set DEEPSEEK_API_KEY_1.. in Modules/AI/.env (see .env.example)")
    return unique


def key_tag(key):
    """What may be printed of a key: its last four characters, never the key."""
    return "…" + key[-4:]


def key_file(key):
    return os.path.join(KEY_COOLDOWNS, hashlib.sha256(key.encode()).hexdigest()[:12])


def cool_key(key, seconds, reason):
    """Keep one key out of use for a while. Its file's mtime plus the seconds inside is when it is ready."""
    os.makedirs(KEY_COOLDOWNS, exist_ok=True)
    with open(key_file(key), "w", encoding="utf-8") as handle:
        handle.write(f"{int(seconds)} {reason}\n")


def key_cooling(key):
    """Seconds this key still has to cool, 0 when it is ready."""
    path = key_file(key)
    if not os.path.exists(path):
        return 0
    seconds = int(read(path).split(" ", 1)[0] or 0)
    left = os.path.getmtime(path) + seconds - time.time()
    if left <= 0:
        os.remove(path)
    return max(0, int(left))


def choose_key(slot_index):
    """The key for this connection slot, or None when every key is cooling. Slots are exclusive, so each
    slot prefers its own key (slot i -> key i mod n): four writers on four keys never share one, and a
    cooling key is skipped by whichever slot would have used it."""
    keys = api_keys()
    for offset in range(len(keys)):
        key = keys[(slot_index + offset) % len(keys)]
        if key_cooling(key) == 0:
            return key
    return None


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


def block_row(code, note):
    """Take a row out of the queue with the reason in its notes: the ledger's own `block`."""
    if not os.path.exists(TASKS_DB):
        return
    connection = sqlite3.connect(TASKS_DB, timeout=30)
    ledger.cmd_block(connection, code, note)
    connection.close()


def record_failure(code, reason):
    """Keep a failed delivery's output as the writer's next input. Nothing is counted against the row and
    nothing stops it: the next attempt resumes the kept working copy with this reason."""
    os.makedirs(ATTEMPTS, exist_ok=True)
    with open(os.path.join(ATTEMPTS, f"{code}.count"), "w", encoding="utf-8") as handle:
        handle.write("1")
    with open(os.path.join(ATTEMPTS, f"{code}.log"), "w", encoding="utf-8") as handle:
        handle.write(reason)


def waits_upstream(code, failing):
    """The open rows this one depends on, when every failing proof step is live; else [].

    A live step can fail for a cause upstream: no raid is flown while no account spies (DEF-33 on
    ATK-001), no debris is recycled while nobody fights (FLEET-002), 1 Oct 2026. The writer cannot fix
    that from this row's files, so such a row neither goes back to the writer nor buys an attempt.
    """
    if any(line.startswith("FAIL test:") for line in failing):
        return []
    connection = sqlite3.connect(TASKS_DB, timeout=30)
    try:
        return [row[0] for row in connection.execute(
            "select d.code from dependencies x join tasks t on t.id=x.task_id join tasks d on d.id=x.depends_on "
            "where t.code=? and d.status!='done'", (code,))]
    finally:
        connection.close()


def live_evidence(failing):
    """What the live cohort shows behind a failed live step, for the writer: the last half hour of the
    cohort (work, refusals, missions) and what the situation's subject account sees and decides. A bare
    "raids 0 floor 1" told the retry nothing about why (1 Oct 2026)."""
    if not any(line.startswith(("FAIL situation:", "FAIL aspect:", "FAIL invariant:")) for line in failing):
        return ""
    env = dict(os.environ, OGAMEX_RUNNER=os.environ.get("OGAMEX_RUNNER", "local-docker-dev"))
    parts = []
    for command in (["pulse", "30"], ["why", "subject"]):
        try:
            result = subprocess.run(["bash", os.path.join(MODULE, "scripts/ogamex"), *command],
                                    capture_output=True, text=True, timeout=120, env=env)
            parts.append(f"--- ogamex {' '.join(command)} (live, now):\n" + result.stdout.strip()[:2500])
        except subprocess.TimeoutExpired:
            continue
    return "\n\nLIVE EVIDENCE\n" + "\n".join(parts) if parts else ""


def still_passes(line):
    """True when a `FAIL test:Name` proof line names a test that now passes on its own."""
    match = re.match(r"FAIL test:(\w+)", line)
    if not match:
        return False
    env = dict(os.environ, OGAMEX_RUNNER=os.environ.get("OGAMEX_RUNNER", "local-docker-dev"))
    result = subprocess.run(["bash", os.path.join(MODULE, "scripts/ogamex"), "test-one", match.group(1)],
                            capture_output=True, text=True, timeout=600, env=env)

    return '"result":"passed"' in result.stdout


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

    # A test that failed beside a running writer or a concurrent suite and passes alone is not the
    # writer's to fix: ATK-001 went back for a 40-turn attempt on a test that passed six runs in a row.
    failing = [line for line in failing if not still_passes(line)]
    if not failing:
        print(f"{code}: its failing test steps pass on a re-run; stays delivered for the next proof")
        return 0

    upstream = waits_upstream(code, failing)
    if upstream:
        print(f"{code}: its live proof fails, but it waits on {', '.join(upstream)}; stays delivered")
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
    record_failure(code, "the delivered change passed its tests but its live proof failed:\n" + "\n".join(text.splitlines()[:30])
                   + live_evidence(failing))
    print(f"{code}: back to the writer with the failing proof step")

    return 0


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
    """The row's test steps as `prove CODE --json` reports them, or None when they could not run.

    Fast on purpose: an attempt is judged on its tests, which take seconds. The live steps (a situation
    on the cohort took up to 183 s, an aspect needs an hour of play) judge a delivered row afterwards, and
    a failure there goes back to the writer through `reopen`.
    """
    env = dict(os.environ, OGAMEX_RUNNER=os.environ.get("OGAMEX_RUNNER", "local-docker-dev"), PROVE_FAST="1")
    if claim_path(VERIFY_LANE) in HELD_CLAIMS:
        env["OGAMEX_LANE_HELD"] = "1"  # this attempt holds the lane already; waiting on it would deadlock
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
        # Its tests pass; the live steps may not. `done` runs the whole proof, so only a pass settles it.
        if subprocess.run([sys.executable, os.path.join(MODULE, "plan/tasks/task.py"), "done", code]).returncode == 0:
            print(f"  the proof of {code} already passes; closed, nothing spent")
            return True
        log = os.path.join(MODULE, "plan/research/ogame/proofs", f"{code}.log")
        failed = [line for line in (read(log).splitlines() if os.path.exists(log) else []) if line.startswith("FAIL ")]
        upstream = waits_upstream(code, failed)
        if upstream:
            print(f"  the tests of {code} pass; its live proof waits on {', '.join(upstream)}; nothing spent")
            return True
        print(f"  the tests of {code} pass but its live proof does not; the attempt works from that failure")
        return False
    if all(step.get("suspect") for step in failing):
        print(f"  every failing step of {code} is suspect; nothing spent")
        block_row(code, "proof suspect: only " + ", ".join(step["step"] for step in failing) + " fails; the reviewer judges the proof")
        return True

    return False


def proof_change(before, after):
    """Why an attempt moved nothing, or None when its fast steps pass or one went FAIL to PASS and none
    went PASS to FAIL. Only `test:` steps are judged here: a live situation, an aspect or an invariant is
    measured over an hour of cohort play, cannot flip within one attempt, and is judged later by
    `task.py done` on the delivered row (FLEET-002 went stuck on `aspect:recycle` for that reason)."""
    fast = lambda report: [step for step in report["steps"] if step["step"].split(":")[0] == "test"]
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


def known_red_stories():
    """Situation stories failing on the newest behaviour board (scripts/stories.py)."""
    path = os.path.join(MODULE, "plan/research/ogame/stories.json")
    board = json.loads(read(path)) if os.path.exists(path) else {}
    return {story["test"] for story in board.get("stories", []) if not story["pass"]}


def moves_no_failing_aspect(proof):
    """Why no step of this proof currently fails, or None when one does or nothing says. Only an
    aspect: or invariant: step with a read newer than the cohort reset can hold a row back."""
    aspects, invariants = live_aspects(), violated_invariants()
    judged = []

    # A story on the behaviour board is seconds old where a cohort read is hours old: a row whose
    # story fails has work to do even while its live aspect passes (QUAL-006, 1 Oct 2026: research
    # "passed" live while an account with a lab and 2M stock queued none).
    failing = known_red_stories()
    if any(step.startswith("test:") and step[5:] in failing for step in proof.split()):
        return None

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

    # A repeat failure is the terminal state: digits, paths, ids and durations do not make two failures
    # differ, and the second identical one stops the row for good.
    assert failure_signature("Tests: 2 failed 1.04s at /var/www/a.php:32 row 18915 c0ffee12ab") == \
        failure_signature("Tests: 7 failed 2.5s at /tmp/b.php:41 row 99 deadbe3f12"), "volatile parts are not the failure"
    assert failure_signature("no test") != failure_signature("no tests found"), "different words are different failures"
    red, green = {"pass": False, "steps": [{"step": "test:X", "pass": False, "line": "boom"}]}, {"pass": True, "steps": []}
    assert proof_change(red, green) is None, "a proof that passes is accepted"
    assert proof_change(red, red).startswith("proof unchanged: test:X boom"), "an attempt that moved nothing is refused"
    two = {"pass": False, "steps": [{"step": "test:X", "pass": True, "line": ""}, {"step": "aspect:a", "pass": False, "line": "n"}]}
    assert proof_change(red, two) is None, "a step that went FAIL to PASS is progress"
    assert proof_change(two, red).startswith("proof regressed"), "a step that went PASS to FAIL is refused"
    assert WRITER_MAX_TOKENS >= 1.5 * 15540 / 3.5 - 100, "the cap follows the largest saved answer"

    # The writer's tools: an edit lands once in the working copy and never on disk; a miss shows the real
    # lines; credentials are unreadable; a saved copy whose base moved is dropped, not replayed.
    working, held = {}, set()
    probe_code = f"__self-check-work-{os.getpid()}__"
    try:
        result = tool_edit({"path": target, "old_string": anchor, "new_string": anchor + " // probe"}, working, held)
        assert result.startswith("edited") and working[target].count("// probe") == 1 and "// probe" not in read(target_full), \
            "an edit changes the working copy only"
        assert "// probe" in tool_read({"path": target}, working), "reads see the working copy"
        assert "// probe" in tool_search({"pattern": "// probe", "path": "app/Actions"}, working), "search sees the working copy"
        missed = tool_edit({"path": target, "old_string": "throw new RuntimeException('Scenario file not found: ' . $file);", "new_string": "x"}, working, held)
        assert missed.startswith("refused") and "Scenario file not found" in missed, "a miss shows the closest real lines"
        assert tool_write({"path": target, "content": "<?php\n"}, working, held).startswith("refused"), "an existing file is never rewritten"
        assert tool_read({"path": ".env", "host": True}, working).startswith("refused"), "credentials are not readable"
        assert tool_read({"path": "plan/research/ogame/attempts/x.log"}, working).startswith("refused"), "harness state is not readable"
        assert not [hit for hit in tool_search({"pattern": "implementing"}, working).splitlines()
                    if hit.startswith("plan/research/ogame/")], "search never returns harness logs"
        assert tool_read({"path": "../../../etc/passwd"}, working).startswith("refused"), "nothing outside the repository"
        save_work(probe_code, working, "boom")
        assert load_work(probe_code)[0] == working and load_work(probe_code)[1] == "boom", "the copy survives the attempt"
        with open(work_path(probe_code), encoding="utf-8") as handle:
            saved = json.load(handle)
        saved["bases"][target] = "moved"
        with open(work_path(probe_code), "w", encoding="utf-8") as handle:
            json.dump(saved, handle)
        assert load_work(probe_code)[2] == [target], "a file changed under the copy is dropped"
    finally:
        drop_work(probe_code)
        release_claims([claim_path(key) for key in held])

    probe = "app/Support/__rollback_probe.php"
    with open(os.path.join(MODULE, probe), "w", encoding="utf-8") as handle:
        handle.write("<?php\n")
    assert rollback([probe]) == 1 and not os.path.exists(os.path.join(MODULE, probe)), \
        "a failed verification must delete what the harness wrote"
    print("self-check ok (window matches config/routing.php; parks at 01:00/03:59/06:00/09:59, "
          "resumes 04:00/10:00, weekends free; rollback removes unverified output)")


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
- Prove behaviour with the SITUATION KIT: `Situation::of($this)->resources(..)->ships(..)->debris(..)
  ->session()->expectWork(AiWorkKind::X)` plants a situation, runs the account's real session and says
  what it chose when the expectation fails. One short story per test; never plant rows by hand when the
  kit has a method for it. If the kit lacks what you need to plant, add ONE method to it (it is a test
  file) in the same answer. It plants colonies, stock on every planet, an inactive neighbour, a spy
  report on it, an alliance application, a direct message; it asserts queued objects, flown missions,
  ranked candidates, decided applications and replies.
- A kit failure is a diagnosis, read it before editing: "executors refused: [X: reason]" means the
  decision was right and an executor gate said no (fix the gate or the plant); a wanted action missing
  from "ranked" means no planner offered it (fix the planner); ranked but outscored means the choice
  (fix the comparison, not the test). Never weaken the story's expectation to make it pass.
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

REFUSED AUTOMATICALLY
When you edit: a path outside the module or starting `Modules/`; `tests/Unit/`; a PHP file under
`resources/behavior/`; anything under `app/Ai/`; a file named after a source id; a second class with an
existing class's name; old_string not found exactly once.
When you check, before any test runs: PHP that does not lint cleanly (warnings included); no test; a class
or data file no runtime code uses; the plan's numbers inlined in PHP; `Model::factory()` on a module
model; a public method or interface that other code uses removed; a scenario missing a required key.

HOW YOU WORK -- with tools, like an engineer at a terminal
- The task context below already holds the task, the files it names and the reference. Read more only
  when the change needs it: `read_file`, `search` and `list_files` read the module (`app/...`,
  `tests/...`, `resources/...`) or, with host=true, the OGameX host (`app/Services/...`, `app/Models/...`,
  `tests/...`, `vendor/...`). Read a method before you call it; search before you assume a method, column,
  enum case or helper exists.
- `edit_file` replaces one exact, unique piece of text; `write_file` creates a new file. Both change your
  WORKING COPY only: nothing reaches the shared tree until `check` passes, and the copy is kept across a
  failed check and across attempts. Edit existing files; never rewrite them.
- `check` puts the working copy in the tree, runs every automatic refusal, the tests and the proof, and
  restores the tree. A pass delivers the task and ends your work. A fail returns the exact output: read it,
  find the cause in the code (not in the test's expectation), fix it, check again.
- `give_up` with one line when the specification cannot hold as written. Never weaken it instead.
- Work order: read the failing proof and the code path it drives, name the cause, make the smallest edit
  that removes it, check. Never repeat a call whose answer you already have, and never check again before
  you changed something that addresses the last failure.
- Paths are module-relative: `app/...`, `tests/Feature/...`, `resources/...`. Data files are YAML.
"""

WRITER_TOOLS = [
    {"type": "function", "function": {
        "name": "read_file",
        "description": "Read a file with line numbers (your working copy when you changed it). Long files come in pages: pass offset to read on.",
        "parameters": {"type": "object", "properties": {
            "path": {"type": "string", "description": "Module-relative path, or host-relative with host=true"},
            "offset": {"type": "integer", "description": "First line to show, 1-based (default 1)"},
            "limit": {"type": "integer", "description": f"Lines to show (default and max {READ_LINES})"},
            "host": {"type": "boolean", "description": "Read the OGameX host instead of the module"},
        }, "required": ["path"]}}},
    {"type": "function", "function": {
        "name": "search",
        "description": "Search file contents with an extended regular expression (grep -E). Returns path:line: text.",
        "parameters": {"type": "object", "properties": {
            "pattern": {"type": "string"},
            "path": {"type": "string", "description": "Directory or file to search (default: the whole module, or the host's app/ with host=true)"},
            "glob": {"type": "string", "description": "Only files whose name matches, e.g. *.php"},
            "host": {"type": "boolean"},
        }, "required": ["pattern"]}}},
    {"type": "function", "function": {
        "name": "list_files",
        "description": "List the files under a directory, recursively, up to 200 entries.",
        "parameters": {"type": "object", "properties": {
            "path": {"type": "string"}, "host": {"type": "boolean"},
        }, "required": ["path"]}}},
    {"type": "function", "function": {
        "name": "edit_file",
        "description": "Replace old_string with new_string in a module file of your working copy. old_string must match exactly once (whitespace included) unless replace_all is true; include enough surrounding lines to make it unique.",
        "parameters": {"type": "object", "properties": {
            "path": {"type": "string"}, "old_string": {"type": "string"}, "new_string": {"type": "string"},
            "replace_all": {"type": "boolean"},
        }, "required": ["path", "old_string", "new_string"]}}},
    {"type": "function", "function": {
        "name": "write_file",
        "description": "Create a new module file in your working copy. An existing file is changed with edit_file, never rewritten.",
        "parameters": {"type": "object", "properties": {
            "path": {"type": "string"}, "content": {"type": "string"},
        }, "required": ["path", "content"]}}},
    {"type": "function", "function": {
        "name": "check",
        "description": f"Verify the working copy in the real tree: lint, automatic refusals, the tests that name what you touched, then the proof. A pass delivers the task. A fail restores the tree, keeps your copy and returns the output. Check as often as you need.",
        "parameters": {"type": "object", "properties": {}}}},
    {"type": "function", "function": {
        "name": "give_up",
        "description": "Stop: the specification cannot hold as written. Say why in one line.",
        "parameters": {"type": "object", "properties": {"reason": {"type": "string"}}, "required": ["reason"]}}},
]

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

    kit = os.path.join(MODULE, "tests/Support/Situation.php")
    if os.path.exists(kit):
        parts += ["SITUATION KIT tests/Support/Situation.php (plant state, run the account's real session, read what it "
                  "did: a verdict in about a second, with the reason when it fails. Prove behaviour with this):",
                  "```php", read(kit), "```", "",
                  "EXAMPLE SITUATION TESTS tests/Feature/Situations/SituationKitTest.php (copy this shape, including the "
                  "`require_once` line):", "```php", read(os.path.join(MODULE, "tests/Feature/Situations/SituationKitTest.php")), "```", ""]

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

    # What the tools already say, so the first attempt starts from the diagnosis instead of re-deriving it:
    # the board's verdict on this row's stories (the kit's own explanation of what the account did), and
    # for a row judged live, what the live subject account sees and what the cohort chose lately.
    board_path = os.path.join(MODULE, "plan/research/ogame/stories.json")
    board = json.loads(read(board_path)) if os.path.exists(board_path) else {}
    mine = [story for story in board.get("stories", []) if story["test"] in proof_tests(code)]
    if mine:
        parts += ["THE BEHAVIOUR BOARD ON YOUR STORIES (current code, before your change):"]
        parts += [f"- {'PASS' if story['pass'] else 'FAIL'} {story['test']}: {story['story']}"
                  + ("" if story["pass"] else f"\n  why: {story['why']}") for story in mine]
        parts += [""]
    live = live_evidence([f"FAIL {step}" for step in task["proof"].split() if step.split(":")[0] in ("situation", "aspect", "invariant")])
    if live:
        parts += [live.strip(), ""]

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
        # Shown whole up to a bound, so the common edit needs no read call; past it the writer pages on.
        cut = "" if len(body) <= EDIT_SHOW_LIMIT else " (TRUNCATED — read_file it with an offset for the rest)"
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


QUALITY_READ_HOURS = 6  # harness-live.sh reads the scorecard with --hours=6


def proven_within_read(connection, code):
    """Whether the row's last PROVEN note is younger than the window the cohort read judges."""
    notes = connection.execute("select coalesce(notes,'') from tasks where code=?", (code,)).fetchone()[0]
    stamps = re.findall(r"PROVEN (\d{4}-\d\d-\d\d \d\d:\d\d) UTC", notes)
    if not stamps:
        return False
    proven = datetime.datetime.strptime(stamps[-1], "%Y-%m-%d %H:%M").replace(tzinfo=datetime.timezone.utc)
    return datetime.datetime.now(datetime.timezone.utc) - proven < datetime.timedelta(hours=QUALITY_READ_HOURS)


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
        if name in known and proven_within_read(connection, known[name][0]):
            # The read judges hours that reach back before the proof, so the old code can fail it
            # the minute after the new code passed: reopening there loops done -> todo -> done.
            tracked.append(f"{name} ({known[name][0]}, proven inside the read's window)")
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
            "select code, status, coalesce(file_ref, ''), coalesce(proof, ''), coalesce(notes, '') like '% PROVEN %', "
            "assignee = 'harness:delivered' from tasks "
            "where kind = 'impl' and priority in ('P0', 'P1', 'P2')").fetchall()
        ready_codes = {code for (code,) in connection.execute("select code from ready_tasks")}
        connection.close()
    else:
        ready_codes = set()

    # Delivered is what the ledger says, not whether a marker file survived: five P0/P1 rows sat
    # `harness:delivered` with no marker, so neither the queue nor the proof stage ever saw them.
    marked = {code for code, *_ in rows if os.path.exists(os.path.join(IMPLEMENTED, f"{code}.md"))}
    marked |= {code for code, _, _, _, _, delivered in rows if delivered}
    attemptable = [code for code, _, file_ref, _, _, _ in rows
                   if code in ready_codes and code not in marked
                   and (file_ref or os.path.exists(os.path.join(PROPOSALS, f"{code}.md")))]
    proofs = {code: proof for code, _, _, proof, _, _ in rows}
    held = {code: moves_no_failing_aspect(proofs[code]) for code in attemptable}
    held = {code: why for code, why in held.items() if why}
    ready = [code for code in attemptable if code not in held]
    proven = [code for code, state, _, _, stamped, _ in rows if state == "done" and stamped]
    closed_blind = [code for code, state, _, _, stamped, _ in rows if state == "done" and not stamped]
    unproven = [code for code, state, *_ in rows if code in marked and state != "done"]
    no_proof = [code for code, state, _, proof, _, _ in rows if state in ("todo", "in_progress", "blocked") and not ledger.on_path(proof)]

    print(f"P0-P2 code rows: {len(proven)} proven, {len(closed_blind)} closed before proofs existed, "
          f"{len(unproven)} delivered but NOT proven, "
          f"{len(ready)} ready now")
    if unproven:
        print("delivered, not proven: " + ", ".join(sorted(unproven)))
    for code, why in sorted(held.items()):
        print(f"WAITING: {code} — {why}")
    if no_proof:
        print("OFF THE NORTH STAR (no aspect, situation or invariant in the proof; cannot be taken or closed): "
              + ", ".join(sorted(no_proof)))
    # Machine-readable for the harness: an empty queue may wait, a queue with attemptable work may not.
    print(f"model today: {usage_today()}")
    print(f"READY: {len(ready)}")
    print(f"UNPROVEN: {' '.join(sorted(unproven))}")

    return 0


def provider_down():
    """True while every key is cooling: nothing can be asked of the provider, so a writer skips its paid
    call and the pass goes on to the free stages (proofs, live checks, the board)."""
    return choose_key(0) is None


def keys_state():
    """One line per key for a log: its tag and ready, or how long it still rests."""
    return ", ".join(f"{key_tag(key)} " + (f"rests {key_cooling(key) // 60 + 1} min" if key_cooling(key) else "ready") for key in api_keys())


def check_keys():
    """Ask every key one 8-token question at once and say which answer: the quick way to learn that a key
    is wrong, empty or queued after a file edit. Paid, but a few tokens per key."""
    import concurrent.futures

    def ask(key):
        request = urllib.request.Request(API, data=json.dumps({"model": MODEL, "max_tokens": 8, "messages": [
            {"role": "user", "content": "Reply with the single word: ok"}]}).encode(),
            headers={"Authorization": f"Bearer {key}", "Content-Type": "application/json"})
        started = time.time()
        try:
            with urllib.request.urlopen(request, timeout=75) as response:
                data = read_within(response, started + 70)
            return key, f"ok in {time.time() - started:.1f}s" if data.get("choices") else f"no answer: {json.dumps(data)[:120]}"
        except urllib.error.HTTPError as error:
            return key, f"refused {error.code}" + (" (bad key or no balance)" if error.code in (401, 402, 403) else "")
        except (TimeoutError, urllib.error.URLError, ConnectionError) as error:
            return key, f"no answer in {time.time() - started:.0f}s ({type(error).__name__})"

    keys = api_keys()
    print(f"{len(keys)} key(s):")
    with concurrent.futures.ThreadPoolExecutor(len(keys)) as pool:
        for key, verdict in pool.map(ask, keys):
            cooling = key_cooling(key)
            print(f"  {key_tag(key)}  {verdict}" + (f"  [harness has it resting {cooling // 60 + 1} min]" if cooling else ""))
    return 0


def read_within(response, deadline):
    """The JSON body, read line by line so a queued request that only sends keep-alive blank lines is
    abandoned at the deadline (as a TimeoutError, which the caller retries) instead of after 15 minutes."""
    body = []
    for line in response:
        if time.time() > deadline:
            raise TimeoutError("no answer within the call deadline")
        body.append(line)
    return json.loads(b"".join(body).strip() or b"{}")


def heartbeat(finished, purpose):
    """A line a minute while a call is out, so a long call reads as waiting and not as nothing."""
    waited = 0
    while not finished.wait(60):
        waited += 1
        print(f"still waiting on the model for {purpose}: {waited} min")


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
    body = json.dumps(payload).encode()

    for attempt in range(1, MODEL_ATTEMPTS + 1):
        slot = take_model_slot()
        if slot is None:
            raise SystemExit("no model slot came free; giving up on this pass")

        key = choose_key(int(os.path.basename(slot).split("-")[1]))
        if key is None:
            os.remove(slot)
            raise SystemExit("every DeepSeek key is cooling; nothing was asked of the provider")
        request = urllib.request.Request(API, data=body, headers={"Authorization": f"Bearer {key}", "Content-Type": "application/json"})

        try:
            publish("waiting on the model", f"{purpose} ({os.path.basename(slot)}, key {key_tag(key)})")
            print(f"asking DeepSeek for {purpose} (key {key_tag(key)})")
            finished = threading.Event()
            threading.Thread(target=heartbeat, args=(finished, purpose), daemon=True).start()
            try:
                started = time.time()
                with urllib.request.urlopen(request, timeout=MODEL_TIMEOUT_SECONDS) as response:
                    data = read_within(response, started + MODEL_DEADLINE_SECONDS)
                finished.set()
                record_usage(purpose, data, time.time() - started, key)
                used = data.get("usage", {})
                print(f"answer for {purpose} in {time.time() - started:.0f}s: {used.get('completion_tokens')} tokens, "
                      f"finish {(data.get('choices') or [{}])[0].get('finish_reason')}")
                if data.get("choices") and os.path.exists(key_file(key)):
                    os.remove(key_file(key))
                return data
            except urllib.error.HTTPError as error:
                if error.code in (401, 402, 403):
                    # A wrong key or an empty balance does not heal in minutes; say which one, loudly.
                    cool_key(key, KEY_REFUSED_SECONDS, f"http {error.code}")
                    print(f"  key {key_tag(key)} refused with {error.code} (bad key or no balance); out for {KEY_REFUSED_SECONDS // 3600} h")
                    continue
                if error.code == 429:
                    wait = retry_after(error) or KEY_RATE_LIMIT_SECONDS
                    cool_key(key, wait, "http 429")
                    print(f"  key {key_tag(key)} rate-limited on {purpose}; trying the next key (this one rests {wait}s)")
                    continue
                if error.code not in MODEL_RETRY_CODES or attempt == MODEL_ATTEMPTS:
                    raise
                wait = retry_after(error) or min(60, 2 ** attempt)
                print(f"  {error.code} from the model on {purpose}; waiting {wait}s "
                      f"(attempt {attempt} of {MODEL_ATTEMPTS})")
                time.sleep(wait + random.uniform(0, 2))
            except TimeoutError as error:
                # Past the deadline is the provider queueing this key's requests, not a dropped connection:
                # the key rests and the next call picks another one.
                cool_key(key, KEY_STALL_SECONDS, "stalled")
                raise SystemExit(f"model stalled on {purpose} with key {key_tag(key)}: {error}; that key rests {KEY_STALL_SECONDS // 60} min")
            except (urllib.error.URLError, ConnectionError) as error:
                # A dropped connection or the server's ten-minute close: retried like a 503, never a
                # crash that loses the worker's whole pass.
                if attempt == MODEL_ATTEMPTS:
                    raise SystemExit(f"model unreachable on {purpose}: {error}")
                wait = min(60, 2 ** attempt)
                print(f"  {type(error).__name__} from the model on {purpose}; waiting {wait}s "
                      f"(attempt {attempt} of {MODEL_ATTEMPTS})")
                time.sleep(wait + random.uniform(0, 2))
        finally:
            finished.set()
            if os.path.exists(slot):
                os.remove(slot)

    raise SystemExit("model call fell through every attempt")


def record_usage(purpose, data, seconds, key=None):
    """One line per paid call: what it cost in tokens and whether it thought. Peak and off-peak bill
    differently and thinking tokens bill as output, so spend is only visible if it is written down."""
    usage = data.get("usage", {})
    message = (data.get("choices") or [{}])[0].get("message", {})
    line = {
        "at": datetime.datetime.now(datetime.timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
        "worker": os.environ.get("HARNESS_WORKER", ""),
        "key": key_tag(key) if key else None,
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
WRITER_REASONING_EFFORT = "high"


def work_path(code):
    return os.path.join(ATTEMPTS, f"{code}.work.json")


def disk_text(clean):
    full = os.path.join(MODULE, clean)

    return read(full) if os.path.exists(full) else None


def digest(text):
    return hashlib.sha1((text if text is not None else "\0absent").encode()).hexdigest()


def save_work(code, working, failure=""):
    """Keep the writer's copy beside the attempt, with the disk each file was based on, so the next
    attempt resumes from it instead of rewriting the slice from a blank page."""
    os.makedirs(ATTEMPTS, exist_ok=True)
    with open(work_path(code), "w", encoding="utf-8") as handle:
        json.dump({"files": working, "bases": {clean: digest(disk_text(clean)) for clean in working},
                   "failure": failure}, handle)


def load_work(code):
    """(working copy, its last failure, the files dropped because the tree moved under them)."""
    if not os.path.exists(work_path(code)):
        return {}, "", []
    try:
        saved = json.loads(read(work_path(code)))
    except ValueError:
        return {}, "", []
    # A file somebody changed since the copy was taken is dropped: replaying the copy would undo their work.
    kept = {clean: content for clean, content in saved["files"].items()
            if saved["bases"].get(clean) == digest(disk_text(clean))}

    return kept, saved.get("failure", ""), sorted(set(saved["files"]) - set(kept))


def drop_work(code):
    if os.path.exists(work_path(code)):
        os.remove(work_path(code))


def working_diff(working, limit=14000):
    """The writer's copy against the tree, as a unified diff: what a resumed writer needs to see."""
    lines = []
    for clean, content in sorted(working.items()):
        before = disk_text(clean)
        lines += difflib.unified_diff((before or "").splitlines(), content.splitlines(),
                                      f"a/{clean}" if before is not None else "/dev/null", f"b/{clean}", lineterm="", n=2)
    text = "\n".join(lines)

    return text if len(text) <= limit else text[:limit] + "\n… (diff truncated; read_file shows your working copy)"


def resume_note(working, failure, dropped=()):
    """What a writer resuming the slice is told: its own diff and what the last check said."""
    parts = []
    if working:
        parts += ["\n\nYOUR WORK SO FAR (your working copy; it reaches the tree only when check passes):",
                  "```diff", working_diff(working), "```"]
    if dropped:
        parts += ["These files of your earlier copy were dropped because the tree changed under them: "
                  + ", ".join(dropped) + ". Read them again before editing."]
    if failure:
        parts += ["\n\nTHE LAST CHECK OF THIS TASK SAID:", failure[:3000],
                  "Fix its cause and call check. Change nothing the failure does not need."]

    return "\n".join(parts)


def tool_target(path, host=False):
    """(absolute path, module-relative path or None, refusal or None) for a path a tool names."""
    base = ROOT if host else MODULE
    full = os.path.realpath(os.path.join(base, str(path or ".").strip().strip("`").lstrip("/")))
    if full != os.path.realpath(ROOT) and not full.startswith(os.path.realpath(ROOT) + os.sep):
        return None, None, "outside the repository"
    if os.path.basename(full).startswith(".env") or f"{os.sep}.git" in full[len(os.path.realpath(ROOT)):]:
        return None, None, "credentials and git internals are not readable"
    inside = full.startswith(os.path.realpath(MODULE) + os.sep)
    clean = os.path.relpath(full, os.path.realpath(MODULE)) if inside else None
    if clean and (clean + "/").startswith("plan/research/ogame/"):
        # The harness's own state: its logs hold the writer's earlier calls, so reading them is a circle (ATK-001).
        return None, None, "harness state (logs, attempts, proofs) is not source; read the code"

    return full, clean, None


def tool_read(args, working):
    full, clean, why = tool_target(args.get("path"), args.get("host"))
    if why:
        return f"refused: {why}"
    text = working.get(clean) if clean else None
    if text is None and not os.path.isfile(full):
        return f"{args.get('path')} does not exist" + (" (use list_files on its directory)" if not os.path.isdir(full) else " — it is a directory; use list_files")
    text = text if text is not None else read(full)
    lines = text.splitlines()
    start = max(1, int(args.get("offset") or 1))
    count = max(1, min(READ_LINES, int(args.get("limit") or READ_LINES)))
    shown = lines[start - 1:start - 1 + count]
    body = "\n".join(f"{number:>5}\t{line}" for number, line in enumerate(shown, start))
    tail = (f"\n(lines {start}-{start + len(shown) - 1} of {len(lines)}; read on with offset {start + len(shown)})"
            if start - 1 + len(shown) < len(lines) else "")

    return (body or "(empty)") + tail


def tool_search(args, working):
    host = bool(args.get("host"))
    full, _, why = tool_target(args.get("path") or ("app" if host else "."), host)
    if why:
        return f"refused: {why}"
    pattern = str(args.get("pattern") or "")
    try:
        compiled = re.compile(pattern)
    except re.error as error:
        return f"refused: the pattern is not a valid regular expression ({error})"
    command = ["grep", "-rnIE", "--exclude-dir=node_modules", "--exclude-dir=.git", "--exclude-dir=storage", "--exclude-dir=ogame",
               "--exclude=.env*", "--exclude-dir=vendor", "-e", pattern, full]
    if f"{os.sep}vendor" in full:
        command.remove("--exclude-dir=vendor")
    if args.get("glob"):
        command.insert(2, f"--include={args['glob']}")
    found = subprocess.run(command, capture_output=True, text=True, timeout=60).stdout.splitlines()
    module = os.path.realpath(MODULE) + os.sep
    # The writer's copy wins over the disk for the files it changed.
    hits = [hit for hit in found if not (hit.startswith(module) and hit[len(module):].split(":", 1)[0] in working)]
    for clean, content in sorted(working.items()):
        path = module + clean
        if (path == full or path.startswith(full.rstrip(os.sep) + os.sep)) and \
                (not args.get("glob") or fnmatch.fnmatch(os.path.basename(clean), args["glob"])):
            hits += [f"{path}:{number}:{line}" for number, line in enumerate(content.splitlines(), 1) if compiled.search(line)]
    base = (os.path.realpath(ROOT) if host else os.path.realpath(MODULE)) + os.sep
    hits = [(hit[len(base):] if hit.startswith(base) else hit)[:240] for hit in hits]
    if not hits:
        return "no matches"

    return "\n".join(hits[:80]) + (f"\n… {len(hits) - 80} more; narrow the path or pattern" if len(hits) > 80 else "")


def tool_list(args, working):
    full, clean, why = tool_target(args.get("path"), args.get("host"))
    if why:
        return f"refused: {why}"
    if not os.path.isdir(full) and not any(path.startswith(f"{clean}/") for path in working if clean):
        return f"{args.get('path')} is not a directory"
    names = []
    for folder, directories, files in os.walk(full):
        directories[:] = sorted(name for name in directories if name not in ("vendor", "node_modules", ".git", "storage"))
        names += [os.path.relpath(os.path.join(folder, name), full) for name in sorted(files) if not name.startswith(".env")]
    names += [os.path.relpath(path, clean) + "  (new, in your working copy)" for path in working
              if clean and path.startswith(f"{clean}/") and disk_text(path) is None]

    return "\n".join(names[:200]) + (f"\n… {len(names) - 200} more" if len(names) > 200 else "") if names else "(empty)"


def nearest_text(text, wanted):
    """The lines of `text` closest to an old_string that is not there, so the writer copies the real ones
    instead of guessing again."""
    lines, size = text.splitlines(), max(1, len(wanted.splitlines()))
    best, at = 0.0, 0
    for start in range(0, max(1, len(lines) - size + 1)):
        ratio = difflib.SequenceMatcher(None, "\n".join(lines[start:start + size]), wanted, autojunk=False).ratio()
        if ratio > best:
            best, at = ratio, start
    shown = lines[max(0, at - 2):at + size + 2]

    return "\n".join(f"{number:>5}\t{line}" for number, line in enumerate(shown, max(0, at - 2) + 1))


def writable(clean, held):
    """Why the writer may not change this module path, or None. Takes the file's claim on first touch,
    so a file another worker holds is refused at the edit, not after a whole check."""
    why = path_refusal(clean)
    if why:
        return why
    key = os.path.join(MODULE, clean)
    if claim_path(key) in HELD_CLAIMS or key in held:
        return None
    if not take_claim(key):
        return "another worker is writing this file; leave it to them"
    HELD_CLAIMS.append(claim_path(key))
    held.add(key)

    return None


def keep(working, clean, content):
    """Store a file in the working copy; a file edited back to the tree's text is no change at all."""
    if content == disk_text(clean):
        working.pop(clean, None)
        return
    working[clean] = content


def tool_edit(args, working, held):
    _, clean, why = tool_target(args.get("path"))
    if why or clean is None:
        return f"refused: {why or 'only module files can be edited'}"
    why = writable(clean, held)
    if why:
        return f"refused: {clean}: {why}"
    current = working.get(clean, disk_text(clean))
    if current is None:
        return f"refused: {clean} does not exist — create it with write_file"
    old, new = str(args.get("old_string", "")), str(args.get("new_string", ""))
    if not old or old == new:
        return "refused: old_string must be non-empty and differ from new_string"
    count = current.count(old)
    if count == 0:
        return f"refused: old_string is not in {clean}. The closest lines are:\n{nearest_text(current, old)}\nCopy the text exactly (without the line numbers)."
    if count > 1 and not args.get("replace_all"):
        return f"refused: old_string matches {count} places in {clean}; include more surrounding lines, or set replace_all"
    keep(working, clean, current.replace(old, new) if args.get("replace_all") else current.replace(old, new, 1))
    diff = list(difflib.unified_diff(current.splitlines(), working.get(clean, current).splitlines(), lineterm="", n=1))[2:]

    return f"edited {clean}:\n" + "\n".join(diff[:40]) + ("\n…" if len(diff) > 40 else "")


def tool_write(args, working, held):
    _, clean, why = tool_target(args.get("path"))
    if why or clean is None:
        return f"refused: {why or 'only module files can be written'}"
    if disk_text(clean) is not None:
        return f"refused: {clean} exists — change it with edit_file"
    why = writable(clean, held) or duplicate_class(str(args.get("content", "")), clean)
    if why:
        return f"refused: {clean}: {why}"
    keep(working, clean, str(args.get("content", "")).rstrip() + "\n")

    return f"wrote {clean} ({len(working[clean].splitlines())} lines) to your working copy"


def check_in_lane(code, working, answer_file=None):
    """verify_and_judge inside the one verification lane, or None when the lane or a file stayed held.

    Written and verified inside the lane: files written outside it sat in the tree while another worker's
    Pest run collected the whole suite, and the retry chased an error it never made. The lane is held to
    the verdict, the proof check included (ECON-001's baseline once read QUAL-006's rejected planner)."""
    lane = await_claim(VERIFY_LANE)
    if lane is None:
        print("  left for the next pass: the verification lane stayed held")
        return None
    try:
        return verify_and_judge(code, working, "", answer_file)
    finally:
        release_claims([lane])


def refresh_claims():
    """Touch every claim this attempt holds: an attempt can outlive the stale window of its own claims,
    and a claim that reads as stale is taken by the next worker in the middle of this one's work."""
    for path in HELD_CLAIMS:
        if os.path.exists(path):
            os.utime(path)


def writer_conversation(context, working, failure, dropped=()):
    return [{"role": "system", "content": IMPLEMENT_PROMPT},
            {"role": "user", "content": context + resume_note(working, failure, dropped)}]


def save_transcript(code, conversation):
    """The attempt as the writer lived it, without the shared prompt: what to read when a row goes stuck."""
    os.makedirs(ATTEMPTS, exist_ok=True)
    with open(os.path.join(ATTEMPTS, f"{code}.transcript.json"), "w", encoding="utf-8") as handle:
        json.dump(conversation[2:], handle, indent=1)


def call_line(name, args):
    shown = {key: (value if len(str(value)) <= 60 else str(value)[:57] + "...") for key, value in args.items()
             if key not in ("content", "new_string")}

    return f"{name}({', '.join(f'{key}={value!r}' for key, value in shown.items())})"


def write_slice(code, context, working, failure, dropped):
    """Run the writer until the slice is delivered. It has no turn, time or check budget: a failed check
    goes straight back to it with the failure, and the only exits are delivery, `give_up` (a specification
    that cannot hold, which blocks the row for the owner), and a pause the provider or a peak window forces.

    Returns ("delivered", (written, proof after)), ("later", None) when nothing is counted (a held lane, a
    provider error, an unrunnable proof, a peak window) or ("gave_up", reason). The working copy is saved
    after every change.
    """
    conversation = writer_conversation(context, working, failure, dropped)
    checks, version, checked_version = 0, 0, -1
    seen, reads, silent, step = {}, 0, 0, 0
    last_failure = failure

    while True:
        step += 1
        if in_peak(datetime.datetime.now(datetime.timezone.utc)):
            # An attempt runs for as long as it takes; one started before a window must not bill inside it.
            print("  a peak window opened; the attempt parks with its working copy kept")
            save_work(code, working, last_failure)
            return "later", None
        refresh_claims()
        publish("implementing", f"{code} step {step}")
        payload = {"model": MODEL, "max_tokens": WRITER_MAX_TOKENS, "thinking": WRITER_THINKING,
                   "reasoning_effort": WRITER_REASONING_EFFORT, "messages": conversation,
                   "tools": WRITER_TOOLS, "tool_choice": "auto"}
        try:
            data = model_call(payload, purpose=f"implementing {code} step {step}")
        except urllib.error.HTTPError as error:
            print(f"  the provider refused the request ({error.code}): {error.read().decode(errors='replace')[:400]}")
            save_work(code, working, last_failure)
            return "later", None
        if not data.get("choices"):
            print(f"  the provider returned no answer, not counted as an attempt: {json.dumps(data)[:400]}")
            save_work(code, working, last_failure)
            return "later", None
        choice = data["choices"][0]
        message = choice.get("message") or {}
        calls = message.get("tool_calls") or []
        if choice.get("finish_reason") == "length":
            conversation.append({"role": "user", "content": "Your last turn hit the output cap and was lost: take smaller "
                                 "steps, one edit per call."})
            continue

        # In thinking mode the provider requires the reasoning of a tool-calling turn to be sent back with it.
        conversation.append({"role": "assistant", "content": message.get("content") or "",
                             **({"reasoning_content": message["reasoning_content"]} if message.get("reasoning_content") else {}),
                             **({"tool_calls": calls} if calls else {})})

        if not calls:
            silent += 1
            if working and checked_version != version:
                # Changes nobody checked: the harness runs the check the writer did not.
                calls = [{"id": "", "function": {"name": "check", "arguments": "{}"}}]
            if not calls:
                conversation.append({"role": "user", "content": "Use the tools: edit_file/write_file to change the "
                                     "code, check when it is done, give_up if the specification cannot hold."})
                continue
        silent = 0

        for call in calls:
            name = call["function"]["name"]
            try:
                args = json.loads(call["function"].get("arguments") or "{}")
            except ValueError as error:
                args, result = {}, f"refused: the arguments were not valid JSON ({error}); send one smaller call"
            else:
                result = None
            print(f"  {step:>2}. {call_line(name, args)}")

            key = (name, json.dumps(args, sort_keys=True), version)
            if result is None and key in seen and name != "give_up":
                result = ("You already made this exact call and nothing has changed since, so the answer is the same:\n"
                          + seen[key][:1500] + "\nDo something different.")

            if result is None and name == "give_up":
                return "gave_up", f"the writer gave up: {args.get('reason', '')}\n{last_failure}"

            if result is None and name == "check":
                if not working:
                    result = "Your working copy is empty: there is nothing to check. Edit the code first."
                else:
                    checks += 1
                    checked_version = version
                    verdict = check_in_lane(code, working)
                    if verdict is None or verdict == 0:
                        save_work(code, working, last_failure)
                        return "later", None
                    if not isinstance(verdict, Rejected):
                        return "delivered", verdict
                    last_failure = verdict.reason
                    save_work(code, working, last_failure)
                    result = (f"CHECK {checks} FAILED; the tree is restored and your working copy is kept.\n"
                              + verdict.reason[:6000])
                    if checks > 1 and failure_signature(verdict.reason) == failure_signature(seen.get("last_check", "")):
                        result += ("\n\nThis is the SAME failure as your previous check: your edits since did not reach its "
                                   "cause. Read the failing line and the code it runs before you edit again.")
                    seen["last_check"] = verdict.reason
                    seen[key] = result

            if result is None and reads >= AGENT_READ_STREAK and name in ("read_file", "search", "list_files"):
                # A nudge is ignored (ATK-001 read for 28 turns without one edit): reading stops being offered.
                result = (f"refused: {reads} read-only calls without a change. Reading is closed until you edit_file or "
                          "write_file; if you cannot name the edit, give_up with what is missing.")
                reads += 1

            if result is None:
                tools = {"read_file": lambda: tool_read(args, working), "search": lambda: tool_search(args, working),
                         "list_files": lambda: tool_list(args, working), "edit_file": lambda: tool_edit(args, working, HELD),
                         "write_file": lambda: tool_write(args, working, HELD)}
                before = json.dumps(working, sort_keys=True)
                try:
                    result = tools[name]() if name in tools else f"refused: there is no tool named {name}"
                except (OSError, ValueError, subprocess.TimeoutExpired) as error:
                    result = f"the tool failed: {error}"
                if json.dumps(working, sort_keys=True) != before:
                    version, reads = version + 1, 0
                    save_work(code, working, last_failure)
                else:
                    reads += 1
                seen[key] = result
                if not result.startswith(("edited", "wrote")):
                    print(f"      {result.splitlines()[0][:110] if result.strip() else '(empty)'}")

            if reads == AGENT_READ_STREAK:
                result += (f"\n\n[{reads} calls without a change: reading closes after this one. Make the edit, or give_up "
                           "with the reason.]")
            if len(result) > TOOL_RESULT_CHARS:
                result = result[:TOOL_RESULT_CHARS] + "\n… (cut; narrow the call)"
            # A check the harness ran for a writer that stopped calling tools answers as the user.
            conversation.append({"role": "tool", "tool_call_id": call["id"], "content": result} if call["id"]
                                else {"role": "user", "content": result})

        save_transcript(code, conversation)
        if (data.get("usage") or {}).get("prompt_tokens", 0) > AGENT_CONTEXT_TOKENS:
            print("  the conversation grew past its bound; the writer restarts from its working copy and the last check")
            conversation = writer_conversation(context, working, last_failure)


# The files this process claimed while the writer edited, beyond the plan's own.
HELD = set()


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


def parse_answer(answer, base=None):
    """Each path's new contents and every refused block, before anything touches the disk.

    `base` is the writer's working copy from an earlier turn: an EDIT applies to it, not to the disk."""
    contents, refused = {}, []
    base = base or {}

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
        current = contents.get(clean, base.get(clean, read(full) if os.path.exists(full) else None))
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
    # A story already red on the behaviour board is another row's open defect (BothQueues is QUAL-006's):
    # it would fail every attempt that touches the session, whatever the attempt did. The row's own
    # proof stories stay in, red or not; they are its target.
    red = known_red_stories()
    names = tests + [name for name in affected_tests(written) if name not in tests and name not in red]
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

    The writer edits a working copy with tools and runs the checks itself; only a passing check leaves
    files in the tree. A task is never marked done here — that still needs its proof (`task.py done`).
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
    atexit.register(lambda: release_claims([claim_path(key) for key in HELD]))

    if answer_file:
        # A saved block answer, verified for nothing and counted as nothing: a replay is for reading.
        changes, refused = parse_answer(read(answer_file))
        for reason in refused:
            print(f"  ! {reason}")
        verdict = check_in_lane(code, changes, answer_file) if changes else Rejected("the saved answer has nothing to write")
        if verdict is None or verdict == 0:
            return 0
        if isinstance(verdict, Rejected):
            print(verdict.reason[:3000])
            return 1
        written, after = verdict
    if not answer_file:
        if provider_down():
            print(f"  every DeepSeek key is cooling ({keys_state()}); no paid call this pass")
            return 0
        # The writer resumes its own copy when an earlier attempt left one, and starts from the last failure
        # either way: a retry that starts cold rewrites the slice and repeats the mistake.
        working, failure, dropped = load_work(code)
        if working:
            print(f"  resuming the saved working copy: {', '.join(sorted(working))}")
        outcome, result = write_slice(code, context, working, failure or previous_failure(code), dropped)
        if outcome == "later":
            return 0
        if outcome == "gave_up":
            print(f"  {result.splitlines()[0][:200]}")
            block_row(code, result.splitlines()[0][:300])
            return 1
        written, after = result
    drop_work(code)

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


class Rejected:
    """A slice the checks refused. The disk is already restored; the writer's copy lives on (CODE.work.json)."""

    def __init__(self, reason):
        self.reason = reason


def verify_and_judge(code, changes, refusal_note, answer_file):
    """Write, verify and judge one slice inside the held lane: (written, proof after) when it is kept,
    None when nothing was written, 0 when the proof could not be run, or Rejected(reason) when a check
    refused it and the tree was restored. Counting the attempt is the caller's, once its turns are used."""
    written, tests, backups = write_changes(code, changes)
    if written is None:
        return None
    for path in written:
        print(f"  + {path}")

    rejection = verify_slice(code, written, tests, backups)
    if rejection:
        print(f"  unverified, tree restored ({rollback(written, backups)} file(s)); the writer keeps its copy")
        return Rejected(rejection + refusal_note)

    # The slice's own tests pass; now ask whether it moved the row's proof.
    baseline_path = os.path.join(ATTEMPTS, f"{code}.baseline.json")
    after = None
    if os.path.exists(baseline_path) and not answer_file:
        after = proof_report(code)
        if after is None:
            print(f"  the proof of {code} could not be run after the attempt; restored {rollback(written, backups)} file(s), nothing counted")
            return 0
        why = proof_change(json.loads(read(baseline_path)), after)
        if why:
            print(f"  {why.splitlines()[0]}; tree restored ({rollback(written, backups)} file(s))")
            return Rejected(why)

    return written, after


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("command", nargs="?",
                        choices=["implement", "wait-until-offpeak", "publish", "peak-gate",
                                 "quality", "status", "model-check", "reopen", "keys"])
    parser.add_argument("source", nargs="?")
    parser.add_argument("--self-check", action="store_true")
    parser.add_argument("--answer", help="implement: verify this saved answer instead of paying for one")
    args = parser.parse_args(argv)

    if args.self_check:
        self_check()
        return 0
    if args.command == "quality":
        # Reads the cohort read the harness captured, so a failed invariant becomes a task row.
        return quality(args.source or "/tmp/harness-quality.txt")
    if args.command == "status":
        return status()
    if args.command == "model-check":
        return model_check()
    if args.command == "keys":
        return check_keys()
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
    parser.error("give a command, or --self-check")


if __name__ == "__main__":
    sys.exit(main())
