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
        if name.startswith("diagnose-") or re.search(r"(Probe|Debug|Scratch|Diagnosis)(Test)?\.php$|^Tmp", name):
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


def stuck_scenarios(actions):
    """A refusal one account repeats is a planner offering what the host will never take. Raise it once."""
    out = subprocess.run(["bash", "scripts/ogamex", "stuck", "60", "4"], cwd=ROOT, capture_output=True, text=True, timeout=120,
                         env={**os.environ, "OGAMEX_RUNNER": "local-docker-dev"}).stdout
    con = sqlite3.connect(DB)
    for found in re.finditer(r"^\s+(\d+) attempts,\s+(\d+) stuck accounts\s+(.+)\n\s+e\.g\. (.+)$", out, re.M):
        attempts, accounts, reason, sample = int(found[1]), int(found[2]), found[3], found[4]
        if accounts < 3:
            continue
        code = "STUCK-" + re.sub(r"[^A-Za-z0-9]+", "-", reason).strip("-")[:36]
        if con.execute("select 1 from tasks where code=? and status not in ('done')", (code,)).fetchone():
            continue
        sh("python3", "plan/tasks/task.py", "add", code, f"{accounts} accounts keep failing the same way: {reason}",
           "impl", "P0", "--proof", "aspect:work_failures",
           "--notes", f"Raised by the babysitter from `bash scripts/ogamex stuck`: {attempts} attempts in an hour, e.g. {sample} (player x repeats). "
                      "Find which planner offers it (bash scripts/ogamex account PLAYER), make the planner stop offering it or do the "
                      "step the host needs first (build the ship, load less, wait for the slot), and prove it with a situation test.")
        actions.append(f"raised {code}: {accounts} accounts repeat '{reason}'")


USAGE = os.path.join(ROOT, "plan/research/ogame/model-usage.jsonl")
BURN_CALLS = int(os.environ.get("BABYSIT_BURN_CALLS", "1000000"))  # owner 3 Oct 2026: writers run free; a stuck row is delegated, not cut off


def burn_watchdog(actions):
    """A row that spends many model calls an hour while no code lands on its files is a loop, not work.

    The writer is stopped and the row is handed to the strong lane (claude), where the harness leaves it
    alone: another thousand calls would only add test files that diagnose the same gap.
    """
    if not os.path.exists(USAGE):
        return
    since = datetime.now(timezone.utc) - timedelta(minutes=WINDOW_MIN)
    calls = {}
    for line in open(USAGE).readlines()[-3000:]:
        try:
            row = json.loads(line)
            at = datetime.strptime(row["at"], "%Y-%m-%dT%H:%M:%SZ").replace(tzinfo=timezone.utc)
        except (ValueError, KeyError):
            continue
        found = re.match(r"implementing (\S+)", row.get("purpose") or "")
        if found and at >= since:
            calls[found[1]] = calls.get(found[1], 0) + 1
    con = sqlite3.connect(DB)
    for code, n in calls.items():
        row = con.execute("select status, file_ref, assignee from tasks where code=?", (code,)).fetchone()
        if row is None or row[0] != "in_progress" or n < BURN_CALLS or (row[2] or "").startswith("claude"):
            continue
        files = [f.split(" (")[0].strip() for f in (row[1] or "").split(";") if f.strip()]
        landed = sh("git", "log", f"--since={WINDOW_MIN} minutes ago", "--oneline", "--", *files) if files else ""
        if landed.strip():
            continue
        subprocess.run(["pkill", "-f", f"^python3 -u scripts/strategy-pipeline.py implement {code}"])
        sh("python3", "plan/tasks/task.py", "unstick", code)
        sh("python3", "plan/tasks/task.py", "claim", code, "claude-lane")
        con.execute("update tasks set notes=coalesce(notes,'')||? where code=?",
                    (f" | BURN {datetime.now(timezone.utc):%Y-%m-%d %H:%M} UTC: {n} writer calls in {WINDOW_MIN} min and nothing landed on its files; writer stopped, row handed to the strong lane (claude).", code))
        con.commit()
        actions.append(f"stopped {code}: {n} writer calls in {WINDOW_MIN} min, nothing landed; handed to claude")


HOST_OBJECTS = os.path.join(os.path.dirname(os.path.dirname(ROOT)), "app/GameObjects")


def host_machine_names():
    names = set()
    for dirpath, _, files in os.walk(HOST_OBJECTS):
        for name in files:
            if name.endswith(".php"):
                names.update(re.findall(r"machine_name = '([a-z_]+)'", open(os.path.join(dirpath, name)).read()))
    return names


def code_guard(actions):
    """New writer code must keep the AI a player, not a script: no else/elseif, no Mockery, no host object named in app/.

    Violations are flagged for the strong lane and a row is raised once per file; nothing is rewritten here.
    """
    names = host_machine_names()
    diff = sh("git", "diff", "HEAD", "-U0", "--", "app", "tests")
    added = {}
    current = None
    for line in diff.splitlines():
        if line.startswith("+++ b/"):
            current = line[6:]
        elif line.startswith("+") and not line.startswith("+++") and current:
            added.setdefault(current, []).append(line[1:])
    for path in sh("git", "ls-files", "--others", "--exclude-standard", "--", "app", "tests").split():
        added[path] = open(os.path.join(ROOT, path)).read().splitlines()
    flagged = []
    for path, lines in added.items():
        for text in lines:
            code_part = text.split("//")[0]
            if re.search(r"(^|[\s}])(else|elseif)\b(?!\s*:)", code_part) and path.endswith(".php") and not code_part.strip().startswith(("*", "/*")):
                flagged.append((path, "else/elseif"))
            if "Mockery" in text and path.startswith("tests/"):
                flagged.append((path, "Mockery"))
            if path.startswith("app/") and path.endswith(".php"):
                hit = [n for n in re.findall(r"'([a-z]+(?:_[a-z]+)*)'", code_part) if n in names and n not in ("metal", "crystal", "deuterium", "energy")]
                if hit:
                    flagged.append((path, f"names host object {hit[0]}"))
    con = sqlite3.connect(DB)
    for path, rule in sorted(set(flagged)):
        code = "RULE-" + re.sub(r"[^A-Za-z0-9]+", "-", os.path.basename(path))[:30]
        if con.execute("select 1 from tasks where code=? and status!='done'", (code,)).fetchone():
            continue
        sh("python3", "plan/tasks/task.py", "add", code, f"New code in {path} breaks a module rule: {rule}", "impl", "P0",
           "--file", path, "--proof", "test:AI",
           "--notes", "Raised by the babysitter code guard. Gate 1 (the object catalogue is read, never named), the no-else rule and the "
                      "no-Mockery rule keep the account a player rather than a script. Rewrite the lines with early returns, a lookup "
                      "or a catalogue read, keep the behaviour, and keep the suite green.")
        actions.append(f"raised {code}: {rule} in {path}")


def commit_records(actions):
    """Run records and the ledger change every minute; one commit every six hours keeps them out of the
    tree without burying the real work in snapshot commits (40 of 47 commits in a day were this)."""
    last = sh("git", "log", "-1", "--format=%ct", "--grep=harness run records").strip()
    if last and time.time() - int(last) < 6 * 3600:
        return
    paths = ["plan/research/ogame", "plan/tasks/tasks.db"]
    sh("git", "add", "-A", "--", *paths)
    if subprocess.run(["git", "diff", "--cached", "--quiet", "--", *paths], cwd=ROOT).returncode == 0:
        return
    sh("git", "commit", "-q", "-m", "harness run records and ledger snapshot (babysitter)", "--", *paths)
    actions.append("committed the harness run records and ledger")


LANE = "claude-lane"
LANE_QUEUE = 3  # rows waiting for the strong lane at once; it works one run at a time


def delegate_to_claude(actions):
    """Stuck, very important and reopened rows go to Claude Code (scripts/claude-lane.py), and only there.

    A writer already on the row is stopped first (the harness would respawn it on the files otherwise);
    `claim` then takes the row and its files so no writer touches them again.
    """
    con = sqlite3.connect(DB)
    queued = con.execute("select count(*) from tasks where assignee=? and status in ('todo','in_progress')", (LANE,)).fetchone()[0]
    rows = con.execute(
        "select code, priority, status, coalesce(notes,'') from tasks where kind='impl' "
        "and status in ('todo','in_progress') and (assignee is null or assignee='' or assignee like 'harness%') "
        "and code not like 'WIK-%' order by priority, updated_at").fetchall()
    for code, priority, status, notes in rows:
        if queued >= LANE_QUEUE:
            break
        reopened = notes.count("REOPENED") >= 2 and "CLAUDE-LANE" not in notes
        urgent = priority == "P0" and (code.startswith(("STUCK-", "LIFE-", "FLEET-")) or status == "in_progress")
        if not (reopened or urgent) or notes.count("CLAUDE-LANE") >= 2:
            continue
        subprocess.run(["pkill", "-f", f"^python3 -u scripts/strategy-pipeline.py implement {code}"])
        sh("python3", "plan/tasks/task.py", "unstick", code)
        sh("python3", "plan/tasks/task.py", "claim", code, LANE)
        if sqlite3.connect(DB).execute("select assignee from tasks where code=?", (code,)).fetchone()[0] != LANE:
            continue
        queued += 1
        actions.append(f"delegated {code} ({'reopened' if reopened else 'urgent'}) to the claude lane")
    run_claude_lane(actions)


def run_claude_lane(actions):
    """Start the lane when a row waits and no run is going; the script's own flock is the guard."""
    waiting = sqlite3.connect(DB).execute("select count(*) from tasks where assignee=? and status in ('todo','in_progress')", (LANE,)).fetchone()[0]
    if waiting == 0 or subprocess.run(["pgrep", "-f", "scripts/claude-lane.py"], capture_output=True).returncode == 0:
        return
    subprocess.Popen(["setsid", "nohup", sys.executable, "scripts/claude-lane.py"], cwd=ROOT,
                     stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    actions.append("started a claude lane run")


def reopen_churn(actions):
    """A row that is done, reopened and done again is the harness going in circles, not delivering."""
    cutoff = datetime.now(timezone.utc) - timedelta(hours=1)
    stamps = re.findall(r"REOPENED (\d{4}-\d\d-\d\d \d\d:\d\d) UTC",
                        " ".join(n or "" for (n,) in sqlite3.connect(DB).execute("select notes from tasks")))
    recent = [t for t in stamps if datetime.strptime(t, "%Y-%m-%d %H:%M").replace(tzinfo=timezone.utc) > cutoff]
    if len(recent) >= 3:
        actions.append(f"CHURN: {len(recent)} rows reopened in the last hour; the quality read and the proofs disagree")


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
    stuck_scenarios(actions)
    burn_watchdog(actions)
    delegate_to_claude(actions)
    code_guard(actions)
    commit_records(actions)
    reopen_churn(actions)

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
