#!/usr/bin/env python3
"""Who is behind every real-band cell, and is the age that made it a real age?

Row 18 of plan/rl/RL-FLOW.md, read-only. `growth` in real-bands.json is a player's score over the age of its
universe, and that age is an estimate - the oldest alliance still standing on the snapshot day. Per band x bucket
cell this prints the universes behind it (community, speed, age in real days, accounts, own p50 growth), then
checks the estimate against the only second date the sources hold, OSTATS `opened`.

    .venv-rl/bin/python Modules/AI/rl/scripts/band_audit.py --bands storage/rl/ogsim/real-bands.json
"""
from __future__ import annotations

import argparse
import collections
import datetime as dt
import json
from pathlib import Path

import real_bands as rb


def epoch(iso: str) -> float:
    return dt.datetime.fromisoformat(str(iso).replace("Z", "+00:00")).timestamp()


def opened_of_ostats(src: Path) -> dict:
    """OSTATS's opening date per (community, server number). A second source; never used to build a band."""
    return {(str(row["community"]), int(row["number"])): epoch(row["opened"])
            for row in rb.rows(src / "OSTATS/normalized/universe_catalogue.jsonl")
            if row.get("opened") and row.get("community") and row.get("number") is not None}


def universes_of(src: Path) -> list[dict]:
    """Per aged PUB universe: what it is, its estimated age, and the accounts it adds to each cell."""
    out = []
    for directory in sorted(src.glob("PUB-*/normalized")):
        universe = rb.universe_of(directory)
        if universe is None:
            continue  # no server row or no alliance to age it: real_bands drops it the same way
        server = next(rb.rows(directory / "server.jsonl"), {})
        status, allied = rb.players_of(directory)
        scores, ranked = rb.scores_of(directory)
        owned, _ = rb.planets_of(directory)
        cells, growth = collections.Counter(), collections.defaultdict(list)
        for band, bucket, vector in rb.account_measures(directory, universe, status, allied, scores, ranked, owned):
            cells[(band, bucket)] += 1
            growth[(band, bucket)].append(vector[0])
        out.append({"name": directory.parent.name, "community": str(server.get("language", "?")),
                    "number": server.get("server_number"), "speed": universe["speed"],
                    "speed_days": universe["speed_days"], "snapshot": epoch(server["captured_at"]),
                    "age_days": universe["speed_days"] / universe["speed"],
                    "players": len(status), "ranked": ranked, "cells": cells,
                    "growth": {key: rb.percentile(v, 50) for key, v in growth.items()}})
    return out


def print_cells(bands: dict, universes: list[dict]) -> None:
    """One block per cell: the universes behind it, biggest first, against the cell's recorded n."""
    recorded = bands["cells"]
    for bucket in rb.BUCKET_NAMES:
        for band in rb.BANDS:
            key = f"{band}|{bucket}"
            backing = sorted((u for u in universes if u["cells"].get((band, bucket))),
                             key=lambda u: -u["cells"][(band, bucket)])
            if not backing:
                continue
            ages = [u["age_days"] for u in backing]
            print(f"\n{key}  n {sum(u['cells'][(band, bucket)] for u in backing)} "
                  f"(real-bands.json {recorded.get(key, {}).get('n', 0)})  {len(backing)} universe(s), "
                  f"age median {rb.percentile(ages, 50):.0f} d (min {min(ages):.0f}, max {max(ages):.0f})")
            print(f"  {'universe':13}{'comm':6}{'spd':>4}{'age d':>7}{'acc':>5}{'growth p50':>12}")
            for u in backing:
                print(f"  {u['name']:13}{u['community']:6}{u['speed']:>4.0f}{u['age_days']:>7.0f}"
                      f"{u['cells'][(band, bucket)]:>5}{u['growth'][(band, bucket)]:>12.1f}")


def print_age_check(universes: list[dict], opened: dict) -> None:
    """The alliance estimate against OSTATS's opening date, on the young universes and the speed-5 ones."""
    for title, group in (("age <= 64 real days", [u for u in universes if u["age_days"] <= 64]),
                         ("speed 5", [u for u in universes if u["speed"] == 5])):
        print(f"\n--- estimate vs OSTATS `opened`: {title} ({len(group)} universes) ---")
        ratios = []
        print(f"  {'universe':13}{'comm':6}{'PUB d':>7}{'OSTATS d':>10}{'ratio':>7}")
        for u in group:
            stamp = opened.get((u["community"], u["number"])) if u["number"] is not None else None
            if not stamp:
                print(f"  {u['name']:13}{u['community']:6}{u['age_days']:>7.0f}{'no match':>10}")
                continue
            other = (u["snapshot"] - stamp) / 86_400.0
            ratios.append(other / u["age_days"] if u["age_days"] else 0.0)
            print(f"  {u['name']:13}{u['community']:6}{u['age_days']:>7.0f}{other:>10.0f}"
                  f"{other / u['age_days']:>7.2f}")
        if ratios:
            print(f"  OSTATS age / alliance age: median {rb.percentile(ratios, 50):.2f}, "
                  f"range {min(ratios):.2f}-{max(ratios):.2f}, n {len(ratios)}")


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    parser.add_argument("--bands", type=Path, default=rb.DEFAULT_OUT)
    parser.add_argument("--src", type=Path, default=rb.DEFAULT_SRC)
    args = parser.parse_args()

    bands = json.loads(args.bands.read_text())
    universes = universes_of(args.src)
    print(f"{len(universes)} aged universes ({bands['universes']} in {args.bands.name}); ages "
          f"{min(u['age_days'] for u in universes):.0f}-{max(u['age_days'] for u in universes):.0f} real days; "
          f"speeds {dict(sorted(collections.Counter(u['speed'] for u in universes).items()))}")
    print("speed-8 ages (real days):",
          ", ".join(f"{age:.0f}" for age in sorted(u["age_days"] for u in universes if u["speed"] == 8)))
    server = next(rb.rows(next(iter(sorted(args.src.glob("PUB-*/normalized")))) / "server.jsonl"), {})
    print("PUB server.jsonl fields:", ", ".join(sorted(server)),
          "and nothing else dated: player ids are hashes, so the only dates are `captured_at` (the snapshot)"
          " and alliance `found_date` (the estimate's own input)")
    print_cells(bands, universes)
    print_age_check(universes, opened_of_ostats(args.src))


if __name__ == "__main__":
    main()
