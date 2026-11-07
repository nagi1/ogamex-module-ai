"""Read champion errand labels (`champion-errand-out=`): how far apart the look-ahead's rows are, which kinds it prefers,
and where a model's argmax disagrees with it.

    python scripts/errand_labels.py LABELS.f32 [...] [--model CHECKPOINT.pt]

Worth spreads are shares of the account's worth (score x 1000 + stock), the same scale `ogrl.train_exit` takes `--tau` on.
"""

from __future__ import annotations

import argparse
import json
import sys
from collections import Counter
from pathlib import Path

import numpy as np

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))


def load(paths: list[str]) -> tuple[np.ndarray, np.ndarray, np.ndarray, np.ndarray, dict]:
    schema = json.loads(Path(paths[0] + ".schema.json").read_text())
    s, c, k = len(schema["state"]), len(schema["candidate"]), schema["candidates"]
    width = s + k * (c + 2)
    rows = np.concatenate([(f := np.fromfile(p, dtype="<f4"))[: len(f) // width * width] for p in paths]).reshape(-1, width)
    state = rows[:, :s]
    cands = rows[:, s: s + k * c].reshape(-1, k, c)
    legal = rows[:, s + k * c: s + k * c + k] > 0
    worths = rows[:, s + k * (c + 1):].astype(np.float64)
    return state, cands, legal & np.isfinite(worths), worths, schema


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    ap.add_argument("labels", nargs="+")
    ap.add_argument("--model", help="an errand checkpoint (.pt): its argmax regret against the look-ahead")
    args = ap.parse_args()
    state, cands, legal, worths, schema = load(args.labels)
    names = schema["candidate"]
    kinds = [n for n in schema["kinds"] if n in names]
    kind_at = [names.index(n) for n in kinds]
    n_all = len(state)
    keep = legal.sum(1) >= 2
    state, cands, legal, worths = state[keep], cands[keep], legal[keep], worths[keep]
    w = np.where(legal, worths, np.nan)
    best = np.nanmax(w, 1)
    rel = (w - best[:, None]) / np.maximum(np.abs(best[:, None]), 1.0)
    second = np.sort(np.where(np.isnan(rel), -np.inf, rel), 1)[:, -2]
    wait_gap = -rel[:, 0]
    print(f"decisions {n_all:,}, with >= 2 legal rows {len(state):,}; legal rows median {np.median(legal.sum(1)):.0f}")
    for name, x in (("best - second", -second), ("best - wait", wait_gap)):
        q = np.quantile(x, [0.5, 0.75, 0.9, 0.99])
        print(f"{name:14s} share of worth: p50 {q[0]:.2e} p75 {q[1]:.2e} p90 {q[2]:.2e} p99 {q[3]:.2e}; "
              f"> 1e-4: {(x > 1e-4).mean():.1%}, > 1e-3: {(x > 1e-3).mean():.1%}, > 1e-2: {(x > 1e-2).mean():.1%}")
    pick = np.nanargmax(w, 1)
    kind_of = lambda i, row: kinds[int(np.argmax(cands[i, row, kind_at]))] if cands[i, row, kind_at].max() > 0 else "?"
    chosen = Counter(kind_of(i, r) for i, r in enumerate(pick))
    offered = Counter(kind_of(i, r) for i in range(len(state)) for r in np.flatnonzero(legal[i]))
    print("look-ahead picks by kind (picked / offered):")
    for k, v in chosen.most_common():
        print(f"  {k:24s} {v:7,} / {offered[k]:7,}")
    if args.model:
        import torch
        from ogrl.errand_schema import load_errand
        from ogrl.model import CandidateScorer
        saved = load_errand(args.model)
        assert saved["names"].get("candidate") == names and saved["names"].get("state") == schema["state"], "model columns differ from the labels'"
        model = CandidateScorer(saved["state_dim"], saved["cand_dim"], saved["width"])
        model.load_state_dict(saved["model"])
        with torch.inference_mode():
            out, _ = model(torch.as_tensor(state), torch.as_tensor(cands), torch.as_tensor(legal))
        mine = out.argmax(1).numpy()
        given = -rel[np.arange(len(mine)), mine]
        given = np.nan_to_num(given, nan=0.0)
        stakes = np.nanmean(-rel, 1)
        high = stakes > np.mean(stakes)
        print(f"model argmax: agrees {np.mean(mine == pick):.1%}; regret mean {given.mean():.2e}, high-stakes {given[high].mean():.2e}, "
              f"decisions giving up > 1e-3: {(given > 1e-3).mean():.1%}")
        swaps = Counter((kind_of(i, mine[i]), kind_of(i, pick[i])) for i in np.flatnonzero(given > 1e-4))
        print("largest disagreements (model kind -> look-ahead kind), regret > 1e-4:")
        for (a, b), v in swaps.most_common(10):
            print(f"  {a:22s} -> {b:22s} {v:6,}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
