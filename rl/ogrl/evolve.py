"""Natural selection over the personas' tunable numbers (`rl/ogsim/src/tuning.rs`): a population of genomes lives in
shared worlds, competes there, and the fittest of each niche (persona) breed the next generation with mutation.

Unlike `teacher_es` (one best vector, fitness against a fixed world), fitness here is frequency-dependent: a raider's
genome is scored among the genomes everyone else carries in the same generation, so niches and diversity can persist.

Per generation: W worlds (fresh seeds) x 64 accounts. A 1-day probe of each seed reads which persona each account gets;
every account then receives a genome drawn from its persona's population (as a `params-es` file of ln(genome/default)).
After the season, accounts are ranked within (world, persona) by ln(final score) (world luck cancels); the top
`--keep` share of each niche survives and refills its niche with mutated copies (log-normal, sigma `--mutation`).

    python -m ogrl.evolve --bin SEASON --out DIR --arms ... [--gens 12 --worlds 24 --keep 0.25 --mutation 0.08]

Writes DIR/genNN.json (per niche: mean, sd, best genome) and DIR/evolve.jsonl. Judge the niche means on held-out seeds.
"""

from __future__ import annotations

import argparse
import json
import subprocess
import time
from pathlib import Path

import numpy as np

from .teacher_es import DATA, DEFAULT, NAMES, finals

NICHES = ("miner", "raider", "saver")


def run(args, seed: int, days: int, out: Path, extra: list[str]) -> subprocess.Popen:
    cmd = ["timeout", str(int(args.limit)), args.bin, str(DATA / "r8w-wall"), str(days), str(args.accounts), str(seed),
           str(DATA / "r8w/units-parity/econ-u1-colonies.json"), str(out), *args.arms, *extra]
    return subprocess.Popen(cmd, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)


def personas(path: Path) -> dict[int, str]:
    out = {}
    for line in open(path):
        r = json.loads(line)
        out[r["account"]] = r["persona"]
    return out


def main(argv: list[str] | None = None) -> int:
    ap = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    ap.add_argument("--bin", required=True)
    ap.add_argument("--out", required=True, type=Path)
    ap.add_argument("--arms", nargs="+", required=True)
    ap.add_argument("--gens", type=int, default=12)
    ap.add_argument("--worlds", type=int, default=24)
    ap.add_argument("--days", type=int, default=256)
    ap.add_argument("--accounts", type=int, default=64)
    ap.add_argument("--keep", type=float, default=0.25)
    ap.add_argument("--mutation", type=float, default=0.08)
    ap.add_argument("--start-sd", type=float, default=0.15, help="spread of generation 1 around today's numbers (log)")
    ap.add_argument("--limit", type=float, default=900.0)
    args = ap.parse_args(argv)
    out = args.out
    out.mkdir(parents=True, exist_ok=True)
    n = len(NAMES)
    base = np.log(np.array(DEFAULT))
    state_file = out / "state.json"
    rng = np.random.default_rng(12345)
    if state_file.exists():
        state = json.loads(state_file.read_text())
        pop = {k: np.array(v) for k, v in state["pop"].items()}
        rng = np.random.default_rng(12345 + state["gen"])
    else:
        # Generation 1: genomes spread around today's numbers (log offsets from the defaults).
        size = args.worlds * args.accounts
        pop = {k: rng.normal(0.0, args.start_sd, (size, n)) for k in NICHES}
        state = {"gen": 0}
    while state["gen"] < args.gens:
        gen = state["gen"] + 1
        started = time.time()
        gdir = out / f"g{gen:02d}"
        gdir.mkdir(exist_ok=True)
        seeds = [70000 + gen * args.worlds + i for i in range(args.worlds)]
        probes = {s: (run(args, s, 1, gdir / f"p{s}.jsonl", []), gdir / f"p{s}.jsonl") for s in seeds}
        for p, _ in probes.values():
            p.wait()
        roles = {s: personas(path) for s, (_, path) in probes.items()}
        # Deal genomes: each niche's population shuffled across the accounts of that persona in every world.
        drawn = {k: rng.permutation(len(pop[k])) for k in NICHES}
        cursor = {k: 0 for k in NICHES}
        carried: dict[int, dict[int, np.ndarray]] = {}
        procs = {}
        for s in seeds:
            genomes = np.zeros((args.accounts, n), dtype="<f4")
            carried[s] = {}
            for a in range(args.accounts):
                k = roles[s].get(a, "miner")
                k = k if k in NICHES else "miner"
                g = pop[k][drawn[k][cursor[k] % len(pop[k])]]
                cursor[k] += 1
                genomes[a] = g
                carried[s][a] = g
            genomes.tofile(gdir / f"genome-{s}.f32")
            procs[s] = (run(args, s, args.days, gdir / f"s{s}.jsonl", [f"params-es={gdir / f'genome-{s}.f32'}:1:1"]), gdir / f"s{s}.jsonl")
        for p, _ in procs.values():
            p.wait()
        # Selection within (world, niche): ranks, so a rich world does not breed more than a poor one.
        survivors = {k: [] for k in NICHES}
        scores = {k: [] for k in NICHES}
        mismatched = 0
        for s, (p, path) in procs.items():
            if p.returncode:
                continue
            fin = finals(path)
            for k in NICHES:
                members = [(a, sc) for a, (persona, sc) in fin.items() if persona == k]
                mismatched += sum(1 for a, (persona, _) in fin.items() if roles[s].get(a) not in (None, persona))
                if len(members) < 2:
                    continue
                members.sort(key=lambda m: m[1], reverse=True)
                top = members[: max(1, int(round(len(members) * args.keep)))]
                survivors[k] += [carried[s][a] for a, _ in top]
                scores[k] += [sc for _, sc in members]
        record = {"gen": gen, "seconds": round(time.time() - started), "persona_mismatch": mismatched, "niches": {}}
        summary = {}
        for k in NICHES:
            if not survivors[k]:
                continue
            parents = np.array(survivors[k])
            size = len(pop[k])
            children = parents[rng.integers(0, len(parents), size)] + rng.normal(0.0, args.mutation, (size, n))
            pop[k] = children
            mean = np.exp(base + parents.mean(0))
            summary[k] = {"mean": dict(zip(NAMES, mean.round(4).tolist())), "sd_log": dict(zip(NAMES, parents.std(0).round(3).tolist())),
                          "parents": len(parents), "median_ln_score": float(np.median(scores[k]))}
            record["niches"][k] = {"median_ln_score": round(float(np.median(scores[k])), 3), "parents": len(parents),
                                   "mean_shift_log": dict(zip(NAMES, parents.mean(0).round(3).tolist()))}
        (out / f"gen{gen:02d}.json").write_text(json.dumps(summary, indent=1))
        state = {"gen": gen, "pop": {k: v.tolist() for k, v in pop.items()}}
        state_file.write_text(json.dumps(state))
        with open(out / "evolve.jsonl", "a") as f:
            f.write(json.dumps(record) + "\n")
        for path in gdir.glob("*.jsonl*"):
            path.unlink()
        print(f"gen {gen}: {time.time() - started:.0f}s, mismatched personas {mismatched}", flush=True)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
