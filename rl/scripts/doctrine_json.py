"""The season's doctrine data: each persona's fleet and defence templates from resources/doctrine, by host unit id.

    python3 doctrine_json.py UNITS.json OUT.json   (system Python: the rl venv has no yaml)

The simulator names no unit (gate 1); the doctrine files do, and this turns their names into the host's ids. A persona
reads the doctrine of the archetype it plays: the miner a miner's, the raider a raider's, the saver a fleeter's.
"""
import json, pathlib, sys
import yaml

DOCTRINE = pathlib.Path(__file__).resolve().parents[2] / "resources" / "doctrine"
# (archetype, defence weight): the defence value a persona keeps per unit of fleet value. A miner walls its planets as
# a turtle does, a fleeter keeps a light wall and its fleet away. Taste, checked against the real mil_share band.
PERSONAS = {"miner": ("miner", 1.0), "raider": ("raider", 0.25), "saver": ("fleeter", 0.25)}


def main(units, out):
    ids = {u["machine_name"]: u["id"] for u in json.load(open(units))["units"]}
    data = {}
    # The documented ceiling on one planet's standing wall, the rule the PHP player follows (defence-doctrines.yaml).
    ceiling = yaml.safe_load(open(DOCTRINE.parent / "behavior" / "defence-doctrines.yaml"))["standing_wall_ceiling"]["units"]
    for persona, (archetype, defence_weight) in PERSONAS.items():
        d = yaml.safe_load(open(DOCTRINE / f"{archetype}.yaml"))
        data[persona] = {key: [[ids[name], share] for name, share in d.get(f"{key}_template", {}).items() if name in ids]
                         for key in ("fleet", "defence")} | {"defence_weight": defence_weight, "wall_ceiling": ceiling}
    json.dump(data, open(out, "w"), indent=1)
    print(out, {p: {k: len(v) if isinstance(v, list) else v for k, v in t.items()} for p, t in data.items()})


if __name__ == "__main__":
    main(*sys.argv[1:])
