#!/usr/bin/env python3
"""Real OGMem battles against our worlds' battles: fleet scale, loss share, loot, debris, and a held-out loss predictor.

    python3 Modules/AI/rl/scripts/real_battle_calibration.py storage/rl/late/9507 storage/rl/late/9512 ...

Real side: storage/rl/external/sources/OGMEM/normalized (battle.jsonl, battle_unit.jsonl; units before and lost per side).
Sim side: each world's end.sqlite battle_reports (probe-only visits dropped). The predictor is a one-feature line, the
log of the attacker/defender ship-count ratio, fitted on some real universes and scored on the others against "always
predict the mean": it exists to say whether fleet-size ratio carries signal worth a model, not to be one.
"""
from __future__ import annotations

import json
import math
import sqlite3
import statistics
import sys
from collections import defaultdict
from pathlib import Path

REAL = Path(__file__).resolve().parents[2] / "storage/rl/external/sources/OGMEM/normalized"


def quantiles(values: list[float]) -> str:
    if not values:
        return "n=0"
    ordered = sorted(values)
    pick = lambda q: ordered[min(len(ordered) - 1, int(q * len(ordered)))]
    return f"n={len(ordered)} p10={pick(.1):,.0f} p50={pick(.5):,.0f} p90={pick(.9):,.0f} max={ordered[-1]:,.0f}"


def real_battles() -> list[dict]:
    battles = {}
    for line in (REAL / "battle.jsonl").read_text().splitlines():
        row = json.loads(line)
        battles[row["report_id"]] = {"universe": f'{row.get("community")}/{row.get("universe")}', "att": 0, "def": 0, "att_lost": 0, "def_lost": 0,
                                     "attacker_losses": abs(row.get("attacker_losses") or 0), "defender_losses": abs(row.get("defender_losses") or 0),
                                     "loot": row.get("loot") or 0, "debris": row.get("debris") or 0}
    for line in (REAL / "battle_unit.jsonl").read_text().splitlines():
        row = json.loads(line)
        battle = battles.get(row["report_id"])
        if battle is None:
            continue
        side = "att" if row["side"] == "attacker" else "def"
        battle[side] += row.get("before") or 0
        battle[f"{side}_lost"] += abs(row.get("lost") or 0)
    return [b for b in battles.values() if b["att"] > 0 and b["def"] > 0]


def sim_battles(world: Path) -> list[dict]:
    out = []
    db = sqlite3.connect(f"file:{world / 'end.sqlite'}?mode=ro", uri=True)
    for attacker, defender, loot in db.execute("select attacker, defender, loot from battle_reports"):
        a, d, l = json.loads(attacker), json.loads(defender), json.loads(loot or "{}")
        a_units, d_units = a.get("units") or {}, d.get("units") or {}
        if set(a_units) <= {"espionage_probe"} or not d_units:
            continue
        out.append({"att": sum(a_units.values()), "def": sum(d_units.values()),
                    "attacker_losses": float(a.get("resource_loss") or 0), "defender_losses": float(d.get("resource_loss") or 0),
                    "loot": sum(float(l.get(k) or 0) for k in ("metal", "crystal", "deuterium"))})
    return out


def loss_share(battle: dict) -> float | None:
    total = battle["attacker_losses"] + battle["defender_losses"]
    return battle["attacker_losses"] / total if total else None


def describe(name: str, battles: list[dict]) -> None:
    shares = [s for s in (loss_share(b) for b in battles) if s is not None]
    print(f"{name}: {len(battles)} battles")
    print("  attacker ships   ", quantiles([b["att"] for b in battles]))
    print("  defender ships   ", quantiles([b["def"] for b in battles]))
    print("  attacker loss share (own loss / both losses): mean %.3f  median %.3f" % (statistics.mean(shares), statistics.median(shares)) if shares else "  no losses")
    print("  loot             ", quantiles([b["loot"] for b in battles]))


def predictor(real: list[dict]) -> None:
    rows = [(b["universe"], math.log(b["att"] / b["def"]), loss_share(b)) for b in real if loss_share(b) is not None]
    by_universe = defaultdict(list)
    for universe, x, y in rows:
        by_universe[universe].append((x, y))
    universes = sorted(by_universe, key=lambda u: -len(by_universe[u]))
    if len(universes) < 3:
        print("predictor: fewer than 3 universes, skipped")
        return
    errors_line, errors_mean = [], []
    for held in universes:  # leave one universe out: never score a universe the line was fitted on
        train = [pair for u in universes if u != held for pair in by_universe[u]]
        xs = [p[0] for p in train]; ys = [p[1] for p in train]
        mean_x, mean = statistics.mean(xs), statistics.mean(ys)
        variance = sum((v - mean_x) ** 2 for v in xs)
        slope = sum((a - mean_x) * (b - mean) for a, b in zip(xs, ys)) / variance if variance else 0.0
        intercept = mean - slope * mean_x
        for hx, hy in by_universe[held]:
            errors_line.append(abs(slope * hx + intercept - hy))
            errors_mean.append(abs(mean - hy))
    print(f"predictor (leave-one-universe-out over {len(universes)} universes, {len(rows)} battles): "
          f"MAE line {statistics.mean(errors_line):.3f} vs always-the-mean {statistics.mean(errors_mean):.3f}")


def main() -> None:
    real = real_battles()
    describe("real (OGMem)", real)
    sims = [b for world in sys.argv[1:] for b in sim_battles(Path(world))]
    if sims:
        print()
        describe(f"ours ({len(sys.argv) - 1} worlds)", sims)
    print()
    predictor(real)


if __name__ == "__main__":
    main()
