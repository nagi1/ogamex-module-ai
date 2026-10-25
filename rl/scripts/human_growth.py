"""How fast our accounts grow against real OGame players, per points level, with economy speed taken out.

    .venv-rl/bin/python Modules/AI/rl/scripts/human_growth.py [--ours storage/rl/m5-eval7] [--score-only]

Real side (Modules/AI/storage/rl/external/sources):
  OSTATS  hourly total-points history (category 1, type 0) for a sample of players over one day (8-9 Oct 2026).
  PUB-*   one public-API snapshot per universe (9 Oct 2026): economy speed, every player's total points and status.
Our side: closed-loop recordings {teacher,policy}/choices-*.jsonl; value/1000 = points (score + held resources / 1000),
or with --score-only the account score alone (expm1 of state[3]), which is what a real highscore counts.

Speed normalisation. Production and build/research times both scale linearly with economy speed, so an account on
speed s at hour h is, to first order, a speed-1 account at "speed-hour" s*h, and points do not need rescaling.
Every rate here is therefore points per speed-hour: (points/hour) / economy speed. Ours run on speed 8.

Table A (the question as asked): growth over a 23 h window (the span OStats gives per player), binned by the points
at the window start. Real: OStats players whose status is active ('' or 'o') and whose universe speed PUB knows.
Table B (what fills the early bins OStats lacks): mean rate since start, P / (age * speed). Real: active players in
universes opened at most --fresh-days before the snapshot, age = universe age (a player who joined later has a
smaller true age, so the real rate is a lower bound; the upper quantiles are close to day-one starters).
Table C: points at matched speed-age, ours at the hour that matches each fresh universe's age * speed.
"""

import argparse
import collections
import datetime
import glob
import json
import math
from pathlib import Path

import numpy as np

AI = Path(__file__).resolve().parents[2]
ROOT = AI.parents[1]
ACTIVE = {"", "o"}
OUR_SPEED = 8
WINDOW_H = 23


def ts(text):
    return datetime.datetime.fromisoformat(text.replace("Z", "+00:00").replace("+0000", "+00:00")).timestamp()


def jsonl(path):
    with open(path) as fh:
        for line in fh:
            yield json.loads(line)


def bin_of(points):
    """Half-decade bins in log10 points; bin k covers [10^(k/2), 10^((k+1)/2))."""
    return int(math.floor(2 * math.log10(max(points, 1.0))))


def bin_label(k):
    lo, hi = 10 ** (k / 2), 10 ** ((k + 1) / 2)
    return f"{fmt(lo)}-{fmt(hi)}"


def fmt(x):
    if x is None or (isinstance(x, float) and math.isnan(x)):
        return "-"
    a = abs(x)
    for div, suf in ((1e9, "G"), (1e6, "M"), (1e3, "k")):
        if a >= div:
            return f"{x / div:.3g}{suf}"
    return f"{x:.3g}"


def q(vals):
    if not len(vals):
        return None
    p10, p50, p90 = np.percentile(np.asarray(vals, float), [10, 50, 90])
    return p10, p50, p90


# ---------------------------------------------------------------- real data

def universe_tables(src):
    speed, captured = {}, {}
    for f in glob.glob(str(src / "PUB-*/normalized/server.jsonl")):
        r = next(jsonl(f))
        u = f"s{r['server_number']}-{r['language']}"
        speed[u], captured[u] = r["speed"], ts(r["captured_at"])
    opened = {r["universe"]: ts(r["opened"]) for r in jsonl(src / "OSTATS/normalized/universe_catalogue.jsonl")}
    return speed, captured, opened


def ostats_windows(src, speed):
    status = {(r["universe"], r["player_id"]): r.get("rank_status") or "" for r in jsonl(src / "OSTATS/normalized/player.jsonl")}
    series = collections.defaultdict(list)
    for r in jsonl(src / "OSTATS/normalized/score_point.jsonl"):
        if r["category"] == 1 and r["score_type"] == 0:
            series[(r["universe"], r["player_id"])].append((ts(r["observed_at"]), r["score"]))
    counts = collections.Counter()
    out = []
    for key, pts in series.items():
        counts["series"] += 1
        st = status.get(key, "?")
        if st not in ACTIVE:
            counts[f"dropped status {st or 'active'}"] += 1
            continue
        if key[0] not in speed:
            counts["dropped no speed (universe not in PUB)"] += 1
            continue
        pts.sort()
        hours = (pts[-1][0] - pts[0][0]) / 3600
        if hours < 12:
            counts["dropped span<12h"] += 1
            continue
        counts["kept"] += 1
        out.append((pts[0][1], (pts[-1][1] - pts[0][1]) / hours / speed[key[0]]))
    return out, counts


def fresh_players(src, speed, captured, opened, fresh_days):
    """(universe, speed, age_h, points) for active players of universes younger than fresh_days."""
    rows = []
    for u, s in speed.items():
        if u not in opened:
            continue
        age_h = (captured[u] - opened[u]) / 3600
        if not 0 < age_h <= fresh_days * 24:
            continue
        d = src / f"PUB-{u.split('-')[1]}{u.split('-')[0][1:]}/normalized"
        status = {r["player_id"]: r.get("rank_status") or "" for r in jsonl(d / "player.jsonl")}
        for r in jsonl(d / "score.jsonl"):
            if r["category"] == 1 and r["score_type"] == 0 and status.get(r["player_id"], "?") in ACTIVE:
                rows.append((u, s, age_h, float(r["score"])))
    return rows


# ---------------------------------------------------------------- our data

def our_series(ours, score_only):
    """{arm: [ (hours array, points array) per account ]}. Arms: planner = teacher run, every seat; learner = policy
    run, learner seats; control = teacher run, the same seats the learner holds in the policy run."""
    arms = collections.defaultdict(list)
    for run in ("teacher", "policy"):
        for f in sorted(glob.glob(str(ours / run / "choices-*.jsonl"))):
            acc = collections.defaultdict(list)
            seats = set()
            t0 = None
            for r in jsonl(f):
                t0 = r["t"] if t0 is None else min(t0, r["t"])
                pts = math.expm1(r["state"][3]) if score_only else r["value"] / 1000
                acc[r["player"]].append((r["t"], pts))
                if r.get("learner"):
                    seats.add(r["player"])
            for player, pts in acc.items():
                pts.sort()
                t = np.array([p[0] for p in pts], float)
                h = (t - t0) / 3600
                v = np.array([p[1] for p in pts], float)
                if run == "teacher":
                    arms["planner"].append((h, v))
                    if player in seats:
                        arms["control"].append((h, v))
                if run == "policy" and player in seats:
                    arms["learner"].append((h, v))
    return arms


def our_windows(series):
    out = []
    for h, v in series:
        grid = np.arange(0, h[-1] - WINDOW_H + 1e-9, 1.0)
        a, b = np.interp(grid, h, v), np.interp(grid + WINDOW_H, h, v)
        out += [(p, (e - p) / WINDOW_H / OUR_SPEED) for p, e in zip(a, b)]
    return out


def our_mean_rates(series, min_h):
    out = []
    for h, v in series:
        grid = np.arange(min_h, h[-1] + 1e-9, 1.0)
        pts = np.interp(grid, h, v)
        out += [(p, p / (g * OUR_SPEED)) for p, g in zip(pts, grid)]
    return out


def points_at(series, hour):
    return [float(np.interp(hour, h, v)) for h, v in series if h[-1] >= hour - 1e-9]


# ---------------------------------------------------------------- report

def binned(pairs):
    d = collections.defaultdict(list)
    for p, r in pairs:
        d[bin_of(p)].append(r)
    return d


def verdict(name, ours_b, real_b):
    """Per shared bin: where our median sits against the real p10..p90 band; summarised by account-weighted bins."""
    inside = faster = slower = 0
    ratios = []
    for k in sorted(set(ours_b) & set(real_b)):
        if len(real_b[k]) < 5:
            continue
        p10, p50, p90 = q(real_b[k])
        m = float(np.median(ours_b[k]))
        if p50 > 0 and m > 0:
            ratios.append(m / p50)
        if m > p90:
            faster += 1
            continue
        if m < p10:
            slower += 1
            continue
        inside += 1
    n = inside + faster + slower
    if not n:
        return f"{name}: no verdict, no points bin holds both our accounts and >=5 real players"
    ratio = f", median {np.exp(np.mean(np.log(ratios))):.2g}x the real median (geo-mean over bins)" if ratios else ""
    call = "within the real p10..p90 band" if inside >= max(faster, slower) else ("faster than real" if faster > slower else "slower than real")
    return f"{name}: {call} ({inside} of {n} bins inside, {faster} above p90, {slower} below p10){ratio}"


def table(title, real_b, arms_b, note):
    print(f"\n## {title}\n{note}\n")
    cols = ["real"] + list(arms_b)
    print("| points bin | " + " | ".join(f"{c} n | {c} p10 / med / p90" for c in cols) + " |")
    print("|---|" + "---|---|" * len(cols))
    keys = sorted(set(real_b).union(*[set(b) for b in arms_b.values()]))
    for k in keys:
        cells = []
        for b in [real_b] + list(arms_b.values()):
            vals = b.get(k, [])
            r = q(vals)
            cells += [str(len(vals)), " / ".join(fmt(x) for x in r) if r else "-"]
        print(f"| {bin_label(k)} | " + " | ".join(cells) + " |")
    print()
    for name, b in arms_b.items():
        print("- " + verdict(name, b, real_b))


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--src", default=str(AI / "storage/rl/external/sources"))
    ap.add_argument("--ours", default=str(ROOT / "storage/rl/m5-eval7"))
    ap.add_argument("--score-only", action="store_true", help="our points = account score, held resources excluded")
    ap.add_argument("--fresh-days", type=float, default=100)
    ap.add_argument("--min-hours", type=float, default=6, help="table B: skip our first hours (rate from start is noisy)")
    a = ap.parse_args()
    src, ours = Path(a.src), Path(a.ours)

    speed, captured, opened = universe_tables(src)
    real_w, counts = ostats_windows(src, speed)
    fresh = fresh_players(src, speed, captured, opened, a.fresh_days)
    arms = our_series(ours, a.score_only)

    print("# Human growth check")
    print(f"ours: {ours} ({', '.join(f'{k} {len(v)} accounts' for k, v in arms.items())}), speed {OUR_SPEED}, "
          f"points = {'account score' if a.score_only else 'value/1000 (score + held resources)'}, "
          f"longest account {max(h[-1] for s in arms.values() for h, _ in s):.1f} h")
    print("OStats series: " + ", ".join(f"{k} {v}" for k, v in sorted(counts.items())))
    unis = sorted({(u, s, round(age / 24, 1)) for u, s, age, _ in fresh}, key=lambda x: (x[2], x[1]))
    print(f"fresh universes (<= {a.fresh_days:g} d, PUB speed known): {len(unis)}, active players {len(fresh)}")
    print("all rates are points per speed-hour = (points/hour) / economy speed")

    table(f"A. growth over {WINDOW_H} h, by points at the window start", binned(real_w),
          {k: binned(our_windows(v)) for k, v in arms.items()},
          "real: OStats hourly history, active players only, one window per player (8-9 Oct 2026)")

    table("B. mean rate since start, P / (age x speed), by current points (biased: real low-points players are the slow ones)", binned([(p, p / (age * s)) for _, s, age, p in fresh]),
          {k: binned(our_mean_rates(v, a.min_hours)) for k, v in arms.items()},
          f"real: PUB snapshot, active players in fresh universes, age = universe age (lower bound); ours from hour {a.min_hours:g}")

    print("\n## C. points at matched speed-age (age x speed): fresh universe vs ours at the same speed-hours\n")
    print("real = active players with points > 0 (accounts that registered and never built are left out)\n")
    longest = max(h[-1] for s in arms.values() for h, _ in s)
    print("| universe | speed | age d | speed-days | our hour | real n | real p10 / med / p90 / max | "
          + " | ".join(f"{k} p10 / med / p90 (med at real pct)" for k in arms) + " |")
    print("|---|---|---|---|---|---|---|" + "---|" * len(arms))
    by_u = collections.defaultdict(list)
    for u, s, age, p in fresh:
        if p > 0:
            by_u[(u, s, age)].append(p)
    calls = collections.defaultdict(list)
    for (u, s, age), pts in sorted(by_u.items(), key=lambda x: x[0][1] * x[0][2]):
        hour = age * s / OUR_SPEED
        if hour > longest:
            continue
        r = np.asarray(pts)
        p10, p50, p90 = np.percentile(r, [10, 50, 90])
        cells = []
        for k, ser in arms.items():
            o10, m, o90 = q(points_at(ser, hour))
            cells.append(f"{fmt(o10)} / {fmt(m)} / {fmt(o90)} (p{100 * np.mean(r <= m):.0f})")
            calls[k].append((u, m, p10, p50, p90))
        print(f"| {u} | {s} | {age / 24:.1f} | {age * s / 24:.1f} | {hour:.0f} | {len(r)} | "
              f"{fmt(p10)} / {fmt(p50)} / {fmt(p90)} / {fmt(r.max())} | " + " | ".join(cells) + " |")
    print("\nVerdict (table C, matched speed-age; the only fair real comparison in our points range):")
    for k, rows in calls.items():
        parts = []
        for u, m, p10, p50, p90 in rows:
            where = "within p10..p90" if p10 <= m <= p90 else ("above p90" if m > p90 else "below p10")
            parts.append(f"{u}: {where}, {m / p50:.2g}x the real median, {m / p90:.2g}x the real p90")
        print(f"- {k}: " + "; ".join(parts))
    print(f"(fresh universes older than our run's {longest:.0f} h at speed {OUR_SPEED} are not compared)")

    print("\nWhat the data cannot tell:")
    print("- OStats holds one day (8-9 Oct 2026) for a sample that is mostly inactive or vacation accounts in old universes;"
          " no player's first days are in it, so table A has no real growth below the bins printed.")
    print("- PUB is a single snapshot: table B/C are cross-sections, not trajectories; a late joiner looks slow, so real"
          " rates there are lower bounds and the upper quantiles stand for day-one starters.")
    print("- Real universes run v13 with lifeforms, classes and Dark Matter purchases, and research speed can differ from"
          " economy speed; the PUB 'speed' field is economy speed only. Points/speed is a first-order normalisation.")
    print("- Ours count held resources in value/1000; --score-only removes them. Real scores never count held resources.")


if __name__ == "__main__":
    main()
