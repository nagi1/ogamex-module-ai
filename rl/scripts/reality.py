"""Real-server gap gauges for season dirs (REAL-SERVER-PLAYER-PROFILE.md targets at 128-256 days in brackets).

    reality.py DIR [DIR ...]
    reality.py --paired DIR_A DIR_B      (per gauge, B - A over the paired seeds, judged by verdict.judge: NOISE or a number)

Per dir, mean over seasons: active share, inactive accounts over 100k points per active one, raider loot / own production
(from day 100/256 of the season on), top-10% store in days of production, rank-1 share of points (at the last day unless
noted; mid is half the season). A season counts when it reached its side's last day (256 for the long world, 60 for the
reference regression).
"""

import glob
import json
import statistics as st
import sys
from collections import defaultdict
from pathlib import Path

from verdict import judge


def season(path):
    rows = defaultdict(dict)
    for line in open(path):
        try:
            r = json.loads(line)
        except json.JSONDecodeError:
            return None
        if "day" in r and "account" in r and "score" in r:
            rows[r["day"]][r["account"]] = r
    if not rows:
        return None
    end = max(rows)
    last = rows[end]
    out = {"end": end}
    for name, day in (("mid", end // 2), ("end", end)):
        day_rows = list(rows.get(day, {}).values())
        if not day_rows:
            continue
        active = [r for r in day_rows if not r["inactive"]]
        farms = [r for r in day_rows if r["inactive"] and r["score"]["total"] > 100_000]
        out[f"active_{name}"] = len(active) / len(day_rows)
        out[f"farms_{name}"] = len(farms) / max(1, len(active))
    loot, made = 0.0, 0.0
    for day, accs in rows.items():
        if day < end * 100 // 256:
            continue
        for r in accs.values():
            if r["persona"] == "raider" and not r["inactive"]:
                loot += r["loot_in"]
                made += r["produced"]
    out["raid_loot_x_prod"] = loot / max(made, 1.0)
    # War gauges: per active account-day, over the whole season.
    spies = raids = lost = act = 0
    for accs in rows.values():
        for r in accs.values():
            if r["inactive"]:
                continue
            e = r.get("events_today") or {}
            act += 1
            spies += e.get("spy_sent", 0)
            raids += e.get("raid_sent", 0)
            lost += e.get("ships_lost_value", 0)
    out["spy_day"] = spies / max(act, 1)
    out["raid_day"] = raids / max(act, 1)
    out["lost_bn"] = lost / 1e9
    ranked = sorted(last.values(), key=lambda r: -r["score"]["total"])
    total = sum(r["score"]["total"] for r in ranked)
    out["r1_share"] = ranked[0]["score"]["total"] / max(total, 1)
    out["r1_r10"] = ranked[0]["score"]["total"] / max(ranked[9]["score"]["total"], 1)
    top = ranked[: max(1, len(ranked) // 10)]
    week = [rows[d] for d in range(max(rows) - 6, max(rows) + 1) if d in rows]
    days = []
    for r in top:
        prod = st.mean(w[r["account"]]["produced"] for w in week if r["account"] in w)
        days.append(r["stock"] / max(prod, 1.0))
    out["top10_store_days"] = st.median(days)
    return out


KEYS = ["active_mid", "active_end", "farms_mid", "farms_end", "raid_loot_x_prod", "r1_share", "r1_r10", "top10_store_days", "spy_day", "raid_day", "lost_bn"]


def side(d):
    """{seed: gauges} of the dir's seasons that reached the dir's last day."""
    seasons = {int(Path(p).stem.split("-")[1]): season(p) for p in sorted(glob.glob(f"{d}/ready-*.jsonl")) if ".jsonl." not in p}
    seasons = {k: v for k, v in seasons.items() if v}
    end = max((v["end"] for v in seasons.values()), default=0)
    return {k: v for k, v in seasons.items() if v["end"] == end}


def main():
    if sys.argv[1] == "--paired":
        a, b = side(sys.argv[2]), side(sys.argv[3])
        seeds = sorted(set(a) & set(b))
        for k in KEYS:
            d = [b[s][k] - a[s][k] for s in seeds if k in a[s] and k in b[s]]
            print(f"{k:18s} {judge(d)}")
        return
    print("dir n end " + " ".join(KEYS) + "  [real: .27 .27 .42 .42 .5-2 .17 5.5 1-2]")
    for d in sys.argv[1:]:
        seasons = list(side(d).values())
        if not seasons:
            print(d, "no finished seasons")
            continue
        print(d.rstrip("/").split("/")[-1], f"n={len(seasons)}", f"day={seasons[0]['end']}", " ".join(f"{st.mean(s.get(k, float('nan')) for s in seasons):.3g}" for k in KEYS))


if __name__ == "__main__":
    main()
