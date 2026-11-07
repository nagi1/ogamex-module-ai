#!/usr/bin/env python3
"""HDS and the Turing test: how far our accounts are from looking like real ones.

North-star plan N4 (plan/rl/NORTH-STAR-PLAN.md §6). Reads one or more season files (§9.1) and
`real-bands.json` (§9.2), and prints the two numbers of §4:

* **HDS**, the in-band share: of every (account, measure) pair, how many sit inside the real p10-p90 of
  their cell (rank band x speed-days bucket). A real account scores about 0.80 by construction.
  Target ≥ 0.75.
* **Turing AUC**: in the matched cell, can a classifier tell our accounts from the real sample? 0.5 is
  indistinguishable. Target ≤ 0.60. The measures with the largest permutation importance are *what gives
  us away*, and they order the planner's next rows.

Plus the hard-constraint violations (§4), which must be 0: a week whose logins touch 18 or more hours of the
day (the host's bot signal, `ServerAdministrationController::getBotActivitySignals`, flags fleet departures over
18 distinct hours in 7 days; logins are a stricter proxy since every departure happens at one), an active day with no dark stretch of 6 h (the host flags an account that never sleeps; ESCALATIONS
26 Oct, item 5), two actions less than 10 s apart.

    .venv-rl/bin/python rl/scripts/hds.py season-1.jsonl season-2.jsonl season-3.jsonl
    .venv-rl/bin/python rl/scripts/hds.py --self-test      # proves the yardstick before it judges

The self-test splits the real sample in half and scores one half as "ours": HDS must come out near 0.80
and the AUC near 0.5, or the measures leak.

`--war-cells` adds the war cells of war_cells.py (attack odds, attacker/defender loss ratio, attack-fleet hull roles by
value tier, satellites and defence per planet; real bands from the OGMEM battle archive), printed in their own section
and saved under `war`. They never enter HDS, the AUC or the verdict, so benches with and without the flag compare.
With `--self-test` the flag also runs their own leave-one-out self-test.
"""
from __future__ import annotations

import argparse
import collections
import json
import lzma
import statistics
import sys
from pathlib import Path

import numpy as np
from sklearn.ensemble import HistGradientBoostingClassifier
from sklearn.inspection import permutation_importance
from sklearn.linear_model import LogisticRegression
from sklearn.metrics import roc_auc_score
from sklearn.model_selection import GroupKFold, StratifiedKFold

sys.path.insert(0, str(Path(__file__).resolve().parent))
from real_bands import MEASURES, MIN_CELL, age_bucket_of, band_of, bucket_of  # noqa: E402  (same file)
from census import print_health  # noqa: E402
import war_cells  # noqa: E402

DEFAULT_BANDS = Path(__file__).resolve().parents[4] / "storage/rl/ogsim/real-bands.json"
GROWTH_EVERY = 10          # the growth measure is read every tenth day (§4)
WEEK = 7                   # the hard constraints are weekly
MIN_HOURS_TOUCHED = 18
MAX_DARK_HOURS = 6.0
MIN_GAP_SECONDS = 10.0
# §4's three hard constraints, as the planner settled them (ESCALATIONS.md, 26 Oct, item 5): every calendar day an
# account plays holds a dark stretch of at least 6 h; a day without one is a violation.
GATED = ("login_hours_18_or_more", "action_under_10s", "day_without_6h_dark")
HDS_TARGET, AUC_TARGET = 0.75, 0.60
# A season whose scores are the simulator's stub may not be compared against real totals: a made-up split
# would read as a distance that is not there. Only the counts a player can see are scored.
COUNTS = ["planets", "moons", "galaxies", "alliance"]


def read_season(paths: list[Path]) -> list[dict]:
    rows = []
    for path in paths:
        # Scored seasons are kept xz-compressed.
        with (lzma.open(path, "rt", encoding="utf-8") if path.suffix == ".xz" else path.open(encoding="utf-8")) as handle:
            for line in handle:
                line = line.strip()
                if line:
                    rows.append(json.loads(line))
    return rows


def day_lines(rows: list[dict]) -> list[dict]:
    """The snapshots we score: every account's last day, and every tenth day for growth."""
    last = {}
    for row in rows:
        key = (row["seed"], row["account"])
        if key not in last or row["day"] > last[key]["day"]:
            last[key] = row
    out = {(row["seed"], row["account"], row["day"]): row for row in rows if row["day"] % GROWTH_EVERY == 0}
    for row in last.values():
        out.setdefault((row["seed"], row["account"], row["day"]), row)
    return list(out.values())


def measures_of(row: dict, world_size: int, rank: int) -> tuple[str, dict[str, float]]:
    """The §4 measure vector and the cell it belongs to."""
    speed_days = row["day"] * row["world_speed"]
    score = row["score"]
    total = score["total"] or 0.0
    share = (lambda part: part / total) if total > 0 else (lambda part: 0.0)
    vector = {
        "growth": total / speed_days if speed_days > 0 else 0.0,
        "eco_share": share(score["economy"]),
        "res_share": share(score["research"]),
        "mil_share": share(score["military"]),
        "planets": float(row["planets"]),
        "moons": float(row["moons"]),
        "galaxies": float(row["galaxies"]),
        "alliance": 1.0 if row["alliance"] else 0.0,
    }
    # A season runs at speed 8, so its line is placed by its own age in days against universes of that age.
    # Days 15-63 have no published speed-8 universe at all (row 18): they are listed and not scored.
    bucket = bucket_of(speed_days)
    if row["world_speed"] == 8:
        bucket = age_bucket_of(float(row["day"])) or "no-age-15-63"
    return f"{band_of(rank / max(world_size, 1))}|{bucket}", vector


def score_rows(rows: list[dict]) -> tuple[dict[str, list[dict]], list[str]]:
    """Group the scored rows by cell, with a rank band taken from the account's own world."""
    by_world: dict[tuple, list[dict]] = collections.defaultdict(list)
    for row in rows:
        by_world[(row["seed"], row["day"])].append(row)
    cells: dict[str, list[dict]] = collections.defaultdict(list)
    for day_rows in by_world.values():
        ordered = sorted(day_rows, key=lambda r: -r["score"]["total"])
        size = len(ordered)
        for rank, row in enumerate(ordered, start=1):
            # As real_bands.py: an inactive account holds its rank but is never in a band's sample.
            if row["inactive"]:
                continue
            cell, vector = measures_of(row, size, rank)
            cells[cell].append({"account": f'{row["seed"]}/{row["account"]}/{row["day"]}', "vector": vector,
                                "seed": row["seed"], "persona": row.get("persona", "?")})
    return cells, [c for c in cells if any(r["vector"]["growth"] > 0 for r in cells[c])]


def violations(rows: list[dict]) -> dict[str, int]:
    """The §4 hard constraints, counted over every account's own week."""
    by_account: dict[tuple, list[dict]] = collections.defaultdict(list)
    for row in rows:
        by_account[(row["seed"], row["account"])].append(row)
    out = collections.Counter()
    for days in by_account.values():
        # An account that has quit cannot break a login rhythm: its weeks are simply empty. Only the days it
        # was still playing are held to the constraints.
        days = sorted((d for d in days if not d["inactive"]), key=lambda r: r["day"])
        for week in range(0, len(days) - WEEK + 1, WEEK):
            chunk = days[week:week + WEEK]
            # logins_today holds seconds within its own day: offset by the day or a week reads as one long
            # backwards jump between days and every night looks like a missed constraint.
            stamps = sorted((day["day"] - 1) * 86_400 + s for day in chunk for s in day["logins_today"])
            # The host flags spread, not its absence: a human who sleeps touches fewer hours.
            if len({int(s % 86_400 // 3600) for s in stamps}) >= MIN_HOURS_TOUCHED:
                out["login_hours_18_or_more"] += 1
            gaps = [b - a for a, b in zip(stamps, stamps[1:])]
            out["action_under_10s"] += sum(1 for gap in gaps if gap < MIN_GAP_SECONDS)
        out["day_without_6h_dark"] += days_without_dark_stretch(days)
    return dict(out)


def days_without_dark_stretch(days: list[dict]) -> int:
    """Active days touched by no login-free stretch of MAX_DARK_HOURS. A night is one stretch even when it crosses
    midnight (21:00-04:00 is 7 h), so stretches run over the account's whole timeline and count for every day they
    touch; a day with no login at all is dark throughout."""
    stamps = sorted((day["day"] - 1) * 86_400 + s for day in days for s in day["logins_today"])
    if not days:
        return 0
    edges = [(days[0]["day"] - 1) * 86_400, *stamps, days[-1]["day"] * 86_400]
    dark: set[int] = set()
    for a, b in zip(edges, edges[1:]):
        if b - a >= MAX_DARK_HOURS * 3600:
            dark.update(range(int(a // 86_400) + 1, int((b - 1e-9) // 86_400) + 2))
    return sum(1 for day in days if day["day"] not in dark)


def real_vectors(bands: dict, cell: str) -> list[list[float]]:
    return bands["cells"].get(cell, {}).get("sample", [])


def auc_of(ours: list[list[float]], real: list[list[float]]) -> float | None:
    """Can a classifier separate these two sets? Out of fold, the larger of a linear and a boosted model.

    Scored per fold and averaged: fitting and scoring on the same rows reports how well a model can
    memorise, not whether the two sets differ.
    """
    if len(ours) < 16 or len(real) < 16:
        return None
    x = np.array(ours + real, dtype=float)
    y = np.array([1] * len(ours) + [0] * len(real))
    floor = np.nanmin(x, axis=0)
    span = np.where(np.ptp(x, axis=0) == 0, 1.0, np.ptp(x, axis=0))
    x = (x - floor) / span
    folds = StratifiedKFold(n_splits=5, shuffle=True, random_state=0)
    best = None
    for model in (LogisticRegression(max_iter=1000), HistGradientBoostingClassifier(max_iter=200)):
        try:
            scores = [roc_auc_score(y[test], model.fit(x[train], y[train]).predict_proba(x[test])[:, 1])
                      for train, test in folds.split(x, y)]
        except ValueError:
            continue
        best = max(best or 0.0, statistics.mean(scores))
    return best


def giveaways(cells: dict[str, list[dict]], bands: dict, measures: list[str]) -> tuple[list[tuple[str, float]], float]:
    """Permutation importance of a pooled boosted model, on the features standardised inside their cell."""
    rows_x, rows_y, groups = [], [], []
    for cell, accounts in cells.items():
        real = real_vectors(bands, cell)
        if len(real) < 8 or len(accounts) < 8:
            continue
        reference = np.array([[v[MEASURES.index(m)] for m in measures] for v in real], dtype=float)
        centre, spread = reference.mean(0), np.where(reference.std(0) == 0, 1.0, reference.std(0))
        for account in accounts:
            rows_x.append((np.array([account["vector"][m] for m in measures]) - centre) / spread)
            rows_y.append(1)
            groups.append(cell)
        for vector in reference:
            rows_x.append((vector - centre) / spread)
            rows_y.append(0)
            groups.append(cell)
    x, y = np.array(rows_x), np.array(rows_y)
    folds = min(5, len(set(groups)))
    if len(set(rows_y)) < 2 or folds < 2:
        return [], 0.0
    # Early stopping draws a random validation split: unseeded, identical seasons read 0.749 to 0.778. Five seeds, averaged.
    aucs = []
    for seed in range(5):
        for train, test in GroupKFold(n_splits=folds).split(x, y, groups):
            model = HistGradientBoostingClassifier(max_iter=200, random_state=seed).fit(x[train], y[train])
            aucs.append(roc_auc_score(y[test], model.predict_proba(x[test])[:, 1]))
    model = HistGradientBoostingClassifier(max_iter=200, random_state=0).fit(x, y)
    importance = permutation_importance(model, x, y, n_repeats=5, random_state=0)
    ranked = sorted(zip(measures, importance.importances_mean), key=lambda pair: -pair[1])
    return ranked, statistics.mean(aucs)


def report(cells: dict[str, list[dict]], bands: dict, label: str, save: Path | None,
           measures: list[str] | None = None) -> dict:
    measures = measures or MEASURES
    pairs = inside = 0
    per_measure = collections.Counter()
    per_measure_n = collections.Counter()
    per_cell_auc, per_cell_weight = [], []
    print(f"\n== {label}")
    print(f"{'cell':22}{'n':>6}{'ours p50':>12}{'real p50':>12}   in-band share per measure")
    unmatched = []
    for cell in sorted(cells):
        accounts = cells[cell]
        real = real_vectors(bands, cell)
        real_cell = bands["cells"].get(cell)
        if not real or real_cell is None or real_cell["n"] < MIN_CELL:
            unmatched.append((cell, len(accounts)))
            continue
        in_band = collections.Counter()
        for account in accounts:
            for measure in measures:
                value = account["vector"][measure]
                low, high = real_cell["p10"][measure], real_cell["p90"][measure]
                pairs += 1
                good = low <= value <= high
                inside += good
                in_band[measure] += good
                per_measure[measure] += good
                per_measure_n[measure] += 1
        auc = auc_of([[a["vector"][m] for m in measures] for a in accounts], [[v[MEASURES.index(m)] for m in measures]
                                                                             for v in real])
        if auc is not None:
            per_cell_auc.append(auc)
            per_cell_weight.append(min(len(accounts), len(real)))
        ours_p50 = statistics.median(a["vector"]["growth"] for a in accounts)
        row = f"{cell:22}{len(accounts):>6}{ours_p50:>12,.0f}{real_cell['p50']['growth']:>12,.0f}  "
        print(row + " ".join(f"{m[:6]}={in_band[m] / len(accounts):.2f}" for m in measures))
    if unmatched:
        print("not scored (no published universe of that age and band, or its n is under 30): "
              + ", ".join(f"{c} ({n})" for c, n in unmatched))
    hds = inside / max(pairs, 1)
    auc = float(np.average(per_cell_auc, weights=per_cell_weight)) if per_cell_auc else float("nan")
    ranked, pooled_auc = giveaways(cells, bands, measures)
    print(f"\nHDS (in-band share)        {hds:.3f}   target >= {HDS_TARGET}   over {len(measures)} measures")
    print(f"Turing AUC (per cell)      {auc:.3f}   target <= {AUC_TARGET}"
          + (f"   pooled by cell: {pooled_auc:.3f}" if pooled_auc else ""))
    print("giveaways (permutation importance):")
    for measure, weight in ranked[:5]:
        miss = 1 - per_measure[measure] / max(per_measure_n[measure], 1)
        print(f"   {measure:12} importance {weight:+.3f}   out-of-band {miss:.0%}")
    result = {"label": label, "hds": hds, "auc": auc, "pooled_auc": pooled_auc, "measures": measures,
              "giveaways": [{"measure": m, "importance": w} for m, w in ranked[:5]],
              "in_band": {m: per_measure[m] / max(per_measure_n[m], 1) for m in measures},
              "cells": {c: len(v) for c, v in sorted(cells.items())}}
    if save:
        save.write_text(json.dumps(result, indent=1))
        print(f"scorecard written to {save}")
    return result


def self_test(bands: dict) -> int:
    """Score half of every real sample against the other half: the yardstick must say 'indistinguishable'.

    Two of the eight measures cannot fail this test: a binary `alliance` and a `moons` count whose real p10
    and p90 are both 0 in every low band are inside their band whatever the value, which pushes the overall
    in-band share above the 0.80 a continuous measure would give. So the pass condition is the strict one:
    no leakage (AUC <= 0.55) *and* the continuous measures land in 0.75-0.85, i.e. the excess over 0.85 is
    fully explained by the two discrete measures and not by a leak.
    """
    cells = {}
    for cell, payload in bands["cells"].items():
        sample = payload.get("sample", [])
        if len(sample) < 20:
            continue
        half = len(sample) // 2
        cells[cell] = [{"account": f"{cell}/{i}", "vector": dict(zip(MEASURES, v)), "seed": 0, "persona": "-"}
                       for i, v in enumerate(sample[:half])]
    result = report(cells, bands, "self-test (half the real sample scored as ours)", None)
    discrete = ["moons", "alliance"]
    continuous = {m: share for m, share in result["in_band"].items() if m not in discrete}
    continuous_hds = statistics.mean(continuous.values())
    shown = " , ".join(f"{m}={v:.2f}" for m, v in sorted(continuous.items()))
    fixed = ", ".join(f"{m}={result['in_band'][m]:.2f}" for m in discrete)
    print(f"\nof which the continuous measures: {continuous_hds:.3f} ({shown})")
    print(f"always-in-band measures: {fixed}")
    ok = result["auc"] <= 0.55 and 0.75 <= continuous_hds <= 0.85 and result["hds"] >= 0.75
    print(f"\nself-test {'PASSED' if ok else 'FAILED'}: HDS {result['hds']:.3f} "
          f"(continuous {continuous_hds:.3f}, want 0.75-0.85), AUC {result['auc']:.3f} (want <= 0.55)")
    return 0 if ok else 1


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    parser.add_argument("season", nargs="*", type=Path, help="season JSONL files (§9.1)")
    parser.add_argument("--bands", type=Path, default=DEFAULT_BANDS)
    parser.add_argument("--save", type=Path, help="write the scorecard here, to use as a --baseline later")
    parser.add_argument("--baseline", type=Path, help="compare with a saved scorecard; exit 1 when it regresses")
    parser.add_argument("--self-test", action="store_true", help="prove the yardstick on the real sample")
    parser.add_argument("--war-cells", action="store_true",
                        help="also score the war cells (OGMEM battles), reported apart from HDS/AUC")
    parser.add_argument("--war-real", type=Path, default=war_cells.DEFAULT_REAL,
                        help="ogmem-strategies.json holding the real `battles`")
    args = parser.parse_args()

    bands = json.loads(args.bands.read_text())
    if args.self_test:
        status = self_test(bands)
        return status | (war_cells.self_test(args.war_real) if args.war_cells else 0)
    if not args.season:
        parser.error("give at least one season file, or --self-test")

    rows = read_season(args.season)
    if not print_health(rows):
        return 1
    counts = violations(rows)
    stub = any(row.get("score_source") == "stub" for row in rows)
    measures = COUNTS if stub else MEASURES
    cells, usable = score_rows(day_lines(rows))
    print(f"{len(rows):,} lines, {len(cells)} cells ({len(usable)} with growth), "
          f"{len({(r['seed'], r['account']) for r in rows})} accounts, {len({r['seed'] for r in rows})} seeds")
    if stub:
        print("scores are the simulator's stub: totals and shares are not compared with real players, "
              f"only {', '.join(COUNTS)}")
    print("hard constraints:", counts or "none violated")
    result = report(cells, bands, "this season", args.save, measures)
    result["violations"] = counts
    if args.war_cells:
        result["war"] = war_cells.judge(args.season, rows, args.war_real)
        if args.save:
            args.save.write_text(json.dumps(result, indent=1))

    failed = any(counts.get(key) for key in GATED)
    comparable = bool(result["cells"]) and result["hds"] > 0
    status = 0 if comparable and not failed and result["hds"] >= HDS_TARGET and result["auc"] <= AUC_TARGET else 1
    if not comparable:
        print("\nno cell of this season has a real counterpart, so nothing was compared: "
              "check the world's speed and the season length")
    print(f"\nverdict: HDS {result['hds']:.3f} / AUC {result['auc']:.3f} / violations "
          f"{sum(counts.values()) if counts else 0} -> {'PASS' if status == 0 else 'FAIL'}")
    if args.baseline and args.baseline.exists():
        previous = json.loads(args.baseline.read_text())
        drop = previous["hds"] - result["hds"]
        print(f"against {args.baseline.name}: HDS {previous['hds']:.3f} -> {result['hds']:.3f} ({drop:+.3f})")
        if drop > 0.05:
            print("regression: HDS dropped by more than 0.05")
            return 1
    return status


if __name__ == "__main__":
    raise SystemExit(main())
