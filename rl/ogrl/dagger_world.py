"""DAgger for the season's build model: the clone plays, the persona labels every state the clone reaches.

A clone that does not see the persona's pick (`noadvice`) agrees with it on 94% of rows and still ends a season on one
planet: its few misses compound into states the teacher's own games never visit. Each round here plays whole seasons
with the current model (sampled, as the judge plays), keeps a share of the rows with the persona's answer at that state
(`SeasonWorld.collect`'s last array), adds them to the pool and trains on all of it.

    python -m ogrl.dagger_world --init runs/train4/bc-noadvice.pt --rounds 4 --out runs/train4/dagger
"""

from __future__ import annotations

import argparse
import json
from pathlib import Path

import numpy as np
import torch

from ogrl.train_world import SEASON_ARMS, season_world

from .model import CandidateScorer

DATA = Path(__file__).resolve().parents[4] / "storage/rl/ogsim"


def gather(env, model, dims, rows: int, keep: float, rng) -> tuple:
    """Rows the model reached, each with the persona's answer there, until `rows` are kept."""
    env.set_policy(json.dumps({n: v.detach().cpu().tolist() for n, v in model.state_dict().items()}))
    s_dim, k, c_dim = dims
    parts = []
    kept = 0
    while kept < rows:
        state, cands, legal, *_, teacher = env.collect(8192, 0.985, 0.9, 1.0)
        n = len(teacher) // 8
        if n == 0:
            continue
        pick = rng.random(n) < keep
        part = (np.frombuffer(state, np.float32).reshape(n, s_dim)[pick], np.frombuffer(cands, np.float32).reshape(n, k, c_dim)[pick],
                np.frombuffer(legal, np.uint8).reshape(n, k)[pick].astype(bool), np.frombuffer(teacher, np.int64)[pick])
        parts.append(part)
        kept += int(pick.sum())
    return tuple(np.concatenate([p[i] for p in parts]) for i in range(4))


def fit(model, pool, epochs: int, batch: int, lr: float, smooth: float, device: str) -> float:
    """Trains on the whole pool; returns top-1 against the persona on the last tenth (held out)."""
    states, cands, masks, labels = (torch.as_tensor(x) for x in pool)
    cut = int(len(labels) * 0.9)
    opt = torch.optim.Adam(model.parameters(), lr=lr)
    for _ in range(epochs):
        order = torch.randperm(cut)
        for at in range(0, cut, batch):
            idx = order[at:at + batch]
            logits, _ = model(states[idx].to(device), cands[idx].to(device), masks[idx].to(device))
            loss = torch.nn.functional.cross_entropy(logits, labels[idx].to(device))
            if smooth > 0:
                legal = masks[idx].to(device)
                spread = -(torch.where(legal, logits.log_softmax(1), torch.zeros_like(logits)).sum(1) / legal.sum(1)).mean()
                loss = (1 - smooth) * loss + smooth * spread
            opt.zero_grad(set_to_none=True)
            loss.backward()
            opt.step()
    with torch.inference_mode():
        hits = 0
        for at in range(cut, len(labels), batch):
            logits, _ = model(states[at:at + batch].to(device), cands[at:at + batch].to(device), masks[at:at + batch].to(device))
            hits += int((logits.argmax(1).cpu() == labels[at:at + batch]).sum())
    return hits / max(len(labels) - cut, 1)


def main(argv: list[str] | None = None) -> int:
    ap = argparse.ArgumentParser(prog="ogrl.dagger_world", description=__doc__.splitlines()[0])
    ap.add_argument("--init", required=True, help="the clone to start from (train_bc_world --season)")
    ap.add_argument("--out", required=True, help="a directory: checkpoint-00000R.pt per round, and config.json")
    ap.add_argument("--rounds", type=int, default=4)
    ap.add_argument("--rows", type=int, default=600_000, help="rows kept per round")
    ap.add_argument("--keep", type=float, default=0.05, help="share of the rows played that is kept")
    ap.add_argument("--worlds", type=int, default=8)
    ap.add_argument("--epochs", type=int, default=4)
    ap.add_argument("--batch", type=int, default=4096)
    ap.add_argument("--lr", type=float, default=5e-4)
    ap.add_argument("--smooth", type=float, default=0.05)
    ap.add_argument("--seed", type=int, default=7)
    ap.add_argument("--device", default="cuda:1" if torch.cuda.device_count() > 1 else "cpu")
    ap.add_argument("--arms", nargs="*", default=list(SEASON_ARMS) + ["noadvice"])
    args = ap.parse_args(argv)
    args.season_data, args.catalogue = str(DATA / "r8w"), str(DATA / "r8w/units-parity/econ-u1-colonies.json")
    args.horizon, args.accounts, args.queue, args.detector, args.realism, args.stagger = 8760.0, 64, 3, None, 0.0, True
    args.controlled = args.accounts
    out = Path(args.out)
    out.mkdir(parents=True, exist_ok=True)
    (out / "config.json").write_text(json.dumps(vars(args), indent=2))

    saved = torch.load(args.init, map_location="cpu", weights_only=False)
    model = CandidateScorer(saved["state_dim"], saved["cand_dim"], saved["width"])
    model.load_state_dict(saved["model"])
    model.to(args.device)
    # Staggered seasons: the worlds start on different days, so one round sees every part of a year.
    env = season_world(args, args.seed, args.worlds)
    dims = env.dims()
    rng = np.random.default_rng(args.seed)
    pool = None
    for round_ in range(1, args.rounds + 1):
        fresh = gather(env, model.cpu(), dims, args.rows, args.keep, rng)
        model.to(args.device)
        pool = fresh if pool is None else tuple(np.concatenate([p, f]) for p, f in zip(pool, fresh))
        # The held-out tenth must be rows the model has not trained on: the newest round's go last.
        top1 = fit(model, pool, args.epochs, args.batch, args.lr, args.smooth, args.device)
        torch.save({**saved, "model": {k: v.detach().cpu() for k, v in model.state_dict().items()}, "iteration": round_},
                   out / f"checkpoint-{round_:06d}.pt")
        print(f"round {round_}: pool {len(pool[3]):,} rows, top-1 on the newest rows {top1:.1%}", flush=True)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
