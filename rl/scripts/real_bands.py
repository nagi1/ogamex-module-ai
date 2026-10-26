#!/usr/bin/env python3
"""The real target distribution: what actual OGame players look like, per rank band and age.

North-star plan N2 (plan/rl/NORTH-STAR-PLAN.md §6). Reads the PUB snapshots (the highscore of 267 real
universes) and the OStats hourly curves, and writes `storage/rl/ogsim/real-bands.json` in the §9.2 schema:
per scored cell (rank band x the universe's age in real days, speed 8 only) the p10/p50/p90 of every
measure and a raw sample for the classifier, the speed-days buckets of the other speeds apart (printed,
not scored),
the activity band from OStats, and the planet-position histogram.

Nothing here names a game object: it is a read of published player data, so gate 1 does not bite.

    .venv-rl/bin/python rl/scripts/real_bands.py            # writes ../../../storage/rl/ogsim/real-bands.json
    .venv-rl/bin/python rl/scripts/real_bands.py --print    # only the pooled table

The printed pooled table is the check: it must reproduce NORTH-STAR-PLAN.md §2 to ±0.01 on shares and
±1 on medians. A rank band is the player's own highscore position over the number of ranked players in its
universe, inactives included, because that is what a human sees in the highscore.
"""
from __future__ import annotations

import argparse
import collections
import datetime as dt
import json
import math
from pathlib import Path

MEASURES = ["growth", "eco_share", "res_share", "mil_share", "planets", "moons", "galaxies", "alliance"]
BANDS = ("top10", "mid", "bottom")
BUCKETS = [(0, 8), (8, 16), (16, 32), (32, 64), (64, 128), (128, 256), (256, math.inf)]
BUCKET_NAMES = ["0-8", "8-16", "16-32", "32-64", "64-128", "128-256", "256+"]
# A scored bucket is the published universe's own age in real days, not speed-days: no speed-8 universe
# is 8-90 days old (band_audit, row 18), so the speed-day buckets mixed weeks-old worlds with 2-year ones.
AGE_BUCKETS = [(0, 15), (64, 129), (128, 257), (256, 513), (512, math.inf)]
AGE_NAMES = ["0-14", "64-128", "128-256", "256-512", "512+"]
SCORE_TYPES = {"total": 0, "eco_share": 1, "res_share": 2, "mil_share": 3}
MIN_CELL = 30
SAMPLE_MAX = 2000
POSITIONS = 15
# scripts -> rl -> AI (the module's own data) and scripts -> rl -> AI -> Modules -> the repo (its run artifacts)
DEFAULT_SRC = Path(__file__).resolve().parents[2] / "storage/rl/external/sources"
DEFAULT_OUT = Path(__file__).resolve().parents[4] / "storage/rl/ogsim/real-bands.json"


def rows(path: Path):
    """Stream one normalized JSONL file, skipping blank or broken lines."""
    with path.open(encoding="utf-8", errors="replace") as handle:
        for line in handle:
            line = line.strip()
            if not line:
                continue
            try:
                yield json.loads(line)
            except json.JSONDecodeError:
                continue


def percentile(values: list[float], q: float) -> float:
    """Linear-interpolated percentile; 0 for an empty list."""
    if not values:
        return 0.0
    ordered = sorted(values)
    k = (len(ordered) - 1) * q / 100.0
    lo = int(math.floor(k))
    hi = min(lo + 1, len(ordered) - 1)
    return ordered[lo] + (ordered[hi] - ordered[lo]) * (k - lo)


def band_of(percentile_position: float) -> str:
    if percentile_position < 0.10:
        return "top10"
    if percentile_position < 0.50:
        return "mid"
    return "bottom"


def bucket_of(speed_days: float) -> str:
    for (low, high), name in zip(BUCKETS, BUCKET_NAMES):
        if low <= speed_days < high:
            return name
    return BUCKET_NAMES[-1]


def age_bucket_of(age_days: float) -> str | None:
    """The universe's age bucket; None in the 14-64 day gap, where no published speed-8 universe exists."""
    for (low, high), name in zip(AGE_BUCKETS, AGE_NAMES):
        if low <= age_days < high:
            return name
    return None


def epoch(iso: str) -> float:
    return dt.datetime.fromisoformat(iso.replace("Z", "+00:00")).timestamp()


def universe_of(directory: Path) -> dict | None:
    """The universe's speed, snapshot time and speed-days at capture; None when it cannot be aged."""
    server = next(rows(directory / "server.jsonl"), None)
    if server is None or not server.get("speed") or not server.get("captured_at"):
        return None
    # The universe's start is the oldest alliance still standing: an estimate, and the only one the
    # snapshot offers (a universe's own opening date is not in PUB).
    found = [a["found_date"] for a in rows(directory / "alliance.jsonl") if a.get("found_date")]
    if not found:
        return None
    speed = float(server["speed"])
    speed_days = (epoch(server["captured_at"]) - min(found)) / 86_400.0 * speed
    if speed_days <= 0:
        return None
    return {"speed": speed, "speed_days": speed_days, "captured_at": server["captured_at"]}


def players_of(directory: Path) -> tuple[dict[str, str], dict[str, bool]]:
    """Status and alliance per player id."""
    status: dict[str, str] = {}
    allied: dict[str, bool] = {}
    for player in rows(directory / "player.jsonl"):
        pid = player.get("player_id")
        if not pid:
            continue
        status[pid] = player.get("rank_status", "")
        allied[pid] = player.get("alliance_id") is not None
    return status, allied


def scores_of(directory: Path) -> tuple[dict[str, dict[int, list[float]]], int]:
    """Score and position per player per score type, and how many players the highscore ranks."""
    scores: dict[str, dict[int, list[float]]] = {}
    ranked = 0
    for point in rows(directory / "score.jsonl"):
        pid = point.get("player_id")
        kind = point.get("score_type")
        if pid is None or kind is None:
            continue
        scores.setdefault(pid, {})[kind] = [float(point.get("score") or 0), float(point.get("position") or 0)]
        if kind == 0 and point.get("position"):
            ranked += 1
    return scores, ranked


def planets_of(directory: Path) -> tuple[dict[str, list[int]], list[int]]:
    """Per owner: planets, moons, distinct galaxies. Also the position histogram over owned planets."""
    owned: dict[str, list[int]] = collections.defaultdict(lambda: [0, 0, 0])
    galaxies: dict[str, set[int]] = collections.defaultdict(set)
    slots = [0] * (POSITIONS + 1)
    for planet in rows(directory / "planet.jsonl"):
        owner = planet.get("owner_id")
        if not owner:
            continue
        entry = owned[owner]
        entry[0] += 1
        entry[1] += 1 if planet.get("has_moon") else 0
        if planet.get("galaxy") is not None:
            galaxies[owner].add(int(planet["galaxy"]))
        position = int(planet.get("position") or 0)
        if 0 < position <= POSITIONS:
            slots[position] += 1
    for owner, entry in owned.items():
        entry[2] = len(galaxies[owner])
    return owned, slots


def account_measures(directory: Path, universe: dict, status: dict, allied: dict, scores: dict, ranked: int,
                     owned: dict[str, list[int]]) -> list[tuple[str, str, list[float]]]:
    """One (band, bucket, measure vector) per active player with a real score."""
    out = []
    for pid, by_kind in scores.items():
        if status.get(pid, "missing") != "":
            continue  # a status of i/I/v/o is not an active player: counted in the rank, never in a band
        total, position = by_kind.get(0, [0.0, 0.0])
        if not position or total <= 0:
            continue  # unranked or a zero score says nothing about how a player plays
        planets = owned.get(pid, [0, 0, 0])
        vector = [
            total / universe["speed_days"],
            by_kind.get(1, [0.0, 0.0])[0] / total,
            by_kind.get(2, [0.0, 0.0])[0] / total,
            by_kind.get(3, [0.0, 0.0])[0] / total,
            float(planets[0]),
            float(planets[1]),
            float(planets[2]),
            1.0 if allied.get(pid) else 0.0,
        ]
        out.append((band_of(position / max(ranked, 1)), bucket_of(universe["speed_days"]), vector))
    return out


def activity_bands(src: Path) -> dict:
    """Share of hours whose total score rose, per rank band, from the OStats hourly curves.

    515 of the 561 published curves repeat one score for all 24 hours: that is one snapshot broadcast, not a
    player who did not grow, so those series carry no information about a day's rhythm and are counted apart.
    """
    catalogue = {}
    for universe in rows(src / "OSTATS/normalized/universe_catalogue.jsonl"):
        if universe.get("universe"):
            catalogue[universe["universe"]] = int(universe.get("players") or 0)
    hourly: dict[tuple[str, str], dict[str, float]] = collections.defaultdict(dict)
    rank: dict[tuple[str, str], float] = {}
    for point in rows(src / "OSTATS/normalized/score_point.jsonl"):
        if point.get("score_type") != 0 or point.get("universe") not in catalogue:
            continue
        observed, score = point.get("observed_at"), point.get("score")
        if not observed or score is None:
            continue
        key = (point["universe"], point["player_id"])
        hourly[key][observed] = float(score)
        if point.get("position"):
            rank[key] = float(point["position"])
    rose: dict[str, list[float]] = collections.defaultdict(list)
    flat = 0
    for key, series in hourly.items():
        if len(series) < 24 or key not in rank or not catalogue[key[0]]:
            continue  # a full day of hours or it says nothing about a day's rhythm
        if len(set(series.values())) < 2:
            flat += 1
            continue
        stamps = sorted(series)
        changes = [series[b] > series[a] for a, b in zip(stamps, stamps[1:])]
        rose[band_of(rank[key] / catalogue[key[0]])].append(sum(changes) / len(changes))
    bands = {band: {"p10": round(percentile(v, 10), 4), "p50": round(percentile(v, 50), 4),
                    "p90": round(percentile(v, 90), 4), "n": len(v)} for band, v in rose.items()}
    bands["series"] = {"varying": sum(len(v) for v in rose.values()), "flat_snapshot": flat}
    return bands


def cells_from(samples: dict[str, list[list[float]]]) -> dict[str, dict]:
    """The §9.2 cell table, with the raw sample kept for the classifier."""
    cells = {}
    for key, vectors in samples.items():
        if not vectors:
            continue
        columns = list(zip(*vectors))
        cells[key] = {
            "n": len(vectors),
            "p10": {m: round(percentile(list(columns[i]), 10), 6) for i, m in enumerate(MEASURES)},
            "p50": {m: round(percentile(list(columns[i]), 50), 6) for i, m in enumerate(MEASURES)},
            "p90": {m: round(percentile(list(columns[i]), 90), 6) for i, m in enumerate(MEASURES)},
            "sample": [list(v) for v in vectors[:SAMPLE_MAX]],
        }
    return cells


def build(src: Path) -> dict:
    samples: dict[str, list[list[float]]] = collections.defaultdict(list)
    slots = [0] * (POSITIONS + 1)
    dropped = {"no_alliance_universes": 0, "status": collections.Counter()}
    pooled: dict[str, list[list[float]]] = collections.defaultdict(list)
    speed_days_cells: dict[str, list[list[float]]] = collections.defaultdict(list)
    universes = 0
    for directory in sorted((src).glob("PUB-*/normalized")):
        universe = universe_of(directory)
        if universe is None:
            dropped["no_alliance_universes"] += 1
            continue
        universes += 1
        status, allied = players_of(directory)
        dropped["status"].update(status.values())
        scores, ranked = scores_of(directory)
        owned, universe_slots = planets_of(directory)
        for position, count in enumerate(universe_slots):
            slots[position] += count
        age_bucket = age_bucket_of(universe["speed_days"] / universe["speed"])
        for band, bucket, vector in account_measures(directory, universe, status, allied, scores, ranked, owned):
            pooled[band].append(vector)
            if universe["speed"] != 8 or age_bucket is None:
                speed_days_cells[f"{band}|{bucket}"].append(vector)
                continue
            samples[f"{band}|{age_bucket}"].append(vector)
    return {
        "version": 1,
        "made": dt.date.today().isoformat(),
        "measures": MEASURES,
        "universes": universes,
        "cells": cells_from(samples),
        "speed_days": cells_from(speed_days_cells),
        "pooled": cells_from(pooled),
        "activity": activity_bands(src),
        "positions": [round(count / max(sum(slots), 1), 6) for count in slots[1:]],
        "dropped": {"no_alliance_universes": dropped["no_alliance_universes"],
                    "status": dict(dropped["status"].most_common())},
    }


def print_table(bands: dict) -> None:
    """The pooled table: one line per rank band, directly comparable with §2 of the plan."""
    head = f"{'band':8}{'n':>7}  {'mil p10/p50/p90':>20}  {'eco share':>20}  {'res share':>20}  {'planets':>12} {'moons':>10}"
    print(head)
    for band in BANDS:
        cell = bands.get(band)
        if not cell:
            continue
        triple = lambda m: "/".join(f"{cell[p][m]:.3f}" for p in ("p10", "p50", "p90"))  # noqa: E731
        counts = lambda m: "/".join(f"{cell[p][m]:.0f}" for p in ("p10", "p50", "p90"))  # noqa: E731
        print(f"{band:8}{cell['n']:>7}  {triple('mil_share'):>20}  {triple('eco_share'):>20}  "
              f"{triple('res_share'):>20}  {counts('planets'):>12} {counts('moons'):>10}")


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    parser.add_argument("--src", type=Path, default=DEFAULT_SRC)
    parser.add_argument("--out", type=Path, default=DEFAULT_OUT)
    parser.add_argument("--print", action="store_true", help="print the pooled table only")
    args = parser.parse_args()

    bands = build(args.src)
    print(f"{bands['universes']} universes, {bands['dropped']['no_alliance_universes']} dropped (no alliance to age them)")
    usable = [k for k, v in bands["cells"].items() if v["n"] >= MIN_CELL]
    thin = [k for k, v in bands["cells"].items() if v["n"] < MIN_CELL]
    print(f"cells: {len(bands['cells'])} ({len(usable)} usable, {len(thin)} under n={MIN_CELL})")
    print("scored: the universe-age buckets at speed 8. Not scored, the speed-days buckets the other"
          " speeds keep: " + ", ".join(f"{key} n {cell['n']}" for key, cell in bands["speed_days"].items()))
    print_table(bands["pooled"])
    print("activity (share of hours with score growth):",
          {b: (v["p50"], v["n"]) for b, v in bands["activity"].items() if b in BANDS})
    print("ostats series:", bands["activity"]["series"])
    if args.print:
        return
    args.out.parent.mkdir(parents=True, exist_ok=True)
    args.out.write_text(json.dumps(bands, separators=(",", ":")))
    print(f"wrote {args.out} ({args.out.stat().st_size / 1e6:.2f} MB)")


if __name__ == "__main__":
    main()
