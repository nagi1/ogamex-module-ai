"""Distributed PPO on the world simulator (rl/ogsim VecWorld): many actor processes, one GPU learner, one evaluator.

    python -m ogrl.train_world --queue 0 --out storage/rl/ogsim/runs/w1 --minutes 15
    python -m ogrl.train_world --smoke --out /tmp/w-smoke            # two minutes, 24 h games, a small evaluation

Layout (one machine; the owner lifted the CPU budgets):

    N actor processes (default 48), each owning one VecWorld (256 worlds x 12 accounts, the first `--controlled` slots
    decide). An actor acts with a CPU copy of the CandidateScorer (one torch thread), turns closed transitions into
    advantages itself and ships the training rows to the learner through preallocated shared-memory slots (no pickling
    of arrays; only a slot index travels through a queue). An empty free-slot ring blocks the actors: that is the
    back-pressure that bounds the policy lag when the learner is the slower side.
    One learner (the main process, cuda:0) runs PPO on the rows (masked softmax, entropy over legal rows, the loss of
    train_ppo.py) and publishes its weights into shared memory every `--publish-every` updates; actors pick them up at
    their next step.
    One evaluator process (cuda:1) plays the newest checkpoint greedily on fixed seeds against the player rule on
    identical worlds (held-out planets) and reports the paired value difference with a bootstrap CI over worlds.

Ticket-matched rewards (how it is handled)

    VecWorld holds one pending decision per world; the reward of a decision (value change of that account until it
    decides again, plus the end-of-horizon bonus) comes back from `step` by ticket, when the *next* decision of the same
    account is pending, or when the world ends (done = 1). The actor keeps a record per decision, closes it when its
    ticket returns and links it to the same account's next record: a *stream* is one account in one world incarnation.
    Discounting is per own decision of the account (gamma^1 per decision, not per second: an account decides at each
    login, so gamma 0.997 looks about 330 own decisions ahead), and GAE runs along the stream. Every `--flush-steps`
    environment steps the closed prefix of each stream becomes advantages and is shipped; the last shipped record of an
    unfinished stream bootstraps with the value predicted for its successor decision (observed at that moment), a
    finished stream with 0, and the open tail waits for the next flush. Advantages use the value head of the policy that
    acted (actor-side GAE as in IMPALA-style async PPO); the PPO clip absorbs the small lag (logged as `lag`, in updates).
    The queue the model does not decide (the player rule plays it) and decisions with no legal row (answer -2) stay in
    the stream, since the reward chain runs through them, but are not trained on. Waiting by choice is not a candidate
    row yet; a learned wait would add one logit.
    Rewards are divided by one scale fixed before the run (RMS of the rule's rewards over whole games), so value targets
    have unit size without a drifting scale.

Rollout record: state, candidates, legal mask, action, behaviour log-probability, advantage, return and `kind` (the
decision's queue: 0 building, 1 research; the id a later fleet or yard kind takes). The learner counts rows per kind.

train_ppo.py keeps its loss and GAE inline in main(), so they cannot be imported: `gae` and `ppo_update` below are the
same maths (per-minibatch advantage normalisation, clipped ratio, 0.5 MSE value, entropy over legal rows, grad clip 0.5).
"""

from __future__ import annotations

import os

os.environ.setdefault("CUDA_DEVICE_ORDER", "PCI_BUS_ID")  # cuda:0 is the 3090, cuda:1 the 4070
for _v in ("OMP_NUM_THREADS", "MKL_NUM_THREADS", "OPENBLAS_NUM_THREADS"):
    os.environ.setdefault(_v, "1")  # every actor is single threaded; the learner raises torch's own count

import argparse
import contextlib
import copy
import json
import queue as queue_module
import threading
import time
from pathlib import Path

import numpy as np
import torch
import torch.multiprocessing as mp
from torch.distributions import Categorical

import ogsim

from .econ import bootstrap_ci
from .errand_schema import load_errand, resolve_names, widen_checkpoint
from .model import CandidateScorer, parameter_count

DATA = Path(__file__).resolve().parents[4] / "storage/rl/ogsim"
KIND_NAMES = {0: "building", 1: "research", 2: "errand", 3: "building+research"}
# Columns of the shared per-actor statistics table.
ST = {n: i for i, n in enumerate(("decisions", "shipped", "finished", "sim_hours", "wait_s", "env_s", "model_s", "book_s", "loop_s", "ctl_sum", "ctl_n", "rule_sum", "rule_n", "reward_abs"))}


class Slots:
    """A ring of rollout slots in shared memory: field tensors [slots, rows, ...] plus the row count of each slot."""

    def __init__(self, slots: int, rows: int, s_dim: int, k: int, c_dim: int):
        make = lambda *shape, dtype=torch.float32: torch.zeros(slots, rows, *shape, dtype=dtype).share_memory_()
        self.state, self.cands = make(s_dim), make(k, c_dim)
        self.mask, self.action, self.logp = make(k, dtype=torch.uint8), make(dtype=torch.int64), make()
        self.adv, self.ret, self.kind = make(), make(), make(dtype=torch.int8)
        self.count = torch.zeros(slots, dtype=torch.int64).share_memory_()
        self.version = torch.zeros(slots, dtype=torch.int64).share_memory_()  # policy version that acted
        self.rows = rows


# W24: the season's arms as the flow's last kept row plays them (runs/flow/e99h/run.sh), so training sees the world hds.py judges.
SEASON_ARMS = ("moonstack", "reach", "expedition", "colonies", "ships", "cap", "lab", "classes", "feed", "factory", "balance",
               "satellites", "doubler", "moonfeed", "standing", "labfirst", "moontaste", "netchain", "merchant", "techtree", "doctrine",
               "acsdefend", "acsattack", "wrecks", "moonraid", "relocate", "keyed", "class=3")


def ensure_fresh_sim() -> None:
    """Refuses a Python `ogsim` older than the sim's sources: `SeasonWorld` ignores an arm it does not know, so a stale build
    plays another world without a word (5 Nov: Stage C collected on the 4 Nov build, without `late` and `satcap`)."""
    built = Path(ogsim.__file__).parent.glob("ogsim*.so")
    built_at = max((f.stat().st_mtime for f in built), default=0.0)
    sources = Path(__file__).resolve().parents[1] / "ogsim" / "src"
    newest = max((f.stat().st_mtime for f in sources.rglob("*.rs")), default=0.0)
    if newest > built_at:
        raise SystemExit(f"ogsim is older than {sources}: rebuild it (cd rl/ogsim && maturin develop --release)")


def season_world(args, seed: int, n_worlds: int) -> "ogsim.SeasonWorld":
    ensure_fresh_sim()
    return ogsim.SeasonWorld(args.season_data, args.catalogue, n_worlds, list(args.arms), controlled=args.controlled,
                             accounts=args.accounts, days=int(args.horizon // 24), seed=seed, queue=args.queue,
                             detector=args.detector, stagger=args.stagger, **{"lambda": args.realism})


def load_world(args, seed: int, n_worlds: int, catalogue: str | None = None) -> "ogsim.VecWorld | ogsim.SeasonWorld":
    if args.season:
        return season_world(args, seed, n_worlds)
    cat = catalogue or args.catalogue
    gaps = json.loads(Path(args.login_gaps).read_text())
    controlled = [True] * args.controlled + [False] * (args.accounts - args.controlled)
    return ogsim.VecWorld(cat, list(range(ogsim.VecEnv.planet_count(cat))), n_worlds, controlled,
                          horizon_hours=args.horizon, seed=seed, login_gaps=gaps,
                          units=args.units, fleet_settings=args.fleet_settings, combat=args.combat,
                          errands=args.queue == 2)


def observe(env: "ogsim.VecWorld", dims: tuple[int, int, int]) -> tuple[np.ndarray, np.ndarray, np.ndarray]:
    s_dim, k, c_dim = dims
    state, cands, legal = env.observe()
    state = np.frombuffer(state, dtype=np.float32).reshape(-1, s_dim)
    return state, np.frombuffer(cands, dtype=np.float32).reshape(len(state), k, c_dim), np.frombuffer(legal, dtype=np.uint8).reshape(len(state), k)


def gae(rewards: np.ndarray, values: np.ndarray, bootstrap: float, gamma: float, lam: float) -> np.ndarray:
    """GAE along one stream of consecutive own decisions; `bootstrap` is V of the decision after the last row (0 when finished)."""
    adv, last, following = np.empty(len(rewards)), 0.0, bootstrap
    for t in range(len(rewards) - 1, -1, -1):
        delta = rewards[t] + gamma * following - values[t]
        last = delta + gamma * lam * last
        adv[t], following = last, values[t]
    return adv


def calibrate(args, dims) -> tuple[tuple[np.ndarray, ...], float]:
    """Feature mean/spread from states the player rule and random legal play visit, and the reward scale (RMS over whole games)."""
    env = load_world(args, args.seed + 7, args.calibrate_worlds)
    rng = np.random.default_rng(args.seed)
    states, cands, rewards, finished_games, step = [], [], [], 0, 0
    while finished_games < args.calibrate_worlds and step < 30000:
        d = env.decisions()
        s, c, m = observe(env, dims)
        keep = np.array([q == args.queue for _, q, _ in d]) & m.any(1)
        states.append(s[keep].copy())
        cands.append(c[keep][m[keep].astype(bool)])
        acts = env.baseline() if step % 2 else [int(rng.choice(np.flatnonzero(row))) if row.any() else -2 for row in m]
        rewards.extend(reward for _, reward, _ in env.step(acts))
        finished_games += len(env.take_finished()) // args.accounts
        step += 1
    states, cands = np.concatenate(states), np.concatenate(cands)
    flat = lambda std: np.where(std < 1e-2, 1.0, std)
    scale = float(np.sqrt(np.mean(np.square(np.asarray(rewards, dtype=np.float64)))) + 1e-8)
    print(f"calibration: {step} steps, {len(states):,} states, {finished_games} games finished, reward scale {scale:.4g}", flush=True)
    return (states.mean(0), flat(states.std(0)), cands.mean(0), flat(cands.std(0))), scale


# ------------------------------------------------------------------------------------------------- actor

def actor(idx: int, args, dims, shared: dict, lock, version, slots: Slots, free_q, ready_q, stats, stop, scale: float) -> None:
    torch.set_num_threads(1)
    s_dim, k, c_dim = dims
    model = CandidateScorer(s_dim, c_dim, args.width)
    model.load_state_dict(shared)
    model.eval()
    seen_version = version.value
    env = load_world(args, args.seed * 1000 + idx * 17 + 1, args.worlds)
    n, A, ctl = args.worlds, args.accounts, args.controlled
    pool = n * (args.flush_steps + 2 * ctl + 4)
    P_s, P_c = np.zeros((pool, s_dim), np.float32), np.zeros((pool, k, c_dim), np.float32)
    P_m, P_a = np.zeros((pool, k), np.uint8), np.zeros(pool, np.int64)
    P_lp, P_v, P_r = np.zeros(pool, np.float32), np.zeros(pool, np.float32), np.zeros(pool, np.float32)
    P_kind, P_train = np.zeros(pool, np.int8), np.zeros(pool, bool)
    P_done, P_closed, P_next = np.zeros(pool, np.int8), np.zeros(pool, bool), np.full(pool, -1, np.int64)
    P_key = np.zeros(pool, np.int64)
    free, top = np.arange(pool, dtype=np.int64), pool
    tail: dict[int, int] = {}
    heads: list[int] = []
    ticket_to_record: dict[int, int] = {}
    my = np.zeros(len(ST))
    pending_version = seen_version

    def ship(rows: np.ndarray, adv: np.ndarray) -> None:
        for start in range(0, len(rows), slots.rows):
            r = rows[start:start + slots.rows]
            clock, slot = time.time(), None
            while slot is None and not stop.is_set():
                try:
                    slot = free_q.get(timeout=0.5)
                except queue_module.Empty:
                    continue
            my[ST["wait_s"]] += time.time() - clock
            if slot is None:
                return
            m, a = len(r), adv[start:start + slots.rows]
            slots.state[slot, :m] = torch.from_numpy(P_s[r])
            slots.cands[slot, :m] = torch.from_numpy(P_c[r])
            slots.mask[slot, :m] = torch.from_numpy(P_m[r])
            slots.action[slot, :m] = torch.from_numpy(P_a[r])
            slots.logp[slot, :m] = torch.from_numpy(P_lp[r])
            slots.adv[slot, :m] = torch.from_numpy(a.astype(np.float32))
            slots.ret[slot, :m] = torch.from_numpy((a + P_v[r]).astype(np.float32))
            slots.kind[slot, :m] = torch.from_numpy(P_kind[r])
            slots.count[slot] = m
            slots.version[slot] = pending_version
            ready_q.put(slot)
            my[ST["shipped"]] += m

    def flush() -> None:
        nonlocal heads, top
        new_heads, rows, advs, released = [], [], [], []
        for h in heads:
            chain, r = [], h
            while r != -1 and P_closed[r]:
                chain.append(r)
                if P_done[r]:
                    break
                r = P_next[r]
            if not chain:
                new_heads.append(h)
                continue
            last = chain[-1]
            successor = -1 if P_done[last] else int(P_next[last])
            if successor != -1:
                new_heads.append(successor)
            elif not P_done[last]:
                raise RuntimeError("a closed transition without a successor decision")
            c = np.asarray(chain)
            bootstrap = float(P_v[successor]) if successor != -1 else 0.0
            a = gae(P_r[c], P_v[c], bootstrap, args.gamma, args.lam)
            train = P_train[c]
            rows.append(c[train])
            advs.append(a[train])
            released.append(c)
        heads = new_heads
        if rows:
            rows_all, adv_all = np.concatenate(rows), np.concatenate(advs)
            if len(rows_all):
                ship(rows_all, adv_all)
        if released:
            freed = np.concatenate(released)
            P_closed[freed], P_done[freed], P_next[freed] = False, 0, -1
            free[top:top + len(freed)] = freed
            top += len(freed)

    decisions = env.decisions()
    state, cands, legal = observe(env, dims)
    steps = 0
    while not stop.is_set() and os.getppid() == args.parent:  # an orphaned actor (killed learner) ends itself
        loop_start = clock = time.time()
        if version.value != seen_version:  # newer weights from the learner
            with lock:
                model.load_state_dict(shared)
            seen_version = version.value
        kinds = np.fromiter((q for _, q, _ in decisions), dtype=np.int8, count=n)
        with torch.inference_mode():
            # Only the candidate rows legal somewhere in this batch are scored (a queue uses half of the rows): same softmax, half the work.
            cols = np.flatnonzero(legal.any(0))
            logits, value = model(torch.from_numpy(state.copy()), torch.from_numpy(cands[:, cols]), torch.from_numpy(legal[:, cols].astype(bool)))
            dist = Categorical(logits=logits)
            sampled = dist.sample()
            logp = dist.log_prob(sampled).numpy()
        any_legal = legal.any(1)
        train = (kinds == args.queue) & any_legal
        actions = np.where(any_legal, cols[sampled.numpy()] if len(cols) else -2, -2).astype(np.int64)
        my[ST["model_s"]] += time.time() - clock
        clock = time.time()
        if not train.all():  # the other queue, and forced waits, play the player rule
            actions = np.where(train, actions, np.asarray(env.baseline(), dtype=np.int64))
        my[ST["env_s"]] += time.time() - clock
        clock = time.time()
        take = free[top - n:top].copy()
        top -= n
        P_s[take], P_c[take], P_m[take], P_a[take] = state, cands, legal, actions
        P_lp[take], P_v[take], P_kind[take], P_train[take] = logp, value.numpy(), kinds, train
        P_next[take], P_closed[take], P_done[take] = -1, False, 0
        for w, ((account, _, ticket), r) in enumerate(zip(decisions, take.tolist())):
            key = w * A + account
            ticket_to_record[ticket] = r
            P_key[r] = key
            previous = tail.get(key)
            if previous is None:
                heads.append(r)
            else:
                P_next[previous] = r
            tail[key] = r
        steps += 1
        if steps % args.flush_steps == 0:
            pending_version = seen_version
            flush()
        my[ST["book_s"]] += time.time() - clock
        clock = time.time()
        closed = env.step(actions.tolist())
        my[ST["env_s"]] += time.time() - clock
        clock = time.time()
        for ticket, reward, done in closed:
            r = ticket_to_record.pop(ticket)
            P_r[r], P_done[r], P_closed[r] = reward / scale, done, True
            my[ST["reward_abs"]] += abs(reward)
            if done and tail.get(int(P_key[r])) == r:
                del tail[int(P_key[r])]
        my[ST["book_s"]] += time.time() - clock
        clock = time.time()
        for i, row in enumerate(env.take_finished()):  # one chunk of `accounts` rows per finished world
            ctl_slot = i % A < ctl
            my[ST["finished"]] += 1
            my[ST["sim_hours"]] += args.horizon
            my[ST["ctl_sum" if ctl_slot else "rule_sum"]] += row[1]
            my[ST["ctl_n" if ctl_slot else "rule_n"]] += 1
        decisions = env.decisions()
        state, cands, legal = observe(env, dims)
        my[ST["env_s"]] += time.time() - clock
        my[ST["decisions"]] += n
        my[ST["loop_s"]] += time.time() - loop_start
        stats[idx] = torch.from_numpy(my)
    ready_q.cancel_join_thread()


def season_actor(idx: int, args, dims, shared: dict, lock, version, slots: Slots, free_q, ready_q, stats, stop, scale: float) -> None:
    """One process for all of an arm's worlds: Rust plays them on every core with the newest weights and hands over finished
    rows; here they only move to shared memory."""
    s_dim, k, c_dim = dims
    args.stagger = True
    env = load_world(args, args.seed * 1000 + idx * 17 + 1, args.worlds)
    my, seen = np.zeros(len(ST)), -1
    while not stop.is_set() and os.getppid() == args.parent:
        loop_start = clock = time.time()
        if version.value != seen:
            with lock:
                weights = {name: tensor.tolist() for name, tensor in shared.items()}
            seen = version.value
            env.set_policy(json.dumps(weights))
        my[ST["model_s"]] += time.time() - clock
        clock = time.time()
        state, cands, legal, action, logp, adv, ret, _ = env.collect(args.collect_rows, args.gamma, args.lam, scale)
        my[ST["env_s"]] += time.time() - clock
        take = lambda raw, kind, *shape: torch.from_numpy(np.frombuffer(raw, kind).reshape(-1, *shape).copy())
        state, cands, legal = take(state, np.float32, s_dim), take(cands, np.float32, k, c_dim), take(legal, np.uint8, k)
        action, logp, adv, ret = take(action, np.int64), take(logp, np.float32), take(adv, np.float32), take(ret, np.float32)
        for start in range(0, len(adv), slots.rows):
            clock, slot = time.time(), None
            while slot is None and not stop.is_set():
                try:
                    slot = free_q.get(timeout=0.5)
                except queue_module.Empty:
                    continue
            my[ST["wait_s"]] += time.time() - clock
            if slot is None:
                break
            part = slice(start, start + slots.rows)
            m = len(adv[part])
            for field, values in ((slots.state, state), (slots.cands, cands), (slots.mask, legal), (slots.action, action), (slots.logp, logp), (slots.adv, adv), (slots.ret, ret)):
                field[slot, :m] = values[part]
            slots.kind[slot, :m] = args.queue
            slots.count[slot], slots.version[slot] = m, seen
            ready_q.put(slot)
            my[ST["shipped"]] += m
        for i, row in enumerate(env.take_finished()):
            my[ST["finished"]] += 1
            my[ST["sim_hours"]] += args.horizon
            my[ST["ctl_sum"]] += row[1]
            my[ST["ctl_n"]] += 1
        my[ST["decisions"]] += args.collect_rows * args.worlds
        my[ST["loop_s"]] += time.time() - loop_start
        stats[idx] = torch.from_numpy(my)
    ready_q.cancel_join_thread()


# ------------------------------------------------------------------------------------------------- learner

def ppo_update(model, optimizer, batch: dict, args, device: str, anchor=None, policy_on: bool = True) -> dict[str, float]:
    """The loss of train_ppo.py: clipped ratio, 0.5 MSE value, entropy over legal rows, per-minibatch advantage normalisation."""
    n = batch["adv"].shape[0]
    kls, clips, vlosses, ents, evs = [], [], [], [], []
    for _ in range(args.epochs):
        for idx in torch.randperm(n, device=device).split(args.minibatch):
            if len(idx) < 64:  # a one-row tail has no standard deviation
                continue
            mask = batch["mask"][idx]
            logits, value = model(batch["state"][idx], batch["cands"][idx], mask)
            dist = Categorical(logits=logits)
            logp = dist.log_prob(batch["action"][idx])
            ratio = (logp - batch["logp"][idx]).exp()
            a = batch["adv"][idx]
            a = (a - a.mean()) / (a.std() + 1e-8)
            policy_loss = torch.max(-a * ratio, -a * ratio.clamp(1 - args.clip, 1 + args.clip)).mean()
            value_loss = 0.5 * (value - batch["ret"][idx]).pow(2).mean()
            p = dist.probs
            entropy = -(p * torch.where(mask, p.clamp_min(1e-12).log(), torch.zeros_like(p))).sum(1).mean()
            loss = args.vf * value_loss + (policy_loss - args.ent * entropy if policy_on else 0.0)
            if anchor is not None:
                # KL(pi || pi_cloned): the policy may leave the behaviour-cloned teacher, but pays for how far.
                with torch.no_grad():
                    ref_logits, _ = anchor(batch["state"][idx], batch["cands"][idx], mask)
                loss = loss + args.kl_anchor * (logp - Categorical(logits=ref_logits).log_prob(batch["action"][idx])).mean()
            model.zero_grad()
            loss.backward()
            torch.nn.utils.clip_grad_norm_(model.parameters(), 0.5)
            optimizer.step()
            with torch.no_grad():
                kls.append(((ratio - 1) - (logp - batch["logp"][idx])).mean())
                clips.append(((ratio - 1).abs() > args.clip).float().mean())
                vlosses.append(value_loss.detach())
                ents.append(entropy.detach())
                evs.append(1.0 - (batch["ret"][idx] - value).var() / batch["ret"][idx].var().clamp_min(1e-8))
    stat = lambda xs: float(torch.stack(xs).mean().detach())
    return {"kl": stat(kls), "clip": stat(clips), "value_loss": stat(vlosses), "entropy": stat(ents), "explained_variance": stat(evs)}


def save(model, dims, args, names, update: int, path: Path) -> None:
    tmp = path.with_suffix(".tmp")
    torch.save({"model": {k: v.detach().cpu() for k, v in model.state_dict().items()}, "state_dim": dims[0], "cand_dim": dims[2], "width": args.width, "iteration": update, "names": names, "queue": args.queue}, tmp)
    os.replace(tmp, path)


class GpuMonitor:
    """nvidia-smi once a second in a thread: utilisation, memory and temperature per card; the learner waits while its card is over a cap."""

    def __init__(self, learner_index: int, util_cap: float, temp_cap: float):
        self.index, self.util_cap, self.temp_cap = learner_index, util_cap, temp_cap
        self.readings: list[tuple[float, float, float]] = []
        self.throttled = 0.0
        self._samples: list[list[tuple[float, float, float]]] = []
        threading.Thread(target=self._poll, daemon=True).start()

    def _poll(self) -> None:
        import subprocess

        while True:
            try:
                rows = subprocess.run(["nvidia-smi", "--query-gpu=utilization.gpu,memory.used,temperature.gpu", "--format=csv,noheader,nounits"], capture_output=True, text=True, timeout=5).stdout.strip().splitlines()
                self.readings = [tuple(float(x) for x in row.split(",")) for row in rows]
                self._samples.append(self.readings)
            except Exception:
                pass
            time.sleep(1.0)

    def window(self) -> list[tuple[float, float, float]]:
        """Mean utilisation, latest memory (MiB) and max temperature per card since the last call."""
        samples, self._samples = self._samples, []
        if not samples:
            return self.readings
        return [(float(np.mean([r[g][0] for r in samples])), samples[-1][g][1], max(r[g][2] for r in samples)) for g in range(len(samples[-1]))]

    def wait_if_hot(self) -> None:
        while self.readings and self.index < len(self.readings) and (self.readings[self.index][0] > self.util_cap or self.readings[self.index][2] > self.temp_cap):
            time.sleep(0.05)
            self.throttled += 0.05


def learner(args, dims, model, shared, lock, version, slots: Slots, free_q, ready_q, stats, stop, final_event, names) -> dict:
    device = args.device
    torch.set_num_threads(4)
    s_dim, k, c_dim = dims
    gpus = GpuMonitor(int(device.split(':')[1]) if device.startswith('cuda') else 99, args.gpu_util_cap, args.gpu_temp_cap)
    model.to(device).train()
    anchor = None
    if args.init and args.kl_anchor > 0:
        # The model is still its warm start here (widened by name when the init was narrower): anchor to that.
        anchor = copy.deepcopy(model).to(device).eval()
        anchor.requires_grad_(False)
    optimizer = torch.optim.Adam(model.parameters(), lr=args.lr, eps=1e-5)
    # BC leaves the value head untrained: it learns alone first, on the head's own parameters, so the clone is not disturbed.
    value_optimizer = torch.optim.Adam(model.value.parameters(), lr=1e-3, eps=1e-5)
    out = Path(args.out)
    log = open(out / "learner.jsonl", "w")
    cap = args.batch + slots.rows
    buffers = [{"state": torch.zeros(cap, s_dim, device=device), "cands": torch.zeros(cap, k, c_dim, device=device), "mask": torch.zeros(cap, k, dtype=torch.bool, device=device),
                "action": torch.zeros(cap, dtype=torch.long, device=device), "logp": torch.zeros(cap, device=device), "adv": torch.zeros(cap, device=device), "ret": torch.zeros(cap, device=device)} for _ in range(3)]
    kind_rows = torch.zeros(8, dtype=torch.long)
    deadline = time.time() + args.minutes * 60 if args.minutes else float("inf")
    batches: queue_module.Queue = queue_module.Queue(maxsize=1)

    def gather() -> None:
        """Copies shared-memory slots into the next GPU buffer on its own stream while the learner trains on the previous one
        (three buffers: one training, one queued, one filling)."""
        stream = torch.cuda.Stream(device) if device.startswith("cuda") else None
        turn = 0
        while not stop.is_set() and time.time() < deadline:
            gpu, filled, lag, kinds = buffers[turn % 3], 0, 0.0, torch.zeros(8, dtype=torch.long)
            turn += 1
            with torch.cuda.stream(stream) if stream else contextlib.nullcontext():
                while filled < args.batch and time.time() < deadline and not stop.is_set():
                    try:
                        slot = ready_q.get(timeout=1.0)
                    except queue_module.Empty:
                        continue
                    m = int(slots.count[slot])
                    for name, field in (("state", slots.state), ("cands", slots.cands), ("action", slots.action), ("logp", slots.logp), ("adv", slots.adv), ("ret", slots.ret)):
                        gpu[name][filled:filled + m].copy_(field[slot, :m])
                    gpu["mask"][filled:filled + m].copy_(slots.mask[slot, :m].bool())
                    kinds += torch.bincount(slots.kind[slot, :m].long(), minlength=8)
                    lag += (version.value - int(slots.version[slot])) * m
                    filled += m
                    free_q.put(slot)
                if stream:
                    stream.synchronize()
            if filled:
                batches.put((gpu, filled, lag, kinds))
        batches.put(None)

    threading.Thread(target=gather, daemon=True).start()
    started = time.time()
    last_print, last_totals = started, np.zeros(len(ST))
    last_update, last_rows, wait_total, busy_total, updates, rows_total = 0, 0, 0.0, 0.0, 0, 0
    lag_sum, lag_n = 0.0, 0
    save(model, dims, args, names, 0, out / "latest.pt")
    print(f"{'time':>6} {'acct-h/s':>10} {'dec/s':>9} {'train/s':>9} {'upd/s':>6} {'upd':>5} {'lag':>4} {'ready':>5} {'learn%':>6} {'actwait%':>8} {'score':>10}  entropy     kl clip vloss    ev  gpu0 util/MiB/C   gpu1 util/MiB/C", flush=True)
    recent: dict[str, float] = {}
    while time.time() < deadline and (not args.updates or updates < args.updates):
        clock = time.time()
        item = batches.get()
        if item is None:
            break
        gpu, filled, lag, kinds = item
        kind_rows += kinds
        lag_sum += lag
        lag_n += filled
        wait_total += time.time() - clock
        clock = time.time()
        gpus.wait_if_hot()
        warming = updates < args.value_warmup
        recent = ppo_update(model, value_optimizer if warming else optimizer, {name: tensor[:filled] for name, tensor in gpu.items()}, args, device, anchor, not warming)
        busy_total += time.time() - clock
        updates += 1
        rows_total += filled
        if updates % args.publish_every == 0:
            cpu_state = {name: tensor.detach().cpu() for name, tensor in model.state_dict().items()}
            with lock:
                for name, tensor in cpu_state.items():
                    shared[name].copy_(tensor)
            version.value += 1
        if updates % args.checkpoint_every == 0:
            save(model, dims, args, names, updates, out / "latest.pt")
            save(model, dims, args, names, updates, out / f"checkpoint-{updates:06d}.pt")
        now = time.time()
        if now - last_print >= args.log_every:
            totals, dt = stats.numpy().sum(0), now - last_print
            delta = totals - last_totals
            # The controlled accounts' mean final result over the seasons finished in this window; 0 until one is.
            result = delta[ST["ctl_sum"]] / delta[ST["ctl_n"]] if delta[ST["ctl_n"]] else 0.0
            loop = max(delta[ST["loop_s"]], 1e-9)
            row = {"t": round(now - started), "acct_hours_s": delta[ST["sim_hours"]] / dt, "decisions_s": delta[ST["decisions"]] / dt, "train_rows_s": (rows_total - last_rows) / dt,
                   "updates_s": (updates - last_update) / dt, "updates": updates, "lag": lag_sum / max(lag_n, 1), "ready": ready_q.qsize(), "learner_busy": busy_total / max(busy_total + wait_total, 1e-9),
                   "actor_wait": delta[ST["wait_s"]] / loop, "actor_env": delta[ST["env_s"]] / loop, "actor_model": delta[ST["model_s"]] / loop, "actor_book": delta[ST["book_s"]] / loop,
                   "controlled_result": float(result), **recent, "kind_rows": {KIND_NAMES.get(i, str(i)): int(v) for i, v in enumerate(kind_rows) if v}}
            cards = gpus.window()
            row["gpus"] = [{"util": c[0], "mem_mib": c[1], "temp_c": c[2]} for c in cards]
            row["learner_throttled_s"] = gpus.throttled
            log.write(json.dumps(row) + "\n")
            log.flush()
            print(f"{row['t']:>5}s {row['acct_hours_s']:>10,.0f} {row['decisions_s']:>9,.0f} {row['train_rows_s']:>9,.0f} {row['updates_s']:>6.2f} {updates:>5} {row['lag']:>4.1f} {row['ready']:>5} {row['learner_busy']:>6.0%} {row['actor_wait']:>8.0%} {result:>10.4g}  {recent.get('entropy', 0):.3f} {recent.get('kl', 0):.4f} {recent.get('clip', 0):.2f} {recent.get('value_loss', 0):.3f} {recent.get('explained_variance', 0):+.2f}  " + "  ".join(f"{c[0]:>3.0f}%/{c[1]:>5.0f}/{c[2]:.0f}" for c in cards), flush=True)
            last_print, last_totals, last_update, last_rows, lag_sum, lag_n = now, totals, updates, rows_total, 0.0, 0
    elapsed = time.time() - started
    totals = stats.numpy().sum(0)
    summary = {"seconds": elapsed, "updates": updates, "updates_per_s": updates / elapsed, "train_rows": rows_total, "train_rows_per_s": rows_total / elapsed, "decisions": totals[ST["decisions"]], "decisions_per_s": totals[ST["decisions"]] / elapsed,
               "account_hours": totals[ST["sim_hours"]], "account_hours_per_s": totals[ST["sim_hours"]] / elapsed, "finished_accounts": totals[ST["finished"]],
               "actor_time_share": {n: float(totals[ST[n]] / max(totals[ST["loop_s"]], 1e-9)) for n in ("env_s", "model_s", "book_s", "wait_s")},
               "learner_busy": busy_total / max(busy_total + wait_total, 1e-9), "kind_rows": {KIND_NAMES.get(i, str(i)): int(v) for i, v in enumerate(kind_rows) if v}}
    save(model, dims, args, names, updates, out / "latest.pt")
    save(model, dims, args, names, updates, out / "model.pt")
    stop.set()
    final_event.set()
    return summary


# ------------------------------------------------------------------------------------------------- evaluator

def play_arm(args, dims, model, device: str, catalogue: str, seeds: list[int], counts: list[int] | None = None) -> np.ndarray:
    """Each seed is one world of `accounts` accounts played to the horizon. The player rule decides the controlled slots, or the model
    does (greedy) for its queue. Returns final values [worlds, accounts] of each world's first game."""
    worlds = [load_world(args, seed, 1, catalogue) for seed in seeds]
    values = np.full((len(seeds), args.accounts), np.nan)
    fleets = np.full((len(seeds), args.accounts), np.nan)
    alive = list(range(len(seeds)))
    while alive:
        rule_actions = [worlds[i].baseline()[0] for i in alive]
        actions = list(rule_actions)
        if model is not None:
            batch = [observe(worlds[i], dims) for i in alive]
            state, cands, legal = (np.concatenate([b[j] for b in batch]) for j in range(3))
            with torch.inference_mode():
                logits, _ = model(torch.from_numpy(state.copy()).to(device), torch.from_numpy(cands.copy()).to(device), torch.from_numpy(legal.astype(bool)).to(device))
            greedy = logits.argmax(1).cpu().numpy()
            kinds = [worlds[i].decisions()[0][1] for i in alive]
            actions = [int(g) if kind == args.queue and legal[j].any() else p for j, (g, kind, p) in enumerate(zip(greedy, kinds, actions))]
            if counts is not None:
                # Compare with the rule's own answer, not with the action already replaced by the model's.
                for g, kind, p in zip(greedy, kinds, rule_actions):
                    if kind == args.queue:
                        counts[0] += 1
                        counts[1] += int(int(g) != int(p))
        still = []
        for i, action in zip(alive, actions):
            worlds[i].step([action])
            done = worlds[i].take_finished()
            if len(done) >= args.accounts:
                # The reward is everything an account owns, so the yardstick is economy + fleet, not the economy alone.
                fleets[i] = [row[5] for row in done[: args.accounts]]
                values[i] = [row[1] + row[5] for row in done[: args.accounts]]
            else:
                still.append(i)
        alive = still
    play_arm.fleets = fleets
    return values


def evaluator(args, dims, stop, final_event) -> None:
    torch.set_num_threads(2)
    device = args.eval_device
    out = Path(args.out)
    seeds = [10_000_000 + i for i in range(args.eval_worlds)]
    started = time.time()
    rule = play_arm(args, dims, None, device, args.eval_catalogue, seeds)
    rule_fleet = play_arm.fleets
    print(f"[eval] player rule on {len(seeds)} fixed worlds ready in {time.time() - started:.0f}s", flush=True)
    done_update, last = -1, None

    def run(path: Path, tag: str) -> dict:
        nonlocal done_update
        saved = torch.load(path, map_location="cpu", weights_only=False)
        model = CandidateScorer(saved["state_dim"], saved["cand_dim"], saved["width"])
        model.load_state_dict(saved["model"])
        model.to(device).eval()
        clock = time.time()
        counts = [0, 0]
        values = play_arm(args, dims, model, device, args.eval_catalogue, seeds, counts)
        fleet = play_arm.fleets
        ctl = slice(0, args.controlled)
        per_account = values[:, ctl] / rule[:, ctl] - 1.0
        per_world = per_account.mean(1)
        low, high = bootstrap_ci(per_world)
        result = {"tag": tag, "update": int(saved["iteration"]), "worlds": len(seeds), "accounts": int(per_account.size), "value_vs_rule_mean": float(per_world.mean()), "ci95_world_bootstrap": [low, high],
                  "better_share_accounts": float((per_account > 0).mean()), "worst_account": float(per_account.min()), "rule_mean_value": float(rule[:, ctl].mean()), "model_mean_value": float(values[:, ctl].mean()),
                  "uncontrolled_accounts_identical": bool(np.allclose(values[:, args.controlled:], rule[:, args.controlled:], rtol=1e-6)),
                  "fleet_vs_rule_mean": float(np.nanmean(fleet[:, ctl] / np.maximum(rule_fleet[:, ctl], 1.0) - 1.0)),
                  "accounts_with_less_fleet": float(np.nanmean(fleet[:, ctl] < rule_fleet[:, ctl])),
                  "economy_vs_rule_mean": float(np.nanmean((values[:, ctl] - fleet[:, ctl]) / np.maximum(rule[:, ctl] - rule_fleet[:, ctl], 1.0) - 1.0)),
                  "accounts_with_less_economy": float(np.nanmean(values[:, ctl] < rule[:, ctl])),
                  "tail_economy": float(np.nanmean((values[:, ctl] / np.maximum(rule[:, ctl], 1.0) - 1.0)[per_account.mean(1) < 0])),
                  "tail_fleet": float(np.nanmean(fleet[:, ctl][per_account.mean(1) < 0])),
                  "decisions_of_kind": int(counts[0]), "differs_from_rule_share": float(counts[1] / max(counts[0], 1)), "seconds": time.time() - clock}
        (out / "eval.jsonl").open("a").write(json.dumps(result) + "\n")
        done_update = result["update"]
        print(f"[eval] {tag} update {result['update']}: value vs player rule {per_world.mean():+.2%} CI [{low:+.2%}, {high:+.2%}] over {len(seeds)} worlds / {per_account.size} accounts, "
              f"better on {(per_account > 0).mean():.0%}, differs from the rule on {result['differs_from_rule_share']:.0%} of its {result['decisions_of_kind']:,} decisions, "
              f"uncontrolled identical {result['uncontrolled_accounts_identical']} ({time.time() - clock:.0f}s)", flush=True)
        return result

    latest = out / "latest.pt"
    while not final_event.is_set() and os.getppid() == args.parent:
        if latest.exists() and torch.load(latest, map_location="cpu", weights_only=False)["iteration"] != done_update:
            last = run(latest, "periodic")
        else:
            time.sleep(2)
    final = run(out / "model.pt", "final")
    (out / "final-eval.json").write_text(json.dumps(final, indent=2))


# ------------------------------------------------------------------------------------------------- main

def main(argv: list[str] | None = None) -> None:
    ap = argparse.ArgumentParser(prog="ogrl.train_world")
    ap.add_argument("--queue", type=int, choices=(0, 1, 2, 3), help="0 decides buildings, 1 research, 2 errands (the fleet side of a login), 3 buildings and research (a season's default, else 0); the other decisions play the player rule; this is the decision kind id")
    ap.add_argument("--init", help="start from a checkpoint (a behaviour-cloned warm start, eg runs/bc-errand.pt)")
    ap.add_argument("--kl-anchor", type=float, default=0.0, help="penalty on leaving the --init policy (KL per row); 0 disables")
    ap.add_argument("--catalogue", help="default: plain-train.json for queue 0, research-train.json for queue 1, units-parity/econ-u1.json for errands")
    ap.add_argument("--eval-catalogue", help="held-out planets; default plain-eval.json / research-eval.json / the training catalogue for errands")
    ap.add_argument("--units", default=str(DATA / "units-parity/units-u1.json"), help="the host's ship and defence tables; errands need them and the fleet exports")
    ap.add_argument("--fleet-settings", default=str(DATA / "fleet-settings-u1.json"))
    ap.add_argument("--combat", default=str(DATA / "combat-u1.json"))
    ap.add_argument("--login-gaps", default=str(DATA / "login-gaps.json"))
    ap.add_argument("--out", required=True)
    ap.add_argument("--seed", type=int, default=1)
    ap.add_argument("--actors", type=int, default=48)
    ap.add_argument("--worlds", type=int, default=256, help="worlds per actor")
    ap.add_argument("--accounts", type=int, default=12)
    ap.add_argument("--controlled", type=int, default=6, help="account slots per world the policy decides (the rest play the rule)")
    ap.add_argument("--horizon", type=float, default=0.0, help="hours per game; 0 = 72, or 240 for errands (a fleet takes days to exist, and in 72 h there is no raid to choose)")
    ap.add_argument("--minutes", type=float, default=15.0, help="learner wall clock; 0 = until --updates")
    ap.add_argument("--updates", type=int, default=0)
    ap.add_argument("--smoke", action="store_true", help="two minutes, 24 h games, a small evaluation")
    ap.add_argument("--flush-steps", type=int, default=128, help="environment steps between advantage computation and shipping")
    ap.add_argument("--slot-rows", type=int, default=4096)
    ap.add_argument("--slots", type=int, default=0, help="shared rollout slots; default 3 per actor")
    ap.add_argument("--batch", type=int, default=65536, help="training rows per update")
    ap.add_argument("--minibatch", type=int, default=32768)
    ap.add_argument("--epochs", type=int, default=2)
    ap.add_argument("--publish-every", type=int, default=1, help="updates between weight publications to the actors")
    ap.add_argument("--checkpoint-every", type=int, default=20)
    ap.add_argument("--width", type=int, default=64)
    ap.add_argument("--lr", type=float, default=3e-4)
    ap.add_argument("--gamma", type=float, default=0.997)
    ap.add_argument("--lam", type=float, default=0.95)
    ap.add_argument("--clip", type=float, default=0.2)
    ap.add_argument("--ent", type=float, default=0.01)
    ap.add_argument("--vf", type=float, default=0.5)
    ap.add_argument("--log-every", type=float, default=10.0)
    ap.add_argument("--device", default="cuda:1" if torch.cuda.device_count() > 1 else ("cuda:0" if torch.cuda.is_available() else "cpu"), help="learner; the 4070 (cuda:1) carries the load, the 3090 is the owner's")
    ap.add_argument("--eval-device", default="cuda:0" if torch.cuda.device_count() > 1 else "cpu", help="evaluator: light (tiny batches), so it goes on the 3090")
    ap.add_argument("--gpu-util-cap", type=float, default=85.0, help="learner sleeps while its card is above this utilisation percent")
    ap.add_argument("--gpu-temp-cap", type=float, default=75.0, help="learner sleeps while its card is above this temperature (C)")
    ap.add_argument("--eval-worlds", type=int, default=64)
    ap.add_argument("--no-eval", action="store_true")
    ap.add_argument("--calibrate-worlds", type=int, default=64, help="worlds played to the end for the feature and reward scales; one process, so a year-long season wants fewer")
    ap.add_argument("--season", action="store_true", help="W24: the season's own universe (ogsim.SeasonWorld, the world examples/season.rs writes and hds.py judges); 64 accounts, 8760 h, r8w data")
    ap.add_argument("--season-data", default=str(DATA / "r8w"), help="the season's exports and login gaps")
    ap.add_argument("--arms", nargs="*", default=SEASON_ARMS, help="the season's switches by their command-line names")
    ap.add_argument("--collect-rows", type=int, default=8192, help="decisions per world in one rollout of a season: 128 per account, about 17 game days, so GAE sees past a step or two")
    ap.add_argument("--detector", help="ogrl.discriminator's file: the reward's realism term reads it")
    ap.add_argument("--realism", type=float, default=0.0, help="lambda: reward per midnight per unit of the detector's logit; 0 is growth alone")
    ap.add_argument("--reward-scale", type=float, help="fixed reward scale; default the --init checkpoint's, else calibrated")
    ap.add_argument("--value-warmup", type=int, default=0, help="updates that train the value head alone, after a BC start")
    args = ap.parse_args(argv)
    args.parent = os.getpid()
    args.stagger = False
    if args.horizon <= 0.0:
        args.horizon = 8760.0 if args.season else 240.0 if args.queue == 2 else 72.0
    if args.season and args.accounts == 12:
        args.accounts = 64
    if args.season and args.controlled == 6:
        args.controlled = args.accounts  # the world hds.py judges: every account the model's, ranks among themselves
    if args.queue is None:
        args.queue = 3 if args.season else 0
    if args.smoke:
        args.minutes, args.horizon, args.eval_worlds = 2.0, 24.0, min(args.eval_worlds, 16)
    name = KIND_NAMES[args.queue]
    defaults = {0: ("plain-train.json", "plain-eval.json"), 1: ("research-train.json", "research-eval.json"), 2: ("units-parity/econ-u1.json", "units-parity/econ-u1.json")}
    train_catalogue, eval_catalogue = ("r8w/units-parity/econ-u1-colonies.json",) * 2 if args.season else defaults[args.queue]
    args.catalogue = args.catalogue or str(DATA / train_catalogue)
    args.eval_catalogue = args.eval_catalogue or str(DATA / eval_catalogue)
    args.slots = args.slots or 3 * args.actors
    out = Path(args.out)
    out.mkdir(parents=True, exist_ok=True)
    (out / "config.json").write_text(json.dumps(vars(args), indent=2))
    torch.manual_seed(args.seed)
    np.random.seed(args.seed)

    probe = load_world(args, args.seed, 1)
    dims = probe.dims()
    names = dict(zip(("state", "candidate"), probe.feature_names()))
    del probe
    saved = torch.load(args.init, map_location="cpu", weights_only=False) if args.init else {}
    if args.init and args.queue == 2:
        # An errand warm start may be narrower than the simulator: widen it by column name (new columns at weight 0).
        names = resolve_names(names, dims[0], dims[2])
        try:
            saved = widen_checkpoint(load_errand(args.init), names)
        except ValueError as error:
            raise SystemExit(f"errand checkpoint feature schema differs and cannot be widened: {error}")
    scale = args.reward_scale or saved.get("reward_scale")
    model = CandidateScorer(dims[0], dims[2], args.width)
    if not (args.init and scale):  # a year-long world costs minutes per game: a BC start brings its scale and normalisation
        norm, calibrated = calibrate(args, dims)
        scale = scale or calibrated
        model.set_normalisation(*(torch.as_tensor(x, dtype=torch.float32) for x in norm))
    if args.init:
        # A behaviour-cloned warm start (ogrl.train_bc_world): PPO begins where the scripted teacher plays instead of at noise.
        if (saved["state_dim"], saved["cand_dim"], saved["width"]) != dims[0:1] + dims[2:3] + (args.width,):
            raise SystemExit(f"{args.init} is {saved['state_dim']}x{saved['cand_dim']} width {saved['width']}, this run is {dims[0]}x{dims[2]} width {args.width}")
        model.load_state_dict(saved["model"])
        print(f"warm start from {args.init}, reward scale {scale:.4g}", flush=True)
    shared = {k: v.detach().clone().share_memory_() for k, v in model.state_dict().items()}
    print(f"ogsim world PPO: {parameter_count(model):,} parameters, kind {args.queue} ({name}), {args.actors} actors x {args.worlds} worlds x {args.accounts} accounts ({args.controlled} controlled), "
          f"horizon {args.horizon:g} h, learner {args.device}, evaluator {args.eval_device}", flush=True)

    ctx = mp.get_context("spawn")
    lock, version = ctx.Lock(), ctx.Value("i", 0)
    slots = Slots(args.slots, args.slot_rows, *dims)
    free_q, ready_q = ctx.Queue(), ctx.Queue()
    for s in range(args.slots):
        free_q.put(s)
    stats = torch.zeros(args.actors, len(ST), dtype=torch.float64).share_memory_()
    stop, final_event = ctx.Event(), ctx.Event()
    procs = [ctx.Process(target=season_actor if args.season else actor, args=(i, args, dims, shared, lock, version, slots, free_q, ready_q, stats, stop, scale), daemon=True) for i in range(args.actors)]
    for p in procs:
        p.start()
    judge = None if args.no_eval else ctx.Process(target=evaluator, args=(args, dims, stop, final_event), daemon=True)
    if judge:
        judge.start()
    try:
        summary = learner(args, dims, model, shared, lock, version, slots, free_q, ready_q, stats, stop, final_event, names)
    finally:
        stop.set()
        final_event.set()
    for p in procs:
        p.join(timeout=20)
        if p.is_alive():
            p.terminate()
    summary["config"] = vars(args)
    (out / "summary.json").write_text(json.dumps(summary, indent=2, default=float))
    print("summary:", json.dumps({k: v for k, v in summary.items() if k != "config"}, indent=1, default=float), flush=True)
    if judge:
        judge.join(timeout=3600)
        if judge.is_alive():
            judge.terminate()
    print(f"done -> {out}")


if __name__ == "__main__":
    main()
