"""The S2a comparators: row 5 (replay the PHP teacher) and row 6 (score e4 in the world simulator).

Row 5 replays the teacher's recorded building choices in the economy simulator, on its start planet and login times:
Y = player rule / teacher replay - 1, SIM_T = e4 live / teacher replay - 1, F = |teacher replay / PHP value - 1|.

Row 6 (`--world`) plays the same e4 against the player rule in `VecWorld`, one 12-account world per seed played twice
(paired twins): the controlled slots decide their building queue by e4 in one arm and by the rule in the other, ships
and research are the rule's in both, and the measure is the controlled accounts' account value (ships at price). Before
any number it runs the row's three checks: dims against e4, byte-identical twins, and that the yard spends at all.

    python -m ogrl.transfer --recordings 'storage/rl/m5-eval7/teacher/choices-*.jsonl' --hours 72 \
        --catalogues 'storage/rl/ogsim/s2/cat-*.json' --model storage/rl/ogsim/runs/flow/e4-seed1/model.pt \
        --out storage/rl/ogsim/runs/flow/e5
    python -m ogrl.transfer --world --catalogue storage/rl/ogsim/units-parity/econ-u1.json --hours 72 --worlds 64 \
        --units storage/rl/ogsim/units-parity/units-u1.json --fleet-settings storage/rl/ogsim/fleet-settings-u1.json \
        --combat storage/rl/ogsim/combat-u1.json --login-gaps storage/rl/ogsim/login-gaps.json \
        --model storage/rl/ogsim/runs/flow/e4-seed1/model.pt --accounts 12 --controlled 6 --out storage/rl/ogsim/runs/flow/e6
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
# The evaluator's fixed world seeds (train_world.play_arm), so a world can be replayed by name.
WORLD_SEED = 10_000_000


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


def load_world(args) -> "ogsim.VecWorld":
    """One world whose first `--controlled` accounts are the caller's; ships, research and the rest are the rule's."""
    controlled = [True] * args.controlled + [False] * (args.accounts - args.controlled)
    return ogsim.VecWorld(args.catalogue, list(range(ogsim.VecEnv.planet_count(args.catalogue))), 1, controlled,
                          horizon_hours=args.hours, seed=args.seed, login_gaps=json.loads(Path(args.login_gaps).read_text()),
                          units=args.units, fleet_settings=args.fleet_settings, combat=args.combat)


def play_world(args, model) -> tuple[np.ndarray, np.ndarray]:
    """(account value, fleet part) per account after one world; the model answers the building queue only."""
    world = load_world(args)
    while True:
        rule = world.baseline()
        action = rule[0]
        if model is not None and world.decisions()[0][1] == 0:
            action = model(world)[0]
        world.step([action])
        finished = world.take_finished()
        if len(finished) >= args.accounts:
            rows = finished[: args.accounts]
            return np.array([r[1] + r[5] for r in rows]), np.array([r[5] for r in rows])


def iqm(values) -> float:
    a = np.sort(np.asarray(values, dtype=float))
    trim = int(np.floor(0.25 * len(a)))
    return float(a[trim : len(a) - trim].mean())


def bootstrap(values, strata, draws: int = 10_000, seed: int = 0, whole: bool = False) -> tuple[float, float]:
    """Bootstrap of the IQM: resample within each stratum, or (whole) resample whole strata, the unit of replication."""
    rng = np.random.default_rng(seed)
    groups: dict[int, list[float]] = defaultdict(list)
    for v, s in zip(values, strata):
        groups[s].append(v)
    pools = [np.array(v, dtype=float) for v in groups.values()]
    if whole:
        means = [iqm(np.concatenate([pools[i] for i in rng.integers(0, len(pools), len(pools))])) for _ in range(draws)]
    else:
        means = [iqm(np.concatenate([rng.choice(p, size=len(p)) for p in pools])) for _ in range(draws)]
    return float(np.percentile(means, 2.5)), float(np.percentile(means, 97.5))


def world_mode(args) -> None:
    """Row 6: e4 against the rule in paired 12-account worlds, after the row's three checks."""
    model = ModelPolicy(args.model)
    e4 = (int(model.model.state_mean.numel()), int(model.model.cand_mean.numel()))
    args.seed = WORLD_SEED
    s_dim, k, c_dim = load_world(args).dims()
    print(f"check 1 dims: world ({s_dim}, {k}, {c_dim}) vs e4 (state {e4[0]}, cand {e4[1]}) -> "
          f"{'ok' if (s_dim, c_dim) == e4 else 'FAIL, the features differ'}")
    first, twin = load_world(args), load_world(args)
    print(f"check 2 twins: one seed, the same first observation -> {'ok' if all(x == y for x, y in zip(first.observe(), twin.observe())) else 'FAIL'}")
    _, fleet = play_world(args, None)
    print(f"check 3 yard: ships on {np.mean(fleet[: args.controlled] > 0):.0%} of the controlled and "
          f"{np.mean(fleet[args.controlled :] > 0):.0%} of the rule-played accounts -> "
          f"{'ok' if np.mean(fleet > 0) > 0.5 else 'FAIL, the world adds nothing over row 5'}")

    worlds = []
    for seed in range(WORLD_SEED, WORLD_SEED + args.worlds):
        args.seed = seed
        model_value, model_fleet = play_world(args, model)
        rule_value, rule_fleet = play_world(args, None)
        ctl = slice(0, args.controlled)
        worlds.append({"seed": seed, "value": (model_value[ctl] / rule_value[ctl] - 1.0).tolist(),
                       "economy": ((model_value[ctl] - model_fleet[ctl]) / np.maximum(rule_value[ctl] - rule_fleet[ctl], 1.0) - 1.0).tolist(),
                       "fleet": (model_fleet[ctl] / np.maximum(rule_fleet[ctl], 1.0) - 1.0).tolist(),
                       "model_fleet": model_fleet[ctl].tolist(), "rule_fleet": rule_fleet[ctl].tolist()})

    out = Path(args.out)
    out.mkdir(parents=True, exist_ok=True)
    (out / "worlds.json").write_text(json.dumps(worlds, indent=1))
    strata = [r["seed"] for r in worlds for _ in range(args.controlled)]
    for name, key in (("G_w account value (e4/rule-1)", "value"), ("economy value", "economy"), ("fleet value", "fleet")):
        values = [v for r in worlds for v in r[key]]
        low, high = bootstrap(values, strata, args.draws, whole=True)
        print(f"{name:30} IQM {iqm(values):+.3f}  95% CI [{low:+.3f}, {high:+.3f}]  median {np.median(values):+.3f}  n {len(values)}")
    print("ships held by controlled accounts: model "
          f"{np.mean([f > 0 for r in worlds for f in r['model_fleet']]):.0%}, rule "
          f"{np.mean([f > 0 for r in worlds for f in r['rule_fleet']]):.0%}")


def main(argv: list[str] | None = None) -> None:
    parser = argparse.ArgumentParser(prog="ogrl.transfer")
    parser.add_argument("--recordings")
    parser.add_argument("--catalogues")
    parser.add_argument("--model", required=True)
    parser.add_argument("--hours", type=float, default=HOURS)
    parser.add_argument("--out", required=True)
    parser.add_argument("--draws", type=int, default=10_000)
    parser.add_argument("--world", action="store_true")
    parser.add_argument("--catalogue")
    parser.add_argument("--units")
    parser.add_argument("--fleet-settings", dest="fleet_settings")
    parser.add_argument("--combat")
    parser.add_argument("--login-gaps", dest="login_gaps")
    parser.add_argument("--worlds", type=int, default=64)
    parser.add_argument("--accounts", type=int, default=12)
    parser.add_argument("--controlled", type=int, default=6)
    args = parser.parse_args(argv)
    if args.world:
        world_mode(args)
        return

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
