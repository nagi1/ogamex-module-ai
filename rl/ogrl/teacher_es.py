"""Tune the personas' hand-set numbers (`rl/ogsim/src/tuning.rs`) by antithetic ES, as Kaggle rule-bot winners tuned
their bots' parameters: every account carries its own log-scale nudge of the parameter vector, each seed is played
with +nudge and -nudge (same dice), and an account's ln(final score) difference is its vote. A persona's own numbers
are voted on only by that persona's accounts. Votes are centred-ranked per seed against outliers; the step is the
normalised vote, so every generation moves the parameters by about `--lr` in log scale.

    python -m ogrl.teacher_es --bin SEASON --out DIR --arms ... [--gens 8 --seeds 16 --sigma 0.15 --lr 0.05]

Writes DIR/params-gNN.json each generation and DIR/es.jsonl; resumes from DIR/state.json. Judge the result on
held-out seeds (11-60) with `params=` against the defaults, never on the training seeds (1000+).
"""

from __future__ import annotations

import argparse
import json
import subprocess
import time
from pathlib import Path

import numpy as np

NAMES = ["surplus_days", "pays", "safe_share", "raid_cooldown_hours", "missile_salvo", "exposed_hours", "overflow", "keep_hours",
         "raider_military_share", "raider_cargo_share", "miner_cargo_share", "saver_cargo_share", "raider_fighters"]
DEFAULT = [60.0, 3.0, 0.3, 3.0, 20.0, 24.0, 0.8, 2.0, 0.30, 0.2, 0.1, 0.1, 30.0]
OWNER = {"raider_military_share": "raider", "raider_cargo_share": "raider", "raider_fighters": "raider",
         "miner_cargo_share": "miner", "saver_cargo_share": "saver"}
DATA = Path("/home/nagi/code/ogamex-next/storage/rl/ogsim")


def finals(path: Path) -> dict[int, tuple[str, float]]:
    last = {}
    for line in open(path):
        r = json.loads(line)
        last[r["account"]] = r
    return {a: (r["persona"], float(np.log1p(max(r["score"]["total"], 0)))) for a, r in last.items() if not r["inactive"]}


def centred_ranks(x: np.ndarray) -> np.ndarray:
    if len(x) < 2:
        return np.zeros_like(x)
    r = np.empty(len(x))
    r[np.argsort(x)] = np.arange(len(x))
    return r / (len(x) - 1) - 0.5


def main(argv: list[str] | None = None) -> int:
    ap = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    ap.add_argument("--bin", required=True)
    ap.add_argument("--out", required=True, type=Path)
    ap.add_argument("--arms", nargs="+", required=True)
    ap.add_argument("--gens", type=int, default=8)
    ap.add_argument("--seeds", type=int, default=16)
    ap.add_argument("--days", type=int, default=256)
    ap.add_argument("--accounts", type=int, default=64)
    ap.add_argument("--sigma", type=float, default=0.15)
    ap.add_argument("--lr", type=float, default=0.05)
    ap.add_argument("--limit", type=float, default=900.0, help="seconds a season may take")
    args = ap.parse_args(argv)
    out = args.out
    out.mkdir(parents=True, exist_ok=True)
    state_file = out / "state.json"
    state = json.loads(state_file.read_text()) if state_file.exists() else {"gen": 0, "theta": [float(np.log(v)) for v in DEFAULT]}
    n = len(NAMES)
    while state["gen"] < args.gens:
        gen = state["gen"] + 1
        theta = np.array(state["theta"])
        gen_dir = out / f"g{gen:02d}"
        gen_dir.mkdir(exist_ok=True)
        base = gen_dir / "base.json"
        base.write_text(json.dumps(dict(zip(NAMES, np.exp(theta).tolist()))))
        seeds = [5000 + gen * args.seeds + i for i in range(args.seeds)]
        started = time.time()
        procs = {}
        for seed in seeds:
            noise = np.random.default_rng(seed).standard_normal((args.accounts, n)).astype("<f4")
            noise.tofile(gen_dir / f"noise-{seed}.f32")
            for sign in (1, -1):
                path = gen_dir / f"s{seed}{'+' if sign > 0 else '-'}.jsonl"
                cmd = ["timeout", str(int(args.limit)), args.bin, str(DATA / "r8w-wall"), str(args.days), str(args.accounts), str(seed),
                       str(DATA / "r8w/units-parity/econ-u1-colonies.json"), str(path), *args.arms,
                       f"params={base}", f"params-es={gen_dir / f'noise-{seed}.f32'}:{sign}:{args.sigma}"]
                procs[(seed, sign)] = (subprocess.Popen(cmd, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL), path)
        for p, _ in procs.values():
            p.wait()
        votes = np.zeros(n)
        counts = np.zeros(n)
        used = 0
        for seed in seeds:
            plus, minus = procs[(seed, 1)][1], procs[(seed, -1)][1]
            if procs[(seed, 1)][0].returncode or procs[(seed, -1)][0].returncode:
                continue
            fp, fm = finals(plus), finals(minus)
            noise = np.fromfile(gen_dir / f"noise-{seed}.f32", dtype="<f4").reshape(args.accounts, n)
            common = sorted(set(fp) & set(fm))
            if not common:
                continue
            used += 1
            diff = centred_ranks(np.array([fp[a][1] - fm[a][1] for a in common]))
            persona = [fp[a][0] for a in common]
            for i, name in enumerate(NAMES):
                mask = np.array([OWNER.get(name) in (None, p) for p in persona])
                if mask.sum() < 2:
                    continue
                votes[i] += float((diff[mask] * noise[common, i][mask]).sum())
                counts[i] += mask.sum()
        grad = votes / np.maximum(counts, 1)
        step = args.lr * grad / (np.sqrt(np.mean(grad ** 2)) + 1e-12)
        theta = theta + step
        state = {"gen": gen, "theta": theta.tolist()}
        params = dict(zip(NAMES, np.exp(theta).tolist()))
        (out / f"params-g{gen:02d}.json").write_text(json.dumps(params, indent=1))
        state_file.write_text(json.dumps(state))
        with open(out / "es.jsonl", "a") as f:
            f.write(json.dumps({"gen": gen, "seeds_used": used, "seconds": round(time.time() - started),
                                "grad": dict(zip(NAMES, np.round(grad, 4).tolist())), "params": {k: round(v, 4) for k, v in params.items()}}) + "\n")
        for p in gen_dir.glob("s*.jsonl*"):
            p.unlink()
        print(f"gen {gen}: {used}/{len(seeds)} seeds, {time.time() - started:.0f}s", flush=True)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
