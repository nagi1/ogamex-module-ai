# Language models and classifiers across the whole game and the whole AI

Research note, 8 Oct 2026. It asks, aspect by aspect, where a generative model (LLM) or a classifier (Jev, or a small
model distilled from either) would make an account play more like a good human, or make the module faster to build,
and where neither belongs. Written from the module's code and the game's mechanics, not from the earlier Jev notes.

## 1. Ground rules this map is judged by

1. **The host is the referee and the numbers decide.** Combat, flight times, production, costs and requirements are
   exact data. A model guessing at them is slower and worse than the formula. Models read and write *language*.
2. **Text is the boundary.** Wherever play passes through words (a message, a board, a wiki page, a report a human
   reads, a log a developer reads), a model can turn words into facts or facts into words. Rules act on the facts.
3. **Ordinary play makes zero generative calls** (decision baseline). Thousands of accounts on a 2 vCPU box run on
   rules. A model call is scarce and is spent on contact with a human or offline.
4. **Never the decision, never the reward.** A model reads meaning or writes words; the planners, the social engines
   and the trained policy decide. A model label is never an RL reward.
5. **Off by default, identical without it.** Every use sits behind a setting; a world replay with the provider off is
   byte-identical; tests use the fake.

### The distillation ladder (the idea that makes most of this affordable)

| Rung | Cost | Where it runs | Use |
| --- | --- | --- | --- |
| LLM (hosted, or local on the dev GPU) | high | offline, or one human-facing reply under budget | author, judge, label |
| Jev classifier | medium | hosted, cached by message hash | read meaning live where a human wrote it |
| Distilled small model (logistic or tiny transformer trained on LLM/Jev labels) | near zero | in process on the VPS | the same read at scale, on every message |
| Authored rule / YAML | zero | everywhere | the fallback and the default |

A use starts at the top offline, earns its keep, and moves down: the LLM labels a few thousand examples, Jev or a
distilled model takes over the live read, and a pattern that turns out regular becomes a rule. Most "classifier in the
game" ideas below are only affordable at thousands of accounts on the third rung.

Verdicts: **Build** (clear gain, small mechanism), **Measure** (promising, prove it on data first), **Offline**
(useful only outside play), **Refuse** (a model would be worse or breaks a rule).

## 2. Every aspect of the game

### 2.1 Economy: mines, energy, storage, production percentages

| Possible use | Tool | Verdict | Why |
| --- | --- | --- | --- |
| Choosing the next mine or storage | either | Refuse | Payback maths in the host formulas plus the trained policy; a model only adds error |
| Turning wiki economy advice ("plasma before mine 30 for crystal") into doctrine YAML | LLM extract, Jev check | **Build, offline** | The owner's strategy source is the wiki; this is the pipeline from page to `resources/doctrine/` |
| Explaining an account's economy to the owner ("why is metal flat since hour 6?") from `ogamex economy` output | LLM | Offline | Developer time, not play |
| Detecting "this build order does not read like a pro opening" over a readable trace | LLM judge | **Offline** | A gate-3 regression alarm per world |

### 2.2 Research

| Use | Tool | Verdict | Why |
| --- | --- | --- | --- |
| Pick research | either | Refuse | Requirement graph is host data |
| Wiki research paths per playstyle and phase into doctrine | LLM extract, Jev check | **Build, offline** | Same pipeline as 2.1 |
| Reading a human's "go astro first, colonies win" advice in alliance chat as a hint | Jev | Measure | Players do act on mates' advice; the hint would weight a doctrine option the account already has, never add one |

### 2.3 Buildings and stations, fields, terraformer, moon stations

| Use | Tool | Verdict | Why |
| --- | --- | --- | --- |
| Field budgeting, station order | — | Refuse | FacilityChain plus host requirements |
| Late-game technique pages (nanite, terraformer, lunar base, gate chains) into YAML | LLM extract | **Build, offline** | Late game is first-class in the owner direction and the wiki covers it |

### 2.4 Shipyard and defence

| Use | Tool | Verdict | Why |
| --- | --- | --- | --- |
| Fleet composition and rapid-fire counters | — | Refuse | The simulator and the rapid-fire table are exact |
| Defence doctrine ("rocket fodder plus plasma") from the wiki | LLM extract | **Build, offline** | Fills `defence-doctrines.yaml` from sources |
| A human's "my fleet is all bombers" or "watch out, he has 20 deathstars" in chat | Jev | Measure | A claim, weighed against espionage; useful only as a reason to scout sooner |

### 2.5 Espionage and the galaxy view

| Use | Tool | Verdict | Why |
| --- | --- | --- | --- |
| Target choice, probe counts | — | Refuse | Structured reports and IntelBook |
| Reading a player's profile text, alliance board, planet names ("farm", "vacation", "noob", "fleet gone") | Jev | **Build** | Real players read the galaxy and the boards; names and texts carry intent the numbers do not |
| Distilled "this planet name or alliance text says inactive / bait / do not touch" for every galaxy row | distilled | Measure | Only affordable on the third rung |

### 2.6 Raids, attacks, ACS, moon destruction (every attacking mission)

| Use | Tool | Verdict | Why |
| --- | --- | --- | --- |
| Profit, losses, fuel, waves | — | Refuse | RaidPlanner and the simulator |
| A human mate's "ACS on 3:120:8 at 22:00, join" | Jev (choice + validated coordinates and time) | **Build** | The only way a human leads AI mates into a union; the existing ACS join flies it |
| A human target's "stop or my alliance hits you" | Jev | **Build** | Feeds the grudge and alliance-strength check that decides whether to back off |
| Taunts after a battle ("gg", "farmed", "revenge soon") | Jev | Build | Sets grudge, revenge intent and the reply; humans remember who taunted them |
| Battle-report prose for the owner ("why did this crash fail?") | LLM | Offline | Debugging aid |

### 2.7 Fleet save, phalanx, ninja, recall

| Use | Tool | Verdict | Why |
| --- | --- | --- | --- |
| Save timing, recall maths | — | Refuse | Event times are exact |
| "I'm coming for you tonight", "see you at 3am" | Jev (threat + rough time) | **Build** | Brings the save forward and keeps the account online: the reaction latency humans measure |
| Mate warns "big fleet heading to your moon" | Jev | **Build** | Same seam; a warning a human gives a mate must not be ignored |

### 2.8 Expeditions

| Use | Tool | Verdict | Why |
| --- | --- | --- | --- |
| Expedition outcome text (the host's flavour lines) | — | Refuse | Outcome is structured |
| Wiki expedition strategy (slot use, fleet size vs top player points) | LLM extract | Build, offline | YAML for `expedition.yaml` |

### 2.9 Colonisation and relocation

| Use | Tool | Verdict | Why |
| --- | --- | --- | --- |
| Slot and temperature choice | — | Refuse | Formula |
| Colony naming that reads human ("Colony", in-jokes, alliance tag) | LLM authored offline into a name pool | Offline | One-time pool, zero calls in play; uniform names are a bot tell |

### 2.10 Trade, merchant, resource deals between players

| Use | Tool | Verdict | Why |
| --- | --- | --- | --- |
| Parsing "3:2:1, 500k crystal for your deut?" into a structured offer | Jev (shape) + regex for numbers | **Build** | A human trade arrives as text; QueueableTradePlanner decides and the transport flies |
| Haggling reply to a human | authored, LLM under budget | Build | Words only; terms come from the trade planner |
| Detecting a scam ("send first, I'll pay later" from a stranger with no history) | Jev score + relationship history | Measure | Weighs against trust; never alone |

### 2.11 Missiles and the jump gate

Mechanically pure: Refuse for decisions. The only text use is wiki technique into YAML (offline) and reading a mate's
"IPM his defence before the ACS" as a coordinated request (Jev, Measure).

### 2.12 Alliances, diplomacy, applications, boards, broadcasts

| Use | Tool | Verdict | Why |
| --- | --- | --- | --- |
| A human leader declares war, offers, accepts or breaks a pact | Jev choice + tag resolved | **Build first** | The only path from human diplomacy into `ai_alliance_relations` |
| A human application: serious, farm, spy (enemy alt), spam | Jev | **Build** | The leader review refuses a spy like an exploitative stance |
| Alliance external texts: "War: [X]", "NAP [Y]", "recruiting 500k+", "closed" | Jev | **Build** | AI accounts apply where they would be taken, avoid partners, pick sides in human wars |
| Writing the AI leader's application text, recruitment pitch, war declaration to humans | authored, LLM variety pool offline | Build | Players read these; one template everywhere is a tell |
| Detecting a conspiracy forming against the AI's alliance from leaked messages | Jev | Measure | Rare; a lead for the theory-of-mind engine, not a fact |
| "Is this alliance's week real politics?" over broadcasts and relations | LLM judge | **Offline** | The cohort report for the great war |

### 2.13 Private chat, buddies, the social side

| Use | Tool | Verdict | Why |
| --- | --- | --- | --- |
| Speech act of each inbound message (greeting, question, threat, plea, offer, insult, small talk) | Jev, then distilled | **Build** | The route policy decides reply or silence; the impact policy moves trust, threat, affinity |
| Does this message need an answer at all, and how soon | Jev | **Build** | Humans leave messages unanswered and answer threats fast; uniform reply latency is a bot tell |
| Language and register of the human | Jev | **Build** | Answer in their language and tone |
| The reply text to a human | authored first, LLM under budget | Build (exists) | Words only, after the module decides what to say |
| Outbound guard: does this line read as a bot, leak internals ("threat 0.8"), contradict the account's actions, or have the wrong language | Jev | **Build** | Cheapest protection of the main goal: one robotic line outs an account for good |
| Write-time importance of a message for memory (a promise, betrayal hint, insult) | Jev score | **Build** | Replaces keyword rules in memory importance with something that reads human phrasing |
| Detecting that a human suspects a bot ("are you a bot?", "you reply instantly") | Jev | **Build** | The account changes pacing and answers like a player; ignoring it confirms the suspicion |
| Buddy requests from strangers: friendly, farm-scouting, spam | Jev | Build | Feeds the buddy review |

### 2.14 Highscore, ranking, public curve

Pure numbers: Refuse for decisions. Offline, an LLM can read a world's public growth curves next to real servers'
and say which accounts look automated (a judge for the authenticity goal).

### 2.15 Premium, officers, dark matter, vacation mode

Decisions are rules over host data. One text use: a human's "going on holiday" in chat or board predicts vacation mode
or inactivity (Jev, Measure), which the farm and war logic would weigh against host status.

## 3. Every aspect of the AI

### 3.1 Perception and intel

| Use | Tool | Verdict | Why |
| --- | --- | --- | --- |
| Structured perception (reports, events, galaxy) | — | Refuse | Already exact |
| Every free-text channel a human writes into (chat, boards, names, applications) becomes typed facts with a validity window | Jev, distilled at scale | **Build** | One reader, many consumers; the fact store is the seam, not each planner |

### 3.2 Memory

| Use | Tool | Verdict | Why |
| --- | --- | --- | --- |
| Write-time importance | Jev score | Build | 2.13 |
| Summarising a long relationship into the few lines a reply needs | LLM offline into a stored summary, or none | Measure | Only for human-facing replies; must not add calls per message |
| Semantic recall | embedding model | Measure | Has its own activation gate; keyword plus validity windows first |
| Selective forgetting | — | Refuse | Decay and validity rules |

### 3.3 Affect, social cognition, theory of mind (FAtiMA, CiF, PsychSim, native)

| Use | Tool | Verdict | Why |
| --- | --- | --- | --- |
| Appraisal, stance, volition | engines | Refuse for models | The drivers own these; a model would duplicate them |
| Evidence the engines read: "this message is a threat", "this is an apology", "this reads as a lure" | Jev | **Build** | The engines need typed evidence from text; the classifier supplies it, the engine weighs it |

### 3.4 Persona and authenticity

| Use | Tool | Verdict | Why |
| --- | --- | --- | --- |
| Persona-specific voice (a terse raider, a chatty miner, a non-native speaker's errors) | LLM offline into per-persona phrase pools | **Build, offline** | Variety at zero play cost |
| "Does this account's day read like a human's?" over the action log, uptime and messages | LLM judge | **Offline** | The authenticity goal measured without a human panel; tune pacing YAML from it |
| Self-similarity of message texts across accounts | distilled embedding | Offline | Catches thousands of accounts writing the same line |

### 3.5 Planning and decisions (all Queueable*Planner, UtilityScorer, DecisionEngine)

| Use | Tool | Verdict | Why |
| --- | --- | --- | --- |
| Making the choice | — | Refuse | Rules and the trained policy |
| An LLM as a strategy advisor in the loop | LLM | Refuse | Latency, cost, non-determinism; the CampaignConsultation seam stays gated off |
| Explaining a decision trace in words for the owner | LLM | Offline | Debugging |

### 3.6 Scheduling, login rhythm, reaction latency

| Use | Tool | Verdict | Why |
| --- | --- | --- | --- |
| Urgency of an inbound human message changes the next login time | Jev | **Build** | The single biggest observed tell is reaction latency; a threat must pull the account online |
| Session shape | — | Refuse | Persona rules |

### 3.7 Doctrine and strategy authoring

| Use | Tool | Verdict | Why |
| --- | --- | --- | --- |
| Wiki page → classified paragraphs (phase, playstyle, mechanic, actionable) | Jev | **Build, offline** | The owner direction's strategy source |
| Actionable paragraph → YAML key an existing planner reads | LLM | **Build, offline** | Behaviour before sources: only keys a planner reads |
| YAML entry ↔ source agreement check | Jev or a second LLM | **Build, offline** | Stops invented strategy entering doctrine |
| Conflicts between two pages' advice surfaced for the owner | LLM | Offline | Owner decides |

### 3.8 Learned policy (plan/rl)

| Use | Tool | Verdict | Why |
| --- | --- | --- | --- |
| Reward from a model label | — | Refuse | Binding rule |
| Filter implausible teacher decisions out of imitation data | LLM judge | Measure, owner approval | Data cleaning, not reward; owner decides |
| Naming clusters of states for a human-readable training report | LLM | Offline | Explains what a retrain changed |
| Object identity as a feature from text | — | Refuse | Binding rule |

### 3.9 Evaluation, scorecard, the late-world loop

| Use | Tool | Verdict | Why |
| --- | --- | --- | --- |
| Labelling every battle as farm, war battle, ninja, crash, failed save, ACS | rules first; LLM only for the prose summary | Build (rules) | Battle data is structured; the scorecard should count these |
| Reading a whole world's broadcasts, chats and relations and grading "great war happened, politics plausible" | LLM judge | **Offline, build** | Replaces manual reading of `reach.txt` and logs each iteration |
| Diffing two worlds in words ("9394 had pacts; 9393 did not") | LLM | Offline | Faster iteration |

### 3.10 Development harness and tooling

| Use | Tool | Verdict | Why |
| --- | --- | --- | --- |
| Harness writer for impl rows | LLM (exists) | Keep | Strong lane reviews |
| Gate 2 reviewer on diffs | LLM (exists) | Keep | |
| Triage of failing world logs into a task row | LLM | Build, offline | Turns a failed world into a candidate row with evidence |

### 3.11 Operations and safety

| Use | Tool | Verdict | Why |
| --- | --- | --- | --- |
| Moderation of inbound human text (abuse, personal data) before storing or replying | Jev | **Build** | An account must not store or echo abuse or personal data |
| Prompt-injection screen on any human text that reaches an LLM reply | Jev | **Build** | Humans will try "ignore your instructions"; the reply path must refuse quietly |

## 4. What to build, in order

| # | Use | Rung | Aspect moved | Proof (observer sees) |
| --- | --- | --- | --- | --- |
| 1 | Outbound guard on every human-facing line | Jev | authenticity, chat | no line with internals or wrong language reaches a human; robotic lines fall back |
| 2 | Inbound speech act + urgency + language + bot-suspicion | Jev → distilled | chat, login rhythm, fleet save | a threat pulls the account online within minutes; small talk sometimes goes unanswered |
| 3 | Human diplomacy into `ai_alliance_relations` (war, pact, break) + alliance boards | Jev | alliances, great war | a human leader's declaration puts the AI alliance at war |
| 4 | Human requests: ACS call, help, trade offer | Jev + validated numbers | ACS, defend, trade | an AI mate joins a human's union; a human trade is answered with a transport |
| 5 | Wiki → doctrine pipeline | LLM + Jev, offline | every planner's taste | new doctrine YAML with source citations, checked |
| 6 | World judge for the late-world loop | LLM, offline | evaluation speed | each world ends with a graded summary of wars, politics and gate-3 failures |
| 7 | Persona phrase pools and names | LLM, offline | authenticity | message self-similarity across accounts falls |
| 8 | Memory importance, moderation, injection screen | Jev | memory, safety | |
| 9 | Distil 2 and 8 into in-process models | distilled | cost at scale | same verdicts, zero hosted calls |

Measure before building: the trade-scam score, lure detection, galaxy-name reading, teacher-data filtering, semantic
recall.

### State, 8 Oct 2026: all nine built, off by default

Everything goes through laravel/ai: `Classification` (Jev on the `typesafe` provider) for every read, structured
`Agent`s for extraction, judging and voices, and the SDK fakes in every test. One switch, `AI_CLASSIFICATION_ENABLED`,
plus `TYPESAFE_API_KEY`; the language-model commands use the configured `AI_LANGUAGE_PROVIDER`.

| # | Where | Run or switch |
| --- | --- | --- |
| reader | `ReadAiTextAction`, `ai_text_readings` (cache + labels), daily cap, failure pause | `AI_CLASSIFICATION_ENABLED=true` |
| 1 | `GuardAiOutboundTextAction` in both deliver actions and before a provider reply replaces the authored one | on with the reader |
| 2 | `ReadAiHumanMessageAction` (act, urgency, needs reply, language), `ReactAiToHumanMessageAction` on the language lane | on with the reader |
| 3 | `AdvanceAiAlliancePoliticsAction::answerHumanLeader`, alliance pages, `applicationReadsAsSpy` | on with the reader |
| 4 | mate's ACS / help call with coordinates → enemy | on with the reader |
| 5 | `ai:doctrine-proposals <wiki files>` → `plan/research/ogame/doctrine-proposals.yaml` | offline |
| 6 | `ai:judge-world --sqlite=storage/rl/late/<seed>/end.sqlite --out=…/judge.md` | offline |
| 7 | `resources/behavior/social-lines.yaml`, `ai:write-social-voices <archetypes>` → `social-voices.yaml` | offline, review |
| 8 | memorable weight, manipulation and abuse keep a message off the provider | on with the reader |
| 9 | `ai:export-text-readings <purpose>` → `python -m ogrl.distil_text … <question> resources/models/text/<purpose>.<question>.json`; used only at ≥500 readings and ≥0.95 held-out agreement | offline, then automatic |

Open: the trade offer cannot be completed (no transfer path is wired for a message), and the language read is stored
but not yet used to answer in the human's language.

## 5. Refused everywhere

Choosing builds, research, ships, defence, targets, fleet sizes, timings or stances; battle or loot estimates; an LLM
advisor in the login loop; any model label as an RL reward; object identity read from text into the policy; any call
that a world replay would need to reproduce.
