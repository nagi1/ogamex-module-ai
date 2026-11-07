"""Behaviour cloning for the errand decision, before any PPO (plan/rl/errand-spec.md).

The teacher is the scripted players' own choice (`errands::teacher_choice`, handed out by `VecWorld::baseline`), so the
labels cost nothing: play worlds with the teacher deciding and record (state, candidates, mask, label). PPO then starts
from a policy that already plays like the scripted raider instead of from noise, which is the whole difference between
learning in a plateau and learning at all.

    python -m ogrl.train_bc_world --rows 300000 --out storage/rl/ogsim/runs/bc-errand.pt

The widths and column names come from the data (a live world's `dims()`/`feature_names()`, or the header of rows saved
by `--save-rows`); a header without names is today's errand names (`ogrl.errand_schema`). `--init` warm-starts from an
errand model (checkpoint or exported policy json) mapped by column name: columns the data adds start at weight 0, so the
first step scores every old candidate exactly as the old model did.

    python -m ogrl.train_bc_world --season --errands ... --init runs/train4/bc-errand.policy.json --save-rows rows.npz --out bc.pt
    python -m ogrl.train_bc_world --load-rows rows.npz --init bc.pt --out bc2.pt     # train again, no collection
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
from ogrl.train_world import SEASON_ARMS, season_world

from . import default_device
from .errand_schema import infer_version, load_errand, new_columns, resolve_names, widen_checkpoint
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
    if any(d[6] != done[0][6] for d in done):
        raise ValueError("collector feature schemas differ")
    cat = lambda i: np.concatenate([d[i] for d in done])
    return cat(0), cat(1), cat(2), cat(3), done[0][4], float(np.sqrt(np.mean([d[5] ** 2 for d in done]))), done[0][6]


def collect(args, n_rows: int):
    """Rows the teacher decided: its state, candidates, mask and its own answer."""
    if args.season:
        env = season_world(args, args.seed, args.worlds)
    else:
        gaps = json.loads(Path(args.login_gaps).read_text())
        controlled = [True] * args.controlled + [False] * (args.accounts - args.controlled)
        env = ogsim.VecWorld(args.catalogue, list(range(ogsim.VecEnv.planet_count(args.catalogue))), args.worlds, controlled,
                             horizon_hours=args.horizon, seed=args.seed, login_gaps=gaps,
                             units=args.units, fleet_settings=args.fleet_settings, combat=args.combat, errands=True)
    s_dim, k, c_dim = env.dims()
    names = dict(zip(("state", "candidate"), env.feature_names()))
    if args.errands or not args.season:
        names = resolve_names(names, s_dim, c_dim)
    states, cands, masks, labels = [], [], [], []
    steps, finished, squares, closed = 0, 0, 0.0, 0
    started = time.time()
    rng = np.random.default_rng(args.seed)
    # A season is cloned whole, sampled evenly: the first rows of a year are only its opening.
    while finished < args.seasons if args.season else sum(len(x) for x in labels) < n_rows:
        state, cand, legal = env.observe()
        state = np.frombuffer(state, np.float32).reshape(-1, s_dim)
        cand = np.frombuffer(cand, np.float32).reshape(-1, k, c_dim)
        legal = np.frombuffer(legal, np.uint8).reshape(-1, k)
        label = np.asarray(env.baseline(), dtype=np.int64)
        # Only the old worlds answer -2; a season's build pass is its last row, an errand's wait its first.
        live = legal.any(1) & (label >= 0) & (rng.random(len(label)) < args.keep)
        if live.any():
            states.append(state[live]), cands.append(cand[live]), masks.append(legal[live]), labels.append(label[live])
        rewards = [reward for _, reward, _ in env.step(label.tolist())]
        squares += float(np.square(rewards).sum())
        closed += len(rewards)
        if args.season:
            finished += len(env.take_finished()) // args.accounts
        steps += 1
    print(f"collected {sum(len(x) for x in labels):,} teacher rows in {steps} steps, {closed:,} rewards, {time.time() - started:.0f}s", flush=True)
    cat = lambda xs: np.concatenate(xs)
    return cat(states), cat(cands), cat(masks).astype(bool), cat(labels), (s_dim, k, c_dim), float(np.sqrt(squares / max(closed, 1))), names




def save_rows(path: str, states, cands, masks, labels, dims, reward_scale: float, names: dict, version: int | None, queue: int) -> None:
    """The collected rows with their header (the same field names as the errand label sidecar), so training can be rerun
    or swept on the GPU without collecting again."""
    header = {"errand_feature_version": version, "candidates": dims[1], "state": names.get("state", []),
              "candidate": names.get("candidate", []), "reward_scale": reward_scale, "queue": queue}
    Path(path).parent.mkdir(parents=True, exist_ok=True)
    np.savez(path, state=states, cands=cands, mask=masks, label=labels, header=np.asarray(json.dumps(header)))


def load_rows(path: str):
    """`save_rows`' file: rows, dims from the arrays, names from the header (today's errand names when it has none)."""
    z = np.load(path)
    header = json.loads(str(z["header"])) if "header" in z.files else {}
    states, cands, masks, labels = z["state"], z["cands"], z["mask"].astype(bool), z["label"].astype(np.int64)
    dims = (states.shape[1], cands.shape[1], cands.shape[2])
    queue = int(header.get("queue", 2))
    names = resolve_names(header, dims[0], dims[2]) if queue == 2 else {k: header.get(k, []) for k in ("state", "candidate")}
    version = infer_version(names, header.get("errand_feature_version")) if queue == 2 else None
    if header.get("candidates") not in (None, dims[1]):
        raise ValueError(f"{path}: header says {header['candidates']} candidates, the rows hold {dims[1]}")
    return states, cands, masks, labels, dims, float(header.get("reward_scale", 0.0)), names, version, queue


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
    ap.add_argument("--batch", type=int, default=0, help="0: 4096 on the CPU, 16384 on a GPU")
    ap.add_argument("--lr", type=float, help="default 1e-3 x sqrt(batch / 4096); `--batch 4096 --lr 1e-3` is the old run")
    ap.add_argument("--width", type=int, default=64, help="ignored with --init (the warm start keeps its width)")
    ap.add_argument("--worlds", type=int, default=64)
    ap.add_argument("--accounts", type=int, default=8)
    ap.add_argument("--controlled", type=int, default=4)
    ap.add_argument("--horizon", type=float, default=240.0)
    ap.add_argument("--seed", type=int, default=1)
    ap.add_argument("--procs", type=int, default=1, help="collector processes, the worlds split between them")
    ap.add_argument("--device", default=default_device(), help="cuda:1 (bus order) when there are two GPUs, else cuda:0, else cpu")
    ap.add_argument("--out", required=True)
    ap.add_argument("--season", action="store_true", help="W24: clone the persona's build in the season's own universe (ogsim.SeasonWorld); 64 accounts, 8760 h")
    ap.add_argument("--season-data", default=str(DATA / "r8w"))
    ap.add_argument("--arms", nargs="*", default=SEASON_ARMS)
    ap.add_argument("--seasons", type=int, default=1, help="whole seasons each collector plays")
    ap.add_argument("--smooth", type=float, default=0.0, help="share of the target spread over the legal rows, so PPO has something to explore")
    ap.add_argument("--keep", type=float, default=1.0, help="share of a season's decisions kept as rows")
    ap.add_argument("--errands", action="store_true", help="with --season: clone the scripted mix's fleet errand at every login instead of the build")
    ap.add_argument("--init", help="errand warm start (checkpoint or exported policy json), widened to the data's columns by name")
    ap.add_argument("--feature-version", type=int, help="errand feature version of live rows; default 2 for today's names, else 3")
    ap.add_argument("--save-rows", help="also write the collected rows and their header (npz)")
    ap.add_argument("--load-rows", help="train on rows `--save-rows` wrote instead of collecting")
    args = ap.parse_args(argv)
    if args.season:
        args.catalogue = str(DATA / "r8w/units-parity/econ-u1-colonies.json") if args.catalogue == str(DATA / "units-parity/econ-u1.json") else args.catalogue
        args.horizon = 8760.0 if args.horizon == 240.0 else args.horizon
        args.accounts = 64 if args.accounts == 8 else args.accounts
        args.controlled = args.accounts
        args.queue, args.detector, args.realism, args.stagger = 2 if args.errands else 3, None, 0.0, False
    if args.device.startswith("cuda") and not torch.cuda.is_available():
        print(f"{args.device} unavailable; training on the CPU", flush=True)
        args.device = "cpu"
    batch = args.batch or (16384 if args.device.startswith("cuda") else 4096)
    lr = args.lr or 1e-3 * (batch / 4096) ** 0.5

    if args.load_rows:
        states, cands, masks, labels, dims, reward_scale, names, version, queue = load_rows(args.load_rows)
        print(f"loaded {len(labels):,} rows from {args.load_rows}", flush=True)
    else:
        states, cands, masks, labels, dims, reward_scale, names = collect_parallel(args)
        queue = args.queue if args.season else 2
        version = infer_version(names, args.feature_version) if queue == 2 else None
        if args.save_rows:
            save_rows(args.save_rows, states, cands, masks, labels, dims, reward_scale, names, version, queue)
            print(f"wrote {args.save_rows}", flush=True)
    s_dim, k, c_dim = dims
    n = len(labels)
    cut = int(n * (1.0 - args.valid))
    split = slice(0, cut), slice(cut, n)
    # Normalisation from the training half, as train_world does from its calibration rollouts.
    flat = lambda std: np.where(std < 1e-2, 1.0, std)
    norm = (states[split[0]].mean(0), flat(states[split[0]].std(0)), cands[split[0]][masks[split[0]]].mean(0), flat(cands[split[0]][masks[split[0]]].std(0)))
    width, fresh = args.width, None
    if args.init:
        if queue != 2:
            raise SystemExit("--init widens errand models only")
        old = load_errand(args.init)
        wide = widen_checkpoint(old, names, version)
        width = old["width"]
        model = CandidateScorer(s_dim, c_dim, width)
        model.load_state_dict(wide["model"])
        # A new column has weight 0, so its normalisation changes no score: take the data's, so its first gradients are
        # on the scale of the old columns' rather than of its raw units.
        fresh = new_columns(old["names"], names)
        with torch.no_grad():
            for buf_mean, buf_std, cols, (mean, std) in (("state_mean", "state_std", fresh["state"], norm[0:2]), ("cand_mean", "cand_std", fresh["candidate"], norm[2:4])):
                if cols:
                    getattr(model, buf_mean)[cols] = torch.as_tensor(mean[cols], dtype=torch.float32)
                    getattr(model, buf_std)[cols] = torch.as_tensor(std[cols], dtype=torch.float32).clamp_min(1e-3)
        print(f"warm start from {args.init} (feature version {old['errand_feature_version']}, width {width}): "
              f"{len(fresh['state'])} new state and {len(fresh['candidate'])} new candidate columns at weight 0", flush=True)
        model = model.to(args.device)
    else:
        model = CandidateScorer(s_dim, c_dim, width).to(args.device)
        model.set_normalisation(*(torch.as_tensor(x, dtype=torch.float32, device=args.device) for x in norm))
    opt = torch.optim.Adam(model.parameters(), lr=lr)
    # The whole training half on the device once when it fits in half the free memory: no host copy per batch.
    on_device = args.device.startswith("cuda") and (states.nbytes + cands.nbytes + masks.nbytes + labels.nbytes) < torch.cuda.mem_get_info(args.device)[0] // 2
    home = args.device if on_device else "cpu"
    tr = (torch.as_tensor(states[split[0]], device=home), torch.as_tensor(cands[split[0]], device=home),
          torch.as_tensor(masks[split[0]], device=home), torch.as_tensor(labels[split[0]], device=home))
    va = (torch.as_tensor(states[split[1]], device=args.device), torch.as_tensor(cands[split[1]], device=args.device),
          torch.as_tensor(masks[split[1]], device=args.device), torch.as_tensor(labels[split[1]], device=args.device))

    def judge() -> tuple[float, float]:
        """Held-out loss and top-1 agreement with the teacher, in chunks of the batch."""
        loss, hits = 0.0, 0
        with torch.inference_mode():
            for at in range(0, len(va[3]), batch):
                logits, _ = model(va[0][at:at + batch], va[1][at:at + batch], va[2][at:at + batch])
                loss += torch.nn.functional.cross_entropy(logits, va[3][at:at + batch], reduction="sum").item()
                hits += (logits.argmax(1) == va[3][at:at + batch]).sum().item()
        return loss / max(len(va[3]), 1), hits / max(len(va[3]), 1)

    if args.init:
        held, top1 = judge()
        print(f"before training: held-out loss {held:.4f}, top-1 {top1:.1%}", flush=True)
    print(f"training on {args.device} ({'data on the device' if on_device else 'batches copied from the host'}), batch {batch}, lr {lr:.2g}", flush=True)
    started = time.time()
    top1 = float("nan")
    for epoch in range(args.epochs):
        order = torch.randperm(len(tr[3]))
        if on_device:
            order = order.to(args.device)
        total = 0.0
        for at in range(0, len(order), batch):
            idx = order[at:at + batch]
            logits, _ = model(tr[0][idx].to(args.device), tr[1][idx].to(args.device), tr[2][idx].to(args.device))
            loss = torch.nn.functional.cross_entropy(logits, tr[3][idx].to(args.device))
            if args.smooth > 0:
                # A clone certain of every row would never try anything else under PPO: part of its mass stays on the legal rows.
                legal = tr[2][idx].to(args.device)
                spread = -(torch.where(legal, logits.log_softmax(1), torch.zeros_like(logits)).sum(1) / legal.sum(1)).mean()
                loss = (1 - args.smooth) * loss + args.smooth * spread
            opt.zero_grad(set_to_none=True)
            loss.backward()
            opt.step()
            total += loss.item() * len(idx)
        held, top1 = judge()
        print(f"epoch {epoch + 1}: train loss {total / len(order):.4f}, held-out loss {held:.4f}, top-1 {top1:.1%}", flush=True)

    out = Path(args.out)
    out.parent.mkdir(parents=True, exist_ok=True)
    extra = {"errand_feature_version": version, "candidates": k} if queue == 2 else {}
    torch.save({"model": {k: v.detach().cpu() for k, v in model.state_dict().items()}, "state_dim": s_dim, "cand_dim": c_dim,
                "width": width, "iteration": 0, "names": names, "queue": queue, "reward_scale": reward_scale, **extra}, out)
    print(f"wrote {out} ({time.time() - started:.0f}s of training); top-1 against the teacher {top1:.1%}")
    print(f"rows {n:,}, candidates {k}, state {s_dim}, candidate {c_dim}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
