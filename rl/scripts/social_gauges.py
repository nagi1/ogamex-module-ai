#!/usr/bin/env python3
"""Social gauges for ogsim season dirs, against the real bands of plan/rl/SOCIAL-STEP1-AUDIT.md.

    social_gauges.py [--day D] DIR [DIR ...]

Per dir, over its seasons (median, min-max). Structure is read at day D (default: the last day), the war graph over the
whole season. Real targets: speed-8 universes 128-256 days old (p50, p10-p90); OGMem for the war shares.

Structure: share of active players allied, largest alliance / active, alliances with >=3 members per 100 active, top
alliance's share of points, distinct sides in the top 10, rank-1 share of points, rank-1 / rank-10.
War graph (fights with mission attack; moon shots at mates, `mate_target`, are not hostility):
  hostile/active/day - hostile attacks per active account per day
  inter_ally         - share of hostile attacks where both sides are in (different) alliances   (OGMem 0.64)
  intra_ally         - share of hostile attacks between members of one alliance                  (OGMem 0.00)
  balance            - share of balanced triads in the signed graph: + same alliance, - attacked (Pardus: balance holds)
  at_top10           - share of hostile attacks aimed at the day's top-10 by points
"""
from __future__ import annotations

import argparse
import collections
import glob
import itertools
import json
import os
import statistics as st

REAL = {  # p10, p50, p90 (speed 8, 128-256 days) or a single OGMem share
    "ally_share": (0.57, 0.69, 0.81), "largest_of_active": (0.13, 0.18, 0.24), "clubs3_per100": (5.3, 5.7, 9.8),
    "top_ally_pts": (0.30, 0.49, 0.64), "top10_sides": (2.7, 4, 6), "rank1_share": (0.14, 0.20, 0.23),
    "r1_r10": (3.1, 5.5, 13.6), "inter_ally": (None, 0.64, None), "intra_ally": (None, 0.0, None),
}


def season(path: str, day_at: int | None):
    days: dict[int, dict[int, dict]] = collections.defaultdict(dict)
    for line in open(path):
        try:
            r = json.loads(line)
        except json.JSONDecodeError:
            return None
        if "day" in r and "account" in r and "score" in r:
            days[r["day"]][r["account"]] = {"m": bool(r.get("alliance")), "inactive": r.get("inactive"),
                                           "pts": r["score"]["total"]}
    if not days:
        return None
    try:
        levels = json.load(open(path + ".levels.json"))
        club = {a.get("account", i): (a.get("alliance_id") or 0) for i, a in enumerate(levels)}
    except (OSError, json.JSONDecodeError):
        club = collections.defaultdict(int)
    d = day_at if day_at in days else max(days)
    rows = days[d]
    active = [a for a, r in rows.items() if not r["inactive"]]
    pts = {a: rows[a]["pts"] for a in active}
    total = sum(pts.values()) or 1
    side = {a: (club[a] if rows[a]["m"] else None) for a in active}
    members = collections.defaultdict(list)
    for a in active:
        if side[a] is not None:
            members[side[a]].append(a)
    sizes = sorted((len(m) for m in members.values()), reverse=True)
    apts = {k: sum(pts[a] for a in m) for k, m in members.items()}
    order = sorted(active, key=lambda a: -pts[a])
    t10 = order[:10]
    out = {
        "ally_share": sum(sizes) / len(active),
        "largest_of_active": (sizes[0] if sizes else 0) / len(active),
        "clubs3_per100": 100 * sum(1 for s in sizes if s >= 3) / len(active),
        "top_ally_pts": max(apts.values()) / total if apts else 0.0,
        "top10_sides": len({side[a] for a in t10 if side[a] is not None}) + sum(1 for a in t10 if side[a] is None),
        "rank1_share": pts[order[0]] / total,
        "r1_r10": pts[order[0]] / max(pts[order[9]], 1),
    }
    # War graph over the season.
    top10_by_day = {k: set(sorted(v, key=lambda a: -v[a]["pts"])[:10]) for k, v in days.items()}
    hostile, inter, intra, at_top = 0, 0, 0, 0
    enemy: set[tuple[int, int]] = set()
    try:
        for line in open(path + ".battles.jsonl"):
            b = json.loads(line)
            if b.get("mission") != "attack" or b.get("mate_target"):
                continue
            x, y, k = b["attacker"], b["defender"], b["day"]
            if x == y:
                continue
            hostile += 1
            today = days.get(k, {})
            mx, my = today.get(x, {}).get("m"), today.get(y, {}).get("m")
            if mx and my:
                if club[x] == club[y]:
                    intra += 1
                else:
                    inter += 1
            at_top += y in top10_by_day.get(k, ())
            enemy.add((min(x, y), max(x, y)))
    except OSError:
        pass
    n_active_days = sum(sum(1 for r in v.values() if not r["inactive"]) for v in days.values()) or 1
    out["hostile_per_active_day"] = hostile / n_active_days
    out["inter_ally"] = inter / hostile if hostile else float("nan")
    out["intra_ally"] = intra / hostile if hostile else float("nan")
    out["at_top10"] = at_top / hostile if hostile else float("nan")
    # Structural balance over closed signed triads among active accounts at day d.
    def sign(a, b):
        if side.get(a) is not None and side.get(a) == side.get(b):
            return 1
        return -1 if (min(a, b), max(a, b)) in enemy else 0
    balanced = closed = 0
    for a, b, c in itertools.combinations(active, 3):
        s = sign(a, b) * sign(b, c) * sign(a, c)
        if s:
            closed += 1
            balanced += s > 0
    out["balance"] = balanced / closed if closed else float("nan")
    out["closed_triads"] = closed
    return out


def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("--day", type=int)
    ap.add_argument("dirs", nargs="+")
    args = ap.parse_args()
    for d in args.dirs:
        files = [f for f in sorted(glob.glob(f"{d}/ready-*.jsonl")) if not f.endswith((".battles.jsonl", ".diplomacy.jsonl"))]
        # The simulator's own gauges (arm `social`) when present: exact accounts and live alliance ids. The file fallback
        # below reads alliance ids from levels.json, which is wrong under `standing` (every id null), so it is a
        # cross-check only.
        rust = [json.load(open(f + ".social.json")) for f in files if args.day is None and os.path.exists(f + ".social.json")]
        res = rust or [r for f in files if (r := season(f, args.day))]
        if rust and len(rust) < len(files):
            print(f"{d}: {len(files) - len(rust)} season(s) without .social.json (unfinished) left out")
        if not res:
            print(f"{d}: no seasons")
            continue
        print(f"== {d}  seasons={len(res)}")
        for k in res[0]:
            v = sorted(x[k] for x in res if x.get(k) is not None and x[k] == x[k])
            if not v:
                continue
            real = REAL.get(k)
            target = f"real {real[1]}" + (f" ({real[0]}-{real[2]})" if real and real[0] is not None else "") if real else ""
            print(f"  {k:24s} {st.median(v):9.3f}  [{v[0]:.3f}-{v[-1]:.3f}]  {target}")


if __name__ == "__main__":
    main()
