"""Strategies real players show in the OGMEM battle archive (public, anonymised; 599 reports).

    python3 ogmem_strategies.py OUT.json

Reports whose unit counts are round thousands are battle-simulator inputs, not fights, and are dropped. Prices are the
host's base prices (metal + crystal + deuterium). Output: fleet and defence templates by fleet-value tier (value
shares), the defender's posture (fleet at home or only defence), the attacker/defender value ratio real attacks start
at, and outcomes (losses, debris, loot) -- data for doctrine.json and for hds.py cells, not taste. Also `battles`
(one record per report, host ids, the shape the simulator emits) and `war_bands` for hds.py --war-cells (war_cells.py).
"""
import collections, json, pathlib, statistics, sys

SRC = pathlib.Path(__file__).resolve().parents[2] / "storage/rl/external/sources/OGMEM/normalized"
PRICE = {'Light Fighter': 4e3, 'Heavy Fighter': 1e4, 'Cruiser': 2.9e4, 'Battleship': 6e4, 'Battlecruiser': 7e4,
         'Bomber': 9e4, 'Destroyer': 1.25e5, 'Deathstar': 1e7, 'Reaper': 1.4e5, 'Pathfinder': 2.3e4, 'Small Cargo': 4e3,
         'Large Cargo': 1.2e4, 'Espionage Probe': 1e3, 'Solar Satellite': 2.5e3, 'Recycler': 1.8e4, 'Colony Ship': 4e4,
         'Crawler': 5e3, 'Rocket Launcher': 2e3, 'Light Laser': 2e3, 'Heavy Laser': 8e3, 'Gauss Cannon': 3.7e4,
         'Ion Cannon': 8e3, 'Plasma Turret': 1.3e5, 'Small Shield Dome': 2e4, 'Large Shield Dome': 1e5}
DEFENCE = {'Rocket Launcher', 'Light Laser', 'Heavy Laser', 'Gauss Cannon', 'Ion Cannon', 'Plasma Turret',
           'Small Shield Dome', 'Large Shield Dome'}
MACHINE = {name: name.lower().replace(' ', '_') for name in PRICE}
# Host object ids (app/GameObjects), the keys the simulator's records use.
ID = {'Small Cargo': 202, 'Large Cargo': 203, 'Light Fighter': 204, 'Heavy Fighter': 205, 'Cruiser': 206,
      'Battleship': 207, 'Colony Ship': 208, 'Recycler': 209, 'Espionage Probe': 210, 'Bomber': 211,
      'Solar Satellite': 212, 'Destroyer': 213, 'Deathstar': 214, 'Battlecruiser': 215, 'Crawler': 217, 'Reaper': 218,
      'Pathfinder': 219, 'Rocket Launcher': 401, 'Light Laser': 402, 'Heavy Laser': 403, 'Gauss Cannon': 404,
      'Ion Cannon': 405, 'Plasma Turret': 406, 'Small Shield Dome': 407, 'Large Shield Dome': 408}
TIERS = [(0, 1e8), (1e8, 1e9), (1e9, 1e10), (1e10, 1e11), (1e11, 1e14)]


def load():
    sides = collections.defaultdict(lambda: collections.defaultdict(int))
    for line in open(SRC / 'battle_unit.jsonl'):
        r = json.loads(line)
        if 'before' in r and r['unit'] in PRICE:
            sides[(r['report_id'], r['side'])][r['unit']] += r['before']
    battles = {json.loads(l)['report_id']: json.loads(l) for l in open(SRC / 'battle.jsonl')}
    simulated = {rid for (rid, _), units in sides.items() if any(n >= 1000 and n % 1000 == 0 for n in units.values())}
    return {k: v for k, v in sides.items() if k[0] not in simulated}, {k: v for k, v in battles.items() if k not in simulated}


def battle_records(sides, battles):
    """One record per report with an attacker, in the shape the simulator emits (`<season>.battles.jsonl`, see
    war_cells.py): units at the fight's start and units lost, per side, keyed by host id. Sorted by report id."""
    lost = collections.defaultdict(lambda: collections.defaultdict(int))
    for line in open(SRC / 'battle_unit.jsonl'):
        r = json.loads(line)
        if 'before' in r and r['unit'] in PRICE and r['report_id'] in battles:
            lost[(r['report_id'], r['side'])][r['unit']] += -(r.get('lost') or 0)
    ids = lambda units: {str(ID[u]): n for u, n in sorted(units.items(), key=lambda kv: ID[kv[0]]) if n}
    out = []
    for rid in sorted(battles, key=int):
        if not sides.get((rid, 'attacker')):
            continue
        out.append({'report': rid, 'mission': 'attack', 'attacker_units': ids(sides[(rid, 'attacker')]),
                    'defender_units': ids(sides.get((rid, 'defender'), {})),
                    'attacker_lost': ids(lost[(rid, 'attacker')]), 'defender_lost': ids(lost[(rid, 'defender')])})
    return out


def value(units, keep=lambda name: True):
    return sum(n * PRICE[u] for u, n in units.items() if keep(u))


def shares(group):
    total = collections.Counter()
    for units in group:
        v = value(units)
        for u, n in units.items():
            total[u] += n * PRICE[u] / v / len(group)
    return {MACHINE[u]: round(s, 3) for u, s in total.most_common() if s >= 0.005}


def main(out):
    sides, battles = load()
    fleet = lambda u: u not in DEFENCE and u != 'Solar Satellite'
    result = {'source': 'OGMEM', 'reports': len(battles), 'sides': len(sides), 'tiers': [], 'posture': {}, 'odds': {}, 'outcome': {}}
    for lo, hi in TIERS:
        att = [{u: n for u, n in s.items() if fleet(u)} for (rid, side), s in sides.items() if side == 'attacker' and lo <= value(s, fleet) < hi]
        dfl = [{u: n for u, n in s.items() if fleet(u)} for (rid, side), s in sides.items() if side == 'defender' and lo <= value(s, fleet) < hi]
        dfc = [{u: n for u, n in s.items() if u in DEFENCE} for (rid, side), s in sides.items() if side == 'defender' and lo <= value(s, lambda u: u in DEFENCE) < hi]
        result['tiers'].append({'value': [lo, hi], 'attackers': len(att), 'attack_fleet': shares(att) if att else {},
                                'defending_fleets': len(dfl), 'defence_fleet': shares(dfl) if dfl else {},
                                'walls': len(dfc), 'defence': shares(dfc) if dfc else {}})
    pairs = [(sides.get((rid, 'attacker'), {}), sides.get((rid, 'defender'), {})) for rid in battles]
    pairs = [(a, d) for a, d in pairs if a]
    no_fleet = sum(1 for a, d in pairs if value(d, fleet) == 0)
    no_def = sum(1 for a, d in pairs if value(d, lambda u: u in DEFENCE) == 0)
    sats = [d.get('Solar Satellite', 0) for a, d in pairs if d.get('Solar Satellite', 0)]
    result['posture'] = {'battles': len(pairs), 'defender_without_fleet': round(no_fleet / len(pairs), 3),
                         'defender_without_defence': round(no_def / len(pairs), 3),
                         'satellites_when_present_median': statistics.median(sats) if sats else 0,
                         'satellites_present_share': round(len(sats) / len(pairs), 3)}
    ratios = sorted(value(a) / max(1.0, value(d)) for a, d in pairs if value(d) > 0)
    q = lambda xs, p: xs[min(len(xs) - 1, int(p * len(xs)))]
    result['odds'] = {'attacker_over_defender_value': {'p10': round(q(ratios, .1), 2), 'p25': round(q(ratios, .25), 2), 'median': round(q(ratios, .5), 2), 'p75': round(q(ratios, .75), 2), 'p90': round(q(ratios, .9), 2)},
                      'against_empty_target': sum(1 for a, d in pairs if value(d) == 0)}
    rows = list(battles.values())
    lost_ratio = sorted(abs(b['attacker_losses']) / max(1, abs(b['defender_losses'])) for b in rows if b['defender_losses'])
    loot_share = sorted(b['loot'] / max(1, abs(b['defender_losses']) + b['loot']) for b in rows)
    result['outcome'] = {'attacker_loss_over_defender_loss': {'median': round(q(lost_ratio, .5), 3), 'p75': round(q(lost_ratio, .75), 3)},
                         'loot_share_of_gain': {'median': round(q(loot_share, .5), 3), 'p75': round(q(loot_share, .75), 3)},
                         'with_debris': round(sum(1 for b in rows if b.get('debris')) / len(rows), 3)}
    # Per-battle records and the war-cell bands hds.py --war-cells scores against (war_cells.py builds both ways).
    from war_cells import bands_of, collect
    result['battles'] = battle_records(sides, battles)
    result['war_bands'] = bands_of(collect(result['battles'], [b['defender_units'] for b in result['battles']]))
    json.dump(result, open(out, 'w'), indent=1)
    print(json.dumps({k: v for k, v in result.items() if k != 'battles'}, indent=1)[:8000])


if __name__ == '__main__':
    main(*sys.argv[1:])
