"""Real-server gap gauges for season dirs (REAL-SERVER-PLAYER-PROFILE.md targets at 128-256 days in brackets).

    reality.py DIR [DIR ...]

Per dir, mean over seasons: active share, inactive accounts over 100k points per active one, raider loot / own production
(days 100-256), top-10% store in days of production, rank-1 share of points (all at day 256 unless noted).
"""

import glob
import json
import statistics as st
import sys
from collections import defaultdict


def season(path):
    rows = defaultdict(dict)
    for line in open(path):
        try:
            r = json.loads(line)
        except json.JSONDecodeError:
            return None
        if "day" in r and "account" in r and "score" in r:
            rows[r["day"]][r["account"]] = r
    if not rows or max(rows) < 256:
        return None
    last = rows[max(rows)]
    out = {}
    for day in (128, 256):
        day_rows = list(rows.get(day, {}).values())
        if not day_rows:
            continue
        active = [r for r in day_rows if not r["inactive"]]
        farms = [r for r in day_rows if r["inactive"] and r["score"]["total"] > 100_000]
        out[f"active{day}"] = len(active) / len(day_rows)
        out[f"farms{day}"] = len(farms) / max(1, len(active))
    loot, made = 0.0, 0.0
    for day, accs in rows.items():
        if day < 100:
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


def main():
    print("dir active128 active256 farms128 farms256 raid_loot_x_prod r1_share r1_r10 top10_store_days spy_day raid_day lost_bn  [real: .27 .27 .42 .42 .5-2 .17 5.5 1-2]")
    for d in sys.argv[1:]:
        seasons = [s for s in (season(p) for p in sorted(glob.glob(f"{d}/ready-*.jsonl"))) if s]
        if not seasons:
            print(d, "no finished seasons")
            continue
        keys = ["active128", "active256", "farms128", "farms256", "raid_loot_x_prod", "r1_share", "r1_r10", "top10_store_days", "spy_day", "raid_day", "lost_bn"]
        print(d.rstrip("/").split("/")[-1], f"n={len(seasons)}", " ".join(f"{st.mean(s.get(k, float('nan')) for s in seasons):.3g}" for k in keys))


if __name__ == "__main__":
    main()
