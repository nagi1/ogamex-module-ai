#!/usr/bin/env python3
"""Phase C imitation: a decision tree that copies the teacher's partner choice (keep, help, betray).

    social_imitation.py [--runs DIR] [--dirs imit2 lgp-teacher sc2 teach2] [--hold lgp-teacher:1-10] [--depth 8]
                        [--export tree.json]

Rows are the `kind: partner` lines of ogsim's `*.decisions.jsonl` (arm `minds`), what the leader saw at decision time.
Every feature is named by the same grammar ogsim's arm `bc=` reads off the row it logs (`diplomacy::bc_feature`), so
the tree input and the logged row cannot drift:
  NAME           a top-level number or bool (bool -> 0/1)
  A-B            top-level A minus top-level B (the remembered gratitude over grievance the leader weighs)
  persona.T      the leader's trait T (row.persona.traits.T)
  emotions.E     the leader's strongest FAtiMA emotion E toward the partner's leader (absent -> 0)
  relation.K     the leader's FAtiMA relation K toward the partner's leader (absent or null -> 0)
  psychsim=V     1 when PsychSim's act_now is V (the engines' answer, a live feature, not a copied algorithm)
  fatima=V       1 when FAtiMA's chosen act is V
Anything missing or not a number reads 0.

Held out from training: `--hold DIR:A-B` (default lgp-teacher seeds 1-10, the seeds the sim arm is then evaluated on).
`--export` writes the fitted tree (sklearn `tree_` arrays, leaf class = argmax of value) with the ordered feature list.
"""
from __future__ import annotations

import argparse
import glob
import json
import os
import re

import numpy as np
from sklearn.tree import DecisionTreeClassifier

SCALARS = ["points_ratio", "at_war", "partner_at_war", "grievance", "gratitude", "gratitude-grievance", "p_loyal", "mood",
           "their_broken"]
# ogsim persona::TRAITS (social-persona.yaml).
TRAITS = ["ambition", "authority", "courage", "cruelty", "cunning", "dominance", "forgiveness", "freedom", "generosity",
          "honesty", "loyalty", "vindictiveness"]
EMOTIONS = ["Anger", "Gratitude", "Hate", "Love"]
RELATION = ["debt", "fear", "liking", "respect", "trust"]
PSYCHSIM = ["betray", "help", "keep"]
FATIMA = ["Betray", "DefendAlly", "Gift", "Ignore"]
CLASSES = ["betray", "help", "keep"]


def names(engines: bool) -> list[str]:
    out = SCALARS + [f"persona.{t}" for t in TRAITS] + [f"emotions.{e}" for e in EMOTIONS] + [f"relation.{k}" for k in RELATION]
    if engines:
        out += [f"psychsim={v}" for v in PSYCHSIM] + [f"fatima={v}" for v in FATIMA]
    return out


def num(v) -> float:
    if isinstance(v, bool):
        return 1.0 if v else 0.0
    return float(v) if isinstance(v, (int, float)) else 0.0


def feature(row: dict, name: str) -> float:
    """The value of one named feature on a decision row; the Rust twin is diplomacy::bc_feature."""
    if "=" in name:
        k, v = name.split("=", 1)
        return 1.0 if row.get(k) == v else 0.0
    if "." in name:
        k, sub = name.split(".", 1)
        obj = row.get(k)
        if k == "persona":
            obj = obj.get("traits") if isinstance(obj, dict) else None
        return num(obj.get(sub)) if isinstance(obj, dict) else 0.0
    if "-" in name:
        a, b = name.split("-", 1)
        return num(row.get(a)) - num(row.get(b))
    return num(row.get(name))


def load(runs: str, dirs: list[str]):
    rows = []
    for d in dirs:
        for f in sorted(glob.glob(os.path.join(runs, d, "*.decisions.jsonl"))):
            seed = int(re.search(r"ready-(\d+)", os.path.basename(f)).group(1))
            for line in open(f):
                x = json.loads(line)
                if x.get("kind") == "partner":
                    x["_run"] = (d, seed)
                    rows.append(x)
    return rows


def report(tag: str, pred, y) -> None:
    per = {c: (round(float((pred[y == c] == c).mean()), 3) if (y == c).any() else None, int((y == c).sum())) for c in CLASSES}
    print(f"  {tag}: acc {float((pred == y).mean()):.3f} n={len(y)} recall {per}")


def export(model: DecisionTreeClassifier, features: list[str], path: str) -> None:
    t = model.tree_
    classes = [str(c) for c in model.classes_]
    leaf = [classes[int(np.argmax(v[0]))] for v in t.value]
    json.dump({"features": features, "classes": classes, "feature": t.feature.tolist(), "threshold": t.threshold.tolist(),
               "children_left": t.children_left.tolist(), "children_right": t.children_right.tolist(), "class": leaf,
               "depth": int(model.get_depth()), "leaves": int(model.get_n_leaves())}, open(path, "w"))
    print(f"exported {path}: {t.node_count} nodes, depth {model.get_depth()}")


def main() -> None:
    ap = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    ap.add_argument("--runs", default="/home/nagi/code/ogamex-next/storage/rl/ogsim/runs/social")
    ap.add_argument("--dirs", nargs="+", default=["imit2", "lgp-teacher", "sc2", "teach2"])
    ap.add_argument("--hold", default="lgp-teacher:1-10", help="DIR:A-B held out of training")
    ap.add_argument("--depth", type=int, default=8)
    ap.add_argument("--export", help="write the engines tree (trained without the held-out runs) here")
    a = ap.parse_args()
    rows = load(a.runs, a.dirs)
    y = np.array([x["choice"] for x in rows])
    hd, rng = a.hold.split(":")
    lo, hi = (int(s) for s in rng.split("-"))
    held = np.array([x["_run"][0] == hd and lo <= x["_run"][1] <= hi for x in rows])
    mod4 = np.array([x["_run"][1] % 4 == 0 for x in rows])
    print(f"rows {len(rows)} betray {(y == 'betray').sum()} help {(y == 'help').sum()} held({a.hold}) {held.sum()} "
          f"psychsim present {sum(1 for x in rows if x.get('psychsim'))}")
    for engines in (False, True):
        feats = names(engines)
        X = np.array([[feature(x, n) for n in feats] for x in rows])
        print("engines" if engines else "visible", f"({len(feats)} features)")
        for tag, test in (("seeds = 0 mod 4", mod4), (f"held {a.hold}", held)):
            m = DecisionTreeClassifier(max_depth=a.depth, class_weight="balanced", random_state=0).fit(X[~test], y[~test])
            report(tag, m.predict(X[test]), y[test])
            if engines and test is held and a.export:
                export(m, feats, a.export)


if __name__ == "__main__":
    main()
