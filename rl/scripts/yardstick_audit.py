#!/usr/bin/env python3
"""Audit of the yardstick itself: is the per-cell Turing AUC of hds.py a target any behaviour can reach?

Three checks, all on the same real PUB snapshots real_bands.py reads, but with each player tagged by universe:

1. real vs real: in each cell, one universe's players against the other universes' players. If real
   universes already separate from each other, the per-cell AUC of a season has a floor no behaviour lowers.
2. one feature at a time: for a season, the AUC each single measure reaches in each cell, so the giveaway
   is named directly instead of through a pooled importance.
3. world size: how many ranked players the real universes of each cell have, against our 64.

    .venv-rl/bin/python rl/scripts/yardstick_audit.py ../../storage/rl/ogsim/runs/flow/e79d/ready-*.jsonl
"""
from __future__ import annotations

import argparse
import collections
import statistics
import sys
from pathlib import Path

import numpy as np
from sklearn.ensemble import HistGradientBoostingClassifier
from sklearn.metrics import roc_auc_score
from sklearn.model_selection import StratifiedKFold

sys.path.insert(0, str(Path(__file__).resolve().parent))
from hds import auc_of, day_lines, read_season, score_rows  # noqa: E402
from real_bands import (DEFAULT_SRC, MEASURES, account_measures, age_bucket_of, planets_of,  # noqa: E402
                        players_of, scores_of, universe_of)


def tagged_real(src: Path) -> tuple[dict[str, list[tuple[str, list[float]]]], dict[str, int]]:
    """Per cell, (universe, vector) for every active real player of a speed-8 universe."""
    cells: dict[str, list[tuple[str, list[float]]]] = collections.defaultdict(list)
    sizes: dict[str, int] = {}
    for directory in sorted(src.glob("PUB-*/normalized")):
        universe = universe_of(directory)
        if universe is None or universe["speed"] != 8:
            continue
        age = age_bucket_of(universe["speed_days"] / universe["speed"])
        if age is None:
            continue
        status, allied = players_of(directory)
        scores, ranked = scores_of(directory)
        owned, _ = planets_of(directory)
        name = directory.parent.name
        sizes[name] = ranked
        for band, _, vector in account_measures(directory, universe, status, allied, scores, ranked, owned):
            cells[f"{band}|{age}"].append((name, vector))
    return cells, sizes


def boosted_auc(x: np.ndarray, y: np.ndarray) -> float | None:
    if min(int(y.sum()), int(len(y) - y.sum())) < 10:
        return None
    folds = StratifiedKFold(n_splits=5, shuffle=True, random_state=0)
    model = HistGradientBoostingClassifier(max_iter=200)
    return statistics.mean(roc_auc_score(y[t], model.fit(x[s], y[s]).predict_proba(x[t])[:, 1])
                           for s, t in folds.split(x, y))


def real_vs_real(cells: dict, sizes: dict) -> None:
    print("== 1. real vs real: one universe against the rest of its cell (boosted, 5-fold)")
    for cell in sorted(cells):
        rows = cells[cell]
        by_uni = collections.Counter(u for u, _ in rows)
        unis = [u for u, n in by_uni.items() if n >= 20]
        if len(unis) < 2:
            print(f"{cell:18} n {len(rows):5}  universes {len(by_uni)}  (too few to compare)")
            continue
        aucs = []
        for u in unis:
            x = np.array([v for _, v in rows], dtype=float)
            y = np.array([1 if name == u else 0 for name, _ in rows])
            auc = boosted_auc(x, y)
            if auc is not None:
                aucs.append((auc, by_uni[u]))
        weighted = sum(a * n for a, n in aucs) / sum(n for _, n in aucs)
        ranked = sorted(sizes[u] for u in by_uni)
        print(f"{cell:18} n {len(rows):5}  universes {len(by_uni):2}  share of largest "
              f"{max(by_uni.values()) / len(rows):.2f}  one-vs-rest AUC {weighted:.3f} "
              f"(min {min(a for a, _ in aucs):.2f} max {max(a for a, _ in aucs):.2f})  ranked players p50 "
              f"{ranked[len(ranked) // 2]}")


def single_features(season: dict, real: dict) -> None:
    print("\n== 2. ours vs real, one measure at a time (hds.auc_of on that column alone)")
    print(f"{'cell':18}{'ours':>6}{'real':>6}  all8   " + " ".join(f"{m[:7]:>7}" for m in MEASURES))
    for cell in sorted(season):
        if cell not in real:
            continue
        ours = [[a["vector"][m] for m in MEASURES] for a in season[cell]]
        theirs = [v for _, v in real[cell]]
        whole = auc_of(ours, theirs)
        if whole is None:
            continue
        singles = [auc_of([[o[i]] for o in ours], [[t[i]] for t in theirs]) or 0.5 for i in range(len(MEASURES))]
        print(f"{cell:18}{len(ours):>6}{len(theirs):>6}  {whole:.3f}  " + " ".join(f"{s:7.3f}" for s in singles))


def per_account(season: dict, real: dict) -> None:
    print("\n== 2b. ours vs real with one snapshot per account (its last day in the cell), all 8 measures")
    for cell in sorted(season):
        if cell not in real:
            continue
        last: dict[str, dict] = {}
        for a in season[cell]:
            seed, account, day = a["account"].split("/")
            key = f"{seed}/{account}"
            if key not in last or int(day) > int(last[key]["account"].split("/")[2]):
                last[key] = a
        ours = [[a["vector"][m] for m in MEASURES] for a in last.values()]
        auc = auc_of(ours, [v for _, v in real[cell]])
        if auc is not None:
            print(f"{cell:18} ours {len(ours):5}  AUC {auc:.3f}")


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    parser.add_argument("season", nargs="+", type=Path)
    parser.add_argument("--src", type=Path, default=DEFAULT_SRC)
    args = parser.parse_args()
    real, sizes = tagged_real(args.src)
    real_vs_real(real, sizes)
    season, _ = score_rows(day_lines(read_season(args.season)))
    single_features(season, real)
    per_account(season, real)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
