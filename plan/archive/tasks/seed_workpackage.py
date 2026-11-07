#!/usr/bin/env python3
"""One-off: seed the work-package / repo-plan / half-wired-loop tasks into tasks.db.

After this, run `python3 plan/tasks/dump_seed.py` to back-port the rows into seed.sql.
Codes are new (never reused), so INSERT OR REPLACE is safe. Run once; idempotent per code.
"""
import os
import sqlite3

DB = os.path.join(os.path.dirname(os.path.abspath(__file__)), "tasks.db")
con = sqlite3.connect(DB)

# (code, title, kind, priority, gap_ref, file_ref, notes)
WP = [
 ("WP-001", "Debris-field recycling loop", "impl", "P1", None,
  "app/Domain/Decision/QueueableRecyclePlanner.php; app/Actions/QueueAiRecycleAction.php; app/Contracts/QueueAiRecycle.php; app/Enums/AiCandidateActionType.php; app/Enums/AiWorkKind.php; app/Domain/Decision/CandidateActionFactory.php; app/Providers/AIServiceProvider.php",
  "New planner scans neighbourhood host DebrisField rows above a module loot threshold and dispatches recyclers via host RecycleMission; also harvests slot-16 debris an own expedition leaves. Skips fields already covered by an in-flight recycle. Host-read recycler/pathfinder classification via ObjectService (gate 1). Sources: jaesivsm-pyogame, janeczkins-xbot, kweimann-cruiser, ogame-ninja-scripts, r4fek-ogame-bot, trilogi77-ogamebot."),
 ("WP-002", "Raid estimate — P20 loot quantile, delete expectedLoot()", "impl", "P1", None,
  "app/Domain/Raid/RaidEstimate.php; app/Infrastructure/Battle/NativeRaidEstimator.php; app/Domain/Decision/RaidPlanner.php",
  "Carry the host engine's authoritative P20 loot (cargo-constrained, fill-ordered) out of the existing 50-run stream into clearsLootTier(); delete the hand-rolled min(metalEq x fraction, cargo) scalar so host fill order is the single loot authority. Sources: jstar88-opbe, klaasvp-trashsim."),
 ("WP-003", "Raid estimate — survival floor (win/loss/draw + fleet-loss rate)", "impl", "P1", None,
  "app/Domain/Raid/RaidEstimate.php; app/Infrastructure/Battle/NativeRaidEstimator.php; app/Domain/Decision/RaidPlanner.php",
  "Read attackerUnitsResult/defenderUnitsResult per sampled run; carry pWin (survived) and a fleet-loss rate next to p20NetProfit/losingRuns; RaidPlanner refuses above a persona threshold so a coin-flip that profits never flies. Sources: maximalcode-combat-sim, klaasvp-trashsim, peterradzisz-fleet-optimizer."),
 ("WP-004", "Fleetsave destination safety", "impl", "P1", None,
  "app/Domain/Decision/QueueableFleetSavePlanner.php; app/Domain/Perception/PlayerObservationService.php",
  "Exclude own bodies whose planet_id appears in the inbound picture before the moon/distance sort; prefer a same-coordinate planet-moon (distance-5) jump the phalanx cannot observe. Sources: halfguru-ogamebot, kweimann-cruiser."),
 ("WP-005", "Single-planet fleetsave fallback (harvest-save)", "impl", "P1", None,
  "app/Domain/Decision/QueueableFleetSavePlanner.php; app/Actions/QueueAiFleetSaveAction.php",
  "When no second own body / no moon exists, save to a debris field with recyclers on a harvest mission instead of doing nothing. Source: ogame-tbot-tbot."),
 ("WP-006", "Weak-attack / nothing-to-save fleetsave gate", "impl", "P1", None,
  "app/Domain/Decision/QueueableFleetSavePlanner.php; app/Domain/Decision/SaveFailurePolicy.php; app/Domain/Perception/PlayerObservationService.php",
  "Skip the reactive save when the local fleet is below the persona exposure band or the inbound is a probe-only / trivial fraction of the parked fleet; reuse the band proactivePlan() already applies, no new constant. Sources: ogame-tbot-tbot, janeczkins-xbot."),
 ("WP-007", "Storage-before-build preprocessor", "impl", "P1", None,
  "app/Domain/Decision/EconomyUpgrades.php; app/Domain/Decision/QueueableBuildingPlanner.php",
  "When a build price exceeds the planet current storage, prepend the cheapest storage upgrade for the blocking resource before queueing the build. Source: racinmat-phpogamebot."),
 ("WP-008", "Standing defence pass", "impl", "P2", None,
  "app/Domain/Decision/QueueableUnitPlanner.php; app/Domain/Decision/Policies/TurtlePolicy.php",
  "Turtle/miner personas keep a baseline defence at a persona-scaled ratio of fleet value (host-read getDefenseObjects()), not only reactively under underAttack. Sources: ogame-ninja-scripts, piecepapercode-barakis, trilogi77-ogamebot."),
 ("WP-009", "Expedition fleet composition + system rotation", "impl", "P2", None,
  "app/Domain/Decision/QueueableExpeditionPlanner.php; app/Domain/Decision/QueueableExpedition.php; app/Actions/QueueAiExpeditionAction.php",
  "Dispatch a real expedition fleet — strongest combat hull (host attack), fastest civil hull as pathfinder (host speed), smallest cargo, a probe — and rotate the origin system after N outgoing expeditions. Sources: ogame-infinity-web-extension, trilogi77-ogamebot."),
 ("WP-010", "Colony slot scoring (+ abandon)", "impl", "P2", None,
  "app/Domain/Decision/QueueableColonyPlanner.php",
  "Rank empty slots by host-reported fields and temperature (mid/large first, seeded walk), not the first empty slot; abandon-and-recolonise a below-band colony only once the host abandon seam is verified. Sources: halfguru-ogamebot, trilogi77-ogamebot, janeczkins-xbot."),
 ("WP-011", "Recall scheduling at half-duration / absence-aware save speed", "impl", "P2", None,
  "app/Domain/Decision/QueueableFleetSavePlanner.php; app/Actions/ScheduleAiIntentAction.php",
  "Schedule the recall at ~half the deployment duration (per-account jitter) instead of an arbitrary later session; pick a save speed whose flight lands >= the upcoming absence. Sources: ogame-tbot-tbot, trilogi77-ogamebot."),
 ("WP-012", "Surplus consolidation transfer", "impl", "P2", None,
  "app/Domain/Decision/QueueableTransferPlanner.php; app/Domain/Decision/ReserveFloor.php",
  "When a planet nears its storage cap, sweep the above-floor surplus to the best-developed body (keep a deuterium floor on moons), the reverse direction of the need-driven ferry. Sources: janeczkins-xbot, ogame-ninja-scripts."),
 ("WP-013", "Defenceless-target intel signal + confidence downgrade", "impl", "P2", None,
  "app/Domain/Perception/PlayerObservationService.php; app/Domain/Perception/ActivityIntelReader.php; app/Domain/Decision/RaidPlanner.php",
  "Flag a report whose fleet and defence sections are present-and-empty ([] vs null) as defenceless so the raid gate can short-circuit the estimator; downgrade published confidence when sections are missing, never raise on null. Source: alaingilbert-ogame."),
 ("WP-014", "Probe escalation + closest spy origin", "impl", "P2", None,
  "app/Domain/Decision/QueueableSpyPlanner.php; app/Domain/Decision/QueueableSpy.php; app/Actions/QueueAiSpyAction.php",
  "Escalate probe count (bounded 1-5) for defended/known-rich targets whose last report is partial; pick the closest own planet with an idle probe, not the first in collection order. Sources: racinmat-phpogamebot, ogame-tbot-tbot."),
 ("WP-015", "Raid outcome feedback (cooldown + blacklist + real-loot attribution)", "impl", "P2", None,
  "app/Domain/Decision/RaidPlanner.php; app/Actions/ExecuteAiIntentAction.php; app/Domain/Experience/",
  "Skip a target hit within a cooldown window and blacklist one that underperformed across >=3 real raids; record post-raid loot through the experience rules so the P20 screen is re-weighted by what actually landed. Source: trilogi77-ogamebot."),
 ("WP-016", "Message a new attacker", "impl", "P2", None,
  "app/Actions/RunAiConversationCycleAction.php; app/Actions/BuildAuthoredSocialReplyAction.php",
  "On a hostile inbound, send one casual authored line to the attacker, bounded once per distinct attacker, reusing the authored-dialogue protocol. Source: ogame-ninja-scripts."),
 ("WP-017", "Liveness floor under next-due time", "impl", "P2", None,
  "app/Domain/Scheduling/SessionDecisionService.php; app/Domain/Routine/SessionPlanner.php; config/population.php",
  "Clamp nextDueAt to a configurable max idleness so a long persona-shaped absence cannot trip host inactivity cleanup; keyed off explicit AiProfile identity. Confirm the host purge threshold first or this is YAGNI. Source: ogame-opensource-2-ai."),
 ("WP-018", "Rare no-op idle override + anti-bot threshold self-check", "impl", "P2", None,
  "app/Domain/Decision/DecisionEngine.php",
  "After scoring, a small seeded, skill-band-aware draw selects DoNothing even when real actions exist (never when fleetsaveEligible/reactionWakeAt set); plus a regression test asserting departures stay under the host anti-bot thresholds. Source: hammermaps-ogamex-ai-players."),
 ("WP-019", "P3 investigate list (phalanx, moonshot, jump gate, ...)", "discovery", "P3", None,
  "plan/details/research/repos/WORK-PACKAGE.md",
  "Phalanx fleet scan, moonshot, jump-gate transfer, metal-dump research, archetype->character-class affinity, game-phase classification, consultation confidence gate + horizon, solar-satellite energy fallback, defended-raid debris valuation, pre-flight round-trip duration, CRN screen->confirm ladder. Each gated on a confirmed host seam."),
]

HL = [
 ("HL-001", "SaveResources capability is dead (declared, scored, never produced)", "impl", "P2", "W9-1",
  "app/Enums/AiCapability.php; app/Enums/AiCandidateActionType.php; app/Domain/Perception/PlayerObservationService.php; app/Actions/ScheduleAiIntentAction.php; app/Domain/Decision/Policies/MinerPolicy.php; app/Domain/Decision/Policies/TraderPolicy.php; app/Domain/Decision/Policies/TurtlePolicy.php",
  "Either publish a real hoard-for-next-step intent and execute it, or delete the SaveResources enum case, the three policy weights and the => null branch. Gate 2/3."),
 ("HL-002", "recovery score term is always 0", "impl", "P2", "W9-2",
  "app/Domain/Decision/UtilityScorer.php; app/Domain/Perception/PerceptionSnapshot.php; app/Infrastructure/Perception/PlayerPerceptionBuilder.php; app/Domain/Perception/PlayerObservationService.php",
  "Publish recovery_factor from an existing signal, or delete RECOVERY_WEIGHT, the recovery term and the recoveryFactor snapshot field. Gate 2."),
 ("HL-003", "attack_permitted is hardcoded true (legality never checked)", "impl", "P2", "W9-3",
  "app/Domain/Perception/PlayerObservationService.php; app/Domain/Decision/CandidateActionFactory.php; app/Domain/Decision/RaidPlanner.php",
  "Compute attack_permitted from the host legality answer at report time, or add a legality check to RaidPlanner::plan(); stop publishing a constant true. Gate 3."),
 ("HL-004", "losingRuns is counted and carried but never consumed", "impl", "P2", "W9-4",
  "app/Infrastructure/Battle/NativeRaidEstimator.php; app/Domain/Raid/RaidEstimate.php; app/Domain/Decision/RaidPlanner.php",
  "Add a losing-run threshold to the profit gate (refuse when a majority of the screen loses), or delete losingRuns from RaidEstimate. Gate 2."),
]

RP_SLUGS = [
 "alaingilbert-ogame", "eracle-ogamebot", "halfguru-ogamebot", "hammermaps-ogamex-ai-players",
 "jaesivsm-pyogame", "janeczkins-xbot", "jstar88-ogame-algorithms", "jstar88-opbe",
 "klaasvp-trashsim", "kweimann-cruiser", "maximalcode-combat-sim", "ogame-infinity-web-extension",
 "ogame-ninja-scripts", "ogame-opensource-2-ai", "ogame-tbot-tbot", "patrykstefanski-og-battle-engine",
 "peterradzisz-fleet-optimizer", "piecepapercode-barakis", "r4fek-ogame-bot", "racinmat-phpogamebot",
 "rbardtke-ogamex-combat-sim", "shinigallo-ogame-agi", "trilogi77-ogamebot",
]

DISC = [
 ("DISC-008", "Synthesize LLM-usage research into adoptable work items", "discovery", "P2", None,
  "plan/details/research/repos/llm-ogame-ai.md",
  "Distill prompting / context-management / model-choice / cost-control ideas into WP tasks. Only 3 repos genuinely call an LLM (ogame-agi Gemini, DotAgent kimi/llama-3.1, OGame Commander Qwen2.5-VL-72B); all route through OpenAI-compatible endpoints at cheap models, force a closed JSON action vocabulary, feed summarized snapshots (never raw DOM) and fall back to deterministic code on any error."),
 ("DISC-009", "Synthesize PvE research into adoptable work items", "discovery", "P2", None,
  "plan/details/research/repos/pve-concepts.md",
  "Distill transferable PvE ideas into the PvE empire spec: AI faction racing the same objective (Travian Natars), phased escalation ladder (EVE Triglavian), influence bar gating the climax + completion-gated rewards (EVE Incursions), succession state machine (Stellaris Marauders/Horde), outcome-scaled difficulty (Stellaris Crises)."),
]

rows = list(WP) + list(HL) + list(DISC)
for i, slug in enumerate(RP_SLUGS, 1):
    rows.append((
        "RP-%03d" % i,
        "Execute repo plan: %s" % slug,
        "impl", "P2", None,
        "plan/details/research/repos/plans/%s.plan.md" % slug,
        "Execute this repo's plan: adopt ADOPT-IDEA/ENHANCE rows that pass gates 1-3, skip REFUSE rows (see WORK-PACKAGE cross-cutting refusals). Shared mechanisms are already tracked as WP-* tasks — only repo-specific items here.",
    ))

for code, title, kind, priority, gap, file_ref, notes in rows:
    con.execute(
        "INSERT OR REPLACE INTO tasks (code,title,kind,status,priority,gap_ref,principle_refs,algorithm_ref,file_ref,notes) "
        "VALUES (?,?,?,?,?,?,?,?,?,?)",
        (code, title, kind, "todo", priority, gap, None, None, file_ref, notes))
con.commit()
print("seeded %d tasks" % len(rows))
