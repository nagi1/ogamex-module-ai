"""Integrity gauge for social seasons (SOCIAL-WORLD-DRAFT part 1 gauge 3: deeds match words, promises mostly kept,
deception a rare tool with a reason). Reads <season>.diplomacy.jsonl and <season>.decisions.jsonl per seed.

  pacts            pacts formed (open + secret)
  kept             share of pacts never ended by a betrayal of either signer (a surprise break or a cancel by notice)
  secret           share of pacts made in secret (hidden from the world: a lie of omission)
  betray_club_yr   betrayals (surprise breaks and cancels by notice) per club per 365 days
  surprise         surprise breaks (war at once, no notice; every `betrayal` before notices existed) / pacts (M5 <= 0.003)
  reasoned         share of betray choices backed by a remembered grievance over gratitude or the PsychSim answer
Usage: integrity_gauge.py DIR [DIR ...]
"""
from __future__ import annotations

import glob
import json
import statistics as st
import sys


def season(path: str) -> dict:
    events = [json.loads(line) for line in open(path)]
    pacts = [e for e in events if e.get('kind') == 'pact']
    surprises = [e for e in events if e.get('kind') == 'betrayal']
    betrayals = surprises + [e for e in events if e.get('kind') == 'pact_cancel']
    clubs = {e['by'] for e in events if e.get('kind') == 'founded'}
    hours = max((e.get('hour', 0) for e in events), default=0) or 1
    pairs = {frozenset((e['by'], e['with'])) for e in pacts}
    broken = {frozenset((e['by'], e['with'])) for e in betrayals}
    out = {
        'pacts': len(pacts),
        'kept': 1 - len(pairs & broken) / len(pairs) if pairs else 1.0,
        'secret': sum(e.get('cause') == 'secret' for e in pacts) / len(pacts) if pacts else 0.0,
        'betray_club_yr': len(betrayals) / max(len(clubs), 1) / (hours / 24 / 365),
        'surprise': len(surprises) / len(pacts) if pacts else 0.0,
    }
    try:
        rows = [json.loads(line) for line in open(path.replace('.diplomacy.jsonl', '.decisions.jsonl'))]
        chosen = [r for r in rows if r.get('kind') == 'partner' and r.get('choice') == 'betray']
        if chosen:
            out['reasoned'] = sum(r['grievance'] > r['gratitude'] or r.get('psychsim') == 'betray' for r in chosen) / len(chosen)
    except FileNotFoundError:
        pass
    return out


def main() -> None:
    for d in sys.argv[1:]:
        rows = [season(p) for p in sorted(glob.glob(f'{d}/*.diplomacy.jsonl'))]
        keys = ['pacts', 'kept', 'secret', 'betray_club_yr', 'surprise', 'reasoned']
        summary = {k: round(st.mean([r[k] for r in rows if k in r]), 3) for k in keys if any(k in r for r in rows)}
        print(d, f'seasons={len(rows)}', summary)


if __name__ == '__main__':
    main()
