"""The economy simulator (rl/ogsim) from Python: catalogues, baselines, host parity and evaluation.

    python -m ogrl.econ merge OUT CAT [CAT ...]          # one catalogue from several universes
    python -m ogrl.econ bench CAT                         # the deterministic baselines on every planet
    python -m ogrl.econ schedule CAT PLANET OUT [--policy payback|soonest|random|MODEL.pt]
    python -m ogrl.econ compare REPLAY.json               # host vs simulator, after `php artisan ai:rl-replay`
    python -m ogrl.econ eval CAT MODEL.pt [MODEL.pt ...]  # models against the baselines, paired by planet
"""

from __future__ import annotations

import argparse
import json
import os
from pathlib import Path

import numpy as np

import ogsim

BASELINES = ("random", "soonest", "payback", "player")
HORIZON_HOURS = 72.0
BONUS_HOURS = 24.0


def merge(out: str, paths: list[str]) -> None:
    catalogues = [json.loads(Path(p).read_text()) for p in paths]
    first = catalogues[0]
    for other, path in zip(catalogues[1:], paths[1:]):
        if other["objects"] != first["objects"] or other["time_modifiers"] != first["time_modifiers"] or other["economy_speed"] != first["economy_speed"]:
            raise SystemExit(f"{path}: a different host catalogue or speed; universes are merged only when the game is the same")
    merged = dict(first, planets=[planet for c in catalogues for planet in c["planets"]])
    Path(out).write_text(json.dumps(merged))
    print(f"merged {len(paths)} catalogue(s), {len(merged['planets'])} planet(s) -> {out}")


def observe(env: "ogsim.VecEnv") -> tuple[np.ndarray, np.ndarray, np.ndarray]:
    s_dim, k, c_dim = env.dims()
    state, cands, legal = env.observe()
    state = np.frombuffer(state, dtype=np.float32).reshape(-1, s_dim)
    return state, np.frombuffer(cands, dtype=np.float32).reshape(len(state), k, c_dim), np.frombuffer(legal, dtype=np.uint8).reshape(len(state), k).astype(bool)


class ModelPolicy:
    """A trained scorer playing greedily (argmax over legal rows)."""

    def __init__(self, path: str):
        import torch

        from .model import CandidateScorer

        saved = torch.load(path, map_location="cpu", weights_only=False)
        self.torch = torch
        self.model = CandidateScorer(saved["state_dim"], saved["cand_dim"], saved["width"])
        self.model.load_state_dict(saved["model"])
        self.model.eval()

    def __call__(self, env: "ogsim.VecEnv") -> list[int]:
        state, cands, legal = observe(env)
        with self.torch.no_grad():
            logits, _ = self.model(self.torch.from_numpy(state.copy()), self.torch.from_numpy(cands.copy()), self.torch.from_numpy(legal.copy()))
        choice = logits.argmax(dim=1).numpy()
        return [int(a) if legal[i].any() else -1 for i, a in enumerate(choice)]


def policy_for(name: str):
    return (lambda env: env.baseline(name)) if name in BASELINES else ModelPolicy(name)


def play(catalogue: str, planets: list[int], policy, seed: int = 0) -> tuple["ogsim.VecEnv", dict[int, tuple]]:
    """Every listed planet played once to the horizon; result per planet: (value, rate per hour, decisions, invalid)."""
    env = ogsim.VecEnv(catalogue, planets, 0, horizon_hours=HORIZON_HOURS, bonus_hours=BONUS_HOURS, seed=seed, eval=True)
    while not env.all_done():
        env.step(policy(env))
    return env, {f[0]: f[1:] for f in env.take_finished()}


def bench(catalogue: str) -> None:
    planets = list(range(ogsim.VecEnv.planet_count(catalogue)))
    for name in BASELINES:
        _, result = play(catalogue, planets, policy_for(name), seed=1)
        values = np.array([result[p][0] for p in planets])
        print(f"{name:8} value mean {values.mean():>12,.0f} median {np.median(values):>12,.0f}  decisions {np.mean([result[p][2] for p in planets]):.0f}  invalid {sum(result[p][3] for p in planets)}")


def schedule(catalogue: str, planet: int, out: str, policy: str) -> None:
    env, _ = play(catalogue, [planet], policy_for(policy), seed=1)
    stock, levels = env.state(0)
    meta = json.loads(Path(catalogue).read_text())["planets"][planet]
    steps = [{"start": s, "id": i, "end": e, "stock": list(st)} for s, i, e, st in env.history(0)]
    Path(out).write_text(json.dumps({
        "planet_id": meta["planet_id"], "horizon_seconds": int(HORIZON_HOURS * 3600), "steps": steps,
        "final": {"stock": list(stock), "levels": {str(i): l for i, l in levels}},
    }))
    print(f"planet {planet} (host id {meta['planet_id']}): {len(steps)} build(s) by {policy} -> {out}")


def compare(path: str) -> bool:
    """Host vs simulator for one replay; True when they agree within whole-unit rounding."""
    replay = json.loads(Path(path).read_text())
    worst, refused, time_off = 0.0, 0, 0
    for row in replay["steps"]:
        if row["host_seconds"] is None:
            refused += 1
            continue
        time_off += int(row["host_seconds"] != row["sim_seconds"])
        for sim, host in zip(row["sim_stock"], row["host_stock"]):
            worst = max(worst, abs(sim - host) / max(1.0, abs(host)))
    final_sim, final_host = replay["sim_final"]["stock"], replay["final"]["stock"]
    level_gaps = {k: (v, replay["final"]["levels"].get(k)) for k, v in replay["sim_final"]["levels"].items() if replay["final"]["levels"].get(k) != v}
    final_off = max(abs(s - h) / max(1.0, abs(h)) for s, h in zip(final_sim, final_host))
    print(f"{path}: {len(replay['steps'])} steps, host refused {refused}, build time differs {time_off}, "
          f"worst stock gap {worst:.4%}, final stock gap {final_off:.4%}, level gaps {level_gaps or 'none'}")
    print(f"  final stock sim {[round(x) for x in final_sim]} host {[round(x) for x in final_host]}")
    return refused == 0 and not level_gaps and worst < 0.01 and final_off < 0.01


def bootstrap_ci(values: np.ndarray, seed: int = 0, draws: int = 10_000) -> tuple[float, float]:
    rng = np.random.default_rng(seed)
    means = rng.choice(values, size=(draws, len(values))).mean(axis=1)
    return float(np.percentile(means, 2.5)), float(np.percentile(means, 97.5))


def evaluate(catalogue: str, models: list[str], out: str | None = None) -> dict:
    """Every model and baseline on every planet; paired per-planet relative difference against payback, with a 95% CI."""
    planets = list(range(ogsim.VecEnv.planet_count(catalogue)))
    values = {name: np.array([r[0] for _, r in sorted(play(catalogue, planets, policy_for(name), seed=1)[1].items())]) for name in (*BASELINES, *models)}
    report = {}
    for name, v in values.items():
        rel = v / values["payback"] - 1.0
        low, high = bootstrap_ci(rel)
        pl = v / values["player"] - 1.0
        pl_low, pl_high = bootstrap_ci(pl)
        report[name] = {"mean_value": float(v.mean()), "vs_payback_mean": float(rel.mean()), "ci95": [low, high], "better_share": float((rel > 0).mean()), "n": len(v),
                        "vs_player_mean": float(pl.mean()), "vs_player_ci95": [pl_low, pl_high], "vs_player_better_share": float((pl > 0).mean()), "vs_player_min": float(pl.min())}
        print(f"{Path(name).parent.name + '/' + Path(name).name if name not in BASELINES else name:32} value {v.mean():>12,.0f}  vs payback {rel.mean():+.2%} [{low:+.2%}, {high:+.2%}]  vs player {pl.mean():+.2%} [{pl_low:+.2%}, {pl_high:+.2%}] better on {(pl > 0).mean():.0%} of {len(v)}")
    if out:
        Path(out).write_text(json.dumps(report, indent=2))
    return report


def main(argv: list[str] | None = None) -> None:
    parser = argparse.ArgumentParser(prog="ogrl.econ")
    sub = parser.add_subparsers(dest="command", required=True)
    p = sub.add_parser("merge"); p.add_argument("out"); p.add_argument("catalogues", nargs="+")
    p = sub.add_parser("bench"); p.add_argument("catalogue")
    p = sub.add_parser("schedule"); p.add_argument("catalogue"); p.add_argument("planet", type=int); p.add_argument("out"); p.add_argument("--policy", default="payback")
    p = sub.add_parser("compare"); p.add_argument("replays", nargs="+")
    p = sub.add_parser("eval"); p.add_argument("catalogue"); p.add_argument("models", nargs="*"); p.add_argument("--out")
    args = parser.parse_args(argv)
    match args.command:
        case "merge": merge(args.out, args.catalogues)
        case "bench": bench(args.catalogue)
        case "schedule": schedule(args.catalogue, args.planet, args.out, args.policy)
        case "compare": raise SystemExit(0 if all([compare(r) for r in args.replays]) else 1)
        case "eval": evaluate(args.catalogue, args.models, args.out)


if __name__ == "__main__":
    os.environ.setdefault("CUDA_DEVICE_ORDER", "PCI_BUS_ID")
    main()
