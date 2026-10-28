#!/usr/bin/env python3
"""The dead-end census (row 42 of plan/rl/RL-FLOW.md): every queue, every planet, from the raw season lines.

`stall.py` is replaced by this. It prints, by age band over the **active** account-days:
  1. the building queue, per planet-day: how many planets have anything legal, how many are sealed by fields, and - when
     nothing is legal - which reason dominates;
  2. the lab, on the planet that pays for it;
  3. the yard: how often it spends less than the surplus, and whether its own capacity is the bound;
  4. the attribution: the share of the day's unspent production each reason explains, so a row can say how much of the
     waste is named;
  5. the reachability table: of the sealed planets, how many can still start the object that adds fields.

    .venv-rl/bin/python Modules/AI/rl/scripts/census.py storage/rl/ogsim/runs/flow/e42/*.jsonl

Nothing here reads a row's prose; every number comes from the lines the season wrote.
"""
from __future__ import annotations

import argparse
import glob
import json
import statistics
from pathlib import Path

AGES = ((1, 30), (31, 60), (61, 120), (121, 180), (181, 250), (251, 365))
# Row 47: 30-day bands, the width the planner read the production plateau at.
BANDS = ((1, 30), (31, 60), (61, 90), (91, 120), (121, 150), (151, 180), (181, 210), (211, 240), (241, 270),
         (271, 300), (301, 330), (331, 365))
BUILD_REASONS = ("no fields", "needs", "never affordable", "max level")
TOTALS = ("produced", "wasted", "spent_build", "spent_research", "spent_units")


def median(values: list[float]) -> float:
    return statistics.median(values) if values else float("nan")


def dominant(reasons: dict[str, int]) -> str:
    """The reason that blocks this planet, by how many of its objects it blocks."""
    blocked = {k: v for k, v in reasons.items() if k in BUILD_REASONS and v}
    return max(blocked, key=lambda k: blocked[k]) if blocked else ("ok" if reasons.get("ok") else "nothing")


def load(paths: list[str]) -> list[dict]:
    rows = []
    for pattern in paths:
        for path in sorted(glob.glob(pattern)):
            rows += [json.loads(line) for line in open(path) if line.strip()]
    return rows


def print_health(rows: list[dict]) -> bool:
    """Report instrumented health before interpreting a season's results."""
    if not rows or any("health" not in row for row in rows):
        print("health: unavailable for at least one season; result is unverified\n")
        return False
    keys = ("frozen", "invalid_stock", "unbalanced", "clock_ahead", "unexplained_over_cap")
    if any(not all(key in row["health"] for key in (*keys, "production_drop_warning")) for row in rows):
        print("health: incomplete or mixed instrumentation; result is unverified\n")
        return False
    counts = {key: sum(bool(row["health"][key]) for row in rows) for key in keys}
    warnings = sum(bool(row["health"].get("production_drop_warning")) for row in rows)
    print(f"health: {counts} -> {'BROKEN' if any(counts.values()) else 'PASS'}\n")
    print(f"production-drop warnings: {warnings}\n")
    return not any(counts.values())


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    parser.add_argument("season", nargs="+", help="season JSONL files or globs")
    args = parser.parse_args()
    rows = load(args.season)
    healthy = print_health(rows)
    if not rows or "planets" not in rows[0].get("stall", {}):
        print("no census sample in these lines (a season from row 42 on writes one)")
        return 1
    active = [r for r in rows if not r["inactive"]]
    print(f"{len(rows):,} account-days, {len(active):,} active; "
          f"{sum(len(r['stall']['planets']) for r in active):,} planet-days active\n")

    # 0. the frozen day (row 47): the account logged in, booked no production, and neither its stock, its score nor its
    # fleet moved against the day before. A planet the world retired looks exactly like this, and so did every late-game
    # number before row 47. A quitter is excluded: it is inactive, and inactive is the world's own flag.
    series: dict[tuple, list[dict]] = {}
    for row in rows:
        series.setdefault((row["seed"], row["account"]), []).append(row)
    frozen = []
    for account_rows in series.values():
        account_rows.sort(key=lambda r: r["day"])
        for before, row in zip(account_rows, account_rows[1:]):
            if row["inactive"] or row["logins"] == 0:
                continue
            still = abs(row["stock"] - before["stock"]) < 1e-6
            still = still and abs(row["score"]["total"] - before["score"]["total"]) < 1e-6
            still = still and abs(row["fleet_value"] - before["fleet_value"]) < 1e-6
            if still and row["produced"] <= 1e-6:
                row["frozen"] = True
                frozen.append(row)

    print("0. frozen active account-days: a login day that produced nothing and moved neither stock, score nor fleet")
    print(f"{'age (days)':>10} {'active days':>12} {'frozen':>8}")
    for low, high in AGES:
        part = [r for r in active if low <= r["day"] <= high]
        if not part:
            continue
        print(f"{low:>4}-{high:<5} {len(part):>12,} {sum(1 for r in part if r.get('frozen')) / len(part):>8.1%}")
    accounts = {}
    for row in frozen:
        accounts.setdefault((row["seed"], row["account"]), row["day"])
    if frozen:
        print(f"   {len(frozen):,} frozen account-days over {len(accounts):,} accounts of {len(series):,};"
              f" median first frozen day {median(sorted(accounts.values()))}")
    else:
        print("   no account froze")
    print()

    print("0b. production per active account-day, by 30-day band (a fall with age is an account the world retired)")
    print(f"{'age (days)':>10} {'days':>8} {'median produced':>16} {'median wasted share':>20}")
    for low, high in BANDS:
        part = [r for r in active if low <= r["day"] <= high]
        if not part:
            continue
        share = median([r["wasted"] / r["produced"] for r in part if r["produced"] > 0])
        print(f"{low:>4}-{high:<5} {len(part):>8,} {median([r['produced'] for r in part]) / 1e6:>14.1f} M "
              f"{share:>20.1%}")
    print()

    print("1. the building queue, per planet-day, over active account-days")
    print(f"{'age (days)':>10} {'planet-days':>12} {'legal':>7} {'sealed':>7} {'used/cap':>9} "
          + " ".join(f"{name[:9]:>9}" for name in BUILD_REASONS))
    for low, high in AGES:
        part = [p for r in active if low <= r["day"] <= high for p in r["stall"]["planets"]]
        if not part:
            continue
        legal = sum(1 for p in part if p["reasons"].get("ok")) / len(part)
        sealed = sum(1 for p in part if p["sealed"]) / len(part)
        ratio = median([p["used"] / max(p["cap"], 1) for p in part])
        shares = [sum(1 for p in part if p["reasons"].get(name)) / len(part) for name in BUILD_REASONS]
        print(f"{low:>4}-{high:<5} {len(part):>12,} {legal:>7.0%} {sealed:>7.0%} {ratio:>9.2f} "
              + " ".join(f"{share:>9.0%}" for share in shares))

    print("\n2. the lab, per active account-day")
    print(f"{'age (days)':>10} {'days':>8} {'spent 0':>8} {'no legal':>9} {'needs':>7} {'never affordable':>17}")
    for low, high in AGES:
        part = [r for r in active if low <= r["day"] <= high and r["stall"].get("lab")]
        if not part:
            continue
        print(f"{low:>4}-{high:<5} {len(part):>8,} "
              f"{sum(1 for r in part if r['spent_research'] == 0) / len(part):>8.0%} "
              f"{sum(1 for r in part if not r['stall']['lab']['reasons'].get('ok')) / len(part):>9.0%} "
              f"{sum(1 for r in part if r['stall']['lab']['reasons'].get('needs')) / len(part):>7.0%} "
              f"{sum(1 for r in part if r['stall']['lab']['reasons'].get('never affordable')) / len(part):>17.0%}")

    print("\n3. the yard, per active account-day: spend against the surplus")
    print(f"{'age (days)':>10} {'days':>8} {'spent < surplus':>16} {'capacity < surplus':>19} {'ordered/surplus':>16}")
    for low, high in AGES:
        part = [r for r in active if low <= r["day"] <= high]
        if not part:
            continue
        under = [r for r in part if r["spent_units"] < r["stall"]["surplus"]]
        bound = [r for r in under if r["stall"]["yard_capacity"] < r["stall"]["surplus"]]
        ratio = median([r["spent_units"] / r["stall"]["surplus"] for r in part if r["stall"]["surplus"] > 0])
        print(f"{low:>4}-{high:<5} {len(part):>8,} {len(under) / len(part):>16.0%} "
              f"{(len(bound) / max(len(under), 1)):>19.0%} {ratio:>16.2f}")

    # 4. the attribution: what each reason explains, in weight of production, after day 120.
    def reason_of(row: dict) -> str:
        planets = row["stall"]["planets"]
        capital = next((p for p in planets if p["capital"]), None)
        if capital and capital["sealed"] and not capital["reasons"].get("ok"):
            return "sealed capital, adder unreachable" if not capital["adder_legal"] else "sealed capital, adder legal"
        if row["spent_build"] == 0 and capital and not capital["reasons"].get("ok"):
            return "buildings blocked: " + dominant(capital["reasons"])
        if row["spent_build"] == 0:
            return "buildings: no want"
        if row["spent_research"] == 0:
            return "research frozen"
        if row["spent_units"] < row["stall"]["surplus"]:
            return "yard capacity" if row["stall"]["yard_capacity"] < row["stall"]["surplus"] else "yard plan"
        return "unexplained"

    late = [r for r in active if r["day"] > 120]
    buckets: dict[str, float] = {}
    for row in late:
        unspent = row["produced"] - row["wasted"] - row["spent_build"] - row["spent_research"] - row["spent_units"]
        name = reason_of(row)
        buckets[name] = buckets.get(name, 0.0) + max(unspent, 0.0)
    total = sum(buckets.values()) or 1.0
    print(f"\n4. what the day's unspent production is attributed to, after day 120 ({total / 1e9:.1f} bn metal-equivalent):")
    for name, value in sorted(buckets.items(), key=lambda kv: -kv[1]):
        print(f"   {name:38} {value / total:6.1%}")
    print(f"   {'named (coverage)':38} {1 - buckets.get('unexplained', 0.0) / total:6.1%}")

    print("\n5. the reachability table: planets sealed by fields, after day 120")
    sealed = [p for r in late for p in r["stall"]["planets"] if p["sealed"]]
    if sealed:
        reachable = sum(1 for p in sealed if p["adder_legal"])
        chained = [p for p in sealed if not p["adder_legal"] and p["adder_needs"] is not None]
        print(f"   {len(sealed):,} sealed planet-days: the field adder is startable on {reachable / len(sealed):.0%}, "
              f"blocked by a missing prerequisite on {len(chained) / len(sealed):.0%}, "
              f"and by its own price on {sum(1 for p in sealed if not p['adder_legal'] and p['adder_needs'] is None) / len(sealed):.0%}")
        missing: dict[int, int] = {}
        for planet in chained:
            missing[planet["adder_needs"]] = missing.get(planet["adder_needs"], 0) + 1
        print("   the prerequisites the adder is missing: "
              + ", ".join(f"object {k} on {v / len(sealed):.0%}"
                          for k, v in sorted(missing.items(), key=lambda kv: -kv[1])[:4]))
    else:
        print("   no planet is sealed by fields after day 120")
    return 0 if healthy else 1


if __name__ == "__main__":
    raise SystemExit(main())
