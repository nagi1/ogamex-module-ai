"""Fleet crashes per season day, from the `battles` files (arm `battles`/`levels`).

    crashes.py DIR [DIR ...] [--floor VALUE]

A crash is an attack whose defender lost parked warships and civil hulls worth at least FLOOR (default 1e7, host base
prices metal + crystal + deuterium; probes, satellites and crawlers left out). Per dir, mean over seasons: crashes per
day, the value lost in them (B), the share won by the attacker, and the attackers' share of crashes held by each
season's top crasher.
"""

import glob
import json
import statistics as st
import sys
from collections import Counter

PRICE = {202: 4e3, 203: 12e3, 204: 4e3, 205: 10e3, 206: 29e3, 207: 60e3, 208: 40e3, 209: 18e3, 211: 90e3,
         213: 125e3, 214: 10e6, 215: 85e3, 218: 160e3, 219: 31e3}


def season(path, floor):
    n = value = won = 0
    by = Counter()
    days = 0
    for line in open(path):
        try:
            b = json.loads(line)
        except json.JSONDecodeError:
            break
        days = max(days, b.get("day", 0))
        if b.get("mission") != "attack" or b.get("mate_target"):
            continue
        lost = sum(PRICE.get(int(k), 0.0) * v for k, v in b["defender_lost"].items())
        if lost < floor:
            continue
        n += 1
        value += lost
        won += b.get("outcome") == "attacker"
        by[b["attacker"]] += 1
    if days < 250:
        return None
    return {"crash_day": n / days, "crash_bn": value / 1e9, "won": won / max(n, 1), "top_share": max(by.values(), default=0) / max(n, 1)}


def main():
    args = sys.argv[1:]
    floor = 1e7
    if "--floor" in args:
        i = args.index("--floor")
        floor = float(args[i + 1])
        args = args[:i] + args[i + 2:]
    for d in args:
        rows = [r for p in sorted(glob.glob(f"{d}/ready-*.jsonl.battles.jsonl")) if (r := season(p, floor))]
        if not rows:
            print(f"{d}: no finished season")
            continue
        out = {k: st.mean(r[k] for r in rows) for k in rows[0]}
        print(f"{d}: n={len(rows)} " + " ".join(f"{k}={v:.3f}" for k, v in out.items()))


if __name__ == "__main__":
    main()
