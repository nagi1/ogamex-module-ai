# Player research and design implications

Research date: 10 September 2026. This is a targeted qualitative review, not an exhaustive census of every browser-game subreddit or a representative player survey. Search covered OGame, PBBG, browsergames, incremental_games, MMORPG, StrategyGames and adjacent gaming communities. Useful evidence concentrated in the sources below; irrelevant recommendation/promotional results were excluded.

## Evidence quality

Forum posts describe habits and beliefs, not a definitive rules engine. Reddit contributors self-select; veteran/PvP voices may overrepresent intense play. Deleted posts and search-only excerpts have lower confidence. Do not infer player percentages, causal retention effects or universal strategies from this sample.

The supplied PBBG thread was readable, including its comments. Some OGame Reddit URLs returned 403 on direct open; their indexed excerpts were available. Those are explicitly marked below. No claim of reading inaccessible full conversations is made.

## Findings across years

| Source / period / access | Observation | Design implication — our inference |
|---|---|---|
| [Fleeting advice, official forum](https://board.en.ogame.gameforge.com/index.php?thread/690248-fleeting-advice/=), January 2012; full page | Players discuss moon coverage, phalanx risk, debris timing and consistent fleetsaving. | Preparation and return appointments belong in the earliest survival slice. Avoid a universally safe mission heuristic. |
| [Speed-universe fleetsaving discussion](https://board.en.ogame.gameforge.com/index.php?postID=6558759&thread%2F720559-how-to-fleetsave-in-speeduni-s-big-fleets%2F=), 2013-era; indexed excerpt | A player describes substantial deuterium expenditure for a large fleet. | Fuel reserves and universe speed alter feasible routines; strategy must include carrying costs. |
| [Bored high-level fleeters](https://www.reddit.com/r/OGame/comments/11f56ug/), March 2023; indexed excerpts, direct fetch blocked | A player describes repeated hunting on a relatively quiet server and changing fleetsave routines. | More aggressive accounts can worsen an empty universe; include pressure limits and strategic adaptation. |
| [Not sure what to do](https://www.reddit.com/r/OGame/comments/176yj0r/), October 2023; indexed excerpts, direct fetch blocked | A miner reports repeated attacks; replies discuss resource saving and seeking alliance help. | Supply-side and defensive roles must survive; alliances should sometimes provide practical assistance. |
| [Returning player questions](https://www.reddit.com/r/OGame/comments/1hztgga/), January 2025; indexed excerpts, direct fetch blocked | Advice mixes defense/fleet choices with class-dependent expeditions. | Separate enduring habits from current-version optimization; capability-gate classes and expeditions. |
| [OGame is dying?](https://www.reddit.com/r/OGame/comments/1p66355/ogame_is_dying/), late 2025; indexed excerpts, direct fetch blocked | Complaints about power gaps coexist with an account of enjoyable alliance life. | Preserve competition and social ties without manufacturing unbeatable economic advantages. |
| [Supplied PBBG thread](https://www.reddit.com/r/PBBG/comments/1wca3pb/what_actually_keeps_you_playing_a_pbbg_for_months/), displayed as 13h old when read; full page | Comments value progress, smaller unlocks, friendships, scheduled stakes and recoverability. Others prefer solo play or simple numerical growth. | Offer several styles of participation; memory-backed relationships complement progression but should not be mandatory. |
| [Returning players — how to get them?](https://www.reddit.com/r/PBBG/comments/1veezvw/returning_players_how_to_get_them/), displayed as about one month old; full page | Discussion distinguishes routine logins from caring about roles, goals and other players; one account describes timer/social burnout. | Measure meaningful interaction and post-loss return, alongside stress and opt-out feedback. |
| [When do you lose interest?](https://www.reddit.com/r/incremental_games/comments/11fgred/at_what_point_do_you_lose_interest_in_a_game/), March 2023; full page | Repetition without new decisions and frequent maintenance checks are cited as reasons to stop. | Add variety through evolving relationships and goals; do not require humans to answer constant AI activity. |
| [Browser strategy potential](https://www.reddit.com/r/StrategyGames/comments/1pey4dk/are_browser_strategy_games_dead_or_is_there_still/), December 2025; full page | The post raises UX, diplomacy and fairness; comments question lengthy setup before meaningful action. | Test how quickly new humans encounter an interesting, manageable interaction. Do not infer that all building timers should be shortened. |

These sources span early-2010s forum habits through recent discussions. They support continuity in fleetsaving, timing, risk and social ties, but not a complete history of OGame since launch.

## Lore and ruleset boundary

The [official game overview](https://gameforge.com/en-GB/games/ogame.html) anchors the space-empire setting. The [official fleetsave overview](https://gameforge.com/en-GB/games/ogame-fleetsave.html) emphasizes protecting fleets while absent. These inform vocabulary and motivations, not an independent implementation of rules.

The [OGameX README](https://github.com/lanedirt/OGameX) describes a pre-Lifeforms target and lists fleets, moons, alliances and battle-engine support. The target OGameX Next deployment is now [inspected locally](repository-inspection.md); use that baseline rather than generic upstream assumptions. Record the actual fork's classes, speeds, protections, trade rules, visibility, ACS and mission semantics before enabling matching behaviors. Community references to current monetization or Lifeforms do not belong in the module unless explicitly supported.

## Research to validation

Convert qualitative findings into hypotheses: varied opponents improve perceived activity; visible consequences make identities memorable; excessive pressure harms recovery. Test these in a disclosed pilot. Ask casual miners and quiet players as well as veterans to review traces. Collect volunteered/aggregate telemetry rather than reproducing real people's identities, messages or precise routines.

Next research iteration: recruit a small balanced reviewer group, annotate good/bad decisions with the configured universe rules and calibrate the proposed archetype mix. Keep disagreement in the fixtures; there is rarely one universally correct human move.
