"""W14: compare a world trace's host replay (ai:rl-world-replay) with the simulator, step by step and at the end.

    .venv-rl/bin/python rl/scripts/world_parity.py storage/rl/ogsim/w14/replay-7d-s1.json

A step differs when the host's stock before it differs from the simulator's by more than a resource unit, a
building or research takes other seconds, the yard queued another amount, a fleet flies other seconds or burns other
fuel, or a fleet event ends otherwise. The first difference of each planet
is printed, because everything after it inherits it.
"""
import json
import sys


def first_gap(step: dict) -> str | None:
    if step.get("sim_stock") is not None and step.get("host_stock") is not None:
        gaps = [abs(a - b) for a, b in zip(step["sim_stock"], step["host_stock"])]
        if max(gaps) > 1.0:
            return f"stock sim {[round(x) for x in step['sim_stock']]} host {[round(x) for x in step['host_stock']]}"
    if step.get("queue") == "touch":
        return None
    if step.get("host_refusal"):
        return f"the host refused: {step['host_refusal']}"
    if step.get("queue") == "launch":
        sim, host = (step["sim_arrive"], step["sim_fuel"]), (step["host_arrive"], step["host_fuel"])
        return None if sim == host else f"flight and fuel sim {sim} host {host}"
    if step.get("queue") in ("arrive", "return"):
        # The host records only whether a mission ran; what it found or won shows in the hold and the stock.
        sim_outcome = "Cancelled" if step["sim_outcome"] == "Cancelled" else "Done"
        if sim_outcome != step["host_outcome"]:
            return f"outcome sim {step['sim_outcome']} host {step['host_outcome']}"
        if step.get("host_gained") is not None and max(abs(a - b) for a, b in zip(step["sim_gained"], step["host_gained"])) > 1.0:
            return f"lands with sim {[round(x) for x in step['sim_gained']]} host {step['host_gained']}"
        return None if step["host_at"] == int(step["start"]) else f"lands at sim {step['start']} host {step['host_at']}"
    if step.get("queue") == "units":
        return None if step["sim_queued"] == step["host_queued"] else f"queued sim {step['sim_queued']} host {step['host_queued']}"
    if step.get("host_seconds") is None:
        return "the host did not start it"
    return None if step["sim_seconds"] == step["host_seconds"] else f"seconds sim {step['sim_seconds']} host {step['host_seconds']}"


def final_gaps(planet: dict) -> list[str]:
    sim, host = planet["sim_final"], planet["host_final"]
    if host is None:
        return ["the host has no such planet"]
    out = []
    stock = [abs(a - b) for a, b in zip(sim["stock"], host["stock"])]
    if max(stock) > 1.0:
        out.append(f"final stock sim {[round(x) for x in sim['stock']]} host {[round(x) for x in host['stock']]}")
    for kind in ("levels", "units"):
        diff = {k: (sim[kind].get(k, 0), v) for k, v in ((str(k), v) for k, v in host[kind].items()) if sim[kind].get(k, 0) != v}
        if diff:
            out.append(f"final {kind} (sim, host) {diff}")
    return out


def main(path: str) -> int:
    planets = json.load(open(path))["planets"]
    steps = differ = 0
    for planet in planets:
        steps += len(planet["steps"])
        gap = next(((i, s, g) for i, s in enumerate(planet["steps"]) for g in [first_gap(s)] if g), None)
        finals = final_gaps(planet)
        if gap or finals:
            differ += 1
            print(f"planet {planet['planet_id']} at {planet.get('coord')}:")
            if gap:
                i, s, g = gap
                print(f"  step {i} of {len(planet['steps'])} at {s['start']} ({s.get('queue', 'building')} {s.get('id', s.get('mission', ''))}): {g}")
            for f in finals:
                print(f"  {f}")
    print(f"{len(planets)} planets, {steps} steps, {differ} planets differ")
    return 1 if differ else 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1]))
