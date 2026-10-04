# ML stack research (checked 4 October 2026)

## 1. Versions and status

| Library | Current | Status | Source |
| --- | --- | --- | --- |
| PyTorch | 2.14.0 (2 Sep 2026); 2.15 scheduled 28 Oct 2026 | Active. Python 3.10 dropped in 2.15. | [dev-discuss: 2.14 GA](https://dev-discuss.pytorch.org/t/pytorch-2-14-0-general-availability/3431), [3.10 removal notice](https://dev-discuss.pytorch.org/t/notice-python-3-10-support-is-being-removed-from-pytorch-2-15-2-14-is-the-last-release-with-3-10-wheels/3440) |
| Stable-Baselines3 | 2.9.0 (15 Jun 2026) | Active, maintenance pace. torch ≥ 2.8, gymnasium < 2.0, Python ≥ 3.10. | [SB3 changelog](https://stable-baselines3.readthedocs.io/en/master/misc/changelog.html) |
| sb3-contrib (MaskablePPO, RecurrentPPO) | 2.9.0 (15 Jun 2026) | Active. 2.8.0 fixed `MaskableCategorical.apply_masking()` crashing on large action spaces in float32. | [sb3-contrib changelog](https://sb3-contrib.readthedocs.io/en/master/misc/changelog.html) |
| Gymnasium | 1.3.0 (22 Apr 2026) | Active. | [PyPI](https://pypi.org/project/gymnasium/) |
| PufferLib | 3.0.0 (Jun 2025) | Fast vectorisation (shared-memory buffers); ~1M steps/s for C envs. Irrelevant at our env speed. | [PyPI](https://pypi.org/project/pufferlib/), [docs](https://www.mintlify.com/PufferAI/PufferLib/concepts/vectorization) |
| ONNX Runtime | 1.30.0 (10 Sep 2026) | Active. Used here for the latency measurement. | [PyPI](https://pypi.org/project/onnxruntime/) |
| PyO3 | 0.29.0 (12 Jun 2026) | Active; free-threaded 3.14t/3.15t support. Measured here: 47 ns per call. | [PyO3 free-threading](https://pyo3.rs/main/free-threading) |
| TorchScript | **Deprecated** (PyTorch docs since 2.10): use `torch.export` / ONNX | Do not build on it. | [PyTorch docs](https://docs.pytorch.org/docs/2.9/jit_unsupported.html) |
| PHP ONNX bindings | `ankane/onnxruntime` 0.3.3 (FFI) | Small, FFI-based; PHP FFI is already enabled in the host image (Rust battle engine). | [Packagist](https://root.packagist.org/packages/ankane/onnxruntime) |

## 2. Where the throughput limit is

PufferLib and SB3 vectorisation tune for 10⁵–10⁶ env steps/s. Our measured environment produces about
**10–60 decisions per second per process** (section 4 of [benchmark-plan.md](benchmark-plan.md)). Even at 16
processes, the learner sees < 10³ samples/s. **The learning library's speed is irrelevant**; correctness,
action representation and debuggability decide.

## 3. Algorithm fit

Requirements: discrete choice among a **variable, legal-only** candidate list; long delayed consequences;
several accounts per universe sharing one policy; persona as input; offline teacher data available.

| Algorithm | Fit | Notes |
| --- | --- | --- |
| Behaviour cloning (cross-entropy over the candidate list) | **Phase 1** | The teacher's choice is available for every C1–C3 decision. Plain supervised PyTorch. |
| **PPO with a candidate-scoring policy** (logits = per-candidate scores, illegal = not present) | **Phase 2 main algorithm** | On-policy, stable, handles masks natively when the list *is* the legal set. Write it CleanRL-style (one file, ~400 lines) because SB3's policies assume a fixed `action_space`. |
| SB3 `MaskablePPO` with padded `Discrete(K)` + `action_masks()` | Usable sanity baseline | Needs candidate rows packed into a fixed `Box(K, F)` observation and a custom feature extractor that scores rows. Works, but fights the framework. Multi-agent stepping (several learner accounts per universe, event-driven) also does not map onto `VecEnv` lockstep without padding tricks. |
| DQN variants | Poor | Value per candidate is possible (Q(s, c)), but off-policy bootstrapping with delayed, high-variance rewards and a non-stationary candidate set is harder to stabilise than PPO. Only revisit for offline RL. |
| RecurrentPPO / LSTM | Not needed for v1 | The state is near-Markov when goals, queues and timers are features. `ai_goals` gives persisted intent. |
| Hierarchical (choose manager, then candidate) | Later | The planner choice points already are the lower level. A high-level "budget envelope" policy over `managers.yaml` numbers is a natural v3. |
| Offline RL (IQL, CQL, AWAC) on deterministic traces | Limited | One deterministic teacher gives near-zero action diversity per state, so offline RL cannot learn better than the teacher without perturbed data. Useful only once logs contain mixed policies (epsilon-perturbed teacher). |
| Self-play / league | Phase 4 | Only once the policy controls interactive decisions (raids, saves). For economy-only, opponents matter little. |

## 4. Recommended stack

```
PyTorch 2.14 (CUDA on the 3090 for training)          – networks, BC, PPO (CleanRL-style, own file)
Gymnasium 1.3 API for the environment wrapper           – reset/step semantics, truncated vs terminated
SB3 2.9 + sb3-contrib MaskablePPO                        – optional baseline only, not the core
ONNX (torch.onnx / torch.export)                         – model hand-off from training to the game
ONNX Runtime 1.30 – Python side for evaluation; PHP side via FFI (ankane/onnxruntime) for production
```

Why not SB3 as the core: it assumes Python drives `env.step()` for N synchronous envs with a fixed action
space. Our environment is PHP-driven, event-ordered, multi-agent per universe, and has a variable action list.
A small custom PPO is less code than adapting SB3 to that, and is easier to read for a developer new to RL.

## 5. CPU inference: measured

0.293 M-parameter candidate scorer (state 100 → 256 → 256; candidate 30 → 128; joint 384 → 256 → 128 → 1),
exported to ONNX, ONNX Runtime 1.30, one intra-op thread, on this 4-vCPU cloud Xeon:

| Candidates per decision | Latency |
| --- | --- |
| 8 | 34 µs |
| 32 | 69 µs |
| 128 | 207 µs |

A session costs 100–500 ms of PHP today. **Inference is < 0.1% of a decision.** GPU is not needed for play.
PHP → ONNX Runtime via FFI adds marshalling; the FFI call itself costs 54 ns (measured, Rust lib). Expect
well under 1 ms per decision end to end. To confirm in the PoC (gate G5).
