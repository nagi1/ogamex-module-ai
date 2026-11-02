"""The coverage gate (WORLD-SIM-PLAN, late game): which host objects and missions a season's accounts never reach.

    coverage.py CATALOGUE UNITS SEASON.jsonl[.xz]...

Reads each account's last line. An object is reached when some account holds a level or a unit of it; a mission when
some account flew it. Prints the share of accounts reaching each, then what nobody reached; exits 1 when anything is
missing. A moon's buildings count from `moon_built`.
"""
import json, lzma, sys
from collections import Counter

def lines(path):
    with (lzma.open if path.endswith(".xz") else open)(path, "rt") as f:
        for line in f:
            yield json.loads(line)


def main(catalogue, units, *seasons):
    cat, uni = json.load(open(catalogue)), json.load(open(units))
    names = {o["id"]: o["machine_name"] for o in cat["objects"] + cat["research"]}
    names |= {u["id"]: u["machine_name"] for u in uni["units"]}
    last, flown = {}, Counter()
    for path in seasons:
        for r in lines(path):
            last[(path, r["account"])] = r
            for k, v in r["events_today"].items():
                if isinstance(v, (int, float)) and v > 0: flown[k] += 1
    reached = Counter()
    for r in last.values():
        s = r["stall"]
        held = {i for i, lv in s.get("built", []) + s.get("moon_built", []) if max(lv, default=0) > 0} | {i for i, lv in s.get("tech", []) if lv > 0}
        held |= {i for i, n in s.get("units", []) if n > 0}
        reached.update(held)
    n = len(last)
    for i, name in sorted(names.items()):
        print(f"{i:>4} {name:32} {reached[i] / n:6.1%}")
    missing = [names[i] for i in sorted(names) if not reached[i]]
    missions = ("raid_sent", "spy_sent", "recycle", "colonise", "expedition", "save_ok", "moon_made", "missile", "moon_shot", "phalanx", "jump")
    missing += [m for m in missions if not flown[m]]
    print("accounts", n, "never reached:", ", ".join(missing) or "nothing")
    return 1 if missing else 0


if __name__ == "__main__":
    sys.exit(main(*sys.argv[1:]))
