#!/usr/bin/env python3
"""OGameX task-DB CLI — the native interface to plan/tasks/tasks.db.

Uses Python's sqlite3 (bundled with the interpreter, same C library as the sqlite3 CLI).
The sqlite3 CLI also works if installed: `sudo apt-get install -y sqlite3`.

Usage:
  python3 plan/tasks/task.py list|ready|blocked|graph
  python3 plan/tasks/task.py claim CODE ASSIGNEE   # the row AND its files, or nothing
  python3 plan/tasks/task.py lock CODE PATH ...    # one more file the row's work touches
  python3 plan/tasks/task.py reap                  # release claims nobody has touched in 6h
  python3 plan/tasks/task.py unclaim CODE
  python3 plan/tasks/task.py next                  # the one row to work now (P0-P2 impl, by priority)
  python3 plan/tasks/task.py show CODE             # everything the row says, proof included
  python3 plan/tasks/task.py proof CODE [STEP ...] # read or set the proof: test:X situation:Y aspect:Z invariant:NAME
  python3 plan/tasks/task.py done CODE             # runs the proof; refuses unless it passes
  python3 plan/tasks/task.py block CODE NOTE      # todo -> blocked
  python3 plan/tasks/task.py unblock CODE          # blocked -> todo
  python3 plan/tasks/task.py deps CODE             # direct + transitive dependencies
  python3 plan/tasks/task.py depends-on CODE       # reverse: who needs this
  python3 plan/tasks/task.py add CODE TITLE KIND PRIORITY [--depends D1,D2] \
      [--gap G] [--principles P] [--alg A] [--file F] [--notes N]
  python3 plan/tasks/task.py rebuild               # re-run seed.sql (resets all statuses)
"""
import argparse
import datetime
import hashlib
import os
import re
import sqlite3
import subprocess
import sys

DB = os.path.join(os.path.dirname(os.path.abspath(__file__)), "tasks.db")
SEED = os.path.join(os.path.dirname(os.path.abspath(__file__)), "seed.sql")


MODULE = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
CLAIMS = os.path.join(MODULE, "plan/research/ogame/claims")
# An interactive agent works a row for hours; the harness holds a file for one attempt. A lock older
# than this with no activity belongs to an agent that went away.
AGENT_CLAIM_HOURS = 6


# The north star in one rule: code work must be judged by something an account visibly does on the
# cohorts (an aspect, a situation, an invariant) or keep the loop that judges it sound (harness).
# A row that can only say "my test passes" cannot be taken, created or closed as code work.
NORTH_STAR_STEPS = ("aspect", "situation", "invariant", "harness")


def on_path(proof):
    """Whether a proof judges the row by the account's behaviour or by the loop that measures it."""
    return any(re.search(rf"(^|\s){kind}:", proof or "") for kind in NORTH_STAR_STEPS)


def off_path_reason(code):
    return (f"{code} names no aspect it moves. Give it a proof with an aspect:, situation: or invariant: "
            f"step (`task.py proof {code} aspect:economy ...`; `bash scripts/ogamex scorecard` lists the "
            f"aspects), or freeze it. Work that moves no aspect is not north-star work.")


def file_paths(file_ref):
    """The files a row names. One parser for the ledger and the harness, so both lock the same key:
    `;` or `,` separated, with a trailing `(new)`, `(edit)` or `(method names)` note taken off."""
    paths = []
    for piece in re.split(r"[;,]", file_ref or ""):
        clean = re.sub(r"\s*\([^)]*\)\s*$", "", piece.strip("- ").replace("`", "")).strip()
        if clean:
            paths.append(clean)

    return paths


def lock_path(key):
    """Where the lock for a key lives. The harness's own file claims use this same name."""
    return os.path.join(CLAIMS, hashlib.sha256(key.encode()).hexdigest()[:16] + ".lock")


def lock_owner(path):
    try:
        with open(path, encoding="utf-8") as handle:
            # Line 2 names the holder: "agent NAME CODE" from here, "pid N at ..." from the harness.
            return handle.read().splitlines()[1]
    except (OSError, IndexError):
        return ""


def take_locks(code, assignee, paths):
    """Every file at once or none, written O_EXCL. Returns (taken, the path someone else holds)."""
    os.makedirs(CLAIMS, exist_ok=True)
    taken = []
    for path in sorted({os.path.join(MODULE, p) for p in paths}):
        lock = lock_path(path)
        try:
            handle = os.open(lock, os.O_CREAT | os.O_EXCL | os.O_WRONLY)
        except FileExistsError:
            if lock_owner(lock) == f"agent {assignee} {code}":
                continue
            for mine in taken:
                os.remove(mine)
            return [], f"{os.path.relpath(path, MODULE)} ({lock_owner(lock) or 'held'})"
        os.write(handle, f"{path}\nagent {assignee} {code}\n".encode())
        os.close(handle)
        taken.append(lock)

    return taken, None


def release_locks(code, assignee):
    if not os.path.isdir(CLAIMS):
        return 0
    released = 0
    for name in os.listdir(CLAIMS):
        lock = os.path.join(CLAIMS, name)
        if lock_owner(lock) == f"agent {assignee} {code}":
            os.remove(lock)
            released += 1

    return released


def connect():
    con = sqlite3.connect(DB)
    con.execute("PRAGMA foreign_keys = ON")
    return con


def rows(con, sql, args=()):
    return con.execute(sql, args).fetchall()


def print_table(header, data):
    widths = [len(h) for h in header]
    for r in data:
        for i, v in enumerate(r):
            widths[i] = max(widths[i], len(str(v)))
    line = "  ".join(h.ljust(widths[i]) for i, h in enumerate(header))
    print(line)
    print("  ".join("-" * widths[i] for i in range(len(header))))
    for r in data:
        print("  ".join(str(v).ljust(widths[i]) for i, v in enumerate(r)))


def cmd_list(con):
    data = rows(con, "SELECT code,kind,status,priority,title FROM tasks ORDER BY id")
    print_table(["code", "kind", "status", "prio", "title"], data)


def cmd_ready(con):
    data = rows(con, "SELECT code,priority,title,file_ref FROM ready_tasks ORDER BY priority,id")
    print_table(["code", "prio", "title", "file"], data)


def cmd_blocked(con):
    data = rows(con, """
      SELECT t.code, group_concat(dep.code)
      FROM blocked_tasks t
      JOIN dependencies d ON d.task_id = t.id
      JOIN tasks dep ON dep.id = d.depends_on
      WHERE dep.status <> 'done'
      GROUP BY t.id ORDER BY t.id""")
    print_table(["code", "blocked by"], data)


def cmd_claim(con, code, assignee):
    """The row and every file it names, or nothing: two agents in one file is how one slice's code
    ends up inside another's, and the harness's rollback then erases the other agent's edit."""
    row = con.execute("SELECT kind, coalesce(proof,'') FROM tasks WHERE code=?", (code,)).fetchone()
    if row and row[0] == "impl" and not on_path(row[1]):
        sys.exit("NOT claimed: " + off_path_reason(code))
    cur = con.execute(
        "UPDATE tasks SET status='in_progress', assignee=?, updated_at=datetime('now') "
        "WHERE code=? AND status='todo'", (assignee, code))
    if not cur.rowcount:
        con.rollback()
        sys.exit(f"NOT claimed {code} (already taken or not todo)")
    file_ref = con.execute("SELECT coalesce(file_ref,'') FROM tasks WHERE code=?", (code,)).fetchone()[0]
    _, held = take_locks(code, assignee, file_paths(file_ref))
    if held:
        con.rollback()
        sys.exit(f"NOT claimed {code}: {held} is being edited by someone else. Take another row.")
    con.commit()
    print("claimed", code, "and locked:", ", ".join(file_paths(file_ref)) or "(no files)")


def cmd_lock(con, code, paths):
    row = con.execute("SELECT assignee FROM tasks WHERE code=? AND status='in_progress'", (code,)).fetchone()
    if row is None or not row[0]:
        sys.exit(f"claim {code} first")
    _, held = take_locks(code, row[0], paths)
    if held:
        sys.exit(f"NOT locked: {held} is being edited by someone else")
    print("locked", ", ".join(paths), "for", code)


def cmd_unclaim(con, code):
    row = con.execute("SELECT coalesce(assignee,'') FROM tasks WHERE code=?", (code,)).fetchone()
    released = release_locks(code, row[0]) if row else 0
    con.execute("UPDATE tasks SET status='todo', assignee=NULL WHERE code=?", (code,))
    con.commit()
    print("released", code, f"and {released} file lock(s)")


def cmd_reap(con):
    """Claims a vanished agent left: a row in progress untouched for hours, and its file locks."""
    # A harness attempt lasts minutes, so its row is stale after an hour (a killed worker); a row
    # waiting on its proof (harness:delivered) is not stale at all.
    stale = con.execute(
        "SELECT code, coalesce(assignee,'') FROM tasks WHERE status='in_progress' AND ("
        "(coalesce(assignee,'') NOT LIKE 'harness:%' AND updated_at < datetime('now', ?)) OR "
        "(assignee LIKE 'harness:%' AND assignee <> 'harness:delivered' AND updated_at < datetime('now', '-1 hours')))",
        (f"-{AGENT_CLAIM_HOURS} hours",)).fetchall()
    for code, assignee in stale:
        release_locks(code, assignee)
        con.execute("UPDATE tasks SET status='todo', assignee=NULL, notes=coalesce(notes,'') || ? WHERE code=?",
                    (f" | {datetime.datetime.now(datetime.timezone.utc):%Y-%m-%d %H:%M} UTC: claim by {assignee} "
                     f"released after {AGENT_CLAIM_HOURS}h without activity.", code))
    con.commit()
    print("reaped:", ", ".join(code for code, _ in stale) or "nothing")


def cmd_done(con, code):
    """Done means proven. A code row closes only when its proof runs green: a passing unit of code
    that changes nothing an account does is how 34 of 75 'delivered' slices turned out dead."""
    row = con.execute("SELECT kind, coalesce(proof,'') FROM tasks WHERE code=?", (code,)).fetchone()
    if row is None:
        sys.exit(f"no task {code}")
    kind, proof = row
    if kind == "impl" and not on_path(proof):
        sys.exit("NOT done: " + off_path_reason(code))
    if kind == "impl":
        module = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
        result = subprocess.run(["bash", os.path.join(module, "scripts/ogamex"), "prove", code])
        if result.returncode != 0:
            sys.exit(f"NOT done: the proof for {code} failed (see above). The row stays open.")
    holder = con.execute("SELECT coalesce(assignee,'') FROM tasks WHERE code=?", (code,)).fetchone()[0]
    release_locks(code, holder)
    con.execute("UPDATE tasks SET status='done', assignee=NULL, notes=coalesce(notes,'') || ?, "
                "updated_at=datetime('now') WHERE code=?",
                (f" | PROVEN {datetime.datetime.now(datetime.timezone.utc):%Y-%m-%d %H:%M} UTC: {proof}" if proof else "", code))
    con.commit()
    print("done", code)


def cmd_show(con, code):
    row = con.execute("SELECT * FROM tasks WHERE code=?", (code,)).fetchone()
    if row is None:
        sys.exit(f"no task {code}")
    names = [d[0] for d in con.execute("SELECT * FROM tasks LIMIT 0").description]
    for name, value in zip(names, row):
        print(f"{name}: {value if value is not None else ''}")
    deps = [d[0] for d in rows(con, "SELECT dep.code || ' [' || dep.status || ']' FROM dependencies d JOIN tasks dep ON dep.id=d.depends_on JOIN tasks t ON t.id=d.task_id WHERE t.code=?", (code,))]
    print("depends on: " + (", ".join(deps) or "(none)"))


def cmd_next(con):
    """The single row an agent should take: worst observable badness first, code rows that name a file."""
    row = con.execute(
        "SELECT code, priority, title, file_ref FROM ready_tasks WHERE kind='impl' AND priority IN ('P0','P1','P2') "
        "AND coalesce(file_ref,'') <> '' AND (" + " OR ".join(f"proof LIKE '%{k}:%'" for k in NORTH_STAR_STEPS) + ") "
        "ORDER BY priority, id LIMIT 1").fetchone()
    if row is None:
        print("NEXT: nothing ready")
        return
    print(f"NEXT: {row[0]} [{row[1]}] {row[2]}\n  file: {row[3]}\n  read it: python3 plan/tasks/task.py show {row[0]}")


def cmd_proof(con, code, steps):
    if steps:
        bad = [step for step in steps if not re.match(r"^(test|situation|aspect|invariant|harness):[\w.-]+$", step)]
        if bad:
            sys.exit(f"not a proof step: {' '.join(bad)} (use test:TestName situation:name aspect:name invariant:NAME)")
        kind = con.execute("SELECT kind FROM tasks WHERE code=?", (code,)).fetchone()
        if kind and kind[0] == "impl" and not on_path(" ".join(steps)):
            sys.exit("proof NOT set: " + off_path_reason(code))
        con.execute("UPDATE tasks SET proof=?, updated_at=datetime('now') WHERE code=?", (" ".join(steps), code))
        con.commit()
    row = con.execute("SELECT coalesce(proof,'') FROM tasks WHERE code=?", (code,)).fetchone()
    if row is None:
        sys.exit(f"no task {code}")
    print(row[0])


def cmd_block(con, code, note):
    holder = con.execute("SELECT coalesce(assignee,'') FROM tasks WHERE code=?", (code,)).fetchone()
    if holder:
        release_locks(code, holder[0])
    con.execute("UPDATE tasks SET status='blocked', notes=coalesce(notes,'') || ' | blocked: ' || ? WHERE code=?", (note, code))
    con.commit()
    print("blocked", code)


def cmd_unblock(con, code):
    con.execute("UPDATE tasks SET status='todo' WHERE code=? AND status='blocked'", (code,))
    con.commit()
    print("unblocked", code)


def transitive(con, code, direction):
    seen, frontier = set(), [code]
    while frontier:
        c = frontier.pop()
        if c in seen:
            continue
        seen.add(c)
        if direction == "up":
            frontier += [r[0] for r in rows(con, "SELECT dep.code FROM dependencies d JOIN tasks dep ON dep.id=d.depends_on JOIN tasks t ON t.id=d.task_id WHERE t.code=?", (c,))]
        else:
            frontier += [r[0] for r in rows(con, "SELECT t.code FROM dependencies d JOIN tasks t ON t.id=d.task_id JOIN tasks dep ON dep.id=d.depends_on WHERE dep.code=?", (c,))]
    seen.discard(code)
    return sorted(seen)


def cmd_deps(con, code):
    print("depends on:", ", ".join(transitive(con, code, "up")) or "(none)")


def cmd_dependson(con, code):
    print("needed by:", ", ".join(transitive(con, code, "down")) or "(none)")


def cmd_add(con, a):
    kinds = {"review", "doc", "impl", "deferred", "discovery"}
    if a.kind not in kinds:
        sys.exit(f"kind must be one of {sorted(kinds)}")
    # A deferred row is born deferred: ready_tasks selects status='todo', so inserting one as
    # todo would offer work the plan has deliberately not scheduled to the next agent.
    if a.kind == "impl" and not on_path(a.proof):
        sys.exit("NOT added: " + off_path_reason(a.code))
    status = "deferred" if a.kind == "deferred" else "todo"
    con.execute(
        "INSERT OR REPLACE INTO tasks (code,title,kind,status,priority,gap_ref,principle_refs,algorithm_ref,file_ref,notes,proof) "
        "VALUES (?,?,?,?,?,?,?,?,?,?,?)",
        (a.code, a.title, a.kind, status, a.priority, a.gap, a.principles, a.alg, a.file, a.notes, a.proof))
    for dep in (a.depends or "").split(","):
        dep = dep.strip()
        if dep:
            con.execute("INSERT OR REPLACE INTO dependencies (task_id, depends_on) SELECT t.id, d.id FROM tasks t, tasks d WHERE t.code=? AND d.code=?",
                        (a.code, dep))
    con.commit()
    print("added", a.code, "| deps:", (a.depends or "") or "(none)")


def cmd_graph(con):
    for r in rows(con, "SELECT id, code, kind, status FROM tasks ORDER BY id"):
        deps = [d[0] for d in rows(con, "SELECT dep.code FROM dependencies d JOIN tasks dep ON dep.id=d.depends_on WHERE d.task_id=?", (r[0],))]
        suffix = "  <- " + ", ".join(deps) if deps else ""
        print(f"{r[1]:10} [{r[3]:10}] {r[2]}{suffix}")


def cmd_rebuild(con):
    con.executescript(open(SEED).read())
    con.commit()
    print("rebuilt from seed.sql; all statuses reset")


def main():
    p = argparse.ArgumentParser(description="OGameX task-DB CLI")
    sub = p.add_subparsers(dest="cmd", required=True)
    for name in ("list", "ready", "blocked", "graph", "rebuild", "next", "reap"):
        sub.add_parser(name)
    cp = sub.add_parser("claim"); cp.add_argument("code"); cp.add_argument("assignee")
    for name in ("unclaim", "done", "unblock", "show"):
        sub.add_parser(name).add_argument("code")
    bp = sub.add_parser("block"); bp.add_argument("code"); bp.add_argument("note")
    sub.add_parser("deps").add_argument("code")
    sub.add_parser("depends-on").add_argument("code")
    ap = sub.add_parser("add")
    ap.add_argument("code"); ap.add_argument("title"); ap.add_argument("kind"); ap.add_argument("priority")
    ap.add_argument("--depends", default="")
    ap.add_argument("--gap", default=None)
    ap.add_argument("--principles", default=None)
    ap.add_argument("--alg", default=None)
    ap.add_argument("--file", default=None)
    ap.add_argument("--notes", default=None)
    ap.add_argument("--proof", default=None)
    pp = sub.add_parser("proof"); pp.add_argument("code"); pp.add_argument("steps", nargs="*")
    lp = sub.add_parser("lock"); lp.add_argument("code"); lp.add_argument("paths", nargs="+")

    a = p.parse_args()
    con = connect()
    try:
        cmd = a.cmd
        if cmd == "list":
            cmd_list(con)
        elif cmd == "ready":
            cmd_ready(con)
        elif cmd == "blocked":
            cmd_blocked(con)
        elif cmd == "graph":
            cmd_graph(con)
        elif cmd == "rebuild":
            cmd_rebuild(con)
        elif cmd == "claim":
            cmd_claim(con, a.code, a.assignee)
        elif cmd == "unclaim":
            cmd_unclaim(con, a.code)
        elif cmd == "done":
            cmd_done(con, a.code)
        elif cmd == "block":
            cmd_block(con, a.code, a.note)
        elif cmd == "unblock":
            cmd_unblock(con, a.code)
        elif cmd == "deps":
            cmd_deps(con, a.code)
        elif cmd == "depends-on":
            cmd_dependson(con, a.code)
        elif cmd == "add":
            cmd_add(con, a)
        elif cmd == "show":
            cmd_show(con, a.code)
        elif cmd == "next":
            cmd_next(con)
        elif cmd == "proof":
            cmd_proof(con, a.code, a.steps)
        elif cmd == "lock":
            cmd_lock(con, a.code, a.paths)
        elif cmd == "reap":
            cmd_reap(con)
    finally:
        con.close()


if __name__ == "__main__":
    main()
