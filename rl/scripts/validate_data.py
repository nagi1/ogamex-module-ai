#!/usr/bin/env python3
"""Validate recorded choice data before any training. Exit 1 when a blocking check fails.

    python3 Modules/AI/rl/scripts/validate_data.py 'storage/rl/bc/choices-*.jsonl' [--epsilon 0.1] [--out storage/rl/validation.json]

Blocking checks protect the labels (schema, finite numbers, teacher legal, no duplicates, epsilon share, sim logs clean);
warnings describe balance and learnability and are shown on /ai-harness/rl but do not stop a run.
Standard library only; a 300k-row set takes a couple of minutes on one core.
"""
from __future__ import annotations

import argparse
import glob
import json
import math
import re
import sys
from collections import Counter, defaultdict
from pathlib import Path

WINDOW_DAYS = 31
START = 1791158400  # 2026-10-05T00:00:00Z, the run's start instant


def finite(values) -> bool:
    return all(isinstance(v, (int, float)) and math.isfinite(v) for v in values)


def validate(pattern: str, epsilon: float) -> dict:
    files = sorted(glob.glob(pattern), key=lambda p: int(re.sub(r"\D", "", Path(p).stem) or 0))
    blocking: list[dict] = []
    warnings: list[dict] = []
    schemas = set()
    seen = set()
    last_t: dict = {}
    per_file = {}
    per_arch = Counter()
    teacher_pos = Counter()
    legal_counts = Counter()
    kinds = Counter()
    sums: dict[int, float] = defaultdict(float)
    sqs: dict[int, float] = defaultdict(float)
    state_dim = None
    total = multi = off_teacher = first_legal_hit = wait_teacher = multi3 = first3 = 0
    problems = Counter()
    growth: dict = defaultdict(list)
    cheap = pure = 0
    pure_by = Counter()
    cand_ix: dict = {}
    catalogue = {}
    objects_file = Path(pattern).parent.parent / 'objects.json'
    if objects_file.exists():
        catalogue = {int(k): v for k, v in json.loads(objects_file.read_text()).items()}

    for path in files:
        n = re.sub(r"\D", "", Path(path).stem)
        schema_file = Path(path + ".schema.json")
        schemas.add(schema_file.read_text() if schema_file.exists() else "missing")
        version = json.loads(schema_file.read_text()).get("version") if schema_file.exists() else None
        if not cand_ix and schema_file.exists():
            cand_ix = {n: i for i, n in enumerate(json.loads(schema_file.read_text()).get('candidate', []))}
        rows = ok = 0
        for line in open(path):
            try:
                d = json.loads(line)
            except ValueError:
                problems["unparseable line (NaN/Infinity or truncation)"] += 1
                continue
            rows += 1
            cands, legal, state = d["cands"], d["legal"], d["state"]
            width = {len(c) for c in cands}
            if state_dim is None:
                state_dim = len(state)
            if len(state) != state_dim or len(width) != 1 or not (len(cands) == len(legal) == len(d["objects"])):
                problems["row shape differs from the schema"] += 1
                continue
            if d.get("v") != version:
                problems["feature version differs"] += 1
            if not finite(state) or not all(finite(c) for c in cands):
                problems["non-finite feature"] += 1
                continue
            if max(abs(v) for v in state) > 1e7 or max(abs(v) for c in cands for v in c) > 1e7:
                problems["feature magnitude above 1e7"] += 1
            teacher, chosen = d["teacher"], d["chosen"]
            if not (0 <= teacher < len(legal)) or not legal[teacher]:
                problems["teacher row is not legal"] += 1
                continue
            if not (0 <= chosen < len(legal)) or not legal[chosen]:
                problems["chosen row is not legal"] += 1
                continue
            key = (d["seed"], d["player"], d["planet"], d["t"], d["kind"])
            if key in seen:
                problems["duplicate choice point"] += 1
            seen.add(key)
            who = (d["seed"], d["player"], d["kind"], d["planet"])
            if d["t"] < last_t.get(who, 0):
                problems["time goes backwards for one account"] += 1
            last_t[who] = d["t"]
            if not START <= d["t"] <= START + WINDOW_DAYS * 86400:
                problems["timestamp outside the simulated window"] += 1
            ok += 1
            growth[(d['seed'], d['player'], d.get('archetype'))].append(((d['t'] - START) / 86400, d['value']))
            legal_n = sum(1 for x in legal if x)
            legal_counts[min(legal_n, 6)] += 1
            if legal_n < 2:
                continue
            multi += 1
            if d['kind'] == 'building' and cand_ix:
                unx = lambda v: math.copysign(math.expm1(abs(v)), v)
                if any(i and unx(cands[i][cand_ix['payback_hours']]) < 2 and unx(cands[i][cand_ix['eta_hours']]) < 0.05 and cands[i][cand_ix['teacher_ok']] > 0.5 for i, x in enumerate(legal) if x):
                    cheap += 1
                    pk = cands[teacher]
                    if teacher and unx(pk[cand_ix['payback_hours']]) >= 9999 and unx(pk[cand_ix['energy_delta']]) <= 0 and pk[cand_ix['storage_gain']] <= 0 and pk[cand_ix['unlocks']] <= 0:
                        pure += 1
                        pure_by[catalogue.get(d['objects'][teacher], d['objects'][teacher])] += 1
            per_arch[d.get("archetype")] += 1
            kinds[d["kind"]] += 1
            teacher_pos["wait" if teacher == 0 else "act"] += 1
            wait_teacher += teacher == 0
            off_teacher += chosen != teacher
            first = next(i for i, x in enumerate(legal) if x and i != 0) if legal_n > 1 else 0
            first_legal_hit += first == teacher
            if legal_n >= 3:
                multi3 += 1
                first3 += first == teacher
            for i, v in enumerate(state):
                sums[i] += v
                sqs[i] += v * v
        per_file[n] = {"rows": rows, "ok": ok}
        total += ok

    for what, count in problems.items():
        blocking.append({"check": what, "count": count})
    if len(schemas) > 1:
        blocking.append({"check": "schema files differ between universes", "count": len(schemas)})
    if "missing" in schemas:
        blocking.append({"check": "schema file missing", "count": 1})

    unfinished = []
    logs = [log for d in {Path(f).parent for f in files} if not (d / ".partial").exists() for log in d.glob("sim-*.log")]
    for log in logs:
        text = Path(log).read_text(errors="replace")
        played = re.search(r"^SIM: .*? (\d+) error\(s\)", text, re.M)
        if not played:
            unfinished.append(Path(log).stem)
        elif int(played.group(1)) > 0:
            blocking.append({"check": f"sim errors in {Path(log).stem}", "count": int(played.group(1))})
    if unfinished:
        warnings.append({"check": "universes still running (partial data)", "count": len(unfinished)})

    if multi:
        off = off_teacher / multi
        expected = epsilon * 0.75  # a random legal row differs from the teacher's most of the time
        if not expected * 0.4 <= off <= expected * 1.8 + 0.01:
            blocking.append({"check": f"chosen differs from teacher on {off:.1%} of rows, epsilon {epsilon} predicts about {expected:.1%}", "count": 1})
        wait_share = wait_teacher / multi
        if wait_share > 0.8 or wait_share < 0.02:
            warnings.append({"check": f"teacher picks wait on {wait_share:.0%} of rows (labels nearly constant)", "count": 1})
        if multi3 and first3 / multi3 > 0.9:
            warnings.append({"check": f"'first legal non-wait row' already predicts {first3 / multi3:.0%} of the 3+ legal rows the G-BC gate scores: passing it proves little", "count": multi3})
        for arch, c in per_arch.items():
            if c / multi > 0.6:
                warnings.append({"check": f"archetype {arch} is {c / multi:.0%} of the data", "count": c})
        sizes = sorted(v["ok"] for v in per_file.values())
        median = sizes[len(sizes) // 2] if sizes else 0
        for n, v in per_file.items():
            # Late-game universes legitimately record several times more than early ones in the same time; only a runaway is 10x.
            if median and (v["ok"] < 0.35 * median or v["ok"] > 10 * median):
                warnings.append({"check": f"universe {n} has {v['ok']} rows against a median {median}", "count": v["ok"]})
    names = json.loads(Path(files[0] + '.schema.json').read_text()).get('state', []) if files and Path(files[0] + '.schema.json').exists() else []
    # New accounts never become Trader or Casual (CreateAiRlUniverse::archetype), so those two stay constant by design.
    # Every training universe is built at economy speed 8 (ai:rl-universe default), so speed_economy is constant too.
    expected_dead = {"archetype_trader", "archetype_casual", "speed_economy"}
    dead = [i for i in range(state_dim or 0) if multi and sqs[i] / multi - (sums[i] / multi) ** 2 < 1e-12
            and (names[i] if i < len(names) else i) not in expected_dead]
    if dead and not unfinished:
        warnings.append({"check": "constant state features over the whole set: " + ", ".join(str(names[i]) for i in dead[:8]), "count": len(dead)})

    def value_at(rows, day):
        before = [v for x, v in rows if x <= day]
        return before[-1] if before else None

    curve = {}
    for day in (1, 3, 5, 8, 10, 15, 20, 30):
        reached = [r for r in growth.values() if max(x for x, _ in r) >= day - 0.5]
        vals = sorted(v for v in (value_at(r, day) for r in reached) if v is not None)
        if len(vals) >= 0.9 * len(growth) and len(vals) >= 10:
            curve[day] = {"p10": vals[len(vals) // 10], "median": vals[len(vals) // 2], "p90": vals[9 * len(vals) // 10], "n": len(vals)}
    stalled = 0
    judged = 0
    for rows in growth.values():
        last_day = max(x for x, _ in rows)
        a, b = value_at(rows, 3), value_at(rows, min(last_day, 9))
        if a and b and last_day >= 6:
            judged += 1
            stalled += b <= a * 1.5
    if judged and stalled / judged > 0.05:
        warnings.append({"check": f"{stalled / judged:.0%} of accounts grew under 1.5x from day 3 to day 9 (stalled play)", "count": stalled})
    if cheap and pure / cheap > 0.05:
        warnings.append({"check": f"teacher picks a no-value object on {pure / cheap:.1%} of choices where a payback<2h mine was affordable", "count": pure})
    strategy = {"growth": curve, "stalled_share": round(stalled / judged, 4) if judged else None, "accounts_judged": judged,
                "cheap_mine_available": cheap, "no_value_pick_share": round(pure / cheap, 4) if cheap else None,
                "no_value_picks": dict(pure_by.most_common(6))}

    report = {"strategy": strategy, "files": len(files), "rows": total, "multi_legal": multi, "blocking": blocking, "warnings": warnings,
              "legal_counts": dict(sorted(legal_counts.items())), "teacher": dict(teacher_pos), "archetypes": dict(per_arch),
              "kinds": dict(kinds), "off_teacher_share": round(off_teacher / multi, 4) if multi else None,
              "first_legal_baseline": round(first_legal_hit / multi, 4) if multi else None,
              "first_legal_baseline_3plus": round(first3 / multi3, 4) if multi3 else None, "rows_3plus": multi3, "dead_state_features": len(dead),
              "dead_state_feature_names": [names[i] if i < len(names) else i for i in dead][:20], "verdict": "fail" if blocking else "pass"}
    return report


def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("pattern")
    ap.add_argument("--epsilon", type=float, default=0.1)
    ap.add_argument("--out", default="storage/rl/validation.json")
    args = ap.parse_args()
    report = validate(args.pattern, args.epsilon)
    Path(args.out).write_text(json.dumps(report, indent=1))
    print(json.dumps({k: report[k] for k in ("files", "rows", "multi_legal", "verdict", "blocking", "warnings", "teacher", "off_teacher_share", "first_legal_baseline", "first_legal_baseline_3plus", "dead_state_feature_names")}, indent=1))
    sys.exit(1 if report["blocking"] else 0)


if __name__ == "__main__":
    main()
