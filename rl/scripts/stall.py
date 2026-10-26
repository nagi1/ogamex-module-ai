#!/usr/bin/env python3
"""Why a queue stops: the stall table by account age (row 29 of plan/rl/RL-FLOW.md).

Row 23s's ledger says the account stops spending (building 7.8% of production at 129-256 days, 0.3% at 257-365, while
the store throws 78-96% away). This reads the season's own `stall` sample - the hours a queued build holds each planet's
building queue, the levels of the host's time modifiers, what the player rule would build next with its build time and
its affordability wait, and why each building object cannot start - and the yard's reason at the logins that found a
store at 80% or more, and prints them by age.

    .venv-rl/bin/python Modules/AI/rl/scripts/stall.py storage/rl/ogsim/runs/flow/e29/season-*.jsonl

The pick row 29 pre-commits: **T** if the building queue is time-blocked (> 24 h of build left) on at least 40% of the
planet-hours after day 120; **Y** if the yard's reason is "share target met" on at least 40% of the logins that found a
store at cap; **R** always. The script prints both shares and says which reason wins.
"""
from __future__ import annotations

import argparse
import json
import statistics
from pathlib import Path

AGES = ((1, 30), (31, 60), (61, 120), (121, 180), (181, 250), (251, 365))
REASONS = ("ok", "max level", "needs", "no fields", "never affordable")


def median(values: list[float]) -> float:
    return statistics.median(values) if values else float("nan")


def planets_of(row: dict) -> int:
    return max(int((row["stall"]["blocked_hours"] + row["stall"]["idle_hours"]) / 24), 1)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    parser.add_argument("season", nargs="+", type=Path)
    args = parser.parse_args()

    rows = [json.loads(line) for path in args.season for line in path.read_text().splitlines() if line.strip()]
    rows = [row for row in rows if "stall" in row]
    if not rows:
        print("no season rows with a stall sample (a season from row 29 on writes one)")
        return 1
    print(f"{len(rows):,} account-days over {len(args.season)} season(s)\n")

    print(f"{'age (days)':>10} {'days':>6} {'hours':>10} {'queued':>7} {'>24 h':>7} {'modifier (build)':>18} "
          f"{'next build':>11} {'next wait':>11} {'no want':>8}")
    for low, high in AGES:
        part = [row for row in rows if low <= row["day"] <= high]
        if not part:
            continue
        hours = sum(row["stall"]["blocked_hours"] + row["stall"]["idle_hours"] for row in part)
        blocked = sum(row["stall"]["blocked_hours"] for row in part)
        planets = sum(planets_of(row) for row in part)
        long = sum(row["stall"]["long_builds"] for row in part)
        levels = [level for row in part for (_, level) in row["stall"]["mod_build"]]
        waits = [row["stall"]["next_wait"] for row in part if row["stall"]["next_wait"] is not None]
        print(f"{low:>4}-{high:<5} {len(part):>6} {hours:>10,.0f} {blocked / hours:>7.0%} {long / max(planets, 1):>7.0%} "
              f"{median([float(level) for level in levels]):>18.0f} "
              f"{median([row['stall']['next_seconds'] for row in part if row['stall']['next_seconds'] is not None]):>9.1f} h "
              f"{median(waits):>9.1f} h "
              f"{sum(1 for row in part if row['stall']['next_id'] is None) / len(part):>8.0%}")

    print("\nwhy the building queue's objects cannot start (median objects per account-day, of the building queue's own):")
    print(f"{'age (days)':>10} {'days':>6} " + " ".join(f"{reason:>17}" for reason in REASONS))
    for low, high in AGES:
        part = [row for row in rows if low <= row["day"] <= high and "buildings" in row["stall"]]
        if not part:
            continue
        cells = [median([float(row["stall"]["buildings"].get(reason, 0)) for row in part]) for reason in REASONS]
        print(f"{low:>4}-{high:<5} {len(part):>6} " + " ".join(f"{cell:>17.0f}" for cell in cells))

    print("\nthe yard, at the logins that found a store at 80% or more (row 29's Y):")
    print(f"{'age (days)':>10} {'logins':>8} {'ordered':>8} {'share met':>10} {'nothing payable':>16}")
    for low, high in AGES:
        part = [row for row in rows if low <= row["day"] <= high and row["events_today"]["full_yard"]]
        counts = [sum(row["events_today"]["full_yard"][key] for row in part) for key in ("order", "share_met", "nothing_payable")]
        total = sum(counts)
        if not total:
            continue
        print(f"{low:>4}-{high:<5} {total:>8,} " + " ".join(f"{count / total:>10.0%}" if i == 1 else f"{count / total:>8.0%}"
                                                           for i, count in enumerate(counts)))

    late = [row for row in rows if row["day"] > 120]
    hours = sum(row["stall"]["blocked_hours"] + row["stall"]["idle_hours"] for row in late)
    timed = sum(row["stall"]["long_builds"] for row in late) / max(sum(planets_of(row) for row in late), 1)
    full = [row for row in late if row["events_today"]["full_yard"]]
    counts = [sum(row["events_today"]["full_yard"][key] for row in full) for key in ("order", "share_met", "nothing_payable")]
    met = counts[1] / max(sum(counts), 1)
    print(f"\nafter day 120: the building queue is time-blocked on {timed:.0%} of planet-days, "
          f"queued at all on {sum(row['stall']['blocked_hours'] for row in late) / max(hours, 1):.0%} of planet-hours, "
          f"and the yard says \"share met\" at {met:.0%} of full-store logins")
    print(f"row 29's pick: T {'qualifies' if timed >= 0.40 else 'does not qualify'} ({timed:.0%} against 40%), "
          f"Y {'qualifies' if met >= 0.40 else 'does not qualify'} ({met:.0%} against 40%), R always")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
