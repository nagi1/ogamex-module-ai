#!/usr/bin/env python3
"""Weird-behaviour detector: ranks the oddities of a season's top10 accounts against our own collected sources.

    .venv-rl/bin/python Modules/AI/rl/scripts/anomaly_report.py RUN_DIR [--top N] [--pattern 'ready-*']

Reads every `<world>.jsonl.levels.json` of RUN_DIR (last-day state per account: planets, moons, levels, units, res)
and, when present, the `<world>.jsonl` day rows (season event totals) and `<world>.jsonl.battles.jsonl` (fights).
The top N accounts of each world by `total` are judged (the model is judged on them). Writes RUN_DIR/anomalies.md
and RUN_DIR/anomalies.json, deterministic for a given input (no clock in the files).

Checks (each anomaly = one object x one check, aggregated over the judged accounts):
1. magnitude   a unit count on a planet or moon above every real side of the OGMEM archive with that unit (its max),
               shown against the real p50/p90 when present; combat research levels (109/110/111) against the
               Weapons/Shields/Armour % of every fleet in the raw OGMEM reports; planets and moons per account
               against the real highscore band (real-bands.json, top10 cell of the season's age).
   cap         an explicit documented cap or threshold: Graviton > 1 (WIK-175), defence units per planet > 20,000
               (defence-doctrines.yaml standing_wall_ceiling), satellites per account beyond the one-off Graviton L1
               need at the hottest owned slot (300,000 energy, WIK-237 / WIK-139, host formula (Tmax+140)/6).
2. never used  units real players field (present in >= 3% of OGMEM sides) that no judged account holds; catalogue
               buildings/research nobody reaches; event kinds (expedition, phalanx, jump, missile, ...) that no
               judged account used all season.
3. principle   measurable from the dumps: resources banked on planets (> 24 h of production), alliance depot without
               an alliance, missile silo without missiles, a military fleet parked on a planet that has a moon,
               fields over the cap; from battles.jsonl: contested attacks under the real p10 odds (1.6) and light
               hulls above the real p90 share in attack fleets >= 1e8.
   consistency dump/simulator oddities (moons carrying research levels that differ from the account's).
4. evidence    for every anomaly, the cached corpus index (anomaly_corpus.py) is searched for the object's names
               together with cue words of the anomaly's kind; up to 3 verbatim passages with source id and path are
               attached. None found -> "unsupported - needs owner".

Severity: `excess` is log10 of how far past the reference the median affected value sits, in resource-cost terms
(a unit count ratio; for a level, the cumulative-cost ratio of the levels above the cap); never-used reads
1 + 2 x real share; principles have their own excess (stated per check). score = excess x (0.4 + 0.6 x share of
judged accounts affected) + 0.5 for an explicit documented cap.
"""
from __future__ import annotations

import argparse
import collections
import json
import math
import statistics
import sys
import time
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
import anomaly_corpus as corpus  # noqa: E402
from ogmem_strategies import ID as OGMEM_ID  # noqa: E402
from real_bands import age_bucket_of  # noqa: E402
import war_cells  # noqa: E402

ROOT = corpus.ROOT
REAL_BANDS = ROOT / "storage/rl/ogsim/real-bands.json"

BUILDINGS = {1: "Metal Mine", 2: "Crystal Mine", 3: "Deuterium Synthesizer", 4: "Solar Plant", 12: "Fusion Reactor",
             14: "Robotics Factory", 15: "Nanite Factory", 21: "Shipyard", 22: "Metal Storage", 23: "Crystal Storage",
             24: "Deuterium Tank", 31: "Research Lab", 33: "Terraformer", 34: "Alliance Depot", 36: "Space Dock",
             41: "Lunar Base", 42: "Sensor Phalanx", 43: "Jump Gate", 44: "Missile Silo"}
RESEARCH = {106: "Espionage Technology", 108: "Computer Technology", 109: "Weapons Technology",
            110: "Shielding Technology", 111: "Armour Technology", 113: "Energy Technology",
            114: "Hyperspace Technology", 115: "Combustion Drive", 117: "Impulse Drive", 118: "Hyperspace Drive",
            120: "Laser Technology", 121: "Ion Technology", 122: "Plasma Technology",
            123: "Intergalactic Research Network", 124: "Astrophysics", 199: "Graviton Technology"}
UNITS = {v: k for k, v in OGMEM_ID.items()} | {502: "Anti-Ballistic Missile", 503: "Interplanetary Missile"}
NAMES = UNITS | BUILDINGS | RESEARCH
ALIASES = {199: ["graviton"], 212: ["solar satellite", "satellite", "sats"], 210: ["probe", "spy probe"],
           214: ["death star", "deathstar", "RIP"], 123: ["IRN", "research network"], 124: ["astro"],
           502: ["ABM", "anti-ballistic missile"], 503: ["IPM", "interplanetary missile"],
           111: ["armor technology", "armour tech", "armor tech"], 109: ["weapon technology", "weapons tech"],
           110: ["shield technology", "shields technology", "shielding tech"], 42: ["phalanx"], 41: ["moon base"],
           15: ["nanite", "nanites"], 14: ["robotics", "robot factory"], 12: ["fusion plant", "fusion"],
           22: ["metal store"], 23: ["crystal store"], 24: ["deuterium store", "deuterium storage"],
           31: ["research lab", "lab"], 204: ["LF"], 205: ["HF"], 207: ["BS"], 215: ["BC"], 202: ["SC"],
           203: ["LC"], 209: ["recycler", "recs"], 208: ["colony ship", "colonyship"], 401: ["RL"],
           402: ["LL"], 403: ["HL"], 404: ["gauss"], 406: ["plasma turret"], 407: ["small dome"],
           408: ["large dome"], 217: ["crawler"], 113: ["energy tech"], 114: ["hyperspace tech"],
           122: ["plasma tech", "plasma technology"], 106: ["espionage tech", "espionage technology"]}
DEFENCE = set(range(401, 409))
SATELLITE = 212
NON_FLEET = DEFENCE | {SATELLITE, 217, 502, 503}
UNIT_PRICE = {k: v for k, v in war_cells.PRICE_BY_ID.items()} | {502: 10000.0, 503: 25000.0, 217: 5000.0}
# research cost factor per level (graviton is the energy research that triples; the rest double)
COST_FACTOR = collections.defaultdict(lambda: 2.0, {199: 3.0, 124: 1.75})
# host PlanetServiceFactory: max-temperature range per slot; satellite energy = floor((Tmax + 140) / 6)
SLOT_TMAX = {1: (220, 260), 2: (170, 210), 3: (120, 160), 4: (70, 110), 5: (60, 100), 6: (50, 90), 7: (40, 80),
             8: (30, 70), 9: (20, 60), 10: (10, 50), 11: (0, 40), 12: (-10, 30), 13: (-50, -10), 14: (-90, -50),
             15: (-130, -90)}
GRAVITON_ENERGY = 300_000
WALL_CEILING = war_cells.WALL_CEILING
BANK_HOURS = 24.0
PARKED_VALUE = 1e9
MIN_REAL_SIDES = 5
NEVER_SHARE = 0.03
EVENTS = ["expedition", "raid_sent", "spy_sent", "recycle", "colonise", "acs_attack", "acs_defend", "missile",
          "phalanx", "jump", "moon_made", "moon_shot", "relocated", "wreck_repaired", "fights"]
EVENT_NAMES = {"expedition": ["expedition"], "raid_sent": ["raid", "raiding", "farm"], "spy_sent": ["espionage", "spy"],
               "recycle": ["recycler", "debris"], "colonise": ["colonize", "colonise", "colony"],
               "acs_attack": ["ACS attack", "ACS"], "acs_defend": ["ACS defend", "ACS", "hold"],
               "missile": ["IPM", "interplanetary missile", "missile attack"], "phalanx": ["phalanx"],
               "jump": ["jump gate"], "moon_made": ["moon", "moonshot"], "moon_shot": ["moon destruction", "destroy mission", "moon destroy"],
               "relocated": ["relocate", "relocation"], "wreck_repaired": ["wreck", "wreckage"],
               "fights": ["attack", "combat"]}

CUES = {
    "too_much": ["not useful", "useless", "no use", "only", "abandon", "abandoned", "level 1", "max", "maximum",
                 "ceiling", "never", "no more", "large amount", "enough", "too many", "waste", "limit", "stop",
                 "purpose", "pointless", "not worth", "cap", "beyond", "more than", "required", "need"],
    "too_little": ["should", "need", "more", "at least", "minimum", "important", "essential", "must"],
    "never_used": ["should", "useful", "important", "essential", "must", "key", "allows", "need", "best", "vital",
                   "recommend", "always", "every", "advantage", "protect", "crucial", "profit", "use"],
}


# ---------------------------------------------------------------- inputs

def load_world(levels_path: Path, top: int) -> tuple[str, list[dict], dict]:
    world = levels_path.name[: -len(".jsonl.levels.json")]
    accounts = json.loads(levels_path.read_text())
    accounts.sort(key=lambda a: (-a["total"], a["account"]))
    judged = accounts[:top]
    for a in judged:
        a["_world"] = world
    events, last = season_events(levels_path.with_name(world + ".jsonl"), {a["account"] for a in judged})
    for a in judged:
        a["_events"] = events.get(a["account"], {})
        a["_last"] = last.get(a["account"], {})
        # levels.json carries alliance 0 for everyone; the day row is the account's real membership
        a["_alliance"] = bool(a["_last"].get("alliance", a.get("alliance")))
    return world, judged, {"accounts": len(accounts)}


def season_events(path: Path, accounts: set) -> dict:
    """Season totals of events_today per judged account; lines are prefiltered on their leading account key."""
    out, last = collections.defaultdict(collections.Counter), {}
    if not path.exists():
        return out, last
    prefixes = tuple(f'{{"account":{a},' for a in accounts)
    with path.open(encoding="utf-8") as h:
        for line in h:
            if not line.startswith(prefixes):
                continue
            row = json.loads(line)
            for k, v in (row.get("events_today") or {}).items():
                if isinstance(v, (int, float)):
                    out[row["account"]][k] += v
            if row["day"] >= last.get(row["account"], {}).get("day", -1):
                last[row["account"]] = {k: row.get(k) for k in ("day", "alliance", "planets", "moons")}
    return out, last


def research_of(acc: dict) -> dict[int, int]:
    lv = collections.Counter()
    for p in acc["planets"]:
        for k, v in p["levels"].items():
            if int(k) in RESEARCH:
                lv[int(k)] = max(lv[int(k)], v)
    return dict(lv)


def bodies(acc: dict):
    """(kind, planet index, body) for every planet and moon."""
    for i, p in enumerate(acc["planets"]):
        yield "planet", i, p
        if p.get("moon"):
            yield "moon", i, p["moon"]


def ident(acc: dict, kind: str = "", i: int | None = None, body: dict | None = None) -> str:
    s = f"{acc['_world']}#{acc['account']}"
    if body is not None:
        s += f" {kind} [{body.get('galaxy')}:{body.get('system')}:{int(body.get('position') or 0)}]"
    return s


# ---------------------------------------------------------------- real references

def unit_reference(index: dict) -> dict[int, dict]:
    stocks = index["real"]["stocks"]
    by = collections.defaultdict(list)
    for s in stocks:
        for u, n in s["units"].items():
            by[int(u)].append(n)
    ref = {}
    for u, v in by.items():
        v.sort()
        ref[u] = {"n": len(v), "sides": len(stocks), "share": len(v) / len(stocks), "p50": v[len(v) // 2],
                  "p90": v[min(len(v) - 1, int(.9 * len(v)))], "max": v[-1]}
    return ref


def tech_reference(index: dict) -> dict[int, dict]:
    t = index["real"]["tech"]
    out = {}
    for col, rid in ((1, 109), (2, 110), (3, 111)):
        v = sorted(r[col] / 10 for r in t)
        if v:
            out[rid] = {"n": len(v), "p50": v[len(v) // 2], "p90": v[int(.9 * len(v))], "max": v[-1]}
    return out


def account_bands(day: int) -> tuple[str, dict]:
    d = json.loads(REAL_BANDS.read_text())
    cell = f"top10|{age_bucket_of(day)}"
    c = d["cells"].get(cell)
    return cell, c or {}


# ---------------------------------------------------------------- anomalies

class Board:
    def __init__(self, judged: list[dict]):
        self.judged = judged
        self.n = len(judged)
        self.items: dict[str, dict] = {}

    def add(self, key: str, *, check: str, kind: str, obj: int | str, scope: str, acc: dict, where: str,
            value: float, reference: dict, excess: float, why: str, cue: str = "too_much", documented: str = "",
            context: list[str] = (), cues: list[str] | None = None, pin: str = ""):
        a = self.items.setdefault(key, {
            "key": key, "check": check, "kind": kind, "object_id": obj,
            "object": NAMES.get(obj, str(obj)) if isinstance(obj, int) else obj, "scope": scope,
            "reference": reference, "why": why, "cue": cue, "documented": documented, "context": list(context),
            "cues": list(cues) if cues else None, "pin": pin, "_accounts": set(), "_values": [], "_excess": [], "_examples": []})
        a["_accounts"].add((acc["_world"], acc["account"]))
        a["_values"].append(value)
        a["_excess"].append(excess)
        a["_examples"].append((value, where))

    def finish(self) -> list[dict]:
        out = []
        for a in self.items.values():
            share = len(a["_accounts"]) / self.n
            excess = max(0.0, min(6.0, statistics.median(a["_excess"])))
            score = excess * (0.4 + 0.6 * share) + (0.5 if a["documented"] else 0.0)
            ex = sorted(a["_examples"], key=lambda x: (-x[0], x[1]))
            vals = sorted(a["_values"])
            rec = {k: v for k, v in a.items() if not k.startswith("_")}
            rec |= {"accounts_affected": len(a["_accounts"]), "accounts_judged": self.n,
                    "share": round(share, 3), "instances": len(vals), "value_median": round(statistics.median(vals), 3),
                    "value_max": round(vals[-1], 3), "excess": round(excess, 3), "score": round(score, 3),
                    "examples": [f"{w} = {fmt(v)}" for v, w in ex[:3]],
                    "_who": sorted(a["_accounts"])}
            out.append(rec)
        out.sort(key=lambda r: (-r["score"], r["key"]))
        return out


def fmt(v: float) -> str:
    if isinstance(v, float) and not v.is_integer():
        return f"{v:,.2f}"
    return f"{int(v):,}"


def lg(x: float) -> float:
    return math.log10(x) if x > 0 else 0.0


def sat_energy(position: float) -> int:
    lo, _ = SLOT_TMAX.get(int(position or 8), (30, 70))
    return max(1, math.floor((lo + 140) / 6))


def check_units(b: Board, ref: dict):
    for acc in b.judged:
        for kind, i, body in bodies(acc):
            for u, n in body.get("units", {}).items():
                u, n = int(u), float(n)
                r = ref.get(u)
                if not n or not r or r["n"] < MIN_REAL_SIDES or n <= r["max"] or u in (407, 408):
                    continue
                b.add(f"magnitude:{u}:{kind}", check=f"{NAMES.get(u, u)} per {kind} above every real OGMEM side",
                      kind="magnitude", obj=u, scope=kind, acc=acc, where=ident(acc, kind, i, body), value=n,
                      reference={"source": "OGMEM sides with the unit", **r}, excess=lg(n / r["p90"]),
                      why=f"real sides holding it: p50 {fmt(r['p50'])}, p90 {fmt(r['p90'])}, max {fmt(r['max'])} "
                          f"(n={r['n']} of {r['sides']})")


def check_tech(b: Board, tref: dict):
    for acc in b.judged:
        res = research_of(acc)
        for rid, r in tref.items():
            lv = res.get(rid, 0)
            if lv > r["max"]:
                b.add(f"magnitude:{rid}", check=f"{NAMES[rid]} above every real OGMEM fleet", kind="magnitude",
                      obj=rid, scope="account", acc=acc, where=ident(acc), value=lv,
                      reference={"source": "OGMEM fleet tech %", **r},
                      excess=(lv - r["max"]) * lg(COST_FACTOR[rid]),
                      why=f"real fleets: p50 {r['p50']}, p90 {r['p90']}, max {r['max']} (n={r['n']})")


def check_account_bands(b: Board, cell: str, band: dict):
    if not band:
        return
    for acc in b.judged:
        planets = len(acc["planets"])
        moons = sum(1 for p in acc["planets"] if p.get("moon"))
        for m, v in (("planets", planets), ("moons", moons)):
            lo, hi = band["p10"][m], band["p90"][m]
            if lo <= v <= hi:
                continue
            side = "below" if v < lo else "above"
            b.add(f"band:{m}:{side}", check=f"{m} per account {side} the real {cell} band", kind="magnitude",
                  obj=m, scope="account", acc=acc, where=ident(acc), value=v,
                  reference={"source": f"real-bands.json {cell}", "p10": lo, "p50": band["p50"][m], "p90": hi},
                  excess=abs(lg(max(v, 1) / (lo if v < lo else hi))), cue="too_little" if v < lo else "too_much",
                  why=f"real top10 accounts of that age: p10 {lo:g}, p50 {band['p50'][m]:g}, p90 {hi:g}")


def check_caps(b: Board):
    for acc in b.judged:
        res = research_of(acc)
        g = res.get(199, 0)
        if g > 1:
            ratio = (3 ** g - 1) / 2   # cumulative energy of levels 1..g over level 1 (x3 per level)
            b.add("cap:199", check="Graviton Technology above level 1", kind="cap", obj=199, scope="account", acc=acc,
                  where=ident(acc), value=g, reference={"cap": 1, "cost_factor": 3},
                  excess=lg(ratio), documented="WIK-175: not useful over level 1", pin="not useful over level 1",
                  why=f"cumulative Graviton energy x{fmt(round(ratio))} the one useful level")
        total_sats, hot = 0, None
        for kind, i, body in bodies(acc):
            s = float(body.get("units", {}).get(str(SATELLITE), 0) or 0)
            if kind == "planet":
                total_sats += s
                e = sat_energy(body.get("position"))
                hot = e if hot is None else max(hot, e)
            for dome in (407, 408):
                n = float(body.get("units", {}).get(str(dome), 0) or 0)
                if n > 1:
                    b.add(f"rule:{dome}", check=f"more than one {NAMES[dome]} on a {kind}", kind="rule", obj=dome,
                          scope=kind, acc=acc, where=ident(acc, kind, i, body), value=n, reference={"cap": 1},
                          excess=2 + lg(n), documented="game rule: one dome of each size per planet",
                          cues=["only one", "one small", "one large", "may be built", "can exist"],
                          why="the game allows one small and one large shield dome per planet: an impossible state")
            d = sum(float(n) for u, n in body.get("units", {}).items() if int(u) in DEFENCE)
            if d > WALL_CEILING:
                b.add(f"cap:defence:{kind}", check=f"defence units per {kind} above the 20,000 wall ceiling",
                      kind="cap", obj="defence", scope=kind, acc=acc, where=ident(acc, kind, i, body), value=d,
                      reference={"cap": WALL_CEILING}, excess=lg(d / WALL_CEILING),
                      documented="defence-doctrines.yaml standing_wall_ceiling 20,000", pin="ceiling on one planet's standing wall",
                      cues=["ceiling", "wall", "enough", "waste", "limit", "maximum", "stop", "too much", "fodder"],
                      why="no planet should hold more defence units than the documented standing-wall ceiling")
        if hot:
            need = math.ceil(GRAVITON_ENERGY / hot)
            if total_sats > need:
                b.add("cap:212", check="satellites per account beyond the one-off Graviton L1 need", kind="cap",
                      obj=SATELLITE, scope="account", acc=acc, where=ident(acc), value=total_sats,
                      reference={"need": need, "energy_per_sat_hottest_slot": hot}, excess=lg(total_sats / need),
                      documented="WIK-237 / WIK-139: sats are sized for Graviton L1, the colony is then abandoned",
                      context=["graviton"], pin="large amount of [[solar satellite",
                      why=f"{fmt(need)} satellites at the account's hottest slot ({hot} energy each) give the "
                          f"300,000 energy of Graviton L1; the account holds {fmt(total_sats)}")


def check_never(b: Board, ref: dict, judged_day: int):
    owned_units, levels = collections.Counter(), collections.Counter()
    events = collections.Counter()
    for acc in b.judged:
        have = set()
        for kind, i, body in bodies(acc):
            have |= {int(u) for u, n in body.get("units", {}).items() if n}
            for k, v in body.get("levels", {}).items():
                levels[int(k)] = max(levels[int(k)], v)
        for u in have:
            owned_units[u] += 1
        for e in EVENTS:
            events[e] += 1 if acc["_events"].get(e, 0) > 0 else 0
    for u, r in sorted(ref.items()):
        if r["share"] < NEVER_SHARE or owned_units[u]:
            continue
        for acc in b.judged:
            b.add(f"never:{u}", check=f"{NAMES.get(u, u)} never held by any judged account (day {judged_day})",
                  kind="never_used", obj=u, scope="account", acc=acc, where=ident(acc), value=0,
                  reference={"source": "OGMEM sides", **r}, excess=1 + 2 * r["share"], cue="never_used",
                  why=f"{r['share']:.0%} of real OGMEM sides hold it (median {fmt(r['p50'])})")
    for oid in sorted(BUILDINGS | RESEARCH):
        if levels[oid] == 0:
            for acc in b.judged:
                b.add(f"never:{oid}", check=f"{NAMES[oid]} never built/researched by any judged account",
                      kind="never_used", obj=oid, scope="account", acc=acc, where=ident(acc), value=0,
                      reference={}, excess=0.8, cue="never_used", why="catalogue object nobody reaches")
    if any(acc["_events"] for acc in b.judged):
        for e in EVENTS:
            if events[e] == 0:
                for acc in b.judged:
                    b.add(f"never:event:{e}", check=f"no judged account ever did '{e}' all season",
                          kind="never_used", obj=e, scope="account", acc=acc, where=ident(acc), value=0,
                          reference={}, excess=0.8, cue="never_used", why="season event total 0 on every account")


def check_principles(b: Board):
    for acc in b.judged:
        res = research_of(acc)
        banked = sum(sum(p.get("res", [0, 0, 0])) for p in acc["planets"])
        rate = sum(sum(p.get("rate", [0, 0, 0])) for p in acc["planets"])
        if rate > 0 and banked / rate > BANK_HOURS:
            h = banked / rate
            b.add("principle:banked", check="resources banked on planets (> 24 h of production)", kind="principle",
                  obj="resources", scope="account", acc=acc, where=ident(acc), value=round(h, 1),
                  reference={"hours": BANK_HOURS}, excess=lg(h / BANK_HOURS),
                  cues=["plunder", "plundered", "loot", "raid", "raided", "fleetsave", "stored", "spend", "safe"],
                  why=f"{fmt(banked)} resources sitting = {h:,.0f} h of the account's production (loot for raiders)")
        missiles = 0
        silo = 0
        for kind, i, body in bodies(acc):
            missiles += sum(float(body.get("units", {}).get(str(m), 0) or 0) for m in (502, 503))
            silo = max(silo, body.get("levels", {}).get("44", 0))
            depot = body.get("levels", {}).get("34", 0)
            if kind == "planet" and depot and not acc["_alliance"]:
                b.add("principle:depot", check="Alliance Depot built while the account has no alliance",
                      kind="principle", obj=34, scope="planet", acc=acc, where=ident(acc, kind, i, body),
                      value=depot, reference={}, excess=lg(2 ** depot / 2) / 4, cue="too_much",
                      cues=["alliance", "ACS", "hold", "defend", "fuel", "supply", "allied"],
                      why="the depot only supplies allied fleets holding at the planet")
            if kind == "planet" and body.get("moon"):
                fleet = sum(float(n) * UNIT_PRICE.get(int(u), 0) for u, n in body.get("units", {}).items()
                            if int(u) not in NON_FLEET)
                if fleet > PARKED_VALUE:
                    b.add("principle:parked", check="fleet >= 1e9 parked on a planet that has a moon",
                          kind="principle", obj="fleet", scope="planet", acc=acc, where=ident(acc, kind, i, body),
                          value=fleet, reference={"value": PARKED_VALUE}, excess=0.5 + lg(fleet / PARKED_VALUE),
                          cues=["moon", "phalanx", "fleetsave", "save", "crash", "safe"],
                          why="a parked fleet belongs on the moon (no phalanx) or in flight")
            if body.get("fields", 0) > body.get("cap", 10 ** 9):
                b.add(f"principle:fields:{kind}", check=f"{kind} fields over the cap", kind="consistency",
                      obj="fields", scope=kind, acc=acc, where=ident(acc, kind, i, body),
                      value=body["fields"], reference={}, excess=0.5, why=f"cap {body.get('cap')}")
            if kind == "moon":
                bad = {int(k): v for k, v in body.get("levels", {}).items() if int(k) in RESEARCH and v != res.get(int(k))}
                if bad:
                    b.add("consistency:moon-research", check="moon carries research levels unlike the account's",
                          kind="consistency", obj="research", scope="moon", acc=acc, where=ident(acc, kind, i, body),
                          value=len(bad), reference={}, excess=0.5,
                          cues=["all planets", "every planet", "all your planets", "all of your planets", "empire-wide"],
                          why="research is account-wide; a moon's levels map holds its own (lower) research ids, "
                              "e.g. " + ", ".join(f"{NAMES[k]} {v} vs {res.get(k)}" for k, v in sorted(bad.items())[:2]))
        if acc["_last"].get("alliance") and not acc.get("alliance"):
            b.add("consistency:alliance", check="levels.json says no alliance, the last day row says member",
                  kind="consistency", obj="alliance", scope="account", acc=acc, where=ident(acc), value=1,
                  reference={}, excess=0.3,
                  why="dump field mismatch (levels.json `alliance` is 0 for every account)")
        if silo and not missiles:
            b.add("principle:silo", check="Missile Silo built but no missile anywhere on the account",
                  kind="principle", obj=44, scope="account", acc=acc, where=ident(acc), value=silo,
                  reference={}, excess=lg(2 ** silo / 2) / 4, context=["missile", "IPM", "ABM"],
                  why="silo levels store missiles; none are held")


def check_battles(b: Board, paths: list[Path], judged: dict, real: dict):
    odds_p10 = real["odds"]["p10"]
    for path in paths:
        world = path.name[: -len(".jsonl.battles.jsonl")]
        for line in path.open():
            if not line.strip():
                continue
            f = json.loads(line)
            acc = judged.get((world, f.get("attacker")))
            if acc is None or f.get("mission") not in (None, "attack", "acs_attack"):
                continue
            m = war_cells.battle_measures(f)
            if not m:
                continue
            where = f"{world}#{f['attacker']} day {f.get('day')}"
            if m["odds"] < odds_p10:
                b.add("principle:odds", check=f"contested attack under the real p10 odds ({odds_p10:.2f})",
                      kind="principle", obj="attack odds", scope="fight", acc=acc, where=where,
                      value=round(m["odds"], 3), reference=real["odds"], excess=lg(odds_p10 / max(m["odds"], 1e-3)) + 0.5,
                      context=["odds", "ratio", "attack"], cue="too_little",
                      why="real attackers bring at least 1.6x the defender's value in 90% of fights")
            band = real.get(f"light_share|{m['tier']}")
            if band and m["light_share"] > band["p90"] + 1e-9 and m["tier"] != "<1e8":
                b.add("principle:light", check="light hulls above the real p90 share in a big attack fleet",
                      kind="principle", obj=204, scope="fight", acc=acc, where=where,
                      value=round(m["light_share"], 3), reference=band, excess=0.5 + m["light_share"] - band["p90"],
                      context=["fodder", "rapid fire"],
                      why=f"real {m['tier']} attack fleets: light share p90 {band['p90']:.2f}")


# ---------------------------------------------------------------- evidence

def names_of(obj) -> list[str]:
    if isinstance(obj, int):
        base = [NAMES.get(obj, str(obj))]
        return base + ALIASES.get(obj, [])
    special = {"defence": ["defence", "defense", "defences", "defenses"], "resources": ["resources"],
               "fleet": ["fleet"], "attack odds": ["odds", "ratio"], "research": ["research"],
               "planets": ["planet", "planets", "colony", "colonies"], "moons": ["moon", "moons"],
               "fields": ["fields"]}
    return special.get(obj, EVENT_NAMES.get(obj, [obj]))


def attach_evidence(index: dict, anomalies: list[dict]):
    for a in anomalies:
        if a["kind"] == "consistency":    # a dump/simulator state no player source speaks about
            a.pop("cues", None), a.pop("pin", None)
            a["evidence"], a["supported"] = [], False
            continue
        names = names_of(a["object_id"])
        cues = a.pop("cues", None) or CUES[a["cue"]]
        ev = corpus.search(index, names, cues, a.get("context", []), k=3, pin=a.pop("pin", ""))
        a["evidence"] = ev
        a["supported"] = bool(ev)


# ---------------------------------------------------------------- output

def write(run: Path, anomalies: list[dict], meta: dict, top_n: int):
    clean = [{k: v for k, v in a.items() if k != "_who"} for a in anomalies]
    (run / "anomalies.json").write_text(json.dumps({"meta": meta, "anomalies": clean}, indent=1, sort_keys=True))
    L = [f"# Anomalies: {run.name}", "",
         f"Judged: top {meta['top']} accounts by total in each of {meta['worlds']} worlds ({meta['judged']} accounts), "
         f"last day {meta['day']}. Real references: {meta['real']}.", "",
         "score = excess x (0.4 + 0.6 x share affected) + 0.5 for a documented cap; excess = log10 of the "
         "resource-cost ratio past the reference (see anomaly_report.py).", "",
         f"## Top {top_n}", "",
         "| # | score | kind | anomaly | affected | median / max | reference | evidence |",
         "|---|---|---|---|---|---|---|---|"]
    for i, a in enumerate(anomalies[:top_n], 1):
        ev = ", ".join(e["id"] for e in a["evidence"]) or "**unsupported - needs owner**"
        L.append(f"| {i} | {a['score']:.2f} | {a['kind']} | {a['check']} | {a['accounts_affected']}/{a['accounts_judged']}"
                 f" ({a['instances']}) | {fmt(a['value_median'])} / {fmt(a['value_max'])} | {a['why']} | {ev} |")
    L += ["", "## Details", ""]
    for i, a in enumerate(anomalies, 1):
        L.append(f"### {i}. {a['check']}  (score {a['score']:.2f}, {a['kind']})")
        L.append(f"- affected: {a['accounts_affected']}/{a['accounts_judged']} accounts, {a['instances']} instances; "
                 f"median {fmt(a['value_median'])}, max {fmt(a['value_max'])}")
        L.append(f"- why: {a['why']}" + (f"; documented: {a['documented']}" if a["documented"] else ""))
        L.append(f"- worst: {'; '.join(a['examples'])}")
        if a["evidence"]:
            for e in a["evidence"]:
                L.append(f"- evidence `{e['id']}` ({e['path']}): \"{e['snippet']}\"")
        else:
            L.append("- evidence: **unsupported - needs owner** ("
                     + ("simulator/dump consistency: no player source covers it)" if a["kind"] == "consistency"
                        else "no corpus passage names it with a matching cue)"))
        L.append("")
    L += ["## Per account (top 5 each)", ""]
    per = collections.defaultdict(list)
    for i, a in enumerate(anomalies, 1):
        for w in a["_who"]:
            per[w].append(f"#{i} {a['check']}")
    for w in sorted(per):
        L.append(f"- {w[0]}#{w[1]}: " + "; ".join(per[w][:5]))
    L += ["", "## Coverage", "", *[f"- {c}" for c in meta["coverage"]], ""]
    (run / "anomalies.md").write_text("\n".join(L))


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    ap.add_argument("run", type=Path)
    ap.add_argument("--top", type=int, default=10, help="accounts judged per world (by total)")
    ap.add_argument("--show", type=int, default=15, help="rows in the summary table")
    ap.add_argument("--pattern", default="*", help="world name glob, e.g. 'model-*'")
    ap.add_argument("--rebuild-index", action="store_true")
    args = ap.parse_args()
    t0 = time.time()
    index = corpus.build(force=args.rebuild_index)
    files = sorted(args.run.glob(f"{args.pattern}.jsonl.levels.json"))
    if not files:
        print(f"no {args.pattern}.jsonl.levels.json under {args.run}", file=sys.stderr)
        return 1
    judged = []
    for f in files:
        _, accs, _ = load_world(f, args.top)
        judged += accs
    day = max(a["day"] for a in judged)
    b = Board(judged)
    ref, tref = unit_reference(index), tech_reference(index)
    cell, band = account_bands(day)
    check_units(b, ref)
    check_tech(b, tref)
    check_account_bands(b, cell, band)
    check_caps(b)
    check_never(b, ref, day)
    check_principles(b)
    fights = sorted(p for p in args.run.glob(f"{args.pattern}.jsonl.battles.jsonl"))
    if fights:
        real_war = json.loads(war_cells.DEFAULT_REAL.read_text())["war_bands"]
        check_battles(b, fights, {(a["_world"], a["account"]): a for a in judged}, real_war)
    anomalies = b.finish()
    attach_evidence(index, anomalies)
    no_ref = sorted({NAMES[int(k)] for a in judged for p in a["planets"] for k in p["levels"]
                     if int(k) not in tref and int(k) != 199})
    coverage = [
        f"unit counts: OGMEM {len(index['real']['stocks'])} report sides (flag = above the real max of sides holding "
        f"the unit; units with < {MIN_REAL_SIDES} real sides are not judged: "
        + (", ".join(NAMES.get(u, str(u)) for u, r in sorted(ref.items()) if r["n"] < MIN_REAL_SIDES) or "none")
        + "; missiles 502/503 have no real stock)",
        f"combat research: {len(index['real']['tech'])} OGMEM fleets' Weapons/Shields/Armour %",
        f"account counts: real-bands.json {cell}",
        "no real distribution for these levels (only documented caps apply): " + ", ".join(no_ref),
        f"battles.jsonl: {'%d file(s)' % len(fights) if fights else 'none (fight principles not judged)'}",
        f"season event totals: {'yes' if any(a['_events'] for a in judged) else 'no day rows found'}",
        f"corpus index: {json.dumps(index['counts'], sort_keys=True)} passages ({corpus.INDEX.relative_to(ROOT)})",
    ]
    meta = {"run": str(args.run), "worlds": len(files), "top": args.top, "judged": len(judged), "day": day,
            "real": "OGMEM sides + fleet tech, real-bands.json, documented caps", "coverage": coverage}
    write(args.run, anomalies, meta, args.show)
    print(f"{len(anomalies)} anomalies over {len(judged)} accounts -> {args.run / 'anomalies.md'} "
          f"({time.time() - t0:.1f}s)")
    for i, a in enumerate(anomalies[: args.show], 1):
        ev = ",".join(e["id"] for e in a["evidence"]) or "UNSUPPORTED - needs owner"
        print(f"{i:2d}. {a['score']:5.2f} {a['check']}  [{a['accounts_affected']}/{a['accounts_judged']}, "
              f"med {fmt(a['value_median'])}, max {fmt(a['value_max'])}]  {ev}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
