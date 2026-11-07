"""The errand model's feature header, and widening a trained errand model to a wider header by column name.

The simulator names every column it writes (`ogsim/src/errands.rs`): the state row is `STATE_NAMES`, a candidate row is
`CAND_NAMES` then one one-hot column per `KIND_NAMES` entry. A wider simulator appends new names at the end of each of
those three lists, so in the flat candidate row new candidate columns land *between* the old candidate columns and the
kind one-hots: weights must be mapped by name, never by position.

The header a dataset carries (the `<labels>.schema.json` sidecar of `champion-errand-out=`, a live world's
`dims()`/`feature_names()`, or the `names` of a checkpoint) is read here; when it carries no names, today's fixed names
are assumed, so old datasets and checkpoints keep training exactly as before.

Widening keeps every old column's weights and normalisation, and gives each new column a zero first-layer weight with
mean 0 / std 1: the widened model scores any candidate exactly as the old one did, whatever the new columns hold.
"""

from __future__ import annotations

import json
from pathlib import Path

import torch

# Feature version 2, as `errands.rs` defines it before the `wide` arm.
LEGACY_VERSION = 2
LEGACY_CANDIDATES = 32
LEGACY_STATE = [
    "stock", "stock_ratio", "probe", "cargo", "fighter", "harvester", "hull_home",
    "hour_sin", "hour_cos", "hours_since_attack", "incoming_hours", "idle",
]
LEGACY_CAND = [
    "distance", "flight_hours", "fuel_over_stock", "expected_loot", "loot_over_fuel",
    "target_ratio_observed_or_estimated", "report_age_hours", "target_inactive", "field", "field_over_capacity",
    "target_stock", "attack_in_hours", "order_share",
]
LEGACY_KINDS = ["spy", "raid", "recycle", "save", "recall", "order", "wait"]
LEGACY_CANDIDATE = LEGACY_CAND + LEGACY_KINDS

# The layers that read the raw (normalised) inputs; everything after them is width-only and copies unchanged.
STATE_INPUT, CAND_INPUT = "state_net.0.weight", "cand_net.0.weight"
VERSION_KEY, NAMES_KEY = "_errand_feature_version", "_names"


def resolve_names(names: dict | None, s_dim: int, c_dim: int) -> dict:
    """Full column names for a (state, candidate) width. Empty or missing names are today's when the widths are today's
    (checkpoints from before the season world named its columns); a candidate list without the kind one-hots (VecWorld
    names only `CAND_NAMES`) gets `kinds` (or today's kinds) appended."""
    names = names or {}
    state, cand = list(names.get("state") or []), list(names.get("candidate") or [])
    kinds = list(names.get("kinds") or (LEGACY_KINDS if cand[: len(LEGACY_CAND)] == LEGACY_CAND else []))
    if not state and s_dim == len(LEGACY_STATE):
        state = list(LEGACY_STATE)
    if not cand and c_dim == len(LEGACY_CANDIDATE):
        cand = list(LEGACY_CANDIDATE)
    if len(cand) != c_dim and kinds and len(cand) + len(kinds) == c_dim and not set(kinds) & set(cand):
        cand = cand + kinds
    if len(state) != s_dim or len(cand) != c_dim:
        raise ValueError(f"feature names ({len(state)} state, {len(cand)} candidate) do not match the widths {s_dim} x {c_dim}")
    for what, cols in (("state", state), ("candidate", cand)):
        if len(set(cols)) != len(cols):
            raise ValueError(f"duplicate {what} column names: a column must be named once to be mapped by name")
    return {"state": state, "candidate": cand}


def is_legacy(names: dict) -> bool:
    return names.get("state") == LEGACY_STATE and names.get("candidate") == LEGACY_CANDIDATE


def infer_version(names: dict, given: int | None = None) -> int:
    """The feature version of data with these names: the header's if it said, today's (2) for today's names, else 3."""
    if given is not None:
        return int(given)
    return LEGACY_VERSION if is_legacy(names) else LEGACY_VERSION + 1


def read_header(data_file: str | Path) -> dict | None:
    """The `<file>.schema.json` sidecar as {version, candidates, state, candidate}, or None when there is none. A sidecar
    without names (written before the `wide` arm) is today's names."""
    sidecar = Path(f"{data_file}.schema.json")
    if not sidecar.is_file():
        return None
    raw = json.loads(sidecar.read_text())
    header = {"version": raw.get("errand_feature_version"), "candidates": raw.get("candidates")}
    if raw.get("state") or raw.get("candidate"):
        s_dim, c_dim = len(raw.get("state") or []), len(raw.get("candidate") or []) + (
            len(raw.get("kinds") or []) if raw.get("kinds") and not set(raw["kinds"]) & set(raw.get("candidate") or []) else 0)
        header.update(resolve_names(raw, s_dim, c_dim))
    else:
        header.update({"state": list(LEGACY_STATE), "candidate": list(LEGACY_CANDIDATE)})
    return header


def widen_model(old: dict[str, torch.Tensor], old_names: dict, new_names: dict) -> dict[str, torch.Tensor]:
    """A state dict for `new_names` from one trained on `old_names`: every old column's normalisation and first-layer
    weights copy to its new position, new columns get weight 0 and mean 0 / std 1, the rest copies unchanged. Every old
    column must still exist (a dropped column would change old scores)."""
    out = {k: v.clone() for k, v in old.items()}
    for what, input_layer, mean, std in (("state", STATE_INPUT, "state_mean", "state_std"), ("candidate", CAND_INPUT, "cand_mean", "cand_std")):
        before, after = old_names[what], new_names[what]
        missing = [n for n in before if n not in after]
        if missing:
            raise ValueError(f"the wider {what} header drops {missing}; widening only appends columns")
        at = torch.as_tensor([after.index(n) for n in before], dtype=torch.long)
        weight = old[input_layer]
        out[input_layer] = weight.new_zeros(weight.shape[0], len(after))
        out[input_layer][:, at] = weight
        out[mean] = old[mean].new_zeros(len(after))
        out[mean][at] = old[mean]
        out[std] = old[std].new_ones(len(after))
        out[std][at] = old[std]
    return out


def new_columns(old_names: dict, new_names: dict) -> dict[str, list[int]]:
    """Positions in `new_names` of the columns `old_names` lacks."""
    return {w: [i for i, n in enumerate(new_names[w]) if n not in old_names[w]] for w in ("state", "candidate")}


def load_errand(path: str | Path) -> dict:
    """An errand model as a checkpoint dict (model, state_dim, cand_dim, width, names, errand_feature_version), from a
    torch checkpoint or from an exported policy json. Missing names are today's only when the weights carry the
    version-2 stamp (an exported policy); an unnamed, unstamped model is refused, as `export_policy` refuses it."""
    path = Path(path)
    if path.suffix == ".json":
        raw = json.loads(path.read_text())
        model = {k: torch.as_tensor(v, dtype=torch.float32) for k, v in raw.items() if not k.startswith("_")}
        saved = {"model": model, "queue": 2, "iteration": 0, "names": raw.get(NAMES_KEY) or {}}
        version = raw.get(VERSION_KEY)
    else:
        saved = dict(torch.load(path, map_location="cpu", weights_only=False))
        version = saved.get("errand_feature_version")
    named = saved.get("names") or {}
    if not (named.get("state") or named.get("candidate")) and version != LEGACY_VERSION:
        # Before version 2 checkpoints carried no names, and their threat column read hidden enemy research.
        raise ValueError(f"{path}: errand model has no feature names and no version-{LEGACY_VERSION} stamp; retrain it")
    model = saved["model"]
    s_dim, c_dim, width = model[STATE_INPUT].shape[1], model[CAND_INPUT].shape[1], model[STATE_INPUT].shape[0]
    saved.update(state_dim=s_dim, cand_dim=c_dim, width=width, names=resolve_names(saved.get("names"), s_dim, c_dim))
    saved["errand_feature_version"] = infer_version(saved["names"], version)
    return saved


def widen_checkpoint(saved: dict, new_names: dict, version: int | None = None) -> dict:
    """`saved` (as `load_errand` returns it) widened to `new_names`; identity when the names already match."""
    old_names = resolve_names(saved.get("names"), saved["state_dim"], saved["cand_dim"])
    model = saved["model"] if old_names == new_names else widen_model(saved["model"], old_names, new_names)
    return {**saved, "model": model, "names": new_names, "state_dim": len(new_names["state"]), "cand_dim": len(new_names["candidate"]),
            "errand_feature_version": infer_version(new_names, version if version is not None else (saved.get("errand_feature_version") if old_names == new_names else None))}


def policy_json(saved: dict) -> dict:
    """The weights as `season::Policy::load_errand` reads them, stamped with the feature version and the column names."""
    weights = {name: tensor.tolist() for name, tensor in saved["model"].items()}
    names = resolve_names(saved.get("names"), saved["state_dim"], saved["cand_dim"])
    weights[VERSION_KEY] = infer_version(names, saved.get("errand_feature_version"))
    weights[NAMES_KEY] = names
    return weights
