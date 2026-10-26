#!/usr/bin/env python3
"""Where the growth goes: the week-one hours, the income ledger and the mix, by account age, beside the real medians.

Row 11 of plan/rl/RL-FLOW.md built the hours; row 23 added the income ledger (`produced`, `wasted`, what each queue
spent, the loot, the fuel and the cargo lost) and the signed mix table, and moved the real column onto the account's
own age bucket - the same cell `hds.py` scores a speed-8 season in (row 19), since days 15-63 have no published
speed-8 universe at all.

    .venv-rl/bin/python Modules/AI/rl/scripts/growth.py storage/rl/ogsim/runs/flow/e23s/base-*.jsonl \
        --bands storage/rl/ogsim/real-bands.json
"""
from __future__ import annotations

import argparse
import json
import statistics
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from real_bands import age_bucket_of, bucket_of  # noqa: E402  (the same cells the target file is built from)

DEFAULT_BANDS = Path(__file__).resolve().parents[4] / "storage/rl/ogsim/real-bands.json"
AGES = ((1, 7), (8, 14), (15, 30), (31, 60), (61, 128), (129, 256), (257, 365))
# Row 23's ledger, in the order the identity nets them.
LEDGER = ("produced", "wasted", "spent_build", "spent_research", "spent_units", "fuel", "loot_in", "loot_out",
          "expedition_in", "cargo_lost")
SHARES = ("res_share", "eco_share", "mil_share")
BANDS = ("top10", "mid", "bottom")


def cell_of(row: dict) -> str | None:
    """The cell a season line is compared with: a speed-8 season by its own age in days, as `hds.py` places it."""
    if row["world_speed"] == 8:
        return age_bucket_of(float(row["day"]))
    return bucket_of(row["day"] * row["world_speed"])


def real_p50(bands: dict, band: str, row: dict, measure: str) -> float | None:
    cell = bands["cells"].get(f"{band}|{cell_of(row)}")
    return None if cell is None else cell["p50"][measure]


def share_of(row: dict, measure: str) -> float:
    total = row["score"]["total"] or 0.0
    part = row["score"][{"eco_share": "economy", "res_share": "research", "mil_share": "military"}[measure]]
    return part / total if total > 0 else 0.0


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
    ledger = "produced" in rows[0]

    print(f"{len(rows):,} account-days over {len(args.season)} season(s)")
    if ledger:
        over = [row for row in rows if abs(row["unexplained"]) > 0.01 * max(row["produced"], 1.0)]
        print(f"ledger: {len(over):,} line(s) out by more than 1% of the day's production, "
              f"worst {max(abs(row['unexplained']) for row in rows):,.0f} of a {max(row['produced'] for row in rows):,.0f} day")
        # Row 23's check 5: inactivity is the host's seven-day rule, so no line an account logged into is scored as gone.
        wrong = [row for row in rows if row["inactive"] and row["logins"] > 0]
        print(f"quitters: {sum(1 for row in rows if row['inactive']):,} inactive line(s), {len(wrong)} of them with a login that day")

    print(f"\n{'age (days)':>10} {'n':>6} {'logins/d':>9} {'build h/d':>10} {'idle h/d':>9} {'full logins':>12} "
          f"{'growth p50':>11} {'real mid p50':>13} {'ours/real':>9}")
    for low, high in AGES:
        part = [row for row in rows if low <= row["day"] <= high]
        if not part:
            continue
        matched = [real_p50(bands, "mid", row, "growth") for row in part]
        matched = [value for value in matched if value is not None]
        ours = statistics.median(row["score"]["total"] / max(row["day"] * row["world_speed"], 1) for row in part)
        real = statistics.median(matched) if matched else float("nan")
        print(f"{low:>4}-{high:<5} {len(part):>6} {statistics.mean(row['logins'] for row in part):>9.1f} "
              f"{statistics.mean(row['build_hours'] for row in part):>10.1f} "
              f"{statistics.mean(row['idle_hours'] for row in part):>9.1f} "
              f"{statistics.mean(row['full_logins'] / max(row['logins'], 1) for row in part):>12.0%} {ours:>11,.0f} "
              f"{real:>13,.0f} {ours / real if real else float('nan'):>9.2f}")

    if ledger:
        # Row 23: where the growth goes - every flow the season booked, as a share of what the account produced.
        print("\nthe income ledger, as a share of `produced`:")
        print(f"{'age (days)':>10} {'n':>6} " + " ".join(f"{name[:9]:>9}" for name in LEDGER[1:]))
        for low, high in AGES:
            part = [row for row in rows if low <= row["day"] <= high and row["produced"] > 0]
            if not part:
                continue
            made = sum(row["produced"] for row in part)
            print(f"{low:>4}-{high:<5} {len(part):>6} "
                  + " ".join(f"{sum(row[name] for row in part) / made:>9.1%}" for name in LEDGER[1:]))
        made = sum(row["produced"] for row in rows)
        print(f"{'all':>10} {len(rows):>6} " + " ".join(f"{sum(row[name] for row in rows) / made:>9.1%}"
                                                          for name in LEDGER[1:]))
        # The ceiling arm (row 23): what a cadence change can buy at all, against the real mid median.
        print("\nthe ceiling at 64-256, per season (ours against the real mid median)")
        for path in args.season:
            part = [row for row in (json.loads(line) for line in path.read_text().splitlines() if line.strip())
                    if 64 <= row["day"] <= 256]
            if not part:
                continue
            ours = statistics.median(row["score"]["total"] / max(row["day"] * row["world_speed"], 1) for row in part)
            real = [value for value in (real_p50(bands, "mid", row, "growth") for row in part) if value is not None]
            real = statistics.median(real) if real else float("nan")
            print(f"  {path.name:>22}: {ours:>10,.0f}   real mid {real:>10,.0f}   "
                  f"{ours / real if real else float('nan'):>6.3f}x")

    # Row 23: the signed table - our p50 minus the real p50, per band and age, so the next mix row knows its direction.
    print("\nour p50 minus the real p50, per band and age (+ means we hold too much of it):")
    print(f"{'age (days)':>10} {'n':>6} " + " ".join(f"{band[:5]}-{name[:6]:<8}" for band in BANDS for name in SHARES))
    for low, high in AGES:
        part = [row for row in rows if low <= row["day"] <= high]
        if not part:
            continue
        cells = []
        for band in BANDS:
            for name in SHARES:
                ours = statistics.median(share_of(row, name) for row in part)
                real = [value for value in (real_p50(bands, band, row, name) for row in part) if value is not None]
                cells.append(ours - statistics.median(real) if real else float("nan"))
        print(f"{low:>4}-{high:<5} {len(part):>6} " + " ".join(f"{value:>+14.2f}" for value in cells))

    # The accounts that close the game: what their first week looked like, against the planner's 169-520 points.
    last = max(row["day"] for row in rows)
    final = {}
    for row in rows:
        if row["day"] == last:
            final[(row["seed"], row["account"])] = row["score"]["total"]
    best = sorted(final, key=lambda key: -final[key])[:3]
    print("\nthe three best accounts at day", last, "- first-week totals and what the day held:")
    for key in best:
        week = [row for row in rows if (row["seed"], row["account"]) == key and row["day"] <= 7]
        day8 = next((row["score"]["total"] for row in rows if (row["seed"], row["account"]) == key and row["day"] == 8), None)
        print(f"  {key[0]}/{key[1]:>2}: day-8 {day8:>7,} points, week-one "
              f"{statistics.mean(row['logins'] for row in week):>4.1f} logins/day, "
              f"{statistics.mean(row['build_hours'] for row in week):>4.1f} build-h/day, "
              f"{statistics.mean(row['idle_hours'] for row in week):>4.1f} idle-h/day, "
              f"full logins {statistics.mean(row['full_logins'] / max(row['logins'], 1) for row in week):>4.0%}, "
              f"final {final[key]:>9,}")

    week_one = [row for row in rows if row["day"] <= 7]
    print(f"\nweek one: idle hours/day p10/p50/p90 "
          f"{statistics.quantiles([row['idle_hours'] for row in week_one], n=10)[0]:.1f} / "
          f"{statistics.median(row['idle_hours'] for row in week_one):.1f} / "
          f"{statistics.quantiles([row['idle_hours'] for row in week_one], n=10)[-1]:.1f}, "
          f"build hours/day median {statistics.median(row['build_hours'] for row in week_one):.1f}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
