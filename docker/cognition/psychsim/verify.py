#!/usr/bin/env python3
"""Self-check for the PsychSim sidecar: build one two-agent world and assert the
step returns a decision for each agent. Run inside the image:
    python verify.py
"""

import json
import urllib.request


def main():
    request = {"steps": 1, "depth": 1}
    req = urllib.request.Request(
        "http://127.0.0.1:8080/evaluate",
        data=json.dumps(request).encode(),
        headers={"Content-Type": "application/json"},
    )
    with urllib.request.urlopen(req, timeout=5) as response:
        body = json.loads(response.read())

    decisions = {d["agent"] for d in body["decisions"]}
    assert decisions == {"self", "other"}, decisions
    print("psychsim sidecar OK:", body)


if __name__ == "__main__":
    main()
