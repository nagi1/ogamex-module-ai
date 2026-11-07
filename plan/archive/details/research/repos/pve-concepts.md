# PvE concept harvesting — external games and OGame ecosystem

Read 17 September 2026. Collected to enrich the PvE "empire" mode in
[`plan/details/specs/pve-empire.md`](../specs/pve-empire.md), which already ships a
coalition-vs-AI campaign loop, a director, objectives and contribution records. This file
catalogs PvE structures from shipped games and the OGame ecosystem, one source per section,
and closes with the ideas worth porting. "Fetched" means page content was pulled this session;
"documented, page unreachable at fetch time" means the canonical wiki is cited from
established game mechanics but the page returned an error when fetched.

Target context recap: the empire mode reuses the module's accounts, routines, economy, combat
and scheduler; it only adds coalition membership, a campaign coordinator, objective/contribution
records and a campaign page. Host reads keep object universe, prices and requirements as the
source of truth (gate 1); mechanisms must be nameable as ordinary experienced-player play
(gate 3); smallest mechanism wins (gate 2). Ideas below are scored against that.

---

## 1. EVE Online — Incursions (Sansha's Nation)

- **Source / URL:** https://wiki.eveuniversity.org/Incursions (fetched).
- **Concept:** A nomadic NPC faction (Sansha's Nation) sieges a constellation. Players in
  *existing* corporations/alliances coordinate fleets to clear escalating sites and finally kill
  a mothership.
- **Structure:**
  - **Objectives:** staged site tiers — Vanguard → Assault → Headquarters — capped fleet sizes so
    every member matters; the kill of the **mothership** is the decisive objective that ends the
    incursion and is the *only* condition that pays out loyalty points (LP).
  - **Progression:** an "influence" bar falls as players run sites; when influence hits 0 the
    mothership becomes killable. The incursion is a finite, measured **tug-of-war** (Established →
    Mobilized → Withdrawing), not an open-ended war.
  - **Rewards:** bounty ISK per site plus CONCORD LP — LP is withheld unless the mothership dies
    before the incursion expires, tying reward to campaign completion, not participation.
  - **AI behaviour:** constellation-wide debuffs (resist, damage, bounty penalties) that scale
    with Sansha influence and hit everyone, plus normal NPC spawns replaced by much stronger
    faction rats — the presence of the threat degrades ordinary play in the area.
  - **Social structure:** nomadic community fleets that stay out of formal alliances so they
    cannot be war-declared; public channels, shared fits, defined fleet roles.
- **Transferable:** (a) a campaign "influence/momentum" bar that gates the final decisive
  objective and must be zeroed before the climax; (b) reward withheld unless the *campaign*
  objective completes, not per-hit; (c) a scaling constellation-wide penalty that makes the
  threat's presence felt on normal activity; (d) persistent participant identities that
  cooperate without needing to join one shared alliance.

## 2. EVE Online — Triglavian Invasion (Chapter 3)

- **Source / URL:** https://wiki.eveuniversity.org/Triglavian_Invasion (fetched).
- **Concept:** A server-wide scripted invasion where players pick **either side** (defend the
  empires as EDENCOM, or aid the NPC Triglavian Collective) and push systems through a phased
  tug-of-war that permanently reshaped the map (the captured systems became the Pochven region).
- **Structure:**
  - **Objectives:** kill opposing-side NPCs to move a system along a symmetric ladder
    (`Final Liminality ← … ← Stellar Reconnaissance → Redoubts/Bulwarks → EDENCOM Fortress`).
  - **Progression:** phase escalation at 75% thresholds with new, harder site tiers and stronger
    roaming fleets; the final phase is irreversible — a system fully captured by the NPC side
    stays captured (a permanent, authored map change).
  - **Rewards:** ISK, loyalty points (only for the defending side), salvage, and **faction
    standings** that swing symmetrically as you kill the opposite faction; standings later
    gate access to the captured region's stations/gates.
  - **AI behaviour:** roaming NPC fleets on gates/belts/station, tiered naming (Scout/Normal/
    Elite/Officer) with distinct roles (tackle, neut, ECM), system-wide structure effects, and
    escalating patrol strength as a side nears victory.
  - **Social structure:** player coalitions self-organized under banners per side; the "war"
    was a two-sided community effort, not one faction vs everyone.
- **Transferable:** (a) a *ladder of phases* with visible progress bars and 75% escalation
  thresholds — objectives are "push the system/planet to the next phase", not "kill one big HP
  bar"; (b) **sided PvE** where supporting the defender (or the invader) is an explicit choice
  with symmetric standing effects; (c) irreversible campaign outcomes that persist into later
  campaigns (captured territory / lost systems carry forward); (d) tiered enemy role variety
  (tackle/neut/ECM analogues → in OGame, different fleet compositions per stronghold).

## 3. Stellaris — Crises

- **Source / URL:** https://stellaris.paradoxwikis.com/Crisis (fetched).
- **Concept:** Scripted midgame and endgame **crisis factions** that attack all empires
  indiscriminately in a total-war fashion; the galaxy is expected to unite against them.
- **Structure:**
  - **Objectives:** implicit — stop the crisis before it swallows the galaxy; a **Situation Log**
    tracks casualties on both sides and "how close the crisis is to being defeated".
  - **Progression:** spawn is **weighted and time-gated** (endgame year, escalating weights with
    elapsed time, tech triggers like jump drives); "all-crisis" setting doubles each next crisis's
    power, and defeating a crisis *fast* (within 5–10 years) makes the next even stronger —
    difficulty is tuned by how quickly the players win.
  - **Rewards:** +10% happiness for 10 years and a large Unity payout on defeat; the "Threat"
    mechanic makes empires like each other more while a crisis is active.
  - **AI behaviour:** crisis factions are removed from diplomacy, cannot be negotiated with,
    absorb or destroy territory as they expand, and trigger the galactic community's
    "Galactic Focus" resolution — the threat itself creates the cooperation.
  - **Social structure:** Fallen Empires can awaken as "Guardians of the Galaxy" to fight *alongside*
    the players, and the community passes a joint resolution to fight the crisis.
- **Transferable:** (a) a **situation/campaign log** that publishes objective progress and both
  sides' losses, not just win/lose; (b) difficulty scaled by *outcome quality* (faster wins make
  the next campaign harder) rather than by hidden buffs mid-flight; (c) a shared threat that
  *produces* coalition cohesion (the current spec's coalition is pre-formed; a crisis gives it a
  reason); (d) an optional AI ally (a "guardian" faction) that fights with the coalition.

## 4. Stellaris — Fallen Empires

- **Source / URL:** https://stellaris.paradoxwikis.com/Fallen_empire (fetched).
- **Concept:** Dormant, overwhelmingly strong NPC empires that stay passive unless provoked,
  then "awaken" into an expansionist threat or a guardian of the galaxy.
- **Structure:**
  - **Objectives:** optional — their **tasks** are one-shot missions ("attack this rival",
    "colonize this planet", "outlaw purges") with opinion and gift rewards; **demands** are
    ultimatums with a casus belli on refusal.
  - **Progression:** **awakening** triggers on thresholds (a normal empire's fleet power passes
    70k, or a fallen-empire world is conquered); awakening grants fleets, fills storages and
    unlocks expansion civics.
  - **Rewards:** gifts (resources, tech, ships) keyed to attitude and task completion; **Decadence**
    then decays an awakened empire over 20+ years — a self-balancing ceiling on the threat.
  - **AI behaviour:** distinct personalities per ethic (Enigmatic Observers, Militant
    Isolationists, Holy Guardians, Keepers of Knowledge, Ancient Caretakers) with different
    demands, tolerance and gifts; a **War in Heaven** pits two awakened empires against each
    other with normal empires choosing sides or a neutral "League".
  - **Social structure:** the neutral "League of Non-Aligned Powers" — a coalition of empires
    that refuse both sides.
- **Transferable:** (a) an NPC opponent with a **personality profile** (aggressive raider,
  isolationist, hoarder) that changes *which* objectives it issues and how it reacts to
  provocation — maps cleanly onto the module's existing 6 AI profiles; (b) **dormant → awakened**
  escalation as a campaign-state machine instead of a flat difficulty curve; (c) a
  **decadence/fatigue** counter so an NPC empire that wins too much self-nerfs; (d) optional
  **tasks/demands** from the AI faction as a social lane separate from combat objectives.

## 5. Stellaris — Marauders and the Great Khan (Horde)

- **Source / URL:** https://stellaris.paradoxwikis.com/Marauders (fetched).
- **Concept:** A midgame threat that starts as static raider NPCs, then can unify under a
  "Great Khan" into an expansionist crisis empire that players can fight or submit to.
- **Structure:**
  - **Objectives:** none formal — players either pay tribute, repel raids, or (midgame) destroy
    the marauder home systems.
  - **Progression:** tribute/raid cycle (pay or be raided; other players can **pay the marauders
    to raid a rival**); after the midgame year a Great Khan may unify them into the **Horde**,
    a full expansionist crisis with subjugation ("Satrapy") as an alternative to war.
  - **Rewards:** hired **mercenary fleets** (fixed composition, 5-year contract, no naval
    capacity) and hired commanders; killing the Khan's second fleet yields a relic.
  - **AI behaviour:** raiding fleets scale with game year; the Khan personally leads a named
    fleet and survives its first destruction; on the Khan's death the Horde **splits or reforms**
    (Khanate successor, civil war into 2–4 empires, or back to marauders) based on how much
    territory it held — a scripted succession, not a despawn.
  - **Social structure:** empires can submit (Satrapy), stay independent, or hire the raiders
    as mercenaries.
- **Transferable:** (a) a **succession/aftermath state machine** for the AI empire — when its
  leader is beaten, it fragments, reforms, or retreats depending on territory held, instead of
  simply despawning; (b) **tribute vs. repel** as a non-combat resolution option; (c)
  **hireable AI fleets** as a reward (in OGame terms, limited-duration ACS/transport support or
  a one-off reinforcement, not minted resources); (d) an NPC threat whose raids scale with
  elapsed time — a published escalation schedule the coalition can plan around.

## 6. Travian — Natars and the World Wonder

- **Source / URL:** https://support.travian.com/en/articles/103-world-wonder (fetched).
- **Concept:** A permanent NPC faction (the Natars) holds special endgame villages; winning the
  server is a **race** to build a World Wonder to level 100, against both other players and the
  Natars' own Wonder.
- **Structure:**
  - **Objectives:** conquer Natar-held World Wonder villages (8 outside the centre with +50%
    defenders, 6 in the centre, plus the unconquerable Natar capital at 0|0), then build a
    Wonder to 100.
  - **Progression:** the **Natars build their own Wonder on a schedule**; if they reach 75, all
    their forces are recalled to defend it — an explicit escalation point that changes their
    behaviour; server ends when either side hits 100.
  - **Rewards:** the conquered WW villages themselves (with special rules: no gold, half build
    time, 50% crop consumption, unconquerable after level 1).
  - **AI behaviour:** the NPC faction is an *active competitor on the same objective*, not just
    a defender — it can win the race and end the server.
  - **Social structure:** a Wonder is too expensive for one player; alliances/confederacies
    organize logistics, defense and resource supply — the objective *forces* cooperation.
- **Transferable:** (a) an AI faction that **competes on the same objective** (it builds/advances
  its own goal on a published schedule, and winning is possible for the AI) — the strongest
  "stakes" idea found; (b) an escalation threshold (level 75) that visibly changes AI behaviour
  (recall-to-defend); (c) a campaign win condition shared by both sides rather than a fixed
  deadline; (d) objectives anchored on **conquerable special sites** rather than arbitrary
  targets.

## 7. Ikariam — Abyssal / Winter & New Year's Ambush

- **Source / URL:** https://forum.ikariam.gameforge.com/forum/thread/113219-winter-and-new-years-ambush/ (fetched).
- **Concept:** A recurring, seasonal shared-monster event where the whole server piles damage
  onto sea monsters that respawn stronger each round.
- **Structure:**
  - **Objectives:** attack map monsters; each round the monsters have **randomized
    strengths/weaknesses against unit types** (shown in the UI before you commit, tuned from
    60–120% to 80–120% after feedback).
  - **Progression:** monsters respawn "stronger than ever" after defeat; a "stages reached"
    counter is tracked per server; event runs on a fixed calendar window.
  - **Rewards:** resources **scaled to damage dealt, never negative** (based on what you invested
    in units), paid over several days to protect server performance; achievements/titles for
    participation thresholds (50/150/300 times, top-10/top-5/rank-1); top-3 get premium item
    packs and a visible town icon; an **Alliance tab** shows total alliance damage.
  - **AI behaviour:** no counterattack — the monsters are damage sponges; the event's friction
    is coordination and unit choice, not survival.
  - **Social structure:** leaderboard split by player and alliance; self-reported no, everything
    is server-tracked damage.
- **Transferable:** (a) a **seasonal/calendar event lane** parallel to the persistent campaign —
    the module could run a bounded, announced "monster/raid" window between campaigns;
    (b) damage-proportional, never-negative rewards keyed to what the player actually committed;
    (c) **achievement/title** recognition for participation thresholds and ranks (titles, not a
    second economy); (d) an alliance-level contribution tab on the campaign board.

## 8. OGame ecosystem — hammermaps/OGameX-AI-Players (existing substrate)

- **Source / URL:** https://github.com/hammermaps/OGameX-AI-Players (documented in
  `hammermaps-ogamex-ai-players.md`; fetched from repo docs).
- **Concept:** A from-scratch OGame clone fork adding bot accounts driven by a daemon, with 6
  strategy profiles, per-player difficulty, sleep windows and action-priority weights.
- **Structure:**
  - **Objectives:** none — it populates a server with believable AI accounts.
  - **Progression:** per-player `difficulty_level`, `priority_building/research/fleet` weights,
    randomized 60–300 s action spacing, per-player sleep window, 5% idle-skip at low difficulty.
  - **Rewards:** none.
  - **AI behaviour:** profiles (`aggressive|neutral|defensive|miner|raider|turtle`) resolve
    strategy via a match; domain actions gated by weight dice; labelled `' [ AI ]'`, not hidden.
  - **Social structure:** none; the AI is a population feature.
- **Transferable:** this is the *substrate* the empire mode already reuses. Its directly useful
  pieces for a PvE faction: per-account strategy profiles and difficulty, sleep/uptime windows,
  and action-priority weights — a per-account **difficulty profile** is how the campaign
  coordinator should differentiate stronghold defenders, not a single faction-wide buff.

## 9. OGame ecosystem — Origin forum PvE mission concept (archived)

- **Source / URL:** https://board.origin.ogame.gameforge.com/index.php/Thread/10997-Concept-PVE-Mission-Extra-new-stuff/ (indexed excerpts; page now redirects).
- **Concept:** An archived player suggestion for pirate-outpost PvE missions in OGame.
- **Structure:** proposal-level only; no shipped mechanic.
- **Transferable:** already catalogued in `pve-precedents.md` as *not* evidence of a shipped or
  loved feature. It shows appetite for a pirate-raid lane but carries no transferable mechanics
  beyond what the empire mode already proposes.

## 10. Aurora 4X — NPRs and spoilers (documented, page unreachable at fetch time)

- **Source / URL:** https://aurorawiki.pentarch.org/index.php?title=Spoilers (and
  `Non-Player_Race`); the wiki returned 525/404 this session — this section rests on the game's
  well-established, long-shipped mechanics rather than a fresh page read.
- **Concept:** A single-player 4X where the galaxy is populated by **NPRs** (full autonomous AI
  races with their own economy, expansion and diplomacy) plus scripted **spoiler** threats.
- **Structure:**
  - **Objectives:** emergent — discover and survive/contain spoilers; NPRs are played as
    ordinary rivals, not special enemies.
  - **Progression:** spoilers have distinct escalation archetypes — **Precursors** guard ruin
    systems and are largely static; **Invaders** establish a beachhead and launch escalating
    assaults on the player; the **Swarm** self-replicates exponentially and strips systems if
    left unchecked.
  - **Rewards:** salvage/ruins from clearing spoiler systems.
  - **AI behaviour:** the three spoiler archetypes differ along a key axis — *static defender*
    (Precursor), *active aggressor that escalates* (Invader), *snowballing ecosystem* (Swarm) —
    and NPRs are indistinguishable in kind from the player, differing only in behaviour.
  - **Social structure:** NPR diplomacy is optional; spoilers cannot be negotiated with.
- **Transferable:** (a) give the AI empire **multiple threat archetypes** that escalate
  differently — a static defender, an escalating aggressor, and a snowballing presence — so
  campaigns can vary their shape without new machinery; (b) spoilers are *non-negotiable* while
  NPRs are *ordinary rivals* — a clean split the module can reuse (a PvE faction is either a
  campaign antagonist or just a populated rival, not both at once).

---

## Transferable ideas

Distilled, and checked against the three gates and the lazy-senior discipline. Everything here
is achievable with the existing engine (accounts, routines, combat, scheduler) plus the campaign
layer already specified.

1. **AI faction competes on the same objective** (Travian Natars). The single highest-value idea:
   the AI empire advances its *own* goal on a published schedule and can win. Replaces "deadline
   or survive" with a real race and is fully expressible as a campaign objective with a progress
   counter. Cheap: one more objective type (`advance_to N before the faction does`).
2. **Phased campaign ladder with escalation thresholds** (EVE Triglavian, Stellaris). Replace a
   flat objective set with a small state machine — e.g. `scout → weaken strongholds → final
   stronghold` — where crossing a threshold (like Travian's level-75 recall) visibly changes
   enemy behaviour. Objectives already exist; the ladder is just ordering plus a phase counter.
3. **Influence/momentum bar gating the climax** (EVE Incursions). A campaign-wide progress value
   that must be zeroed (or filled) before the decisive objective unlocks, and reward is withheld
   unless the campaign completes. One counter + one condition on objective acceptance.
4. **Outcome-scaled difficulty** (Stellaris all-crisis). The next campaign's difficulty is set
   from the previous campaign's *outcome quality* (fast win → harder next), never mid-flight
   buffs. Fits the spec's existing "pre-campaign difficulty profile" rule.
5. **Succession / aftermath state machine** (Stellaris Horde). When the AI empire's leader or
   capital objective is beaten, the faction fragments, reforms, or retreats depending on
   territory held — instead of despawning. Reuses persistent identities; one branching rule.
6. **Per-account difficulty profiles for defenders** (OGameX-AI-Players). Differentiate enemy
   strongholds with the existing per-account profile/difficulty/sleep knobs, not a faction-wide
   buff. No new mechanism — the knobs already exist.
7. **Optional non-combat resolutions** (Stellaris Marauders, Fallen Empires). Tribute/repel and
   task/demand lanes give small accounts a non-fleet contribution and give the director a social
   channel. Only if a later slice wants a social lane; not part of the first campaign.
8. **Contribution-shaped rewards, never negative, plus titles** (Ikariam Ambush). Rewards keyed
   to what was actually committed (supply delivered, damage dealt), paid after the campaign, with
   achievement/title recognition — the spec already forbids minted resources and a damage
   leaderboard; titles and verified-contribution credit fit within that.
9. **A situation/campaign log** (Stellaris Situation Log). Publish objective progress and both
   sides' losses on the module campaign page — cheap and matches the "cheap to read" review-loop
   requirement (structured, counters aggregated at write time).
10. **Threat presence degrades normal play in the contested area** (EVE Incursions). A scaling
    penalty on systems near the AI faction makes the threat felt between objectives. Optional;
    only if a cost-benefit shows it adds stakes without adding a parallel economy.
11. **Seasonal event lane between campaigns** (Ikariam). A bounded, announced raid window on a
    calendar, separate from the persistent campaign. Lower priority — it is new scope and the
    persistent campaign should ship first.
12. **Sided PvE** (EVE Triglavian). Let coalitions *choose* to aid or resist the AI faction with
    symmetric standing effects. Speculative — only consider after the single-coalition campaign
    is proven; it doubles the social model.

**Not ported, deliberately:** monster health bars and damage-based economies (Ikariam), jump
drives/star transmutation (EVE), and full autonomous NPR populations (Aurora) — each would be a
second mechanism or a second economy the gates and the spec already exclude.
