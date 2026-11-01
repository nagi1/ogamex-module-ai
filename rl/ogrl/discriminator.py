"""The detector in the reward: does an account's day look real? (plan/rl/TRAINING-RUN.md)

Real side: the samples of real-bands.json, 267 universes, per cell of rank band and universe age. Our side: the active
accounts of season files at the same rank and age. It reads hds.py's eight measures standardised inside the cell, as
hds.py's own pooled detector does, with growth on a log scale because real growth spans five orders of magnitude.

    python -m ogrl.discriminator --out runs/x/detector.json runs/flow/e99i/ready-*.jsonl
"""

from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path

import numpy as np
import torch
from sklearn.metrics import roc_auc_score
from torch import nn

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "scripts"))
from hds import DEFAULT_BANDS, day_lines, score_rows  # noqa: E402
from real_bands import MEASURES, MIN_CELL  # noqa: E402

GROWTH = MEASURES.index("growth")
CLIP = 8.0


def logged(vectors: np.ndarray) -> np.ndarray:
    out = vectors.astype(np.float64).copy()
    out[:, GROWTH] = np.log1p(np.maximum(out[:, GROWTH], 0.0))
    return out


def season_rows(paths: list[Path]) -> list[dict]:
    """The lines hds.py scores (every tenth day and the last); a season file is 66 MB, so only those are kept whole."""
    fields = ("seed", "account", "day", "world_speed", "score", "planets", "moons", "galaxies", "alliance", "inactive", "persona")
    rows = []
    for path in paths:
        for line in path.open():
            row = json.loads(line)
            if row["day"] % 10 == 0 or row["day"] == 365:
                rows.append({name: row[name] for name in fields})
    return rows


def main() -> None:
    ap = argparse.ArgumentParser(prog="ogrl.discriminator", description=__doc__.splitlines()[0])
    ap.add_argument("seasons", nargs="+", type=Path)
    ap.add_argument("--out", required=True, type=Path)
    ap.add_argument("--bands", type=Path, default=DEFAULT_BANDS)
    ap.add_argument("--epochs", type=int, default=60)
    ap.add_argument("--device", default="cuda:1" if torch.cuda.device_count() > 1 else "cpu")
    args = ap.parse_args()

    bands = json.loads(args.bands.read_text())
    cells, _ = score_rows(day_lines(season_rows(args.seasons)))
    xs, ys, weights, kept = [], [], [], {}
    for name, accounts in cells.items():
        real_cell = bands["cells"].get(name)
        if not real_cell or real_cell["n"] < MIN_CELL or len(accounts) < 8:
            continue
        real = logged(np.array(real_cell["sample"]))
        ours = logged(np.array([[a["vector"][m] for m in MEASURES] for a in accounts]))
        centre, spread = real.mean(0), np.where(real.std(0) < 1e-6, 1.0, real.std(0))
        kept[name] = {"centre": centre.tolist(), "spread": spread.tolist()}
        for group, label in ((real, 1.0), (ours, 0.0)):
            xs.append(np.clip((group - centre) / spread, -CLIP, CLIP))
            ys.append(np.full(len(group), label))
            # Each cell and each side counts alike, so a cell's own size says nothing.
            weights.append(np.full(len(group), 1.0 / len(group)))
    x, y, w = (torch.as_tensor(np.concatenate(v), dtype=torch.float32, device=args.device) for v in (xs, ys, weights))
    w = w / w.mean()

    def fit(train: torch.Tensor) -> nn.Sequential:
        net = nn.Sequential(nn.Linear(len(MEASURES), 32), nn.ReLU(), nn.Linear(32, 32), nn.ReLU(), nn.Linear(32, 1)).to(args.device)
        optimizer = torch.optim.Adam(net.parameters(), lr=2e-3, weight_decay=1e-3)
        for _ in range(args.epochs):
            for batch in train[torch.randperm(len(train), device=args.device)].split(1024):
                loss = nn.functional.binary_cross_entropy_with_logits(net(x[batch]).squeeze(-1), y[batch], weight=w[batch])
                optimizer.zero_grad()
                loss.backward()
                optimizer.step()
        return net

    order = torch.randperm(len(x), device=args.device)
    cut = len(x) // 5
    probe = fit(order[cut:])
    held = probe(x[order[:cut]]).squeeze(-1).detach().cpu().numpy()
    auc = roc_auc_score(y[order[:cut]].cpu().numpy(), held, sample_weight=w[order[:cut]].cpu().numpy())
    net = fit(order)
    weights_out = {f"net.{name}": tensor.detach().cpu().tolist() for name, tensor in net.state_dict().items()}
    args.out.parent.mkdir(parents=True, exist_ok=True)
    args.out.write_text(json.dumps({"cells": kept, "auc_held_out": auc, **weights_out}))
    print(f"{len(kept)} cells, {int(y.sum())} real and {int((1 - y).sum())} of ours, held-out AUC {auc:.3f} -> {args.out}")


if __name__ == "__main__":
    main()
