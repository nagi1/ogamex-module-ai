#!/usr/bin/env python3
"""Watch the worker harness the way its owner does: is it advancing north-star work, or filling git with logs?

One pass, then exit; scripts/babysitter.sh runs it on a clock. It measures from the ledger, git and the
scorecard, prints one verdict, and applies only fixes that are safe to repeat:

  - harness process gone            -> restart it (same env the owner launched it with)
  - in_progress row, no touch 45min -> task.py unstick (the row goes back to the writers)
  - nothing delivered or committed  -> say which rows are looping and why, and ask for no new work

It never edits code, never closes a row and never touches the price gate.
"""
import json, os, re, sqlite3, subprocess, sys, time
from datetime import datetime, timedelta, timezone

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DB = os.path.join(ROOT, "plan/tasks/tasks.db")
STATE = "/tmp/babysitter-state.json"
WINDOW_MIN = int(os.environ.get("BABYSIT_WINDOW", "60"))
STUCK_MIN = int(os.environ.get("BABYSIT_STUCK", "45"))
CODE = ("app/", "tests/", "resources/behavior/", "config/", "database/")


def sh(*cmd):
    return subprocess.run(cmd, cwd=ROOT, capture_output=True, text=True).stdout


def ledger():
    con = sqlite3.connect(DB)
    since = (datetime.now(timezone.utc) - timedelta(minutes=WINDOW_MIN)).strftime("%Y-%m-%d %H:%M:%S")
    done = [r[0] for r in con.execute("select code from tasks where status='done' and updated_at >= ?", (since,))]
    cutoff = (datetime.now(timezone.utc) - timedelta(minutes=STUCK_MIN)).strftime("%Y-%m-%d %H:%M:%S")
    stuck = con.execute("select code, assignee from tasks where status='in_progress' and updated_at < ?", (cutoff,)).fetchall()
    loops = []
    for code, notes in con.execute("select code, notes from tasks where kind='impl' and status in ('todo','in_progress') and notes like '%REOPENED%'"):
        n = len(re.findall(r"REOPENED", notes or ""))
        if n >= 3:
            loops.append((code, n, (re.findall(r"REOPENED[^|]*", notes) or [""])[-1][:140]))
    return done, stuck, sorted(loops, key=lambda x: -x[1])


def commits():
    log = sh("git", "log", f"--since={WINDOW_MIN} minutes ago", "--numstat", "--relative", "--format=%h")
    code = other = 0
    for line in log.splitlines():
        m = re.match(r"(\d+|-)\s+(\d+|-)\s+(\S+)", line)
        if m and m.group(1) != "-":
            n = int(m.group(1)) + int(m.group(2))
            code, other = (code + n, other) if m.group(3).startswith(CODE) else (code, other + n)
    return code, other


def tree():
    status = sh("git", "status", "--short").splitlines()
    junk = sum(1 for s in status if " plan/research/" in s or " plan/tasks/" in s)
    return len(status), junk


def scorecard():
    out = subprocess.run(["bash", "scripts/ogamex", "scorecard"], cwd=ROOT, capture_output=True, text=True,
                         env={**os.environ, "OGAMEX_RUNNER": "local-docker-dev"}, timeout=150).stdout
    m = re.search(r"PLAY: (\d+) of (\d+) aspects pass", out)
    return (int(m.group(1)), int(m.group(2))) if m else (None, None), re.findall(r"FAIL (\w+)", out.split("PLAY:")[0])


def harness_alive():
    return subprocess.run(["pgrep", "-f", "^bash scripts/harness-live.sh"], capture_output=True).returncode == 0


def main():
    actions = []
    if not harness_alive():
        env = {**os.environ, "IMPL_WORKERS": "6", "MODEL_CONCURRENCY": "8", "OGAMEX_RUNNER": "local-docker-dev"}
        subprocess.Popen(["setsid", "nohup", "bash", "scripts/harness-live.sh"], cwd=ROOT, env=env,
                         stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        actions.append("restarted the harness (it was not running)")

    done, stuck, loops = ledger()
    for code, assignee in stuck:
        sh("python3", "plan/tasks/task.py", "unstick", code)
        actions.append(f"unstuck {code} (held by {assignee or 'nobody'} with no change for {STUCK_MIN} min)")

    code_lines, other_lines = commits()
    changed, junk = tree()
    (passing, total), failing = scorecard()
    prev = json.load(open(STATE)) if os.path.exists(STATE) else {}
    moved = passing is not None and prev.get("passing") is not None and passing > prev["passing"]
    json.dump({"passing": passing, "at": time.time()}, open(STATE, "w"))

    advancing = bool(done) or code_lines > 0 or moved
    verdict = "ADVANCING" if advancing else "STALLED"
    print(f"BABYSIT {datetime.now(timezone.utc):%H:%M}Z {verdict} (last {WINDOW_MIN} min)")
    print(f"  delivered rows: {len(done)} {' '.join(done)}")
    print(f"  commits: {code_lines} code/test/behaviour lines vs {other_lines} other lines")
    print(f"  working tree: {changed} changed, {junk} of them plan/research or ledger churn")
    print(f"  scorecard: {passing}/{total} aspects pass; failing: {', '.join(failing) or 'none'}")
    for code, n, why in loops[:5]:
        print(f"  LOOP {code}: reopened {n}x, last: {why}")
    if not advancing and junk > code_lines:
        print("  CIRCLES: the tree fills with logs while no code lands; read the LOOP rows above")
    for a in actions:
        print(f"  FIXED: {a}")


if __name__ == "__main__":
    sys.exit(main())
