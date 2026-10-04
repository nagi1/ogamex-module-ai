"""ONNX export of a CandidateScorer for the policy server (and later PHP via ONNX Runtime FFI)."""

from __future__ import annotations

from pathlib import Path

import torch

from .model import CandidateScorer


def export_onnx(model: CandidateScorer, path: str | Path, state_dim: int, cand_dim: int) -> Path:
    model = model.eval().cpu()
    path = Path(path)
    state = torch.zeros(1, state_dim)
    cands = torch.zeros(1, 8, cand_dim)
    mask = torch.ones(1, 8, dtype=torch.bool)
    names = {"input_names": ["state", "cands", "mask"], "output_names": ["logits", "value"]}
    try:
        # The classic exporter handles the dynamic candidate axis directly.
        torch.onnx.export(
            model, (state, cands, mask), str(path), opset_version=17, dynamo=False,
            dynamic_axes={"cands": {1: "K"}, "mask": {1: "K"}, "logits": {1: "K"}}, **names,
        )
    except (TypeError, RuntimeError):
        k = torch.export.Dim("K", min=1, max=64)
        torch.onnx.export(
            model, (state, cands, mask), str(path), dynamo=True,
            dynamic_shapes={"state": None, "cands": {1: k}, "mask": {1: k}}, **names,
        )
    return path
