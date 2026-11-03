"""Expert iteration: clone the look-ahead champion into the candidate scorer, on the GPU.

The season's `champion-out=FILE` writes every look-ahead decision as raw little-endian f32: state [S], candidates
[K, C], legal [K], worths [K] (NaN where illegal). The target at a decision is a softmax over the legal rows of each
row's worth relative to the best row's, so a row 1% (`--tau`) poorer than the best gets e^-1 of its weight: the
student learns the champion's ranking, not only its argmax (AlphaZero trains on visit counts for the same reason).

    python -m ogrl.train_exit --init best.pt --out exit.pt --labels a.f32 b.f32 [--epochs 20] [--device cuda]
"""

from __future__ import annotations

import argparse
import time
from pathlib import Path

import numpy as np
import torch

from .model import CandidateScorer


def records(paths: list[str], s_dim: int, c_dim: int) -> tuple[np.ndarray, int]:
    """Every record, and K: the only row count for which each record's last legal flag (the wait row) is 1."""
    raw = np.concatenate([np.fromfile(p, dtype="<f4") for p in paths])
    for k in range(2, 400):
        width = s_dim + k * (c_dim + 2)
        if len(raw) % width:
            continue
        rows = raw.reshape(-1, width)
        legal = rows[:, s_dim + k * c_dim: s_dim + k * c_dim + k]
        if np.isin(legal, (0.0, 1.0)).all() and (legal[:, -1] == 1).all():
            return rows, k
    raise ValueError("no row count fits the label files")


def main(argv: list[str] | None = None) -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--init", required=True, help="checkpoint to start from (its width and normalisation are kept)")
    ap.add_argument("--out", required=True)
    ap.add_argument("--labels", nargs="+", required=True)
    ap.add_argument("--tau", type=float, default=0.01)
    ap.add_argument("--epochs", type=int, default=20)
    ap.add_argument("--lr", type=float, default=3e-4)
    ap.add_argument("--batch", type=int, default=1024)
    ap.add_argument("--device", default="cuda")
    args = ap.parse_args(argv)
    started = time.time()

    saved = torch.load(args.init, map_location="cpu", weights_only=False)
    s_dim, c_dim = saved["state_dim"], saved["cand_dim"]
    rows, k = records(args.labels, s_dim, c_dim)
    state = rows[:, :s_dim]
    cands = rows[:, s_dim: s_dim + k * c_dim].reshape(-1, k, c_dim)
    legal = rows[:, s_dim + k * c_dim: s_dim + k * c_dim + k] > 0
    worths = rows[:, s_dim + k * (c_dim + 1):].astype(np.float64)
    best = np.nanmax(np.where(legal, worths, np.nan), axis=1, keepdims=True)
    relative = (worths - best) / np.maximum(np.abs(best), 1.0)
    logits = np.where(legal, relative / args.tau, -np.inf)
    target = np.exp(logits - logits.max(axis=1, keepdims=True))
    target /= target.sum(axis=1, keepdims=True)

    device = torch.device(args.device)
    model = CandidateScorer(s_dim, c_dim, saved["width"]).to(device)
    model.load_state_dict(saved["model"])
    t = lambda x, dtype=torch.float32: torch.as_tensor(np.ascontiguousarray(x), dtype=dtype, device=device)
    data = [t(state), t(cands), t(legal, torch.bool), t(target), t(target.argmax(1), torch.long)]
    n = len(state)
    order = np.random.default_rng(0).permutation(n)
    held, train = order[: max(1, n // 10)], order[max(1, n // 10):]
    va = [d[held] for d in data]

    def judge() -> tuple[float, float]:
        with torch.inference_mode():
            out, _ = model(*va[:3])
            loss = -(va[3] * torch.log_softmax(out, 1).clamp_min(-1e4)).sum(1).mean().item()
            return loss, (out.argmax(1) == va[4]).float().mean().item()

    loss0, top0 = judge()
    print(f"rows {n:,} (held out {len(held):,}), candidates {k}; start: held-out loss {loss0:.4f}, agrees with the champion {top0:.1%}", flush=True)
    opt = torch.optim.Adam([p for name, p in model.named_parameters() if not name.startswith("value")], lr=args.lr)
    for epoch in range(args.epochs):
        model.train()
        total = 0.0
        for at in range(0, len(train), args.batch):
            idx = torch.as_tensor(train[at: at + args.batch], device=device)
            out, _ = model(data[0][idx], data[1][idx], data[2][idx])
            loss = -(data[3][idx] * torch.log_softmax(out, 1).clamp_min(-1e4)).sum(1).mean()
            opt.zero_grad()
            loss.backward()
            torch.nn.utils.clip_grad_norm_(model.parameters(), 0.5)
            opt.step()
            total += loss.item() * len(idx)
        held_loss, top1 = judge()
        print(f"epoch {epoch + 1}: train loss {total / len(train):.4f}, held-out loss {held_loss:.4f}, agrees with the champion {top1:.1%}", flush=True)

    out = Path(args.out)
    out.parent.mkdir(parents=True, exist_ok=True)
    torch.save({**saved, "model": {name: v.detach().cpu() for name, v in model.state_dict().items()}, "iteration": 0}, out)
    print(f"wrote {out} ({time.time() - started:.0f}s)")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
