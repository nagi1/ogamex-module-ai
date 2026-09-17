#!/usr/bin/env python3
"""Regenerate plan/tasks/seed.sql from the live tasks.db so `rebuild` is reproducible.

The plan docs are the source of truth for CONTENT; this script keeps the derived index
(seed.sql) byte-faithful to the live DB after `task.py add` / `done` / `block` edits,
so a `python3 plan/tasks/task.py rebuild` produces the exact current graph.

Usage: python3 plan/tasks/dump_seed.py
"""
import os
import sqlite3

DB = os.path.join(os.path.dirname(os.path.abspath(__file__)), "tasks.db")
SEED = os.path.join(os.path.dirname(os.path.abspath(__file__)), "seed.sql")

con = sqlite3.connect(DB)
con.row_factory = sqlite3.Row

# Keep the schema DDL + meta INSERT header verbatim (everything before the tasks INSERT marker).
header = []
with open(SEED) as f:
    for line in f:
        if line.startswith("-- \u2500\u2500 tasks"):
            break
        header.append(line)


def q(v):
    if v is None:
        return "NULL"
    return "'" + str(v).replace("'", "''") + "'"


tasks = con.execute("SELECT * FROM tasks ORDER BY id").fetchall()
deps = con.execute("SELECT task_id, depends_on, reason FROM dependencies ORDER BY task_id, depends_on").fetchall()

out = "".join(header)
out += "-- \u2500\u2500 tasks \u2500\u2500" + "\u2500" * 90 + "\n"
out += ("INSERT OR REPLACE INTO tasks\n"
        " (id, code, title, kind, status, priority, gap_ref, principle_refs, algorithm_ref, doc_refs, file_ref, notes, updated_at) VALUES\n")
rows = []
for t in tasks:
    rows.append(" (" + ",".join([
        str(t["id"]), q(t["code"]), q(t["title"]), q(t["kind"]), q(t["status"]),
        q(t["priority"]), q(t["gap_ref"]), q(t["principle_refs"]), q(t["algorithm_ref"]),
        q(t["doc_refs"]), q(t["file_ref"]), q(t["notes"]), q(t["updated_at"]),
    ]) + ")")
out += ",\n".join(rows) + ";\n"

out += "-- \u2500\u2500 dependencies \u2500\u2500" + "\u2500" * 83 + "\n"
if deps:
    out += ("INSERT OR REPLACE INTO dependencies (task_id, depends_on, reason) VALUES\n")
    out += ",\n".join(" (%d, %d, %s)" % (d["task_id"], d["depends_on"], q(d["reason"])) for d in deps) + ";\n"

with open(SEED, "w") as f:
    f.write(out)

print("wrote %s: %d tasks, %d dependencies" % (SEED, len(tasks), len(deps)))
