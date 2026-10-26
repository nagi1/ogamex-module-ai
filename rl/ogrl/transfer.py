"""The S2a comparator (RL-FLOW row 5): is the S1 gain a learner, or a weak yardstick?

Row 4's +104.6% is measured against the simulator's player rule, PHP's parity (median 0.99) against the PHP teacher.
This replays the teacher's own recorded building choices in the simulator, on its start planet and login times, and
reports per account: Y = player rule / teacher replay - 1 (the yardstick gap), SIM_T = e4 live / teacher replay - 1
(the like-for-like transfer gap), F = |teacher replay / the teacher's recorded PHP value - 1| (replay fidelity).

Research is the player rule in all three arms, as the row says. bonus_hours is 0 because the PHP value is a point in
time, with no end-of-horizon production added.

    python -m ogrl.transfer --recordings 'storage/rl/m5-eval7/teacher/choices-*.jsonl' --hours 72 \
        --catalogues 'storage/rl/ogsim/s2/cat-*.json' --model storage/rl/ogsim/runs/flow/e4-seed1/model.pt \
        --out storage/rl/ogsim/runs/flow/e5
"""

from __future__ import annotations

import argparse
import glob
import json
from collections import defaultdict
from pathlib import Path

import numpy as np

import ogsim

from .econ import QUEUE, ModelPolicy

HOURS = 72.0


def recordings(pattern: str) -> dict[tuple[int, int], dict]:
    """(universe seed, player) -> its start planet, login times, building choices and recorded PHP value."""
    found: dict[tuple[int, int], dict] = {}
    for path in sorted(glob.glob(pattern)):
        for line in Path(path).read_text().splitlines():
            r = json.loads(line)
            at = found.setdefault((r["seed"], r["player"]), {"planet": r["planet"], "logins": set(), "buildings": [], "php": None, "last": -1.0})
            at["logins"].add(r["t"])
            if r["kind"] == "building":
                at["buildings"].append((r["t"], r["objects"][r["chosen"]]))
            if r["t"] >= at["last"]:
                at["last"], at["php"] = r["t"], r["value"]
    return found


def catalogue_maps(pattern: str) -> dict[int, tuple[str, dict[int, int], dict[int, int]]]:
    """universe seed -> (path, planet id -> planet index, object id -> candidate index)."""
    out = {}
    for path in sorted(glob.glob(pattern)):
        raw = json.loads(Path(path).read_text())
        out[int(Path(path).stem.rsplit("-", 1)[1])] = (path, {p["planet_id"]: i for i, p in enumerate(raw["planets"])}, {o["id"]: i for i, o in enumerate(raw["objects"])})
    return out


def play(catalogue: str, planet: int, logins: list[float], policy, hours: float = HOURS) -> tuple[float, int]:
    """One account played to the horizon at its own login times: (final value, invalid actions)."""
    env = ogsim.VecEnv(catalogue, [planet], 1, horizon_hours=hours, bonus_hours=0.0, seed=0, eval=True, queue=QUEUE, login_times=logins)
    while not env.all_done():
        env.step(policy(env))
    finished = env.take_finished()
    return finished[0][1], finished[0][4]


class Replay:
    """The teacher's recorded building choices in order; the player rule once they run out or one is refused."""

    def __init__(self, choices: list[int | None]):
        self.choices, self.at, self.refused, self.beyond = choices, 0, 0, 0

    def __call__(self, env) -> list[int]:
        legal = np.frombuffer(env.observe()[2], dtype=np.uint8).astype(bool)
        if self.at >= len(self.choices):
            self.beyond += 1
            return env.baseline("player")
        k = self.choices[self.at]
        self.at += 1
        if k is None or not legal[k]:
            self.refused += 1
            k = int(np.flatnonzero(legal)[0])
        return [k]


def iqm(values) -> float:
    a = np.sort(np.asarray(values, dtype=float))
    trim = int(np.floor(0.25 * len(a)))
    return float(a[trim : len(a) - trim].mean())


def bootstrap(values, strata, draws: int = 10_000, seed: int = 0) -> tuple[float, float]:
    """Stratified bootstrap (resample accounts within each universe) of the IQM."""
    rng = np.random.default_rng(seed)
    groups: dict[int, list[float]] = defaultdict(list)
    for v, s in zip(values, strata):
        groups[s].append(v)
    pools = {s: np.array(v, dtype=float) for s, v in groups.items()}
    means = [iqm(np.concatenate([rng.choice(p, size=len(p)) for p in pools.values()])) for _ in range(draws)]
    return float(np.percentile(means, 2.5)), float(np.percentile(means, 97.5))


def main(argv: list[str] | None = None) -> None:
    parser = argparse.ArgumentParser(prog="ogrl.transfer")
    parser.add_argument("--recordings", required=True)
    parser.add_argument("--catalogues", required=True)
    parser.add_argument("--model", required=True)
    parser.add_argument("--hours", type=float, default=HOURS)
    parser.add_argument("--out", required=True)
    parser.add_argument("--draws", type=int, default=10_000)
    args = parser.parse_args(argv)

    cats = catalogue_maps(args.catalogues)
    accounts = recordings(args.recordings)
    model = ModelPolicy(args.model)
    rows = []
    for (seed, player), account in sorted(accounts.items()):
        if seed not in cats:
            continue
        path, planets, objects = cats[seed]
        if account["planet"] not in planets:
            continue
        planet = planets[account["planet"]]
        start = min(account["logins"])
        logins = [t - start for t in sorted(account["logins"])]
        replay = Replay([objects.get(oid) for _, oid in account["buildings"]])
        teacher, _ = play(path, planet, logins, replay, args.hours)
        rule, _ = play(path, planet, logins, lambda env: env.baseline("player"), args.hours)
        live, _ = play(path, planet, logins, model, args.hours)
        rows.append({"seed": seed, "player": player, "planet": account["planet"], "buildings": len(replay.choices),
                     "php": account["php"], "teacher": teacher, "rule": rule, "model": live, "refused": replay.refused,
                     "beyond": replay.beyond, "y": rule / teacher - 1.0, "sim_t": live / teacher - 1.0, "f": abs(teacher / account["php"] - 1.0)})

    out = Path(args.out)
    out.mkdir(parents=True, exist_ok=True)
    (out / "accounts.json").write_text(json.dumps(rows, indent=1))
    if not rows:
        raise SystemExit("no account could be placed on a catalogue planet; check the planet ids")
    chosen, refused, beyond = (sum(r[k] for r in rows) for k in ("buildings", "refused", "beyond"))
    print(f"n {len(rows)} account(s) over {len({r['seed'] for r in rows})} universe(s); "
          f"{chosen} recorded building choice(s), {refused} refused ({refused / max(chosen, 1):.2%}), "
          f"{beyond} decision(s) after the recording ended")
    for name, key in (("Y  yardstick (player/teacher-1)", "y"), ("SIM_T transfer (e4/teacher-1)", "sim_t"), ("F  fidelity |replay/php-1|", "f")):
        values = [r[key] for r in rows]
        low, high = bootstrap(values, [r["seed"] for r in rows], args.draws)
        print(f"{name:30} IQM {iqm(values):+.3f}  95% CI [{low:+.3f}, {high:+.3f}]  median {np.median(values):+.3f}")


if __name__ == "__main__":
    main()
