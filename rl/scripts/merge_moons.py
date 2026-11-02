"""One catalogue for planets and moons (WORLD-SIM-PLAN M2): the planets' export plus `ai:rl-catalogue --moons`.

    merge_moons.py PLANETS.json MOONS.json OUT.json

Each object gets `valid_types` from which export carries it (the host's own `objectValidPlanetType` filtered both), and
the moons join `planets` flagged `moon`, one per home in the same order, after every planet config. A moon-only
object's build-time grid is over the robot factory alone; it is spread over the planets' modifiers, which is exact on a
moon because no other modifier stands there. An object on both types must give the moon's grid back at those levels:
the merge checks it, so a host whose moon times differ stops here.
"""
import itertools, json, sys


def main(planets_path, moons_path, out):
    planets, moons = json.load(open(planets_path)), json.load(open(moons_path))
    mods = [m["id"] for m in planets["time_modifiers"]]
    moon_mods = [m["id"] for m in moons["time_modifiers"]]
    assert set(moon_mods) <= set(mods), (mods, moon_mods)
    side = planets["time_grid_level"] + 1
    assert moons["time_grid_level"] + 1 == side
    combos = list(itertools.product(range(side), repeat=len(mods)))

    def spread(grid):
        # The planets' row for every combination whose levels of the moon's own modifiers pick the moon's cell.
        at = lambda combo: sum(combo[mods.index(m)] * side ** (len(moon_mods) - 1 - i) for i, m in enumerate(moon_mods))
        return [grid[at(c)] for c in combos]

    on_moon = {o["id"]: o for o in moons["objects"]}
    for o in planets["objects"]:
        o["valid_types"] = ["planet"] + (["moon"] if o["id"] in on_moon else [])
        if o["id"] in on_moon:
            mine = [[row[i] for i, c in enumerate(combos) if all(c[mods.index(m)] == 0 for m in mods if m not in moon_mods)] for row in o["build_seconds_grid"]]
            theirs = on_moon[o["id"]]["build_seconds_grid"]
            assert mine[:len(theirs)] == theirs[:len(mine)], f"object {o['id']}: a moon builds it in other times than a planet"
    known = {o["id"] for o in planets["objects"]}
    for o in moons["objects"]:
        if o["id"] in known: continue
        o["valid_types"] = ["moon"]
        o["build_seconds_grid"] = [spread(row) for row in o["build_seconds_grid"]]
        planets["objects"].append(o)
    units = len(planets["units"])
    for m in moons["planets"]:
        m.update(moon=True, production={}, production_research={}, unit_counts=[0] * units, unit_production=[])
    planets["moon_configs"] = len(planets["planets"])
    planets["planets"] += moons["planets"]
    json.dump(planets, open(out, "w"))
    print(out, "objects", len(planets["objects"]), "configs", len(planets["planets"]), "moons from", planets["moon_configs"])


if __name__ == "__main__":
    main(*sys.argv[1:])
