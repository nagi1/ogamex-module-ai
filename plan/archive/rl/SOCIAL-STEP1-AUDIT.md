# Social cognition, step 1: what real data measures, and the gap (6 Nov 2026)

Goal (owner-approved 6 Nov): a social player **indistinguishable from humans** in alliance structure and movement
(social bands inside HDS), and **stronger** through its social choices (join, lead, war, peace, betrayal), judged by
strength and survival against a population that includes betrayers, using **visible information only**.

Scripts (scratchpad, not yet in the repo): `social_bands.py` (PUB), `social_sim.py` (ogsim day rows).

## 1. What each source can measure

| Phenomenon | Source | Measurable? |
| --- | --- | --- |
| Alliance sizes, largest alliance, points concentration, top-10 split, rank-1 dominance | PUB (267 universes; 43 speed-8 with ≥30 active players) | yes, cross-section by universe age |
| Alliance founding over time | PUB `found_date` | yes (founded late vs early) |
| Recruitment open/closed | PUB `open` | yes |
| Join / leave / defection rates | PUB has **one snapshot per universe** → no | **only via OStats `memberHistory`** (joined/left per alliance, last 30 days; verified live on s278-en and s1-en). Not collected yet |
| Who fights whom (war between alliances, intra-alliance attack) | OGMem raw HTML titles carry the tags | yes, but a biased sample (599 large showcased battles) |
| NAP, pacts, betrayal, conspiracy | not in any public data | **no**: forum corpus as a prior only, never as a band |
| Founder leaves | PUB `founder_id` | not informative (always 1.0; the founder cannot leave without handing over) |

## 2. Real bands vs the simulator

Real: speed 8, universe age 128-256 days (n=8; p10 / p50 / p90). Simulator: `err4-side/noquit` (the owner's
everyone-plays reference world, 64 accounts, 10 seeds), day 100 (rank-1 numbers at day 200 are the same). Real
universes have a median of 36-100 active players, so 64 accounts is a comparable scale.

| Measure | Real p10 / p50 / p90 | Sim median | Gap |
| --- | --- | --- | --- |
| Share of active players in an alliance | 0.57 / 0.69 / 0.81 | 0.63 | ok |
| Largest alliance / active players | 0.13 / **0.18** / 0.24 | **0.63** | ×3.5 |
| Alliances with ≥3 members per 100 active | 5.3 / **5.7** / 9.8 | **1.6** (one) | one alliance |
| Top alliance's share of points | 0.30 / 0.49 / 0.64 | 0.84 | high |
| Distinct sides in the top 10 | 2.7 / **4** / 6 | 2 | low |
| Rank-1 share of points | 0.14 / **0.20** / 0.23 (512+ d: 0.29) | **0.055** | ×4 |
| Rank-1 / rank-10 points | 3.1 / **5.5** / 13.6 | **1.18** | ×5 |
| Rank-1 inside the top alliance | mostly yes | yes | ok |
| Big alliances founded in the second half of the universe's life | 0 / 0 / 0.29 (512+ d: 0.36) | none | no new alliances |
| Top-5 alliances open to applications | 0.8 | n/a | — |

`war-mix` / `war-all` (`noalliance`): no alliances at all, rank-1 share 0.02-0.07, r1/r10 1.1-1.4.

OGMem (295 parseable battles): 64% both sides tagged (alliance vs alliance), 9% only attacker tagged, 24% no tags,
**0% same tag on both sides**, 4% several attackers (ACS).

## 3. Reading

1. The sim's social structure is one blob: one alliance holds 63% of the players and 84% of the points. Real worlds
   have about 6 clubs per 100 players, the largest about 18%.
2. **The largest social gap is dominance, not diplomacy**: rank-1 holds 5.5% of the points (real 20%), and r1/r10 is
   1.18 (real 5.5). This is the same gap as the owner's grinding-war-world goal.
3. Caution: dominance may be mostly economic (personas too alike, growth too even), not social. A richer alliance
   structure alone will not prove that it closes the gap. That needs the ablation in step 3.
4. Joins, leaves and defection can't be measured today. The only source is OStats `memberHistory`.

## 4. Open decisions (owner)

- **OStats collection.** About 38k alliances (or the top 20 per universe, about 9k requests). The site's robots.txt says
  `Content-Signal: ai-train=no, use=reference`. We would use the data as aggregate target statistics, not as training
  content, but that is the owner's call.
- The small n per age bucket (3-20 universes) means these are bands for a gate, not precise targets.

## 5. Next (step 2 → 3)

- Add the measures above (largest alliance share, clubs per 100, top-10 sides, rank-1 share, r1/r10) to the HDS gap
  list beside the binary `alliance` measure, so they rank against every other gap.
- Step 3 ablation (≤15 min): an arm with N alliances sized from the real distribution, no diplomacy. Does structure
  alone move rank-1 share and r1/r10? If not, the dominance gap is economic or military, and social cognition waits.
