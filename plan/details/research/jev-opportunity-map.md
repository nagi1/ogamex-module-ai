# Jev opportunity map — every hand-made judgement in Modules/AI

Research note, 30 September 2026. Third in the set: [jev-decision-model.md](jev-decision-model.md)
(what Jev is, what it costs, what it cannot do) and
[jev-with-cognition-drivers.md](jev-with-cognition-drivers.md) (how Jev composes with FAtiMA/CiF, CBRKit,
AgentOS and PsychSim).

Where the first two notes looked at the driver seams, this one is a **whole-module sweep**: every
hand-made judgement, every constant that stands in for a reading of a situation, every input the code
receives and throws away, and every capability it deliberately declines. Each finding below was read out
of the file and the decisive quote is reproduced verbatim, because the point of the exercise is to see
what the code actually says rather than what we assume it says.

**The verdicts use four words:** *Build* (Jev fits, and the answer has a consumer), *Measure* (it might
fit, but we need numbers before we change anything), *Authoring* (a human judgement is genuinely needed,
so use Jev offline as a drafting/challenging tool, never at runtime), *Refuse* (Jev is the wrong tool, or
the code is right to be deterministic).

---

## 1. The reframe: there are four families, and Jev only serves one and a half

The sweep changed my earlier answer. The module's hand-made judgements are not one category — they are
four, and they want completely different remedies.

| Family | What it is | Example | Right remedy | Jev |
| --- | --- | --- | --- | --- |
| **1. Reading meaning** | Text or a situation in, a label out | "Which exchange is this message?" | A classifier with a threshold and an abstain path | **Yes — its home** |
| **2. Taste over classes** | Which *kind* of thing a veteran prefers, and roughly how much | "Raid 0.9, Spy 0.8" per archetype | A human authoring decision, ideally evidence-cited | **Offline only** — draft and challenge, then a human commits it to `resources/behavior` |
| **3. Calibration** | Weights that should be *fitted* against what actually happened | `SAFETY_WEIGHT = 50.0` | Regression/measurement over recorded outcomes | **No — wrong tool entirely** |
| **4. Mechanics, legality, arithmetic** | Host rules, budgets, TTLs, exact validation | Bashing limit, `termsAreExplicit` | Code, exactly as today | **No** |

The uncomfortable finding is family 3. The single largest hand-tuned surface in the module — the weights
that decide *everything an account does* — is not a classification problem at all:

```php
// app/Domain/Decision/UtilityScorer.php:16-26
private const RESOURCE_NEED_WEIGHT = 30.0;   ...  private const SAFETY_WEIGHT = 50.0;
private const TARGET_CONFIDENCE_WEIGHT = 30.0; ... private const ARCHETYPE_WEIGHT = 25.0;
```

Six hand-picked exchange rates between "how badly do I need resources", "how safe is this" and "does my
personality like this", multiplied against features and summed. Jev cannot improve that: its own docs say
its score outputs are "weak in numerical calibration" and that any mathematical logic belongs in code.
Proposing a model here would be exactly the mistake the gates exist to prevent. What family 3 needs is the
loop that already exists in **one** place — `RaidPlanner::blacklisted()`, which reads real raid loot back
from recorded cases — generalised to the other weights, and that is a measurement exercise, not a model.

So the honest headline: **the module's biggest hand-made judgements need measurement, not a model; a small
and specific set of them are text readings that Jev is genuinely built for; and a third set are authoring
decisions where a model can help a human but must never run in the account.**

---

## 2. Family 1 — reading meaning (Jev's home)

Ranked by how much real judgement is being faked today.

### 2.1 ★ Alliance pitch quality is literally "is this string non-empty"

```php
// app/Domain/Social/AllianceChoice.php:92
$pitch = trim((string) ($alliance->external_text . $alliance->application_text)) !== '' ? 1.0 : 0.0;
```

`$pitch` is then added to a score where the quality signal is worth `$quality * 2.0`. So "did anyone type
anything in the box" is worth **half a quality point** when an account is choosing which alliance to join.
There is no reading of the text at all.

**Question Jev would answer:** *does this pitch read like a real, selective alliance or like a placeholder?*
— a `Score` over authored levels, phrased the way a player would judge ("selective club with expectations"
→ "a line anyone could have written" → "empty boilerplate").

**Why it is affordable:** this happens once, when an account picks an alliance. One call per account, for
its whole life. **Verdict: Build.** The alternative is to delete the term (gate 2 — it currently rewards
nothing), which is also respectable; what is not respectable is that a non-empty check silently scores 1.0.

### 2.2 ★ Alliance applications are judged without reading a word of them

`ReviewAiAllianceApplicationsAction` reads `$application->user_id` and `$application->created_at` and
nothing else; the decision is rank ratio (`STRONG_RANK_RATIO = 0.15`, `NEWBIE_RANK_RATIO = 0.5`), age
(`MINIMUM_APPLICATION_AGE_MINUTES = 15`) and the PsychSim stance. The comment on the *pitch* in
`AllianceChoice` states the design intent the review action ignores: *"A readable pitch is the applicant's
own filter: demanding alliances write one."*

**Question:** *does this application read as a serious applicant, or as a farm/multi/spam?* — with an
abstain that leaves the rank rule in charge.
**Caveat to check first:** confirm the host `AllianceApplication` actually exposes the applicant's text; the
sweep could not confirm the column from this workspace. **Verdict: Measure** (then Build if the text exists).

### 2.3 ★ When a target sleeps is not modelled at all — but people *announce* it

The sweep answered "when do they sleep?" with **not found**. The only uptime statement anywhere is one
scalar:

```php
// app/Domain/Perception/ActivityIntelReader.php
// ponytail: exponential decay over the 15-minute window is the smallest honest model for
// "still online on arrival"; the true session length is a Weibull (H2) ...
return exp(-$minutesToArrival / self::ACTIVITY_WINDOW_MINUTES);
```

Fitting that decomposition is arithmetic over activity stamps (family 3/4 — the code itself names the
Weibull upgrade path). But there is a second, textual signal that experienced players act on and we
currently discard: **players say when they are leaving.** "gn", "cya tomorrow", "back in 8h", "brb food" is
an availability declaration, and the module already reads every inbound chat message — through a matcher
that only knows eleven transaction types, so `"gn, off to bed"` places as nothing and is forgotten.

**Question:** *does this message declare availability or absence?* (`Boolean` + a `Choice` for the rough
horizon from authored buckets: "leaving now", "back shortly", "back tomorrow", "unknown"). The answer would
be stored as an expiring fact — `ai_memory_facts` already has an `expires_at` column that **nothing
currently sets** — and read by raid timing.

**Why this is the most authentic idea in this note:** it is exactly what a human player does — "he said he's
going to bed, launch now" — and it is a reading of language, not a calculation.
**Harness constraints:** it must not gate the raid on a paid call (the raid decision has to work provider-off);
it belongs beside the existing exchange matcher, in the same bounded read.
**Verdict: Measure** — the offline run in §5 would show how often real cohort chat contains such a
declaration, and what a confidence gate would let through.

### 2.4 Exchange recognition (already recommended in the driver note)

`ClassifyInboundSocialExchangeAction` is the module's only reader of message meaning: an ordered regex
cascade with `RESOURCE_WORDS = 'metal|crystal|deuterium|deut'`, an
`MAXIMUM_ACKNOWLEDGEMENT_CHARACTERS = 80` guard, and the policy *"the default answer to ambiguous text is
silence rather than a guess."* Keep the cascade; add the classification as a **fallback** for the messages it
drops. **Verdict: Build** (offline first), unchanged from the driver note.

Two narrower readings inside the same action are worth naming separately because each replaces a rule with a
judgement and each has an explicit downstream consumer:

| Reading | Today | Consumer of the answer |
| --- | --- | --- |
| *Is this message coercive?* | one regex alternation (`or else\|you will regret\|last warning\|…`) → `AiSocialTerm::Coercive` | `ContactImpactPolicy::warningDeltas()`: `trust -0.10, threat +0.15, affinity -0.05` |
| *Does this apology name the harm?* | regex for harm words; the docblock says *"Inventing the acknowledgement on the sender's behalf would skip the one question the module has to ask"* | `AiSocialResponseReason::HarmNotAcknowledged` → clarify instead of accept |

Both are `Boolean` questions with a threshold and an abstain, and both already have a place to land.
**Verdict: Measure** (they are worth a few cents; the value is fewer wrong relationship deltas).

### 2.5 Trade direction is deliberately unresolved

`ClassifyInboundSocialExchangeAction::tradeTerms()` — *"Which resource was offered and which was requested is
deliberately not resolved: the module cannot complete a trade either way, so guessing the direction would only
make the recorded reason wrong."*

This is the module refusing to guess, for a good reason, because it cannot act on the answer. **Verdict:
Refuse** — Jev would not change the underlying limitation (no trade capability), so it would only produce a
better-recorded reason for something we cannot do.

### 2.6 Doctrine prose parsed by regex — the honest answer is "fix the data, not the model"

```php
// app/Domain/Decision/DefenseCompositionPlanner.php:143
if (!is_string($rule) || preg_match('/swap to ([a-z_]+) once the wall passes (\d+)/', $rule, $matches) !== 1) {
```

against `resources/behavior/defence-doctrines.yaml:57`: `stop_rule: swap to big_gun_heavy once the wall
passes 200 Light Lasers` — and the file's own warning: *"ponytail: the sentence is matched by shape, so
rewording it silently disables the handover."*

A model *could* read that sentence. It should not: the same document already carries the target and the
threshold as facts, and the fix is a structured key (`stop_rule: {doctrine: big_gun_heavy, after: {unit:
Light Laser, count: 200}}`) keeping the verbatim source quote alongside it. **Verdict: Refuse** — this is the
test case for whether the analysis can say no to a superficially perfect Jev fit. It can.

### 2.7 Where reading meaning is *forbidden*, and stays forbidden

- **Campaign consultation evidence.** The brief pre-filters evidence by authorisation
  (`authorized && value !== null && collectedAt >= deadline`). *"the module's rule is explicit about not
  letting a provider see what wasn't admitted"* — a relevance model here would re-open a closed boundary.
- **`RecordAiLanguageProposalAction::termsAreExplicit()`.** Proposals are re-checked against the raw message
  with `str_contains` and an exact-number regex. A probabilistic answer must never satisfy a validation
  boundary.
- **`GalaxyScanObservation`** — `isVerified()` is hard-coded `false` because WIK-206's field semantics are
  host-unconfirmed, and the docblock says the record "stays inert… deliberately exposes no derived fleet,
  resource or combat value."
- **`CounterespionageObservation`** — *"The host states no counterespionage mechanic, so nothing is derived
  from them: no probability, no threshold, no ratio."*

---

## 3. Family 2 — authoring-time judgements (Jev drafts, a human commits)

These are numbers that encode *taste* rather than a reading, and several of them say out loud that nobody
measured them:

| Constant | Where | Its own words |
| --- | --- | --- |
| `DENOMINATOR = 30` | `Domain/Decision/SaveFailurePolicy.php` | *"The rate is a placeholder — no source quantifies how often real players fail"* |
| `6:1` intel staleness ratio | `Domain/Perception/ActivityIntelReader.php` | *"a placeholder for the calibration runs, not a measured constant"* |
| `variationWeight 8/4/1`, `selectionMargin 10/2.5/1`, `evidenceReaction 1.0/0.5/0.2`, `idleOverrideProbability 0.05/0.02/0.01` | `app/Enums/AiSkillBand.php` | *"ponytail: three unmeasured rates"* |
| `SURVIVAL_FLOOR = 0.8` | `Domain/Decision/RaidPlanner.php` | *"one unmeasured floor for every persona"* |
| `MAX_ATTACKS_PER_TARGET_PER_DAY = 8` | `Domain/Attack/DailyAttackBudget.php` | no source; and it disagrees with the other two caps (§6) |
| `RAID_STORAGE_FILL_RATIO = 0.8` | `Domain/Decision/RaidPlanner.php` | *"the exact number is persona flavour"* |
| per-action taste table `[0.0, 1.0, 0.0, 0.0]`… | `Domain/Decision/CandidateActionFactory.php::features()` | *"The taste values live here in one place"* |
| archetype `$preferences` (`Raid 0.9`, `Spy 0.8`, `QueueUnits 0.6`) | `Domain/Decision/Policies/*Policy.php` | authored per archetype |

**What Jev can honestly do here:** given the ingested corpus (`plan/research/ogame/`, `SOURCE-REGISTRY.yaml`,
the claim vocabulary `DOCUMENTED`/`CONTESTED`/`STRATEGIC_HEURISTIC`), answer *"does this constant's stated
rationale appear in its cited source?"* and *"is this claim presented as documented or contested?"* — the
citation-check shape TypeSafe documents. That is a **Gate-1/Gate-3 provenance guard**, which is the module's
central discipline: the behaviour README says every value must resolve to a source id, and the doctrines file
says *"Every number here is copied from an ingested source and cites it. Nothing is authored."*

**What it must never do:** invent the number, or write it at runtime. The output of such a run is a
reviewable proposal for `resources/behavior/`, with a human and a source id attached.

Two structural notes from the sweep that are gate-2/gate-3 findings in their own right: these
human-behaviour constants live in **PHP** (`AiSkillBand`) rather than `resources/behavior/`, which the module
instruction requires; and `RaidPlanner::LOOT_TIER_DEFENDED = 2.0` is justified in its comment by debris that
the code never collects — a rationale that contradicts another documented policy (already recorded in
`plan/details/reviews/2026-09-17-defended-raid-loot-tier.md` and `reopened-repo-value.md` T7).

---

## 4. Family 3 — calibration is not a model problem

The module has exactly one closed evidence loop:

```php
// app/Domain/Decision/RaidPlanner.php — the only place a hand-written judgement is corrected by outcomes
if ($cases->count() < self::BLACKLIST_RAIDS) { return false; }
return $totalLoot / $cases->count() < self::BLACKLIST_LOOT_FLOOR;
```

and one capped one: `EconomyUpgrades::rememberedBias()` reads experience cases but is explicitly bounded by
`EXPERIENCE_MAXIMUM_SHARE` "so it can never outvote the arithmetic".

Everything else — the six `UtilityScorer` weights, the taste tables, the loot tiers, the exposure bands —
is authored once and never revisited. The data to fit them already exists (`ai_experience_cases`,
`ai_decision_traces.score_components`, `ai_score_samples`, `ai_raid_experience_features`).
**Verdict: Refuse Jev; do the measurement.** Naming this is the most useful thing this sweep produced,
because "add an AI model to the decision engine" is the obvious-looking idea and it is the wrong one.

---

## 5. The measurement that settles family 1

The same offline harness proposed in the driver note now has a sharper job, because the sweep named the
comparison sets:

| Question | Compare against | Artefacts |
| --- | --- | --- |
| Which exchange is this message? | the regex cascade's placement, and its `null`s | `ai_social_exchanges`, host `ChatMessage` rows |
| Is this message coercive? | the regex's `AiSocialTerm::Coercive` | `ai_relationships` deltas via `ContactImpactPolicy` |
| Does the apology name the harm? | the regex's harm-word test | `ai_social_exchanges.response_reason` (`HarmNotAcknowledged`) |
| Does this declare absence/availability? | **no incumbent** — coverage question only | chat rows vs `ActivityIntelReader` activity stamps |
| Is this pitch/application serious? | the `!== ''` test it would replace | `AllianceChoice` decisions, `ai_relationships` |

Print agreement, the disagreement list with raw quotes (redacted), coverage gain on the `null` cases, the
confidence-vs-correctness split, latency p50/p95 and settled cost. **Pass bar:** confidence must separate
right from wrong on *our* data; coverage gain must be worth the per-message spend; and no disagreement may
be one a human reading the raw message calls obviously wrong.

---

## 6. What the sweep found that has nothing to do with Jev

These are real defects and gaps, worth fixing on their own merits. Several are already catalogued in
[half-wired-play-loops.md](repos/half-wired-play-loops.md), which is the right home for the detail — I am
listing them so they are not lost inside a Jev note:

1. **One cap, three statements, two values.** `RaidPlanner::BASHING_LIMIT = 6` ("the host's hard bashing
   limit"), `RaidWavePlan::WAVE_LIMIT = 6`, `DailyAttackBudget::MAX_ATTACKS_PER_TARGET_PER_DAY = 8`. One of
   these is wrong. `half-wired-play-loops.md` also notes the host never actually reports the 6, so a universe
   that tunes it diverges — a gate-1 concern.
2. **A hardcoded username stands in for a host authority.** `QueueableSpyPlanner.php:187` —
   `if ($owner?->getUsername(false) === 'Legor') { continue; }` — while `PlayerObservationService` already
   has `isAdmin()`.
3. **Two classes named `DefenseCompositionPlanner`** (`Domain\Decision` doctrine-over-YAML, bound and used;
   `Ai\Defense` fodder-ratio, referenced only by its own test). Dome names are also unguarded across three
   spellings (`SmallShieldDome` vs `small_shield_dome`).
4. **A dead policy surface.** `WaveFarmPlanner`, `PillageCap`, `NapPolicy`, `DefMinerStance`,
   `IonCannonShieldFodder`, `Ai/Defence/QueueableDefencePlanner`, `DailyAttackBudget`, `AttackPlanner` and
   others have no production consumer — several were generated by the strategy harness (their evidence lives
   in `plan/research/ogame/attempts/WIK-*.log`) with tests that pass in isolation. **This is a gate-2 finding
   about the harness**: it is producing tested, unwired classes.
5. **Recorded evidence nothing reads.** `defence-doctrines.yaml` says of `minimum_deterrent: 4000` —
   *"NOT READ BY CODE YET… Do not treat it as live behaviour."* The doctrine file's `rationale`,
   `stated_ceiling`, `known_weakness` and `constraints` blocks are read by nothing.
6. **Written facts nothing queries.** `AiMemoryPredicate::AttackReceived` is written by
   `AppraiseObservedBattleReportAction` and never read in production; `ai_memory_facts.expires_at` is never
   set by any caller; `MemoryRecallQuery::$queryText` is a declared seam with no caller.
7. **Driver outputs computed and discarded** (`mood`, `driverEmotion`, `driverIntensity`, `volition`, `step`,
   `driverSimilarity`) — listed in the driver note, repeated here because it is the same pattern.

---

## 7. What I would do first

1. **The provenance check (harness, zero gameplay risk).** One Jev question per authored constant — does its
   cited source support it, and is it documented or contested — run over `resources/behavior/` and the
   corpus. Cheap, pure win, and it guards the rule the whole research pipeline exists to protect.
2. **The offline reading run (§5).** It decides family 1 with numbers, including the two readings nobody has
   built (coercion, and availability declarations).
3. **Fix the two-line embarrassments, no model involved:** the non-empty pitch test, the `'Legor'` username,
   the three-way cap conflict. These are defects whether or not Jev is ever adopted.

---

## 8. Decisions for the owner

1. **Confirm the family split.** Do we accept that the decision engine's weights are a measurement problem
   (family 3) and keep Jev out of `UtilityScorer` and the planners entirely?
2. **Approve the offline reading run** and the provenance check; both spend cents and change no behaviour.
3. **Availability declarations (§2.3)** — is reading "I'm off to bed" from chat, and storing it as an
   expiring fact, wanted? It is the most authentic use case found, and it is also the one furthest from the
   current design.
4. **Alliance pitch and application text** — build the reading, or delete the term? Either beats a
   non-empty check.
5. **The harness finding (§6.4)** — should generating a class with a test but no consumer fail the quality
   gate? It currently does not.

---

## Sources

- Whole-module sweeps run read-only on 30 September 2026 over `app/Domain/**`, `app/Ai/**`,
  `app/Infrastructure/**`, `app/Actions/**`, `app/Enums/**`, `app/Support/**`, `config/**`,
  `resources/behavior/**`, `resources/scenarios/**`, `plan/details/**` and `plan/tasks/seed.sql`.
- Verified by direct read during this note: `app/Domain/Decision/UtilityScorer.php`,
  `app/Domain/Decision/RaidPlanner.php`, `app/Domain/Decision/RaidWavePlan.php`,
  `app/Domain/Attack/DailyAttackBudget.php`, `app/Domain/Decision/DefenseCompositionPlanner.php`,
  `app/Domain/Social/AllianceChoice.php`, `app/Domain/Decision/QueueableSpyPlanner.php`,
  `app/Domain/Perception/ActivityIntelReader.php`, `app/Actions/MapObservedBattleReportToStimulusAction.php`,
  `app/Actions/ClassifyInboundSocialExchangeAction.php`, `app/Infrastructure/Cognition/FatimaAffectEngine.php`,
  `app/Domain/Cognition/NativeAffectEngine.php`, `resources/behavior/defence-doctrines.yaml`.
- Comparisons and prior art: [half-wired-play-loops.md](repos/half-wired-play-loops.md),
  `plan/details/reviews/2026-09-17-defended-raid-loot-tier.md`, `plan/details/specs/reopened-repo-value.md`,
  and the Jev material cited in [jev-decision-model.md](jev-decision-model.md).
