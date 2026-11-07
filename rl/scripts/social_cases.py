#!/usr/bin/env python3
"""Build the social case base CBRKit's ``/social/similar`` retrieves from (SOCIAL-ENGINES-CONTRACT.md).

Source: the simulator's diplomacy logs, ``storage/rl/ogsim/runs/social/*/ready-*.jsonl.diplomacy.jsonl`` (one season
each; events war, peace, pact, pact_refused, betrayal, leak, submit, revolt, ... with hour, by, with, cause; by/with
are clubs).

A case is (situation before a club's decision -> the decision -> what happened between the two clubs over the next
HORIZON_DAYS). The situation holds only what the deciding club could know: the public record (wars and pacts open,
public pact breaks, leaked secret breaks) and its own dealings with the other club, including the ordered history of
that relationship. Secret pacts and secret betrayals of third parties stay hidden until they leak.

Decisions: pact (offered and accepted), pact_refused, war, betrayal, peace, submit, revolt.
Outcomes:
- pact: betrayed (by whom, after how many days) or kept
- pact_refused: war between the two or no_war
- war / betrayal / revolt: peace, submit or ongoing (and the days it took)
- peace: war_again or held
- submit: revolt or held
Each outcome also carries ``future``: the pair's events over the horizon, so a caller sees what followed.
An outcome whose horizon runs past the end of the season is marked ``censored``.

  python3 social_cases.py                       write Modules/AI/docker/cognition/cbrkit/social_cases.json
  python3 social_cases.py --out x.json --horizon-days 21
"""

import argparse
import glob
import json
from collections import defaultdict
from pathlib import Path

ROOT = Path(__file__).resolve().parents[4]
SOURCE = ROOT / 'storage/rl/ogsim/runs/social'
OUT = ROOT / 'Modules/AI/docker/cognition/cbrkit/social_cases.json'

DECISIONS = ('pact', 'pact_refused', 'war', 'betrayal', 'peace', 'submit', 'revolt')
PAIR_KINDS = ('war', 'peace', 'pact', 'pact_refused', 'betrayal', 'submit', 'revolt', 'leak')
HISTORY_LENGTH = 4
NO_EVENT = 'none'
DAYS_CAP = 60.0


def pair(a, b):
    return (a, b) if a <= b else (b, a)


def load(path):
    events = []
    with open(path) as fh:
        for line in fh:
            e = json.loads(line)
            if 'by' in e and 'hour' in e:
                events.append(e)
    events.sort(key=lambda e: e['hour'])  # stable: same-hour events keep their logged order
    return events


class Record:
    """What is known before an event: open stances, public breaks, and each pair's own history."""

    def __init__(self):
        self.wars = set()
        self.pacts = {}             # pair -> secret?
        self.vassal = {}            # vassal -> overlord
        self.broken = defaultdict(int)       # public pact breaks per club (public betrayal, or a leaked secret one)
        self.history = defaultdict(list)     # pair -> [(hour, kind, by)]

    def open_wars(self, c):
        return sum(1 for p in self.wars if c in p)

    def open_pacts(self, c, viewer):
        # A secret pact is known to its two parties only.
        return sum(1 for p, secret in self.pacts.items() if c in p and (not secret or viewer in p))

    def apply(self, e):
        k, a, b = e['kind'], e['by'], e['with']
        p = pair(a, b)
        if k in ('war', 'revolt'):
            self.wars.add(p)
            self.pacts.pop(p, None)
            if k == 'revolt':
                self.vassal.pop(a, None)
        elif k == 'betrayal':
            self.wars.add(p)
            self.pacts.pop(p, None)
            if e['cause'] != 'secret':
                self.broken[a] += 1
        elif k == 'peace':
            self.wars.discard(p)
        elif k == 'pact':
            self.pacts[p] = e['cause'] == 'secret'
        elif k == 'submit':
            self.wars.discard(p)
            self.vassal[a] = b
        elif k == 'leak' and e['cause'] == 'betrayal':
            # by = victim, with = breaker: the secret break becomes public.
            self.broken[b] += 1
        if k in PAIR_KINDS:
            self.history[p].append((e['hour'], k, a))

    def situation(self, e):
        k, a, b = e['kind'], e['by'], e['with']
        p = pair(a, b)
        past = self.history[p]
        mark = lambda kind, by: f'{kind}{"+" if by == a else "-"}'   # + by the decider, - by the other club
        recent = [mark(kind, by) for _, kind, by in past[-HISTORY_LENGTH:]]
        last = past[-1][0] if past else None
        return {
            'decision': k,
            'cause': e['cause'],
            'day': round(e['hour'] / 24.0, 2),
            'my_broken': self.broken[a],
            'their_broken': self.broken[b],
            'my_wars': self.open_wars(a),
            'their_wars': self.open_wars(b),
            'my_pacts': self.open_pacts(a, a),
            'their_pacts': self.open_pacts(b, a),
            'pair_wars': sum(1 for _, kind, _ in past if kind in ('war', 'revolt')),
            'pair_pacts': sum(1 for _, kind, _ in past if kind == 'pact'),
            'pair_betrayals': sum(1 for _, kind, _ in past if kind == 'betrayal'),
            'pair_peaces': sum(1 for _, kind, _ in past if kind == 'peace'),
            'days_since_pair': DAYS_CAP if last is None else round(min(DAYS_CAP, (e['hour'] - last) / 24.0), 2),
            'history': [NO_EVENT] * (HISTORY_LENGTH - len(recent)) + recent,
        }


def outcome(e, later, horizon_hours, season_end):
    """What happened between the two clubs within the horizon after decision ``e``."""
    k, a, b = e['kind'], e['by'], e['with']
    p = pair(a, b)
    future = [x for x in later if pair(x['by'], x['with']) == p and x['kind'] in PAIR_KINDS
              and x['hour'] - e['hour'] <= horizon_hours]
    first = lambda kinds: next((x for x in future if x['kind'] in kinds), None)
    days = lambda x: round((x['hour'] - e['hour']) / 24.0, 2)
    result = {}
    if k == 'pact':
        x = first(('betrayal',))
        result = ({'label': 'betrayed', 'betrayer': 'offerer' if x['by'] == a else 'partner', 'days': days(x)}
                  if x else {'label': 'kept'})
    elif k == 'pact_refused':
        x = first(('war', 'betrayal', 'revolt'))
        result = {'label': 'war', 'days': days(x)} if x else {'label': 'no_war'}
    elif k in ('war', 'betrayal', 'revolt'):
        x = first(('peace', 'submit'))
        result = ({'label': 'peace' if x['kind'] == 'peace' else 'submit', 'days': days(x)} if x
                  else {'label': 'ongoing'})
        if x and x['kind'] == 'submit':
            result['submitted'] = 'decider' if x['by'] == a else 'other'
    elif k == 'peace':
        x = first(('war', 'betrayal', 'revolt'))
        result = {'label': 'war_again', 'days': days(x)} if x else {'label': 'held'}
    elif k == 'submit':
        x = first(('revolt',))
        result = {'label': 'revolt', 'days': days(x)} if x else {'label': 'held'}
    resolved = 'days' in result
    result['censored'] = not resolved and e['hour'] + horizon_hours > season_end
    result['future'] = [{'day': days(x), 'kind': x['kind'], 'by': 'decider' if x['by'] == a else 'other',
                         'cause': x['cause']} for x in future]
    return result


def build(paths, horizon_days):
    horizon_hours = horizon_days * 24.0
    cases = {}
    for path in paths:
        events = load(path)
        if not events:
            continue
        season_end = events[-1]['hour']
        rel = Path(path).relative_to(SOURCE)
        season = f'{rel.parent}/{rel.name.split(".")[0]}'
        record = Record()
        for i, e in enumerate(events):
            if e['kind'] in DECISIONS:
                cases[f'{season}#{i}'] = {'situation': record.situation(e),
                                          'outcome': outcome(e, events[i + 1:], horizon_hours, season_end)}
            record.apply(e)
    return cases


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument('--source', default=str(SOURCE / '*/ready-*.jsonl.diplomacy.jsonl'))
    ap.add_argument('--out', default=str(OUT))
    ap.add_argument('--horizon-days', type=float, default=14.0)
    args = ap.parse_args()
    paths = sorted(glob.glob(args.source))
    cases = build(paths, args.horizon_days)
    labels = defaultdict(lambda: defaultdict(int))
    for case in cases.values():
        labels[case['situation']['decision']][case['outcome']['label']] += 1
    base = {'version': 1, 'horizon_days': args.horizon_days, 'history_length': HISTORY_LENGTH,
            'seasons': len(paths), 'cases': cases}
    Path(args.out).write_text(json.dumps(base, sort_keys=True, separators=(',', ':')) + '\n')
    print(f'{len(cases)} cases from {len(paths)} seasons -> {args.out}')
    for decision, counts in sorted(labels.items()):
        print(f'  {decision:13s} ' + ' '.join(f'{k}={v}' for k, v in sorted(counts.items())))


if __name__ == '__main__':
    main()
