"""A critic for the look-ahead champion: the value head learns the log of the points an account ends the season with,
from the state the learner reads at any of its build decisions, `--ahead` days later (AlphaZero's value network; it
lets a short rollout be scored by what the account will be weeks later instead of by what pays back within the
rollout). Not the season's end: a late-game account is limited by build time, every active account ends within about
10% of the others whatever it did, and a critic of the end has nothing to learn (3 Nov, R2 ~0).

The season's `value-out=FILE` writes, per build decision, the account, the day and the state as raw little-endian f32;
the season's own lines give each account's last points. Accounts inactive at the end are left out: quitting is drawn,
not played. Whole seeds are held out, so the error printed is on worlds the critic never saw.

    python -m ogrl.train_value --init best.pt --out critic.pt --runs value-es3 [--width 256] [--device cuda:1]
"""

from __future__ import annotations

import argparse
import glob
import json
import math
import os
import time
from pathlib import Path

import numpy as np
import torch

from .model import CandidateScorer


def daily(path: str) -> tuple[np.ndarray, int]:
    """Log points per (account, day) from the season's lines, NaN where the account is inactive."""
    rows = [json.loads(line) for line in open(path)]
    rows = [r for r in rows if "score" in r]
    days = max(r["day"] for r in rows)
    table = np.full((max(r["account"] for r in rows) + 1, days + 1), np.nan)
    for r in rows:
        if not r.get("inactive"):
            table[r["account"], r["day"]] = math.log1p(r["score"]["total"])
    return table, days


def main(argv: list[str] | None = None) -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--init", required=True, help="checkpoint whose shape and candidate normalisation the critic keeps")
    ap.add_argument("--out", required=True)
    ap.add_argument("--runs", nargs="+", required=True, help="directories with states-SEED.f32 and ready-SEED.jsonl")
    ap.add_argument("--width", type=int, default=256)
    ap.add_argument("--ahead", type=int, default=30, help="days ahead the critic predicts (capped at the season's last day)")
    ap.add_argument("--epochs", type=int, default=30)
    ap.add_argument("--lr", type=float, default=1e-3)
    ap.add_argument("--batch", type=int, default=4096)
    ap.add_argument("--device", default="cuda")
    args = ap.parse_args(argv)
    started = time.time()
    saved = torch.load(args.init, map_location="cpu", weights_only=False)
    s_dim, c_dim = saved["state_dim"], saved["cand_dim"]

    xs, ys, seeds = [], [], []
    for run in args.runs:
        for path in sorted(glob.glob(os.path.join(run, "states-*.f32"))):
            seed = int(os.path.basename(path).split("-")[1].split(".")[0])
            ready = os.path.join(run, f"ready-{seed}.jsonl")
            if not os.path.exists(ready):
                continue
            table, days = daily(ready)
            raw = np.fromfile(path, dtype="<f4")
            rows = raw[: len(raw) // (s_dim + 2) * (s_dim + 2)].reshape(-1, s_dim + 2)
            rows = rows[np.random.default_rng(seed).random(len(rows)) < 0.25]
            day = np.minimum(np.floor(rows[:, 1]).astype(int) + 1 + args.ahead, days)
            target = table[rows[:, 0].astype(int), day]
            keep = np.isfinite(target)
            xs.append(rows[keep, 2:])
            ys.append(target[keep])
            seeds.append(np.full(keep.sum(), seed))
    x, y, seed_of = np.concatenate(xs), np.concatenate(ys).astype(np.float32), np.concatenate(seeds)
    held_seeds = sorted(set(seed_of))[-max(1, len(set(seed_of)) // 6):]
    held = np.isin(seed_of, held_seeds)
    print(f"states {len(x):,} from {len(set(seed_of))} seasons; held-out seeds {held_seeds} ({held.sum():,} states); "
          f"target log points mean {y.mean():.2f} sd {y.std():.2f}", flush=True)

    device = torch.device(args.device)
    model = CandidateScorer(s_dim, c_dim, args.width).to(device)
    m = saved["model"]
    model.set_normalisation(x[~held].mean(0), x[~held].std(0), m["cand_mean"], m["cand_std"])
    t = lambda a: torch.as_tensor(np.ascontiguousarray(a), device=device)
    tx, ty = t(x), t(y)
    train_idx, held_idx = np.flatnonzero(~held), torch.as_tensor(np.flatnonzero(held), device=device)
    value = lambda batch: model.value(model.state_net((batch - model.state_mean) / model.state_std)).squeeze(-1)
    params = list(model.state_net.parameters()) + list(model.value.parameters())
    opt = torch.optim.Adam(params, lr=args.lr)
    rng = np.random.default_rng(0)

    def judge() -> tuple[float, float]:
        with torch.inference_mode():
            pred = value(tx[held_idx])
            err = (pred - ty[held_idx])
            return err.abs().mean().item(), 1 - (err ** 2).mean().item() / ty[held_idx].var().item()

    for epoch in range(args.epochs):
        order = rng.permutation(train_idx)
        total = 0.0
        for at in range(0, len(order), args.batch):
            idx = torch.as_tensor(order[at: at + args.batch], device=device)
            loss = torch.nn.functional.smooth_l1_loss(value(tx[idx]), ty[idx])
            opt.zero_grad()
            loss.backward()
            opt.step()
            total += loss.item() * len(idx)
        mae, r2 = judge()
        print(f"epoch {epoch + 1}: train loss {total / len(order):.4f}, held-out error {mae:.3f} log points (x{math.exp(mae):.2f}), R2 {r2:.3f}", flush=True)

    out = Path(args.out)
    out.parent.mkdir(parents=True, exist_ok=True)
    torch.save({**saved, "width": args.width, "model": {k: v.detach().cpu() for k, v in model.state_dict().items()}}, out)
    print(f"wrote {out} ({time.time() - started:.0f}s)")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
