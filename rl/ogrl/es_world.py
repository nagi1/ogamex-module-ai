"""Evolution strategies on the build model, scored by whole seasons (Salimans et al. 2017, OpenAI-ES with antithetic pairs).

PPO has to credit ~15k decisions per account-season with a result that shows months later; ES never assigns credit. Each
generation plays K seeds twice: in one season every account plays the policy nudged by +sigma x its own noise row, in the
other (same seed, same keyed dice) by -sigma x the same row. An account's ln(final score) difference between the two is its
vote for that direction; the votes, normalised, move the weights by Adam. The objective is the final host score itself,
so nothing in it can be gamed.

    python -m ogrl.es_world --init runs/train4/bc-t2.pt --out runs/train4/es1 --bin runs/train4/season-bin
"""

from __future__ import annotations

import argparse
import json
import math
import subprocess
import time
from pathlib import Path

import numpy as np
import torch

from .errand_schema import load_errand, policy_json

ROOT = Path(__file__).resolve().parents[4]
DATA = ROOT / "storage/rl/ogsim/r8w"
# The judge's world (`rl/scripts/judge_policy.sh`), win-first: keyed dice, every account a Discoverer.
ARMS = ("moonstack reach expedition colonies ships cap lab classes feed factory balance satellites doubler moonfeed standing labfirst "
        "moontaste netchain merchant techtree doctrine acsdefend acsattack wrecks moonraid relocate keyed class=3").split()
# The scoring layers, in the order `season::Policy::nudged` walks them; the value head is not the policy.
SCORING = ("state_net.0", "state_net.2", "cand_net.0", "joint.0", "joint.2", "joint.4")


def flat(model: dict) -> np.ndarray:
    return np.concatenate([model[f"{layer}.{part}"].numpy().ravel() for layer in SCORING for part in ("weight", "bias")]).astype(np.float32)


def unflat(model: dict, theta: np.ndarray) -> dict:
    out, at = dict(model), 0
    for layer in SCORING:
        for part in ("weight", "bias"):
            shape = model[f"{layer}.{part}"].shape
            n = int(np.prod(shape))
            out[f"{layer}.{part}"] = torch.from_numpy(theta[at:at + n].reshape(shape).copy())
            at += n
    return out


def finals(path: Path, accounts: int) -> dict[int, tuple[float, bool]]:
    """Per account, ln(1 + final host score) and whether it still plays at the end. The last day's lines close the file, so
    only its tail is read: parsing the whole 100 MB season left the machine idle for minutes each generation."""
    last: dict[int, dict] = {}
    size = path.stat().st_size
    with path.open("rb") as f:
        tail = 1 << 21
        while len(last) < accounts and tail < 2 * size:
            f.seek(max(0, size - tail))
            last = {}
            for line in f.read().splitlines()[1:] if size > tail else f.read().splitlines():
                if b'"planets"' in line:
                    r = json.loads(line)
                    last[r["account"]] = r
            tail *= 4
    return {a: (math.log1p(r["score"]["total"]), not r.get("inactive")) for a, r in last.items()}


def generation(args, gen_dir: Path, seeds: list[int]) -> dict[tuple[int, int], dict[int, tuple[float, bool]]]:
    """Both signs of every seed, in one process: the seasons share the 0.8 GB catalogue a process each would load again."""
    jobs, outs = [], {}
    for seed in seeds:
        for sign in (1, -1):
            out = gen_dir / f"s{seed}{'+' if sign > 0 else '-'}.jsonl"
            outs[(seed, sign)] = out
            jobs.append(f"{seed} {out} {'es-errand' if args.errand else 'es'}={gen_dir / f'noise-{seed}.f32'}:{sign}:{args.sigma}")
    (gen_dir / "jobs.txt").write_text("\n".join(jobs) + "\n")
    # `--errand`: the trained weights are the fleet errand model of the first `--fleet` accounts; the builds stay `--errand`'s.
    plays = ([f"policy={args.errand}", f"errand-policy={gen_dir / 'policy.json'}", f"fleet-model={args.fleet}"] if args.errand
             else [f"policy={gen_dir / 'policy.json'}"])
    arms = ARMS + (["sampled"] if args.sampled else []) + plays + [ f"jobs={gen_dir / 'jobs.txt'}", f"threads={args.procs}"]
    arms = arms + list(args.extra_arms)
    subprocess.run([args.bin, args.data, str(args.days), str(args.accounts), "0", str(DATA / "units-parity/econ-u1-colonies.json"), str(gen_dir / "unused.jsonl"), *arms],
                   check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, env={"OMP_NUM_THREADS": "1"})
    done = {key: finals(out, args.accounts) for key, out in outs.items()}
    for out in outs.values():
        out.unlink()
    return done


def gate(args, out: Path, gen: int, policy: Path) -> float:
    """The realism gate (RL-PITFALLS #1, #2): the judge's seeds 1-10 with the current weights, scored by hds.py, which no
    vote ever sees. Returns the HDS; the seasons are kept under gate-<gen>/ for the anomaly report."""
    gate_dir = out / f"gate-{gen}"
    gate_dir.mkdir(exist_ok=True)
    plays = ([f"policy={args.errand}", f"errand-policy={policy}", f"fleet-model={args.fleet}"] if args.errand else [f"policy={policy}"])
    arms = ARMS + (["sampled"] if args.sampled else []) + plays + list(args.extra_arms)
    procs = [subprocess.Popen([args.bin, args.data, str(args.days), str(args.accounts), str(seed), str(DATA / "units-parity/econ-u1-colonies.json"),
                               str(gate_dir / f"ready-{seed}.jsonl"), *arms], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
                              env={"OMP_NUM_THREADS": "1"}) for seed in range(1, 11)]
    for p in procs:
        p.wait()
    files = sorted(str(f.name) for f in gate_dir.glob("ready-*.jsonl") if f.name.count(".") == 1)
    run = subprocess.run([args.hds_python, str(ROOT / "Modules/AI/rl/scripts/hds.py"), *files, "--save", "score.json"], cwd=gate_dir,
                         capture_output=True, text=True)
    (gate_dir / "hds.txt").write_text(run.stdout + run.stderr)
    found = [line for line in run.stdout.splitlines() if line.startswith("verdict:")]
    return float(found[-1].split()[2]) if found else float("nan")


def band_hds(out: Path, gen: int, bands: list[str]) -> float:
    """The mean of the in-band values hds.py prints for the cells of `bands` (e.g. top10 mid) at gate `gen`: the bands the
    model is judged in. A model that lifts its accounts out of the bottom band always costs that band's cells (real
    bottoms are near-abandoned), which whole-HDS reads as a realism loss (5 Nov, C3 confirmation)."""
    text = (out / f"gate-{gen}" / "hds.txt")
    rows = [l for l in text.read_text().splitlines() if l.split("|")[0] in bands and "growth=" in l] if text.exists() else []
    values = [float(w.split("=")[1]) for row in rows for w in row.split() if "=" in w]
    return sum(values) / len(values) if values else float("nan")


def main(argv: list[str] | None = None) -> int:
    ap = argparse.ArgumentParser(prog="ogrl.es_world", description=__doc__.splitlines()[0])
    ap.add_argument("--init", required=True)
    ap.add_argument("--out", required=True)
    ap.add_argument("--bin", required=True, help="the season binary (examples/season)")
    ap.add_argument("--seeds", type=int, default=20, help="antithetic seed pairs per generation: 2x this many seasons")
    ap.add_argument("--procs", type=int, default=40, help="season threads in the one process")
    ap.add_argument("--sigma", type=float, default=0.02)
    ap.add_argument("--lr", type=float, default=0.01)
    ap.add_argument("--gens", type=int, default=1000)
    ap.add_argument("--days", type=int, default=365)
    ap.add_argument("--accounts", type=int, default=64)
    ap.add_argument("--errand", help="a build policy (json) to hold fixed while ES trains --init as the fleet errand model")
    ap.add_argument("--fleet", type=int, default=21, help="with --errand: the first N accounts fly the errand model and vote")
    ap.add_argument("--sampled", action="store_true", help="play the policy sampled, as the judge does; the + and - season share each account's draws")
    ap.add_argument("--data", default=str(DATA), help="the season data dir (r8w-wall for the wall-ceiling doctrine)")
    ap.add_argument("--extra-arms", nargs="*", default=[], help="arms added to every season (e.g. spread scrap exposhield wide late)")
    ap.add_argument("--anchor", type=float, default=0.0,
                    help="pull toward the starting (cloned) weights: the step ascends vote - anchor/2 x |theta - theta0|^2, so ES "
                         "cannot drift away from the teacher's style for score alone (RL-PITFALLS #5: VPT, AlphaStar, CICERO)")
    ap.add_argument("--gate-every", type=int, default=0, help="every N generations, play the judge's seeds 1-10 and score HDS")
    ap.add_argument("--gate-hds", type=float, default=0.0, help="stop (exit 3) when the gate's HDS falls below this")
    ap.add_argument("--gate-drop", type=float, default=-1.0,
                    help="stop (exit 3) when the gate's HDS falls more than this below the starting weights' own gate (gen 0, "
                         "same seeds and arms); negative: off")
    ap.add_argument("--gate-bands", nargs="*", default=[],
                    help="with --gate-drop: the drop is read on these bands' cells (e.g. top10 mid, `band_hds`) instead of whole HDS")
    ap.add_argument("--hds-python", default=str(ROOT / ".venv-rl/bin/python"))
    args = ap.parse_args(argv)
    out = Path(args.out)
    out.mkdir(parents=True, exist_ok=True)
    (out / "config.json").write_text(json.dumps({**vars(args), "arms": ARMS}, indent=2))

    # `--errand`: --init may also be an exported errand policy json; its names (or today's) and version come along.
    saved = load_errand(args.init) if args.errand else torch.load(args.init, map_location="cpu", weights_only=False)
    theta = flat(saved["model"])
    theta0 = theta.copy()
    m, v = np.zeros_like(theta), np.zeros_like(theta)
    start = 1
    resume = out / "latest.pt"
    if resume.exists():
        state = torch.load(resume, map_location="cpu", weights_only=False)
        theta, m, v, start = flat(state["model"]), state["adam_m"], state["adam_v"], state["iteration"] + 1
    base_hds = float("nan")
    if args.gate_every and args.gate_drop >= 0:
        known = out / "gate-0" / "hds.txt"
        found = [l for l in known.read_text().splitlines() if l.startswith("verdict:")] if known.exists() else []
        if found:
            base_hds = float(found[-1].split()[2])
        if not found:
            policy = out / "gate-0.policy.json"
            start_model = unflat(saved["model"], theta0)
            policy.write_text(json.dumps(policy_json({**saved, "model": start_model}) if args.errand else {k: t.tolist() for k, t in start_model.items()}))
            base_hds = gate(args, out, 0, policy)
        base_band = band_hds(out, 0, args.gate_bands) if args.gate_bands else float("nan")
        print(json.dumps({"gen": 0, "gate_hds": base_hds, **({"gate_band": base_band} if args.gate_bands else {})}), flush=True)
    for gen in range(start, args.gens + 1):
        clock = time.time()
        gen_dir = out / f"gen-{gen}"
        gen_dir.mkdir(exist_ok=True)
        model = unflat(saved["model"], theta)
        # The errand door refuses unversioned weights (`season::Policy::load_errand`): stamp the version and column names.
        weights = policy_json({**saved, "model": model}) if args.errand else {k: t.tolist() for k, t in model.items()}
        (gen_dir / "policy.json").write_text(json.dumps(weights))
        # Fresh seeds every generation, clear of the judge's 1-10.
        seeds = [1000 + gen * args.seeds + i for i in range(args.seeds)]
        noise = {}
        for seed in seeds:
            noise[seed] = np.random.default_rng(seed).standard_normal((args.accounts, len(theta)), dtype=np.float32)
            noise[seed].astype("<f4").tofile(gen_dir / f"noise-{seed}.f32")
        done = generation(args, gen_dir, seeds)
        votes, rows, plus, minus = [], [], [], []
        for seed in seeds:
            up, down = done[(seed, 1)], done[(seed, -1)]
            for a, (score, playing) in up.items():
                if not playing or a not in down or (args.errand and a >= args.fleet):
                    continue
                votes.append(score - down[a][0])
                rows.append(noise[seed][a])
                plus.append(score)
                minus.append(down[a][0])
        votes = np.asarray(votes, np.float32)
        # Centred ranks (Salimans et al.): a season's tail (a sealed planet, a lucky expedition run) moves no more than its rank.
        weights = (np.argsort(np.argsort(votes)) / max(len(votes) - 1, 1) - 0.5).astype(np.float32)
        grad = (weights[:, None] * np.stack(rows)).mean(0) / args.sigma
        if args.anchor > 0:
            grad = grad - args.anchor * (theta - theta0)
        m = 0.9 * m + 0.1 * grad
        v = 0.999 * v + 0.001 * grad * grad
        theta = theta + args.lr * (m / (1 - 0.9 ** gen)) / (np.sqrt(v / (1 - 0.999 ** gen)) + 1e-8)
        for f in gen_dir.iterdir():
            f.unlink()
        gen_dir.rmdir()
        state = {**saved, "model": unflat(saved["model"], theta), "iteration": gen, "adam_m": m, "adam_v": v}
        torch.save(state, out / "latest.pt.tmp")
        (out / "latest.pt.tmp").replace(resume)
        if gen % 5 == 0:
            torch.save({**saved, "model": unflat(saved["model"], theta), "iteration": gen}, out / f"checkpoint-{gen:06d}.pt")
        both = np.concatenate([plus, minus])
        line = {"gen": gen, "accounts": len(votes), "median_score": float(np.expm1(np.median(both))), "mean_ln": float(both.mean()),
                "vote_sd": float(votes.std()), "vote_mean_abs": float(np.abs(votes).mean()), "step": float(np.abs(theta - flat(model)).mean()),
                "drift": float(np.linalg.norm(theta - theta0) / (np.linalg.norm(theta0) + 1e-12)), "seconds": round(time.time() - clock)}
        if args.gate_every and gen % args.gate_every == 0:
            policy = out / f"gate-{gen}.policy.json"
            gated = unflat(saved["model"], theta)
            policy.write_text(json.dumps(policy_json({**saved, "model": gated}) if args.errand else {k: t.tolist() for k, t in gated.items()}))
            line["gate_hds"] = gate(args, out, gen, policy)
            if args.gate_bands:
                line["gate_band"] = band_hds(out, gen, args.gate_bands)
        with (out / "es.jsonl").open("a") as log:
            log.write(json.dumps(line) + "\n")
        print(json.dumps(line), flush=True)
        if "gate_band" in line and args.gate_drop >= 0 and not line["gate_band"] >= base_band - args.gate_drop:
            print(f"gate: bands {args.gate_bands} at {line['gate_band']} dropped more than {args.gate_drop} below the start ({base_band}) at generation {gen}; stopping", flush=True)
            return 3
        if "gate_hds" in line and not args.gate_bands and args.gate_drop >= 0 and not line["gate_hds"] >= base_hds - args.gate_drop:
            print(f"gate: HDS {line['gate_hds']} dropped more than {args.gate_drop} below the start ({base_hds}) at generation {gen}; stopping", flush=True)
            return 3
        if "gate_hds" in line and not line["gate_hds"] >= args.gate_hds:
            print(f"gate: HDS {line['gate_hds']} under {args.gate_hds} at generation {gen}; stopping", flush=True)
            return 3
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
