"""Recorded economy choice points (ai:sim --record-choices) as padded arrays.

Each JSON line is one choice: `state` (account + planet numbers), `cands` (one feature row per candidate,
row 0 = wait), `legal`, `teacher` (the planner's pick), `chosen` (what was played), plus metadata. A
`<file>.schema.json` sidecar names the features; every file of one dataset must share the schema.
"""

from __future__ import annotations

import glob
import hashlib
import json
from dataclasses import dataclass, field
from pathlib import Path

import numpy as np

from . import MAX_CANDIDATES


@dataclass
class Schema:
    version: int
    state: list[str]
    candidate: list[str]

    @classmethod
    def read(cls, data_file: str | Path) -> "Schema":
        raw = json.loads(Path(f"{data_file}.schema.json").read_text())
        return cls(int(raw["version"]), list(raw["state"]), list(raw["candidate"]))

    def to_json(self) -> dict:
        return {"version": self.version, "state": self.state, "candidate": self.candidate}


@dataclass
class Dataset:
    schema: Schema
    state: np.ndarray          # [N, S] float32
    cands: np.ndarray          # [N, K, C] float32 (zero rows beyond the candidate count)
    mask: np.ndarray           # [N, K] bool: a row the host would accept
    present: np.ndarray        # [N, K] bool: a row that exists
    teacher: np.ndarray        # [N] int64
    chosen: np.ndarray         # [N] int64
    group: np.ndarray          # [N] int64: universe id (seed + source file), for splitting
    meta: dict[str, np.ndarray] = field(default_factory=dict)

    def __len__(self) -> int:
        return int(self.state.shape[0])

    def subset(self, index: np.ndarray) -> "Dataset":
        return Dataset(
            self.schema, self.state[index], self.cands[index], self.mask[index], self.present[index],
            self.teacher[index], self.chosen[index], self.group[index],
            {key: value[index] for key, value in self.meta.items()},
        )


def load(pattern: str | list[str], min_legal: int = 2, only_teacher_label: bool = True) -> Dataset:
    """Read every file matching the pattern(s). Keeps choices with at least `min_legal` legal rows (a choice
    with one legal row teaches nothing) and, by default, only rows whose label is the planner's own pick."""
    files = sorted({f for p in ([pattern] if isinstance(pattern, str) else pattern) for f in glob.glob(p)})
    files = [f for f in files if not f.endswith(".schema.json")]
    if not files:
        raise FileNotFoundError(f"no recorded choices match {pattern}")

    schema = Schema.read(files[0])
    S, C, K = len(schema.state), len(schema.candidate), MAX_CANDIDATES
    states, cands, masks, presents, teachers, chosens, groups = [], [], [], [], [], [], []
    meta: dict[str, list] = {k: [] for k in ("kind", "archetype", "n_legal", "phase", "player", "t", "value", "policy", "learner", "seed")}
    phase_names = [n for n in schema.state if n.startswith("phase_")]
    phase_at = [schema.state.index(n) for n in phase_names]

    for path in files:
        other = Schema.read(path)
        if other.to_json() != schema.to_json():
            raise ValueError(f"{path} has a different feature schema (v{other.version}); record with one encoder version")
        with open(path) as handle:
            for line in handle:
                if not line.strip():
                    continue
                row = json.loads(line)
                n = len(row["cands"])
                legal = row["legal"][:K]
                if sum(legal) < min_legal:
                    continue
                if only_teacher_label and row["teacher"] >= K:
                    continue
                c = np.zeros((K, C), dtype=np.float32)
                c[: min(n, K)] = np.asarray(row["cands"][:K], dtype=np.float32)
                m = np.zeros(K, dtype=bool)
                m[: len(legal)] = legal
                p = np.zeros(K, dtype=bool)
                p[: min(n, K)] = True
                st = np.asarray(row["state"], dtype=np.float32)
                if st.shape[0] != S or c.shape[1] != C:
                    raise ValueError(f"{path}: row dims do not match the schema")
                states.append(st)
                cands.append(c)
                masks.append(m)
                presents.append(p)
                teachers.append(int(row["teacher"]))
                chosens.append(int(row["chosen"]))
                groups.append(_group_id(row.get("seed", 0), path))
                meta["kind"].append(row["kind"])
                meta["archetype"].append(row["archetype"])
                meta["n_legal"].append(int(sum(legal)))
                meta["phase"].append(phase_names[int(np.argmax(st[phase_at]))].removeprefix("phase_") if phase_at else "unknown")
                meta["player"].append(int(row["player"]))
                meta["t"].append(int(row["t"]))
                meta["value"].append(float(row["value"]))
                meta["policy"].append(row["policy"])
                meta["learner"].append(bool(row["learner"]))
                meta["seed"].append(int(row.get("seed", 0)))

    if not states:
        raise ValueError("no usable choice points (all filtered out)")

    return Dataset(
        schema,
        np.stack(states), np.stack(cands), np.stack(masks), np.stack(presents),
        np.asarray(teachers, dtype=np.int64), np.asarray(chosens, dtype=np.int64), np.asarray(groups, dtype=np.int64),
        {k: np.asarray(v) for k, v in meta.items()},
    )


def split_by_universe(data: Dataset, validation: float = 0.2, salt: str = "ogrl") -> tuple[Dataset, Dataset]:
    """Hold out whole universes, so validation measures states from universes the model never saw."""
    groups = np.unique(data.group)
    held = {g for g in groups if int(hashlib.sha256(f"{salt}:{g}".encode()).hexdigest(), 16) % 1000 < validation * 1000}
    if not held or len(held) == len(groups):
        held = set(groups[: max(1, int(len(groups) * validation))])
    is_val = np.isin(data.group, list(held))
    return data.subset(np.where(~is_val)[0]), data.subset(np.where(is_val)[0])


def _group_id(seed: int, path: str) -> int:
    return int(hashlib.sha256(f"{seed}:{Path(path).name}".encode()).hexdigest()[:12], 16)
