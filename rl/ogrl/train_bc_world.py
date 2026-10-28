"""Behaviour cloning for the errand decision, before any PPO (plan/rl/errand-spec.md).

The teacher is the scripted players' own choice (`errands::teacher_choice`, handed out by `VecWorld::baseline`), so the
labels cost nothing: play worlds with the teacher deciding and record (state, candidates, mask, label). PPO then starts
from a policy that already plays like the scripted raider instead of from noise, which is the whole difference between
learning in a plateau and learning at all.

    python -m ogrl.train_bc_world --rows 300000 --out storage/rl/ogsim/runs/bc-errand.pt
"""

from __future__ import annotations

import argparse
import json
import time
from concurrent.futures import ProcessPoolExecutor
from pathlib import Path

import numpy as np
import torch

import ogsim

from .model import CandidateScorer
from .econ import bootstrap_ci

DATA = Path(__file__).resolve().parents[4] / "storage/rl/ogsim"


def collect_part(job):
    args, n_rows = job
    torch.set_num_threads(1)
    return collect(args, n_rows)


def collect_parallel(args):
    """The worlds split over processes, each with its own seed; the last part's worlds are the held-out tail."""
    if args.procs <= 1:
        return collect(args, args.rows)
    parts = [(argparse.Namespace(**{**vars(args), "seed": args.seed * 1000 + i, "worlds": max(1, args.worlds // args.procs)}),
              -(-args.rows // args.procs)) for i in range(args.procs)]
    with ProcessPoolExecutor(max_workers=args.procs) as pool:
        done = list(pool.map(collect_part, parts))
    cat = lambda i: np.concatenate([d[i] for d in done])
    return cat(0), cat(1), cat(2), cat(3), done[0][4]


def collect(args, n_rows: int):
    """Rows the teacher decided: its state, candidates, mask and its own answer."""
    gaps = json.loads(Path(args.login_gaps).read_text())
    controlled = [True] * args.controlled + [False] * (args.accounts - args.controlled)
    env = ogsim.VecWorld(args.catalogue, list(range(ogsim.VecEnv.planet_count(args.catalogue))), args.worlds, controlled,
                         horizon_hours=args.horizon, seed=args.seed, login_gaps=gaps,
                         units=args.units, fleet_settings=args.fleet_settings, combat=args.combat, errands=not args.season,
                         season=args.season, queue=0)
    s_dim, k, c_dim = env.dims()
    states, cands, masks, labels = [], [], [], []
    steps = 0
    started = time.time()
    while sum(len(x) for x in labels) < n_rows:
        state, cand, legal = env.observe()
        state = np.frombuffer(state, np.float32).reshape(-1, s_dim)
        cand = np.frombuffer(cand, np.float32).reshape(-1, k, c_dim)
        legal = np.frombuffer(legal, np.uint8).reshape(-1, k)
        label = np.asarray(env.baseline(), dtype=np.int64)
        # A build decision the persona lets pass (-2) has no row to clone.
        live = legal.any(1) & (label >= 0)
        states.append(state[live]), cands.append(cand[live]), masks.append(legal[live]), labels.append(label[live])
        env.step(label.tolist())
        steps += 1
    print(f"collected {sum(len(x) for x in labels):,} teacher rows in {steps} steps, {time.time() - started:.0f}s", flush=True)
    cat = lambda xs: np.concatenate(xs)
    return cat(states), cat(cands), cat(masks).astype(bool), cat(labels), (s_dim, k, c_dim)


def main(argv: list[str] | None = None) -> int:
    ap = argparse.ArgumentParser(prog="ogrl.train_bc_world", description=__doc__.splitlines()[0])
    ap.add_argument("--catalogue", default=str(DATA / "units-parity/econ-u1.json"))
    ap.add_argument("--units", default=str(DATA / "units-parity/units-u1.json"))
    ap.add_argument("--fleet-settings", default=str(DATA / "fleet-settings-u1.json"))
    ap.add_argument("--combat", default=str(DATA / "combat-u1.json"))
    ap.add_argument("--login-gaps", default=str(DATA / "login-gaps.json"))
    ap.add_argument("--rows", type=int, default=300_000)
    ap.add_argument("--valid", type=float, default=0.1, help="share of rows held out")
    ap.add_argument("--epochs", type=int, default=8)
    ap.add_argument("--batch", type=int, default=4096)
    ap.add_argument("--lr", type=float, default=1e-3)
    ap.add_argument("--width", type=int, default=64)
    ap.add_argument("--worlds", type=int, default=64)
    ap.add_argument("--accounts", type=int, default=8)
    ap.add_argument("--controlled", type=int, default=4)
    ap.add_argument("--horizon", type=float, default=240.0)
    ap.add_argument("--seed", type=int, default=1)
    ap.add_argument("--procs", type=int, default=1, help="collector processes, the worlds split between them")
    ap.add_argument("--device", default="cuda:1" if torch.cuda.device_count() > 1 else ("cuda:0" if torch.cuda.is_available() else "cpu"))
    ap.add_argument("--out", required=True)
    ap.add_argument("--season", action="store_true", help="row 34: clone the persona's build (field reserve and all) in the season world; colonies catalogue, 8760 h")
    args = ap.parse_args(argv)
    if args.season:
        args.catalogue = str(DATA / "units-parity/econ-u1-colonies.json") if args.catalogue == str(DATA / "units-parity/econ-u1.json") else args.catalogue
        args.horizon = 8760.0 if args.horizon == 240.0 else args.horizon

    states, cands, masks, labels, dims = collect_parallel(args)
    s_dim, k, c_dim = dims
    n = len(labels)
    cut = int(n * (1.0 - args.valid))
    split = slice(0, cut), slice(cut, n)
    model = CandidateScorer(s_dim, c_dim, args.width).to(args.device)
    # Normalisation from the training half, as train_world does from its calibration rollouts.
    flat = lambda std: np.where(std < 1e-2, 1.0, std)
    model.set_normalisation(
        torch.as_tensor(states[split[0]].mean(0), dtype=torch.float32, device=args.device),
        torch.as_tensor(flat(states[split[0]].std(0)), dtype=torch.float32, device=args.device),
        torch.as_tensor(cands[split[0]][masks[split[0]]].mean(0), dtype=torch.float32, device=args.device),
        torch.as_tensor(flat(cands[split[0]][masks[split[0]]].std(0)), dtype=torch.float32, device=args.device),
    )
    opt = torch.optim.Adam(model.parameters(), lr=args.lr)
    tr = (torch.as_tensor(states[split[0]]), torch.as_tensor(cands[split[0]]), torch.as_tensor(masks[split[0]]), torch.as_tensor(labels[split[0]]))
    va = (torch.as_tensor(states[split[1]], device=args.device), torch.as_tensor(cands[split[1]], device=args.device),
          torch.as_tensor(masks[split[1]], device=args.device), torch.as_tensor(labels[split[1]], device=args.device))
    started = time.time()
    for epoch in range(args.epochs):
        order = torch.randperm(len(tr[3]))
        total = 0.0
        for at in range(0, len(order), args.batch):
            idx = order[at:at + args.batch]
            logits, _ = model(tr[0][idx].to(args.device), tr[1][idx].to(args.device), tr[2][idx].to(args.device))
            loss = torch.nn.functional.cross_entropy(logits, tr[3][idx].to(args.device))
            opt.zero_grad(set_to_none=True)
            loss.backward()
            opt.step()
            total += loss.item() * len(idx)
        with torch.inference_mode():
            logits, _ = model(*va[:3])
            top1 = (logits.argmax(1) == va[3]).float().mean().item()
            held = torch.nn.functional.cross_entropy(logits, va[3]).item()
        print(f"epoch {epoch + 1}: train loss {total / len(order):.4f}, held-out loss {held:.4f}, top-1 {top1:.1%}", flush=True)

    out = Path(args.out)
    out.parent.mkdir(parents=True, exist_ok=True)
    torch.save({"model": {k: v.detach().cpu() for k, v in model.state_dict().items()}, "state_dim": s_dim, "cand_dim": c_dim,
                "width": args.width, "iteration": 0, "names": {"state": [], "candidate": []}, "queue": 0 if args.season else 2}, out)
    print(f"wrote {out} ({time.time() - started:.0f}s of training); top-1 against the teacher {top1:.1%}")
    print(f"rows {n:,}, candidates {k}, state {s_dim}, candidate {c_dim}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
