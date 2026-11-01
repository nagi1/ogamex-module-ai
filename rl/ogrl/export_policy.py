"""A checkpoint as the JSON `season::Policy` reads, so a season plays the model in Rust and hds.py judges its lines.

    python -m ogrl.export_policy runs/x/model.pt runs/x/policy.json
"""

from __future__ import annotations

import json
import sys

import torch


def main() -> None:
    saved = torch.load(sys.argv[1], map_location="cpu", weights_only=False)
    weights = {name: tensor.tolist() for name, tensor in saved["model"].items()}
    with open(sys.argv[2], "w") as out:
        json.dump(weights, out)


if __name__ == "__main__":
    main()
