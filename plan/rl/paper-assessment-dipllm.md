# Research assessment: DipLLM (arXiv 2506.09655) for the OGameX AI module

Read-only research. **No production code was changed.** Written 10 October 2026 against
`Modules/AI` at the state in `plan/HANDOFF.md` and `plan/rl/next-steps.md` (BC v4 in closed loop).

Paper: *DipLLM: Fine-Tuning LLM for Strategic Decision-making in Diplomacy*, Xu, Chai, Li, Fu, Zhu, Zhao
(ICML 2025), [arXiv:2506.09655v2](https://arxiv.org/abs/2506.09655). HTML:
<https://arxiv.org/html/2506.09655v1>.

How to read this document. Every non-obvious claim is tagged:

- `[paper §x]` — stated or shown in the paper, with the section/table that supports it.
- `[ours]` — a fact read from this repository, with the file.
- `[hyp]` — my hypothesis, not established by the paper or by our code.

The three gates in `AGENTS.md` (no static AI, relatively simple, what a professional player does) and the
north star ("an aspect of `ogamex scorecard` moves") are the lens throughout.

---

## 1. Executive assessment

**Bottom line: one idea in this paper is worth an experiment in our RL pipeline; the paper's headline
artefact (a fine-tuned 8B LLM) is the wrong tool for OGameX and should be rejected.** The paper is a
strong, well-ablated result *for Diplomacy*, and the parts that do not transfer are exactly the parts most
people would copy.

The paper's real contribution, once you strip the framing, is two mechanisms:

1. **Autoregressive factorization (AF)** of a joint decision into a sequence of smaller decisions, each
   conditioned on the earlier ones, plus a proof that the product of the per-decision policies equals the
   joint policy (`[paper §4.1–4.2, Thm 1]`).
2. **A value-weighted supervised loss** where imitation is weighted by an equilibrium value estimate:
   `max E[log π_φ · exp{Q}]` (`[paper §4.3, Eq 5–6]`).

Everything else — the Llama-3-8B backbone, LoRA, the equilibrium-search data pipeline (piKL-Hedge),
the Diplomacy prompt format — is Diplomacy-specific scaffolding.

Map that to our code:

- Our `CandidateScorer` already *structurally* is `softmax over legal candidates` — the same shape as the
  paper's per-unit policy `π_i^{d,*} = softmax(Q_i^d)` (`[paper Eq 4]`, `[ours]` `rl/ogrl/model.py`). We do
  **not** have the paper's failure mode ("generate the whole joint action in one step"), because we never
  generate a joint action: we rank a small legal list. So the part of the paper that beats its own SFT
  baseline with a large margin is **not** a gap we currently have.
- What we genuinely lack is mechanism 1. **Every choice point in one login is scored from the same
  pre-decision state**; the decisions are simultaneous, not sequential (`[ours]`
  `app/Actions/DecideAiEconomyStepsAction.php`: `account()` is read once, `choiceSets()` is built from the
  planner's steps *before* the policy answers any set, and nothing is deducted between sets). A human plans
  a login as a sequence — decide the colony, then spend the crystal on the lab, then fill the yard — so this
  is a real, nameable gap (`gate 3`).
- Mechanism 2 (value-weighted imitation) is implementable on our infra because we already record a value
  per row and we already have a simulator to roll out for better value estimates — but the *source* of the
  value is different: the paper uses equilibrium-search Q-values; we would have to produce them with a
  critic or Monte-Carlo returns. That makes it offline RL dressed as weighted BC, not a free lunch.

**Verdict in one line:** copy the *factorization idea* into the choice seam (one small experiment, ADOPT
the direction), treat the *value-weighted loss* as an EXPERIMENT that is really a warm start for the
already-gated PPO, and REJECT the LLM backbone, the equilibrium-search teacher for the economy, and DPO.

| Paper element | Ours | Verdict |
| --- | --- | --- |
| Autoregressive factorization of a joint decision | Our choices are simultaneous per login | **EXPERIMENT** (H1 — the highest-value transfer) |
| `exp{Q}`-weighted imitation loss | Plain BC today; `value` head exists but is untrained | **EXPERIMENT** (H2 — a PPO warm start) |
| Equilibrium search (piKL-Hedge) as teacher | A hand-written deterministic planner is the teacher | **REJECT** for the economy; **DEFER** for raid/save (C5/C7) |
| Llama-3-8B + LoRA policy | 0.3–4.3 M-param MLP candidate scorer; reference host is 2 vCPU/2 GB, no GPU | **REJECT** |
| DPO / preference learning | not used | **REJECT** (the paper itself reports it failed, `[paper §E.3]`) |
| Action-distribution-shift diagnosis (Fig 7) | not used | **ADOPT** (cheap eval tool for the model we already have) |
| Learning-curve vs data volume (Fig 6) | implied by `improving.md §1` but not measured | **ADOPT** (methodology) |

---

## 2. Explanation of the paper

### 2.1 The problem

Diplomacy is a 7-player, mixed cooperative/competitive game. A player controls up to 34 units, each with
~26 possible orders, so the number of joint orders per turn can exceed 10⁶⁴ (`[paper §1]`). The
state-of-the-art approach (Cicero, `[paper §2]`) does equilibrium search (CoShar-piKL) to generate training
data and needs enormous compute — "up to 448 GPUs for gameplay rollouts" (`[paper §2]`). The authors ask
whether a *fine-tuned LLM* can learn a strong (near-equilibrium) policy from a small dataset instead.

> **ML concept — joint action space explosion.** If a turn is one "action", the action is a tuple of 34
> unit orders. The number of tuples is the product of the per-unit choices, which is astronomically large.
> You cannot put a softmax over 10⁶⁴ things, and you cannot get a meaningful label for each. OGame has the
> same shape, one level up: a login is a tuple of decisions (which building on each planet with a free
> queue, which research, which ships, what the session is *for*). We side-step the explosion by only
> ranking the legal candidates a planner already produced (`[ours]` `plan/rl/state-and-action-space.md §1`).

### 2.2 The formalism (Markov game, equilibrium, LLM policy)

Diplomacy is modelled as an n-player Markov game `Δ = ⟨S, A¹…Aⁿ, r¹…rⁿ, p⟩` (`[paper §3.1]`). piKL-Hedge
is the equilibrium solver: each player maximizes reward while staying close (KL) to an anchor policy τ
(here, a human-imitation network), producing
$$\pi_i^t(a_i\mid s)\ \propto\ \exp\!\big\{\beta \log \tau_i(a_i\mid s) + Q_i^t(s,a_i)\big\}\quad([paper\ Eq\ 1])$$
An LLM policy maps a textual state `v_in` to a textual action `v_out`, i.e. `π_φ: V^m → V^n`
(`[paper §3.3]`).

> **ML concepts here.** An **anchor policy** τ is a fixed "what humans do" distribution that a KL term
> keeps the search near — it stops the solver drifting into weird-but-strong play. **Q_i^t(s,a)** is the
> expected payoff of action `a` against the others' current policies. **β** trades reward against staying
> human-like. `∝ exp{…}` means the policy is a *softmax* over `β log τ + Q`: high-Q, human-plausible
> actions get most of the probability. OGame translation: our deterministic planner is the anchor τ; a Q
> would be the marginal value of an action; β would be how much "taste" (doctrine) we let override raw
> value. We have τ today, we do not have Q.

### 2.3 Autoregressive factorization (`[paper §4.1, Fig 2]`)

The joint policy over D units is decomposed into a sequence of per-unit policies:
$$\boldsymbol{\pi}_i(a_i^{1:D}\mid s)=\prod_{d=1}^{D}\pi_i^d\big(a_i^d \mid s,\ a_i^{1:d-1}\big)$$
Each unit's decision is conditioned on the board state *and the orders already given to earlier units*. To
fit the next-token-prediction interface, the previously chosen actions become the context. At inference the
model emits one unit's order at a time; a post-processor assembles the joint action.

> **ML concept — autoregressive.** Same idea as language modelling: predict the next thing given everything
> so far. The gain is that each step only has to choose among ~26 legal options instead of the 10⁶⁴-tuple,
> and each step can react to the earlier steps ("I already supported Burgundy, so this fleet should convoy
> instead"). **The OGame analogue is not units — it is the choice points of one login**, which today do not
> see each other (H1, §6).

### 2.4 The learning objective (`[paper §4.2, Eq 2–4]`)

They rewrite the piKL policy and factorize the *joint Q* into per-unit Q-values by marginalizing over the
remaining units:
$$Q_i^d(s,a_i^{1:d-1},a_i^d)\ \triangleq\ \log\!\!\sum_{a_i^{d+1:D}}\!\!\exp\big\{\boldsymbol{Q}_i(s,a_i^{1:D})+\beta\log\boldsymbol{\tau}_i(a_i^{1:D}\mid s)\big\}$$
and define the per-unit policy `π_i^{d,*}(a_i^d|s,a_i^{1:d-1}) = softmax_{a_i^d} Q_i^d`. Two theorems:

- **Theorem 1 (objective equivalence):** the product of the per-unit policies equals the original joint
  policy `π_i^*`. So factorizing loses nothing in principle.
- **Theorem 2 (optimality in 2-player zero-sum):** if both players update with this objective, average
  policies converge to an approximate Nash equilibrium as T→∞, extending Jacob et al. 2022 (`[paper §4.2,
  App. C]`).

> **The subtle and important point.** The per-unit Q is **not** the value of that unit's order alone: it is
> the log-sum-exp (soft-max) over all *continuations* of the remaining units. That is what makes the
> product equal the joint distribution. If you naively trained D independent per-unit classifiers, the
> product would *not* equal the joint policy. AF is "each decision, seeing the ones already made, is scored
> with awareness of the best way to complete the rest." Keep this in mind for H1: conditioning on earlier
> picks is a *practical approximation* to computing the continuation value, not the same guarantee.

### 2.5 The loss (`[paper §4.3, Eq 5–6, App. C.1]`)

They minimize KL between the target equilibrium policy and the LLM:
$$\min_{\pi_\phi}\ \mathbb{E}_{(s,a_i^{1:d-1})\sim\mathcal D}\Big[D_{\mathrm{KL}}\big(\pi_i^{d,*}\ \|\ \pi_\phi\big)\Big]
\qquad\equiv\qquad \max_{\pi_\phi}\ \mathbb{E}\Big[\log\pi_\phi(a_i^d\mid s,a_i^{1:d-1})\cdot \exp\{Q_i^d\}\Big]$$

> **ML concept — why the KL collapses to a weighted cross-entropy.** Because the target is `softmax(Q)`,
> the gradient of the KL is `Σ_a softmax(Q)(a) · ∇log π_φ(a)`, i.e. a **cross-entropy whose per-sample
> weight is `exp{Q}`**. So this is *supervised imitation re-weighted by value*: the model still imitates the
> data's action, but rows the equilibrium trusts get a larger gradient. This is the same family as
> Advantage-Weighted Regression `[hyp]`. Note `exp{Q}` must be normalised (softmax over the candidate set,
> or divided by its own mean) or the loss scale explodes — the paper does not say how they normalise it.

**Data collection (`[paper §4.3, Fig 3, App. D.1]`):** DipNet plays the environment to produce raw games;
piKL-Hedge (256 iterations, 50 candidates, β=0.1, ≤6 actions/unit) runs with DipNet as the anchor τ and
produces joint Q-values; these are decomposed (with a log-sum-exp lower-bound approximation over *sampled*
continuations, `[paper App. C.1]`) into per-unit `Q_i^d`; rows are stored as `(s, a_i^{1:d-1}, a_i^d, Q_i^d)`
in prompt form.

### 2.6 Model, training, results

- **Backbone:** Llama 3 8B, **LoRA** (rank 16, α 32, dropout 0.05), AdamW, batch 4, lr 2e-4, linear
  schedule, 5 epochs, max seq 2048 (`[paper App. D.2, Table 4]`).
- **Headline result:** against a population of baselines `[paper Table 5]`, DipLLM SoS **23.0±0.1%** vs
  Cicero **20.8±0.3%**, win rate **22.3±0.2%** vs **20.5±0.2%**; equal performance = 1/7 ≈ 14.3%. Claimed
  using **1.5% of Cicero's data**. Inference uses **10–20% of Cicero's per-turn time** `[paper §5.2]`.
- **Ablation (vs six DipNet copies, `[paper Table 2]`):** AF✗/FT✗ SoS 0.2%; AF✗/FT✓ 0.8%; AF✓/FT✗ 9.9%;
  **AF✓/FT✓ 29.4%**. Both mechanisms are necessary and AF is the dominant one. This is the strongest
  evidence in the paper and it is *about the factorization*, not about LLMs.
- **Data efficiency (`[paper Fig 6]`):** with 100 fine-tuning games DipLLM matches DipNet; at 500 games it
  leads by 6.7%.
- **Versus leading LLMs (`[paper §E.1]`):** beats o3-mini and DeepSeek-R1 by a wide margin, but note these
  are prompted, not fine-tuned or search-augmented.
- **Diagnosis (`[paper Fig 7]`):** fine-tuning shifts England's opening action distribution toward the
  piKL equilibrium distribution.
- **Case study (`[paper Fig 8]`):** one hand-picked game where DipLLM feints and encircles Cicero. An
  anecdote, not evidence.

### 2.7 Limitations and unproven assumptions (stated `[paper §6, §E.2–E.3]` or inferred `[hyp]`)

- **The teacher still needs equilibrium search.** The paper's headline "1.5% of Cicero's data" is true
  only *after* piKL-Hedge has produced the Q-values; the raw data is from DipNet and the targets are from
  search. The authors name this as the main limitation and propose self-play as future work.
- **Theory is 2-player zero-sum only.** Diplomacy is 7-player general-sum; the transfer is asserted, not
  proved.
- **No full-press (communication).** Only no-press Diplomacy. The social/negotiation half of the game is
  out of scope.
- **Fairness caveats.** Every comparison disables equilibrium search for all agents; Figure 5 shows Cicero
  *with* search staying closer (DipLLM still above the 14.3% line but the margin narrows). The result is
  "end-to-end reasoning beats search-free SOTA", not "beats search-augmented SOTA in absolute terms".
- **DPO and PPO underperformed their method** `[paper §E.3]`. They attribute DPO's failure to ambiguous,
  noisy preferences in multi-agent settings. Table 7's numbers are partly unreadable in the HTML export
  `[ours: arXiv HTML artefact]`.
- **Variance reporting is thin** for the case study and long-horizon claims; the game counts behind Figure
  5 are not stated in the HTML.
- **`[hyp]` The LLM may be carrying little of the load.** The ablation shows AF + fine-tuning do the work;
  the Llama backbone supplies *data efficiency* (pretrained priors), which the paper never isolates with a
  from-scratch transformer at matched parameters. Our domain has no textual pretraining corpus for OGame
  state→action, so that prior is unavailable to us anyway.

---

## 3. Research reproducibility analysis

| Artefact | Status | Notes |
| --- | --- | --- |
| Paper | ICML 2025, arXiv 2506.09655v2 | Full method, hyperparameters, proofs present. |
| Official code | **Not found.** arXiv page has no code link; a GitHub search for "DipLLM" returned nothing `[ours: search 10 Oct 2026]`. | Reproduction means rebuilding from the text. |
| Model weights / dataset | **Not released.** | The training data is piKL-generated and not public. |
| Equilibrium solver (piKL-Hedge) | Available via Meta's `diplomacy_cicero` repo (Jacob et al. 2022) | Heavy: needs a value model + search. |
| Anchor policy (DipNet) | Available (Paquette et al. 2019) | Needed only to regenerate data. |
| Llama 3 8B + LoRA | Public | Trivial to obtain. |

**What is reproducible from the paper alone, cheaply:** the *mechanisms* — autoregressive factorization of
a joint decision and the `exp{Q}`-weighted CE loss — onto our own candidate scorer and data. That needs no
Diplomacy code and is what this assessment recommends.

**What is not cheaply reproducible:** the end-to-end 23.0% SoS number. It requires DipNet, piKL-Hedge at 256
iterations, and a Diplomacy environment, and even then the exact data volume and seeds are unstated.
`[hyp]` I would not attempt it; the value to OGameX is the transferable mechanism, not the score.

**Subsequent work:** the paper is recent (June 2025) and the related line (Cicero, DORA, Richelieu,
Welfare-Diplomacy) is the natural citation neighbourhood. `[hyp]` No published follow-up specifically
isolating "AF without an LLM" was found, which is exactly the experiment our domain can run cheaply.

---

## 4. OGame applicability matrix

Legend: **Method** = paper mechanism, tagged demonstrated (`[D]`) or our adaptation (`[H]`).
"Simpler alternative?" answers: could something already in the module do as well?

| # | OGame area | Paper method | Adaptation (`[H]` unless `[D]`) | Math/arch change | Simpler alternative? |
| --- | --- | --- | --- | --- | --- |
| 1 | **Economic development / resource allocation** | Q-weighted imitation `[D]`; AF over unit orders `[D]` | Autoregressive **login budget plan**: each choice point sees the resources already committed this login; the last decisions optimize the leftover `[H]` | Add a "session-so-far" block to the state vector (committed metal/crystal/deut, queues already filled, kinds already used). No loss change | The host already re-validates affordability (`[ours]` queue actions) and the teacher already reserves (`refusal`/`canSpendFor`). AF must beat *that* to matter — see H1 |
| 2 | **Building / research priorities** | per-unit softmax `π^d = softmax(Q^d)` `[D]` | Already ours: `softmax` over the planner's legal pass-ordered list (`[ours]` `QueueableBuildingPlanner::choiceSets`) | none | Already implemented. The paper adds nothing beyond confirmation of our shape |
| 3 | **Fleet composition / attack planning** | joint-action factorisation to avoid 10⁶⁴ | Per-ship/per-wave factorisation of a raid plan: choose waves/target one at a time, each seeing the earlier ones `[H]` | New choice kind C5 with a sequence over waves; raid already one-plan-per-report (`[ours]` `RaidPlanner`) so it must first become a scored list (`improving.md §6`) | **Yes, for now:** the existing deterministic `RaidPlanner` + profit/survival gates. Only worth factorising after target selection is a learned choice point |
| 4 | **Defensive strategy** | not covered as such | none direct | — | The paper offers nothing here beyond the general loss. Defence is a cost/benefit computation, not an equilibrium search |
| 5 | **Opponent modelling** | **not modelled** — fixed opponent pool; equilibrium against the population, no belief over the specific opponent | — | — | **Reject as a source.** For opponent modelling our own `IntelBook`/`ActivityIntelReader`/`GalaxyMap` and the CBRKit experience driver are the tools; this paper does not address it |
| 6 | **Long-term strategic planning** | credit via Q from search; no explicit option/plan machinery | Time-scaled ΔV already gives delayed credit (`[ours]` `plan/rl/reward-design.md §5`); no change | — | The paper's "long-horizon" is still one-turn-at-a-time with a value function. Ours is the same. No new mechanism |
| 7 | **Multi-agent competition** | equilibrium search over 7 players `[D]`; theory only for 2p0s | Only relevant once our policy owns interactive decisions (raids/saves, C5/C7) `[H]` | Would need a payoff/opponent model the module does not have | **Yes:** our cohort of deterministic opponents + twin-universe pairing is the pragmatic substitute. piKL is a large build for an unproven payoff |
| 8 | **Humanlike strategic variability** | β trades value vs the human anchor τ; Fig 7 shows imitation shifting toward the equilibrium | Use the teacher as anchor and a value term as the "explore" pull; temperature at serving `[ours]` `ogrl.serve` | Weighted loss with a KL-to-teacher penalty (already planned for PPO `[ours]` `improving.md §5`) | **Yes:** persona YAML already gives archetype-conditional taste; a temperature/ε at serving gives variability. A learned β per persona is deferrable |

**Reading the matrix.** The paper is strongest exactly where we already are (per-choice softmax) and weakest
where our north star currently points (social, opponent modelling, long-horizon interactive play). Its one
untapped idea for us is row 1: sequential, budget-aware conditioning within a login.

---

## 5. Architecture alternatives

Four candidate architectures for "learn what the account does", judged against `gate 2` (smallest mechanism).

**A. Current: independent candidate scoring (baseline).** One MLP scores each `(state, candidate)` per
choice point; softmax over the legal list; choice points independent. `[ours]` `rl/ogrl/model.py`,
`plan/rl/state-and-action-space.md`. Pros: proven, 0.3–4.3 M params, 69 µs/decision, teacher fallback,
Gate-1 clean. Cons: no session-level coherence; budget conflicts are resolved by the host refusing, not by
the model planning.

**B. B + session-level autoregression (recommended experiment).** Add a small "session so far" block to the
state and process the login's choice points in order, feeding earlier picks forward. This is the *minimum*
adaptation of the paper's AF that fits our seam: one extra state block, one encoder version bump, same
network, same training. Cost: a new encoder version, regenerate data, retrain, closed-loop test. It is
nameable as professional play (plan the login as a sequence) and stays inside Gate 1 (all features
host-derived) and Gate 2 (no new class, no new abstraction).

**C. DipLLM-style LLM policy (rejected).** Replace the MLP with a fine-tuned 8B model per decision.
Against it: the reference deployment is 2 vCPU/2 GB **no GPU** (`[ours]` `AGENTS.md` budgets, memory
`phase-3-external-drivers.md`); `AGENTS.md` mandates **zero generative calls** in ordinary play and
training; the input is ~130 numbers and the answer is a rank over ≤32 rows, so there is no text and no
pretrained prior to exploit; latency would break the authenticity observer's reaction-latency measure
(`account-authenticity.md`). Reject on gates 1/2/3 and budget, not on quality.

**D. Search-based teacher (piKL / MCTS) for the economy (rejected for now).** Compute Q-values by search
and use them to weight the loss. Against it: the economy choice is largely single-player vs the
environment; there is no equilibrium to find, and the deterministic planner already encodes the rules of
thumb a search would rediscover. Search might pay for **raid target selection and fleetsave under attack**
(C5/C7) where an opponent's intent matters — but that is a large build and those choice points are not yet
scored lists (`[ours]` `improving.md §6`). Defer behind the C5/C7 rows.

**E. Hybrid: LLM/solver as an offline teacher only (defer).** Never call a model at play time; use one
offline to produce better labels or Q-values, then distil into the small scorer (teacher-neutral, Gate 1
safe). This is the only form in which "an LLM" is even admissible in this module (`[ours]`
`plan/details/specs/classification-use-plan.md` keeps models off the decision path). Not worth it while BC
from the planner already passes its gates.

**Recommendation: B, then the value-weighted loss H2, then revisit C5/C7 (table row 3/7) before any search.**

---

## 6. Proposed experiments

Every experiment below is gated the way the module already gates: a hypothesis, a baseline, a held-out
closed-loop twin test, and a success criterion that is *an aspect moving*, not a loss number.

### H1 — Session-level autoregressive factorization (primary)

**Hypothesis.** Conditioning each choice point in a login on the choices already made earlier in the same
login improves closed-loop play (ΔV and/or coherence) at fixed data, model size and training setup.

**Why it is the right first test.** It is the paper's dominant mechanism (`[paper Table 2]`, AF is worth
~9.6 SoS points with FT vs 0.8 without), and it targets a concrete absence in our code (`[ours]`
`DecideAiEconomyStepsAction` scores all sets from one state). It needs no new class and no new dependency.

**Baseline algorithm.** BC v4 (or the current head) exactly as in `plan/rl/next-steps.md` — identical
weights, data, seeds, and evaluation harness (`rl/scripts/generate.sh`, `closed_loop.sh`).

**Dataset.** The same v2 choice recordings, re-encoded under encoder **v3** with the added state block; the
recorder already stores one row per choice point with the teacher label, so only the encoder changes
(`[ours]` `EconomyChoiceEncoder::VERSION` → bump to 3; `ogrl.data` refuses mixed versions). One extra
piece of data is needed: the *within-login order* of the choice points, which the recorder already has via
`decisionKey` (`[ours]` `DecideAiEconomyStepsAction::handle` passes `$decisionKey . ':…'`).

**State/action representation.** Action unchanged (candidate rows, mask over legal). State gains a
fixed-size "session so far" block, all host-derived and identity-free (Gate 1):

- resources already committed this login (metal/crystal/deut, log1p), as a fraction of the opening stock,
- count of choice points already answered, and which *kinds* (`kind_research`, `kind_yard`, `kind_errand`
  already exist as one-hots),
- whether the lab is now occupied, how many planet queues are now busy,
- `[hyp]` optionally the pass one-hot of the previous pick, so the model can see "I already did a doctrine
  build, so this planet should be routine".

At inference the choice points are processed strictly in the planner's order (planet queue(s) → lab →
yard → errand), each with the running block updated by the previous pick. The host still validates every
pick, so a wrong answer costs one fallback, exactly as today.

**Model.** Unchanged `CandidateScorer` (forward signature `(state, cands, mask) -> (logits, value)` must
stay, `[ours]` `improving.md §4`). Width/param count held constant so the comparison is clean.

**Training objective.** Unchanged BC cross-entropy (H1 isolates the factorization, not the loss).

**Hardware / cost.** Identical to the current BC runs `[ours]` `plan/rl/next-steps.md`: data generation is
CPU-bound (16 workers, hours), training is seconds/epoch on the RTX 3090, closed loop is 16 universes ×
12 days. No new GPU memory.

**Evaluation.** Exactly the twin-universe paired test in `plan/rl/evaluation-plan.md`: pre-registered
held-out seeds (not 1001–1016/1201–1216, which the v2–v4 models saw), 16 pairs × 12 accounts × 12 days,
learner share 1.0, 0 socket fallbacks. Report **total value ratio, median, geometric mean, and the 95% CI
of the mean of per-account ratios** (the next-steps log learned the mean alone is skewed). Add one
*coherence* metric tied to an existing aspect: the share of logins whose orders the host refused
(`work_failures` in `ogamex scorecard`) — mainly picks that do not fit the budget the earlier picks in the
same login already spent `[hyp]`. Today a same-login over-spend is invisible; it is the direct symptom AF
should reduce.

**Success / failure.**
- **Success:** total value ratio ≥ 1.00 (not worse) **and** either median ΔV improves by a margin whose CI
  excludes 0, or the `work_failures` refusal rate falls materially, with 0 socket fallbacks and 0 sim errors.
- **Failure / stop:** no improvement in either, or worse with a CI excluding 0 — then record it and close
  the AF direction for the economy (a clean negative result is the deliverable). Do not tune seeds to pass.
- **`[hyp]` Expected effect size:** small. OGame session decisions are largely independent across planets,
  so AF should move coherence more than raw ΔV. Pre-register "coherence improves, ΔV not worse" as the
  realistic pass condition to avoid over-claiming.

### H2 — Value-weighted imitation (the paper's loss, self-generated value)

**Hypothesis.** Weighting the BC cross-entropy by `exp(advantage)` (advantage from the trained critic or
Monte-Carlo returns of the simulator) improves closed-loop ΔV over plain BC at fixed data.

**Why it maps.** It is the paper's Eq 6 `[paper §4.3]` with a different Q source: the paper's Q comes from
piKL search; ours must come from a value head trained on returns (the `value` head already exists, untouched
by BC `[ours]` `rl/ogrl/model.py`). `[hyp]` Realistically this is *advantage-weighted regression*, a warm
start for the already-planned PPO, not a replacement.

**Data.** The same recorded rows; `value` per row already recorded (`[ours]` `ChoiceRecorder::record(...,
$account['value'])`). Advantage = return-to-go minus the critic's baseline, computed over a login's rows.

**Caveats to state up front.** The paper's Q is a search estimate over *counterfactual* actions; ours is
about the *taken* trajectory, so it cannot upweight an action the teacher never tried — the signal is
weaker. Normalisation of `exp{Q}` is unspecified in the paper `[hyp]`; use a per-batch softmax and monitor
weight entropy.

**Success:** closed-loop ΔV significantly > plain BC on pre-registered seeds, no increase in socket
fallbacks or sim errors. **Failure:** no gain → drop it and go straight to PPO from BC.

### H3 — The two diagnostics the paper gives, applied to our existing model (cheap, do now)

1. **Action-distribution shift (Fig 7 analogue).** Compare the learned policy's choice distribution against
   the teacher's per `kind` and per `GamePhase`. We have the data; this is a plotting script over the
   existing recordings `[ours]` `rl/scripts/review_sheet.py` is close. Tells us where the model *changes*
   play, which is the first thing to read before any new training.
2. **Learning-curve vs data volume (Fig 6 analogue).** Train BC on 1×, 2×, 4× universes and plot
   `top1_3plus_legal` and closed-loop ΔV. Directly answers `improving.md §1` ("more universes beat more
   days") with evidence instead of assumption.

Both are analysis, not training runs; both feed the next-steps Results table.

### Explicitly not proposed

No LLM fine-tune, no piKL/MCTS build, no DPO, no self-play league. Each is either forbidden by our gates
and reference profile or is behind an unpassed gate (`G-RL start`).

---

## 7. Dataset and training requirements

**H1 (session AF).** Same corpus as v2, re-encoded at encoder v3. Volume already comfortable: 1,153,216
rows / 172 universes at v4 (`[ours]` `plan/rl/next-steps.md`), gate G-data was ≥300k points from ≥24
universes. The only new requirement is that the *within-login order* be preserved when a recording is
replayed into features — the recorder's `decisionKey` already carries it, so no re-recording is required,
just a re-encode (`ogrl.data` + `EconomyChoiceEncoder`). Training data for H2 = the same rows plus the
critic's advantage, produced by a rollout pass with the existing `ai:sim --choice-policy=socket`.

**No new labels, no human annotation, no model calls.** All state features are host-derived (Gate 1).

**What the paper's data path would need (for completeness, not a plan).** piKL-Hedge Q-values require a
value network and search rollouts; DipNet requires Diplomacy human games. Neither exists in our domain and
neither is on the reference path.

---

## 8. Hardware feasibility

Available: RTX 3090 24 GB + RTX 4070 Ti SUPER 16 GB. Assumptions stated where estimates are not measured;
measured numbers cite `[ours]` where noted.

| Workload | Params | Activation memory (assumed) | Feasible? | Notes |
| --- | --- | --- | --- | --- |
| BC / H1 / H2 training (candidate scorer) | 0.3–4.3 M | < 1 GB at batch 1024–4096 | Yes, trivially | Measured ~1 s/epoch at 4.3 M, 80 epochs on the 3090 `[ours]` `next-steps.md` |
| Twin-universe closed loop (eval) | — | none (ONNX, CPU) | Yes | CPU-bound on the sim; GPU idle `[ours]` `training-plan.md §9` |
| Data generation | — | none | Yes | 16 CPU workers; the GPU is not used |
| *(Rejected)* DipLLM full reproduction: Llama-3-8B LoRA, seq 2048, batch 4 | 8 B | `[hyp]` ~16–22 GB with LoRA + gradient checkpointing; more without 4-bit base | Borderline on 3090, out of scope | Listed only to show it is *possible but pointless* here |
| *(Rejected)* LLM inference per decision | 8 B | `[hyp]` GB-scale, tens of ms/decision on GPU, no GPU in production | No | Violates `AGENTS.md` zero-generative-call rule and the reference profile |

**Conclusion.** The recommended experiment (H1) adds **no** GPU pressure and reuses the existing pipeline.
The paper's own training regime is not where our compute should go.

---

## 9. Benchmark methodology

Reuse the module's existing, already-validated protocol rather than inventing one `[ours]`
`plan/rl/evaluation-plan.md`, `plan/rl/next-steps.md`:

1. **Paired twin universes.** Policy side vs teacher side from the same seed and snapshot; paired
   differencing removes universe difficulty. Seeds pre-registered before the run, held out from training.
2. **n and reporting.** ≥ 16 pairs × 12 accounts × 12 days; report total value ratio, median, geometric
   mean, and mean-of-ratios with a 95% CI (the mean alone is skewed — a documented lesson in the log).
3. **Validity guards.** 0 sim errors; 0 socket fallbacks; identical digests for top/bottom seeds on the
   MySQL path (parity); the teacher path byte-identical (`EconomyChoiceSeamTest`).
4. **Offline metrics** (necessary, not sufficient): `top1_3plus_legal`, MRR, `top1_hard_rows`.
5. **The north-star read.** Any behavioural claim must show an aspect of `ogamex scorecard` move; for H1
   the relevant aspect is `economy` (and the new coherence metric).
6. **Statistical honesty.** No seed tuning after seeing results; a clean negative is reported as a result.

The paper contributes two *evaluation ideas* worth adding: the action-distribution-shift plot (H3.1) and
the data-volume learning curve (H3.2). Neither needs new infrastructure.

---

## 10. Recommended next steps

Ordered by expected learning value per unit of effort, all as **proposals/handoffs** (this document changes
no production code; `AGENTS.md` governs how a row is claimed and closed):

1. **Write H1 as a task row** (`IMPL-` / `plan/rl/`), with proof `aspect:economy` plus the coherence
   metric, `file_ref` = `EconomyChoiceEncoder` + `ogrl` data/model, and gate = the twin-universe test. This
   is the only mechanism from the paper I recommend building.
2. **Run H3 now** (two analysis scripts over the existing v2/v4 recordings): the action-distribution shift
   and the data-volume curve. Zero training cost, immediate information, feeds the next-steps table.
3. **Queue H2 behind PPO readiness**, framed as advantage-weighted BC (a PPO warm start), never as a
   piKL-style equilibrium objective. Do not start it before the BC closed-loop gate is signed off.
4. **Record the rejections** (LLM backbone, piKL for economy, DPO) in `plan/rl/next-steps.md` so the same
   idea is not re-proposed, with this document as the pointer.
5. **Revisit the paper only for C5/C7** (raid targets, fleetsave under attack), where an opponent's intent
   exists and search/equilibrium could in principle matter — *after* those choice points become scored
   lists (`improving.md §6`). Until then, defer.

**Do not**: fine-tune an LLM, build piKL, or start a league. Each fails a gate or the reference profile.

---

## 11. References and source links

**Primary**
- Xu, Chai, Li, Fu, Zhu, Zhao. *DipLLM: Fine-Tuning LLM for Strategic Decision-making in Diplomacy.*
  ICML 2025. arXiv:2506.09655v2 — <https://arxiv.org/abs/2506.09655> ·
  HTML <https://arxiv.org/html/2506.09655v1>. Sections cited: §1, §3.1–3.3, §4.1–4.3 (Eq 1–6), §5.1–5.2
  (Tables 2, 5; Figs 4–8), §6, App. C (Thm 1–2, loss derivation, Q lower bound), App. D.1–D.2 (Tables 3–4),
  App. E.1–E.3 (Tables 6–7).

**Cited within the paper (relevant to us)**
- Jacob et al. 2022, *Modeling strong and human-like gameplay with KL-regularized search* (piKL; Corollary 1).
- Bakhtin et al. 2022, *Human-level play in Diplomacy…* (Cicero; the 448-GPU figure and the 1.5% baseline).
- Paquette et al. 2019, *No-press Diplomacy: Modeling multi-agent gameplay* (DipNet).
- Chebotar et al. 2023, *Q-Transformer* (autoregressive Q functions — the same factorization idea in RL).
- Rafailov et al. 2023 (DPO), Schulman et al. 2017 (PPO) — both reported to underperform the paper's loss.

**Our repository**
- `Modules/AI/AGENTS.md` (gates, direction), `plan/HANDOFF.md` (state and order).
- `plan/rl/README.md`, `state-and-action-space.md`, `reward-design.md`, `training-plan.md`,
  `evaluation-plan.md`, `next-steps.md` (status + Results log).
- `.github/skills/ogame-rl-training/SKILL.md` and `references/improving.md` (the workflow and gates).
- Code: `app/Actions/DecideAiEconomyStepsAction.php` (simultaneous choice points — the AF gap),
  `app/Domain/Choice/EconomyChoiceEncoder.php` (features, `VERSION`), `app/Contracts/ChoicePolicy.php`,
  `rl/ogrl/model.py` (`CandidateScorer`, unused `value` head), `config/rl.php`.
- `plan/details/specs/classification-use-plan.md` (why no model sits on the decision path),
  `plan/details/research/account-authenticity.md` (latency/uptime as the real target).
