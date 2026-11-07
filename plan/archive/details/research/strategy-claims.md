# Strategy claims — atomic evidence layer

Created 15 September 2026 by [strategy mining](../specs/strategy-mining.md). This file closes the
pipeline stage the earlier passes compressed: `source → atomic claim → principle`. The principles live
in [`strategy-principles.md`](strategy-principles.md) and already carry `sources`, `confidence`,
`status` and `ogamex` mapping; this file adds the two things the handoff required and that were
missing — the **claim-type classification** (so not everything becomes an executable rule) and a
small set of **atomic contested claims** preserved verbatim, never cleaned up.

Provenance markers and the A/B/C/D confidence scale are inherited from
[`strategy-principles.md`](strategy-principles.md). Counts as of this pass: **107 principles**
(21 shipped, 7 partial, 76 researched, 3 deferred, 0 gap).

## The eight claim types

Every claim is exactly one of these; the type decides what it may become at implementation time, so a
sourced observation can never silently turn into executable policy.

| type | meaning | disposition at implementation |
| --- | --- | --- |
| `DOMAIN_FACT` | a mechanic/fact about the game or host | verify against the host; becomes a precondition or host check, never a module constant |
| `HARD_SAFETY_POLICY` | a binding rule or legal invalidity | a hard gate in the planner (abort/refuse), never a scored preference |
| `SCORING_FACTOR` | a term that belongs in a score | a documented component of the relevant scorer, with its own line in the decision trace |
| `STRATEGIC_HEURISTIC` | a rule-of-thumb ordering or trigger | the default mechanism — a comparator or trigger, the smallest thing that closes the gap |
| `BEHAVIOR_PROFILE_PARAMETER` | a persona/archetype/skill knob | a band in `ArchetypePolicy`/`AiSkillBand`, never a constant |
| `POSSIBLE_STRATEGIC_POSTURE_TRIGGER` | a candidate trigger for a temporary state | recorded only; stays open until an intent genuinely cannot be scored |
| `ADVANCED_TACTIC` | a named expert manoeuvre | an executor gated behind a reviewed cluster, never silent |
| `REJECTED / UNSUPPORTED` | dismissed or not supportable | recorded and not implemented |

## Classification of the 107 principles

Grouped by domain; each ID is assigned its type (abbreviated: `DF` domain fact · `HS` hard-safety ·
`SF` scoring factor · `SH` strategic heuristic · `BP` behaviour-profile parameter · `AT` advanced
tactic).

| Domain | DF | HS | SF | SH | BP | AT |
| --- | --- | --- | --- | --- | --- | --- |
| Economy (7) | — | ECO-005 | ECO-007 | ECO-001, ECO-003, ECO-004 | ECO-002, ECO-006 | — |
| Research (3) | — | — | RES-002 | RES-001, RES-003 | — | — |
| Colonization (4) | — | — | — | COL-001, COL-002, COL-003, COL-004 | — | — |
| Raiding (14) | — | RAID-001, RAID-002, RAID-010 | RAID-004, RAID-005, RAID-006, RAID-013 | RAID-003, RAID-008, RAID-009, RAID-011, RAID-012, RAID-014 | RAID-007 | — |
| Fleet composition (15) | FLE-005, FLE-007, FLE-011 | FLE-001 | — | FLE-002, FLE-003, FLE-004, FLE-006, FLE-008, FLE-009, FLE-010, FLE-012, FLE-014, FLE-015 | — | FLE-013 |
| Fleetsave (11) | — | — | — | FS-001, FS-002, FS-004, FS-005, FS-006, FS-008 | FS-003, FS-007 | FS-009, FS-010, FS-011 |
| Espionage (12) | INT-004, INT-005, INT-006, INT-008, INT-010 | — | INT-003, INT-009 | INT-001, INT-002, INT-007, INT-011, INT-012 | — | — |
| Fleetcrash (14) | CRASH-001, CRASH-002, CRASH-004, CRASH-006 | — | CRASH-011, CRASH-014 | — | — | CRASH-003, CRASH-005, CRASH-007, CRASH-008, CRASH-009, CRASH-010, CRASH-012, CRASH-013 |
| Ninja (5) | — | — | — | NIN-003 | — | NIN-001, NIN-002, NIN-004, NIN-005 |
| ACS (14) | ACS-001, ACS-002, ACS-012, ACS-013 | ACS-011 | — | ACS-005, ACS-006, ACS-007, ACS-009, ACS-014 | — | ACS-003, ACS-004, ACS-008, ACS-010 |
| Expeditions (2) | EXP-002 | EXP-001 | — | — | — | — |
| Routine (4) | — | AUTH-001 | — | AUTH-003, AUTH-004 | AUTH-002 | — |
| Social (2) | — | — | — | SOC-002 | SOC-001 | — |

The classification is the handoff's guarantee that a `DOMAIN_FACT` about phalanx range cannot become a
scored preference, and a `HARD_SAFETY_POLICY` (bashing, detector thresholds, energy interlock) cannot
be out-ranked by utility.

## Atomic contested claims (preserved, not resolved)

These are the claims where sources disagree or an earlier reading was wrong. They stay here verbatim;
the principle that consumes them carries the `contested` confidence marker and a pointer back.

- `CLAIM-RAID-014` — **contested, REJECTED for the single-raid gate.** ogames.net publishes
  `Profit = Loot + Debris − Fuel − Losses`; the module deliberately keeps debris out of the raid
  gate (two missions, two capacities). Disposition: module doctrine unchanged; the split is recorded
  in `RAID-014` and `T4`/`F4`.
- `CLAIM-FS-007` — **contested.** Landing buffer "10–20 minutes after you log in" (single
  non-Gameforge source) vs "+30–60 minutes" (Gameforge). Disposition: a persona band, never a constant.
- `CLAIM-CRASH-003` — **corrected.** A same-planet relocation is **not** recallable
  (`cancelMission` early-returns on `planet_id_from === planet_id_to`); a deployment between two own
  planets **is** recallable. Disposition: `F2`/`V4` state the correction.
- `CLAIM-CRASH-005` — **partially verified.** Moon destruction (type 9) redirects returning fleets to
  the planet; the fleet-redirect consequence is **not re-verified** against the host.
  Disposition: `F6` marks it as such.
- `CLAIM-FLE-006` — **contested (early).** Heavy fighter ages out once gauss/plasma appear; contested
  for the early rocket/light-laser defence case. Disposition: persona/stage band.
- `CLAIM-SIM-MEAN` — **REJECTED.** A mean-profit reading inverted a real 60 M decision
  (TrashSim thread). Disposition: `T2` uses a conservative tail statistic, never a mean.
- `CLAIM-AUTH-LOSS` — **REJECTED, no source.** "80% of fleets are lost while offline" has no source
  and is deleted wherever it appears (`V3`).
- `CLAIM-NIN-ARRIVAL` — **contested.** WIK-005 says the ninja fleet arrives "a few seconds before"
  impact; TP-011 says same-second or ~1 s before and warns that arriving seconds early exposes the
  fleet to a recall. Disposition: reconciled by the server-tick observation (a ~1 s buffer lands on
  the same combat second); recorded, not silently merged.
- `CLAIM-DEBRIS-RATE` — **host-config, not a conflict.** TP-013 (43/55/80% + defence debris) vs
  WIK-008 (vanilla 30%, some 70%) — a private-server vs vanilla difference; reinforces gate 1 (read
  the debris rate from the host).
- `CLAIM-TP019-ERROR` — **source-internal error.** TP-019's tip text prints Deathstar RF×250 against
  battlecruisers while its own RF table (and WIK-004) list ×15; the canonical value is **15** — do
  not encode 250.
- `CLAIM-ECO006-FUSION` — **contested.** PLW-002: fusion has an *early* role ("for the first few
  levels the fusion plant is good"), against ECO-006's "fusion only when deuterium is surplus".
  Disposition: stays a persona band (C), consistent with ECO-006's own contested-switch note.
- `CLAIM-COL001-SLOTS` — **corrected.** WIK-012 + TP-016 agree exactly on the host 7.4.0/7.5.0 slot
  bonuses; the earlier "30/22.5/15" figures were stale official values, not a genuine contest.
  Disposition: COL-001 upgraded to confidence A.

## Pipeline status

`source registry` (source-registry.md) → `claims` (this file, classification + contested atoms) →
`principles` (strategy-principles.md) → `architecture mapping` (architecture-mapping.md) →
`algorithm blocks` (gameplay-algorithms.md) → `integration gates` (strategy-mining.md). The runtime
policy layer (weights, thresholds, behaviour profiles) is **not** derived from this file; it is a
later, separately-justified step, and nothing here is executable configuration.
