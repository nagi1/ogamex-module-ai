"""Headroom of the season world's build decision over the player rule, with no learning (rl-diagnostics section 1.2).

Each fixed seed is played once by the rule and N times with the rule perturbed: on a controlled build decision, with
probability eps, a uniformly random legal row (the wait row included) replaces the rule's. If the best of N perturbed
games is not above the rule's, no learner on this menu will be either.

    python -m ogrl.headroom storage/rl/ogsim/runs/w37/ppo --worlds 8 --arms 16 --eps 0.02 0.1 --procs 40
"""

from __future__ import annotations

import argparse
import json
from concurrent.futures import ProcessPoolExecutor
from pathlib import Path

import numpy as np

from .train_world import load_world, observe


def play(job):
    run_args, seed, eps, arm = job
    args = argparse.Namespace(**run_args)
    world = load_world(args, seed, 1, args.eval_catalogue)
    dims = world.dims()
    rng = np.random.default_rng([seed, arm, int(eps * 1000)])
    while True:
        action = world.baseline()[0]
        kind = world.decisions()[0][1]
        if eps and kind == args.queue and rng.random() < eps:
            legal = np.flatnonzero(observe(world, dims)[2][0])
            action = int(rng.choice(legal)) if len(legal) else action
        world.step([action])
        done = world.take_finished()
        if len(done) >= args.accounts:
            return seed, eps, arm, [(row[1], row[5]) for row in done[: args.accounts]]


def main(argv: list[str] | None = None) -> int:
    ap = argparse.ArgumentParser(prog="ogrl.headroom", description=__doc__.splitlines()[0])
    ap.add_argument("run", help="a train_world output directory (its config.json sets the world)")
    ap.add_argument("--worlds", type=int, default=8)
    ap.add_argument("--arms", type=int, default=16)
    ap.add_argument("--eps", type=float, nargs="+", default=[0.02, 0.1])
    ap.add_argument("--procs", type=int, default=40)
    args = ap.parse_args(argv)
    config = json.loads((Path(args.run) / "config.json").read_text())
    seeds = [10_000_000 + i for i in range(args.worlds)]
    jobs = [(config, s, 0.0, 0) for s in seeds] + [(config, s, e, a) for e in args.eps for s in seeds for a in range(args.arms)]
    values: dict[tuple, np.ndarray] = {}
    with ProcessPoolExecutor(max_workers=args.procs) as pool:
        for seed, eps, arm, row in pool.map(play, jobs):
            values[seed, eps, arm] = np.array(row)
    # The accounts past `controlled` are never perturbed: how far they move is the world's own noise, not the decision.
    groups = {"controlled": slice(0, config["controlled"]), "untouched": slice(config["controlled"], config["accounts"])}
    # values[...][account] is (economy, fleet); the total is what the learner is rewarded on.
    parts = {"total": lambda v: v.sum(1), "economy": lambda v: v[:, 0], "fleet": lambda v: v[:, 1]}
    for (part, pick), (group, ctl), eps in ((p, g, e) for p in parts.items() for g in groups.items() for e in args.eps):
        # ratio[world, arm, account]: each perturbed game against the rule's game on the same seed
        ratio = np.array([[pick(values[s, eps, a])[ctl] / np.maximum(pick(values[s, 0.0, 0])[ctl], 1) - 1.0 for a in range(args.arms)] for s in seeds])
        line = {"value": part, "accounts": group, "eps": eps, "worlds": len(seeds), "arms": args.arms,
                "mean_arm": float(ratio.mean()),
                "best_arm_per_world": float(ratio.mean(2).max(1).mean()),
                "best_arm_per_account": float(ratio.max(1).mean()),
                "share_arms_better": float((ratio.mean(2) > 0).mean()),
                "spread_per_world": float(ratio.mean(2).std(1).mean())}
        print(json.dumps(line), flush=True)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
