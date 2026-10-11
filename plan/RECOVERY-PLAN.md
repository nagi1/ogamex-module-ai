# Recovery plan: from a large machine to a proven player (11 Oct 2026)

Status: **proposal for the owner.** When accepted it replaces the work order in `plan/HANDOFF.md`; `AGENTS.md`
gates and code rules still bind. Every number below was measured in this repository on or before 11 Oct 2026.

Hard constraints this plan respects:

- **Runtime is a CPU VPS.** No local language model and no local classifier, ever. Language models (DeepSeek and the
  others behind `laravel/ai`) and the JEV classifier are reached **only through their APIs**, with budgets.
- The small play model (ONNX, about 290k parameters, 0.2 ms per decision) is a policy network, not a language model or a
  classifier, and stays on the VPS.
- The three gates hold: code never names objects (Gate 1), the smallest mechanism wins (Gate 2), every behaviour is
  something a veteran player does (Gate 3).

---

## 1. Where we are, honestly

### What exists

| Area | Size |
|---|---|
| Module code | 529 PHP files, 48.5k lines, 232 feature test files, 906 commits since 1 Sep |
| Cognition | FAtiMA (affect), PsychSim (theory of mind), CBRKit (cases), AgentOS (memory), native engines as fallback |
| Language | Composer and replies through `laravel/ai` (DeepSeek ladder), JEV classifier stack built and off |
| Play model | Behaviour cloning of our own planner, choice kinds building / research / yard / errand |
| Evaluation | LLM world judge, objective scorecard, noise floor, probe, reach tables |
| Data | 3.7 GB: 112k real players (PUB, 267 universes), OSTATS, 563 real battles (OGMem), forums and Reddit (~550 MB), negotiation and social corpora, OGame engines and bots, SC2 and Catan trajectories |

### What it produces

| Measure | Result |
|---|---|
| Judge politics | 1/3 in almost every world for weeks, whatever changed |
| Judge human-likeness | 1 to 2/3, contradicted by our own counts (9515) |
| Play model vs planner | +0.045, 95% CI −0.049 to +0.139: a tie |
| Fleet scale vs real battles | median 6 attacking ships against 175,000; loot 1,072 against 129M |
| Noise floor | coefficient of variation 0.5 to 1.1 on the social counts of one world |
| Forum against ours | a word classifier spots our lines 87% of the time |

### Why: six root causes

1. **The model imitates our own planner, so it can never beat it.** Parity is the ceiling, not a bug. Per-choice
   outcomes are not measurable at account level: a random legal choice instead of the teacher's changed 6 h production
   growth by −0.0002 ± 0.002 against a spread of 0.14. Capacity is not the limit (22k to 1.1M parameters: 0.80 to 0.82),
   data is flattening (+1 point per doubling), and the one big gap was missing inputs (yard 0.61 → 0.81 when the doctrine
   gap was added, early data, 11 Oct).
2. **No ground truth for the goal.** "Indistinguishable from humans" is graded by a language model reading one 12-hour
   all-bot world. With a noise floor near 1, single-world readings steered the work in circles.
3. **The world cannot show what we grade.** 16 to 18 bots, staged into the late game, 12 to 24 sim hours. Real politics
   and great wars take weeks and hundreds of players.
4. **The simulator is too slow to learn in.** 24 sim hours of 12 accounts take 6 to 8 real hours. Reinforcement
   learning needs millions of decisions.
5. **Complexity outran evidence.** Four cognitive drivers, memory, language and social systems ship with no ablation
   showing any of them changes an outcome. AgentOS ranks at most 50 memories with a word-hashing embedder, so the
   "vector memory" matches words, not meaning.
6. **The behavioural data sat unused.** The 112k real players with category scores, planets, moons and alliances are
   exactly "what an observer sees"; we used them for two calibration numbers.

### What we stop now

- Judging progress from one world or from the judge's score. The judge stays as a bug-finder.
- Launching worlds to re-check unchanged code, and changing code under running batches.
- Adding systems, lines or labels before the benchmark (section 5) shows the current ones matter.
- Treating behaviour cloning of our planner as the road to a stronger player.

---

## 2. Using the data we have

One pipeline, not one-off scripts: **catalogue → one extractor per kind of knowledge → one knowledge store with
provenance → consumers** (benchmark, doctrine YAML, simulator spec, tests, line library). Every claim carries its
source, an evidence count and a validation sample.

| Kind of knowledge | Sources | Extractor | What it becomes |
|---|---|---|---|
| Real player behaviour | PUB (112k players), OSTATS (hourly curves, alliances), OGMem (battles) | Statistics and fitted conditional models: score split by category vs rank, planets and moons per player, alliance size and membership, growth per hour, battle size vs economy | The observer benchmark; doctrine targets; staging distributions |
| Game mechanics | 2Moons ×3, SteemNova, oClassic, New-Star, OPBE, Ogniter, Galaxytool | LLM code reading (API) to formula specs, each checked against our host by a test | The fast simulator's spec |
| Other bots' strategy | ogame-agi, ogamebot, ogame-agent, 7omate | LLM code reading to heuristics | Baselines to compare our planners against |
| Player talk and norms | EN/DE/US forums, Reddit, archives | Retrieval-grounded LLM extraction (section 3) with clustering to find what exists | Veteran situations (tests), doctrine candidates, real lines per situation |
| Human negotiation and deception | LLMafia, Werewolf, Discord, CaSiNo, Deal-or-No-Deal, STAC, Avalon, Diplomacy and Cicero | Labels and strategies read as-is; JEV prompts calibrated on them | Classifier prompts and gold sets; negotiation moves for the social layer |
| Strategy-game trajectories | SC2-Dynamics (765k rows), Catan 43k | Method check only | A sanity benchmark for the learning pipeline |
| Our own worlds | BC rows, 52k social lines, judge files | Scorecard and benchmark | Regression evidence |

Order: real player statistics first (they define the benchmark), forum extraction in the background, code mining for the
simulator, social corpora, trajectories last.

## 3. Language models and JEV, API only

### The retrieval layer: AgentOS, switched on properly

The installed `@framers/agentos` 0.10.16 already ships what a retrieval pipeline needs: HNSW, SQLite, Postgres and
Qdrant stores, BM25 and hybrid search, HyDE, RAPTOR, GraphRAG, a semantic chunker, cross-encoder rerankers and a citation
verifier. We use the in-memory store and a hashing embedder. Plan:

- Embeddings come from an **embedding API** (no local model), cached per text, so a corpus is embedded once.
- One persistent store (SQLite vectors or HNSW) for the corpus, and one for account memories and curated lines.
- Hybrid search (BM25 + vector), then a rerank, then the citation verifier on anything written from it.
- **Needs owner approval:** the store dependency (`hnswlib-node` or SQLite vectors) and the embedding provider and key.
  A hashing-vs-real recall test on fixed situations decides whether play-time memory switches over.

### How we use retrieval (RAG)

Retrieval runs locally in AgentOS (no model on the VPS: embeddings come from an API once and are cached); writing, when
there is any, is a language model through the API. Every use below is grounded: the model sees retrieved evidence and
must cite it, and the citation verifier rejects claims the evidence does not support.

| Use | Retrieved from | Feeds | When |
|---|---|---|---|
| Knowledge extraction | Forum, Reddit, archives, guides | Cited claims with evidence counts in the knowledge store | Offline |
| Veteran situations | Threads on the same kind of event | Situations corrected by real cases, then tests | Offline |
| Line library | Real messages from the same situation | Lines in the account's voice, YAML | Offline |
| Doctrine proposals | Repeated advice, with thread counts | Doctrine YAML candidates for review | Offline |
| Simulator spec | Engine source files (2Moons, OPBE, SteemNova) | Formula specs, each checked against the host | Offline |
| Grounded judge | Real cases like the world's events | Bug reports that say "unlike these real cases" | Offline |
| Coverage search | The corpus against our worlds' chat and actions | Situations our accounts never handle | Offline |
| Design questions | The whole corpus | Answers with sources for the owner and agents | On demand |
| Account memory | The account's own facts and episodes | Recall by meaning instead of shared words | Play |
| Composer context | Account memory plus curated real lines | Lines grounded in history and real phrasing | Play, capped |
| Ambiguous messages | Labelled precedents from the corpus | Context for the rare model read of a hard message | Play, capped |
| PvE director | Campaign history and past operations | Recommendations the deterministic validator checks | Play, capped |

Measured, not assumed: recall at 5 on a fixed question set, citation precision on a sampled 100 claims, and for play-time
memory the share of replies that cite a true fact, each against the current hashing ranker.

### Where the language model is used

| Where | When | What it does | Guard |
|---|---|---|---|
| Knowledge extraction | Offline | Reads threads, guides and engine code into structured claims, citing retrieved evidence | Citation verifier; sampled precision check |
| Line library | Offline | Writes lines per situation from retrieved real lines, in the account's voice | Real-vs-ours check, fact check, slop list; output is YAML |
| Judge and bug-finder | Offline | Reads worlds against retrieved real cases | Never the gate |
| Composer | Play, optional | Words a decided line from the engines' read of the other player and retrieved examples | Daily caps per universe, player and conversation; authored line on any failure |
| Hard social reads | Play, rare | A long or ambiguous human message the classifier cannot settle | Same caps; rules decide the action |

Decisions never come from a language model. Prompts live in YAML and each has an evaluation (`ai:eval-composer` is the
pattern: kept, fell back, slop words, distinct lines, facts kept).

### Where JEV is used

| Where | What it labels | Budget rule |
|---|---|---|
| Inbound human messages | Intent (truce, threat, trade, recruit, insult, question), tone, whether it names terms | One call per inbound message, cached by text hash |
| Offline labelling | Forum and corpus posts for the knowledge store and gold sets | Batch, metered |
| Quality gates | Every generated line before it enters the library | Batch |

JEV accuracy is measured on a hand-checked OGame gold set (about 600 messages, split by thread) and on the negotiation
corpora labels. **Blocked on the owner:** `TYPESAFE_API_KEY` (the stack is built and off since 8 Oct).

### Cost control

Each API lane has a daily ceiling, a cache, a provider ladder (cheap model first) and a fallback to authored text or
rules. The status page shows spend per lane.

## 4. Social cognition, economy and play

### Social

- **Commitments that bind** (truces work; NAPs and pacts next): terms, end date, enforcement through `Enemies::spared()`,
  breach detection, apology and compensation paths, memory of who broke what.
- **Situations from data:** the veteran situations, checked against threads, each with a test. First set: NAP to the
  alliance before answering, apology after a breach, recruit spy-history check, fleet save never at the same minute,
  hit report shared, peer war chosen over farming solos, pact against a common enemy.
- **Talk with substance:** every line carries facts the account actually knows (hits, terms, coordinates, memories).
- **Breadth and pacing:** contact spread, reply latency and channel activity follow real distributions (section 5).

### Economy

- **Doctrine from data:** score split by category against rank and age from the 112k real players becomes doctrine YAML
  targets the planners already read (no new class).
- **Feature completeness:** every input a planner decides on must be in the model's row (the yard lesson). An audit per
  choice kind lists the planner's inputs and the row's columns.

### Play (war)

- **Peer war over farming:** the strongest club chooses a peer target over solos when one exists (judge evidence 9512 to
  9518).
- **Real-scale staging:** worlds staged from the real point distribution instead of three fixed budgets.
- **Coverage of every object and mission** stays the standing check (`play-coverage.md`), read by counts per world.

## 5. Test, verify and validate fast, at scale, without stalling

### The observer benchmark (the single gate)

Computed identically for real players and for ours:

| Signal | Real source |
|---|---|
| Points split by category against rank and age | PUB |
| Planets and moons per player | PUB |
| Alliance membership, size, churn | PUB, OSTATS |
| Growth per hour | OSTATS |
| Activity pattern across the day, reaction latency | OSTATS hourly curves, account-authenticity research |
| Battle frequency and size relative to economy | OGMem |
| Social: commitments kept vs broken, distinct lines, greeting share, contact breadth | our worlds against the forum-derived norms |

Score: a real-vs-ours classifier (0.5 means indistinguishable) plus a distance per signal, reported on at least 8 paired
seeds with a confidence interval. Frozen and versioned; a change of the benchmark is its own commit.

### Three speeds of test

| Speed | What | Turnaround |
|---|---|---|
| Seconds | Feature tests, scripted situations (social and play), invariants (no hidden reads, saving goal held) | per commit |
| Minutes | Snapshot and fork: one saved late-game world, branched with and without a change, read on the benchmark | per change |
| Hours | Batches of paired worlds (8 to 16 seeds) on one frozen commit, read only on the benchmark | per milestone |

### Not stalling

- One batch, one commit SHA, recorded in each world; no edits under running sims.
- Generation retries killed universes (done, 9aa8003); the status page separates slow, lost and queued.
- Parallel sims sized to the container's CPU cap; health checked by the collector, not by hand.
- The fast simulator (section 6) takes the high-volume work off the PHP sim.

## 6. Training a model that knows OGame in every phase

### Why the current loop cannot get there

Imitating the planner caps the model at the planner. Outcomes per choice are invisible at account level. The PHP sim is
too slow for reinforcement learning.

### The new loop

1. **Complete the inputs.** Audit every choice kind against its planner (yard done, schema v4). Errands next (0.78
   held out), then research and building edge cases.
2. **Cover every phase.** Staging from real distributions plus one long start-to-late lifecycle run per batch, so early,
   mid and late rows exist in proportion.
3. **A fast abstract simulator.** OGame's economy is formulas: production, storage, build and research times, fleet
   speed and fuel, slots, bashing limit. Written vectorised (Python on the GPU, or Rust), it runs thousands of times
   faster than real time. Combat calls the existing Rust battle engine. The spec comes from the engine code mining
   (section 2) and each formula is tested against the host.
4. **Reinforcement learning there, the planner as a prior.** Start from the behaviour-cloned model, train with PPO on
   reward from growth, losses and battle outcomes, with a penalty for drifting far from the planner early on. Self-play
   for attack and defence: raider policies against defender policies, fleet saves against timed hits.
5. **New choice kinds where the value is.** Raid target and wave, fleet composition for a strike, fleet save timing,
   defence mix under threat, moon and jump-gate moves. Each enters behind the choice seam (`app/Domain/Choice`).
6. **Verify on the real thing.** Every model is checked in PHP worlds against the planner on the competence axis (growth
   against OSTATS curves for the same rank, battle loss ratios against OGMem), 16 or more pairs, at least 3 days.
   Installed only on a win; the planner stays the fallback.

### Knowledge, not just imitation

Doctrine targets from real players, formulas from engines, veteran situations as tests, and the engines' state as model
inputs (affect, trust, threat, theory-of-mind stance) all reach the model as numbers or YAML, never as object names.

## 7. The PvE Empire: an NPC worth playing against

PvE and PvP want different things. PvP accounts must be indistinguishable from humans. The PvE Empire is disclosed AI: it
must be **hard, fair and fun**. The spec is `plan/details/specs/pve-empire.md`; this plan adds how it gets strong.

| Need | How |
|---|---|
| Strong economy | The trained economy policy at its best skill band, no human-like slack |
| Coordinated defence | Campaign coordinator assigns strongholds; ACS defend, fleet saves on phalanx sightings, counter-hits on exposed fleets |
| Smart offence | Self-play-trained strike policy from the fast simulator: target choice, wave timing, moon and jump-gate use |
| Fairness | Only legal information (reports, phalanx, galaxy), normal fuel, slots and travel time; difficulty disclosed before a campaign, never raised mid-campaign |
| Worth playing | Difficulty bands tuned between campaigns so a committed coalition wins about half the time; failures are recoverable; rewards are loot and recognition |

Measured by coalition win rate, campaign completion, player return rate and a short post-campaign survey.

## 8. Our cognitive engines, used for what they are good at

Each engine gets one job, one metric decided in advance and an on/off ablation on the benchmark. It stays only if it
moves its metric.

| Engine | Its job | Metric |
|---|---|---|
| AgentOS | Retrieval for memory and corpus (section 3) | Recall of the right fact on fixed situations; replies citing a true fact |
| PsychSim | Predict whether a counterpart keeps or breaks a deal | Accuracy of break predictions against what happened in worlds |
| CBRKit | Reuse past raid and defence outcomes | Raid loss ratio and empty-raid rate against the native path |
| FAtiMA | Mood that changes how the account acts and speaks (grudges, caution) | Retaliation and caution patterns against forum-derived norms; drop if it changes nothing |

Engine state also becomes model inputs and composer context (the composer side is done, 25ea06e). Native engines stay the
fallback.

## 9. What the north star needs that this list did not name

| Gap | Why it matters | Where it goes |
|---|---|---|
| **Human activity realism** | Uptime shape across the day, sleep, reaction latency to probes and attacks, saves that sometimes fail: the strongest tells | Benchmark signals and persona routines |
| **Persona diversity and skill spread** | Real servers have casuals, miners, raiders, bad players; ours converge | Personas sampled from real distributions; skill bands with real mistakes |
| **Information hiding** | An account that knows what it could not see is caught | Stage 3 invariant `no-hidden-reads` |
| **Long time horizons** | Politics, grudges and wars live over weeks | Lifecycle runs and memory consistency checks across weeks |
| **Robustness to human exploitation** | Humans will probe, bait and farm bots | Scripted adversarial situations (bait fleets, fake truces, message spam) |
| **Disclosure and fairness policy** | Undisclosed bots in PvP is a product and legal decision | Owner decision, written down |
| **Cost and scale on the VPS** | Thousands of accounts on 2 vCPU; alliance life is ~820 statements per login; API spend | Budgets per lane; the performance pass after the account is strong |
| **Mods and new objects** | Gate 1: an added object must be playable with no module edit | A mod-object test in the benchmark batch |
| **Observability** | The owner must see spend, health and the benchmark at a glance | The RL status page grows a benchmark panel |
| **Human ratings** | The final judge of human-likeness is a human | Owner rates 20 blind chat excerpts and 10 account histories once per milestone |
| **Premium officers** | OFF-003 taste rows and OFF-004 scorecard aspect are open | Economy track |

## 10. Roadmap

| Week | Deliverable | Exit test |
|---|---|---|
| 1 | Observer benchmark v1 from PUB, OSTATS and OGMem; benchmark run on existing worlds | Real-vs-ours score for our current worlds, with CI |
| 1 | AgentOS store and API embeddings; full forum extraction started | Recall test passes; first 1,000 cited claims |
| 2 | Doctrine targets from real players; peer-war and pact situations as tests | Benchmark moves on 8 paired seeds |
| 2 | Engine code mining into a simulator spec, each formula tested against the host | Spec covers economy, fleets, fuel, slots |
| 3 | Fast simulator v1 with the Rust battle engine | 1,000× real time; matches PHP worlds on growth within tolerance |
| 3 | Engine ablations on the benchmark | Keep or freeze each engine |
| 4 | RL on the fast simulator from the cloned model | Beats the planner in the fast sim |
| 5 | Verified in PHP worlds, 16 pairs, 3 days | Wins on the competence axis; benchmark not worse |
| 6 | PvE Empire on the trained policies; first internal campaign | Coalition win rate in the target band |

## 11. Decisions the owner owns

1. Accept the observer benchmark as the definition of success, with the judge demoted to bug-finder.
2. Approve the fast simulator project (weeks 2 to 4).
3. Approve two dependencies for AgentOS retrieval (a vector store and an embedding API provider and key).
4. Provide `TYPESAFE_API_KEY` for JEV.
5. Agree that engines failing their ablation are frozen and later removed.
6. Decide the disclosure policy for AI accounts on PvP servers.
7. Set the monthly API budget across the language model, embedding and JEV lanes.
