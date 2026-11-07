"""Widening the errand model by column name (Stage C warm start): the widened model scores old candidates exactly as the
old model did, whatever the new columns hold, and the narrow policy json round-trips through the widening.

    .venv-rl/bin/python -m pytest Modules/AI/rl/tests -q
"""

from __future__ import annotations

import json

import pytest
import torch

from ogrl.errand_schema import (LEGACY_CAND, LEGACY_CANDIDATE, LEGACY_KINDS, LEGACY_STATE, load_errand, policy_json,
                                read_header, resolve_names, widen_checkpoint)
from ogrl.model import CandidateScorer

# What the `wide` arm appends: new names at the END of STATE_NAMES, CAND_NAMES and KIND_NAMES. In the flat candidate row
# the new CAND columns therefore sit between the old CAND columns and the kind one-hots.
NEW_STATE = ["moon", "fleet_value", "war_hulls", "acs_open", "phalanx"]
NEW_CAND = ["predicted_loss", "fleet_role", "speed", "hull_share", "unit_cost", "batch", "moon_target"]
NEW_KINDS = ["strike", "moon_destroy", "moon_shot", "acs_join", "acs_defend", "expedition", "deploy_save", "phalanx_scan"]
WIDE = {"state": LEGACY_STATE + NEW_STATE, "candidate": LEGACY_CAND + NEW_CAND + LEGACY_KINDS + NEW_KINDS}
OLD = {"state": list(LEGACY_STATE), "candidate": list(LEGACY_CANDIDATE)}
K_OLD, K_NEW = 32, 64


def trained_narrow(seed: int = 0) -> CandidateScorer:
    """A narrow model with nothing at its defaults: random weights and a non-trivial normalisation."""
    torch.manual_seed(seed)
    model = CandidateScorer(len(OLD["state"]), len(OLD["candidate"]), 64)
    with torch.no_grad():
        for p in model.parameters():
            p.normal_(0, 0.3)
        model.set_normalisation(torch.randn(12), torch.rand(12) + 0.5, torch.randn(20), torch.rand(20) + 0.5)
    return model.eval()


def old_batch(seed: int = 1, batch: int = 50):
    g = torch.Generator().manual_seed(seed)
    state = torch.randn(batch, 12, generator=g) * 3
    cands = torch.randn(batch, K_OLD, 20, generator=g) * 3
    kinds = torch.randint(0, 7, (batch, K_OLD), generator=g)
    cands[..., 13:] = torch.nn.functional.one_hot(kinds, 7).float()
    legal = torch.rand(batch, K_OLD, generator=g) < 0.6
    legal[:, 0] = True  # wait
    return state, cands, legal


def embed(state, cands, legal, noise: bool):
    """Old-format rows in the wide layout (by name), padded to 64 candidates; new columns random when `noise`."""
    g = torch.Generator().manual_seed(7)
    b = state.shape[0]
    wide_state = torch.randn(b, len(WIDE["state"]), generator=g) * 5 if noise else torch.zeros(b, len(WIDE["state"]))
    wide_cands = torch.randn(b, K_NEW, len(WIDE["candidate"]), generator=g) * 5 if noise else torch.zeros(b, K_NEW, len(WIDE["candidate"]))
    for i, name in enumerate(OLD["state"]):
        wide_state[:, WIDE["state"].index(name)] = state[:, i]
    for i, name in enumerate(OLD["candidate"]):
        wide_cands[:, :K_OLD, WIDE["candidate"].index(name)] = cands[..., i]
    wide_legal = torch.zeros(b, K_NEW, dtype=torch.bool)
    wide_legal[:, :K_OLD] = legal
    return wide_state, wide_cands, wide_legal


def widened(model: CandidateScorer, saved_extra: dict | None = None) -> CandidateScorer:
    saved = {"model": model.state_dict(), "state_dim": 12, "cand_dim": 20, "width": 64, "names": OLD, "errand_feature_version": 2, **(saved_extra or {})}
    wide = widen_checkpoint(saved, WIDE)
    assert wide["errand_feature_version"] == 3
    out = CandidateScorer(len(WIDE["state"]), len(WIDE["candidate"]), 64)
    out.load_state_dict(wide["model"])
    return out.eval()


@pytest.mark.parametrize("noise", [False, True])
def test_widened_model_scores_old_candidates_identically(noise):
    narrow = trained_narrow()
    wide = widened(narrow)
    state, cands, legal = old_batch()
    with torch.no_grad():
        before, value_before = narrow(state, cands, legal)
        after, value_after = wide(*embed(state, cands, legal, noise))
    torch.testing.assert_close(after[:, :K_OLD][legal], before[legal], rtol=0, atol=1e-5)
    torch.testing.assert_close(after[:, :K_OLD].softmax(1), before.softmax(1), rtol=0, atol=1e-6)
    assert (after[:, :K_OLD].argmax(1) == before.argmax(1)).all()
    torch.testing.assert_close(value_after, value_before, rtol=0, atol=1e-5)


def test_new_columns_start_at_weight_zero_and_unit_normalisation():
    wide = widened(trained_narrow()).state_dict()
    new_s = [WIDE["state"].index(n) for n in NEW_STATE]
    new_c = [WIDE["candidate"].index(n) for n in NEW_CAND + NEW_KINDS]
    assert wide["state_net.0.weight"][:, new_s].abs().sum() == 0
    assert wide["cand_net.0.weight"][:, new_c].abs().sum() == 0
    assert (wide["state_mean"][new_s] == 0).all() and (wide["state_std"][new_s] == 1).all()
    assert (wide["cand_mean"][new_c] == 0).all() and (wide["cand_std"][new_c] == 1).all()


def test_policy_json_round_trip_and_unnamed_v2_fallback(tmp_path):
    """Today's exported policies carry no names but the version-2 stamp: they load as today's names and widen."""
    narrow = trained_narrow()
    exported = policy_json({"model": narrow.state_dict(), "state_dim": 12, "cand_dim": 20, "names": OLD, "errand_feature_version": 2})
    assert exported["_errand_feature_version"] == 2 and exported["_names"] == OLD
    del exported["_names"]
    path = tmp_path / "errand.policy.json"
    path.write_text(json.dumps(exported))
    loaded = load_errand(path)
    assert loaded["names"] == OLD and loaded["width"] == 64 and loaded["errand_feature_version"] == 2
    wide = CandidateScorer(len(WIDE["state"]), len(WIDE["candidate"]), 64)
    wide.load_state_dict(widen_checkpoint(loaded, WIDE)["model"])
    state, cands, legal = old_batch(seed=3)
    with torch.no_grad():
        torch.testing.assert_close(wide.eval()(*embed(state, cands, legal, True))[0][:, :K_OLD].softmax(1), narrow(state, cands, legal)[0].softmax(1), rtol=0, atol=1e-6)


def test_unnamed_unstamped_model_is_refused(tmp_path):
    exported = {k: v.tolist() for k, v in trained_narrow().state_dict().items()}
    path = tmp_path / "old.policy.json"
    path.write_text(json.dumps(exported))
    with pytest.raises(ValueError, match="no feature names"):
        load_errand(path)


def test_widening_refuses_a_dropped_column():
    saved = {"model": trained_narrow().state_dict(), "state_dim": 12, "cand_dim": 20, "names": OLD}
    narrower = {"state": LEGACY_STATE[:-1] + ["other"], "candidate": WIDE["candidate"]}
    with pytest.raises(ValueError, match="drops"):
        widen_checkpoint(saved, narrower)


def test_header_contract(tmp_path):
    """The sidecar the Rust exporter writes: names when present, today's names when absent."""
    old = tmp_path / "old.f32"
    (tmp_path / "old.f32.schema.json").write_text(json.dumps({"errand_feature_version": 2, "candidates": 32}))
    assert read_header(old) == {"version": 2, "candidates": 32, **OLD}
    new = tmp_path / "new.f32"
    (tmp_path / "new.f32.schema.json").write_text(json.dumps({"errand_feature_version": 3, "candidates": 64, **WIDE}))
    assert read_header(new) == {"version": 3, "candidates": 64, **WIDE}
    # Candidate names without the kinds, with the kinds listed apart: appended in KIND order.
    split = tmp_path / "split.f32"
    (tmp_path / "split.f32.schema.json").write_text(json.dumps({"errand_feature_version": 3, "candidates": 64, "state": WIDE["state"],
                                                                 "candidate": LEGACY_CAND + NEW_CAND, "kinds": LEGACY_KINDS + NEW_KINDS}))
    assert read_header(split)["candidate"] == WIDE["candidate"]
    assert read_header(tmp_path / "none.f32") is None
    # A VecWorld names only CAND_NAMES: today's kinds complete it.
    assert resolve_names({"state": LEGACY_STATE, "candidate": LEGACY_CAND}, 12, 20) == OLD
    assert resolve_names({"state": [], "candidate": []}, 12, 20) == OLD
