"""Paired evaluation of several train_world checkpoints at once, the fixed worlds split over processes.

The run's own evaluator plays one checkpoint at a time in one process (a year-long season world takes minutes per
world), so it falls behind the learner. This plays the same fixed seeds with the same `play_arm`, in parallel.

    python -m ogrl.eval_checkpoints storage/rl/ogsim/runs/w34/ppo2 --updates 120 240 360 --procs 48
"""

from __future__ import annotations

import argparse
import json
from concurrent.futures import ProcessPoolExecutor
from pathlib import Path

import numpy as np
import torch

from .econ import bootstrap_ci
from .model import CandidateScorer
from .train_world import load_world, play_arm


def play(job):
    run_args, checkpoint, seeds = job
    torch.set_num_threads(1)
    args = argparse.Namespace(**run_args)
    probe = load_world(args, args.seed, 1)
    dims = probe.dims()
    del probe
    model = None
    if checkpoint:
        saved = torch.load(checkpoint, map_location="cpu", weights_only=False)
        model = CandidateScorer(saved["state_dim"], saved["cand_dim"], saved["width"])
        model.load_state_dict(saved["model"])
        model.eval()
    values = play_arm(args, dims, model, "cpu", args.eval_catalogue, seeds)
    return checkpoint, seeds, values


def main(argv: list[str] | None = None) -> int:
    ap = argparse.ArgumentParser(prog="ogrl.eval_checkpoints", description=__doc__.splitlines()[0])
    ap.add_argument("run", help="a train_world output directory (config.json and checkpoint-*.pt)")
    ap.add_argument("--updates", type=int, nargs="+", required=True)
    ap.add_argument("--worlds", type=int, default=0, help="default: the run's --eval-worlds")
    ap.add_argument("--procs", type=int, default=32)
    args = ap.parse_args(argv)
    run = Path(args.run)
    config = json.loads((run / "config.json").read_text())
    seeds = [10_000_000 + i for i in range(args.worlds or config["eval_worlds"])]
    chunks = [seeds[i::max(1, args.procs // (len(args.updates) + 1))] for i in range(max(1, args.procs // (len(args.updates) + 1)))]
    chunks = [c for c in chunks if c]
    arms = [""] + [str(run / f"checkpoint-{u:06d}.pt") for u in args.updates]
    results: dict[str, dict[int, np.ndarray]] = {arm: {} for arm in arms}
    with ProcessPoolExecutor(max_workers=args.procs) as pool:
        for checkpoint, part, values in pool.map(play, [(config, arm, c) for arm in arms for c in chunks]):
            for seed, row in zip(part, values):
                results[checkpoint][seed] = row
    order = sorted(seeds)
    rule = np.array([results[""][s] for s in order])
    ctl = slice(0, config["controlled"])
    for arm in arms[1:]:
        values = np.array([results[arm][s] for s in order])
        per_account = values[:, ctl] / rule[:, ctl] - 1.0
        per_world = per_account.mean(1)
        low, high = bootstrap_ci(per_world)
        line = {"checkpoint": Path(arm).name, "worlds": len(order), "value_vs_rule_mean": float(per_world.mean()), "ci95_world_bootstrap": [low, high],
                "better_share_accounts": float((per_account > 0).mean())}
        print(json.dumps(line), flush=True)
        (run / "eval-checkpoints.jsonl").open("a").write(json.dumps(line) + "\n")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
