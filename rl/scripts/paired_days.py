#!/usr/bin/env python3
"""Two runs of the same seeds compared account by account at chosen days: the mean paired log-ratio of points with a
95% bootstrap CI over seeds, and the share of accounts ahead. Accounts inactive in either run are left out.
`--seats champion` compares only the accounts marked champion in A (the side score against the champion).

    .venv-rl/bin/python rl/scripts/paired_days.py DIR_A DIR_B [--days 10 30 365] [--seats champion]
"""
from __future__ import annotations

import argparse
import glob
import json
import lzma
import math
import os

import numpy as np


def lines(path: str):
    opener = lzma.open if path.endswith(".xz") else open
    with opener(path, "rt") as handle:
        for line in handle:
            try:
                yield json.loads(line)
            except json.JSONDecodeError:
                return


def run(directory: str) -> dict[int, dict[tuple[int, int], dict]]:
    """Per seed, (day, account) -> line."""
    out: dict[int, dict] = {}
    for path in glob.glob(os.path.join(directory, "ready-*.jsonl*")):
        seed = int(os.path.basename(path).split("-")[1].split(".")[0])
        out[seed] = {(r["day"], r["account"]): r for r in lines(path) if "score" in r}
    return out


def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("a")
    ap.add_argument("b")
    ap.add_argument("--days", type=int, nargs="+", default=[10, 30, 365])
    ap.add_argument("--seats", choices=["all", "champion"], default="all")
    args = ap.parse_args()
    a, b = run(args.a), run(args.b)
    seeds = sorted(set(a) & set(b))
    rng = np.random.default_rng(0)
    for day in args.days:
        per_seed = []
        for s in seeds:
            ratios = []
            for (d, acc), ra in a[s].items():
                rb = b[s].get((d, acc))
                if d != day or rb is None or ra.get("inactive") or rb.get("inactive"):
                    continue
                if args.seats == "champion" and not ra.get("champion"):
                    continue
                ratios.append(math.log(max(ra["score"]["total"], 1) / max(rb["score"]["total"], 1)))
            if ratios:
                per_seed.append(ratios)
        if not per_seed:
            continue
        means = np.array([np.mean(r) for r in per_seed])
        boot = [rng.choice(means, len(means)).mean() for _ in range(2000)]
        ahead = np.mean([x > 0 for r in per_seed for x in r])
        print(f"day {day:3d}: A vs B mean log-ratio {means.mean():+.4f} (x{math.exp(means.mean()):.3f}) "
              f"CI {np.percentile(boot, 2.5):+.4f}..{np.percentile(boot, 97.5):+.4f}, seeds up {int((means > 0).sum())}/{len(means)}, "
              f"accounts ahead {ahead:.0%} of {sum(len(r) for r in per_seed)}")


if __name__ == "__main__":
    main()
