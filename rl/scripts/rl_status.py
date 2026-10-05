#!/usr/bin/env python3
"""Collector for the ML training overview page (/ai-harness/rl).

Reads the run's own artifacts under storage/rl (sim logs, recorded choices, training log, closed-loop logs,
/proc) and rewrites storage/rl/status.json every few seconds. The page only renders that file.
Standard library only, so it runs under any python3 on the host.

    python3 Modules/AI/rl/scripts/rl_status.py [--once] [--interval 5]
"""
from __future__ import annotations

import argparse
import heapq
import json
import math
import os
import re
import time
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parents[4]
RL = ROOT / "storage" / "rl"
DATE = re.compile(r"^\s+(\d{4}-\d{2}-\d{2}) (\d{2}):\d{2}\s+sessions\s+\d+\s+other\s+\d+\s+errors\s+(\d+)")
PLAYED = re.compile(r"^SIM: .*?played in (\d+) s .*?(\d+) error\(s\)", re.M)
START = datetime(2026, 10, 5, tzinfo=timezone.utc)
GATES = {"choice_points": 300_000, "top1_3plus": 0.90, "mrr": 0.93, "dv_band": 0.05}
HISTORY = 180


def read(path: Path) -> str:
    try:
        return path.read_text(errors="replace")
    except OSError:
        return ""


def parse_epoch(text: str, default: float) -> float:
    """gen.start is a note someone else writes; a bad one must not take the collector (and the page) down."""
    try:
        return float(text.strip())
    except ValueError:
        return default


def load_json(path: Path, default):
    try:
        return json.loads(path.read_text())
    except (OSError, ValueError):
        return default


def tail(path: Path, size: int = 4096) -> str:
    try:
        with path.open("rb") as f:
            f.seek(0, os.SEEK_END)
            f.seek(max(0, f.tell() - size))
            return f.read().decode(errors="replace")
    except OSError:
        return ""


class Counter:
    """Incremental line and legal>=2 counts per recorded-choices file: only new bytes are read each pass."""

    def __init__(self, state: dict):
        self.state = state

    def count(self, path: Path) -> tuple[int, int]:
        key = str(path)
        offset, lines, multi = self.state.get(key, [0, 0, 0])
        try:
            size = path.stat().st_size
        except OSError:
            return 0, 0
        if size < offset:
            offset, lines, multi = 0, 0, 0
        if size > offset:
            with path.open("rb") as f:
                f.seek(offset)
                chunk = f.read(size - offset)
            end = chunk.rfind(b"\n") + 1
            for line in chunk[:end].splitlines():
                lines += 1
                m = re.search(rb'"legal":\[([^\]]*)\]', line)
                multi += 1 if m and m.group(1).count(b"true") >= 2 else 0
            offset += end
        self.state[key] = [offset, lines, multi]
        return lines, multi


def sim_progress(log: Path) -> dict:
    text = read(log)
    header = re.search(r"\(([\d.]+) h\)", text)
    hours = float(header.group(1)) if header else 720.0
    errors, last = 0, None
    for line in text.splitlines():
        m = DATE.match(line)
        if m:
            errors += int(m.group(3))
            last = m
    played = PLAYED.search(text)
    done = played is not None
    day = hours / 24 if done else 0.0
    if last and not done:
        moment = datetime.fromisoformat(f"{last.group(1)}T{last.group(2)}:00:00+00:00")
        day = (moment - START).total_seconds() / 86400
    fell_back = re.search(r"(\d+) choice\(s\) fell back", text)
    errors = int(played.group(2)) if played else errors
    try:
        idle = time.time() - log.stat().st_mtime
    except OSError:
        idle = None
    return {"day": round(day, 2), "days": round(hours / 24, 1), "done": done, "errors": errors,
            "idle": None if idle is None else round(idle), "wall": int(played.group(1)) if played else None,
            "fell_back": int(fell_back.group(1)) if fell_back else None, "kinds": error_kinds(text)}


def error_kinds(text: str) -> list[str]:
    block = re.search(r"Errors by kind[^\n]*\n((?: +\d+ [^\n]*\n)+)", text)
    return [ln.strip()[:160] for ln in block.group(1).splitlines()][:3] if block else []


def generation(counter: Counter, previous: dict) -> dict:
    """Every batch folder under storage/rl (bc, bc2, ...). A folder marked `.partial` was cut short (crash) and is final."""
    universes = []
    for out in sorted(RL.glob("bc*")):
        partial = (out / ".partial").exists()
        for log in sorted(out.glob("sim-*.log"), key=lambda p: int(re.sub(r"\D", "", p.stem) or 0)):
            n = int(re.sub(r"\D", "", log.stem))
            lines, multi = counter.count(out / f"choices-{n}.jsonl")
            info = sim_progress(log)
            if partial and not info["done"]:
                info.update(done=True, days=info["day"], errors=info["errors"], partial=True)
            universes.append({"n": n, "points": lines, "multi": multi, **info})
    target, days = parse_target()
    return {"universes": universes, "target": target, "plan_days": days}


def parse_target() -> tuple[int, int]:
    cmd = read(RL / "gen.cmd").split()
    return (int(cmd[0]), int(cmd[1])) if len(cmd) > 1 else (32, 30)


def machine() -> dict:
    load = float(read(Path("/proc/loadavg")).split()[0] or 0)
    mem = {k: int(v.split()[0]) for k, v in (ln.split(":", 1) for ln in read(Path("/proc/meminfo")).splitlines() if ":" in ln)}
    gpu = {}
    free = os.statvfs(str(RL))
    return {"cpus": os.cpu_count(), "load": load,
            "mem_used_mb": (mem.get("MemTotal", 0) - mem.get("MemAvailable", 0)) // 1024, "mem_total_mb": mem.get("MemTotal", 0) // 1024,
            "disk_free_gb": free.f_bavail * free.f_frsize // 2**30, "gpu": gpu}


def training() -> dict:
    # The newest bc-*/train.log is the run being shown: a retrain in a new folder takes over the page by itself.
    folders = sorted(RL.glob("bc-*/train.log"), key=lambda f: f.stat().st_mtime)
    folder = folders[-1].parent if folders else RL / "bc-model"
    log = read(folder / "train.log")
    epochs = []
    for line in log.splitlines():
        if line.startswith("{") and '"epoch"' in line:
            try:
                epochs.append(json.loads(line))
            except ValueError:
                pass
    metrics = load_json(folder / "metrics.json", {})
    info = {"epochs": epochs, "total": metrics.get("args", {}).get("epochs") or parse_epochs(), "metrics": None,
            "header": [ln for ln in log.splitlines() if ln.startswith(("choices:", "parameters:"))]}
    if metrics:
        v = metrics.get("validation", {})
        info["metrics"] = {"parameters": metrics.get("parameters"), "top1": v.get("top1"), "top1_3plus": v.get("top1_3plus_legal"),
                           "mrr": v.get("mrr"), "baseline": v.get("baseline_random_top1"), "top1_hard": v.get("top1_hard_rows"),
                           "hard_share": v.get("hard_rows_share"), "baseline_first": v.get("baseline_first_legal_3plus"),
                           "weakest": {k: v[k] for k in v if k.startswith(("weakest", "wait_rate", "payback"))}}
    return info


def parse_epochs() -> int:
    m = re.search(r"--epochs (\d+)", read(RL / "train.cmd"))
    return int(m.group(1)) if m else 30


def closed_loop(counter: Counter) -> dict:
    out = RL / "eval"
    pairs = {}
    for side in ("teacher", "policy"):
        for log in (out / side).glob("sim-*.log") if (out / side).exists() else []:
            n = int(re.sub(r"\D", "", log.stem))
            pairs.setdefault(n, {})[side] = sim_progress(log)
    report = load_json(out / "report.json", None)
    return {"pairs": [{"n": n, **p} for n, p in sorted(pairs.items())], "report": report,
            "target": int(read(RL / "eval.cmd").split()[0] or 30) if (RL / "eval.cmd").exists() else 30}


def alarms(gen: dict, mach: dict, train: dict, loop: dict) -> list[dict]:
    found = []
    run_started = parse_epoch(read(RL / "run.started"), 0)
    for u in gen["universes"]:
        if u["errors"] > 0:
            found.append({"where": f"universe {u['n']}", "what": f"{u['errors']} sim error(s)", "detail": "; ".join(u["kinds"])})
        # A queued universe keeps the log of an earlier attempt; only a sim this run started and that went silent is stalled.
        if not u["done"] and u["idle"] is not None and time.time() - u["idle"] >= run_started and u["idle"] is not None and u["idle"] > 900 and not (RL / ".paused").exists():
            found.append({"where": f"universe {u['n']}", "what": f"stalled {u['idle'] // 60} min", "detail": ""})
    for pair in loop["pairs"]:
        for side, p in pair.items():
            if side == "n":
                continue
            if p["errors"] > 0:
                found.append({"where": f"eval {side} {pair['n']}", "what": f"{p['errors']} sim error(s)", "detail": "; ".join(p["kinds"])})
            if side == "policy" and p["fell_back"]:
                found.append({"where": f"eval policy {pair['n']}", "what": f"{p['fell_back']} choice(s) fell back to the planner", "detail": ""})
    if mach["mem_total_mb"] and mach["mem_used_mb"] > 0.92 * mach["mem_total_mb"]:
        found.append({"where": "machine", "what": "memory above 92%", "detail": ""})
    if mach["disk_free_gb"] < 20:
        found.append({"where": "machine", "what": f"disk free {mach['disk_free_gb']} GB", "detail": ""})
    return found


def gates(gen: dict, train: dict, loop: dict, validation: dict | None) -> list[dict]:
    invalid = bool(validation and validation.get("verdict") == "fail")
    validation_note = " · data validation FAILED" if invalid else ""
    points = sum(u["multi"] for u in gen["universes"])
    errors = sum(u["errors"] for u in gen["universes"])
    unis = len(gen["universes"])
    rows = [{"name": "G-data", "what": "choice points with 2+ legal rows", "value": points, "need": GATES["choice_points"],
             "extra": f"{unis} universes, {errors} sim errors (need 0)" + validation_note, "state": "fail" if errors or invalid else ("pass" if points >= GATES["choice_points"] else "pending")}]
    m = train["metrics"]
    rows.append({"name": "G-BC top-1", "what": "top-1 on 3+ legal rows", "value": m and m["top1_3plus"], "need": GATES["top1_3plus"], "extra": "",
                 "state": "pending" if not m else ("pass" if m["top1_3plus"] >= GATES["top1_3plus"] else "fail")})
    rows.append({"name": "G-BC MRR", "what": "mean reciprocal rank", "value": m and m["mrr"], "need": GATES["mrr"], "extra": "",
                 "state": "pending" if not m else ("pass" if m["mrr"] >= GATES["mrr"] else "fail")})
    rep = loop["report"] or {}
    diff = rep.get("relative_value_difference_b_minus_a") or {}
    mean, ci = diff.get("mean"), diff.get("ci95")
    verdict = "pending" if mean is None else ("pass" if abs(mean) <= GATES["dv_band"] and ci[1] >= 0 else "fail")
    rows.append({"name": "G-BC closed loop", "what": "relative value change, model vs planner", "value": mean, "need": GATES["dv_band"],
                 "extra": "" if mean is None else f"CI [{ci[0]:+.3f}, {ci[1]:+.3f}] · n={diff.get('n')} · better in {diff.get('share_better', 0):.0%}", "state": verdict})
    return rows


def steps(gen: dict, train: dict, loop: dict, gate_rows: list[dict], fixed: dict) -> list[dict]:
    finished = [u for u in gen["universes"] if u["done"]]
    unis = len(gen["universes"])
    gen_done = unis >= gen["target"] and len(finished) == unis and unis > 0
    gen_started = unis > 0
    train_done = bool(train["metrics"])
    train_started = bool(train["epochs"]) or (RL / "bc-model").exists()
    loop_done = bool(loop["report"])
    loop_started = bool(loop["pairs"])
    names = ["Setup", "Tests", "Smoke + determinism", "Throughput", "Generate data", "Train on GPU", "Closed loop", "Record results"]
    state = [fixed.get(str(i), {}).get("state", "pending") for i in range(4)] + [
        "done" if gen_done else "running" if gen_started else "pending",
        "done" if train_done else "running" if train_started else "pending",
        "done" if loop_done else "running" if loop_started else "pending",
        fixed.get("7", {}).get("state", "pending")]
    summary = [fixed.get(str(i), {}).get("summary", "") for i in range(4)] + [
        f"{sum(u['multi'] for u in gen['universes']):,} choice points · {len(finished)}/{gen['target']} universes",
        (f"{len(train['epochs'])}/{train['total']} epochs" + (f" · top-1(3+) {train['metrics']['top1_3plus']:.3f}" if train["metrics"] and train["metrics"]["top1_3plus"] else "")) if train_started else "",
        f"{sum(1 for p in loop['pairs'] if p.get('teacher', {}).get('done') and p.get('policy', {}).get('done'))}/{loop['target']} pairs" if loop_started else "",
        fixed.get("7", {}).get("summary", "")]
    return [{"n": i, "name": names[i], "state": state[i], "summary": summary[i]} for i in range(8)]


def eta(state: dict, gen: dict, now: float, since: float) -> int | None:
    """Wall seconds until every planned universe is done.

    A simulated day gets dearer as accounts grow, so time to reach day d is fitted as c*d^p from the samples of
    every running universe (log-log least squares), then the queued universes are scheduled onto the workers as they free up.
    """
    running = [u for u in gen["universes"] if not u["done"]]
    if not running:
        return None
    seen = state.setdefault("first_seen", {})
    last = state.setdefault("last_day", {})
    samples = state.setdefault("day_samples", [])
    for u in running:
        if u["day"] < last.get(str(u["n"]), 0) - 0.05:   # the universe restarted from scratch (crash, relaunch): its old samples are void
            seen[str(u["n"])] = now
            samples.clear()
        last[str(u["n"])] = u["day"]
        # A universe first seen already well along started with the run; one seen at day 0 starts now.
        start = seen.setdefault(str(u["n"]), since if u["day"] > 1 else now)
        if u["day"] >= 0.5:
            samples.append([now - start, u["day"]])
    state["day_samples"] = samples = samples[-1500:]
    pts = [(math.log(t), math.log(d)) for t, d in samples if t > 30 and d >= 0.5]
    if len(pts) < 20:
        return None
    mx, my = sum(x for x, _ in pts) / len(pts), sum(y for _, y in pts) / len(pts)
    var = sum((x - mx) ** 2 for x, _ in pts)
    if var < 1e-6:
        return None
    slope = sum((x - mx) * (y - my) for x, y in pts) / var       # d ~ t^slope, so t ~ d^(1/slope)
    power = min(max(1 / slope, 1.0), 3.0) if slope > 0 else 2.0
    # c from the pooled mean: log t = power * log d + c
    c = sum(x - power * y for x, y in pts) / len(pts)
    plan = gen["plan_days"]
    full = math.exp(c + power * math.log(plan))
    free_at = sorted(max(full - (now - seen[str(u["n"])]), 0) if u["day"] < plan else 0 for u in running)
    queued = max(gen["target"] - len(gen["universes"]), 0)
    for _ in range(queued):
        soonest = heapq.heappop(free_at) if free_at else 0
        heapq.heappush(free_at, soonest + full)
    return int(max(free_at)) if free_at else None


def update_events(prev: dict, now: float, steps_now: list[dict], alarm_now: list[dict], gen: dict) -> list[dict]:
    events = prev.get("events", [])
    seen = prev.get("seen", {})
    stamp = datetime.now().strftime("%H:%M:%S")

    def add(kind: str, text: str):
        events.append({"t": stamp, "kind": kind, "text": text})

    for s in steps_now:
        key = f"step{s['n']}"
        if seen.get(key) != s["state"]:
            if key in seen or s["state"] != "pending":
                add("good" if s["state"] == "done" else "info", f"Step {s['n']} {s['name']}: {s['state']}")
            seen[key] = s["state"]
    for u in gen["universes"]:
        key = f"u{u['n']}"
        if u["done"] and key not in seen:
            seen[key] = "done"
            add("good", f"Universe {u['n']} finished: {u['points']:,} points, {u['errors']} errors, {u['wall']} s")
    now_alarms = {f"{a['where']}|{a['what']}" for a in alarm_now}
    for a in alarm_now:
        key = f"a|{a['where']}|{a['what']}"
        if key not in seen:
            seen[key] = "raised"
            add("bad", f"ALARM {a['where']}: {a['what']}")
    for key in [k for k in seen if k.startswith("a|") and k[2:] not in now_alarms]:
        del seen[key]
    return {"events": events[-40:], "seen": seen}


def collect(state: dict) -> dict:
    now = time.time()
    counter = Counter(state.setdefault("offsets", {}))
    gen = generation(counter, state)
    mach = machine()
    train = training()
    loop = closed_loop(counter)
    fixed = load_json(RL / "steps.json", {})
    validation = load_json(RL / "validation.json", None)
    gate_rows = gates(gen, train, loop, validation)
    alarm_rows = alarms(gen, mach, train, loop)
    step_rows = steps(gen, train, loop, gate_rows, fixed)

    done_days = sum(u["days"] if u["done"] else u["day"] for u in gen["universes"])
    queued = max(gen["target"] - len(gen["universes"]), 0)
    total_days = sum(u["days"] for u in gen["universes"]) + queued * gen["plan_days"]
    since = parse_epoch(read(RL / "gen.start"), now)
    history = state.setdefault("progress", [])
    history.append([now, done_days])
    state["progress"] = history[-400:]
    remaining = total_days - done_days
    running = step_rows[4]["state"] == "running"
    eta_s = eta(state, gen, now, since) if running and remaining > 0 else None

    series = state.setdefault("series", [])
    series.append([int(now), mach["load"], mach["gpu"].get("util", 0), mach["mem_used_mb"], mach["gpu"].get("mem_used", 0)])
    state["series"] = series[-HISTORY:]
    flow = update_events(state.get("flow", {}), now, step_rows, alarm_rows, gen)
    state["flow"] = flow
    current = next((s for s in step_rows if s["state"] == "running"), None)
    overall = "paused" if (RL / ".paused").exists() else "alarm" if alarm_rows else "done" if all(s["state"] == "done" for s in step_rows) else "running" if current else "idle"
    plain = {
        4: ("Playing simulated game worlds and recording every decision the rule-based player makes.", "Check the data, then train the model."),
        5: ("Teaching the model to copy the rule-based player's decisions, on the graphics card.", "Test the model by letting it play whole games."),
        6: ("Replaying the same worlds twice, once with the rule-based player and once with the model deciding everything, to see if the model plays as well.", "Compare the results, then run a correction round where the model plays and the rule-based player fixes its mistakes."),
        7: ("Writing the results down and saving them.", "Done, or another correction round."),
    }
    now_text, next_text = plain.get(current["n"], ("Waiting for the next step to start.", "")) if current else ("Nothing is running right now.", "Waiting for the next step to be started.")
    return {"at": int(now), "overall": overall, "current": current, "plain": {"now": now_text, "next": next_text}, "steps": step_rows, "gates": gate_rows, "alarms": alarm_rows,
            "generation": {**gen, "done_days": round(done_days, 1), "total_days": total_days, "eta_seconds": eta_s,
                           "elapsed": int(now - since) if running or done_days else None,
                           "wall_started": int(since)},
            "training": train, "closed_loop": loop, "validation": validation, "machine": mach, "series": state["series"], "events": flow["events"][::-1],
            "fixed": fixed}


def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("--once", action="store_true")
    ap.add_argument("--interval", type=float, default=5)
    args = ap.parse_args()
    state_file = RL / ".collector-state.json"
    state = load_json(state_file, {})
    while True:
        status = collect(state)
        tmp = RL / "status.json.tmp"
        tmp.write_text(json.dumps(status))
        tmp.replace(RL / "status.json")
        state_file.write_text(json.dumps(state))
        if args.once:
            return
        time.sleep(args.interval)


if __name__ == "__main__":
    main()
