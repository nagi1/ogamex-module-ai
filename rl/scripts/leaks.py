"""Loss review: where each persona's value leaks over a season (as a share of what it produced), top quarter vs the
rest by final score, across many seasons.

    python scripts/leaks.py DIR [DIR ...]
"""
import glob, json, sys
from collections import defaultdict
import numpy as np

acc = defaultdict(lambda: defaultdict(float))
last = {}
for d in sys.argv[1:]:
    for p in glob.glob(f"{d}/ready-*.jsonl"):
        if ".jsonl." in p: continue
        for line in open(p):
            r = json.loads(line)
            key = (p, r["account"])
            e = r["events_today"]; a = acc[key]
            for k in ("ships_lost_value", "loot_value", "raid_received", "raid_sent", "spy_sent", "expedition", "fights", "save_ok", "save_late"):
                a[k] += e.get(k, 0)
            a["cargo_lost"] += r["cargo_lost"]
            # produced, expedition_in, loot_in/out, wasted and spent_* are the day's own; stock is the moment's.
            for k in ("produced", "expedition_in", "loot_in", "loot_out", "wasted", "spent_build", "spent_research", "spent_units"):
                a[k] += r.get(k, 0.0)
            if r["day"] > 249: a["week_prod"] += r["produced"]; a["week_n"] += 1
            last[key] = r
rows = []
for key, r in last.items():
    if r["inactive"]: continue
    a = acc[key]; prod = max(a["produced"], 1.0); daily = a["week_prod"] / max(a["week_n"], 1)
    rows.append(dict(persona=r["persona"], score=np.log1p(r["score"]["total"]),
        expo=a["expedition_in"] / prod, loot_in=a["loot_in"] / prod, loot_out=a["loot_out"] / prod,
        lost=a["ships_lost_value"] / prod, cargo_lost=a["cargo_lost"] / prod, wasted=a["wasted"] / prod,
        units=a["spent_units"] / prod, research=a["spent_research"] / prod, build=a["spent_build"] / prod,
        stock_days=r["stock"] / max(daily, 1.0),
        idle_h=r["idle_hours"], planets=r["planets"], moons=r["moons"],
        raids_day=a["raid_sent"] / r["day"], raided_day=a["raid_received"] / r["day"], spies_day=a["spy_sent"] / r["day"],
        expos_day=a["expedition"] / r["day"], saves_late=a["save_late"], fights=a["fights"]))
cols = ["expo", "loot_in", "loot_out", "lost", "cargo_lost", "wasted", "build", "research", "units", "stock_days", "idle_h", "planets", "moons",
        "raids_day", "raided_day", "spies_day", "expos_day", "saves_late"]
print(f"{len(rows)} active accounts at season end")
for persona in sorted({r["persona"] for r in rows}):
    g = [r for r in rows if r["persona"] == persona]
    cut = np.quantile([r["score"] for r in g], 0.75)
    top = [r for r in g if r["score"] >= cut]; rest = [r for r in g if r["score"] < cut]
    print(f"\n== {persona}: n {len(g)}, median ln score top {np.median([r['score'] for r in top]):.2f} rest {np.median([r['score'] for r in rest]):.2f}")
    print(f"{'measure':12s} {'top q':>10s} {'rest':>10s}")
    for c in cols:
        print(f"{c:12s} {np.median([r[c] for r in top]):10.3g} {np.median([r[c] for r in rest]):10.3g}")
