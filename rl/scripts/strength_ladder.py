#!/usr/bin/env python3
"""How strong real OGame players are at a given age: the strength ladder a strong player is judged against.

Reads the PUB snapshots (267 real universes, one highscore each) and writes `storage/rl/ogsim/strength-ladder.json`:
per speed-days bucket (universe age x economy speed, as real_bands.py ages a universe), the lifeform-free points of
rank 1, the top 1%, the top 10% and the median ranked player. With seasons given, it prints where their accounts stand:
day d of a speed-s season is s * d speed-days.

    .venv-rl/bin/python rl/scripts/strength_ladder.py [SEASON.jsonl[.xz] ...]

A season account far above real rank 1 at its age is not a strong player but a simulator exploit to look for.
"""
from __future__ import annotations

import json
import lzma
import math
import sys
from pathlib import Path

from real_bands import lifeform_free, percentile, scores_of, universe_of

AI = Path(__file__).resolve().parents[2]
SOURCES = AI / "storage/rl/external/sources"
OUT = AI.parents[1] / "storage/rl/ogsim/strength-ladder.json"
EDGES = [0, 50, 100, 200, 400, 800, 1600, 3200, 6400, 12800, math.inf]
RUNGS = {"rank1": None, "top1": 0.99, "top10": 0.90, "median": 0.50}


def bucket(speed_days: float) -> str:
    for low, high in zip(EDGES, EDGES[1:]):
        if speed_days < high:
            return f"{low}-{high}"
    raise ValueError(speed_days)


def ladder() -> dict:
    cells: dict[str, dict[str, list[float]]] = {}
    for directory in sorted(SOURCES.glob("PUB-*/normalized")):
        universe = universe_of(directory)
        if universe is None:
            continue
        scores, _ = scores_of(directory)
        points = sorted(p for p in (lifeform_free(by_kind) for by_kind in scores.values()) if p > 0)
        if len(points) < 50:
            continue
        cell = cells.setdefault(bucket(universe["speed_days"]), {rung: [] for rung in RUNGS} | {"speed_days": []})
        cell["speed_days"].append(universe["speed_days"])
        for rung, q in RUNGS.items():
            cell[rung].append(points[-1] if q is None else percentile(points, q * 100))
    # Per bucket, the median universe's value of each rung (universes differ in size and age inside a bucket).
    return {name: {"universes": len(cell["speed_days"])} | {rung: percentile(values, 50) for rung, values in cell.items()}
            for name, cell in sorted(cells.items(), key=lambda kv: float(kv[0].split("-")[0]))}


def lines(path: str):
    opener = lzma.open if path.endswith(".xz") else open
    with opener(path, "rt") as handle:
        for line in handle:
            yield json.loads(line)


def place(table: dict, path: str) -> None:
    """Prints, at a few days, each season group's median and best account against the ladder at that age."""
    by_day: dict[int, dict[str, list[float]]] = {}
    for r in lines(path):
        if "score" not in r or r.get("inactive"):
            continue
        group = "champion" if r.get("champion") else "others"
        by_day.setdefault(r["day"], {}).setdefault(group, []).append(r["score"]["total"])
    speed = next(lines(path)).get("world_speed", 8)
    for day in sorted(by_day):
        if day not in (1, 3, 7, 10, 30, 60, 120, 240, 365) and day != max(by_day):
            continue
        rung = table.get(bucket(day * speed))
        if not rung:
            continue
        parts = [f"{g} median {percentile(sorted(v), 50):,.0f} best {max(v):,.0f}" for g, v in sorted(by_day[day].items())]
        print(f"day {day:3d} ({day * speed:5.0f} speed-days) | " + " | ".join(parts)
              + f" | real rank1 {rung['rank1']:,.0f} top1% {rung['top1']:,.0f} top10% {rung['top10']:,.0f} median {rung['median']:,.0f}")


def main() -> None:
    table = ladder()
    OUT.write_text(json.dumps(table, indent=1))
    for name, cell in table.items():
        print(f"{name:>12} speed-days  {cell['universes']:3d} universes  rank1 {cell['rank1']:>15,.0f}  top1% {cell['top1']:>13,.0f}"
              f"  top10% {cell['top10']:>12,.0f}  median {cell['median']:>10,.0f}")
    for path in sys.argv[1:]:
        print(f"\n{path}")
        place(table, path)


if __name__ == "__main__":
    main()
