# OGameX war simulator handoff — 4 November 2026

## Current direction

Build a faithful Rust simulator of what the OGameX host and AI module allow, prioritizing late-game wars between distinct alliances and strong opponents. The desired tactics include reconnaissance, coordinated mixed-fleet attacks, moon destruction and defense, fleet saves, counterattacks and recycling. Do not reward loopholes or treat point farming as proof of strong play. Report controlled tests, organic simulation, host parity and live gameplay as separate levels of evidence.

The ten-minute continuation automation was deleted at the owner request. Do not recreate or resume it without a new request.

## Owner constraints

- No simulation, training or other individual run longer than 10 minutes. Use the existing Modules/AI/rl/scripts/capped.sh wrapper and timeout 540; CPU and RAM stay at or below the configured 85 percent cap. Neural training uses the 4070 at cuda:1 with CUDA_DEVICE_ORDER=PCI_BUS_ID.
- Root cause before tuning. Use observable information and proven methods; do not train or evaluate on hidden simulator information. Every player should ultimately play the whole game, with late game first.
- Keep updates concise and minimize token/context usage: redirect verbose command output to logs and inspect only short targeted excerpts. Do not delete user recordings, data, run artifacts or large directories without approval. Do not shut down WSL or stop unrelated workloads.
- Follow the repository and Modules/AI/AGENTS.md rules and the RL training skill. Run the required Composer chain after code changes, preserve host and module work, do not modify dependency locks, and commit only explicitly named files if a commit is requested.

## Workspace and current changes

Host checkout: /home/nagi/code/ogamex-next. AI module: /home/nagi/code/ogamex-next/Modules/AI. Both have pre-existing uncommitted work. Inspect both git statuses before editing; do not reset, clean, stash or overwrite unrelated work. The host has a pre-existing modified .gitignore and untracked storage and venv data.

Current uncommitted module changes include the war plan and errand spec; Rust simulator players, world, strike, errands, season and late-bench files; and Python policy export and training scripts. These changes were not committed. Read the latest entries in plan/rl/STRONG-PLAYER-PLAN.md and plan/rl/errand-spec.md before continuing. This handoff supplements, rather than replaces, plan/rl/STRONG-PLAYER-HANDOFF.md and the dated log.

## What was implemented

1. Experimental distinct alliances use alliances=N, preserving the older one-alliance scenario when the option is absent. Membership controls ACS, moon-shot assistance and ally targeting. The round-robin assignment is a test assumption, not observed alliance formation.
2. The war bench reports sightings, report depth, attacks, debris, starting-leader involvement, coordination requests, acceptances, launches and ACS joins. The leader is fixed at the benchmark start. Counts include ordinary raids and moon shots, and do not by themselves identify a war or link a leader fight to a coordinated attack.
3. Raiders scout the strongest active non-allied ranked account and its moon, can seek additional information from a partial report, and use bounded follow-up probes. If ships are hidden, only the top public non-allied rival gets an exploratory follow-up subject to an estimated risk budget. Probe losses and counter-espionage still use the world rules. The four-to-32-probe escalation, prior reported counter chance and one-hour production budget are explicit approximations, not verified human statistics.
4. The opt-in war scenario supports time-limited ally requests and acceptance at separate simulated logins, followed by a leader launch and a normal ACS join. It uses a rough three-round strength estimate and a prior report. Acceptance is not a guaranteed later dispatch; timing, changed fleets and refusals can prevent a join, and the leader does not yet cancel automatically when support fails.
5. Reports on enemy moons and colonies can be used for coordinated attack planning. The selected target is derived from the report and public owner rank, not current enemy fleet state.
6. Allied fleets can defend a moon against a destruction mission, and a scripted ally can dispatch that defense. A surviving allied fleet is kept distinct from the moon owner. Direct host parity with ACS moon defense remains unverified, including a host return-mission lifecycle ambiguity documented in the plan.
7. A fleet saver now notices normal attacks aimed at its moon; an attack on the parent planet is not mistaken for a moon attack. A gate escape no longer schedules a recall of an unrelated fleet.
8. The moon-destruction policy now uses a recent spy report for enemy fleet, defense, technology and reported phalanx, instead of current hidden target state. Remaining possible hidden-information leaks require a broader audit.
9. The latest step removes live enemy research from raid sizing, cargo ACS assistance, missile-target evaluation and the errand threat feature. When research is visible, use the report. When it is not, use the observer own research as an explicit estimate. This is uncertain and may underestimate a stronger opponent; actual battles still use actual host rules.

## Feature and model compatibility

The errand threat feature changed semantics and is now named target_ratio_observed_or_estimated, feature version 2. Existing errand labels, checkpoints and policies were generated with a hidden-information feature and must not be mixed with version 2 or treated as validated. Economy policies use a separate schema. The trainer now records feature names, rejects incompatible errand warm starts, rejects old or unversioned errand exports, and requires version-2 sidecars for raw champion errand labels. The Rust season runner rejects old or unversioned errand policy JSON. The teacher path remains available.

The local Python ogsim extension was rebuilt in the existing RL venv with maturin, offline and locked, after confirming no related training process was running. The previous shared object is backed up at /tmp/ogame-war11-ogsim-before.so while that temporary file survives. A Python world smoke check reported the new feature name and successfully returned observations. The training and export schema guards passed focused synthetic checks, but an end-to-end retrain and the new raw-label sidecar writer have not been exercised. No model was retrained in this step.

## Evidence and limits

- A seeded controlled encounter exercised request, acceptance, attack, ACS join and battle, including a separate target-moon case. These are controlled simulations, not proof of realistic autonomous alliance war.
- Before the latest research-information correction, one seed from day 120 to day 150 took 98 seconds and recorded 29 full leader reports, two coordinated launches and one ACS join arrival. Two fights involved the starting leader, but the counters do not prove that those fights were the coordinated attacks. Different policies changed the world from day zero; these runs are not a controlled strength comparison.
- The latest hidden-research change passed two Rust tests: changing unreported enemy research no longer changes the raid forecast or errand inputs, while changing reported research does. A Rust schema test rejected old and unversioned errand policies. Python synthetic checks rejected incompatible checkpoints and labels and accepted current-version examples. The release Rust examples built successfully.
- The moon-focused suite previously passed 12 tests, including 300 recorded host moon-destruction cases without allied defense. A direct host-versus-simulator replay for ACS moon destruction is still required.
- No full late-game benchmark has yet been run after the latest research-information and schema changes. The previously observed 98-second run still had information leaks and must not be presented as a fair final benchmark.
- Rector and CS completed; unrelated automatic PHP formatting changes were reverted. PHPStan reported eight pre-existing host errors. The required parallel, bail-enabled targeted PHP test could not connect to MySQL. Do not claim the full PHP chain passed. No active task processes remained at handoff.

## Recommended next work

1. Review the pending diff and validate the new feature schema end to end, including a small versioned champion-errand label file and a safe rejection of old data. Rebuild any external Python binding only when no related training workload is active.
2. Audit remaining hidden-state access. In particular, allied defense and moon-shot planning inspect ally state, while raid diagnostics inspect target live state for post-hoc measurements. Distinguish genuinely shared information, public information and instrumentation from policy inputs. Check rules and loss accounting against the host.
3. Run a capped, reproducible late-game benchmark after the latest changes and record report depth, raid/ACS outcomes, fleet losses, actual attacker and defender identities, and timing. Attribute leader fights to actual fleets before claiming a boss was attacked by an alliance. Compare multiple seeds and note when changed actions produce different world trajectories.
4. Profile representative war scenarios before optimizing. The 98-second and 59-second runs had different behaviors and do not prove a speed change. Retain no-run-over-ten-minutes and resource caps.
5. Add cancellation and failure handling when a promised ally cannot join, validate moon-defense lifecycle against the real host, and progressively cover the remaining host and AI capabilities without adding reward exploits.

## Useful paths

- Simulator: Modules/AI/rl/ogsim; benchmark: Modules/AI/rl/ogsim/examples/late.rs.
- World and mission handling: Modules/AI/rl/ogsim/src/world.rs and src/world/strikes.rs.
- Player tactics and coordination: Modules/AI/rl/ogsim/src/players.rs; errand inputs: src/errands.rs; season runner: src/season.rs and examples/season.rs.
- Versioned export/training: Modules/AI/rl/ogrl/export_policy.py, train_bc_world.py, train_exit.py and train_world.py.
- Catalogue and old run assets: /home/nagi/code/ogamex-next/storage/rl/ogsim/r8w and /home/nagi/code/ogamex-next/storage/rl/ogsim/runs/train4. Preserve them.
- Local verification logs for the latest step: /tmp/ogame-war11last-rust.log, /tmp/ogame-war11-python-schema2.log, /tmp/ogame-war11-python-world2.log, /tmp/ogame-war11last-build.log, /tmp/ogame-war11last-stan.log and /tmp/ogame-war11last-php.log. Temporary logs can disappear after reboot.

## Status, 4 November 2026 (schema validation)

Recommended work item 1 is done: the version-2 feature schema was driven end to end on real artifacts, which
exposed and fixed a broken champion-errand label reader. See `plan/rl/STRONG-PLAYER-PLAN.md` →
"Feature-schema validation end to end". Items 2-5 are open. Nothing was committed.
