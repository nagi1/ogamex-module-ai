"""Population-based training over the season ES (Jaderberg et al. 2017, PBT; T1 items 6-7 of GAME-THEORY-EVOLUTION-FOR-TRAINING).

`es_world` plateaued on C3 (+0.179 by gen 14, then flat): one run, one sigma, one lr, one anchor, all chosen by hand. Here M
members train side by side, each with its own weights, Adam state and hyperparameters (sigma, lr, anchor). Every generation
all members play the same seeds with the same noise rows (common random numbers), so on a seed their scores differ only by
their weights. Every K generations the members are ranked by that paired score; the worst copies the best (weights, Adam
state, hyperparameters) when the best's lead over it is clear (a paired t over the window's seeds), then perturbs its
hyperparameters by x0.8 or x1.25 each (exploit = truncation, explore = perturb, as in the paper).

The ranked score is a member's mean ln(1 + final score) over its voting accounts (the first --fleet), averaged over its + and -
season of a seed, i.e. the sigma-smoothed objective the member actually optimises. A larger sigma plays noisier weights and
so scores a little lower: PBT therefore leans to smaller sigma, which the sigma rule below pushes back against.

Adaptive sigma, the cross-fit SNR rule (a success rule in the 1/5th-rule family, Rechenberg 1973; chosen over the NES/PEPG
log-sigma gradient and CSA because with d = 15k weights and ~150 votes their statistics, |z|^2/d - 1 and |path|^2/d - 1,
have null spread sqrt(2/d) ~ 0.01 and carry next to no signal; and the PEPG sigma term needs f(+)+f(-) ranked across
accounts, which ranks the accounts' start positions, not the noise). sigma trades the gradient's bias (smoothing) against
its variance (season noise does not shrink with sigma, the vote signal does, linearly). The rule measures the variance side
directly from the same antithetic votes: the seeds are split in halves A and B, the gradient direction u_A of half A is
tested on half B, t_B = sum_B w_i (z_i . u_A) / sqrt(sum_B w_i^2), and vice versa. With no signal t ~ N(0, 1) exactly
(the halves' noise rows are independent draws). t = mean(t_A, t_B) is smoothed (EMA 0.5) and

    log sigma += eta x clip(t* - t_ema, -2, 2),   sigma in [--sigma-min, --sigma-max]

so a member whose votes do not replicate across seeds widens its probe, one whose votes clearly replicate narrows it (less
smoothing bias, finer steps of the objective). Cost: two N x d dot products a generation, no extra season.

With --members 1 and no --adapt-sigma the update is es_world's, line for line (same seeds, noise, ranks, Adam, anchor).

    python -m ogrl.pbt_es --errand POLICY --init runs/bc-late-c4/bc.pt --out runs/pbt-c4/pbt --bin runs/bc-late-c4/season.bin ...
"""

from __future__ import annotations

import argparse
import copy
import json
import math
import time
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path
from types import SimpleNamespace

import numpy as np
import torch

from .errand_schema import load_errand, policy_json
from .es_world import ARMS, DATA, flat, generation, unflat


def weights_json(args, saved: dict, theta: np.ndarray) -> dict:
    model = unflat(saved["model"], theta)
    return policy_json({**saved, "model": model}) if args.errand else {k: t.tolist() for k, t in model.items()}


def crossfit_t(weights: np.ndarray, rows: np.ndarray, seed_of: np.ndarray, seeds: list[int]) -> float:
    """The SNR statistic: the gradient of one half of the seeds tested on the other half, both ways (N(0, 1) under no signal)."""
    if len(seeds) < 2:
        return float("nan")
    half = set(seeds[: len(seeds) // 2])
    in_a = np.array([s in half for s in seed_of])
    ts = []
    for fit, test in ((in_a, ~in_a), (~in_a, in_a)):
        g = (weights[fit, None] * rows[fit]).sum(0)
        norm = np.linalg.norm(g)
        wt = weights[test]
        if norm == 0 or not (wt ** 2).sum() > 0:
            return float("nan")
        ts.append(float((wt * (rows[test] @ (g / norm))).sum() / math.sqrt(float((wt ** 2).sum()))))
    return sum(ts) / 2


def step(args, mem: dict, done: dict, noise: dict, seeds: list[int], theta0: np.ndarray) -> dict:
    """es_world's update for one member (votes, centred ranks, anchor, Adam), plus its per-seed paired score and the SNR t."""
    votes, rows, plus, minus, seed_of = [], [], [], [], []
    per_seed = {}
    for seed in seeds:
        up, down = done[(seed, 1)], done[(seed, -1)]
        for a, (score, playing) in up.items():
            if not playing or a not in down or (args.errand and a >= args.fleet):
                continue
            votes.append(score - down[a][0])
            rows.append(noise[seed][a])
            plus.append(score)
            minus.append(down[a][0])
            seed_of.append(seed)
        # The ranked score: every voting-range account, active or not, both signs (the confirmations' "mean ln of accounts <21").
        both = [up[a][0] for a in up if not args.errand or a < args.fleet] + [down[a][0] for a in down if not args.errand or a < args.fleet]
        per_seed[seed] = float(np.mean(both))
    votes = np.asarray(votes, np.float32)
    weights = (np.argsort(np.argsort(votes)) / max(len(votes) - 1, 1) - 0.5).astype(np.float32)
    rows = np.stack(rows)
    grad = (weights[:, None] * rows).mean(0) / mem["sigma"]
    if mem["anchor"] > 0:
        grad = grad - mem["anchor"] * (mem["theta"] - theta0)
    mem["t"] += 1
    t = mem["t"]
    mem["m"] = 0.9 * mem["m"] + 0.1 * grad
    mem["v"] = 0.999 * mem["v"] + 0.001 * grad * grad
    before = mem["theta"]
    mem["theta"] = before + mem["lr"] * (mem["m"] / (1 - 0.9 ** t)) / (np.sqrt(mem["v"] / (1 - 0.999 ** t)) + 1e-8)
    snr = crossfit_t(weights, rows, np.asarray(seed_of), seeds)
    sigma_used = mem["sigma"]
    if args.adapt_sigma and not math.isnan(snr):
        mem["snr_ema"] = snr if mem.get("snr_ema") is None else 0.5 * mem["snr_ema"] + 0.5 * snr
        log_sigma = math.log(mem["sigma"]) + args.sigma_eta * max(-2.0, min(2.0, args.sigma_target - mem["snr_ema"]))
        mem["sigma"] = float(min(args.sigma_max, max(args.sigma_min, math.exp(log_sigma))))
    mem["scores"].update({str(s): v for s, v in per_seed.items()})
    both = np.concatenate([plus, minus])
    return {"accounts": len(votes), "score": float(np.mean(list(per_seed.values()))), "mean_ln": float(both.mean()),
            "vote_sd": float(votes.std()), "snr_t": snr, "sigma": sigma_used, "sigma_next": mem["sigma"], "lr": mem["lr"],
            "anchor": mem["anchor"], "step": float(np.abs(mem["theta"] - before).mean()),
            "drift": float(np.linalg.norm(mem["theta"] - theta0) / (np.linalg.norm(theta0) + 1e-12))}


def exploit(args, members: list[dict], gen: int, window: list[int]) -> list[dict]:
    """Truncation selection on the window's shared seeds, then perturbation of the copy's hyperparameters."""
    keys = [str(s) for s in window]
    table = np.array([[mem["scores"][k] for k in keys] for mem in members])
    rel = table - table.mean(0)  # paired: each seed's population mean taken out
    order = list(np.argsort(-rel.mean(1)))
    rng = np.random.default_rng(args.pbt_seed * 100003 + gen)
    events = []
    cut = max(1, len(members) // 4)
    for best, worst in zip(order[:cut], order[::-1][:cut]):
        if best == worst:
            continue
        d = table[best] - table[worst]
        se = d.std(ddof=1) / math.sqrt(len(d)) if len(d) > 1 else float("inf")
        tstat = float(d.mean() / se) if se > 0 else (float("inf") if d.mean() > 0 else 0.0)
        event = {"gen": gen, "best": int(best), "worst": int(worst), "lead": float(d.mean()), "t": tstat, "seeds": len(d),
                 "ranking": [int(i) for i in order], "rel": [float(x) for x in rel.mean(1)]}
        if tstat >= args.exploit_t and d.mean() > 0:
            src, dst = members[best], members[worst]
            new = copy.deepcopy({k: src[k] for k in ("theta", "m", "v", "t", "sigma", "lr", "anchor", "snr_ema")})
            for k in ("sigma", "lr", "anchor"):
                new[k] = float(new[k] * rng.choice(args.perturb))
            new["sigma"] = float(min(args.sigma_max, max(args.sigma_min, new["sigma"])))
            dst.update(new)
            dst["parent"] = f"{src['parent']}>{best}@{gen}"
            event.update({"exploit": True, "new": {k: new[k] for k in ("sigma", "lr", "anchor")}})
        else:
            event["exploit"] = False
        events.append(event)
    return events


def main(argv: list[str] | None = None) -> int:
    ap = argparse.ArgumentParser(prog="ogrl.pbt_es", description=__doc__.splitlines()[0])
    ap.add_argument("--init", required=True)
    ap.add_argument("--out", required=True)
    ap.add_argument("--bin", required=True)
    ap.add_argument("--members", type=int, default=3)
    ap.add_argument("--seeds", type=int, default=6, help="antithetic seed pairs per generation, shared by all members")
    ap.add_argument("--procs", type=int, default=39, help="season threads across ALL members (split evenly); at most 40")
    ap.add_argument("--sigma", type=float, default=0.02)
    ap.add_argument("--lr", type=float, default=0.01)
    ap.add_argument("--anchor", type=float, default=0.0)
    ap.add_argument("--gens", type=int, default=1000)
    ap.add_argument("--days", type=int, default=365)
    ap.add_argument("--accounts", type=int, default=64)
    ap.add_argument("--errand")
    ap.add_argument("--fleet", type=int, default=21)
    ap.add_argument("--sampled", action="store_true")
    ap.add_argument("--data", default=str(DATA))
    ap.add_argument("--extra-arms", nargs="*", default=[])
    ap.add_argument("--exploit-every", type=int, default=2, help="K: rank and exploit every K generations on their shared seeds")
    ap.add_argument("--exploit-t", type=float, default=1.0, help="copy only when the best leads the worst by a paired t of at least this")
    ap.add_argument("--perturb", type=float, nargs=2, default=[0.8, 1.25])
    ap.add_argument("--pbt-seed", type=int, default=7)
    ap.add_argument("--spread-init", action=argparse.BooleanOptionalAction, default=True,
                    help="members 1.. start with sigma and lr perturbed (member 0 keeps the given ones)")
    ap.add_argument("--adapt-sigma", action="store_true", help="the cross-fit SNR sigma rule (see the module docstring)")
    ap.add_argument("--sigma-target", type=float, default=1.0, help="t*: the cross-fit z the rule holds")
    ap.add_argument("--sigma-eta", type=float, default=0.1)
    ap.add_argument("--sigma-min", type=float, default=0.0025)
    ap.add_argument("--sigma-max", type=float, default=0.08)
    args = ap.parse_args(argv)
    if args.procs > 40:
        ap.error("--procs is the budget across all members and may not pass 40")
    out = Path(args.out)
    out.mkdir(parents=True, exist_ok=True)
    (out / "config.json").write_text(json.dumps({**vars(args), "arms": ARMS}, indent=2))

    saved = load_errand(args.init) if args.errand else torch.load(args.init, map_location="cpu", weights_only=False)
    theta0 = flat(saved["model"])
    resume = out / "latest.pt"
    if resume.exists():
        state = torch.load(resume, map_location="cpu", weights_only=False)
        members, start = state["members"], state["iteration"] + 1
    else:
        lo, hi = args.perturb
        # Distinct corners of the (sigma, lr) perturbation grid, then their squares: no two members start alike.
        spread = [(1.0, 1.0), (hi, lo), (lo, hi), (hi, hi), (lo, lo)]
        spread += [(a * a, b * b) for a, b in spread[1:]] * args.members
        members = []
        for i in range(args.members):
            f_sigma, f_lr = spread[i] if args.spread_init else (1.0, 1.0)
            members.append({"theta": theta0.copy(), "m": np.zeros_like(theta0), "v": np.zeros_like(theta0), "t": 0,
                            "sigma": args.sigma * f_sigma, "lr": args.lr * f_lr, "anchor": args.anchor, "snr_ema": None,
                            "scores": {}, "parent": str(i)})
        start = 1
    share = max(1, args.procs // len(members))
    for gen in range(start, args.gens + 1):
        clock = time.time()
        gen_dir = out / f"gen-{gen}"
        gen_dir.mkdir(exist_ok=True)
        seeds = [1000 + gen * args.seeds + i for i in range(args.seeds)]
        noise = {}
        for seed in seeds:
            noise[seed] = np.random.default_rng(seed).standard_normal((args.accounts, len(theta0)), dtype=np.float32)
            noise[seed].astype("<f4").tofile(gen_dir / f"noise-{seed}.f32")
        runs = []
        for i, mem in enumerate(members):
            mdir = gen_dir / f"m{i}"
            mdir.mkdir(exist_ok=True)
            for seed in seeds:
                link = mdir / f"noise-{seed}.f32"
                if not link.exists():
                    link.symlink_to(gen_dir / f"noise-{seed}.f32")
            (mdir / "policy.json").write_text(json.dumps(weights_json(args, saved, mem["theta"])))
            runs.append((SimpleNamespace(**{**vars(args), "sigma": mem["sigma"], "procs": share}), mdir))
        with ThreadPoolExecutor(len(members)) as pool:
            done = list(pool.map(lambda r: generation(r[0], r[1], seeds), runs))
        lines = []
        for i, mem in enumerate(members):
            lines.append({"gen": gen, "member": i, "parent": mem["parent"], **step(args, mem, done[i], noise, seeds, theta0)})
        for f in sorted(gen_dir.rglob("*"), key=lambda p: -len(p.parts)):
            f.rmdir() if f.is_dir() and not f.is_symlink() else f.unlink()
        gen_dir.rmdir()
        events = []
        if gen % args.exploit_every == 0 and len(members) > 1:
            window = [1000 + g * args.seeds + i for g in range(gen - args.exploit_every + 1, gen + 1) for i in range(args.seeds)]
            events = exploit(args, members, gen, window)
        for mem in members:  # scores older than the window are never read again
            keep = {str(1000 + g * args.seeds + i) for g in range(gen - args.exploit_every + 1, gen + 1) for i in range(args.seeds)}
            mem["scores"] = {k: v for k, v in mem["scores"].items() if k in keep}
        torch.save({"members": members, "iteration": gen}, out / "latest.pt.tmp")
        (out / "latest.pt.tmp").replace(resume)
        for i, mem in enumerate(members):
            (out / f"member-{i}.policy.json").write_text(json.dumps(weights_json(args, saved, mem["theta"])))
            if gen % 5 == 0:
                torch.save({**saved, "model": unflat(saved["model"], mem["theta"]), "iteration": gen, "member": i},
                           out / f"checkpoint-{gen:06d}-m{i}.pt")
        with (out / "pbt.jsonl").open("a") as log:
            for line in lines:
                line["seconds"] = round(time.time() - clock)
                log.write(json.dumps(line) + "\n")
                print(json.dumps(line), flush=True)
            for event in events:
                log.write(json.dumps(event) + "\n")
                print(json.dumps(event), flush=True)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
