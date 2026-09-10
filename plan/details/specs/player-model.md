# Player model

Owner: gameplay. Evidence and historical caveats: [player research](../research/player-research.md).

## Model habits across player types

Use a persistent profile plus slowly changing state. Sample correlated traits once; do not reroll personality each decision. These example shares form a test population, not a measured distribution of OGame players.

| Archetype | Initial share | Typical priorities and limitations |
|---|---:|---|
| Routine miner | 30% | Mines, colony logistics, cargo/resource saves; avoids expensive feuds. |
| Casual mixed player | 25% | Growth and occasional raids; inconsistent sessions and unfinished goals. |
| Opportunistic raider | 15% | Nearby profitable targets, probes and recyclers; declines uncertain long flights. |
| Committed fleeter | 10% | Fleet readiness, moons, timing and selective simulations; limited attention, not continuous surveillance. |
| Defensive builder | 10% | Security and predictable growth; may overinvest in defense without treating it as invulnerability. |
| Social supplier | 10% | Deuterium/logistics and repeat partners where trade is supported; selective diplomacy. |

Skill, sociability, language, timezone and account age are separate dimensions. Include learners, veterans with little free time, quiet experts and returning players. A miner can be highly skilled. Avoid tying competence or aggression to nationality, gender or language.

Profile fields: playstyle weights, experience, risk aversion, patience, ambition, loyalty, sociability, planning horizon, routine, writing style and PRNG seed. Dynamic fields: goal, stress, available attention, losses, recovery stage and relationship beliefs.

## Routine and imperfect attention

Use a semi-Markov routine: offline, quick check, ordinary session, extended operation, busy period, vacation/inactive. State durations depend on day/time and previous activity. Illustrative sessions: quick checks 2–6 minutes; ordinary sessions 10–25; occasional longer operations. Fit these using pilot observations, not Reddit anecdotes.

Schedule availability before inspecting hidden threats. Generate correlated daily variation, occasional missed sessions and planned appointments for the account's own fleet returns. Store UTC deadlines and an IANA timezone; test daylight-saving boundaries. Human availability stays in wall time even when universe speeds change.

An incoming fleet becomes actionable only when a permitted view or genuinely available notification exposes it. A server event can enqueue bookkeeping while leaving the next observation time unchanged. Offline accounts may lose fleets. Active accounts have a sampled observation delay plus action delay; noticing a threat does not guarantee resources, slots or time to escape.

Predictable enough to learn; variable enough to feel alive. Keep the selected routine seed stable for replays and private from opponents. Avoid synchronized starts, identical login intervals and identical text.

## Goals and state changes

Keep one primary medium-term goal and a few obligations. Examples: second colony, cargo capacity for nightly collection, a research prerequisite, a safer fleetsave route or replacement recyclers. Goal hysteresis prevents switching after every small score change.

After fleet loss: assess actual loss relative to personal economy → cancel infeasible plans → secure cargo/fuel → rebuild income → rebuild selected capabilities → cautiously resume. Some accounts take breaks or remain miners. No free replacements.

Relationship dimensions are trust, threat, affinity and debt. A profitable attack need not mean hatred; a fulfilled agreement changes trust more than friendly wording. Stale intel reduces confidence rather than creating random false facts.

## OGame culture and fiction

Use game-grounded commander identities, consistent planet naming and restrained jargon: fleetsave/FS, miner, fleeter, turtle, deut, debris/DF, moonshot, phalanx/lanx, ninja, ACS, good luck rebuilding/GL rebuild. “No fleetsave, no fleet” expresses the culture's emphasis on preparation. Terminology can differ by community.

The science-fiction setting supports industrial colonies, research, fleets and alliances; do not invent canonical factions or lore bonuses. ACS, moons, return timing and expedition references must match enabled capabilities. Cultural knowledge comes from [research](../research/player-research.md); exact mechanics come from the fork.
