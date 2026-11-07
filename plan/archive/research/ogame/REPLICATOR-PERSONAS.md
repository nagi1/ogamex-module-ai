# Replicator check of the sim's personas (T2 / E2, 5 Nov)

Diagnostic only, no sim change. Script: `Modules/AI/rl/scripts/replicator.py` (read-only on its inputs).

    .venv-rl/bin/python Modules/AI/rl/scripts/replicator.py storage/rl/ogsim/runs/late/spy-a storage/rl/ogsim/runs/late/err-a --save out.json

## Inputs
- Sim: `storage/rl/ogsim/runs/late/spy-a/` and `err-a/`. Each has `ready-1..10.jsonl` (64 accounts, 256 days), `.battles.jsonl`.
  Personas are fixed at 40/30/30 (`ogsim/src/season.rs` `PERSONAS`, giving 26/19/19 of 64). Battle loot is weighted 1 : 1.5 : 2,
  which matches the sim's `loot_value` exactly. Colony and moon defender slots (>= 64) are mapped to accounts through the daily
  `loot_out`: 16 slots were mapped, 0 unmapped, and 0% of loot was left unattributed.
- Real: `storage/rl/ogsim/real-bands.json`, the file hds.py scores against (PUB highscore types 0-3 from
  `Modules/AI/storage/rl/external/sources/PUB-*`, 267 universes, speed 8). The `sample` vectors per cell are used, with
  n = top10/mid/bottom of 122/434/113 at 0-14, 87/220/170 at 64-128 and 163/274/231 at 128-256. Real battles come from
  `Modules/AI/storage/rl/external/sources/OGMEM/normalized/battle.jsonl` (599 reports) and
  `storage/rl/ogsim/r8w/ogmem-strategies.json`. Per-player attack counts are not public. OSTATS `score_point.jsonl` holds
  only total score (score_type 0), so no real raider/saver split is possible.
- Behaviour rule, the same for sim and real, is the highscore military share, cut at the midpoints of the personas' own targets
  (miner 0.06, saver 0.20, raider 0.30): eco < 0.13 <= mid < 0.25 <= mil. Mapping eco/mid/mil to miner/saver/raider is
  an assumption. Only eco vs military is really observable.

## Results (spy-a; err-a agrees to within ±0.1 ln and ±3 pp)
Payoff is the mean change in ln(total score) per account. Order is miner/raider/saver.

| phase | payoff m/r/s | loot / own production (raider) | expedition / production (m/r/s) | replicator rest point |
|---|---|---|---|---|
| 1-14 | 6.35 / 5.13 / 6.24 | 0.04 | 1.9 / 0.7 / 2.4 | all miner (miner and saver nearly neutral) |
| 14-64 | 3.54 / 3.79 / 3.53 | 0.21 | 7.8 / 8.8 / 9.3 | all raider (bootstrap p50 1.0, p10 0) |
| 64-128 | 0.66 / 0.71 / 0.64 | 0.03 | 3.0 / 2.9 / 2.9 | all raider (bootstrap p10 0.71 in err-a) |
| 128-256 | 0.54 / 0.50 / 0.46 | 0.02 | 2.7 / 2.0 / 2.4 | all miner |
| season | 11.09 / 10.13 / 10.87 | | | **miner (miner ≈ saver)** |

- Who raids whom: only raiders attack. Miners and savers made 0-3 attacks out of about 92k (spy-a) and 500k (err-a)
  fights. Raiders hit all three personas roughly in proportion to their numbers. The net loot plus ships-destroyed flow is
  at most a few % of what an account acquires, so A[i][j] is almost constant along each row. The game is close to
  frequency-independent: the stable mix is just whichever persona has the best intrinsic growth in that phase, and the
  miner and saver rows match in every column except the raider one (a zero eigenvalue, neutral drift between them).
  Every rest point is pure. There is no interior ESS.
- 99% of sim fights destroy nothing on the defender (1.1% have defender losses). In OGMEM, 99% of reports have defender
  losses, and the loot share is 0.077 against the sim's 1.0. OGMEM is biased toward published crashes, so this is weak evidence.

### The three mixes (military-share behaviour, eco/mid/mil; sim label mix in brackets, active accounts, hds banding)

| cell | sim actual (label m/r/s) | sim behaviour | sim stable (replicator) | real behaviour |
|---|---|---|---|---|
| top10 0-14 | .60/.00/.40 | .08/.37/.55 | miner | .02/.02/.95 |
| mid 0-14 | .58/.02/.41 | .21/.57/.22 | miner | .44/.18/.38 |
| bottom 0-14 | .22/.62/.16 | .26/.54/.20 | miner | .98/.00/.02 |
| top10 64-128 | .41/.01/.58 | .00/.03/.97 | raider | .10/.24/.66 |
| mid 64-128 | .45/.31/.24 | .00/.02/.98 | raider | .23/.24/.53 |
| bottom 64-128 | .00/.93/.07 | .00/.00/1.00 | raider | .46/.18/.36 |
| top10 128-256 | .54/.00/.46 | .00/.08/.92 | miner | .13/.26/.61 |
| mid 128-256 | .42/.32/.27 | .00/.11/.89 | miner | .22/.28/.50 |
| bottom 128-256 | .00/1.00/.00 | .00/.02/.98 | miner | .42/.23/.35 |

Confusion, persona to behaviour (e/m/M): miner .03/.05/.93, raider .01/.06/.93, saver .00/.21/.79. All three personas
look the same from outside.

## Top 3 discrepancies and what to check
1. **No economy players after day 14 (sim 0% eco against real 10-46%).** Every persona converges to a military share of
   about 0.30 (days 128/256, miner p50 0.31, target 0.06). Real p50 is 0.29/0.25/0.17 for top10/mid/bottom at 128-256, and
   the real p10 is 0.11/0.05/0.00 (real-bands.json cells `*|128-256`). Suspect: the miner's `military_share` target does not
   bind. The yard cap (`cap`, row 73 in players.rs) is in the late arm list (`runs/late/ablate.sh`), so the excess most
   likely comes from ships the yard did not buy: expedition finds, Escort expeditions (`Expedition/Escort` chosen 40,853
   times in `spy-a/ready-1.jsonl.errands.json`) and the doctrine template. Check: split each miner's fleet value into
   built vs found, and confirm which arms these runs actually used.
   Moving the cut to 0.08 or 0.18 keeps the gap: real eco share at 128-256 becomes 8-20% / 14-35% / 35-51%
   (top10/mid/bottom), while sim miners' p10 is still 0.27.
2. **Raiding hardly pays, and expeditions swamp everything.** Raider loot is 2-4% of its own mine production (21% only in
   days 14-64). Expedition income is 2-9x mine production for every persona. As a result, raider/saver interaction cannot
   shape the mix: A is frequency-independent, so there is no mixed ESS. Real OGame is believed to have one. A raider/miner
   balance is the premise of item 5, but no source here measures it directly. Suspect: expedition yield or frequency is too
   high (no real share of expedition income exists in our data, so this cannot be verified, only flagged). Check: re-run this
   script on a season with expeditions capped. If the flows then matter, M starts to bend A.
3. **The raider persona is a handicap that sorts it to the bottom.** Raiders lose 1.2 ln in days 1-14 (expedition/production
   0.7 against 1.9 for miners, and the early fighters and probes do not pay back: loot is 4% of production) and never catch
   up. They make up 62-100% of the sim's active bottom band and 0-3% of top10. In real data, military-heavy players are 61-66%
   of top10 and 35-36% of the bottom (64-256). Early on, the real military class grows fastest: g50 for mid 0-14 is
   6/39/479 (eco/mid/mil) and for top10 0-14 is 9.5k/20.9k/10.1k (real-bands.json). Suspect: the raider's early build
   (`Raider::new`: fighters 30, eager, military_share 0.30 from day 1) plus a low raid yield on fleetless farms. Check: the
   raider's day-14 spend split and its raid profit per flight hour against the miner's expedition profit per flight hour.

## How sure
- High: the flow matrix is tiny, so the game is near frequency-independent (direct counts, both runs agree). Personas are
  indistinguishable by military share (confusion matrix). Raiders sit in the bottom band.
- Medium: real eco share by band (n 87-434 per cell, one classifier cut). Moving the cut ±0.05 changes the numbers but not
  the sign of the gap.
- Low: per-phase stable mixes in phases 1-3 (bootstrap p10-p90 spans 0-1, since the payoff gaps are 0.02-0.25 ln against
  seed noise). The raider vs saver split on real data cannot be observed. The OGMEM comparison has selection bias.
