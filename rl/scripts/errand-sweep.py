#!/usr/bin/env python3
"""Sweep the errand trainer's knobs against one yardstick, unattended.

The point of a fast loop is not that a person runs it; it is that a change is judged by the same number every time. This
runs a list of configurations of `ogrl.train_world`, all starting from the same behaviour-cloned teacher and all evaluated
on the same held-out worlds, and prints them ranked. Nothing is kept that does not beat the baseline with its whole
confidence interval above zero.

    PY=.venv-rl/bin/python python rl/scripts/errand-sweep.py --baseline path/bc.pt --minutes 3 --parallel 2

A configuration is a dict of trainer flags, eg {"ent": 0.001} or {"kl-anchor": 1.0, "width": 128}. The defaults below are
the ones worth trying first: exploration pressure, how tightly the policy is held to the cloned teacher, model width.
"""

from __future__ import annotations

import argparse
import json
import os
import subprocess
import sys
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path

DEFAULT_CONFIGS = [
    {"ent": 0.001},
    {"ent": 0.05},
    {"kl-anchor": 0.0},
    {"kl-anchor": 1.0},
    {"width": 32},
    {"width": 128},
    {"lr": 1e-4},
    {"lr": 1e-3},
]


def run_config(python: str, out: Path, config: dict, args) -> dict:
    """One trainer run, and the last evaluation it wrote."""
    name = "-".join(f"{k}={v}" for k, v in config.items()) or "baseline"
    run = out / name.replace("/", "_")
    run.mkdir(parents=True, exist_ok=True)
    cmd = [python, "-m", "ogrl.train_world", "--queue", "2", "--seed", str(args.seed), "--init", str(args.baseline),
           "--actors", str(args.actors), "--worlds", str(args.worlds), "--accounts", str(args.accounts),
           "--controlled", str(args.controlled), "--minutes", str(args.minutes), "--out", str(run)]
    for key, value in config.items():
        cmd += [f"--{key}", str(value)]
    log = run / "train.log"
    with log.open("w") as handle, open(os.devnull, "w") as devnull:
        subprocess.run(cmd, stdout=handle, stderr=devnull, check=False)
    rows = [json.loads(line) for line in (run / "eval.jsonl").open()] if (run / "eval.jsonl").exists() else []
    if not rows:
        return {"config": config, "name": name, "failed": True}
    last = rows[-1]
    return {"config": config, "name": name, "failed": False, "update": last["update"],
            "value": last["value_vs_rule_mean"], "ci": last["ci95_world_bootstrap"],
            "better": last["better_share_accounts"], "differs": last.get("differs_from_rule_share", 0.0)}


def main(argv: list[str] | None = None) -> int:
    ap = argparse.ArgumentParser(prog="errand-sweep", description=__doc__.splitlines()[0])
    ap.add_argument("--baseline", required=True, help="the behaviour-cloned checkpoint every run starts from")
    ap.add_argument("--out", default="../../../storage/rl/ogsim/runs/sweep")
    ap.add_argument("--minutes", type=float, default=3.0)
    ap.add_argument("--actors", type=int, default=12)
    ap.add_argument("--worlds", type=int, default=24)
    ap.add_argument("--accounts", type=int, default=8)
    ap.add_argument("--controlled", type=int, default=4)
    ap.add_argument("--seed", type=int, default=1)
    ap.add_argument("--parallel", type=int, default=2)
    ap.add_argument("--configs", help="JSON list of flag dicts; default is exploration, anchoring, width and learning rate")
    ap.add_argument("--python", default=sys.executable)
    args = ap.parse_args(argv)

    configs = json.loads(args.configs) if args.configs else DEFAULT_CONFIGS
    out = Path(args.out)
    out.mkdir(parents=True, exist_ok=True)
    print(f"{len(configs)} configurations, {args.minutes:g} min each, {args.parallel} at a time", flush=True)
    with ThreadPoolExecutor(max_workers=args.parallel) as pool:
        results = list(pool.map(lambda c: run_config(args.python, out, c, args), configs))
    for row in results:
        (out / "sweep.jsonl").open("a").write(json.dumps(row) + "\n")

    ok = [r for r in results if not r["failed"]]
    ok.sort(key=lambda r: -r["value"])
    print(f"\n{'configuration':<32} {'value vs teacher':>17} {'95% CI':>22} {'better':>7} {'differs':>8}  verdict")
    for r in ok:
        low, high = r["ci"]
        verdict = "keep" if low > 0 else "no"
        print(f"{r['name']:<32} {r['value']:>+16.2%} [{low:>+7.2%}, {high:>+7.2%}] {r['better']:>6.0%} {r['differs']:>7.0%}  {verdict}")
    for r in results:
        if r["failed"]:
            print(f"{r['name']:<32} {'failed':>17}")
    best = ok[0] if ok else None
    print(f"\nbest: {best['name'] if best else 'none'} at {best['value']:+.2%}" if best else "\nnothing finished")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
