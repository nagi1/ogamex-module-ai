#!/usr/bin/env python3
"""OGameX task-DB CLI — the native interface to plan/tasks/tasks.db.

Uses Python's sqlite3 (bundled with the interpreter, same C library as the sqlite3 CLI).
The sqlite3 CLI also works if installed: `sudo apt-get install -y sqlite3`.

Usage:
  python3 plan/tasks/task.py list|ready|blocked|graph
  python3 plan/tasks/task.py claim CODE ASSIGNEE
  python3 plan/tasks/task.py unclaim CODE
  python3 plan/tasks/task.py done CODE
  python3 plan/tasks/task.py block CODE NOTE      # todo -> blocked
  python3 plan/tasks/task.py unblock CODE          # blocked -> todo
  python3 plan/tasks/task.py deps CODE             # direct + transitive dependencies
  python3 plan/tasks/task.py depends-on CODE       # reverse: who needs this
  python3 plan/tasks/task.py add CODE TITLE KIND PRIORITY [--depends D1,D2] \
      [--gap G] [--principles P] [--alg A] [--file F] [--notes N]
  python3 plan/tasks/task.py rebuild               # re-run seed.sql (resets all statuses)
"""
import argparse
import os
import sqlite3
import sys

DB = os.path.join(os.path.dirname(os.path.abspath(__file__)), "tasks.db")
SEED = os.path.join(os.path.dirname(os.path.abspath(__file__)), "seed.sql")


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
    cur = con.execute(
        "UPDATE tasks SET status='in_progress', assignee=?, updated_at=datetime('now') "
        "WHERE code=? AND status='todo'", (assignee, code))
    con.commit()
    print("claimed" if cur.rowcount else "NOT claimed (already taken or not todo)", code)


def cmd_unclaim(con, code):
    con.execute("UPDATE tasks SET status='todo', assignee=NULL WHERE code=?", (code,))
    con.commit()
    print("released", code)


def cmd_done(con, code):
    con.execute("UPDATE tasks SET status='done', assignee=NULL, updated_at=datetime('now') WHERE code=?", (code,))
    con.commit()
    print("done", code)


def cmd_block(con, code, note):
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
    con.execute(
        "INSERT OR REPLACE INTO tasks (code,title,kind,status,priority,gap_ref,principle_refs,algorithm_ref,file_ref,notes) "
        "VALUES (?,?,?,?,?,?,?,?,?,?)",
        (a.code, a.title, a.kind, "todo", a.priority, a.gap, a.principles, a.alg, a.file, a.notes))
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
    for name in ("list", "ready", "blocked", "graph", "rebuild"):
        sub.add_parser(name)
    cp = sub.add_parser("claim"); cp.add_argument("code"); cp.add_argument("assignee")
    for name in ("unclaim", "done", "unblock"):
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
    finally:
        con.close()


if __name__ == "__main__":
    main()
