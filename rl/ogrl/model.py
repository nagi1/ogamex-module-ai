"""Candidate scorer: one shared network scores every (state, candidate) pair; a softmax over the legal rows
is the policy. No object identity is an input, so a modded object is scored from its host-derived numbers
(plan/rl/state-and-action-space.md). Feature normalisation is stored inside the model, so the exported
ONNX file needs nothing else."""

from __future__ import annotations

import torch
from torch import nn


class CandidateScorer(nn.Module):
    def __init__(self, state_dim: int, cand_dim: int, width: int = 256):
        super().__init__()
        self.register_buffer("state_mean", torch.zeros(state_dim))
        self.register_buffer("state_std", torch.ones(state_dim))
        self.register_buffer("cand_mean", torch.zeros(cand_dim))
        self.register_buffer("cand_std", torch.ones(cand_dim))
        self.state_net = nn.Sequential(nn.Linear(state_dim, width), nn.ReLU(), nn.Linear(width, width), nn.ReLU())
        self.cand_net = nn.Sequential(nn.Linear(cand_dim, width // 2), nn.ReLU())
        self.joint = nn.Sequential(nn.Linear(width + width // 2, width), nn.ReLU(), nn.Linear(width, width // 2), nn.ReLU(), nn.Linear(width // 2, 1))
        # Not trained by behaviour cloning; kept so PPO can start from the same network (plan/rl phase 4).
        self.value = nn.Sequential(nn.Linear(width, width), nn.ReLU(), nn.Linear(width, 1))

    def set_normalisation(self, state_mean, state_std, cand_mean, cand_std) -> None:
        self.state_mean.copy_(torch.as_tensor(state_mean))
        self.state_std.copy_(torch.as_tensor(state_std).clamp_min(1e-3))
        self.cand_mean.copy_(torch.as_tensor(cand_mean))
        self.cand_std.copy_(torch.as_tensor(cand_std).clamp_min(1e-3))

    def forward(self, state: torch.Tensor, cands: torch.Tensor, mask: torch.Tensor) -> tuple[torch.Tensor, torch.Tensor]:
        """state [B, S], cands [B, K, C], mask [B, K] (True = may be chosen) -> masked logits [B, K], value [B]."""
        s = self.state_net((state - self.state_mean) / self.state_std)
        c = self.cand_net((cands - self.cand_mean) / self.cand_std)
        joint = torch.cat([s.unsqueeze(1).expand(-1, c.shape[1], -1), c], dim=-1)
        logits = self.joint(joint).squeeze(-1)
        logits = logits.masked_fill(~mask, torch.finfo(logits.dtype).min)
        return logits, self.value(s).squeeze(-1)


def parameter_count(model: nn.Module) -> int:
    return sum(p.numel() for p in model.parameters())
