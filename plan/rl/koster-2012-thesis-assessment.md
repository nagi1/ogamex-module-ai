# Assessment: Koster (2012), *Reinforcement Learning and the Parallel Actions Common Goal problem*

Research assessment written 10 October 2026. **No production code was changed.** This document evaluates
whether the thesis can materially advance the learned-economy work already specified in `plan/rl/`. It does not
re-propose that work; it says what the thesis adds to it, what to copy, what to modernise, what to throw away,
what to test first, and what result would justify continuing.

Primary source: `https://cs.ru.nl/bachelors-theses/2012/Ivan_Koster___0814903___Reinforcement_Learning_and_the_Parallel_Actions_Common_Goal_problem.pdf`
(80 pages, read in full: model ch. 2, simulator ch. 3, learning agent ch. 4, experiments ch. 5, appendices A–D).
Host mechanics re-verified against `/home/nagi/code/ogamex-next` (the OGameX host) on the date above.

---

## 0. Verdict in one box

| Question | Answer |
| --- | --- |
| **Reuse** | The PACG framing, the *afterstate + wait/no-wait* action design, the event-jump scheduler, the "beat the hand policy" success definition, the γ-horizon finding, and the "warm-start exploration with a hand-written rule policy" idea (which is our behaviour cloning). |
| **Modernise** | Tabular Q and per-action ANNs → one shared candidate-scoring net (already chosen, `app/Domain/Choice`); per-episode backward experience replay + no target network → PPO over a replay/n-step baseline (already chosen); the single scalar milestone reward → ΔV with a time-scaled discount (`plan/rl/reward-design.md`). |
| **Discard** | The hardcoded 13-object universe and its cost table as a source of truth (Gate 1), the one-goal scalar objective as *the* objective, per-action separate networks, the hand-crafted 19-input level encoding, NeuronDotNet/C#, and the 50,000,000-second terminal cap. |
| **Test first** | One reproducible benchmark: **minimum simulated seconds to the first Colony Ship**, with the deterministic teacher, an analytic **search-based optimum**, and the thesis's static policy as three baselines, not just random. |
| **Continue only if** | The learned policy matches the search optimum **on held-out seeds/speeds and transfers to a different milestone/persona without retraining**, or beats the teacher on the production ΔV objective. Reaching one predetermined milestone fast is explicitly **not** sufficient. |

The north star is a strategically competent, adaptable, humanlike player — not a shortest-path solver for one
fixed goal. The thesis is valuable as a *safe, cheap, fully-specified sandbox* in which to validate our
plumbing and our baselines; it is not a source of the final policy, and it solved a strictly easier problem
than we own.

---

## 1. What the thesis is

Koster defines the **parallel actions common goal (PACG)** problem: several production "assembly lines"
(building, research, shipyard) run in parallel, share one resource pool, have preconditions, and only matter
through a single common goal reached as fast as possible. OGame is the instance. The work is a clean
model → simulator → learner pipeline with three stated research products:

1. an MDP model of OGame usable for RL (ch. 2),
2. a simulator implementing that MDP (ch. 3, C# / .NET, Appendix D),
3. a Q-learning agent with ANNs and experience replay (ch. 4).

The **goal state is "own one Colony Ship"** and the thesis defines a *good* policy as one that reaches it in
**≤ 837,629 simulated seconds** — the time its own hand-written static rule policy needed (≈ 9.7 game days at
universe speed 1). The reward is `+1,000,000 − x` at the goal and `−x` otherwise, where `x` is the simulated
seconds since the previous decision. In other words the task is *undiscounted shortest path in time*, and the
"big" terminal bonus is chosen only to dwarf the horizon so the return stays positive.

The three research sub-questions are answered "yes" by construction (a model exists, a simulator exists, an
algorithm stack exists). The **main** question — *can RL find a good policy for OGame?* — is answered **"we
cannot say definitively; likely, but unproven"**: the toy model was solved, the full OGame model was never
trained to a conclusion.

---

## 2. Extraction

### 2.1 State representation

**Toy MDP v2** (one resource, two lines): `‹metal, level(MetalMine), level(LaserTechnology), timer(build),
timer(research), waiting(build), waiting(research), action(build), action(research)›`; start `‹0,0,0,0,0,false,false,null,null›`.

**OGame MDP** (`Table 2.4`): three resource doubles; 13 integer levels
(`MetalMine, CrystalMine, DeuteriumSynthesizer, SolarPlant, RoboticsFactory, CrystalStorage, MetalStorage,
DeuteriumTank, ResearchLab, Shipyard, EnergyTechnology, ImpulseDrive, ColonyShip`); three timers; three
`waiting_*` booleans; three `action_*` action pointers. Start `‹500, 500, 0, 0 … ›` (a new account's metal and
crystal).

Two modelling decisions carry the weight:

- **Afterstates.** Only states where a "serious" action is available (not `no op`) are decision points; the
  model auto-applies `no op` between them. This cut the toy transition table from 47 to 18 transitions and is
  the reason the "time advances to the next meaningful instant" idea exists at all.
- **Resources and timers are dropped from the neural-network input.** The paper argues that with *wait* actions
  the agent never needs to know current resources, and afterstates make timers trivial. This is the direct
  ancestor of our design: our choice points do not encode "which object id", and our row 0 is **WAIT** — the
  same insight that a wait/spend decision is first-class.

### 2.2 Action space

`A = {13 build/research/ship actions} ∪ {no_building, no_research, no_shipyard, no op}`. At each state
`A_s ⊆ A` adds preconditions: the line is idle and not waiting; requirements are met (e.g.
`ColonyShip ⇐ Shipyard ≥ 4 ∧ ImpulseDrive ≥ 3`); actions needing deuterium are hidden while deuterium
production is zero; actions costlier than storage capacity are hidden. The **no-wait** actions take a line out
of "waiting for nothing" mode and are guarded so that all three lines cannot deadlock.

The key mechanism is **wait actions**: picking an action you cannot yet afford puts the line in *waiting mode*
for that action and it fires automatically when resources arrive. This (a) removes `no op` spamming, (b) fixes
the credit-assignment bug where the terminal bonus landed on `no op`, and (c) collapses
"resources + timer" into "an intention the line holds". This is functionally identical to the deterministic
planner's *savings goal* (`SavingsGoal` / `canSpendFor`) plus our WAIT row.

### 2.3 Transition function

Deterministic: `Pa(s,s') = 1` if `a ∈ A_s`, else `0`. There is no stochasticity in the model — the thesis
removes other players, combat, expeditions and events entirely (Appendix A.2 says why). State space is
infinite (OGame never ends); a 50,000,000-second terminal cap makes it finite for tabular use.

### 2.4 Reward design

`R(s) = 1,000,000 − x` at a goal state, `R(s) = −x` otherwise, `x` = seconds since the parent afterstate. This
is a shortest-path reward with a large goal bonus. Non-goal actions that do not advance time earn 0; the time
steps carry `−x`. (The thesis notes that the raw toy version mis-credited `no op`; wait/no-wait actions fixed
it.)

### 2.5 Event scheduling

The single most useful engineering idea in the thesis. Raw per-second stepping (`NextSecond`) was too slow, so
the author added `TimeUntilNextEvent()`, `NextX()`, `NextAfterstate()`: after an action, **jump straight to the
next instant at which a line finishes, a wait fires, or resources make an action affordable**, caching the
energy factor between events. This is discrete-event simulation, which the author correctly identifies as
related to Semi-Markov Decision Processes (§3.7). Reported speed: **≈ 2,000 learning episodes/hour on one core
of a 2.8 GHz AMD Phenom II X3 720** (with Q-learning + a 102-neuron ANN). No wall-clock reward is ever used —
all time is simulated.

### 2.6 Learning architecture

- **Tabular Q-learning** (Watkins/Dayan update; `Q(s,a) ← Q(s,a) + α[r + γ max_a' Q(s',a') − Q(s,a)]`),
  ε-greedy, then a **logarithmic ε schedule** `ε = 1 / (1 + e^(x/(0.09N)) / 50)`.
- **Q-learning with a neural network** (NeuronDotNet 3.0, one feed-forward net **per action**, one hidden
  layer, backprop). Update target `Q(s,a) ←BP(α) r + γ max_a' Q(s',a')`. No target network.
- **Experience replay**: store one episode, replay it `R` times **in backward-temporal order**, clear the
  buffer per episode. (Only implemented for the toy simulator.)

### 2.7 Feature mapping

For each action (except `no op`): **19 inputs** encoding the level (level 1 → `[1,0,…,0]`, level 0 → all
zeros; 19 chosen because a reasonable colony-ship path never exceeds level 19) plus **2 inputs** ("currently
executing", "in waiting mode"). No resources, no timers. Six-neuron hidden layer in the base experiment.

---

## 3. Verification against our codebase

The thesis models **OGame 3.0.1**; the host is OGameX. Mechanics were re-checked against the host source.
Where they differ, the host is authoritative (Gate 1: the module never restates a host rule).

| Thesis quantity | Thesis (2012) | OGameX host | Verdict |
| --- | --- | --- | --- |
| Cost formula | `base × CIF^(level−1)` | `GameObjectPrice` with per-object `cost_factor` (metal/crystal/deut), same shape | **Match**; host holds the table in `app/GameObjects/*Objects.php` |
| Metal/Crystal/Deut production | `30·L·1.1^L`, `20·L·1.1^L`, `12.8·L·1.1^L` | `30·L·1.1^L`, `20·L·1.1^L`, `10·L·1.1^L·(1.44−0.004·tempAvg)` (`BuildingObjects.php:37,61,85`) | **Match**; the thesis's `12.8` is the mean-temperature value of the host's temperature term |
| Energy use / solar output | `−10·L·1.1^L`, `−20·L·1.1^L`, `+20·L·1.1^L` | identical (`BuildingObjects.php:39,63,87,110`) | **Match** |
| Production factor | `min(1, energyProd / energyUse)` | `floor(energyProd/energyUse·100)/100`, capped at 1 (`PlanetService:2471`) | **Match** (host floors) |
| Storage capacity | `12500 · e^(20·L/33)` | `5000 · floor(2.5 · e^(20·L/33))` for all three stores (`BuildingObjects.php:158,176,195`) | **Match** (thesis dropped the floor) |
| Colony Ship | cost 10000/20000/10000, req `Shipyard 4 ∧ ImpulseDrive 3` | identical (`CivilShipObjects.php` id 208) | **Match** |
| Energy Technology | `0/800/400`, needs lab | identical (`ResearchObjects.php`) | **Match** |
| Impulse Drive | `2000/4000/600` | identical | **Match** |
| **Building time** | `(M+C)/2500 · 1/(Robotics+1) · 3600` | host adds `max(4 − nextLevel/2, 1)`, `2^Nanite`, and `· economySpeed`; min 1 s (`PlanetService:1019`) | **Differs** — the thesis omits the level factor, Nanite and universe speed |
| **Research time** | `(M+C)/1000 · (1 + RoboticsLab) · 3600` — the function text names *robotics* though the parameter is the *research lab* | uses `1 + researchLabLevel`, `· economySpeed · researchSpeed`, class multiplier (`PlanetService:1191`) | **Differs** and the thesis formula contains a typo |
| Universe speed | assumed 1 | `economySpeed/fleetSpeed/researchSpeed` are settings (our cohorts use 8/16/1000) | **Differs** — decisive for any absolute-seconds number |
| Rounding | modelled without it, implemented in the simulator | `ceil`/`floor` at specific points | thesis's own caveat, honest |

**Consequence for the milestone experiment.** The thesis's headline number (837,629 s ≈ 9.7 days) is a
*speed-1* figure and its time formulas are a simplification. We must not compare against 837,629 s directly;
we must re-run the thesis's static policy on the host at a **fixed, declared economy speed** and use *that*
number as the baseline. Absolute-second numbers only ever compare within one speed setting.

Confirmed against the host: **no object, price, requirement or formula in the thesis is reproduced in the
module** — the encoder (`app/Domain/Choice/EconomyChoiceEncoder.php`, `VERSION = 2`) reads all of it from the
host at decision time, which is exactly what Gate 1 demands and what the thesis violates (its `Table C.1`
hardcodes 13 objects).

---

## 4. Demonstrated vs unproven

**Demonstrated (in print, with plots):**

- The toy MDP is solvable to optimality: value iteration needs **γ ≥ 0.97** and the horizon effect is
  non-monotone (a parabola, not a line).
- **Tabular Q-learning** learns the 34-second optimal toy policy **consistently**: 96/100 at 350k episodes,
  100/100 at 500k with the logarithmic ε schedule; ~1M episodes with purely random exploration.
- Q-learning beats **TD(0)** on the toy (consistent 38 s vs a 41.7 ± 1.35 s mean) — an argument for off-policy
  control under ε-greedy.
- The **full stack (Q + per-action ANN + ER)** on the toy reaches the optimal policy in **25/100** runs at its
  best parameter set (α=0.01, γ=1, capped log-ε, 500k episodes); base settings mostly gave 40–41 s.
- **Instability is real and parameter-dependent**: α=0.1 caused divergence/oscillation (policies up to 130 s,
  many failures); α=0.01 was stable; aggressive early exploration destabilised the ANN.

**Unproven (stated as such by the author):**

- **Anything on the real OGame MDP.** No training result is reported. The bottleneck was explicit: the full
  simulator ran at ~2,000 episodes/hour, and the toy experiments implied 500k–10M episodes per run, i.e.
  months. The thesis's main question is left open.
- Convergence *with function approximation* (the theorem only covers the tabular case; the author says so).
- ANN + ER on OGame at all (ER was never wired to the OGame simulator).
- The ANN input mapping (only levels + two flags) was never ablated; resources/timers were dropped by argument,
  not by measurement.
- Any human-likeness, adaptivity, opponents, combat, or non-milestone objective — all explicitly out of scope.

---

## 5. Is the original implementation recoverable? — No

| Lead | Result |
| --- | --- |
| Google Drive package (`Appendix D`, `docs.google.com/open?id=0B8xFlIzMhHcpTkhRbFVsWTZ4dFE`) | **Dead.** The link 302-redirects to a Google sign-in; the old `open?id=` share form no longer serves the file. No auth-free download. |
| Web Archive for the Drive object id | **No capture** (`CDX` for `docs.google.com/open*` filtered on the id: 0 rows). |
| Web Archive for the thesis directory | Only the **PDF** is archived (`cs.ru.nl/bachelors-theses/2012/…Koster…pdf`, 2019-05-11). No source `.zip` is archived. |
| GitHub / code search for `OGameReinforcementLearning`, "Parallel Actions Common Goal", "NeuronDotNet OGame" | **No repository found.** |
| Author contact | `ivankoster at gmail dot com` (last resort only; not attempted). |

**Verdict: not publicly recoverable.** This is acceptable because the thesis is *self-describing enough to
re-implement*: ch. 2 gives the full MDP, Appendix C gives every formula, and Appendix D names the class
structure (`State`, `Action`, `OgameFormulas`, `Simulator`, `UserTerminal`). Even so we should not re-implement
it — our host *is* the simulator (see §7), so a faithful port would be a second game and violate the
"one implementation" rule. Treat the paper as the specification of a **benchmark**, not of code to write.

---

## 6. 2012 methodology vs modern alternatives

For a reader new to training: the thesis's agent is a **value-based, off-policy** method — it learns "how good
is this action here" and acts greedily. The modern candidates differ mainly in *what* they learn and *how* they
stay stable.

- **The thesis's ANN-Q is an early precursor of DQN** — replay + a network approximating Q. What it lacks is the
  reason DQN works: a **target network**, **fixed replay minibatches** (not one whole episode replayed
  backward then thrown away), and **reward/observation normalisation**. Its own oscillation results are exactly
  the symptom a target network removes.
- **DQN variants** (Double/Dueling/Prioritised/Rainbow) would be the *same family done correctly*. But
  off-policy bootstrapping with a per-state variable candidate set and delayed, time-scaled rewards is harder
  to stabilise than on-policy learning, and, worse, a Q-per-candidate target is ill-defined when the candidate
  list is non-stationary. Not the first choice.
- **PPO** (on-policy, clipped) is the natural fit for our representation: score the candidate list, softmax,
  mask illegal rows — it sidesteps the bootstrapping instabilities the thesis hit. This is already our chosen
  Phase-2 algorithm (`plan/rl/ml-stack-research.md §3`).
- **Behaviour cloning / imitation** is where the thesis *accidentally points*: its best suggestion for OGame is
  to **seed exploration with the hand-written rule policy for ~1,000 episodes, then hand over to ε-greedy**.
  That is warm-starting from a demonstrator — our Phase-3 BC. We already have the demonstrator (the
  deterministic planner is the teacher) and the seam; the thesis only anticipates it.
- **Model-based RL / planning.** Because the thesis's model is *deterministic*, a planning agent is available
  and strictly cheaper than RL: the economy is a **deterministic shortest-path problem**, so Dijkstra/A* over
  the analytic event graph computes the optimum. MCTS adds little in a deterministic single-agent MDP (it
  earns its keep under stochastic combat or adversarial opponents — i.e. only for the full game, later).
- **Hierarchical RL.** The thesis has no hierarchy; its "assembly lines" are flat. Our planner passes already
  *are* the hierarchy (wall → doctrine → storage → …), and a high-level policy over budget envelopes is the
  natural v3 (`ml-stack-research.md §3`).

**No new algorithmic bet is justified.** The thesis strengthens confidence in the direction we already chose
(candidate scoring, BC → PPO, host-derived features, event-driven sim) and adds a *benchmark* and *three
baselines*; it does not argue for switching algorithms.

---

## 7. Would an event-driven in-memory Rust simulator improve throughput?

It already exists in principle, in PHP: `ai:sim` (`Modules/AI/app/Console/Commands/SimulateAiTime.php`) freezes
Carbon at an instant, drains everything due (fleet arrivals, due AI work, maintenance), then **jumps to the
next due instant** — the thesis's `TimeUntilNextEvent`/`NextAfterstate`, but over the *real* host. Measured
throughput (from `plan/rl/README.md` and `benchmark-plan.md`): **×313 (mid-game) to ×1,485 (early) real time**
per 20-account universe, ≈ **100 decisions/s across 16 workers** if every account learns; the environment —
not the learner — is the constraint (< 10³ samples/s).

Relevant measured facts (`rust-performance-research.md`, `simulator-architecture-options.md`):

- Pure math (production, timing) is **< 2 %** of a session; a bit-exact Rust port is 4.8× faster on that 2 %
  and buys nothing end to end.
- A whole Rust simulator is the only large win (10³×/event estimated) but is a **second game**: a toy port
  already diverged from PHP on one `pow` association, and the real host has ~60 closure formulas, class/officer
  bonuses, cadence semantics and 11 mission types.
- Gate **G3** already governs this: *no Rust simulator unless, after the cheap PHP fixes, 16 workers give less
  than the next experiment's budget ÷ 72 h* (≈ 20 learner decisions/s for E2). Today's ~100/s means **G3 passes
  for the economy**, so Rust is **not** on the critical path.

**Hardware split (answers the brief's warning):** simulation is **CPU-bound** (PHP across cores; a GPU cannot
step the env), and training the **≤ 4 M-parameter** candidate scorer is trivial for either card. The **RTX 4070
Ti SUPER 16 GB is already more than sufficient**; the 3090's extra VRAM buys nothing at this model size. Put
the GPUs on the trainer, keep `ai:sim` on 16 CPU workers. *Hypothesis to measure:* BC/PPO at this scale trains
in minutes–hours per run on either GPU; if a run takes longer, the cause will be data volume, not the card.

**Verdict:** do **not** build a Rust simulator for this work. Revisit only through G3, and only for
interactive/self-play decisions (raids, saves) that the economy path does not need.

---

## 8. What needs full simulation vs analytical approximation

| Calculation | Method | Why |
| --- | --- | --- |
| Cost, production, energy, storage, prerequisites | **Analytical** (host closures) | Closed form; the thesis's Appendix C is the same math |
| Time-to-afford an action, next event time | **Analytical** | `(cost − stock) / production` and queue times; this *is* the event jump |
| Requirement closure / unlock depth | **Analytical** (tech-tree DAG) | Static graph over host objects |
| Build/research/ship **time** | **Analytical**, host formula | Must use the host's level/speed factors, not the thesis's |
| Economy trajectory up to the Colony Ship | **Analytical + search** | Deterministic → shortest path computable without sampling |
| Combat outcomes, losses, debris, moon chance | **Full simulation** (Rust battle engine) | Stochastic, host RNG |
| Expeditions, espionage, counter-espionage | **Full simulation** | Stochastic |
| Responses of other accounts (raids, fleet saves) | **Full simulation / agents** | Multi-agent, non-stationary |
| Resource loss to raids, message/route side effects | **Full simulation** | Emergent, not closed-form |

The takeaway that shapes the experiment: **for the Colony-Ship milestone the whole transition is analytical**,
so a search-based optimum is cheap and should be one of the baselines (§10). Full simulation is required only
once stochastic or multi-agent behaviour enters the objective — i.e. beyond the milestone.

---

## 9–11. Reproduction experiment, in full

**Objective (the thesis's own):** reach the first Colony Ship in the **minimum simulated time**. This is a
declared *benchmark*, not the north-star objective; its value is that it is small, deterministic, fully
specified, and already instrumented by our seam.

### 9.1 Environment and protocol

- **Unit of study:** one fresh account on a fresh universe, played forward by `ai:sim --in-memory --seed`
  (`--native-cognition`, sidecars/social lanes off), which is the real host and module — never a second game.
- **Time:** all results are **simulated** seconds. Fix one economy speed per run and report it; sweep 1, 4, 8 in
  the robustness panel. The thesis's baseline must be re-run on the host at the same speed.
- **Decision point:** exactly our economy choice points (building queue / lab). Candidate list = the teacher's
  passes with row 0 = WAIT (`ChoicePoint`, `EconomyChoiceEncoder`).
- **Reproducibility:** same seed + same code + same model ⇒ same run (guard with `plan/rl/bench/digest.php`).

### 9.2 Arms (all paired on the same seed/snapshot)

| Arm | Policy | Origin |
| --- | --- | --- |
| A0 | Random legal choice | Floor |
| A1 | **Thesis static policy** (5 rules, §5.3: solar if factor<1; metal if metal=crystal; crystal if crystal=deut; … ) | Thesis baseline, re-run on host |
| A2 | **Deterministic teacher** (`QueueableBuildingPlanner::firstQueueable`) | Our real baseline |
| A3 | **Analytic search optimum** (Dijkstra/BFS over the deterministic event graph, edge cost = next-event seconds) | The true bar for the milestone |
| A4 | **Behaviour-cloning policy** on the teacher (economy rows only) | Exists (`rl/`, Phase 3) |
| A5 | **RL policy** (PPO over the candidate scorer) initialised from A4, trained on the shortest-path reward | Phase 4 |

A3 is the point the brief insists on: benchmark against **search**, not only random. If a learner cannot match
A3 on a deterministic problem, that is expected and informative — it bounds what the milestone can justify.

### 9.3 Reward for this benchmark

Use the thesis's form, adapted: `r_t = −(seconds since previous decision)`, terminal `r = +BIG` at first Colony
Ship, with `BIG ≫` the horizon (measure the horizon first). Discount **γ = 1** (equivalently per-game-time γ),
because the time penalty already encodes "fastest". This is a **benchmark reward**, walled off from the
production path; it must never be wired into the live policy.

### 9.4 Specification table (unmeasured items labelled **hypothesis**)

| Item | Value | Basis |
| --- | --- | --- |
| State features | existing 45-number `EconomyChoiceEncoder` state (v2) | measured/implemented |
| Candidate features | existing 27 per row, K=32 rows, row 0 = WAIT | measured/implemented |
| Model | candidate scorer, shared trunk, **0.3–1 M params** (4 M ceiling) | derived in `state-and-action-space.md §7` |
| Training data (BC) | ≥ 300k choice points, ≥ 24 universes (gate G-data) | measured (v2: 452k points) |
| Training data (RL) | **10⁶–10⁷ decisions** | **hypothesis** (thesis: 500k–10M episodes) |
| Episodes | new account, **to first Colony Ship**; report the seed count | thesis objective |
| Episodes per speed | speed 1: likely 10⁷+ decisions — **hypothesis, must be timed first** | thesis wall-clock warning |
| Eval seeds | pre-registered; paired twin-universe per `evaluation-plan.md`; **n fixed before confirm** (pilot n≈20) | evaluation plan |
| Metrics | **primary**: median simulated seconds to Colony Ship + 95 % CI; **secondary**: value at goal (ΔV), wasted resources, idle-queue hours, teacher-agreement | this doc + evaluation plan |
| Success | A5 median ≤ A3 optimum on held-out seeds **and** transfers (see §12); A5 ≥ A2 is *necessary* but far from sufficient | north star |
| Hardware | CPU: 16× Xeon workers for `ai:sim`. GPU: **RTX 4070 Ti SUPER is sufficient**; 3090 not required at this model size | measured throughput + model size |
| Wall-clock | BC data ≈ hours; BC train minutes; RL per run **1–3 days** (hypothesis) | `plan/rl/README.md` |

### 9.5 Hypotheses (all unmeasured)

- **H1** Our teacher (A2) beats the thesis's static policy (A1) by a large factor (the static policy is weak).
- **H2** The analytic optimum (A3) is well below both, and is reachable by a learner given enough BC-initialised
  PPO.
- **H3** On this truly deterministic milestone, a learned policy will **not** beat A3 — the milestone alone
  cannot justify RL. This is the honest expected outcome and the reason §12 is phrased around *transfer*.

---

## 12. What to reuse / modernise / discard / test / require

| | Items |
| --- | --- |
| **Reuse** | PACG → MDP framing; afterstates; **wait / no-wait actions** (= our WAIT row); **event-jump scheduling** (already in `ai:sim`); the *"good policy = beat the hand policy"* success definition; the **γ ≥ 0.97 / long-horizon** finding (matches our time-scaled γ); the **SMDP tying of time to reward** (= our `γ^(Δt/1h)`); **warm-starting exploration from a rule policy** (= our BC); dropping resources/timers from the network input when wait actions exist; the analytic-vs-simulated split. |
| **Modernise** | Tabular Q → **shared candidate-scoring net** (chosen); per-action nets + no target → **one net + PPO/target** (chosen); whole-episode backward ER → **replay/n-step/GAE** (chosen); single milestone reward → **ΔV** (chosen); hand 19-input encoding → **host-derived features** (chosen); C# sim → **the real host** (chosen). |
| **Discard** | Hardcoded 13-object universe and `Table C.1` as a source of truth (**Gate 1 violation**); the one-goal scalar objective as *the* objective; the 50,000,000-second cap; NeuronDotNet; a from-scratch C#/Rust simulator; any use of the thesis's simplified time formulas (use the host's). |
| **Test first** | The §9 reproduction: re-run A1 on the host, add the **A3 search optimum**, and measure whether BC/PPO (A5) matches it on held-out seeds. Cheapest possible proof that our seam, baselines and metrics are sound. |
| **Continue only if** | (a) the learner matches A3 on held-out seeds **and** at a **different economy speed** it was not trained on (generality), **or** (b) it transfers to a **different milestone/persona** (e.g. first Astrophysics, or a raider opening) without retraining, **or** (c) it beats the deterministic teacher on the **production ΔV** objective where the teacher is myopic. A single predetermined milestone reached in minimal time is **not** sufficient — that would be solving the thesis's toy, not the north star. |

### Path beyond the milestone (brief task 12)

- **Long-term economy.** Swap the terminal bonus for the **ΔV** reward (`reward-design.md`): invested + held,
  time-scaled γ, 30-day episodes, snapshot curricula (day 10/30/60). The thesis's milestone becomes a
  *curriculum shaping signal*, not the objective. Add the hierarchical "budget envelope" policy last
  (`ml-stack-research.md §3`).
- **Multi-agent.** The thesis deliberately removed opponents, so it contributes nothing here beyond proof that
  the *isolated* economy is learnable. The first step is already specified: the deterministic cohort as
  opponents inside one universe; then a league/self-play once the policy controls interactive decisions (raids,
  saves, colonisation) — roadmap Phase 4.

---

## 13. Provenance and cross-references

- Thesis (read in full): `cs.ru.nl/bachelors-theses/2012/Ivan_Koster___0814903___…pdf`, 80 pp, 14 Jun 2012.
  Catalogued in `plan/ogame_ai_source_catalog.md` (row: OGame RL thesis 2012). No source code recovered.
- Host mechanics verified in: `app/GameObjects/BuildingObjects.php`, `CivilShipObjects.php`,
  `ResearchObjects.php`; `app/Services/PlanetService.php` (`getBuildingConstructionTime`,
  `getTechnologyResearchTime`, `getResourceProductionFactor`).
- Our design this assessment builds on: `plan/rl/README.md`, `state-and-action-space.md`, `reward-design.md`,
  `ml-stack-research.md`, `rust-performance-research.md`, `simulator-architecture-options.md`,
  `evaluation-plan.md`, `benchmark-plan.md`, `next-steps.md`; skill `.github/skills/ogame-rl-training/SKILL.md`.
- Gates referenced: `AGENTS.md` (Gate 1/2/3), `plan/details/specs/overengineering-gate.md` (Gate 2).
