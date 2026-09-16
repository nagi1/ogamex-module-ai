# Driver utilisation conformance — 6B

Owner requirement (Package 6, item 6B): every verified external-driver field is listed with its
source, consumer and observed effect; a field with no safe consumer is recorded as unused with its
reason, never silently discarded. This record is the conformance list for the 6B slice
`IMPL-031`, written 16 September 2026.

## The 6B contribution

One bounded driver-evidence contribution at the existing decision point. The account's current
mood — the persisted affect intensity the appraisal engine (FAtiMA when healthy, native otherwise)
has already recorded — moves the decision score as a signed **appetite**:

- **anger** presses the attack and cools the save; **fear** presses the save and avoids the raid
  (the human play: a player who lost a fleet either hits back or rebuilds, and the mood says which).
- **the skill band decides how fully the account plays the feeling** (`AiSkillBand::evidenceReaction`:
  novice 1.0, standard 0.5, veteran 0.2), so two accounts with the same mood diverge reproducibly.
- the nudge is bounded by `ai.cognition.affect.decision_weight` alone (default `0`), so an enabled
  mood can promote a near-equal candidate but can never promote an unavailable action, override a
  native refusal, or outvote the safety/resource weights.

The default is off, so ordinary-universe decisions are byte-for-byte unchanged until an operator
opts the weight in for the cooperative cohort they measure.

| Field | Source | Consumer | Observed effect |
| --- | --- | --- | --- |
| `AffectAppraisal::intensity` (anger/fear) | `AffectEngine::appraiseObservedEvent` → `AiAffectState` | `UtilityScorer` `affect` component | bounded per-candidate score nudge, profile-weighted |

## Verified fields by driver

### FAtiMA — `AffectEngine`

| Field | Consumer | Effect |
| --- | --- | --- |
| `emotion` | `AppraiseObservedBattleReportAction` → `AiEmotionalEpisode`, `AiAffectState` | recorded episode + running state |
| `intensity` | `AiAffectState` → `CurrentAiAffectIntensityAction` → `UtilityScorer` | **used by 6B** (above) |
| `driverEmotion` | `RunCognitionConformance` | proves driver participation; no decision consumer |
| `driverIntensity` | `RunCognitionConformance` | proves driver participation; no decision consumer |
| `mood` | **none** | **unused** — computed by the driver, not persisted, and no decision consumer reads a valence that outlives the appraisal; recorded rather than silently dropped |

### FAtiMA/CiF — `SocialCognition`

| Field | Consumer | Effect |
| --- | --- | --- |
| `volition` | `HybridSocialCognition` | demotes a weak native `Accept` to `Clarify` (ordinary conversation) |
| `step` | `HybridSocialCognition` | withheld when the exchange cannot start |

**Unused by 6B:** coalition/social caution has no campaign decision point today — social evaluation
runs on ordinary-universe conversation, which 6B must not change. A campaign social stance needs its
own decision point first.

### CBRKit — `ExperienceEngine`

| Field | Consumer | Effect |
| --- | --- | --- |
| `similarity` | `EconomyUpgrades::rememberedBias` | bounded building-confidence nudge |
| `driverSimilarity` | `RunCognitionConformance` | distinguishes driver order from native fallback |

**Unused by 6B:** "confidence in a familiar campaign plan" has no campaign plan confidence point
today; the existing consumer is economy building choice only.

### AgentOS — `LongTermMemory`

| Field | Consumer | Effect |
| --- | --- | --- |
| recall `id` order + fact `value` | `EvaluateAiSocialExchangeAction` (HelpRequest history) | ordinary social caution |

**Unused by 6B:** `relevance`/provenance weighting over past relationships and obligations has no
reachable production consumer (measured 15 September 2026: the sole reader sits behind an
unreachable transfer-capability branch). Recorded unused rather than wired speculatively.

## Gates

- **Gate 1** — no object list, price or requirement is encoded; the contribution reads only the
  account's own persisted mood and the module's candidate types.
- **Gate 2** — one component, one enum method, one config knob; no new planner or driver manager.
- **Gate 3** — "a player who just lost a fleet either hits back or rebuilds, and a veteran shrugs
  off the same mood a novice acts on" is nameable ordinary play.
