#!/usr/bin/env python3
"""Replicator check of the simulator's persona population (GAME-THEORY-EVOLUTION-FOR-TRAINING.md, T2 item 5 / E2).

The season's three personas (miner, raider, saver; ogsim/src/players.rs, shares in ogsim/src/season.rs PERSONAS) are
read as strategies of one population game:

1. From season dumps (`ready-S.jsonl` + `.battles.jsonl`) it measures each persona's share and payoff (growth of
   ln total score) per phase (days 1-14, 14-64, 64-128, 128-256) and the pairwise flow matrix: who raids whom, and the
   loot and destroyed ships moved between personas (metal-standard 1 : 1.5 : 2, the sim's own `loot_value` weights).
2. It builds a linear payoff matrix A[i][j] = b_i + k_i * N * M[i][j] (M = net flow to an i-account from one
   j-account, k_i = ln-score growth per resource acquired, b_i = the persona's growth with its observed flow removed)
   and runs replicator dynamics on it: rest points, which one is stable, and a bootstrap over seeds.
3. It classifies accounts by one observable rule shared with the real data - the military share of the highscore -
   at the sim's own persona targets (miner 0.06, saver 0.20, raider 0.30; cut at the midpoints 0.13 and 0.25) - for
   the sim (with a confusion matrix against the persona label) and for the real sample of `real-bands.json`
   (the PUB highscores, types 0-3, the same file hds.py scores against). Real battle participation is not public
   per player, so the raid side of the real data is the OGMEM battle archive, aggregated.

Read-only on every input. Writes a JSON summary with --save.

    .venv-rl/bin/python rl/scripts/replicator.py storage/rl/ogsim/runs/late/spy-a storage/rl/ogsim/runs/late/err-a
"""
from __future__ import annotations

import argparse
import collections
import json
import math
import sys
from concurrent.futures import ProcessPoolExecutor
from pathlib import Path

import numpy as np

sys.path.insert(0, str(Path(__file__).resolve().parent))
from real_bands import MEASURES, age_bucket_of, band_of  # noqa: E402

REPO = Path(__file__).resolve().parents[4]
REAL_BANDS = REPO / "storage/rl/ogsim/real-bands.json"
UNITS = REPO / "storage/rl/ogsim/r8w/units-parity/units-u1.json"
OGMEM = Path(__file__).resolve().parents[2] / "storage/rl/external/sources/OGMEM/normalized/battle.jsonl"
PERSONAS = ("miner", "raider", "saver")
PHASES = ((1, 14), (14, 64), (64, 128), (128, 256))
WEIGHTS = np.array([1.0, 1.5, 2.0])            # the sim's loot_value weights (fitted exactly on spy-a seed 1)
CUTS = (0.13, 0.25)                             # midpoints of the persona military-share targets 0.06 / 0.20 / 0.30
CLASSES = ("eco", "mid", "mil")                 # miner-like, saver-like, raider-like
HOMES = 64


def unit_values() -> dict[str, float]:
    units = json.loads(UNITS.read_text())["units"]
    return {str(u["id"]): float(np.dot(u["price"], WEIGHTS)) for u in units}


def classify(mil_share: float) -> str:
    return CLASSES[0] if mil_share < CUTS[0] else CLASSES[1] if mil_share < CUTS[1] else CLASSES[2]


def extract(path: str) -> dict:
    """One seed: per (account, day) arrays and the battle list, with defender slots mapped to accounts."""
    values = unit_values()
    days = 256
    shape = (HOMES, days + 1)
    arr = {k: np.zeros(shape) for k in ("total", "mil", "loot_in", "loot_out", "produced", "expo", "lost",
                                         "raid_sent", "raid_recv")}
    inactive = np.zeros(shape, dtype=bool)
    persona = [""] * HOMES
    seed = None
    with open(path) as handle:
        for line in handle:
            if not line.strip():
                continue
            r = json.loads(line)
            a, d = r["account"], r["day"]
            if a >= HOMES or d > days:
                continue
            seed = r["seed"]
            persona[a] = r["persona"]
            s, e = r["score"], r["events_today"]
            arr["total"][a, d] = s["total"] or 0.0
            arr["mil"][a, d] = s["military"] or 0.0
            arr["loot_in"][a, d] = r["loot_in"]
            arr["loot_out"][a, d] = r["loot_out"]
            arr["produced"][a, d] = r["produced"]
            arr["expo"][a, d] = r["expedition_in"]
            arr["lost"][a, d] = e.get("ships_lost_value", 0.0)
            arr["raid_sent"][a, d] = e.get("raid_sent", 0)
            arr["raid_recv"][a, d] = e.get("raid_received", 0)
            inactive[a, d] = r["inactive"]
    battles = []
    with open(path + ".battles.jsonl") as handle:
        for line in handle:
            if not line.strip():
                continue
            b = json.loads(line)
            val = lambda units: sum(n * values.get(k, 0.0) for k, n in units.items())  # noqa: E731
            battles.append((b["day"], b["attacker"], b["defender"], float(np.dot(b["loot"], WEIGHTS)),
                            val(b["attacker_lost"]), val(b["defender_lost"]), b["mate_target"], b["mission"]))
    # Defender slots >= 64 are colonies or moons; the log names the slot, not the account. Each is given to the
    # account whose daily loot_out, after its home's fights, has room for that slot's loot on most of its days.
    resid = arr["loot_out"].copy()
    for d, _, t, loot, *_ in battles:
        if t < HOMES and d <= days:
            resid[t, d] -= loot
    by_slot = collections.defaultdict(list)
    for d, _, t, loot, *_ in battles:
        if t >= HOMES and loot > 0 and d <= days:
            by_slot[t].append((d, loot))
    slot_owner, unmapped = {}, 0
    for slot, fights in sorted(by_slot.items(), key=lambda kv: -sum(x[1] for x in kv[1])):
        per_day = collections.defaultdict(float)
        for d, loot in fights:
            per_day[d] += loot
        fit = np.zeros(HOMES)
        for d, loot in per_day.items():
            fit += (resid[:, d] >= loot - 1.0)
        best = int(np.argmax(fit))
        if fit[best] >= 0.8 * len(per_day):
            slot_owner[slot] = best
            for d, loot in per_day.items():
                resid[best, d] -= loot
        else:
            unmapped += 1
    return {"path": path, "seed": seed, "persona": persona, "inactive": inactive, "battles": battles,
            "slot_owner": slot_owner, "unmapped_slots": unmapped, "mapped_slots": len(slot_owner),
            **arr}


def account_of(seed: dict, slot: int) -> int | None:
    return slot if slot < HOMES else seed["slot_owner"].get(slot)


def seed_phase(s: dict, lo: int, hi: int) -> dict:
    """One seed's contribution to a phase: payoffs per persona and the flow matrices between personas."""
    part = {"pay": {p: [] for p in PERSONAS}, "pay_active": {p: [] for p in PERSONAS},
            "acquired": {p: [] for p in PERSONAS}, "unknown": 0.0,
            **{k: np.zeros((3, 3)) for k in ("raids", "loot", "alost", "dlost")}}
    sl = slice(lo + 1, hi + 1)
    for a in range(HOMES):
        p = s["persona"][a]
        g = math.log1p(s["total"][a, hi]) - math.log1p(s["total"][a, lo])
        part["pay"][p].append(g)
        if not s["inactive"][a, hi]:
            part["pay_active"][p].append(g)
        part["acquired"][p].append(s["produced"][a, sl].sum() + s["expo"][a, sl].sum() + s["loot_in"][a, sl].sum())
    for d, att, dfn, lt, al, dl, mate, _ in s["battles"]:
        if not (lo < d <= hi) or mate:
            continue
        i, j = account_of(s, att), account_of(s, dfn)
        if i is None or j is None:
            part["unknown"] += lt
            continue
        pi, pj = PERSONAS.index(s["persona"][i]), PERSONAS.index(s["persona"][j])
        part["raids"][pi, pj] += 1
        part["loot"][pi, pj] += lt
        part["alost"][pi, pj] += al
        part["dlost"][pi, pj] += dl
    return part


def combine(parts: list[dict]) -> dict:
    """Per phase: persona payoff (mean d ln score), acquired resources, and the flow matrix between personas."""
    pay = {p: sum((x["pay"][p] for x in parts), []) for p in PERSONAS}
    act = {p: sum((x["pay_active"][p] for x in parts), []) for p in PERSONAS}
    acq = {p: sum((x["acquired"][p] for x in parts), []) for p in PERSONAS}
    raids, loot, alost, dlost = (sum(x[k] for x in parts) for k in ("raids", "loot", "alost", "dlost"))
    unknown = sum(x["unknown"] for x in parts)
    n = np.array([len(pay[p]) for p in PERSONAS], dtype=float)  # summed over seeds; per-seed pairs = n_i n_j / S
    S = len(parts)
    pairs = np.outer(n, n) / S
    # Net flow to an i-account from one j-account: what i takes from j minus what i loses attacking j, minus what j
    # takes from i and what i's ships lose defending against j.
    M = (loot - alost - loot.T - dlost.T) / pairs
    return {
        "payoff": {p: float(np.mean(pay[p])) for p in PERSONAS},
        "payoff_sd": {p: float(np.std(pay[p])) for p in PERSONAS},
        "payoff_active": {p: float(np.mean(act[p])) if act[p] else float("nan") for p in PERSONAS},
        "active_share": {p: len(act[p]) / len(pay[p]) for p in PERSONAS},
        "acquired": {p: float(np.mean(acq[p])) for p in PERSONAS},
        "raids": raids.tolist(), "loot": loot.tolist(), "attacker_lost": alost.tolist(),
        "defender_lost": dlost.tolist(), "M": M.tolist(), "n": n.tolist(), "seeds": S,
        "unmapped_loot_share": unknown / max(loot.sum() + unknown, 1.0),
    }


def payoff_matrix(ph: dict) -> tuple[np.ndarray, np.ndarray]:
    n = np.array(ph["n"])
    N = n.sum() / ph["seeds"]
    x_obs = n / n.sum()
    M = np.array(ph["M"])
    pay = np.array([ph["payoff"][p] for p in PERSONAS])
    acq = np.array([ph["acquired"][p] for p in PERSONAS])
    k = pay / np.maximum(acq, 1.0)                        # ln-score growth per resource acquired
    flow = N * (M @ x_obs)
    b = pay - k * flow
    A = b[:, None] + (k[:, None] * N) * M
    return A, x_obs


def replicator(A: np.ndarray, x0: np.ndarray, steps: int = 20000, dt: float = 0.01) -> np.ndarray:
    x = x0.copy()
    for _ in range(steps):
        f = A @ x
        x = x + dt * x * (f - x @ f)
        x = np.clip(x, 0, None)
        x /= x.sum()
    return x


def rest_points(A: np.ndarray) -> list[dict]:
    """Every rest point (by support), with its stability: a rest point is stable when no strategy outside its support
    earns more there (saturated) and the replicator Jacobian on its face has negative eigenvalues."""
    k = A.shape[0]
    found = []
    for mask in range(1, 2 ** k):
        sup = [i for i in range(k) if mask >> i & 1]
        m = len(sup)
        sub = A[np.ix_(sup, sup)]
        # equal payoff on the support, shares sum to 1
        lhs = np.vstack([sub[:-1] - sub[1:], np.ones(m)]) if m > 1 else np.ones((1, 1))
        rhs = np.zeros(m)
        rhs[-1] = 1.0
        try:
            xs = np.linalg.solve(lhs, rhs)
        except np.linalg.LinAlgError:
            continue
        if np.any(xs < -1e-9):
            continue
        x = np.zeros(k)
        x[sup] = xs
        f = A @ x
        mean = x @ f
        saturated = all(f[i] <= mean + 1e-9 for i in range(k) if i not in sup)
        J = np.zeros((k, k))
        for i in range(k):
            for j in range(k):
                J[i, j] = (i == j) * (f[i] - mean) + x[i] * (A[i, j] - (A[:, j] @ x) - f[j])
        # tangent space of the simplex
        P = np.eye(k) - np.ones((k, k)) / k
        eig = np.linalg.eigvals(P @ J @ P)
        eig = sorted(eig, key=lambda e: -e.real)[:k - 1]
        stable = saturated and all(e.real < -1e-9 for e in eig)
        found.append({"x": x.round(3).tolist(), "saturated": saturated, "stable": bool(stable),
                      "eig": [round(float(e.real), 4) for e in eig]})
    return found


def stable_mix(A: np.ndarray) -> np.ndarray:
    """The basin winner: the replicator from the centre and from 30 random interior starts."""
    rng = np.random.default_rng(0)
    ends = [replicator(A, np.ones(3) / 3)] + [replicator(A, rng.dirichlet(np.ones(3))) for _ in range(30)]
    return np.round(np.mean(ends, axis=0), 3)


def sim_behaviour(seeds: list[dict]) -> dict:
    """The observable classification on the sim, hds-style: every tenth day and the last, active accounts, banded in
    their own world; plus the persona label mix of the same rows and the confusion matrix."""
    label = collections.defaultdict(collections.Counter)
    behave = collections.defaultdict(collections.Counter)
    growth = collections.defaultdict(lambda: collections.defaultdict(list))
    confusion = collections.defaultdict(collections.Counter)
    for s in seeds:
        for d in list(range(10, 257, 10)) + [256]:
            order = np.argsort(-s["total"][:, d], kind="stable")
            for rank, a in enumerate(order, start=1):
                if s["inactive"][a, d]:
                    continue
                bucket = age_bucket_of(float(d)) or "15-63"
                cell = f"{band_of(rank / HOMES)}|{bucket}"
                tot = s["total"][a, d]
                cls = classify(s["mil"][a, d] / tot if tot > 0 else 0.0)
                label[cell][s["persona"][a]] += 1
                behave[cell][cls] += 1
                growth[cell][cls].append(tot / (d * 8))
                confusion[s["persona"][a]][cls] += 1
    return {"label": label, "behave": behave, "growth": growth, "confusion": confusion}


def real_behaviour(path: Path = REAL_BANDS) -> dict:
    bands = json.loads(path.read_text())
    gi, mi = MEASURES.index("growth"), MEASURES.index("mil_share")
    out = {}
    for cell, c in bands["cells"].items():
        cls = collections.Counter()
        growth = collections.defaultdict(list)
        for v in c["sample"]:
            k = classify(v[mi])
            cls[k] += 1
            growth[k].append(v[gi])
        out[cell] = {"n": c["n"], "sample": len(c["sample"]), "mix": cls,
                     "growth_p50": {k: float(np.median(g)) for k, g in growth.items()},
                     "mil_p50": c["p50"]["mil_share"]}
    return out


def ogmem_summary(path: Path = OGMEM) -> dict:
    """Real battles (OGMEM reports): loot over the defender's losses+loot, attacker over defender losses."""
    loot_share, ratio, n, looted = [], [], 0, 0
    for line in path.open():
        b = json.loads(line)
        n += 1
        dl, al, lt = -float(b.get("defender_losses") or 0), -float(b.get("attacker_losses") or 0), float(b.get("loot") or 0)
        looted += lt > 0
        if dl + lt > 0:
            loot_share.append(lt / (dl + lt))
        if dl > 0:
            ratio.append(al / dl)
    return {"reports": n, "with_loot": looted / max(n, 1), "loot_over_loot_plus_def_loss_p50": float(np.median(loot_share)),
            "att_loss_over_def_loss_p50": float(np.median(ratio))}


def sim_battle_summary(seeds: list[dict]) -> dict:
    loot_share, ratio, n, looted = [], [], 0, 0
    for s in seeds:
        for d, att, dfn, lt, al, dl, mate, _ in s["battles"]:
            if mate:
                continue
            n += 1
            looted += lt > 0
            if dl + lt > 0:
                loot_share.append(lt / (dl + lt))
            if dl > 0:
                ratio.append(al / dl)
    return {"fights": n, "with_loot": looted / max(n, 1), "loot_over_loot_plus_def_loss_p50": float(np.median(loot_share)),
            "att_loss_over_def_loss_p50": float(np.median(ratio)) if ratio else float("nan")}


def fmt(x) -> str:
    return "/".join(f"{v:.2f}" for v in x)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    parser.add_argument("runs", nargs="+", type=Path, help="run directories holding ready-S.jsonl + .battles.jsonl")
    parser.add_argument("--workers", type=int, default=10)
    parser.add_argument("--boot", type=int, default=200, help="bootstrap resamples over seeds")
    parser.add_argument("--save", type=Path)
    args = parser.parse_args()

    summary = {"personas": PERSONAS, "classes": CLASSES, "cuts": CUTS, "runs": {}}
    real = real_behaviour()
    summary["real"] = {k: {**v, "mix": dict(v["mix"])} for k, v in real.items()}
    summary["ogmem"] = ogmem_summary()
    for run in args.runs:
        files = sorted(str(p) for p in run.glob("ready-*.jsonl") if p.name.count(".") == 1)
        with ProcessPoolExecutor(args.workers) as pool:
            seeds = list(pool.map(extract, files))
        print(f"\n===== {run}  ({len(seeds)} seeds; colony/moon slots mapped {sum(s['mapped_slots'] for s in seeds)}, "
              f"unmapped {sum(s['unmapped_slots'] for s in seeds)})")
        res = {"phases": {}}
        for lo, hi in PHASES:
            name = f"{lo}-{hi}"
            parts = [seed_phase(s, lo, hi) for s in seeds]
            p = combine(parts)
            A, x_obs = payoff_matrix(p)
            rps = rest_points(A)
            mix = stable_mix(A)
            boot = []
            rng = np.random.default_rng(1)
            for _ in range(args.boot):
                Ab, _ = payoff_matrix(combine([parts[i] for i in rng.integers(0, len(parts), len(parts))]))
                boot.append(stable_mix_fast(Ab))
            boot = np.array(boot)
            print(f"\n-- phase {name}: shares (m/r/s) {fmt(x_obs)}  active at end {fmt(p['active_share'].values())}  "
                  f"unmapped loot {p['unmapped_loot_share']:.1%}")
            print("   payoff d ln score  all  ", fmt(p["payoff"].values()), "  active", fmt(p["payoff_active"].values()))
            print("   acquired / account ", " ".join(f"{v:.3g}" for v in p["acquired"].values()))
            print("   raids  (row attacks col, m/r/s):", np.array(p["raids"]).astype(int).tolist())
            print("   loot   (row takes from col)    :", [[f"{v:.3g}" for v in r] for r in p["loot"]])
            print("   ships lost attacking / defending:", [[f"{v:.3g}" for v in r] for r in p["attacker_lost"]],
                  [[f"{v:.3g}" for v in r] for r in p["defender_lost"]])
            print("   payoff matrix A:\n", np.array2string(A, precision=4))
            print("   rest points:", [r for r in rps if r["saturated"]])
            print(f"   stable mix (replicator) {fmt(mix)}  bootstrap p10/p50/p90 per persona: "
                  + "  ".join(f"{PERSONAS[i]} {np.percentile(boot[:, i], 10):.2f}/{np.median(boot[:, i]):.2f}/"
                              f"{np.percentile(boot[:, i], 90):.2f}" for i in range(3)))
            res["phases"][name] = {**p, "A": A.tolist(), "x_obs": x_obs.tolist(), "rest_points": rps,
                                   "stable_mix": mix.tolist(), "boot_p50": np.median(boot, axis=0).tolist(),
                                   "boot_p10": np.percentile(boot, 10, axis=0).tolist(),
                                   "boot_p90": np.percentile(boot, 90, axis=0).tolist()}
        beh = sim_behaviour(seeds)
        print("\n-- behaviour by military share (eco <0.13 / mid / mil >=0.25), sim vs real, per cell")
        print(f"{'cell':18}{'n':>6}  {'sim label m/r/s':>16}  {'sim behaviour e/m/M':>20}  {'real behaviour e/m/M':>21}"
              f"  {'sim g50 e/m/M':>24}  {'real g50 e/m/M':>24}")
        cells = {}
        for cell in sorted(beh["behave"]):
            lab, bh = beh["label"][cell], beh["behave"][cell]
            n = sum(bh.values())
            lmix = [lab[p] / n for p in PERSONAS]
            bmix = [bh[c] / n for c in CLASSES]
            g = [float(np.median(beh["growth"][cell][c])) if beh["growth"][cell][c] else float("nan") for c in CLASSES]
            r = real.get(cell)
            rmix = [r["mix"][c] / r["sample"] for c in CLASSES] if r else None
            rg = [r["growth_p50"].get(c, float("nan")) for c in CLASSES] if r else None
            print(f"{cell:18}{n:>6}  {fmt(lmix):>16}  {fmt(bmix):>20}  {fmt(rmix) if rmix else '-':>21}  "
                  f"{'/'.join(f'{v:,.0f}' for v in g):>24}  {'/'.join(f'{v:,.0f}' for v in rg) if rg else '-':>24}")
            cells[cell] = {"n": n, "label": lmix, "behave": bmix, "real": rmix, "growth": g, "real_growth": rg}
        print("confusion (persona -> behaviour e/m/M):",
              {p: fmt([beh["confusion"][p][c] / max(sum(beh["confusion"][p].values()), 1) for c in CLASSES]) for p in PERSONAS})
        sb = sim_battle_summary(seeds)
        print("sim fights:", sb, "\nreal OGMEM:", summary["ogmem"])
        res["cells"] = cells
        res["confusion"] = {p: dict(beh["confusion"][p]) for p in PERSONAS}
        res["battles"] = sb
        income = {}
        for p in PERSONAS:
            tot = collections.Counter()
            for s in seeds:
                for a in range(HOMES):
                    if s["persona"][a] == p:
                        tot["produced"] += s["produced"][a].sum()
                        tot["expedition"] += s["expo"][a].sum()
                        tot["loot_in"] += s["loot_in"][a].sum()
                        tot["loot_out"] += s["loot_out"][a].sum()
                        tot["lost"] += s["lost"][a].sum()
            income[p] = {k: v / max(tot["produced"], 1) for k, v in tot.items()}
        print("season income over production per persona:", {p: {k: round(v, 3) for k, v in d.items()} for p, d in income.items()})
        res["income"] = income
        summary["runs"][str(run)] = res
    if args.save:
        args.save.write_text(json.dumps(summary, indent=1, default=lambda o: o.tolist() if hasattr(o, "tolist") else str(o)))
    return 0


def stable_mix_fast(A: np.ndarray) -> np.ndarray:
    return replicator(A, np.ones(3) / 3, steps=5000, dt=0.04)


if __name__ == "__main__":
    raise SystemExit(main())
