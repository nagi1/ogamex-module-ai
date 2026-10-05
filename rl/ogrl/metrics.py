"""Imitation metrics for variable candidate lists (plan/rl/training-plan.md, section 4). Plain accuracy is
misleading when a list has one or two legal rows, so every number is also given on multi-candidate choices
and broken down by choice kind, archetype, phase and list size."""

from __future__ import annotations

from collections import defaultdict

import numpy as np


def summarise(logits: np.ndarray, mask: np.ndarray, label: np.ndarray, meta: dict[str, np.ndarray], cands: np.ndarray | None = None, cand_names: list[str] | None = None) -> dict:
    scores = np.where(mask, logits, -np.inf)
    order = np.argsort(-scores, axis=1)
    rank = np.argmax(order == label[:, None], axis=1)  # 0 = top
    top1 = rank == 0
    top3 = rank < 3
    rr = 1.0 / (rank + 1)
    n_legal = mask.sum(axis=1)

    out = {
        "n": int(len(label)),
        "top1": float(top1.mean()),
        "top3": float(top3.mean()),
        "mrr": float(rr.mean()),
        "baseline_random_top1": float((1.0 / np.maximum(n_legal, 1)).mean()),
        "wait_rate_model": float((order[:, 0] == 0).mean()),
        "wait_rate_teacher": float((label == 0).mean()),
    }
    many = n_legal >= 3
    if many.any():
        out["top1_3plus_legal"] = float(top1[many].mean())
        out["mrr_3plus_legal"] = float(rr[many].mean())

    # The planner's pick is nearly always the first legal non-wait row, so plain accuracy can be reached by copying
    # list order. The rows where the teacher is not that row are the ones that show whether the game was learned.
    first = np.argmax(mask[:, 1:], axis=1) + 1
    hard = many & (label != first)
    out["baseline_first_legal_3plus"] = float((label[many] == first[many]).mean()) if many.any() else None
    out["hard_rows_share"] = float(hard.sum() / max(many.sum(), 1))
    if hard.any():
        out["top1_hard_rows"] = float(top1[hard].mean())
        out["baseline_random_hard_rows"] = float((1.0 / np.maximum(n_legal[hard], 1)).mean())

    # Regret-like: how much worse the chosen row's payback is than the teacher's (0 when equivalent).
    if cands is not None and cand_names and "payback_hours" in cand_names:
        p = cand_names.index("payback_hours")
        picked = cands[np.arange(len(label)), order[:, 0], p]
        wanted = cands[np.arange(len(label)), label, p]
        out["payback_log_regret"] = float(np.maximum(0.0, picked - wanted).mean())

    for key in ("kind", "archetype", "phase"):
        if key in meta:
            out[f"top1_by_{key}"] = _by(meta[key], top1)
    out["top1_by_n_legal"] = _by(np.minimum(n_legal, 8).astype(str), top1)
    return out


def _by(groups: np.ndarray, hit: np.ndarray) -> dict:
    sums: dict[str, list[float]] = defaultdict(lambda: [0.0, 0.0])
    for g, h in zip(groups, hit):
        sums[str(g)][0] += float(h)
        sums[str(g)][1] += 1.0
    return {g: {"top1": s / n, "n": int(n)} for g, (s, n) in sorted(sums.items())}
