"""Our exports set to the typical real speed-8 universe (196 PUB serverData files, 29 Oct 2026), the way the host
applies each setting: research_speed 2 (PlanetService::getTechnologyResearchTime divides before it truncates, so
floor(floor(x)/2) is the host's floor(x/2)), planet_fields_bonus 30 (added to every drawn size), debris from ships 60%
with deuterium, deuterium consumption 0.5, fleet speed war 2 / peaceful 5 / holding 3 (each mission's FleetSpeedType),
6 galaxies. Usage: python real_universe.py SRC_DATA_DIR OUT_DIR (storage/rl/ogsim real8)"""
import json, math, os, shutil, sys

src, out = sys.argv[1:3]
WAR, PEACEFUL, HOLDING = 2, 5, 3
SPEEDS = {"1": WAR, "2": WAR, "6": WAR, "8": WAR, "9": WAR, "3": PEACEFUL, "4": PEACEFUL, "7": PEACEFUL, "15": PEACEFUL, "5": HOLDING}
BONUS_FIELDS, DEBRIS, FUEL, GALAXIES = 30, 60, 0.5, 6

def load(p): return json.load(open(os.path.join(src, p)))
def save(p, d):
    os.makedirs(os.path.dirname(os.path.join(out, p)), exist_ok=True)
    json.dump(d, open(os.path.join(out, p), "w"), separators=(",", ":"))
half = lambda x: max(1, math.floor(x / 2))

econ = load("units-parity/econ-u1-colonies.json")
for r in econ["research"]:
    r["build_seconds"] = [half(x) for x in r["build_seconds"]]
    r["build_seconds_grid"] = [[half(x) for x in row] for row in r["build_seconds_grid"]]
for p in econ["planets"]:
    p["field_max"] += BONUS_FIELDS
for s in econ["colonisation"]["sites"]:
    s["fields"] += BONUS_FIELDS
econ["colonisation"]["field_ranges"] = {k: [a + BONUS_FIELDS, b + BONUS_FIELDS] for k, (a, b) in econ["colonisation"]["field_ranges"].items()}
save("units-parity/econ-u1-colonies.json", econ)

for suffix in ("", "-class2", "-class3"):
    f = load(f"fleet-settings-u1{suffix}.json")
    f["mission_fleet_speed"] = {k: SPEEDS[k] for k in f["mission_fleet_speed"]}
    f["deuterium_consumption"] = FUEL
    f["number_of_galaxies"] = GALAXIES
    save(f"fleet-settings-u1{suffix}.json", f)
    c = load(f"combat-u1{suffix}.json")
    c["rules"].update(debris_ships_percentage=DEBRIS, debris_deuterium=True, fleet_speed_war=WAR,
                      deuterium_consumption=c["rules"]["deuterium_consumption"] * FUEL)
    c["expedition"]["fleet_speed_peaceful"] = PEACEFUL
    save(f"combat-u1{suffix}.json", c)

for p in ("units-parity/units-u1.json", "login-gaps.json"):
    shutil.copy(os.path.join(src, p), os.path.join(out, p))
print("written", out)
