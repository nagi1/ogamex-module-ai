"""Twin-universe comparison (plan/rl/evaluation-plan.md): the same seeded universes played twice, once with the
planner (A) and once with the policy (B), compared account by account on account value.

    python -m ogrl.evaluate --a 'runs/eval/teacher/*.jsonl' --b 'runs/eval/policy/*.jsonl'

Account value = invested (host score formulas) + held resources, as recorded at each choice point; the
value at an account's last choice in the run is compared. Only accounts the policy decided for in B count.
"""

from __future__ import annotations

import argparse
import glob
import json
import math
from collections import defaultdict


def final_values(pattern: str) -> dict[tuple[int, int], dict]:
    out: dict[tuple[int, int], dict] = {}
    for path in sorted(glob.glob(pattern)):
        if path.endswith(".schema.json"):
            continue
        with open(path) as handle:
            for line in handle:
                if not line.strip():
                    continue
                row = json.loads(line)
                key = (int(row.get("seed", 0)), int(row["player"]))
                seen = out.get(key)
                if seen is None or row["t"] >= seen["t"]:
                    first = seen["first"] if seen else row["value"]
                    out[key] = {"t": row["t"], "value": row["value"], "first": first, "archetype": row["archetype"],
                                "learner": row["learner"], "policy": row["policy"]}
    return out


def stats(xs: list[float]) -> dict:
    n = len(xs)
    if n == 0:
        return {"n": 0}
    mean = sum(xs) / n
    sd = math.sqrt(sum((x - mean) ** 2 for x in xs) / (n - 1)) if n > 1 else 0.0
    se = sd / math.sqrt(n) if n > 1 else float("inf")
    return {"n": n, "mean": mean, "sd": sd, "ci95": [mean - 1.96 * se, mean + 1.96 * se], "t": mean / se if se not in (0, float("inf")) else None,
            "share_better": sum(1 for x in xs if x > 0) / n}


def main(argv: list[str] | None = None) -> None:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--a", required=True, help="glob of the planner (teacher) run recordings")
    ap.add_argument("--b", required=True, help="glob of the policy run recordings, same seeds")
    args = ap.parse_args(argv)

    a, b = final_values(args.a), final_values(args.b)
    diffs, by_arch = [], defaultdict(list)
    for key, rb in b.items():
        ra = a.get(key)
        if ra is None or not rb["learner"] or ra["value"] <= 0:
            continue
        rel = (rb["value"] - ra["value"]) / ra["value"]
        diffs.append(rel)
        by_arch[rb["archetype"]].append(rel)

    report = {"relative_value_difference_b_minus_a": stats(diffs), "by_archetype": {k: stats(v) for k, v in sorted(by_arch.items())},
              "accounts_a": len(a), "accounts_b": len(b)}
    print(json.dumps(report, indent=2))


if __name__ == "__main__":
    main()
