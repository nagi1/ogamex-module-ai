"""Expert iteration: clone the look-ahead champion into the candidate scorer, on the GPU.

The season's `champion-out=FILE` writes every look-ahead decision as raw little-endian f32: state [S], candidates
[K, C], legal [K], worths [K] (NaN where illegal). The target at a decision is a softmax over the legal rows of each
row's worth relative to the best row's, so a row 1% (`--tau`) poorer than the best gets e^-1 of its weight: the
student learns the champion's ranking, not only its argmax (AlphaZero trains on visit counts for the same reason).
Each decision is weighted by its stakes, the worth a uniform legal pick gives up against the best (advantage-weighted
imitation): three in four decisions have their two best rows within 1%, inside two rollouts' noise, and trained equally
they drowned the few that decide a season, such as the deuterium synthesizer before the robot factory (3 Nov).

    python -m ogrl.train_exit --init best.pt --out exit.pt --labels a.f32 b.f32 [--epochs 20] [--device cuda]
"""

from __future__ import annotations

import argparse
import json
import time
from pathlib import Path

import numpy as np
import torch

from . import default_device
from .errand_schema import LEGACY_VERSION, load_errand, read_header, widen_checkpoint
from .model import CandidateScorer


def records(paths: list[str], s_dim: int, c_dim: int, k_given: int | None = None) -> tuple[np.ndarray, int]:
    """Every whole record (a file a season is still writing ends mid-record), and K: the row count for which every
    legal flag is 0 or 1 and each record's last (the wait row) is 1."""
    files = [np.fromfile(p, dtype="<f4") for p in paths]
    for k in [k_given] if k_given else range(2, 400):
        width = s_dim + k * (c_dim + 2)
        rows = np.concatenate([f[: len(f) // width * width] for f in files]).reshape(-1, width)
        if not len(rows):
            continue
        legal = rows[:, s_dim + k * c_dim: s_dim + k * c_dim + k]
        if np.isin(legal, (0.0, 1.0)).all() and (k_given or (legal[:, -1] == 1).all()):
            return rows, k
    raise ValueError("no row count fits the label files")


def validate_errand_schema(saved: dict, paths: list[str]) -> tuple[int, dict, int]:
    """Check the errand labels' sidecars agree on their feature version, width and column names, and return (candidate
    width, names, version): the errand menu offers `wait` first and pads the rest, so `records` cannot infer K from a
    legal last row. A sidecar without names is today's (version 2) header."""
    if "target_ratio_observed_or_estimated" not in saved.get("names", {}).get("candidate", []):
        raise ValueError("errand checkpoint has old or missing feature names")
    headers = []
    for path in paths:
        header = read_header(path)
        if header is None or header.get("version") is None or int(header["version"]) < LEGACY_VERSION:
            raise ValueError(f"{path}: errand labels need feature version {LEGACY_VERSION} or later")
        if not header.get("candidates"):
            raise ValueError(f"{path}: errand labels need their candidate width in the schema")
        headers.append(header)
    if len({h["candidates"] for h in headers}) > 1:
        raise ValueError("errand label files disagree on the candidate width")
    if any((h["version"], h["state"], h["candidate"]) != (headers[0]["version"], headers[0]["state"], headers[0]["candidate"]) for h in headers):
        raise ValueError("errand label files disagree on the feature version or column names")
    first = headers[0]
    return first["candidates"], {"state": first["state"], "candidate": first["candidate"]}, int(first["version"])


def main(argv: list[str] | None = None) -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--init", required=True, help="checkpoint to start from (its width and normalisation are kept)")
    ap.add_argument("--out", required=True)
    ap.add_argument("--labels", nargs="+", required=True)
    ap.add_argument("--tau", type=float, default=0.01)
    ap.add_argument("--epochs", type=int, default=20)
    ap.add_argument("--lr", type=float, default=3e-4)
    ap.add_argument("--batch", type=int, default=1024)
    ap.add_argument("--device", default=default_device())
    ap.add_argument("--k", type=int, help="rows per decision when not the build menu (the errand menu: 32, wait first)")
    ap.add_argument("--width", type=int, help="a wider network than --init's, trained from its normalisation")
    args = ap.parse_args(argv)
    started = time.time()

    saved = torch.load(args.init, map_location="cpu", weights_only=False)
    errand_k = None
    if saved.get("queue") == 2 or args.k == 32:
        errand_k, label_names, label_version = validate_errand_schema(saved, args.labels)
        # Labels from a wider simulator: the checkpoint widens by column name (new columns at weight 0, same old scores).
        saved = widen_checkpoint(load_errand(args.init), label_names, label_version)
    s_dim, c_dim = saved["state_dim"], saved["cand_dim"]
    rows, k = records(args.labels, s_dim, c_dim, args.k or errand_k)
    state = rows[:, :s_dim]
    cands = rows[:, s_dim: s_dim + k * c_dim].reshape(-1, k, c_dim)
    legal = rows[:, s_dim + k * c_dim: s_dim + k * c_dim + k] > 0
    worths = rows[:, s_dim + k * (c_dim + 1):].astype(np.float64)
    # A decision with one legal row is not looked ahead, and a row the prior pruned has no worth: train on the rest.
    keep = (legal & np.isfinite(worths)).sum(1) >= 2
    state, cands, legal, worths = state[keep], cands[keep], legal[keep] & np.isfinite(worths[keep]), worths[keep]
    best = np.nanmax(np.where(legal, worths, np.nan), axis=1, keepdims=True)
    relative = (worths - best) / np.maximum(np.abs(best), 1.0)
    logits = np.where(legal, relative / args.tau, -np.inf)
    target = np.exp(logits - logits.max(axis=1, keepdims=True))
    target /= target.sum(axis=1, keepdims=True)
    stakes = -np.where(legal, relative, 0.0).sum(1) / legal.sum(1)
    weight = stakes / stakes.mean()

    device = torch.device(args.device)
    width = args.width or saved["width"]
    model = CandidateScorer(s_dim, c_dim, width).to(device)
    if width == saved["width"]:
        model.load_state_dict(saved["model"])
    else:
        model.set_normalisation(*(saved["model"][name] for name in ("state_mean", "state_std", "cand_mean", "cand_std")))
    t = lambda x, dtype=torch.float32: torch.as_tensor(np.ascontiguousarray(x), dtype=dtype, device=device)
    data = [t(state), t(cands), t(legal, torch.bool), t(target), t(target.argmax(1), torch.long), t(np.where(legal, relative, 0.0)), t(weight)]
    n = len(state)
    order = np.random.default_rng(0).permutation(n)
    held, train = order[: max(1, n // 10)], order[max(1, n // 10):]
    va = [d[held] for d in data]

    weighty = va[6] > 1.0

    def judge() -> tuple[float, float, float, float]:
        """Held-out weighted loss, agreement with the champion's pick, regret (the worth the student's pick gives up
        against the champion's, as a share) and regret on the decisions of above-average stakes."""
        with torch.inference_mode():
            out, _ = model(*va[:3])
            loss = -(va[6] * (va[3] * torch.log_softmax(out, 1).clamp_min(-1e4)).sum(1)).mean().item()
            given_up = -va[5].gather(1, out.argmax(1, keepdim=True)).squeeze(1)
            return loss, (out.argmax(1) == va[4]).float().mean().item(), given_up.mean().item(), given_up[weighty].mean().item()

    loss0, top0, regret0, high0 = judge()
    print(f"rows {n:,} (held out {len(held):,}), candidates {k}; start: held-out loss {loss0:.4f}, agrees with the champion {top0:.1%}, regret {regret0:.3%}, high-stakes regret {high0:.3%}", flush=True)
    opt = torch.optim.Adam([p for name, p in model.named_parameters() if not name.startswith("value")], lr=args.lr)
    for epoch in range(args.epochs):
        model.train()
        total = 0.0
        for at in range(0, len(train), args.batch):
            idx = torch.as_tensor(train[at: at + args.batch], device=device)
            out, _ = model(data[0][idx], data[1][idx], data[2][idx])
            loss = -(data[6][idx] * (data[3][idx] * torch.log_softmax(out, 1).clamp_min(-1e4)).sum(1)).mean()
            opt.zero_grad()
            loss.backward()
            torch.nn.utils.clip_grad_norm_(model.parameters(), 0.5)
            opt.step()
            total += loss.item() * len(idx)
        held_loss, top1, regret, high = judge()
        print(f"epoch {epoch + 1}: train loss {total / len(train):.4f}, held-out loss {held_loss:.4f}, agrees with the champion {top1:.1%}, regret {regret:.3%}, high-stakes regret {high:.3%}", flush=True)

    out = Path(args.out)
    out.parent.mkdir(parents=True, exist_ok=True)
    torch.save({**saved, "width": width, "model": {name: v.detach().cpu() for name, v in model.state_dict().items()}, "iteration": 0}, out)
    print(f"wrote {out} ({time.time() - started:.0f}s)")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
