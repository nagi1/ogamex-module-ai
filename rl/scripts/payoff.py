"""Empirical payoff matrix between errand (fleet) policies, with Nash averaging and alpha-Rank.

Track T4 item 3 of Modules/AI/plan/research/ogame/GAME-THEORY-EVOLUTION-FOR-TRAINING.md (Balduzzi et al. 2018, "Re-evaluating
evaluation"; Omidshafiei et al. 2019, "alpha-Rank"). One season seats policy A on accounts 0-31 and B on 32-63 through the
season binary's `errand-pool` / `errand-split`; the swapped split plays the same seed, so the account's start position and
persona cancel between the two (keyed dice: the pair is paired). M[A][B] = mean ln(1 + final score) of A's accounts minus B's,
averaged over both splits; seeds give the bootstrap.

  payoff.py export CKPT OUT.json        errand checkpoint -> policy json (as es_world writes it)
  payoff.py run RUN_DIR [--seeds 1-5] [--par 20] [--only A:B] [--seed-list 1]
  payoff.py analyze RUN_DIR
"""
from __future__ import annotations

import argparse
import itertools
import json
import math
import subprocess
import sys
import time
from pathlib import Path

import numpy as np

ROOT = Path(__file__).resolve().parents[4]
sys.path.insert(0, str(ROOT / "Modules/AI/rl"))

R = ROOT / "storage/rl/ogsim"
BIN = R / "runs/pool/season.bin"
CAPPED = ROOT / "Modules/AI/rl/scripts/capped.sh"
POLICIES = {
    "teacher": "teacher",
    "g14": str(R / "runs/es-late-c3/final-g14.policy.json"),
    "c4": str(R / "runs/bc-late-c4/errand.policy.json"),
    "g20": str(R / "runs/payoff/g20.policy.json"),
}
ORDER = list(POLICIES)
ARMS = ("moonstack reach expedition colonies ships cap lab classes feed factory balance satellites doubler moonfeed standing "
        "labfirst moontaste netchain merchant techtree doctrine acsdefend acsattack wrecks moonraid relocate keyed class=3 spread "
        f"scrap levels policy={R}/runs/train4/best-es3-s10.policy.json sampled exposhield").split()
TAIL = "wide late satcap minerpost anchor freshspy tally".split()
DAYS, ACCOUNTS, HALF = 256, 64, 32


def export(ckpt: str, out: str) -> None:
    import torch
    from ogrl.errand_schema import load_errand, policy_json
    saved = torch.load(ckpt, map_location="cpu", weights_only=False)
    Path(out).write_text(json.dumps(policy_json(saved)))
    back = load_errand(out)  # the round trip es_world relies on
    diff = max(float((back["model"][k] - saved["model"][k]).abs().max()) for k in saved["model"])
    print(f"wrote {out}; reload max |diff| {diff:.3g}")


def jobs(seeds: list[int], only: list[tuple[str, str]] | None) -> list[tuple[str, str, int, int]]:
    """(A, B, seed, swap): A plays 0-31 when swap=0, 32-63 when swap=1. The diagonal needs one split only."""
    pairs = only or [(a, b) for a, b in itertools.combinations_with_replacement(ORDER, 2)]
    return [(a, b, s, w) for a, b in pairs for s in seeds for w in ((0,) if a == b else (0, 1))]


def name(a: str, b: str, s: int, w: int) -> str:
    return f"{a}-{b}-s{s}-w{w}"


def launch(run: Path, a: str, b: str, s: int, w: int) -> str:
    n = name(a, b, s, w)
    split = f"0-{HALF - 1}:0,{HALF}-{ACCOUNTS - 1}:1" if w == 0 else f"0-{HALF - 1}:1,{HALF}-{ACCOUNTS - 1}:0"
    season = [str(BIN), str(R / "r8w-wall"), str(DAYS), str(ACCOUNTS), str(s), str(R / "r8w/units-parity/econ-u1-colonies.json"),
              f"out/{n}.jsonl", *ARMS, f"errand-pool={POLICIES[a]},{POLICIES[b]}", f"errand-split={split}", *TAIL]
    script = (f"cd {run} && SECONDS=0; timeout 540 {' '.join(season)} >/dev/null 2>out/{n}.err; rc=$?; "
              f"echo \"$rc $SECONDS\" > out/{n}.done")
    unit = f"payoff-{n}-{int(time.time())}"
    subprocess.run([str(CAPPED), unit, str(run / f"out/{n}.log"), "bash", "-c", script], check=True, capture_output=True)
    return n


def run(args) -> None:
    run_dir = Path(args.run_dir).resolve()
    (run_dir / "out").mkdir(parents=True, exist_ok=True)
    seeds = [int(x) for x in args.seed_list.split(",")] if args.seed_list else list(range(1, args.seeds + 1))
    only = [tuple(p.split(":")) for p in args.only] if args.only else None
    todo = [j for j in jobs(seeds, only) if not (run_dir / f"out/{name(*j)}.done").exists()]
    clock, live = time.time(), []
    while todo or live:
        live = [n for n in live if not (run_dir / f"out/{n}.done").exists()]
        while todo and len(live) < args.par:
            live.append(launch(run_dir, *todo.pop(0)))
            print(f"[{time.time() - clock:6.0f}s] launched {live[-1]} ({len(todo)} queued)", flush=True)
        time.sleep(10)
    print(f"all done in {time.time() - clock:.0f}s", flush=True)


def finals(path: Path) -> dict[int, float]:
    from ogrl.es_world import finals as es_finals
    return {a: v for a, (v, _) in es_finals(path, ACCOUNTS).items()}


def season_payoff(run: Path, a: str, b: str, s: int, w: int) -> float | None:
    p = run / f"out/{name(a, b, s, w)}.jsonl"
    if not p.exists():
        return None
    ln = finals(p)
    first = [ln[i] for i in range(HALF)]
    second = [ln[i] for i in range(HALF, ACCOUNTS)]
    da = np.mean(first) - np.mean(second)
    return float(da if w == 0 else -da)  # A minus B


def max_entropy_nash(m: np.ndarray) -> np.ndarray:
    """Maximum-entropy Nash of the symmetric zero-sum game M (value 0): p >= 0, sum 1, p^T M >= 0 on every column.
    Balduzzi et al. 2018 sec. 3; the tolerance absorbs sampling noise in M."""
    from scipy.optimize import linprog, minimize
    n = len(m)
    # the game value (0 for an exactly antisymmetric M) -- solve it, then take max entropy on the optimal face
    lp = linprog(np.r_[np.zeros(n), -1], A_ub=np.c_[-m.T, np.ones(n)], b_ub=np.zeros(n), A_eq=[np.r_[np.ones(n), 0]], b_eq=[1],
                 bounds=[(0, 1)] * n + [(None, None)])
    v = -lp.fun
    cons = [{"type": "eq", "fun": lambda p: p.sum() - 1}, {"type": "ineq", "fun": lambda p: p @ m - v + 1e-9}]
    best = None
    for start in [np.full(n, 1 / n), lp.x[:n]]:
        r = minimize(lambda p: float(np.sum(p * np.log(np.clip(p, 1e-12, 1)))), start, bounds=[(0, 1)] * n, constraints=cons,
                     method="SLSQP")
        if r.success and (best is None or r.fun < best.fun):
            best = r
    p = best.x if best is not None else lp.x[:n]
    p = np.clip(p, 0, None)
    return p / p.sum()


def alpha_rank(m: np.ndarray, alpha: float, pop: int = 50) -> np.ndarray:
    """Single-population alpha-Rank (Omidshafiei et al. 2019): a mutant r in a monomorphic population s fixes with the
    Fermi probability of its payoff edge; the stationary distribution of the mutation chain ranks the strategies."""
    n = len(m)
    c = np.zeros((n, n))
    for s in range(n):
        for r in range(n):
            if r == s:
                continue
            d = m[r, s] - m[s, r]
            y = alpha * abs(d)
            # (1 - e^-ad) / (1 - e^-mad), written so neither sign overflows
            rho = 1 / pop if y < 1e-12 else (-math.expm1(-y) / -math.expm1(-pop * y)) * (1 if d > 0 else math.exp((1 - pop) * y))
            c[s, r] = rho / (n - 1)
        c[s, s] = 1 - c[s].sum()
    w, v = np.linalg.eig(c.T)
    pi = np.real(v[:, np.argmin(np.abs(w - 1))])
    return pi / pi.sum()


def analyze(args) -> None:
    run_dir = Path(args.run_dir).resolve()
    n = len(ORDER)
    seeds = sorted({int(p.stem.split("-s")[1].split("-")[0]) for p in (run_dir / "out").glob("*.jsonl")
                    if p.name.count(".") == 1})
    per = np.full((len(seeds), n, n), np.nan)  # per seed, mean of both splits
    for (i, a), (j, b) in itertools.combinations_with_replacement(list(enumerate(ORDER)), 2):
        for k, s in enumerate(seeds):
            vals = [season_payoff(run_dir, a, b, s, w) for w in ((0,) if a == b else (0, 1))]
            if None in vals:
                continue
            per[k, i, j] = np.mean(vals)
            per[k, j, i] = -per[k, i, j] if i != j else per[k, i, j]
    # A vs A has a single split, so it carries the seed's position effect (accounts 0-31 minus 32-63). The off-diagonal
    # pairs measure it: raw(w0) = d + P, raw(w1) = -d + P. Subtracting P leaves the diagonal's true sanity value (~0).
    pos = np.full(len(seeds), np.nan)
    for k, s in enumerate(seeds):
        raw = [(season_payoff(run_dir, a, b, s, 0) - season_payoff(run_dir, a, b, s, 1)) / 2
               for a, b in itertools.combinations(ORDER, 2) if (run_dir / f"out/{name(a, b, s, 1)}.jsonl").exists()]
        pos[k] = np.mean(raw) if raw else np.nan
    raw_diag = np.array([np.diag(x) for x in per])
    for i in range(n):
        per[:, i, i] -= pos
    ok = ~np.isnan(per).any(axis=(1, 2))
    pos, raw_diag = pos[ok], raw_diag[ok]
    per = per[ok]
    seeds = [s for s, o in zip(seeds, ok) if o]
    m = per.mean(0)
    rng = np.random.default_rng(0)
    boots = per[rng.integers(0, len(per), (10000, len(per)))].mean(1)
    lo, hi = np.percentile(boots, [2.5, 97.5], axis=0)
    off = m - np.diag(np.diag(m))  # the diagonal is a sanity check, not a game outcome
    nash = max_entropy_nash(off)
    nash_boot = np.array([max_entropy_nash(b - np.diag(np.diag(b))) for b in boots[:300]])
    scale = np.abs(off).max() or 1
    ranks = {a: alpha_rank(off, a / scale).tolist() for a in (1, 10, 100)}
    uniform = off.mean(1)
    cycles = [(ORDER[i], ORDER[j], ORDER[k]) for i, j, k in itertools.permutations(range(n), 3)
              if i < j and i < k and off[i, j] > 0 and off[j, k] > 0 and off[k, i] > 0]
    sig_cycles = [c for c in cycles if all(lo[ORDER.index(x), ORDER.index(y)] > 0
                                           for x, y in zip(c, c[1:] + c[:1]))]
    times = [float(p.read_text().split()[1]) for p in (run_dir / "out").glob("*.done")]
    rcs = [int(p.read_text().split()[0]) for p in (run_dir / "out").glob("*.done")]
    out = {"order": ORDER, "seeds": seeds, "M": m.tolist(), "ci_lo": lo.tolist(), "ci_hi": hi.tolist(),
           "per_seed": per.tolist(), "position_effect": pos.tolist(), "raw_diag": raw_diag.tolist(), "nash": nash.tolist(), "nash_boot_mean": nash_boot.mean(0).tolist(),
           "nash_support_freq": (nash_boot > 0.01).mean(0).tolist(), "nash_payoff": (off @ nash).tolist(),
           "alpha_rank": ranks, "vs_uniform": uniform.tolist(), "cycles": cycles, "significant_cycles": sig_cycles,
           "seasons": len(times), "season_seconds_mean": float(np.mean(times)) if times else None, "nonzero_rc": sum(r != 0 for r in rcs)}
    (run_dir / "payoff.json").write_text(json.dumps(out, indent=2))
    print("M[A][B] = mean ln score A - B (rows A), 95% bootstrap CI over seeds", seeds)
    print("        " + "".join(f"{b:>24}" for b in ORDER))
    for i, a in enumerate(ORDER):
        print(f"{a:>8}" + "".join(f"{m[i, j]:+8.3f} [{lo[i, j]:+.3f},{hi[i, j]:+.3f}]" for j in range(n)))
    print("position effect per seed (0-31 minus 32-63):", np.round(pos, 3), "raw diag per seed:", np.round(raw_diag, 3).tolist())
    print("Nash (max-ent):", dict(zip(ORDER, np.round(nash, 3))), "boot support freq", dict(zip(ORDER, out["nash_support_freq"])))
    print("Nash payoff of each vs the Nash mix:", dict(zip(ORDER, np.round(off @ nash, 3))))
    for a, r in ranks.items():
        print(f"alpha-Rank (alpha={a}/max|M|):", sorted(zip(ORDER, np.round(r, 3)), key=lambda t: -t[1]))
    print("vs uniform:", dict(zip(ORDER, np.round(uniform, 3))))
    print("cycles:", cycles, "significant:", sig_cycles)
    print(f"seasons {len(times)}, mean {out['season_seconds_mean']}, nonzero rc {out['nonzero_rc']}")


def main() -> None:
    ap = argparse.ArgumentParser()
    sub = ap.add_subparsers(dest="cmd", required=True)
    e = sub.add_parser("export")
    e.add_argument("ckpt")
    e.add_argument("out")
    r = sub.add_parser("run")
    r.add_argument("run_dir")
    r.add_argument("--seeds", type=int, default=5)
    r.add_argument("--seed-list")
    r.add_argument("--par", type=int, default=20)
    r.add_argument("--only", nargs="*")
    a = sub.add_parser("analyze")
    a.add_argument("run_dir")
    args = ap.parse_args()
    {"export": lambda: export(args.ckpt, args.out), "run": lambda: run(args), "analyze": lambda: analyze(args)}[args.cmd]()


if __name__ == "__main__":
    main()
