"""Noise floor of the errand look-ahead: the same label-mode season run with 2 and with 4 rollouts passes the same
decisions (labels do not move the world) and shares rollouts 1-2, so rollouts 3-4 = 2 x worth4 - worth2 is an independent
estimate. Reports how often the two halves agree on the best row and how far apart their gaps are.

    python scripts/errand_noise.py R2.f32 R4.f32
"""
import sys
from pathlib import Path

import numpy as np

sys.path.insert(0, str(Path(__file__).resolve().parent))
from errand_labels import load  # noqa: E402

s2, c2, l2, w2, _ = load([sys.argv[1]])
s4, c4, l4, w4, _ = load([sys.argv[2]])
n = min(len(s2), len(s4))
same = np.all(s2[:n] == s4[:n], 1) & np.all(c2[:n] == c4[:n], (1, 2)) & np.all(l2[:n] == l4[:n], 1)
first_diff = int(np.argmin(same)) if not same.all() else n
print(f"decisions r2 {len(s2):,}, r4 {len(s4):,}; identical prefix {first_diff:,}")
n = first_diff
legal = l2[:n]
keep = legal.sum(1) >= 2
a, b, legal = w2[:n][keep], (2 * w4[:n] - w2[:n])[keep], legal[keep]
rel = lambda w: (np.where(legal, w, np.nan) - np.nanmax(np.where(legal, w, np.nan), 1, keepdims=True)) / np.maximum(np.abs(np.nanmax(np.where(legal, w, np.nan), 1, keepdims=True)), 1.0)
ra, rb = rel(a), rel(b)
pa, pb = np.nanargmax(ra, 1), np.nanargmax(rb, 1)
gap_a = -np.sort(np.where(np.isnan(ra), -np.inf, ra), 1)[:, -2]
print(f"multi-row decisions {len(a):,}: halves agree on the best row {np.mean(pa == pb):.1%}")
# How much half B says half A's pick gives up: the regret a label built from 2 rollouts carries from noise alone.
lost = -rb[np.arange(len(pa)), pa]
for lo, hi in ((0, 1e-5), (1e-5, 1e-4), (1e-4, 1e-3), (1e-3, 1)):
    m = (gap_a >= lo) & (gap_a < hi)
    if m.any():
        print(f"  half-A gap {lo:.0e}-{hi:.0e}: n {m.sum():6,}, B agrees {np.mean(pa[m] == pb[m]):.1%}, A's pick loses in B {np.mean(lost[m]):.2e}")
d = (ra - rb)[legal]
print(f"row-wise |A - B| share of worth: p50 {np.nanmedian(np.abs(d)):.2e}, p90 {np.nanquantile(np.abs(d), 0.9):.2e}")
