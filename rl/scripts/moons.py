"""Row 17: the moon diagnosis.

How many fights a season fought, how many left enough debris for the host to roll a moon, how many of those rolls
made one, and how many accounts hold a moon at day 60 - by rank band, since a moon is a top10 thing (real top10 p50
15, real bottom 0). The counters come from the season's `events_today` (row 17's append to §9.1): `fights`,
`moon_rolls` (a fight whose debris cleared the host's own threshold), `moon_made`, and `moon_shot` (moon destruction,
a different mission).

    python3 rl/scripts/moons.py storage/rl/ogsim/runs/flow/e17/season-*.jsonl
"""
import collections
import json
import statistics
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).parent))
from real_bands import band_of  # noqa: E402

BANDS = ("top10", "mid", "bottom")


def read(paths: list[str]) -> list[dict]:
    rows = []
    for path in paths:
        rows.extend(json.loads(line) for line in Path(path).read_text().splitlines() if line.strip())
    return rows


def main(paths: list[str]) -> int:
    rows = read(paths)
    tally = collections.defaultdict(lambda: collections.Counter())
    days = collections.defaultdict(lambda: collections.Counter())
    # Rank inside each world-day's own score, as the scorecard does, so a band means the same thing here.
    by_world = collections.defaultdict(list)
    for row in rows:
        by_world[(row["seed"], row["day"])].append(row)
    moon_days = collections.defaultdict(list)
    for (seed, day), group in by_world.items():
        ordered = sorted(group, key=lambda r: -r["score"]["total"])
        for rank, row in enumerate(ordered, start=1):
            band = band_of(rank / max(len(ordered), 1))
            events = row.get("events_today", {})
            tally[band]["fights"] += events.get("fights", 0)
            tally[band]["rolls"] += events.get("moon_rolls", 0)
            tally[band]["made"] += events.get("moon_made", 0)
            tally[band]["shot"] += events.get("moon_shot", 0)
            tally[band]["debris"] += sum(1 for _ in range(events.get("moon_rolls", 0)))
            days[band]["account_days"] += 1
            if day == max(d for (s, d) in by_world if s == seed):
                moon_days[band].append(row["moons"])

    print(f"{len(rows)} account-days over {len(paths)} season(s)\n")
    print("| band | fights | fights that rolled | moons made | moons / roll | accounts with a moon at day 60 | moon destruction sent |")
    print("| --- | --- | --- | --- | --- | --- | --- |")
    for band in BANDS + ("pooled",):
        cells = [tally[band]] if band != "pooled" else [tally[b] for b in BANDS]
        fights = sum(c["fights"] for c in cells)
        rolls = sum(c["rolls"] for c in cells)
        made = sum(c["made"] for c in cells)
        shot = sum(c["shot"] for c in cells)
        held = [m for b in (BANDS if band == "pooled" else (band,)) for m in moon_days[b]]
        with_moon = sum(1 for m in held if m > 0)
        rate = f"{made / rolls:.3f}" if rolls else "-"
        share = f"{with_moon}/{len(held)}" if held else "-"
        print(f"| {band} | {fights} | {rolls} | {made} | {rate} | {share} | {shot} |")
    accounts = len({(r["seed"], r["account"]) for r in rows})
    print(f"\n{accounts} account-seasons; median moon count at day 60: {statistics.median([m for b in BANDS for m in moon_days[b]] or [0])}")
    return 0


if __name__ == "__main__":
    if len(sys.argv) < 2:
        raise SystemExit(__doc__)
    raise SystemExit(main(sys.argv[1:]))
