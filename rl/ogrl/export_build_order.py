"""Export a build-order policy trained in ogsim (train_ppo) so the policy server can answer PHP's building choices.

    python -m ogrl.export_build_order storage/rl/ogsim/runs/enc-s2/model.pt storage/rl/build-model --schema storage/rl/bc6/choices-4001.jsonl.schema.json

The simulator computes a named subset of EconomyChoiceEncoder's columns; the ONNX file takes the encoder's full vectors and
picks those columns itself, so nothing about the model's inputs lives anywhere but in the file and its schema.json.
"""

from __future__ import annotations

import argparse
import json
from pathlib import Path

import torch
from torch import nn

from .export import export_onnx
from .model import CandidateScorer


class PickColumns(nn.Module):
    def __init__(self, model: CandidateScorer, state_columns: list[int], cand_columns: list[int]):
        super().__init__()
        self.model = model
        self.register_buffer("state_columns", torch.tensor(state_columns))
        self.register_buffer("cand_columns", torch.tensor(cand_columns))

    def forward(self, state, cands, mask):
        return self.model(state[:, self.state_columns], cands[:, :, self.cand_columns], mask)


def export(checkpoint: str, out_dir: str, schema_path: str) -> Path:
    saved = torch.load(checkpoint, map_location="cpu", weights_only=False)
    schema = json.loads(Path(schema_path).read_text())
    names = saved["names"]
    missing = [n for n in names["state"] if n not in schema["state"]] + [n for n in names["candidate"] if n not in schema["candidate"]]
    if missing:
        raise SystemExit(f"the encoder (schema v{schema['version']}) has no column named {missing}")
    model = CandidateScorer(saved["state_dim"], saved["cand_dim"], saved["width"])
    model.load_state_dict(saved["model"])
    wrapped = PickColumns(model, [schema["state"].index(n) for n in names["state"]], [schema["candidate"].index(n) for n in names["candidate"]])
    out = Path(out_dir)
    out.mkdir(parents=True, exist_ok=True)
    export_onnx(wrapped, out / "model.onnx", len(schema["state"]), len(schema["candidate"]))
    (out / "schema.json").write_text(json.dumps({"version": schema["version"], "state": schema["state"], "candidate": schema["candidate"], "kind": "build_order", "uses": names}, indent=1))
    print(f"{checkpoint} -> {out / 'model.onnx'} (encoder v{schema['version']}, {len(names['state'])} state and {len(names['candidate'])} candidate columns)")
    return out / "model.onnx"


def main(argv: list[str] | None = None) -> None:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("checkpoint")
    ap.add_argument("out_dir")
    ap.add_argument("--schema", required=True, help="the schema.json of recorded choices: the encoder's full column names")
    args = ap.parse_args(argv)
    export(args.checkpoint, args.out_dir, args.schema)


if __name__ == "__main__":
    main()
