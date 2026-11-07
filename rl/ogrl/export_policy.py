"""A checkpoint as the JSON `season::Policy` reads, so a season plays the model in Rust and hds.py judges its lines.

    python -m ogrl.export_policy runs/x/model.pt runs/x/policy.json

An errand checkpoint (queue 2) is stamped with its feature version (`_errand_feature_version`, 2 unless the checkpoint
says otherwise) and its column names (`_names`), so a later run can widen it by name (`ogrl.errand_schema`).
"""

from __future__ import annotations

import json
import sys

import torch

from .errand_schema import policy_json


def main() -> None:
    saved = torch.load(sys.argv[1], map_location="cpu", weights_only=False)
    if saved.get("queue") == 2:
        if "target_ratio_observed_or_estimated" not in saved.get("names", {}).get("candidate", []):
            raise SystemExit("errand checkpoint has old or missing feature names; retrain it")
        weights = policy_json(saved)
    else:
        weights = {name: tensor.tolist() for name, tensor in saved["model"].items()}
    with open(sys.argv[2], "w") as out:
        json.dump(weights, out)


if __name__ == "__main__":
    main()
