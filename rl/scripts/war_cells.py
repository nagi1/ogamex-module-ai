#!/usr/bin/env python3
"""War cells: is the model's war play realistic? Measures from real battle reports (OGMEM), off HDS by default.

Stage B of plan/rl/STRONG-PLAYER-PLAN.md ("5 Nov: program"). hds.py scores growth and score shares; these cells score
how an account fights, against the OGMEM archive (rl/scripts/ogmem_strategies.py -> ogmem-strategies.json, key
`battles`: one record per non-simulated report, in the same shape the simulator is asked to emit).

Per attack (scope: attacks sent by an account in the top10 band on that day):
* `odds`        attacker value / defender value when the fight starts (ships + defence + satellites, base prices).
* `loss_ratio`  value the attacker lost / value the defender lost (fights where the defender lost something).
* `line_share`  value share of line hulls (cruiser, battleship, battlecruiser, bomber, destroyer, reaper, deathstar)
                in the attacking fleet, per attack-fleet value tier.
* `light_share` value share of light fighters + heavy fighters, per tier.
* `ds_mixed`    1 when Deathstars are 5-90% of the attacking fleet's value: real players send a pure stack or none.
Per planet (scope: planets of top10 accounts on their last day):
* `satellites`  solar satellites on the planet.
* `defence`     defence units on the planet (401-408); the documented ceiling (20,000, defence-doctrines.yaml
                `standing_wall_ceiling`) is shown next to it.

The archive's bias, and how the cells live with it. OGMEM collects reports players chose to publish: big fleet crashes,
not farming. Its 186 battles with a known attacker have no empty target, and only 5 have odds above 100. So:
* per-attack cells score only *contested* attacks (the defender holds at least 1% of the attacker's value, odds
  <= 100); farming raids on near-empty targets are counted and shown but not scored: the archive cannot judge them;
* composition is scored only in tiers with >= 30 real attacks (1e8-1e9, 1e9-1e10, >= 1e10); under 1e8 the archive has
  8 attacks;
* hit planets are a biased sample of planets (an attacker picks a weak or a fleet-holding target, and a fleet caught
  on a moon has no satellites), so `satellites` and `defence` read "is this planet's stock within what real targets
  hold", a coarse check (both are near zero-inflated) that catches millions of units, not fine differences.

A band is the real p10-p90 (nearest rank, as ogmem_strategies.py). The self-test scores every real battle and planet
against bands built from the others (leave-one-out) and an even/odd split: a continuous measure (odds, loss_ratio)
must land in 0.70-0.90; a cell with a point mass at a band edge (line share 1.0, zero light hulls / satellites /
defence) reads about 0.90 and must stay >= 0.75.

    .venv-rl/bin/python rl/scripts/war_cells.py --self-test
"""
from __future__ import annotations

import argparse
import collections
import json
import statistics
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from ogmem_strategies import ID, PRICE  # noqa: E402  (same directory)

DEFAULT_REAL = Path(__file__).resolve().parents[4] / "storage/rl/ogsim/r8w/ogmem-strategies.json"
PRICE_BY_ID = {ID[name]: price for name, price in PRICE.items()}
DEFENCE_IDS = set(range(401, 409))
SATELLITE = 212
LINE = {206, 207, 211, 213, 214, 215, 218}
LIGHT = {204, 205}
DEATHSTAR = 214
# A Deathstar attack is a pure stack or none (OGMEM 5 Nov: 10/11 attacks with Deathstars are >= 90% Deathstar value; the
# one mixed fleet was lost whole). `ds_mixed` is 1 for an attack whose Deathstar share sits in (5%, 90%).
DS_MIXED = (0.05, 0.90)
TIERS = [(0.0, 1e8), (1e8, 1e9), (1e9, 1e10), (1e10, float("inf"))]
TIER_NAMES = ["<1e8", "1e8-1e9", "1e9-1e10", ">=1e10"]
MAX_ODDS = 100.0          # contested: the defender holds >= 1% of the attacker's value
MIN_REAL = 30             # a band needs this many real values
WALL_CEILING = 20_000     # resources/behavior/defence-doctrines.yaml standing_wall_ceiling
CONTINUOUS = ("odds", "loss_ratio")
# Cells with a point mass at a band edge (line share 1.0, no light hulls, no satellites, no defence): ties at the edge
# count as inside, so they read about 0.9 on real data. They must not fall under 0.75.
POINT_MASS = ("line_share", "light_share", "satellites", "defence", "ds_mixed")


def value(units: dict) -> float:
    return sum(float(n) * PRICE_BY_ID.get(int(u), 0.0) for u, n in units.items())


def tier_of(v: float) -> str:
    for (lo, hi), name in zip(TIERS, TIER_NAMES):
        if lo <= v < hi:
            return name
    return TIER_NAMES[-1]


def battle_measures(b: dict) -> dict | None:
    """The per-attack measures of one fight record; None when it is not a contested attack."""
    # A shot at an alliance mate's planet is a moon shot (lost on purpose for the debris), not a contested attack;
    # the archive holds none, so it is not scored here.
    if b.get("mate_target"):
        return None
    att, dfd = b.get("attacker_units") or {}, b.get("defender_units") or {}
    fleet = {u: n for u, n in att.items() if int(u) not in DEFENCE_IDS and int(u) != SATELLITE}
    va, vd = value(fleet), value(dfd)
    if va <= 0 or vd <= 0 or va / vd > MAX_ODDS:
        return None
    out = {"odds": va / vd, "tier": tier_of(va),
           "line_share": value({u: n for u, n in fleet.items() if int(u) in LINE}) / va,
           "light_share": value({u: n for u, n in fleet.items() if int(u) in LIGHT}) / va}
    ds = value({u: n for u, n in fleet.items() if int(u) == DEATHSTAR}) / va
    out["ds_mixed"] = float(DS_MIXED[0] < ds < DS_MIXED[1])
    lost_d = value(b.get("defender_lost") or {})
    if lost_d > 0:
        out["loss_ratio"] = value(b.get("attacker_lost") or {}) / lost_d
    return out


def planet_measures(units: dict) -> dict:
    return {"satellites": float(units.get(str(SATELLITE), units.get(SATELLITE, 0)) or 0),
            "defence": float(sum(n for u, n in units.items() if int(u) in DEFENCE_IDS))}


def keys_of(m: dict, kind: str) -> list[tuple[str, float]]:
    """(cell key, value) pairs a measure dict contributes; composition cells are per tier."""
    if kind == "planet":
        return [("satellites", m["satellites"]), ("defence", m["defence"])]
    out = [("odds", m["odds"])]
    if "loss_ratio" in m:
        out.append(("loss_ratio", m["loss_ratio"]))
    out += [(f"line_share|{m['tier']}", m["line_share"]), (f"light_share|{m['tier']}", m["light_share"])]
    out.append(("ds_mixed", m["ds_mixed"]))
    return out


def q(xs: list[float], p: float) -> float:
    xs = sorted(xs)
    return xs[min(len(xs) - 1, int(p * len(xs)))]


def collect(battles: list[dict], planets: list[dict]) -> dict[str, list[float]]:
    values = collections.defaultdict(list)
    for b in battles:
        m = battle_measures(b)
        if m:
            for key, v in keys_of(m, "battle"):
                values[key].append(v)
    for units in planets:
        for key, v in keys_of(planet_measures(units), "planet"):
            values[key].append(v)
    return values


def bands_of(values: dict[str, list[float]]) -> dict[str, dict]:
    return {k: {"n": len(v), "p10": q(v, .1), "p50": q(v, .5), "p90": q(v, .9)}
            for k, v in sorted(values.items()) if len(v) >= MIN_REAL}


def real_reference(path: Path = DEFAULT_REAL) -> tuple[list[dict], list[dict]]:
    """The real battles (simulator-shaped records) and hit planets (defender units) from ogmem-strategies.json."""
    data = json.loads(path.read_text())
    battles = data.get("battles", [])
    return battles, [b["defender_units"] for b in battles]


def score(values: dict[str, list[float]], bands: dict[str, dict]) -> dict:
    """In-band share per cell; a cell without own values reads 'no data', one without a real band 'no band'."""
    cells, pairs, inside = {}, 0, 0
    for key in sorted(set(values) | set(bands)):
        ours, band = values.get(key, []), bands.get(key)
        if band is None:
            cells[key] = {"n": len(ours), "status": "no band (real n < %d)" % MIN_REAL}
            continue
        if not ours:
            cells[key] = {"n": 0, "status": "no data", "real": band}
            continue
        good = sum(1 for v in ours if band["p10"] <= v <= band["p90"])
        pairs += len(ours)
        inside += good
        cells[key] = {"n": len(ours), "status": "scored", "in_band": good / len(ours), "p50": q(ours, .5),
                      "real": band}
    return {"cells": cells, "war_in_band": inside / pairs if pairs else None, "pairs": pairs}


def print_score(result: dict, label: str, extra: dict | None = None) -> None:
    print(f"\n== war cells: {label} (OGMEM bands; reported apart, not part of HDS/AUC)")
    print(f"{'cell':22}{'n':>8}{'ours p50':>12}{'real p10':>12}{'real p50':>12}{'real p90':>12}  in-band")
    for key, c in result["cells"].items():
        real = c.get("real")
        r = f"{real['p10']:>12.3g}{real['p50']:>12.3g}{real['p90']:>12.3g}" if real else " " * 36
        if c["status"] == "scored":
            print(f"{key:22}{c['n']:>8}{c['p50']:>12.3g}{r}  {c['in_band']:.2f}")
        else:
            print(f"{key:22}{c['n']:>8}{'':>12}{r}  {c['status']}")
    for k, v in (extra or {}).items():
        print(f"   {k}: {v}")
    share = result["war_in_band"]
    print(f"war in-band share          {'no data' if share is None else f'{share:.3f}'}   over {result['pairs']} pairs")


def self_test(path: Path = DEFAULT_REAL) -> int:
    """Leave-one-out and an even/odd split of the real battles: continuous cells must land in 0.70-0.90."""
    battles, planets = real_reference(path)
    if not battles:
        print(f"no `battles` in {path}: rerun rl/scripts/ogmem_strategies.py")
        return 1
    loo = collections.defaultdict(lambda: [0, 0])
    for i, b in enumerate(battles):
        rest = battles[:i] + battles[i + 1:]
        bands = bands_of(collect(rest, [p["defender_units"] for p in rest]))
        mine = collect([b], [b["defender_units"]])
        for key, vs in mine.items():
            if key in bands:
                for v in vs:
                    loo[key][0] += bands[key]["p10"] <= v <= bands[key]["p90"]
                    loo[key][1] += 1
    even, odd = battles[0::2], battles[1::2]
    split = score(collect(odd, [b["defender_units"] for b in odd]), bands_of(collect(even, [b["defender_units"] for b in even])))
    print(f"\n== war cells self-test: {len(battles)} real battles, leave-one-out and even/odd split")
    print(f"{'cell':22}{'LOO n':>8}{'LOO in-band':>13}{'split n':>9}{'split in-band':>15}")
    ok = True
    for key in sorted(loo):
        good, n = loo[key]
        s = split["cells"].get(key, {})
        sv = f"{s['in_band']:.2f}" if s.get("status") == "scored" else "-"
        print(f"{key:22}{n:>8}{good / n:>13.2f}{s.get('n', 0):>9}{sv:>15}")
        if key.split("|")[0] in CONTINUOUS and n >= MIN_REAL:
            ok &= 0.70 <= good / n <= 0.90
        elif n >= MIN_REAL:
            ok &= good / n >= 0.75
    cont = [loo[k][0] / loo[k][1] for k in loo if k.split("|")[0] in CONTINUOUS and loo[k][1] >= MIN_REAL]
    total = sum(g for g, _ in loo.values()) / sum(n for _, n in loo.values())
    print(f"continuous cells ({', '.join(CONTINUOUS)}): LOO mean {statistics.mean(cont):.3f} (want each 0.70-0.90); "
          f"all cells LOO {total:.3f}; split {split['war_in_band']:.3f}")
    print(f"point-mass cells ({', '.join(POINT_MASS)}): each must be >= 0.75 (ties at a band edge count inside)")
    print(f"war self-test {'PASSED' if ok else 'FAILED'}")
    return 0 if ok else 1


def season_inputs(paths: list[Path], rows: list[dict]) -> tuple[list[dict], list[dict], dict]:
    """Top10 attacks (from `<season>.battles.jsonl`) and top10 planets (from `<season>.levels.json`).

    The band of an account on a day is its rank among every account of its world that day (as hds.py)."""
    from real_bands import band_of  # noqa: E402
    by_world = collections.defaultdict(list)
    for row in rows:
        by_world[(row["seed"], row["day"])].append(row)
    band = {}
    for (seed, day), day_rows in by_world.items():
        ordered = sorted(day_rows, key=lambda r: -r["score"]["total"])
        for rank, row in enumerate(ordered, start=1):
            band[(seed, day, row["account"])] = None if row["inactive"] else band_of(rank / len(ordered))
    battles, planets, info = [], [], collections.Counter()
    for path in paths:
        stem = str(path)[:-3] if str(path).endswith(".xz") else str(path)
        fights = Path(stem + ".battles.jsonl")
        if fights.exists():
            info["battle files"] += 1
            for line in fights.open():
                if not line.strip():
                    continue
                b = json.loads(line)
                if b.get("mission") not in (None, "attack", "acs_attack"):
                    continue
                info["attacks"] += 1
                if band.get((b["seed"], b["day"], b["attacker"])) != "top10":
                    continue
                info["top10 attacks"] += 1
                if battle_measures(b) is None:
                    info["top10 uncontested (not scored)"] += 1
                battles.append(b)
        levels = Path(stem + ".levels.json")
        if levels.exists():
            accounts = json.loads(levels.read_text())
            ordered = sorted(accounts, key=lambda a: -a["total"])
            for rank, acc in enumerate(ordered, start=1):
                if acc["inactive"] or band_of(rank / len(ordered)) != "top10":
                    continue
                for p in acc["planets"]:
                    planets.append(p.get("units", {}))
                    info["top10 planets"] += 1
                    info["top10 planets over the 20,000 wall ceiling"] += planet_measures(p.get("units", {}))["defence"] > WALL_CEILING
    if not info["battle files"]:
        info["battle records"] = "none (<season>.battles.jsonl missing: per-attack cells read 'no data')"
    return battles, planets, dict(info)


def judge(paths: list[Path], rows: list[dict], real: Path = DEFAULT_REAL) -> dict:
    real_battles, real_planets = real_reference(real)
    bands = bands_of(collect(real_battles, real_planets))
    battles, planets, info = season_inputs(paths, rows)
    result = score(collect(battles, planets), bands)
    over = sum(1 for p in real_planets if planet_measures(p)["defence"] > WALL_CEILING)
    info["real hit planets over the 20,000 wall ceiling"] = f"{over}/{len(real_planets)}"
    result["info"] = info
    print_score(result, "this season, top10", info)
    return result


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    parser.add_argument("--real", type=Path, default=DEFAULT_REAL)
    parser.add_argument("--self-test", action="store_true")
    args = parser.parse_args()
    if args.self_test:
        return self_test(args.real)
    battles, planets = real_reference(args.real)
    print_score(score(collect(battles, planets), bands_of(collect(battles, planets))), "real against itself")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
