#!/usr/bin/env python3
"""How human do the scripted accounts look from outside (plan/rl/WORLD-SIM-PLAN.md, W11).

Reads the JSON that `rl/ogsim/examples/dump_humanlike.rs` writes and prints each measure with the band it is compared
against and where that band comes from. Only one measure has a number from real players — growth
(`plan/rl/human-growth-check.md:100-101`). Three more are hard constraints the host itself flags on (a week of logins in
18 or more hours of the day, a dark period shorter than 6 h, an action less than 10 s after the last one). Everything
else has no measured band anywhere in the corpus, and is printed as "no band" rather than invented.

    cargo run --release --example dump_humanlike -- <data dir> 240 12 1 /tmp/humanlike.json
    python3 rl/scripts/humanlike.py /tmp/humanlike.json [--hours 240]
"""

from __future__ import annotations

import argparse
import json
import statistics
import sys


def band(values: list[float]) -> str:
    """Median with p10 and p90, as the growth table reports its bands."""
    if not values:
        return "-"
    ordered = sorted(values)
    (p10, p90) = (ordered[len(ordered) // 10], ordered[min(len(ordered) - 1, 9 * len(ordered) // 10)])
    return f"{statistics.median(ordered):,.0f} [{p10:,.0f}, {p90:,.0f}]"


def main(argv: list[str] | None = None) -> int:
    ap = argparse.ArgumentParser(prog="humanlike", description=__doc__.splitlines()[0])
    ap.add_argument("report", help="the JSON written by dump_humanlike")
    ap.add_argument("--hours", type=float, required=True, help="world hours the dump covered (speed-days / 24)")
    args = ap.parse_args(argv)

    players = json.load(open(args.report))["players"]
    days = args.hours / 24.0
    per_day = lambda key: [p[key] / days for p in players]

    rows = [
        # measure, values, band, provenance, verdict
        ("logins per account", [p["logins"] for p in players], "no band", "nothing in the corpus holds login timestamps", ""),
        ("distinct hours of day", [p["distinct_hours"] for p in players], "< 18 per week", "host detector threshold (veteran-play.md:307-310)", "flag"),
        ("busiest week, hours", [p["busiest_week_hours"] for p in players], "< 18", "host detector threshold", "flag"),
        ("longest gap, h", [p["longest_gap_h"] for p in players], ">= 6 h dark", "host detector threshold", "flag"),
        ("shortest gap, s", [p["shortest_gap_s"] for p in players], ">= 10 s", "host detector threshold", "flag"),
        ("spies per day", per_day("spies"), "no band", "no corpus holds per-account mission times", ""),
        ("raids per day", per_day("raids"), "no band", "no corpus holds per-account mission times", ""),
        ("raids received", [p["raids_received"] for p in players], "no band", "", ""),
        ("loot per account", [p["loot"] for p in players], "no band", "", ""),
        ("saves sent / refused", [p["saves_sent"] for p in players], "no band", "the 1-in-20..50 figure is a placeholder (gameplay-algorithms.md:741)", ""),
        ("recalls", [p["recalls"] for p in players], "no band", "", ""),
        ("planets per account", [p["planets"] for p in players], "median 5, p90 13", "real snapshot (play-coverage.md:310)", "compare"),
        ("value at end", [p["value_end"] for p in players], "growth bands below", "human-growth-check.md:100-101", "compare"),
    ]

    width = max(len(r[0]) for r in rows)
    print(f"{'measure':<{width}}  {'observed  median [p10, p90]':>30}  {'band':<26}  provenance")
    for name, values, expected, source, _ in rows:
        print(f"{name:<{width}}  {band(values):>30}  {expected:<26}  {source}")

    growth_real = "at 7.3 speed-days 3 / 57 / 1330 / 8670 points; at 22 speed-days 3 / 127 / 7660 / 41000 (n = 73 and 61)"
    print()
    print(f"growth: this run is {days:.1f} speed-days old. Real players, cross-sectionally:")
    print(f"        p10 / median / p90 / max  {growth_real}")
    print(f"        scripted accounts here     {band([p['value_end'] for p in players])}  (p10 / median / p90)")
    print("        not comparable as-is: the real bands are cross-sections at one instant, not one cohort followed over")
    print("        time, and the host's points are not the simulator's value.")
    print()

    flagged = [r for r in rows if r[4] == "flag"]
    print("host-flagged behaviour (these are the host's own detection thresholds, not player distributions):")
    for name, values, expected, _, _ in flagged:
        print(f"  {name:<{width}}  {band(values):>30}   want {expected}")
    print()
    print("no band exists for login gaps, reaction latency, save success, raid cadence or session length: report them, never")
    print("compare them against invented numbers (plan/rl/errand-spec.md, humanlike-measures.md).")
    return 0


if __name__ == "__main__":
    sys.exit(main())
