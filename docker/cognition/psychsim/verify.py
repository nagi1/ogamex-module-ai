#!/usr/bin/env python3
"""Self-check for the PsychSim sidecar. Run inside the image (or pass a base URL):
    python verify.py [http://127.0.0.1:8080]

- ``/evaluate`` answers the cooperation stance (cooperate below the switching temptation, defect above it).
- ``/social/partner`` answers a batch, and its partner model behaves: p_loyal falls with broken pacts and rises with
  kept ones, a belief carried forward from a previous call moves with the new history, and betray_now flips with
  points_ratio and with the horizon.
"""

import json
import sys
import urllib.request

BASE = sys.argv[1] if len(sys.argv) > 1 else "http://127.0.0.1:8080"
GREEDY = {"ambition": 0.8, "cunning": 0.8, "honesty": 0.3, "loyalty": 0.3}


def post(path, body):
    req = urllib.request.Request(BASE + path, data=json.dumps(body).encode(),
                                 headers={"Content-Type": "application/json"})
    with urllib.request.urlopen(req, timeout=600) as response:
        return json.loads(response.read())


def main():
    assert post("/evaluate", {"temptation": 1.0}) == {"decision": "cooperate"}
    assert post("/evaluate", {"temptation": 3.0}) == {"decision": "defect"}

    base = {"points_ratio": 1.0, "threat": 0.5, "horizon": 2}
    counts = [(0, 0), (1, 0), (3, 0), (0, 1), (0, 3)]
    p = [r["p_loyal"] for r in post("/social/partner", {"items": [
        dict(base, broken=b, kept=k) for b, k in counts]})["results"]]
    assert p[0] > p[1] > p[2] and p[0] < p[3] < p[4], p

    first = post("/social/partner", {"items": [dict(base, history=[{"t": 1, "action": "kept"}])]})["results"][0]
    carried = post("/social/partner", {"items": [
        dict(base, belief=first["belief"], bond=first["bond"], history=[{"t": 2, "action": h}])
        for h in ("kept", "attack")]})["results"]
    assert carried[0]["p_loyal"] > first["p_loyal"] > carried[1]["p_loyal"], (first, carried)

    flips = post("/social/partner", {"items": [
        {"points_ratio": ratio, "threat": 0.5, "my_traits": GREEDY, "horizon": horizon}
        for ratio, horizon in ((1.0, 1), (0.5, 1), (0.5, 2))]})["results"]
    assert [r["betray_now"] for r in flips] == [False, True, False], flips
    print("psychsim sidecar OK: p_loyal", p, "carried", first["p_loyal"], [r["p_loyal"] for r in carried],
          "betray_now", [r["betray_now"] for r in flips])


if __name__ == "__main__":
    main()
