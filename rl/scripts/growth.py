#!/usr/bin/env python3
"""Where the first week goes: queue-busy hours, logins and full stores by account age, beside the real medians.

Row 11 of plan/rl/RL-FLOW.md. A diagnosis, not a behaviour change: it reads the season's own lines and names the
mechanism behind the week-one growth gap the planner measured (our best accounts hold 169-520 points at day 8
against a real mid median of 1.6-3.2k).

    .venv-rl/bin/python Modules/AI/rl/scripts/growth.py storage/rl/ogsim/runs/flow/e11/season-*.jsonl \
        --bands storage/rl/ogsim/real-bands.json
"""
from __future__ import annotations

import argparse
import json
import statistics
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from real_bands import bucket_of  # noqa: E402  (the same bucket definitions the target file uses)

DEFAULT_BANDS = Path(__file__).resolve().parents[4] / "storage/rl/ogsim/real-bands.json"
AGES = ((1, 7), (8, 14), (15, 30), (31, 60))


def real_p50(bands: dict, band: str, speed_days: float) -> float | None:
    cell = bands["cells"].get(f"{band}|{bucket_of(speed_days)}")
    return None if cell is None else cell["p50"]["growth"]


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    parser.add_argument("season", nargs="+", type=Path)
    parser.add_argument("--bands", type=Path, default=DEFAULT_BANDS)
    args = parser.parse_args()

    rows = [json.loads(line) for path in args.season for line in path.read_text().splitlines() if line.strip()]
    if not rows:
        print("no season rows")
        return 1
    bands = json.loads(args.bands.read_text())
    growth = [r["score"]["total"] / max(r["day"] * r["world_speed"], 1) for r in rows]

    print(f"{len(rows):,} account-days over {len(args.season)} season(s)\n")
    print(f"{'age (days)':>10} {'n':>6} {'logins/d':>9} {'build h/d':>10} {'idle h/d':>9} {'full logins':>12} "
          f"{'growth p50':>11} {'real mid p50':>13} {'ours/real':>9}")
    for low, high in AGES:
        part = [r for r in rows if low <= r["day"] <= high]
        if not part:
            continue
        matched = [real_p50(bands, "mid", r["day"] * r["world_speed"]) for r in part]
        matched = [value for value in matched if value is not None]
        ours = statistics.median(r["score"]["total"] / max(r["day"] * r["world_speed"], 1) for r in part)
        real = statistics.median(matched) if matched else float("nan")
        print(f"{low:>4}-{high:<5} {len(part):>6} {statistics.mean(r['logins'] for r in part):>9.1f} "
              f"{statistics.mean(r['build_hours'] for r in part):>10.1f} {statistics.mean(r['idle_hours'] for r in part):>9.1f} "
              f"{statistics.mean(r['full_logins'] / max(r['logins'], 1) for r in part):>12.0%} {ours:>11,.0f} "
              f"{real:>13,.0f} {ours / real if real else float('nan'):>9.2f}")

    # The accounts that close the game: what their first week looked like, against the planner's 169-520 points.
    last = max(r["day"] for r in rows)
    final = {}
    for r in rows:
        if r["day"] == last:
            final[(r["seed"], r["account"])] = r["score"]["total"]
    best = sorted(final, key=lambda k: -final[k])[:3]
    print("\nthe three best accounts at day", last, "- first-week totals and what the day held:")
    for key in best:
        week = [r for r in rows if (r["seed"], r["account"]) == key and r["day"] <= 7]
        day8 = next((r["score"]["total"] for r in rows if (r["seed"], r["account"]) == key and r["day"] == 8), None)
        print(f"  {key[0]}/{key[1]:>2}: day-8 {day8:>7,} points, week-one {statistics.mean(r['logins'] for r in week):>4.1f} logins/day, "
              f"{statistics.mean(r['build_hours'] for r in week):>4.1f} build-h/day, "
              f"{statistics.mean(r['idle_hours'] for r in week):>4.1f} idle-h/day, "
              f"full logins {statistics.mean(r['full_logins'] / max(r['logins'], 1) for r in week):>4.0%}, "
              f"final {final[key]:>9,}")

    week_one = [g for r, g in zip(rows, growth) if r["day"] <= 7]
    print(f"\nweek one: idle hours/day p10/p50/p90 "
          f"{statistics.quantiles([r['idle_hours'] for r in rows if r['day'] <= 7], n=10)[0]:.1f} / "
          f"{statistics.median(r['idle_hours'] for r in rows if r['day'] <= 7):.1f} / "
          f"{statistics.quantiles([r['idle_hours'] for r in rows if r['day'] <= 7], n=10)[-1]:.1f}, "
          f"build hours/day median {statistics.median(r['build_hours'] for r in rows if r['day'] <= 7):.1f}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
