#!/usr/bin/env python3
"""A readable sample of decisions for a human to judge against real OGame knowledge.

    python3 Modules/AI/rl/scripts/review_sheet.py 'storage/rl/bc/choices-*.jsonl' [--n 40] [--out storage/rl/review.md]

Takes random 'hard' choices (the teacher did not pick the first legal row: the interesting ones) plus a few ordinary
ones, and prints, per decision, the account's stage and every legal candidate with its price and payback, the teacher's
pick marked. storage/rl/objects.json maps object ids to names (dumped from the host catalogue).
"""
from __future__ import annotations

import argparse
import glob
import json
import math
import random
from pathlib import Path

ROOT = Path(__file__).resolve().parents[4]
START = 1791158400


def ex(v: float) -> float:
    """Undo the encoder's (signed) log1p."""
    return math.copysign(math.expm1(abs(v)), v)


def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("pattern"); ap.add_argument("--n", type=int, default=40); ap.add_argument("--out", default="storage/rl/review.md")
    args = ap.parse_args()
    names = {int(k): v for k, v in json.loads((ROOT / "storage/rl/objects.json").read_text()).items()}
    files = sorted(glob.glob(args.pattern))
    schema = json.loads(Path(files[0] + ".schema.json").read_text())
    c = {n: i for i, n in enumerate(schema["candidate"])}
    s = {n: i for i, n in enumerate(schema["state"])}
    hard, easy = [], []
    for f in files:
        for line in open(f):
            d = json.loads(line)
            legal = [i for i, x in enumerate(d["legal"]) if x]
            if len(legal) < 3:
                continue
            first = next((i for i in legal if i != 0), 0)
            (hard if d["teacher"] != first else easy).append(line)
    rng = random.Random(7)
    picks = rng.sample(hard, min(int(args.n * .75), len(hard))) + rng.sample(easy, min(args.n // 4, len(easy)))
    out = [f"# Decisions to review ({len(picks)})\n", "Judge each teacher pick (★) as an experienced OGame player would. ★ = teacher's pick, → = what was actually recorded when different (epsilon).\n"]
    for k, line in enumerate(picks, 1):
        d = json.loads(line)
        st = d["state"]
        out.append(f"\n## {k}. {d['archetype']} · day {(d['t'] - START) / 86400:.1f} · value {d['value']:.0f} · {d['kind']} · planet {d['planet']}")
        out.append(f"account: {round(ex(st[s['acct_planets']]))} planet(s), registered {ex(st[s['acct_age_days']]):.0f} d ago, planet stock M/C/D "
                   f"{ex(st[s['pl_metal']]):,.0f}/{ex(st[s['pl_crystal']]):,.0f}/{ex(st[s['pl_deuterium']]):,.0f}, production/h "
                   f"{ex(st[s['pl_metal_ph']]):,.0f}/{ex(st[s['pl_crystal_ph']]):,.0f}/{ex(st[s['pl_deuterium_ph']]):,.0f}, energy used/made "
                   f"{ex(st[s['pl_energy_used']]):,.0f}/{ex(st[s['pl_energy_max']]):,.0f}, factor {st[s['pl_factor']]:.2f}, queue {round(st[s['pl_queue_length']] * 5)}")
        for i, cand in enumerate(d["cands"]):
            if not d["legal"][i]:
                continue
            obj = "wait" if i == 0 else f"{names.get(d['objects'][i], d['objects'][i])} -> L{round(cand[c['level']] * 40) + 1}"
            mark = "★" if i == d["teacher"] else ("→" if i == d["chosen"] else " ")
            payback = ex(cand[c["payback_hours"]])
            pay = "no gain" if payback >= 9999 else f"{payback:,.1f}h"
            cost = ex(cand[c["cost_metal"]]) + ex(cand[c["cost_crystal"]]) + ex(cand[c["cost_deuterium"]])
            out.append(f"  {mark} {obj:36} cost {cost:>12,.0f}  payback {pay:>10}  gain/h {ex(cand[c['gain_ph']]):>8,.0f}  energy {ex(cand[c['energy_delta']]):>6,.0f}  "
                       f"build {ex(cand[c['build_hours']]):>6.2f}h  affordable in {ex(cand[c['eta_hours']]):.2f}h")
    Path(args.out).write_text("\n".join(out) + "\n")
    print(f"{len(picks)} decisions -> {args.out}")


if __name__ == "__main__":
    main()
