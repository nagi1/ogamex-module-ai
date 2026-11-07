"""Paired comparison of two sides (same seeds, same world): per seed, mean ln(1 + final score) of the model accounts
(accounts below N, `fleet-model=N`; default 21) and of the rest; difference B - A with a bootstrap 95% CI over seeds.

    python scripts/paired_sides.py DIR_A[,DIR_A2] DIR_B[,DIR_B2] [N | STRIDE:OFFSET]   (comma: one side over several seed folders)
"""
import glob
import json
import math
import sys
from pathlib import Path

import numpy as np


def finals(d: str) -> dict[int, tuple[float, float]]:
    out = {}
    for p in [p for part in d.split(",") for p in glob.glob(f"{part}/ready-*.jsonl")]:
        if not p.endswith(".jsonl") or ".jsonl." in p:
            continue
        seed = int(Path(p).stem.split("-")[1])
        last = {}
        try:
            for line in open(p):
                r = json.loads(line)
                last[r["account"]] = r
        except json.JSONDecodeError:
            pass  # a season cut by its timeout ends mid-line
        if not last or max(r["day"] for r in last.values()) < 250:
            continue  # unfinished season: left out of the pairing
        model = [math.log1p(max(r["score"]["total"] if isinstance(r["score"], dict) else r["score"], 0)) for k, r in last.items() if IN(k)]
        rest = [math.log1p(max(r["score"]["total"] if isinstance(r["score"], dict) else r["score"], 0)) for k, r in last.items() if not IN(k)]
        out[seed] = (float(np.mean(model)), float(np.mean(rest)))
    return out


ARG = sys.argv[3] if len(sys.argv) > 3 else "21"
# N: accounts below N; STRIDE:OFFSET: accounts with index % STRIDE == OFFSET (champion seats).
IN = (lambda k, s=int(ARG.split(":")[0]), o=int(ARG.split(":")[1]): k % s == o) if ":" in ARG else (lambda k, n=int(ARG): k < n)
a, b = finals(sys.argv[1]), finals(sys.argv[2])
seeds = sorted(set(a) & set(b))
for i, name in ((0, "model accounts"), (1, "other accounts")):
    d = np.array([b[s][i] - a[s][i] for s in seeds])
    boot = np.random.default_rng(0).choice(d, (10000, len(d))).mean(1)
    print(f"{name}: B - A {d.mean():+.3f} ln, up {int((d > 0).sum())}/{len(d)} seeds, 95% CI [{np.quantile(boot, 0.025):+.3f}, {np.quantile(boot, 0.975):+.3f}]")
