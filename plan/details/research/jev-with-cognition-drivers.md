# Jev with our cognition drivers — every way it can be used

Research note, 30 September 2026. Companion to [jev-decision-model.md](jev-decision-model.md), which
covers what Jev (TypeSafe's System One decision model, added to the Laravel AI SDK v1.0 as
*Classification*) is, what it costs, and what it cannot do.

This note answers one question: **given the four external cognition drivers we actually run — FAtiMA/CiF,
CBRKit, AgentOS and PsychSim — plus the native engines behind them, where can a calibrated decision
model contribute?** The module-wide version of the same question — every hand-made judgement in the tree,
including the ones that are *not* Jev-shaped — is [jev-opportunity-map.md](jev-opportunity-map.md).

Every claim about our code below was read from the file named beside it. Verified constants are quoted
verbatim, because the whole argument rests on the fact that the judgment is currently a **hand-written
number** rather than something measured.

---

## 1. The frame: Jev is not a fifth driver

The four drivers each own a **mechanism** — a thing that computes. Jev owns no mechanism. It reads text
and context and returns a bounded judgment. That distinction decides everything else:

| | Owns | Examples in our code |
| --- | --- | --- |
| Drivers | The mechanism | FAtiMA: emotion intensity and mood decay. CBRKit: feature similarity. AgentOS: memory ranking. PsychSim: the cooperate/defect decision |
| Module | Perception, policy, authority | What happened; how bad it was; which exchange a message is; whether a proposal is legal; how much weight a driver's answer carries |
| Jev | A judgment about meaning, with a probability attached | "Does this read as a probe or as a raid?" "Is this message an apology?" |

**The rule that keeps this simple:** Jev goes into the module's *perception* layer, which feeds the
drivers. It never sits next to a driver as a second opinion on the same question, and it never replaces a
mechanism. That is not a stylistic preference — it is what keeps the existing hybrid combiners
(`HybridAffectEngine`, `HybridSocialCognition`, `HybridExperienceEngine`) untouched. Adding Jev must not
create a three-way merge that needs its own paragraph to justify, because that is exactly the gate-2
failure we already have a tool to catch.

It also keeps Jev out of the trap its own documentation warns about: it is good at one bounded judgment
and bad at everything else, so it belongs at the narrowest seam we have, not at the centre.

---

## 2. The seams as they are today

### FAtiMA/CiF — affect (`AffectEngine`)

`Infrastructure/Cognition/FatimaAffectEngine.php` turns an `ObservedStimulus` into an OCC event and two
beliefs, then reads back emotion and mood:

```php
$event = sprintf('Event(Action-End, %s, %s, %s)', $this->counterparty(), $branch->action, $stimulus->archetype->name);
$state = $this->session->appraise($stimulus->archetype, $event, [
    $this->belief('StimulusDesirability') => $this->format($branch->desirability),
    $this->belief('StimulusThreat') => $this->format($branch->threat),
]);
```

Hand-made judgments around the call:

- `branch()` **pre-selects the OCC action** (`Aid` / `Harm` / `Threaten`) by comparing three numbers, and
  signs `desirability` and `threat` itself. The driver's authored rules are mirrored, not asked.
- Only three OCC emotions map to the module's taxonomy (`Anger`, `Fear`, `Gratitude`); anything else
  declines to native.
- Only `$state['emotions'][0]` — the first emotion — is ever consumed.
- `intensity` is clamped by `ai.cognition.fatima.intensity_ceiling`.

And the stimulus itself is built by `Actions/MapObservedBattleReportToStimulusAction.php`, whose docblock
is a list of deliberate limits:

> "Only loss is read, so a battle can produce harm but never aid. No threat is derived, because whether
> the attacker can strike again is not in this row."

```php
if ($ownShare < 0.5) {
    return null;
}
...
'harm' => $ownShare, 'aid' => 0.0, 'threat' => 0.0,
```

So the module computes: how much of the damage was mine (`ownShare`), whether that is more than half, and
therefore which of three branches we are in. The driver then computes intensity and mood from those
signs. **The judgment is a ratio and a 0.5 cutoff.**

### FAtiMA/CiF — social volition (`SocialCognition`)

`Infrastructure/Cognition/FatimaSocialCognition.php` sends one belief, built by collapsing two
relationship numbers into one integer:

```php
'RapportLevel(SELF, ...)' => (string)(int) round((($exchange->trust + $exchange->affinity) / 2) * config('ai.cognition.fatima.rapport_scale', 10.0))
```

`respect`, `socialImportance`, `anger` and `threat` never reach the driver. `HybridSocialCognition` then
interprets the returned volition with a constant:

```php
private const ACCEPTANCE_VOLITION = 5.0;
```

Accepted only if native said `Accept`; below 5.0 the stance is demoted to `Clarify`. The driver can
withhold, never grant.

### CBRKit — experience (`ExperienceEngine`)

`Infrastructure/Experience/CbrKitExperienceEngine.php` posts `{casebase, queries}` to `/retrieve` and gets
a similarity per case back. The module owns eligibility (same family, feature and ruleset version, an
outcome in `Succeeded|Failed|Inconclusive`, max `ai.cognition.experience.cbrkit.maximum_cases` = 200), the
tie-break, and the cut. The hybrid uses the driver's **order** with the native **score**
(`driverSimilarity` is copied onto the same value as `similarity` and is never read in production).
Downstream, `Domain/Decision/EconomyUpgrades.php::rememberedBias()` only counts cases with
`similarity >= 1.0`, weighted by `experienceDecisionWeight` (default 20).

### AgentOS — memory (`LongTermMemory`)

`Infrastructure/Memory/AgentOsLongTermMemory.php` projects each candidate fact to the driver as text:

```php
'text' => trim($candidate['predicate'] . ' ' . json_encode($candidate['value']))
```

— mechanical, not a paraphrase. The driver returns a ranking with `encodingStrength`, `stability` and
`retrievalCount`; the module keeps **only the ids** and discards the rest. Hybrid mode keeps the native
recency cut authoritative and lets the driver reorder inside it. The sole production caller is
`EvaluateAiSocialExchangeAction::recalledHistory()` for `HelpRequest`, limit 20, read by
`NativeSocialCognition::outstandingDebtPenalty()` (`0.5`).

There is **no write-time importance anywhere**: `RecordAiMemoryFactAction` writes unconditionally with
`firstOrCreate`, `expires_at` defaults to `null`, and `ai_memory_facts` has no importance column.

### PsychSim — theory of mind (inside `SocialCognition`)

`Support/PsychSimTheoryOfMind.php` sends one number:

```php
$decision = app(PsychSimClient::class)->decide($threat * self::DEFECTION_INCENTIVE_SCALE); // 2.0
```

`AiRelationship.threat` is the only input; `{"decision": "cooperate"|"defect"}` is the only output, and
five call sites turn it into a binary veto (`ReviewAiAllianceApplicationsAction`,
`ReviewAiBuddyRequestsAction`, `AllianceChoice`, `GenerateAiReplyAction` context, `ConsultCampaignDecisionAction`).

### And the one place we read a player's *words*

`Actions/ClassifyInboundSocialExchangeAction.php` is the only real text classifier in the module. It
lowercases, collapses whitespace, then runs an ordered regex chain:
`compensation() ?? apology() ?? warning() ?? ceasefire() ?? cooperation() ?? trade() ?? acknowledgement()`.
Its constants:

```php
private const MAXIMUM_ACKNOWLEDGEMENT_CHARACTERS = 80;
private const RESOURCE_WORDS = 'metal|crystal|deuterium|deut';
```

and its docblock states the policy plainly:

> "This is a bounded matcher for exchanges the module already has an authored answer for, not an
> interpreter of free text… A message it cannot place yields nothing, which is why the default answer to
> ambiguous text is silence rather than a guess."

Anything unmatched returns `null`, and the account says nothing. That is also the anti-pattern
`plan/details/specs/phase-3-cognition.md` warns about from the other side: ambiguous sarcasm, names,
quantities and conditions "must not be confidently guessed from a keyword".

---

## 3. So where does Jev go?

Two perception seams, both of them module authority rather than driver duplication:

1. **Event → stimulus.** `MapObservedBattleReportToStimulusAction` converts a committed battle report into
   `harm`/`aid`/`threat`. Its own docblock names three things it cannot do: read aid, derive threat, and
   cope with having won. Those are judgments about meaning, and they are exactly the shape of question
   Jev answers.
2. **Message → exchange.** `ClassifyInboundSocialExchangeAction` decides which known exchange an inbound
   message is. Both the docblock and the plan treat its misses as a known cost.

Both sit before the drivers, both are already module code, and both currently encode a judgment as a
number or a word list. That is the whole opportunity. Nothing below asks Jev to compute anything a driver
already computes.

---

## 4. Every way, with a verdict

| # | Use | Driver involved | Verdict |
| --- | --- | --- | --- |
| A | Appraisal variables for a battle report (desirability, threat, aid) | FAtiMA affect | **Recommended, offline first** |
| B | Which OCC action the event is (`Aid`/`Harm`/`Threaten`) | FAtiMA affect | Fold into A as one more question — never a second call |
| C | Choosing the emotion label itself (`Gratitude`/`Fear`/`Anger`) | FAtiMA affect | **Refused** — the driver's appraisal rules own that |
| D | Filling `mood` / `driverEmotion` / `driverIntensity` | FAtiMA affect | **Refused** — affect dynamics are the driver's mechanism |
| E | Reading aid where none is read today (ally help, resources received) | FAtiMA affect | Needs a named consumer; see §9 |
| F | Reading threat where none is derived today | FAtiMA affect | Needs a named consumer; see §9 |
| G | Richer input to CiF (respect, social importance, exchange class) | FAtiMA social | **Refused for now** — CiF exposes one authored exchange and one volition; extra inputs are unreachable |
| H | Replacing `ACCEPTANCE_VOLITION = 5.0` with a model threshold | FAtiMA social | **Refused** — the volition is the driver's scalar; interpreting it is module policy, and the constant needs a measurement, not a model |
| I | Recognising an inbound social exchange the regex misses | (feeds all social paths) | **Recommended as a fallback cascade, offline first** |
| J | Extracting resources and amounts from a message | (feeds all social paths) | **Refused** — a regex already does it exactly; Jev cannot count or do numbers |
| K | Deciding whether a message is sincere / a trap | PsychSim ToM | Deferred — Jev may add *evidence* to the state PsychSim reads, never the stance |
| L | Changing what PsychSim evaluates (`threat × 2.0`) | PsychSim ToM | **Refused** — that mapping is module translation of a measured field |
| M | Similarity or ordering of experience cases | CBRKit | **Refused** — would be a third similarity measure beside the driver and the native floor |
| N | Deciding whether an episode is worth remembering as a case | CBRKit | Deferred — no consumer exists for a retain gate yet |
| O | Write-time importance for memory facts | AgentOS | **Refused** — our own memory doctrine says importance comes from authored rules, and paying a model on the write path is the Mem0 mistake |
| P | Paraphrasing a fact before sending it to AgentOS | AgentOS | **Refused** — generation is not Jev's job, and the driver is disabled on measured evidence |
| Q | Reranking recalled memories (TypeSafe's own documented rerank pattern) | AgentOS | **Refused for now** — a third ranker with no measurement, over a driver we disabled |
| R | Grading a proposed plan for gate-1 compliance (names object ids instead of taste?) | none — harness | **Recommended, cheapest** (see the companion note, §5c) |

Two are worth building, two are worth measuring, and the rest are refusals with reasons. The refusals are
the important half: they are the difference between adding a capability and adding a competing authority.

---

## 5. The two recommended paths in detail

### 5A — Event appraisal (FAtiMA affect)

**Today:** `ownShare` (a ratio) → one of three branches → two signed beliefs → FAtiMA computes emotion
intensity and mood. Aid is hard zero, threat is hard zero, and losing badly is the only appraisable case.

**With Jev:** the module keeps every number it computes today, and adds the *reading* of the event as one
bounded request sharing one state:

```php
Classification::of([
    'engagement' => ['own_loss' => $ownLoss, 'opponent_loss' => $opponentLoss, 'own_share' => $ownShare,
                     'role' => $isDefender ? 'defender' : 'attacker', 'planet' => $planetName],
    'counterparty' => ['trust' => $trust, 'threat' => $threat, 'recent_attacks' => $recentAttacks],
])->questions([
    'desirability' => new Score('How good or bad was this engagement for me?', ['Badly lost', 'Lost', 'Even', 'Won']),
    'deliberateness' => new Boolean('Was this aimed at me specifically rather than an opportunistic hit?'),
    'can_strike_again' => new Boolean('Does this opponent appear able and likely to hit me again soon?'),
    'received_help' => new Boolean('Did another player help me in this engagement?'),
])->classify();
```

Then, in code — not in the model — `desirability` maps onto the signed OCC beliefs, `can_strike_again`
becomes `threat`, and `received_help` becomes `aid`. FAtiMA still computes emotion intensity and mood from
those beliefs; the module still picks the branch; `ObservedStimulus` still clamps and validates.

**Why this is a real gain rather than a rewrite:** the driver is already being asked "given this event and
these signed beliefs, what emotion and how strongly" — a question it is built to answer. What it is *not*
being given is a trustworthy reading of the event. The 0.5 cutoff is a stand-in for "did I come off
worse", and `aid = 0.0, threat = 0.0` are stand-ins for two judgments nobody has made yet.

**What must not change:** the audit trail. Jev's answers must be *recorded next to* the derived stimulus,
so a later reviewer can see which reading produced which episode — and so a threshold change can be
replayed. Store the raw probabilities and confidences, exactly as the appraisal layer already stores its
episodes in `ai_emotional_episodes` and `ai_affect_states`.

**Honest cost:** one provider call per appraised battle report (plus one more round trip on top of FAtiMA's
measured p50 408 ms external / 403 ms hybrid). It runs inside the session job, not a web request, and
battle reports are rare compared with session ticks — but it is new spend on a path that has none today.

### 5I — Social exchange recognition as a fallback cascade

**Today:** the regex chain places a message or returns `null`; a `null` means the account stays silent.
The docblock calls this deliberate, and it is: silence beats a guess. But it also means genuine offers
phrased outside our word lists — other languages especially — are simply not heard.

**With Jev, as a cascade:** keep the regex exactly as it is. When and only when it returns `null`, ask one
classification over the exchanges the module already has authored answers for:

```php
Classification::of(['message' => $text, 'recent_context' => $recentTurns])
    ->questions([
        'exchange' => new Choice('Which of these is this message?', [
            'apology' => 'An apology for harm already done, naming no amends',
            'compensation' => 'An apology that offers a named resource and amount',
            'warning' => 'A threat or a warning of future attack',
            'ceasefire' => 'A proposal to stop attacking each other',
            'cooperation' => 'An offer of help or a request for it',
            'trade' => 'An exchange of resources with terms',
            'greeting' => 'A greeting or thanks, nothing to transact',
            'other' => 'None of the above',
        ]),
        'names_terms' => new Boolean('Does the message name both a resource and an amount?'),
    ])
    ->classify();
```

Three properties make this the safest possible first move on the ordinary path:

1. **It cannot invent terms.** `names_terms` is not authority — the module still extracts the resource and
   the amount with the existing regex, and `RecordAiLanguageProposalAction::termsAreExplicit()` still
   re-checks any proposal against the raw message. A `compensation` answer with no parsable amount
   degrades to an apology, exactly as today.
2. **It is bounded by a confidence gate.** Family matching by criteria plus a threshold means the account
   can still choose silence. Low confidence is not a licence to guess; it is the same silence we have now,
   with a number explaining why.
3. **It is a fallback, not a replacement.** The deterministic path stays the fast path and the floor. If
   Jev is off, slow, budget-exhausted or unsure, the behaviour is byte-for-byte today's behaviour.

**What it buys, measurably:** coverage on the messages we currently drop, and — the part that matters more
for authenticity — the ability to hear a *counter-offer* or a *ceasefire* in phrasing we never listed.

**What it costs:** one provider call per unmatched inbound message. Only unmatched ones, which is the
cheap half of the funnel.

---

## 6. Composition rules (so this stays simple)

1. **Jev feeds the seam; the seam feeds the driver.** Its answers become module inputs (stimulus fields, an
   exchange kind). No driver ever sees that Jev exists, and no combiner learns a third source.
2. **One consumer per question.** Every question above has exactly one place that reads it. A question
   without a consumer is deleted, not kept for later.
3. **One state, several questions, one call.** Questions run in parallel against the same state, so
   adding breadth is nearly free and one round trip is the cost.
4. **The module still decides.** Ambiguity, thresholds, legality, permission, weight and the fallback all
   stay in code. Jev never makes something legal, never grants a proposal, never bypasses
   `termsAreExplicit()`.
5. **Text in the state is hostile.** Player chat is the adversarial-content case Jev's own docs name. That
   is another reason rule 4 exists, and a reason criteria must be written to name the exact condition.
6. **Filter the state.** Jev's accuracy drops with irrelevant material, so send only the fields a question
   needs — never the whole conversation context the language lane builds.
7. **Answer once, then read the column.** A message's classification is computed once and stored, the way
   the module already stores appraisals and evaluations; a later reader reads the row and never re-calls.
8. **Guards reuse, not reinvent.** The existing `DriverCircuitBreaker`, `DriverResponseLimit` and payload
   cap apply to this call too; the ledger reservation and the monthly wall apply unchanged.

---

## 7. How we would know it works, before wiring anything

We can measure both recommendations **without touching gameplay behaviour**, because the module already
persists what we need to compare against:

| Artefact | What it gives us |
| --- | --- |
| `ai_observations` (+ host `battle_reports`, chat tables) | The inputs: real events and real messages |
| `ai_emotional_episodes`, `ai_affect_states` | The shipped appraisal label and intensity per observation |
| `ai_language_requests`, `ai_language_proposals` | Which messages reached the provider lane, and what it proposed |
| `ai_social_exchanges` | Which messages the regex chain did place, and with what terms — the comparison set for the cascade |
| `ai_decision_traces.score_components` | The affect component that reached an actual decision |

The experiment (a standalone script in the register of `scripts/e2e-agentos-recall-benchmark.php`, run
inside the app container, in the same spirit as that benchmark) replays recorded rows through Jev and
prints:

1. **Agreement** — how often Jev's reading matches the shipped branch, and a ranked list of the
   disagreements with the raw numbers beside them. Disagreements are the product, not the failure.
2. **Coverage** — for messages the regex dropped, how many Jev places with a confidence above the gate,
   broken down by language where it is knowable.
3. **Calibration sanity** — the distribution of confidences, and whether "high confidence" answers are the
   ones that agree with the shipped label. If confidence does not track correctness on our own data, the
   gate is meaningless and the whole idea stops here.
4. **Latency and cost** — p50/p95 round trip, tokens, and settled dollars per call, so the budget line is
   arithmetic rather than hope.

**Pass criteria before any wiring:** confidence must separate right from wrong on our data; the coverage
gain on dropped messages must be worth the per-message cost; and neither run may disagree with the shipped
label in a way a human reading the raw evidence considers obviously wrong.

This is the same discipline the driver decisions already follow (`ai:cognition-conformance`,
`e2e-agentos-recall-benchmark.php`, `plan/details/reviews/`), and it is why the recommendations above are
"offline first".

---

## 8. Cost and latency arithmetic

Jev is charged on input only — $0.042 per million tokens, output free — so a question set costs whatever
its state costs.

| Path | State size | Cost per call | Calls | Notes |
| --- | ---: | ---: | --- | --- |
| 5A event appraisal | ~600 tokens | ~$0.000025 | 1 per appraised battle report | Plus FAtiMA's existing p50 408 ms / 403 ms hybrid → roughly 1.0 s total |
| 5I unmatched message | ~500 tokens | ~$0.000021 | 1 per *unmatched* inbound message | Only the messages the regex already drops |

For scale: a thousand appraisals a day is about **2.5 cents a day**; a thousand unmatched messages a day
is about **2 cents a day**. This is not the constraint. The constraints are (i) that it is new spend on a
path that currently spends nothing, (ii) that the account must stay fully believable with the provider
off, and (iii) that our doctrine of zero paid calls for ordinary gameplay is a decision the owner makes
deliberately, not something that erodes because it looks cheap.

---

## 9. The cases that need a consumer before they are worth anything

These two are genuine gaps named by our own docblocks, and Jev is a plausible way to close them — but only
if something downstream actually reads the answer:

- **Aid is never read** (`'aid' => 0.0`). Consequence: `Gratitude` is unreachable on this path, since it
  needs `aid > harm`. Closing it means deciding *which* observations produce aid evidence (an ally
  defending, a transfer received, a warning given) and where that reaches affect. Without that consumer,
  adding a `received_help` question changes nothing and is a gate-2 failure.
- **Threat is never derived** (`'threat' => 0.0`). Consequence: `Fear` needs `threat > harm`, so it is
  effectively unreachable too, and PsychSim's `threat × 2.0` input is driven by a relationship field that
  this path never updates from an attack. The docblock says the answer "needs the follow-up signals the
  experience extractor owns" — so the honest sequence is: build the signal the extractor was always meant
  to produce, then decide whether a model or a rule reads it.

Both are worth recording as findings: **two of the three emotions in our affect taxonomy are currently
unreachable from the only wired appraisal path.** That is a behavioural gap independent of Jev.

---

## 10. Decisions for the owner

1. **Approve the two offline experiments** (5A appraisal agreement; 5I coverage on dropped messages). No
   gameplay change, no new lane, cost in the cents.
2. **If they pass, which one wires first?** My recommendation is 5I as a fallback cascade, because it
   cannot invent terms, it is bounded by an existing validator, and its gain is directly measurable as
   coverage.
3. **The ordinary-path principle.** Wiring either one means the ordinary path can make a paid call. That
   contradicts `budgets.md`'s "0 generative calls" as written, so it needs an explicit owner amendment
   naming the lane, the cap and the provider-off guarantee — not a silent exception.
4. **Aid and threat** (§9): is closing those two gaps in scope, and if so, is the signal built from host
   evidence first?
5. **Confirm the refusals.** In particular O (write-time memory importance) — I recommend refusing it
   outright, because it pays a model on a write path that authored rules already cover.

---

## Sources

- This repository, read 30 September 2026: `app/Contracts/{AffectEngine,SocialCognition,ExperienceEngine,LongTermMemory}.php`;
  `app/Infrastructure/Cognition/{FatimaAffectEngine,FatimaSocialCognition,FatimaCognitionSession,FatimaClient,HybridAffectEngine,HybridSocialCognition,PsychSimSocialCognition,PsychSimClient}.php`;
  `app/Infrastructure/Experience/{CbrKitExperienceEngine,CbrKitClient,HybridExperienceEngine}.php`;
  `app/Infrastructure/Memory/{AgentOsLongTermMemory,AgentOsClient}.php`;
  `app/Domain/{Cognition/NativeAffectEngine,Conversation/NativeSocialCognition,NativeLongTermMemory,Experience/NativeExperienceEngine,Decision/EconomyUpgrades}.php`;
  `app/Actions/{MapObservedBattleReportToStimulusAction,AppraiseObservedBattleReportAction,ClassifyInboundSocialExchangeAction,RecordAiMemoryFactAction,EvaluateAiSocialExchangeAction,RunAiConversationCycleAction,RecordAiLanguageProposalAction}.php`;
  `app/Support/{AffectEngineSelector,SocialCognitionSelector,ExperienceEngineSelector,LongTermMemorySelector,PsychSimTheoryOfMind,DriverCircuitBreaker,DriverResponseLimit,AiRuntimeSettings}.php`;
  `config/cognition.php`, `app/Support/AiSettings.php`, `app/Console/Commands/RunCognitionConformance.php`,
  `tests/Support/InteractsWithCognitionFixtures.php`, `plan/details/specs/external-drivers.md`,
  `plan/details/reviews/2026-09-15-*`, and the Jev/TypeSafe material cited in
  [jev-decision-model.md](jev-decision-model.md).
