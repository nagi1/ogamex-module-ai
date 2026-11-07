#!/usr/bin/env python3
"""Record the live cognition engines' answers over a small grid of social situations (SOCIAL-WORLD-DRAFT 16.2.5, phase B).

The simulator needs millions of social reads and an engine call costs ~30-100 ms, so it reads a table of the real
engine's answers instead. Nothing here reimplements an engine: every cell is an HTTP answer from the live sidecar,
sent the same endpoints and payloads the PHP module sends (FatimaClient/FatimaAffectEngine, PsychSimClient).

  python3 engine_tables.py             record every table into resources/behavior/engine-tables/
  python3 engine_tables.py --validate  re-ask a random 200-key sample live and print the match rate per engine

Tables (each carries engine, endpoint, health/version, a hash of the authored inputs and the grid):
- fatima-emotions.json: "Character|Action|level" -> the OCC emotions FAtiMA appraises (dominant first, as the module
  reads it). Inputs are visible only: a hit taken (Harm), a menace seen (Threaten), help received (Aid), with their size.
- psychsim-stance.json: "temptation" -> cooperate/defect, the depth-one theory-of-mind stance the served endpoint gives.
CBRKit is not recorded: its served retriever scores building-experience cases the module sends per request, and no
social case base exists to send.
"""

import argparse
import hashlib
import json
import random
import subprocess
import sys
import time
import urllib.request
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path

AI = Path(__file__).resolve().parents[2]
OUT = AI / "resources/behavior/engine-tables"
SCENARIOS = AI / "docker/cognition/fatima/scenarios"

FATIMA_URLS = ["http://localhost:8092", "http://localhost:8095"]
FATIMA_SCENARIO = "OgameCognition"
FATIMA_INSTANCE = 1
FATIMA_COUNTERPARTY = "Other"
PSYCHSIM_URL = "http://localhost:8094"

# The grid. Levels are the size of the stimulus on the module's 0..1 scale; temptation is the sidecar's own scale.
ACTIONS = ["Harm", "Threaten", "Aid"]
LEVELS = [round(i * 0.05, 2) for i in range(21)]
TEMPTATIONS = [round(i * 0.05, 2) for i in range(81)]  # 0..4: the module sends 2 x threat (0..2); above 2 shows the tail.
INTENSITY_TOLERANCE = 1e-3


def http(method, url, body=None, timeout=10):
    data = None if body is None else json.dumps(body).encode()
    req = urllib.request.Request(url, method=method, data=data,
                                 headers={"Content-Type": "application/json", "Accept": "application/json"})
    raw = urllib.request.urlopen(req, timeout=timeout).read()
    try:
        return json.loads(raw or b"null")
    except json.JSONDecodeError:
        return raw.decode(errors="replace")


def sha256(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def image_id(service):
    try:
        out = subprocess.run(["docker", "inspect", "--format", "{{.Image}} {{.Config.Image}}", f"ogamex-ai-cognition-{service}-1"],
                             capture_output=True, text=True, timeout=10).stdout.strip()
        return out or None
    except (OSError, subprocess.SubprocessError):
        return None


# ---------------------------------------------------------------- FAtiMA

def fatima_characters():
    """The authored characters and the one thing that tells them apart: how much they value Safe(SELF)."""
    root = json.loads((SCENARIOS / "ogame-cognition.json").read_text())["root"]
    return {c["KnowledgeBase"]["Perspective"]: next(g["Significance"] for g in c["Goals"] if g["Name"] == "Safe(SELF)")
            for c in root["Characters"]}


def fatima_key(character, action, level):
    return f"{character}|{action}|{level:.2f}"


def fatima_ask(base, character, action, level):
    """FatimaCognitionSession::appraise + FatimaAffectEngine: reload, two beliefs, one perception, read emotions."""
    scenario = (SCENARIOS / "ogame-cognition.json").read_text()
    assets = (SCENARIOS / "ogame-cognition-assets.json").read_text()
    http("POST", base + "/scenarios", {"scenario": scenario, "assets": assets})
    path = f"{base}/scenarios/{FATIMA_SCENARIO}/instances/{FATIMA_INSTANCE}/characters/{character}"
    # FatimaAffectEngine::branch signs the values the OCC way: aid desirable, harm undesirable, threat lowers Safe.
    desirability = level if action == "Aid" else (-level if action == "Harm" else 0.0)
    threat = -level if action == "Threaten" else 0.0
    for name, value in (("StimulusDesirability", desirability), ("StimulusThreat", threat)):
        http("POST", path + "/beliefs", {"name": f"{name}(SELF, {FATIMA_COUNTERPARTY})", "value": fmt(value)})
    event = f"Event(Action-End, {FATIMA_COUNTERPARTY}, {action}, {character})"
    http("POST", path + "/perceptions", [event])
    state = http("GET", path + "/emotions")
    if not isinstance(state, dict) or not isinstance(state.get("Emotions"), list):
        raise RuntimeError(f"FAtiMA answered off contract: {state!r}")
    emotions = [{"type": e["Type"], "intensity": round(float(e["Intensity"]), 6)}
                for e in state["Emotions"] if e.get("CauseEventName") == event]
    return {"dominant": emotions[0]["type"] if emotions else None,
            "intensity": emotions[0]["intensity"] if emotions else 0.0,
            "mood": round(float(state.get("Mood", 0.0)), 6),
            "emotions": emotions}


def fmt(value):
    # FatimaAffectEngine::format: four decimals, trailing zeros dropped.
    text = f"{value:.4f}".rstrip("0").rstrip(".")
    return "0" if text in ("", "-0") else text


def fatima_cells(keys):
    """Each sidecar answers one request at a time: one worker per server, keys spread across them."""
    shards = [keys[i::len(FATIMA_URLS)] for i in range(len(FATIMA_URLS))]

    def run(i):
        return {fatima_key(*k): fatima_ask(FATIMA_URLS[i], *k) for k in shards[i]}

    with ThreadPoolExecutor(len(FATIMA_URLS)) as pool:
        out = {}
        for part in pool.map(run, range(len(FATIMA_URLS))):
            out.update(part)
    return out


def fatima_grid():
    return [(c, a, l) for c in fatima_characters() for a in ACTIONS for l in LEVELS]


def fatima_table():
    characters = fatima_characters()
    return {
        "engine": "FAtiMA-Toolkit (OCC appraisal)",
        "endpoint": {"base_urls": FATIMA_URLS, "sequence": [
            "POST /scenarios {scenario, assets}",
            f"POST /scenarios/{FATIMA_SCENARIO}/instances/{FATIMA_INSTANCE}/characters/<Character>/beliefs "
            "StimulusDesirability(SELF, Other), StimulusThreat(SELF, Other)",
            "POST .../perceptions [Event(Action-End, Other, <Action>, <Character>)]",
            "GET .../emotions (only emotions caused by that event, dominant = first, as FatimaCognitionSession)"]},
        "health": {u: http("GET", u + "/scenarios") for u in FATIMA_URLS},
        "image": image_id("fatima"),
        "inputs_sha256": {f.name: sha256(f) for f in sorted(SCENARIOS.glob("*.json"))},
        "recorded_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
        "grid": {"key": "Character|Action|level", "characters": characters, "actions": ACTIONS, "levels": LEVELS,
                 "size": len(characters) * len(ACTIONS) * len(LEVELS)},
        "reading": {
            "character": "the authored characters differ only in Safe(SELF) significance (how much a threat frightens); "
                         "pick the one whose significance is nearest 1 - courage",
            "Harm": "a hit taken / grievance / betrayal by a partner; level = the grievance toward that club, capped at 1 "
                    "(a betrayal of an open pact = 1.0)",
            "Threaten": "a visible menace (stronger club at war or feared); level = the threat held toward it, capped at 1",
            "Aid": "help received (pact offered while at war, support sent); level = its size on 0..1",
            "snap": "round the level to the nearest grid step (0.05)",
        },
        "cells": fatima_cells(fatima_grid()),
    }


# ---------------------------------------------------------------- PsychSim

def psychsim_key(t):
    return f"{t:.2f}"


def psychsim_ask(temptation):
    """PsychSimClient::ask: POST /evaluate {temptation} -> {decision}."""
    answer = http("POST", PSYCHSIM_URL + "/evaluate", {"temptation": temptation})
    decision = answer.get("decision") if isinstance(answer, dict) else None
    if decision not in ("cooperate", "defect"):
        raise RuntimeError(f"PsychSim answered off contract: {answer!r}")
    return {"decision": decision}


def psychsim_table():
    cells = {psychsim_key(t): psychsim_ask(t) for t in TEMPTATIONS}
    defects = [float(k) for k, v in cells.items() if v["decision"] == "defect"]
    return {
        "engine": "PsychSim (depth-one theory of mind, sequential cooperation dilemma)",
        "endpoint": {"url": PSYCHSIM_URL + "/evaluate", "request": {"temptation": "float"},
                     "response": {"decision": "cooperate|defect"}},
        "health": http("GET", PSYCHSIM_URL + "/health"),
        "image": image_id("psychsim"),
        "inputs_sha256": {"server.py": sha256(AI / "docker/cognition/psychsim/server.py")},
        "recorded_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
        "grid": {"key": "temptation (2 decimals)", "temptations": TEMPTATIONS, "size": len(TEMPTATIONS)},
        "observed_first_defect_at": min(defects) if defects else None,
        "reading": {
            "mapping": "the module's own (PsychSimTheoryOfMind): temptation = 2 x threat the account holds toward the "
                       "counterparty (0..1); snap to the grid step",
            "decision": "defect = the account models the counterparty as exploiting its cooperation: read it as "
                        "'not loyal', refuse its pact; cooperate = read as loyal enough to accept",
            "limitation": "the served endpoint takes one scalar and returns one stance: no belief probability, no "
                          "reputation (pacts broken/kept) and no points ratio as inputs. Mapping those into temptation "
                          "would be the simulator's invention, not the engine's answer, so it is not recorded here",
        },
        "cells": cells,
    }


# ---------------------------------------------------------------- record / validate

def write(name, table):
    OUT.mkdir(parents=True, exist_ok=True)
    (OUT / name).write_text(json.dumps(table, indent=1, sort_keys=False) + "\n")
    print(f"wrote {OUT / name}: {len(table['cells'])} cells")


def same(a, b):
    if "decision" in a:
        return a["decision"] == b["decision"]
    return a["dominant"] == b["dominant"] and abs(a["intensity"] - b["intensity"]) <= INTENSITY_TOLERANCE


def validate(sample, seed):
    fatima = json.loads((OUT / "fatima-emotions.json").read_text())
    psychsim = json.loads((OUT / "psychsim-stance.json").read_text())
    keys = [("fatima", k) for k in fatima["cells"]] + [("psychsim", k) for k in psychsim["cells"]]
    picked = random.Random(seed).sample(keys, min(sample, len(keys)))
    fk = [k for e, k in picked if e == "fatima"]
    pk = [k for e, k in picked if e == "psychsim"]
    split = [tuple(k.split("|")) for k in fk]
    live_f = fatima_cells([(c, a, float(l)) for c, a, l in split])
    live_p = {k: psychsim_ask(float(k)) for k in pk}
    ok = True
    for engine, keys, table, live in (("fatima", fk, fatima, live_f), ("psychsim", pk, psychsim, live_p)):
        if not keys:
            continue
        hits = sum(same(table["cells"][k], live[k]) for k in keys)
        rate = hits / len(keys)
        ok &= rate >= 0.95
        print(f"{engine}: {hits}/{len(keys)} match ({rate:.1%})")
        for k in [k for k in keys if not same(table["cells"][k], live[k])][:5]:
            print(f"  miss {k}: table={table['cells'][k]} live={live[k]}")
    print("PASS" if ok else "FAIL (<95%)")
    return ok


def main():
    p = argparse.ArgumentParser()
    p.add_argument("--validate", action="store_true")
    p.add_argument("--sample", type=int, default=200)
    p.add_argument("--seed", type=int, default=int(time.time()))
    args = p.parse_args()
    if args.validate:
        print(f"seed {args.seed}")
        sys.exit(0 if validate(args.sample, args.seed) else 1)
    write("fatima-emotions.json", fatima_table())
    write("psychsim-stance.json", psychsim_table())


if __name__ == "__main__":
    main()
