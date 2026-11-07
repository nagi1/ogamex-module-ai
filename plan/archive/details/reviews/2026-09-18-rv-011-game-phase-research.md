# RV-011 — how real OGame players change behaviour by game phase (research)

Read-only desk research, 18 September 2026, to find a **named consumer** for game-phase
classification. Sources are live player guides and the OGame wiki; the two most phase-detailed
guides (OGames, Sidian) are speed-server/community guides whose "day 1" pacing is compressed, and
Reddit (403) and 2moons (404 — it is a code clone with no strategy docs) were unreachable.

| Phase | Timing / size indicator | Concrete behaviour | Source |
| --- | --- | --- | --- |
| Early opening | Day 1, 1 planet, mines ≈6-8/4-5/2-3 | ~100% of income into mines + solar; no shipyard, nanite or storage | [Ultimate Miner Guide](https://board.en.ogame.gameforge.com/index.php?thread/821043-updated-the-ultimate-miner-guide-v-2/) · [OGames week 1](https://ogames.net/blog/ogame-build-order-first-week) |
| Early defence | Days 1-3 | None — "the best defense early is being online"; rocket launchers only for banked resources | [OGames defence basics](https://ogames.net/blog/ogame-defense-basics-guide) |
| Early research | Day 1-2 | Energy 1 → Combustion → Espionage 2 → Computer 2 → Impulse 1; no combat tech | [Sidian getting started](https://sidian.app/s/ogame-wiki/guides/getting-started) |
| Week-1 fleet | Day 4-5, shipyard 1-2 | Probes, then **small cargos**, then 15-50 light fighters; heavy/cruiser/battleship skipped | [OGames first ships](https://ogames.net/blog/ogame-first-ships-guide) |
| Week-1 raiding | Same | **Grey/inactive, undefended** planets only | [OGames farming inactives](https://ogames.net/blog/ogame-farming-inactives) |
| Week-1 defence spend | End of week 1 | A few hundred rocket launchers max; do not trade mine upgrades for heavy defences | [OGames defence basics](https://ogames.net/blog/ogame-defense-basics-guide) |
| First colony | Week 2 (astro 1) | 2nd colony ~day 6-7; astro 3 → 2 colonies | [Sidian astrophysics](https://sidian.app/s/ogame-wiki/research/astrophysics) |
| Mid fleet | Weeks 3-4, 2-3 planets | ~100 LF, 10-20 large cargos, 5-10 recyclers, first cruiser; W/S/A 4-5 | [Sidian getting started](https://sidian.app/s/ogame-wiki/guides/getting-started) |
| Mid economy | 4-8 planets, mines 20s-30s | Growth lever moves from homeworld mines to **astro/colonies** | [Miner Guide §3](https://board.en.ogame.gameforge.com/index.php?thread/821043-updated-the-ultimate-miner-guide-v-2/) |
| Mid defence | Gauss unlock | Gauss cannons arrive "squarely in the mid-game"; shield domes always | [OGames defence basics](https://ogames.net/blog/ogame-defense-basics-guide) |
| Mid targets | Espionage 5+, 2 colonies | Inactives first, then **active players** as fleet/intel mature; ninja/phalanx risk appears | [OGames raiding guide](https://ogames.net/blog/ogame-raiding-guide) |
| First moons | Mid, once a fleet exists | Organised moonshots for phalanx + jump gate | [Fandom Moon](https://ogame.fandom.com/wiki/Moon) |
| Late economy | Astro 23+, mines ~40/32/36 | "After Astrophysics 23 the ROI is not good"; investment flips to **Death Stars** | [Miner Guide §3](https://board.en.ogame.gameforge.com/index.php?thread/821043-updated-the-ultimate-miner-guide-v-2/) |
| Late ships | Graviton unlock | Graviton (300k energy via solar satellites) → RIPs; turtles park RIPs behind fodder | [Fandom Graviton](https://ogame.fandom.com/wiki/Graviton_Technology) |
| Late identity | Old/high-mine accounts | Miner flips to Turtle or Fleeter | [Fandom Playing Styles](https://ogame.fandom.com/wiki/Playing_Styles) |

## Explicitly NOT phase-dependent

Profit gate (loot ≥3× deuterium, losses ≤20-30% of loot), espionage-before-attack, fleetsave
discipline, storage fill-time, the 6-attacks/24 h bashing limit, and "defence makes attacks
unprofitable" hold in **every** phase — these must not be conditioned on the phase.

## The three strongest phase-dependent behaviours

1. **Economy → fleet → capital-ship allocation flip.** Early it is ~all mines; mid buys light
   fighters/cruisers; only after astro 23 does the same resource go into RIPs, because mine
   marginal ROI falls below fleet returns.
2. **Defence goes none → Gauss → Plasma as *banked value* grows.** Week 1 has zero defence
   (being online *is* the defence); Gauss arrives mid; Plasma 7 is late — spending tracks both
   banked value and the threat class (cargos → cruisers → bombers/RIPs).
3. **Target class escalates: inactives → actives → fleets.** Each step needs fleet size, intel
   and moons the previous phase lacks.

## Recommendation for the row

Pick **#3 (target-class escalation)** as the consumer: `RaidPlanner` already chooses targets, so
the phase can gate *which class of target is eligible* (inactives early, actives once espionage
and fleet allow, fleet-crashing late) — a real, nameable behaviour, and a decision that already
exists rather than new machinery. #1 and #2 are second choices; #2 would touch defence planning
(no consumer today).

Gate 1 stays clean: the phase is derived from host reads (astrophysics level, planet count,
research-object fraction, own rank), never a hardcoded level.
