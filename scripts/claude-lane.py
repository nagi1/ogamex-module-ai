#!/usr/bin/env python3
"""The strong lane: Claude Code (Sonnet 5.5) works the rows the babysitter hands it, one at a time.

The DeepSeek writers take ordinary rows. A row that is stuck, important or reopened is assigned
`claude-lane` by the babysitter (scripts/babysit.py delegate_to_claude) and the harness leaves it
alone; this script is the only thing that works those rows. One run at a time (flock), one row per run,
a wall-clock and a dollar bound on every run, and the run must end through `task.py done` or leave a note.
"""
import fcntl
import os
import sqlite3
import shutil
import subprocess
import sys
import time
from datetime import datetime, timezone

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DB = os.path.join(ROOT, "plan/tasks/tasks.db")
LOG_DIR = os.path.join(ROOT, "plan/research/ogame/claude-lane")
LOCK = os.path.join(LOG_DIR, "lane.lock")
ASSIGNEE = "claude-lane"
MODEL = "claude-sonnet-5-5"
RUN_SECONDS = 45 * 60
RUN_BUDGET_USD = "12"
MAX_TURNS = "150"

PROMPT = """You are the strong lane of the OGameX AI module harness. Work exactly one task row: {code}.

Read first, in this order: Modules/AI/AGENTS.md, then `python3 plan/tasks/task.py show {code}` (from Modules/AI),
then .github/skills/ai-task-execute/SKILL.md and follow that workflow. The row is already claimed for you
(assignee {assignee}); the harness writers will not touch its files.

Rules that bind this run:
- Say which aspect, situation or invariant the row moves and what an observer would see the account do.
- Fix the cause in code or in a YAML under resources/behavior/. No caps, quotas or forced outcomes, no
  hardcoded object names (Gate 1), the smallest mechanism (Gate 2), what an experienced player does (Gate 3).
- Rust battle engine only. No else/elseif, app()/makeWith not new, Pest Feature tests, no Mockery.
- Tests only through `OGAMEX_RUNNER=local-docker-dev bash scripts/ogamex test-one NAME` (never the shared lane 1 DB).
- Commit only the files you changed, by name (git add <files>), with the trailer
  `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`. Never -A, stash, reset, clean or force-push.
- Finish with `python3 plan/tasks/task.py done {code}`. If its live steps need cohort time, leave the row
  `delivered` and add one line to its notes saying what to read next. If you cannot finish, `unclaim` it
  with a note naming the exact blocker. Do not leave the row silently claimed.
- Reply with three lines: what changed, the proof verdict, what is left.
"""


def claude_binary():
    """The standalone install (npm i -g @anthropic-ai/claude-code), not the copy bundled with the editor."""
    return os.environ.get("CLAUDE_BIN") or shutil.which("claude") or "claude"


MAX_RUNS_PER_ROW = 2


def next_row():
    """The next assigned row; one that two runs did not settle goes back to the owner instead of a third."""
    con = sqlite3.connect(DB)
    rows = con.execute(
        "select code, coalesce(notes,'') from tasks where assignee=? and status in ('todo','in_progress') "
        "order by priority, updated_at", (ASSIGNEE,)).fetchall()
    for code, notes in rows:
        if notes.count("CLAUDE-LANE") < MAX_RUNS_PER_ROW:
            return code
        con.execute("update tasks set assignee=null, status='blocked', notes=notes||? where code=?",
                    (f" | CLAUDE-LANE {datetime.now(timezone.utc):%Y-%m-%d %H:%M} UTC: two runs did not settle it; blocked for the owner (read the run logs).", code))
        con.commit()
    return None


def main():
    os.makedirs(LOG_DIR, exist_ok=True)
    lock = open(LOCK, "w")
    try:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
    except OSError:
        print("claude lane already running")
        return 0

    code = next_row()
    if code is None:
        print("claude lane: nothing assigned")
        return 0

    stamp = datetime.now(timezone.utc).strftime("%Y%m%d-%H%M")
    log = os.path.join(LOG_DIR, f"{code}-{stamp}.log")
    command = [claude_binary(), "-p", PROMPT.format(code=code, assignee=ASSIGNEE),
               "--model", MODEL, "--dangerously-skip-permissions",
               # One worker at a time: the run may not start sub-agents of its own either.
               "--disallowedTools", "Agent",
               "--max-turns", MAX_TURNS, "--max-budget-usd", RUN_BUDGET_USD,
               "--output-format", "text"]
    started = time.time()
    with open(log, "w") as out:
        try:
            result = subprocess.run(command, cwd=ROOT, stdout=out, stderr=subprocess.STDOUT, timeout=RUN_SECONDS)
            verdict = f"exit {result.returncode}"
        except subprocess.TimeoutExpired:
            verdict = f"timed out after {RUN_SECONDS // 60} min"

    # A run that ended with the row still claimed and unchanged must not be picked again at once.
    con = sqlite3.connect(DB)
    con.execute("update tasks set notes=coalesce(notes,'')||? where code=?",
                (f" | CLAUDE-LANE {datetime.now(timezone.utc):%Y-%m-%d %H:%M} UTC: run {verdict} in {int(time.time() - started) // 60} min, log {os.path.relpath(log, ROOT)}", code))
    con.commit()
    print(f"claude lane: {code} {verdict}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
