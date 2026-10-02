#!/usr/bin/env python3
"""Watch the worker harness the way its owner does: is it advancing north-star work, or filling git with logs?

One pass, then exit; scripts/babysitter.sh runs it on a clock. It measures from the ledger, git and the
scorecard, prints one verdict, and applies only fixes that are safe to repeat:

  - harness process gone            -> restart it (same env the owner launched it with)
  - in_progress row, no touch 5 min -> task.py unstick (the row goes back to the writers)
  - nothing delivered or committed  -> say which rows are looping and why, and ask for no new work

It never edits code, never closes a row and never touches the price gate.
"""
import glob, json, os, re, sqlite3, subprocess, sys, time
from datetime import datetime, timedelta, timezone

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DB = os.path.join(ROOT, "plan/tasks/tasks.db")
STATE = "/tmp/babysitter-state.json"
STATUS = os.path.join(ROOT, "plan/research/ogame/babysitter.json")
WINDOW_MIN = int(os.environ.get("BABYSIT_WINDOW", "60"))
STUCK_MIN = int(os.environ.get("BABYSIT_STUCK", "5"))
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


def workers_busy_with():
    """Codes named by a worker heartbeat younger than the stuck threshold: someone is on them."""
    busy = set()
    for path in glob.glob(os.path.join(ROOT, "plan/research/ogame/workers/*.json")):
        if time.time() - os.path.getmtime(path) < STUCK_MIN * 60:
            busy.update(re.findall(r"[A-Z]+-\d+", open(path).read()))
    return busy


def advance(actions):
    """Give back rows held for STUCK_MIN with no change and no worker heartbeat, so a writer takes them."""
    con = sqlite3.connect(DB)
    cutoff = (datetime.now(timezone.utc) - timedelta(minutes=STUCK_MIN)).strftime("%Y-%m-%d %H:%M:%S")
    busy = workers_busy_with()
    for code, assignee in con.execute("select code, assignee from tasks where status='in_progress' and updated_at < ?", (cutoff,)).fetchall():
        # "harness:delivered" is a finished slice waiting for its proof, not a claim that went quiet.
        if code in busy or (assignee or "").startswith(("claude", "harness:delivered")):
            continue
        sh("python3", "plan/tasks/task.py", "unstick", code)
        actions.append(f"unstuck {code} (held by {assignee or 'nobody'}, no change or heartbeat for {STUCK_MIN} min)")
    return actions


SLOW_LOG = os.path.join(ROOT, "plan/research/ogame/slow-steps.log")
OWNERS = {"invariant": "scripts/verify-cohorts.php", "aspect": "scripts/play-scorecard.php", "harness": "scripts/strategy-pipeline.py"}


def slow_verification(actions):
    """A verification step has a minute (scripts/ogamex logs any step over it). Find out why, then fix it.

    A test that is slow only while it queued is already fixed by the parallel lanes; one that is slow
    alone, or a live step that is slow, gets a row for the harness to build its fast path.
    """
    if not os.path.exists(SLOW_LOG):
        return
    since = datetime.now(timezone.utc) - timedelta(minutes=WINDOW_MIN)
    steps = {}
    for line in open(SLOW_LOG):
        at, code, step, took = line.rstrip("\n").split("\t")[:4]
        if datetime.strptime(at, "%Y-%m-%d %H:%M:%S").replace(tzinfo=timezone.utc) >= since:
            steps.setdefault(step.rstrip("?"), []).append((code, int(took)))
    con = sqlite3.connect(DB)
    for step, seen in steps.items():
        kind, _, name = step.partition(":")
        worst = max(t for _, t in seen)
        if kind == "test":
            alone = time.time()
            subprocess.run(["bash", "scripts/ogamex", "test-one", name], cwd=ROOT, capture_output=True, timeout=300,
                           env={**os.environ, "OGAMEX_RUNNER": "local-docker-dev"})
            alone = int(time.time() - alone)
            if alone <= 60:
                actions.append(f"{step} took {worst}s in a proof but {alone}s alone: it was waiting for a test lane")
                continue
            file_ref = f"tests/Feature/{name}.php"
            reason = f"runs {alone}s on its own"
        else:
            file_ref = OWNERS.get(kind, "scripts/ogamex")
            reason = f"took {worst}s inside a proof"
        code = "FAST-" + re.sub(r"[^A-Za-z0-9]+", "-", step).strip("-")[:40]
        if con.execute("select 1 from tasks where code=?", (code,)).fetchone():
            continue
        sh("python3", "plan/tasks/task.py", "add", code, f"Verification step {step} {reason}; give it a path that answers in under 60 s",
           "impl", "P0", "--file", file_ref, "--proof", "harness:self-check",
           "--notes", f"Raised by the babysitter. A proof step has 60 s. {step} {reason} (rows: {', '.join(c for c, _ in seen)}). "
                      "Make it fast without weakening what it proves: read stored results instead of recomputing, build the "
                      "state with the Situation kit instead of waiting, or split the test. The step must then pass in under 60 s.")
        actions.append(f"raised {code}: {step} {reason}")


def scratch_files(actions):
    """Writer debris: untracked diagnostics and probe tests are circles, not delivery. Remove them."""
    for line in sh("git", "status", "--short", "--untracked-files=all").splitlines():
        if not line.startswith("?? "):
            continue
        path = line[3:].strip()
        name = os.path.basename(path)
        if name.startswith("diagnose-") or re.search(r"(Probe|Debug|Scratch)(Test)?\.php$", name):
            os.remove(os.path.join(ROOT, path))
            actions.append(f"removed writer scratch file {path}")


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
    if len(sys.argv) > 1 and sys.argv[1] == "advance":
        for line in advance([]):
            print(f"BABYSIT advance: {line}")
        return
    actions = []
    if not harness_alive():
        env = {**os.environ, "IMPL_WORKERS": "6", "MODEL_CONCURRENCY": "8", "OGAMEX_RUNNER": "local-docker-dev"}
        subprocess.Popen(["setsid", "nohup", "bash", "scripts/harness-live.sh"], cwd=ROOT, env=env,
                         stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        actions.append("restarted the harness (it was not running)")

    done, _, loops = ledger()
    advance(actions)
    scratch_files(actions)
    slow_verification(actions)

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

    previous = json.load(open(STATUS)) if os.path.exists(STATUS) else {}
    now = {"at": datetime.now(timezone.utc).strftime("%H:%M"), "verdict": verdict, "delivered": done,
           "code_lines": code_lines, "other_lines": other_lines, "changed": changed, "junk": junk,
           "passing": passing, "total": total, "failing": failing, "window": WINDOW_MIN,
           "loops": [{"code": c, "reopened": n, "why": w} for c, n, w in loops[:5]], "fixed": actions}
    history = (previous.get("history") or [])[-23:] + [{"at": now["at"], "verdict": verdict, "delivered": len(done), "passing": passing, "fixed": actions}]
    json.dump({**now, "history": history, "written": time.time()}, open(STATUS, "w"))


if __name__ == "__main__":
    sys.exit(main())
