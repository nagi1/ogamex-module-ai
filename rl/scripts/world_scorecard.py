#!/usr/bin/env python3
"""Objective per-world scorecard from finished late worlds' end.sqlite, and the spread across worlds.

    python3 Modules/AI/rl/scripts/world_scorecard.py storage/rl/late/9507 storage/rl/late/9509 ...
    python3 Modules/AI/rl/scripts/world_scorecard.py --glob 'storage/rl/late/95*' --csv out.csv

One row per world: counts read straight from the database, never from a judge. The summary rows give mean, standard
deviation and coefficient of variation per metric across the worlds shown, which is the noise a single world's change
has to beat: an effect smaller than that spread cannot be read from one world.
"""
from __future__ import annotations

import argparse
import glob
import json
import re
import sqlite3
import statistics
import sys
from pathlib import Path

GREETING = re.compile(r"\b(hi|hello|hey|welcome|greetings|o/)\b", re.I)
METRICS = [
    "accounts", "battles", "attacker_loss_share", "loot", "attack_pairs",
    "wars", "pacts", "truces_agreed", "truces_broken", "messages", "distinct_share", "greeting_share",
    "acs_attacks", "recycles", "missiles", "expeditions", "moon_destroys", "growth_median", "errors",
]


def one(db: sqlite3.Connection, sql: str, default=0):
    row = db.execute(sql).fetchone()
    return default if row is None or row[0] is None else row[0]


def score(world: Path) -> dict | None:
    end = world / "end.sqlite"
    if not end.exists():
        return None
    db = sqlite3.connect(f"file:{end}?mode=ro", uri=True)
    attacker_loss = defender_loss = loot = battles = 0
    pairs: set[tuple[int, int]] = set()
    for attacker, defender, loot_json in db.execute("select attacker, defender, loot from battle_reports"):
        try:
            a, d, l = json.loads(attacker), json.loads(defender), json.loads(loot_json or "{}")
        except (TypeError, ValueError):
            continue
        # A probe-only visit is not a battle; the espionage report is the mission, not a fight.
        if set((a.get("units") or {}).keys()) <= {"espionage_probe"}:
            continue
        battles += 1
        attacker_loss += float(a.get("resource_loss") or 0)
        defender_loss += float(d.get("resource_loss") or 0)
        loot += sum(float(l.get(k) or 0) for k in ("metal", "crystal", "deuterium"))
        pairs.add((int(a.get("player_id") or 0), int(d.get("player_id") or 0)))

    messages = [row[0] or "" for row in db.execute("select message from chat_messages")]
    growth = sorted(row[0] for row in db.execute("select general from highscores where player_id in (select player_id from ai_profiles where enabled=1)"))
    errors = 0
    log = world / "sim.log"
    if log.exists():
        found = re.findall(r"errors (\d+)", log.read_text(errors="ignore"))
        errors = sum(int(n) for n in found[-1:])
    missions = dict(db.execute("select mission_type, count(*) from fleet_missions group by 1").fetchall())
    return {
        "world": world.name,
        "accounts": one(db, "select count(*) from ai_profiles where enabled=1"),
        "battles": battles,
        "attacker_loss_share": round(attacker_loss / (attacker_loss + defender_loss), 3) if attacker_loss + defender_loss else 0.0,
        "loot": int(loot),
        "attack_pairs": len(pairs),
        "wars": one(db, "select count(*) from ai_alliance_relations where stance=1"),
        "pacts": one(db, "select count(*) from ai_alliance_relations where stance=2"),
        "truces_agreed": one(db, "select count(*) from ai_social_exchanges where type=6 and response=1"),
        "truces_broken": one(db, "select count(*) from ai_commitments where state=4"),
        "messages": len(messages),
        "distinct_share": round(len(set(messages)) / len(messages), 3) if messages else 0.0,
        "greeting_share": round(sum(1 for m in messages if GREETING.search(m)) / len(messages), 3) if messages else 0.0,
        "acs_attacks": missions.get(2, 0),
        "recycles": missions.get(8, 0),
        "missiles": missions.get(10, 0),
        "expeditions": missions.get(15, 0),
        "moon_destroys": missions.get(9, 0),
        "growth_median": int(statistics.median(growth)) if growth else 0,
        "errors": errors,
    }


def main() -> None:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("worlds", nargs="*")
    ap.add_argument("--glob")
    ap.add_argument("--csv")
    args = ap.parse_args()
    paths = [Path(p) for p in args.worlds] + [Path(p) for p in sorted(glob.glob(args.glob))] if args.glob else [Path(p) for p in args.worlds]
    rows = [r for r in (score(p) for p in paths) if r]
    if not rows:
        sys.exit("no finished worlds")
    widths = {m: max(len(m), 9) for m in METRICS}
    print("world".ljust(7) + "".join(m[:11].rjust(12) for m in METRICS))
    for r in rows:
        print(r["world"].ljust(7) + "".join(str(r[m]).rjust(12) for m in METRICS))
    if len(rows) > 1:
        for label, fn in (("mean", statistics.mean), ("sd", statistics.pstdev)):
            print(label.ljust(7) + "".join(f"{fn([float(r[m]) for r in rows]):.2f}".rjust(12) for m in METRICS))
        print("cv".ljust(7) + "".join(
            (f"{statistics.pstdev([float(r[m]) for r in rows]) / statistics.mean([float(r[m]) for r in rows]):.2f}"
             if statistics.mean([float(r[m]) for r in rows]) else "-").rjust(12) for m in METRICS))
    if args.csv:
        import csv
        with open(args.csv, "w", newline="") as handle:
            writer = csv.DictWriter(handle, fieldnames=["world", *METRICS])
            writer.writeheader()
            writer.writerows(rows)


if __name__ == "__main__":
    main()
