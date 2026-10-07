#!/usr/bin/env python3
"""What a finished (or staged) universe reached: object levels, missions, moons, battles, alliances.

    python3 reach.py end.sqlite [start.sqlite]

With a start database the output shows what was gained since; late-game objects nobody holds are listed as MISSING.
"""
import sqlite3
import sys

BUILD = "nano_factory terraformer lunar_base sensor_phalanx jump_gate space_dock missile_silo alliance_depot fusion_plant research_lab shipyard".split()
SHIPS = "battle_ship battlecruiser bomber destroyer deathstar reaper pathfinder recycler colony_ship large_cargo cruiser".split()
DEF = "plasma_turret gauss_cannon ion_cannon large_shield_dome anti_ballistic_missile interplanetary_missile".split()
TECH = "plasma_technology hyperspace_technology hyperspace_drive astrophysics intergalactic_research_network graviton_technology computer_technology ion_technology".split()
IDS = {"light_fighter": 204, "heavy_fighter": 205, "cruiser": 206, "battle_ship": 207, "colony_ship": 208, "recycler": 209, "bomber": 211, "destroyer": 213, "deathstar": 214, "battlecruiser": 215, "reaper": 218, "pathfinder": 219, "large_cargo": 203, "rocket_launcher": 401, "light_laser": 402, "heavy_laser": 403, "gauss_cannon": 404, "ion_cannon": 405, "plasma_turret": 406, "small_shield_dome": 407, "large_shield_dome": 408, "anti_ballistic_missile": 502, "interplanetary_missile": 503}
MISSION = {1: "attack", 2: "acs_attack", 3: "transport", 4: "deploy", 5: "hold", 6: "espionage", 7: "colonise", 8: "recycle", 9: "moon_destroy", 10: "missile", 15: "expedition"}


def summary(path):
    c = sqlite3.connect(path)
    out = {}
    for col in BUILD + SHIPS + DEF:
        peak, total = c.execute(f"select coalesce(max({col}),0), coalesce(sum({col}),0) from planets").fetchone()
        # A unit paid for and still in the yard counts: the yard is slower than the money in a short test.
        queued = c.execute("select coalesce(sum(object_amount),0) from unit_queues where processed=0 and object_id=?", (IDS.get(col, -1),)).fetchone()[0]
        out[col] = (peak, total + queued)
    for col in TECH:
        out[col] = c.execute(f"select coalesce(max({col}),0), count(case when {col}>0 then 1 end) from users_tech").fetchone()
    out["moons"] = (c.execute("select count(*) from planets where planet_type=3").fetchone()[0], 0)
    out["battles"] = (c.execute("select count(*) from battle_reports").fetchone()[0], 0)
    out["alliance_members"] = (c.execute("select coalesce(max(n),0) from (select count(*) n from alliance_members group by alliance_id)").fetchone()[0], 0)
    missions = dict(c.execute("select mission_type, count(*) from fleet_missions group by 1").fetchall())
    return out, {MISSION.get(k, k): v for k, v in missions.items()}


def main():
    end, missions = summary(sys.argv[1])
    start = summary(sys.argv[2])[0] if len(sys.argv) > 2 else None
    print("missions:", " ".join(f"{k}={v}" for k, v in sorted(missions.items(), key=lambda kv: -kv[1])))
    missing = []
    for name, (peak, total) in end.items():
        was = start[name] if start else (0, 0)
        mark = "" if peak or total else "  MISSING"
        if mark:
            missing.append(name)
        print(f"  {name:28} max {peak:>8}  total {total:>10}" + (f"  (start max {was[0]}, total {was[1]})" if start else "") + mark)
    print("MISSING:", " ".join(missing) or "none")


main()
