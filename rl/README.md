# ogrl: training side of the economy policy

The PHP module decides and plays; this package learns. Design: `plan/rl/` (start at `plan/rl/next-steps.md`).

```
ai:rl-universe ──► universe-N.sqlite ──► ai:sim --in-memory --seed=N --record-choices ──► choices-N.jsonl
                                                                                              │
                         python -m ogrl.train_bc --data 'choices-*.jsonl' ◄──────────────────┘
                                         │ model.onnx
                         python -m ogrl.serve --model model.onnx --socket /tmp/ogrl.sock
                                         ▲ JSON line per choice
           ai:sim --choice-policy=socket --choice-socket=/tmp/ogrl.sock (falls back to the planner on any failure)
```

| File | What |
| --- | --- |
| `ogrl/data.py` | Loads recorded choice points into padded arrays (K = 32 rows, row 0 = wait); split by universe |
| `ogrl/model.py` | `CandidateScorer`: shared MLP over (state, candidate) pairs, masked softmax; normalisation inside the model |
| `ogrl/train_bc.py` | Behaviour cloning on the planner's choices; writes `model.pt`, `model.onnx`, `metrics.json` |
| `ogrl/metrics.py` | top-1/top-3/MRR overall and on 3+ legal rows, by kind/archetype/phase/list size, payback regret |
| `ogrl/export.py` | ONNX export with a dynamic candidate axis |
| `ogrl/serve.py` | Unix-socket policy server for `SocketChoicePolicy` (argmax, or seeded sampling) |
| `ogrl/evaluate.py` | Twin-universe comparison of account value, planner vs policy, per account and archetype |
| `scripts/generate.sh` | N universes × D days in parallel, choices recorded |
| `scripts/closed_loop.sh` | Serve a model and play twin universes against the planner, then evaluate |

Install (on the training machine, CUDA build of PyTorch for the RTX 3090):

```
python3 -m venv .venv && . .venv/bin/activate
pip install torch --index-url https://download.pytorch.org/whl/cu128   # or the CUDA build matching the driver
pip install -e Modules/AI/rl
```

Recorded row (one per free build queue or lab at a login): `state` (45 numbers), `cands` (27 per row),
`legal`, `teacher`, `chosen`, `policy`, `learner`, `value` (invested + held, for rewards later), `kind`,
`archetype`, `player`, `t`, `seed`. Feature names: the `.schema.json` beside each file
(`EconomyChoiceEncoder::stateNames()` / `candidateNames()` in PHP). Changing a feature means bumping
`EconomyChoiceEncoder::VERSION` and recording new data.
