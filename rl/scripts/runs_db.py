"""Run history: every simulation, training and test the pipeline performs, with how long it took, in storage/rl/runs.sqlite.

The collector calls `sync()` on every tick: finished runs are read from their logs (idempotent upsert, so the history
backfills itself), running ones are recorded from the live activity list. `python3 rl/scripts/runs_db.py` prints the report.
"""
from __future__ import annotations

import json
import re
import sqlite3
import statistics
import sys
import time
from pathlib import Path

RL = Path(__file__).resolve().parents[4] / "storage" / "rl"
DB = RL / "runs.sqlite"
PLAYED = re.compile(r"^SIM: ([\d.]+) h played in (\d+) s .*?(\d+) error\(s\)", re.M)
HEADER = re.compile(r"\(([\d.]+) h\), (\d+) account")
KINDS = {"data": "Practice data universe", "eval": "Closed-loop test world", "late": "Late-game test", "long": "Long start-to-end game", "train": "GPU training"}


def digits(text: str) -> str:
    return re.sub(r"\D", "", text)


def connect() -> sqlite3.Connection:
    db = sqlite3.connect(DB, timeout=30)
    db.execute("""create table if not exists runs (
        kind text not null, key text not null, label text, started_at integer, finished_at integer, wall_s integer,
        sim_hours real, accounts integer, errors integer, status text not null, outcome text,
        primary key (kind, key))""")
    db.execute("create index if not exists runs_kind_finished on runs (kind, finished_at)")
    return db


def upsert(db: sqlite3.Connection, kind: str, key: str, **row) -> None:
    row.setdefault("label", f"{KINDS[kind]} {key}")
    cols = ["kind", "key", *row]
    sql = f"insert into runs ({','.join(cols)}) values ({','.join('?' * len(cols))}) on conflict(kind, key) do update set " + ",".join(f"{c}=excluded.{c}" for c in row)
    db.execute(sql, [kind, key, *row.values()])


def sim_row(log: Path, running_within: int = 1800) -> dict | None:
    """One row from a sim log: finished when it has its 'played in N s' line, running while it is still being written."""
    try:
        text, mtime = log.read_text(errors="replace"), log.stat().st_mtime
    except OSError:
        return None
    header, played = HEADER.search(text), list(PLAYED.finditer(text))
    if not header:
        return None
    if played:
        wall = sum(int(p.group(2)) for p in played)
        return {"started_at": int(mtime - wall), "finished_at": int(mtime), "wall_s": wall, "sim_hours": float(header.group(1)),
                "accounts": int(header.group(2)), "errors": int(played[-1].group(3)), "status": "finished"}
    alive = time.time() - mtime < running_within
    return {"started_at": None, "finished_at": None, "wall_s": None, "sim_hours": float(header.group(1)), "accounts": int(header.group(2)),
            "errors": 0, "status": "running" if alive else "stopped"}


def sync(live: list[dict] | None = None, now: float | None = None, act_start: dict | None = None) -> None:
    now = now or time.time()
    db = connect()
    for out in RL.glob("bc*"):
        if not out.is_dir():
            continue
        for log in out.glob("sim-*.log"):
            row = sim_row(log)
            if row and row["status"] == "finished":
                upsert(db, "data", f"{out.name}/{digits(log.stem)}", **row)
    for out in RL.glob("eval*"):
        for side in ("teacher", "policy"):
            for log in (out / side).glob("sim-*.log") if (out / side).exists() else []:
                row = sim_row(log)
                if row and row["status"] == "finished":
                    upsert(db, "eval", f"{out.name}/{side}/{digits(log.stem)}", **row)
    for folder in (RL / "late").glob("[0-9]*"):
        row = sim_row(folder / "sim.log")
        reach = folder / "reach.txt"
        if not row:
            continue
        if row["status"] == "finished" and reach.exists():
            text = reach.read_text()
            missing = re.search(r"^MISSING: (.*)$", text, re.M)
            missions = re.search(r"^missions: (.*)$", text, re.M)
            row["outcome"] = json.dumps({"missions": missions.group(1) if missions else "", "missing": missing.group(1).split() if missing else []})
        label = folder / "label"
        if label.exists():
            row["label"] = f"{label.read_text().strip()} #{folder.name}"
        upsert(db, "late", folder.name, **row)
    for log in (RL / "long").glob("sim-*.log"):
        n = digits(log.stem)
        row = sim_row(log)
        if row:
            row["status"] = "finished" if (RL / "long" / f"u{n}.out.sqlite").exists() else row["status"]
            upsert(db, "long", n, **row)
    for log in list(RL.glob("bc-*.log")) + list(RL.glob("bc-*/train.log")):
        name = log.stem if log.name != "train.log" else log.parent.name
        epochs = []
        for line in log.read_text(errors="replace").splitlines():
            if line.startswith("{") and '"epoch"' in line:
                try:
                    epochs.append(json.loads(line))
                except ValueError:
                    pass
        if not epochs:
            continue
        wall = int(sum(e.get("seconds", 0) for e in epochs))
        last = epochs[-1]
        alive = now - log.stat().st_mtime < 900
        upsert(db, "train", name, started_at=int(log.stat().st_mtime - wall), finished_at=None if alive else int(log.stat().st_mtime), wall_s=wall,
               status="running" if alive else "finished", outcome=json.dumps({"epochs": len(epochs), "top1_3plus": last.get("val_top1_3plus"), "mrr": last.get("val_mrr")}))
    db.commit()
    db.close()


def stats() -> dict:
    """Per-kind timing statistics and the most recent finished runs, for the page and the report."""
    db = connect()
    out = {"kinds": [], "recent": []}
    for kind in KINDS:
        rows = db.execute("select wall_s, sim_hours from runs where kind=? and status='finished' and wall_s > 0", (kind,)).fetchall()
        if not rows:
            continue
        walls = [r[0] for r in rows]
        speed = [r[1] * 3600 / r[0] for r in rows if r[1]]
        out["kinds"].append({"kind": kind, "name": KINDS[kind], "runs": len(rows), "median_s": int(statistics.median(walls)), "mean_s": int(statistics.mean(walls)),
                             "max_s": max(walls), "min_s": min(walls), "speed": round(statistics.median(speed)) if speed else None})
    for r in db.execute("select kind, label, finished_at, wall_s, sim_hours, accounts, errors, outcome from runs where status='finished' and wall_s > 0 order by finished_at desc limit 14"):
        out["recent"].append({"kind": r[0], "label": r[1], "finished_at": r[2], "wall_s": r[3], "sim_hours": r[4], "accounts": r[5], "errors": r[6]})
    db.close()
    return out


def human(seconds: float) -> str:
    return f"{seconds:.0f}s" if seconds < 90 else f"{seconds / 60:.0f} min" if seconds < 5400 else f"{seconds / 3600:.1f} h"


def report() -> None:
    sync()
    data = stats()
    print(f"{'kind':26} {'runs':>5} {'median':>9} {'mean':>9} {'longest':>9} {'x real time':>12}")
    for k in data["kinds"]:
        print(f"{k['name']:26} {k['runs']:>5} {human(k['median_s']):>9} {human(k['mean_s']):>9} {human(k['max_s']):>9} {('x' + str(k['speed'])) if k['speed'] else '':>12}")
    print("\nrecent runs:")
    for r in data["recent"]:
        print(f"  {time.strftime('%m-%d %H:%M', time.localtime(r['finished_at']))}  {r['label']:38} {human(r['wall_s']):>8}  {r['sim_hours'] or 0:g} sim h  {r['errors'] or 0} errors")


if __name__ == "__main__":
    report() if len(sys.argv) < 2 else sys.exit(f"usage: {sys.argv[0]}")
