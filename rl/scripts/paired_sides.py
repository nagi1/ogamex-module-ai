"""Paired comparison of two sides (same seeds, same world): per seed, mean ln(1 + final score) of the model accounts
(accounts below N, `fleet-model=N`; default 21) and of the rest; B - A judged by `verdict.judge` (NOISE when the CI
crosses zero or the effect is under the MDE). A season counts when it reached its side's last day.

    python scripts/paired_sides.py DIR_A[,DIR_A2] DIR_B[,DIR_B2] [N | STRIDE:OFFSET]   (comma: one side over several seed folders)
"""
import glob
import json
import math
import sys
from pathlib import Path

from verdict import judge


def finals(d: str) -> dict[int, tuple[float, float]]:
    seasons = {}
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
        if last:
            seasons[seed] = last
    end = max((max(r["day"] for r in last.values()) for last in seasons.values()), default=0)
    out = {}
    for seed, last in seasons.items():
        if max(r["day"] for r in last.values()) < end:
            continue  # unfinished season: left out of the pairing
        score = lambda r: math.log1p(max(r["score"]["total"] if isinstance(r["score"], dict) else r["score"], 0))
        model = [score(r) for k, r in last.items() if IN(k)]
        rest = [score(r) for k, r in last.items() if not IN(k)]
        out[seed] = (sum(model) / len(model) if model else float("nan"), sum(rest) / len(rest) if rest else float("nan"))
    return out


ARG = sys.argv[3] if len(sys.argv) > 3 else "21"
# N: accounts below N; STRIDE:OFFSET: accounts with index % STRIDE == OFFSET (champion seats).
IN = (lambda k, s=int(ARG.split(":")[0]), o=int(ARG.split(":")[1]): k % s == o) if ":" in ARG else (lambda k, n=int(ARG): k < n)
a, b = finals(sys.argv[1]), finals(sys.argv[2])
seeds = sorted(set(a) & set(b))
for i, name in ((0, "model accounts"), (1, "other accounts")):
    d = [b[s][i] - a[s][i] for s in seeds if not math.isnan(b[s][i] - a[s][i])]
    print(f"{name}: {judge(d, ' ln')}")
