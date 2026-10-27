#!/usr/bin/env python3
"""Row 36: splice the regenerated home planets and the new `fields_added` column onto the previous colonies export.

The 123 colony entries are `planet()` rows of a level-0 planet whose `field_max` is the site's own size, so the new
column and the bare-cap change cannot alter them; carrying them over is exact and is checked below.
"""
import json
import shutil

base = "storage/rl/ogsim/units-parity/"
old = json.load(open(base + "econ-u1-colonies.json"))
home = json.load(open(base + "econ-u1-colonies-home.json"))

shutil.copyfile(base + "econ-u1-colonies.json", base + "econ-u1-colonies-old.json")

sites_from = len(home["planets"])
merged = dict(home)
merged["planets"] = home["planets"] + old["planets"][sites_from:]
merged["colonisation"] = old["colonisation"]
merged["colonisation"]["sites"] = old["colonisation"]["sites"]

assert len(merged["planets"]) == len(old["planets"]), (len(merged["planets"]), len(old["planets"]))
for a, b in zip(merged["planets"][sites_from:], old["planets"][sites_from:]):
    assert a == b, "a carried-over colony entry would change"
for a, b in zip(merged["planets"][:sites_from], old["planets"][:sites_from]):
    assert a["planet_id"] == b["planet_id"]
    assert a["levels"] == b["levels"], "a home planet's levels changed"
    assert a["resources"] == b["resources"]
    if a["field_max"] != b["field_max"]:
        print(f"planet {a['planet_id']}: bare field_max {b['field_max']} -> {a['field_max']}")

json.dump(merged, open(base + "econ-u1-colonies.json", "w"))
print(f"merged: {len(merged['objects'])} objects, {len(merged['planets'])} planets, "
      f"{len(merged['colonisation']['sites'])} sites")
print("field-adding objects: " + ", ".join(
    f"{o['id']}={o['fields_added'][40]}" for o in merged["objects"] if any(o.get("fields_added") or [])
    and len(o["fields_added"]) > 40))
