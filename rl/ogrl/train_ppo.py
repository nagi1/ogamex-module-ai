"""PPO with invalid-action masking on the economy simulator (rl/ogsim): the building policy of plan/rl phase 1.

    python -m ogrl.train_ppo --catalogue storage/rl/ogsim/train.json [more.json ...] --eval storage/rl/ogsim/eval.json [...] \
        --out storage/rl/ogsim/runs/s1 --seed 1 --steps 3000000

One decision is "the next building the free queue commits to"; the reward is what the planet produced (plan/rl/
reward-design.md, metal-equivalent). The network is the same CandidateScorer behaviour cloning uses: it scores every
legal next level from its numbers only, so a modded object is scored with no code change. Checkpoints are not chosen
by the evaluation set: the result is the last model, the evaluation curve is only watched.
"""

from __future__ import annotations

import os

os.environ.setdefault("CUDA_DEVICE_ORDER", "PCI_BUS_ID")  # torch would otherwise number the 4070 before the 3090

import argparse
import csv
import json
import time
from pathlib import Path

import numpy as np
import torch
from torch.distributions import Categorical

import ogsim

from .econ import BONUS_HOURS, HORIZON_HOURS, observe, play
from .model import CandidateScorer, parameter_count


class RunningStd:
    """Running variance of the discounted return, used to scale rewards to about unit size (gym's NormalizeReward)."""

    def __init__(self, n: int, gamma: float):
        self.ret = np.zeros(n)
        self.gamma = gamma
        self.count, self.mean, self.var = 1e-4, 0.0, 1.0

    def scale(self, rewards: np.ndarray, dones: np.ndarray) -> np.ndarray:
        self.ret = self.ret * self.gamma + rewards
        batch_mean, batch_var, n = self.ret.mean(), self.ret.var(), len(self.ret)
        delta, total = batch_mean - self.mean, self.count + n
        self.mean += delta * n / total
        self.var = (self.var * self.count + batch_var * n + delta**2 * self.count * n / total) / total
        self.count = total
        self.ret[dones] = 0.0
        return rewards / np.sqrt(self.var + 1e-8)


class MultiEnv:
    """One ogsim.VecEnv per catalogue (a catalogue holds one economy speed), games split evenly, rows concatenated.

    Finished games come back as (gain over that catalogue's own player baseline, invalid actions).
    """

    def __init__(self, catalogues: list[str], envs: int, seed: int):
        ids = [[o["id"] for o in json.loads(Path(c).read_text())["objects"]] for c in catalogues]
        assert all(i == ids[0] for i in ids), "catalogues must list the same objects in the same order"
        sizes = [envs // len(catalogues) + (i < envs % len(catalogues)) for i in range(len(catalogues))]
        self.envs = [
            ogsim.VecEnv(c, list(range(ogsim.VecEnv.planet_count(c))), n, horizon_hours=HORIZON_HOURS, bonus_hours=BONUS_HOURS, seed=seed + i)
            for i, (c, n) in enumerate(zip(catalogues, sizes))
        ]
        self.sizes = sizes
        self.player = [baseline_values(c, "player") for c in catalogues]
        dims = {e.dims() for e in self.envs}
        assert len(dims) == 1, f"catalogues disagree on observation sizes: {dims}"

    def dims(self):
        return self.envs[0].dims()

    def observe(self):
        parts = [e.observe() for e in self.envs]
        return tuple(b"".join(p[i] for p in parts) for i in range(3))

    def baseline(self, name: str) -> list[int]:
        return [a for e in self.envs for a in e.baseline(name)]

    def step(self, actions):
        actions, parts, start = list(actions), [], 0
        for e, n in zip(self.envs, self.sizes):
            parts.append(e.step(actions[start:start + n]))
            start += n
        return b"".join(p[0] for p in parts), b"".join(p[1] for p in parts)

    def take_finished(self) -> list[tuple[float, int]]:
        return [(f[1] / player[f[0]] - 1.0, f[4]) for e, player in zip(self.envs, self.player) for f in e.take_finished()]


def baseline_values(catalogue: str, name: str) -> np.ndarray:
    planets = list(range(ogsim.VecEnv.planet_count(catalogue)))
    return np.array([r[0] for _, r in sorted(play(catalogue, planets, lambda env: env.baseline(name), seed=1)[1].items())])


def normalisation(env: "ogsim.VecEnv", steps: int) -> tuple[np.ndarray, ...]:
    """Feature mean and spread from states the player baseline and random legal play visit."""
    states, cands = [], []
    for i in range(steps):
        s, c, m = observe(env)
        states.append(s.copy())
        cands.append(c[m].copy())
        env.step(env.baseline("player" if i % 2 else "random"))
    states, cands = np.concatenate(states), np.concatenate(cands)
    # A feature constant in training (the economy speed was) must not explode on an unseen value.
    flat = lambda std: np.where(std < 1e-2, 1.0, std)
    return states.mean(0), flat(states.std(0)), cands.mean(0), flat(cands.std(0))


def evaluate(model: CandidateScorer, catalogue: str, device: str, reference: np.ndarray) -> float:
    model.eval()

    def policy(env):
        s, c, m = observe(env)
        with torch.no_grad():
            logits, _ = model(torch.from_numpy(s.copy()).to(device), torch.from_numpy(c.copy()).to(device), torch.from_numpy(m.copy()).to(device))
        return [int(a) if m[i].any() else -1 for i, a in enumerate(logits.argmax(1).cpu().numpy())]

    planets = list(range(ogsim.VecEnv.planet_count(catalogue)))
    values = np.array([r[0] for _, r in sorted(play(catalogue, planets, policy)[1].items())])
    model.train()
    return float((values / reference - 1.0).mean())


def main(argv: list[str] | None = None) -> None:
    ap = argparse.ArgumentParser(prog="ogrl.train_ppo")
    ap.add_argument("--catalogue", nargs="+", required=True)
    ap.add_argument("--eval", nargs="+", required=True)
    ap.add_argument("--out", required=True)
    ap.add_argument("--seed", type=int, default=1)
    ap.add_argument("--steps", type=int, default=3_000_000)
    ap.add_argument("--envs", type=int, default=256)
    ap.add_argument("--rollout", type=int, default=64)
    ap.add_argument("--width", type=int, default=64)
    ap.add_argument("--lr", type=float, default=3e-4)
    ap.add_argument("--epochs", type=int, default=4)
    ap.add_argument("--minibatch", type=int, default=4096)
    ap.add_argument("--gamma", type=float, default=0.997)
    ap.add_argument("--lam", type=float, default=0.95)
    ap.add_argument("--clip", type=float, default=0.2)
    ap.add_argument("--ent", type=float, default=0.01)
    ap.add_argument("--vf", type=float, default=0.5)
    ap.add_argument("--eval-every", type=int, default=20)
    ap.add_argument("--device", default="cuda:1" if torch.cuda.device_count() > 1 else ("cuda:0" if torch.cuda.is_available() else "cpu"))
    args = ap.parse_args(argv)

    out = Path(args.out)
    out.mkdir(parents=True, exist_ok=True)
    (out / "config.json").write_text(json.dumps(vars(args) | {"horizon_hours": HORIZON_HOURS, "bonus_hours": BONUS_HOURS}, indent=2))
    torch.manual_seed(args.seed)
    np.random.seed(args.seed)
    device = args.device

    env = MultiEnv(args.catalogue, args.envs, args.seed)
    s_dim, k, c_dim = env.dims()
    eval_player = [baseline_values(c, "player") for c in args.eval]

    model = CandidateScorer(s_dim, c_dim, args.width).to(device)
    model.set_normalisation(*(torch.as_tensor(x, dtype=torch.float32) for x in normalisation(env, 200)))
    env.take_finished()
    optimizer = torch.optim.Adam(model.parameters(), lr=args.lr, eps=1e-5)
    print(f"ogsim PPO: {parameter_count(model):,} parameters, {len(args.catalogue)} training catalogue(s), {args.envs} games, device {device}")

    T, N = args.rollout, args.envs
    S = torch.zeros(T, N, s_dim, device=device)
    C = torch.zeros(T, N, k, c_dim, device=device)
    M = torch.zeros(T, N, k, dtype=torch.bool, device=device)
    A = torch.zeros(T, N, dtype=torch.long, device=device)
    LP = torch.zeros(T, N, device=device)
    V = torch.zeros(T, N, device=device)
    R = torch.zeros(T, N, device=device)
    D = torch.zeros(T, N, device=device)
    scaler = RunningStd(N, args.gamma)
    iterations = args.steps // (T * N)
    curve = csv.writer(open(out / "curve.csv", "w", newline=""))
    curve.writerow(["iteration", "steps", "seconds", "episodes", "train_vs_player", "eval_vs_player", *[f"eval_{Path(c).stem}" for c in args.eval], "entropy", "approx_kl", "clip_frac", "value_loss", "explained_var", "invalid", "env_share"])
    started = time.time()

    def tensors():
        s, c, m = observe(env)
        return torch.from_numpy(s.copy()).to(device), torch.from_numpy(c.copy()).to(device), torch.from_numpy(m.copy()).to(device)

    s, c, m = tensors()
    for it in range(1, iterations + 1):
        for g in optimizer.param_groups:
            g["lr"] = args.lr * (1.0 - (it - 1) / iterations)
        env_seconds, iteration_started = 0.0, time.time()
        for t in range(T):
            with torch.no_grad():
                logits, value = model(s, c, m)
                dist = Categorical(logits=logits)
                action = dist.sample()
            S[t], C[t], M[t], A[t], LP[t], V[t] = s, c, m, action, dist.log_prob(action), value
            clock = time.time()
            reward, done = env.step(action.cpu().tolist())
            reward = np.frombuffer(reward, dtype=np.float32).astype(np.float64)
            done = np.frombuffer(done, dtype=np.uint8).astype(bool)
            s, c, m = tensors()
            env_seconds += time.time() - clock
            R[t] = torch.as_tensor(scaler.scale(reward, done), dtype=torch.float32, device=device)
            D[t] = torch.as_tensor(done, dtype=torch.float32, device=device)

        # Generalised advantage estimation; a finished game's next state belongs to a fresh game, so it is cut.
        with torch.no_grad():
            _, next_value = model(s, c, m)
            adv = torch.zeros_like(R)
            last = torch.zeros(N, device=device)
            for t in reversed(range(T)):
                following = next_value if t == T - 1 else V[t + 1]
                nonterminal = 1.0 - D[t]
                delta = R[t] + args.gamma * following * nonterminal - V[t]
                last = delta + args.gamma * args.lam * nonterminal * last
                adv[t] = last
            returns = adv + V

        flat = lambda x: x.reshape(T * N, *x.shape[2:])
        bs, bc, bm, ba, blp, badv, bret, bv = map(flat, (S, C, M, A, LP, adv, returns, V))
        kls, clips, vlosses, ents = [], [], [], []
        for _ in range(args.epochs):
            for idx in torch.randperm(T * N, device=device).split(args.minibatch):
                logits, value = model(bs[idx], bc[idx], bm[idx])
                dist = Categorical(logits=logits)
                logp = dist.log_prob(ba[idx])
                ratio = (logp - blp[idx]).exp()
                a = badv[idx]
                a = (a - a.mean()) / (a.std() + 1e-8)
                policy_loss = torch.max(-a * ratio, -a * ratio.clamp(1 - args.clip, 1 + args.clip)).mean()
                value_loss = 0.5 * (value - bret[idx]).pow(2).mean()
                # Entropy over the legal rows only: a masked row has probability zero and adds nothing.
                p = dist.probs
                entropy = -(p * torch.where(bm[idx], p.clamp_min(1e-12).log(), torch.zeros_like(p))).sum(1).mean()
                loss = policy_loss + args.vf * value_loss - args.ent * entropy
                optimizer.zero_grad()
                loss.backward()
                torch.nn.utils.clip_grad_norm_(model.parameters(), 0.5)
                optimizer.step()
                with torch.no_grad():
                    kls.append(((ratio - 1) - (logp - blp[idx])).mean().item())
                    clips.append(((ratio - 1).abs() > args.clip).float().mean().item())
                    vlosses.append(value_loss.item())
                    ents.append(entropy.item())

        finished = env.take_finished()
        train_rel = float(np.mean([f[0] for f in finished])) if finished else float("nan")
        invalid = sum(f[1] for f in finished)
        explained = float(1 - (bret - bv).var() / (bret.var() + 1e-8))
        due = it % args.eval_every == 0 or it == iterations
        eval_each = [evaluate(model, c, device, ref) if due else float("nan") for c, ref in zip(args.eval, eval_player)]
        eval_rel = float(np.mean(eval_each))
        seconds = time.time() - started
        curve.writerow([it, it * T * N, round(seconds, 1), len(finished), train_rel, eval_rel, *eval_each, np.mean(ents), np.mean(kls), np.mean(clips), np.mean(vlosses), explained, invalid, round(env_seconds / (time.time() - iteration_started), 3)])
        if it % 5 == 0 or it == iterations or not np.isnan(eval_rel):
            print(f"it {it:>4}/{iterations} steps {it * T * N:>9,} {seconds:>6.0f}s  train vs player {train_rel:+.2%}  eval {eval_rel:+.2%} ({", ".join(f"{e:+.1%}" for e in eval_each)})  entropy {np.mean(ents):.3f}  kl {np.mean(kls):.4f}  clip {np.mean(clips):.2f}  ev {explained:.2f}  invalid {invalid}", flush=True)
        if it % args.eval_every == 0 or it == iterations:
            torch.save({"model": model.state_dict(), "state_dim": s_dim, "cand_dim": c_dim, "width": args.width, "iteration": it}, out / f"checkpoint-{it:05d}.pt")

    torch.save({"model": model.state_dict(), "state_dim": s_dim, "cand_dim": c_dim, "width": args.width, "iteration": iterations}, out / "model.pt")
    print(f"done in {time.time() - started:.0f}s -> {out / 'model.pt'}")


if __name__ == "__main__":
    main()
