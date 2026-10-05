"""Behaviour cloning: teach the candidate scorer to pick what the deterministic planner picks.

    python -m ogrl.train_bc --data 'runs/bc/*.jsonl' --out runs/bc-model --epochs 30

Writes model.pt (weights + schema), model.onnx, metrics.json (train/validation per epoch, best epoch in full).
The point is not a better player: it proves state -> encoding -> network -> candidate choice works before any
reinforcement learning (plan/rl/training-plan.md, section 4).
"""

from __future__ import annotations

import argparse
import json
import random
import time
from pathlib import Path

import numpy as np
import torch
import torch.nn.functional as F

from . import data as datalib
from .export import export_onnx
from .metrics import summarise
from .model import CandidateScorer, parameter_count


def seed_everything(seed: int) -> None:
    random.seed(seed)
    np.random.seed(seed)
    torch.manual_seed(seed)
    torch.cuda.manual_seed_all(seed)


def normalisation(train: datalib.Dataset) -> tuple[np.ndarray, ...]:
    rows = train.cands[train.present]
    return train.state.mean(0), train.state.std(0), rows.mean(0), rows.std(0)


def predict(model: CandidateScorer, ds: datalib.Dataset, device: str, batch: int = 4096) -> np.ndarray:
    model.eval()
    out = []
    with torch.no_grad():
        for i in range(0, len(ds), batch):
            s = torch.as_tensor(ds.state[i:i + batch], device=device)
            c = torch.as_tensor(ds.cands[i:i + batch], device=device)
            m = torch.as_tensor(ds.mask[i:i + batch], device=device)
            logits, _ = model(s, c, m)
            out.append(logits.float().cpu().numpy())
    return np.concatenate(out)


def main(argv: list[str] | None = None) -> None:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--data", nargs="+", required=True, help="glob(s) of recorded JSON Lines")
    ap.add_argument("--out", required=True)
    ap.add_argument("--epochs", type=int, default=30)
    ap.add_argument("--batch", type=int, default=1024)
    ap.add_argument("--lr", type=float, default=3e-4)
    ap.add_argument("--width", type=int, default=256)
    ap.add_argument("--validation", type=float, default=0.2)
    ap.add_argument("--min-legal", type=int, default=2)
    ap.add_argument("--seed", type=int, default=1)
    ap.add_argument("--device", default="cuda" if torch.cuda.is_available() else "cpu")
    args = ap.parse_args(argv)

    seed_everything(args.seed)
    out = Path(args.out)
    out.mkdir(parents=True, exist_ok=True)

    ds = datalib.load(args.data, min_legal=args.min_legal)
    train, val = datalib.split_by_universe(ds, args.validation)
    print(f"choices: {len(ds)} (train {len(train)}, validation {len(val)}), universes {len(np.unique(ds.group))}, "
          f"state {ds.state.shape[1]}, candidate {ds.cands.shape[2]}, schema v{ds.schema.version}")

    model = CandidateScorer(ds.state.shape[1], ds.cands.shape[2], args.width)
    model.set_normalisation(*normalisation(train))
    model.to(args.device)
    print(f"parameters: {parameter_count(model):,} on {args.device}")
    opt = torch.optim.AdamW(model.parameters(), lr=args.lr, weight_decay=1e-4)

    # The training set fits in GPU memory: batches are sliced on the device instead of copied from the host.
    tensors = [torch.as_tensor(x, device=args.device) for x in (train.state, train.cands, train.mask, train.teacher)]
    history, best, best_key = [], None, -1.0
    for epoch in range(1, args.epochs + 1):
        model.train()
        started, losses = time.time(), []
        perm = torch.randperm(len(train), device=args.device)
        for i in range(0, len(train), args.batch):
            idx = perm[i:i + args.batch]
            s, c, m, y = (t[idx] for t in tensors)
            logits, _ = model(s, c, m)
            loss = F.cross_entropy(logits, y)
            opt.zero_grad(set_to_none=True)
            loss.backward()
            torch.nn.utils.clip_grad_norm_(model.parameters(), 1.0)
            opt.step()
            losses.append(loss.detach())

        v = summarise(predict(model, val, args.device), val.mask, val.teacher, val.meta, val.cands, ds.schema.candidate)
        row = {"epoch": epoch, "loss": float(torch.stack(losses).mean()), "val_top1": v["top1"], "val_mrr": v["mrr"],
               "val_top1_3plus": v.get("top1_3plus_legal"), "seconds": round(time.time() - started, 1)}
        history.append(row)
        print(json.dumps(row))
        key = v.get("top1_3plus_legal", v["top1"])
        if key > best_key:
            best_key, best = key, v
            torch.save({"state_dict": model.state_dict(), "schema": ds.schema.to_json(), "width": args.width}, out / "model.pt")

    model.load_state_dict(torch.load(out / "model.pt", map_location=args.device)["state_dict"])
    export_onnx(model, out / "model.onnx", ds.state.shape[1], ds.cands.shape[2])
    model.to(args.device)  # the exporter moves the model to the CPU in place
    (out / "schema.json").write_text(json.dumps(ds.schema.to_json(), indent=2))
    train_summary = summarise(predict(model, train, args.device), train.mask, train.teacher, train.meta, train.cands, ds.schema.candidate)
    (out / "metrics.json").write_text(json.dumps({"history": history, "validation": best, "train": train_summary,
                                                    "args": vars(args), "parameters": parameter_count(model)}, indent=2))
    print(f"best validation: top1 {best['top1']:.3f}, mrr {best['mrr']:.3f}, top1 (3+ legal) {best.get('top1_3plus_legal')}, "
          f"random baseline {best['baseline_random_top1']:.3f} -> {out}")


if __name__ == "__main__":
    main()
