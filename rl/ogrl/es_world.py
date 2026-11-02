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
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path

import numpy as np
import torch

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


def finals(path: Path) -> dict[int, tuple[float, bool]]:
    """Per account, ln(1 + final host score) and whether it still plays at the end."""
    last = {}
    for line in path.open():
        r = json.loads(line)
        if "planets" in r:
            last[r["account"]] = r
    return {a: (math.log1p(r["score"]["total"]), not r.get("inactive")) for a, r in last.items()}


def season(args, gen_dir: Path, seed: int, sign: int) -> dict[int, tuple[float, bool]]:
    out = gen_dir / f"s{seed}{'+' if sign > 0 else '-'}.jsonl"
    arms = ARMS + [f"policy={gen_dir / 'policy.json'}", f"es={gen_dir / f'noise-{seed}.f32'}:{sign}:{args.sigma}"]
    subprocess.run([args.bin, str(DATA), str(args.days), str(args.accounts), str(seed), str(DATA / "units-parity/econ-u1-colonies.json"), str(out), *arms],
                   check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, env={"OMP_NUM_THREADS": "1", "RAYON_NUM_THREADS": "1"})
    scores = finals(out)
    out.unlink()
    return scores


def main(argv: list[str] | None = None) -> int:
    ap = argparse.ArgumentParser(prog="ogrl.es_world", description=__doc__.splitlines()[0])
    ap.add_argument("--init", required=True)
    ap.add_argument("--out", required=True)
    ap.add_argument("--bin", required=True, help="the season binary (examples/season)")
    ap.add_argument("--seeds", type=int, default=20, help="antithetic seed pairs per generation: 2x this many seasons")
    ap.add_argument("--procs", type=int, default=40)
    ap.add_argument("--sigma", type=float, default=0.02)
    ap.add_argument("--lr", type=float, default=0.01)
    ap.add_argument("--gens", type=int, default=1000)
    ap.add_argument("--days", type=int, default=365)
    ap.add_argument("--accounts", type=int, default=64)
    args = ap.parse_args(argv)
    out = Path(args.out)
    out.mkdir(parents=True, exist_ok=True)
    (out / "config.json").write_text(json.dumps({**vars(args), "arms": ARMS}, indent=2))

    saved = torch.load(args.init, map_location="cpu", weights_only=False)
    theta = flat(saved["model"])
    m, v = np.zeros_like(theta), np.zeros_like(theta)
    start = 1
    resume = out / "latest.pt"
    if resume.exists():
        state = torch.load(resume, map_location="cpu", weights_only=False)
        theta, m, v, start = flat(state["model"]), state["adam_m"], state["adam_v"], state["iteration"] + 1
    for gen in range(start, args.gens + 1):
        clock = time.time()
        gen_dir = out / f"gen-{gen}"
        gen_dir.mkdir(exist_ok=True)
        model = unflat(saved["model"], theta)
        (gen_dir / "policy.json").write_text(json.dumps({k: t.tolist() for k, t in model.items()}))
        # Fresh seeds every generation, clear of the judge's 1-10.
        seeds = [1000 + gen * args.seeds + i for i in range(args.seeds)]
        noise = {}
        for seed in seeds:
            noise[seed] = np.random.default_rng(seed).standard_normal((args.accounts, len(theta)), dtype=np.float32)
            noise[seed].astype("<f4").tofile(gen_dir / f"noise-{seed}.f32")
        with ThreadPoolExecutor(args.procs) as pool:
            runs = {(s, sign): pool.submit(season, args, gen_dir, s, sign) for s in seeds for sign in (1, -1)}
            done = {key: run.result() for key, run in runs.items()}
        votes, rows, plus, minus = [], [], [], []
        for seed in seeds:
            up, down = done[(seed, 1)], done[(seed, -1)]
            for a, (score, playing) in up.items():
                if not playing or a not in down:
                    continue
                votes.append(score - down[a][0])
                rows.append(noise[seed][a])
                plus.append(score)
                minus.append(down[a][0])
        votes = np.asarray(votes, np.float32)
        weights = votes / (votes.std() + 1e-8)
        grad = (weights[:, None] * np.stack(rows)).mean(0) / args.sigma
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
                "vote_sd": float(votes.std()), "vote_mean_abs": float(np.abs(votes).mean()), "step": float(np.abs(theta - flat(model)).mean()), "seconds": round(time.time() - clock)}
        with (out / "es.jsonl").open("a") as log:
            log.write(json.dumps(line) + "\n")
        print(json.dumps(line), flush=True)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
