"""The judge's one rule (CONSULTANT-GUIDE Rule 8): a paired difference prints NOISE and no number when its 95% bootstrap
CI crosses zero or its size is under the minimum detectable effect, MDE = 2.8 x SE (80% power at alpha 0.05,
NORTH-STAR-PLAN statistical power row). Only an effect that clears both prints its number.
"""

import numpy as np


def judge(diffs, unit: str = "") -> str:
    """One line for paired per-seed differences B - A: the number with CI and MDE, or NOISE with the reason."""
    d = np.asarray(diffs, dtype=float)
    n = len(d)
    if n < 2:
        return f"NOISE (n={n} paired seeds)"
    boot = np.random.default_rng(0).choice(d, (10000, n)).mean(1)
    lo, hi = np.quantile(boot, 0.025), np.quantile(boot, 0.975)
    mde = 2.8 * d.std(ddof=1) / np.sqrt(n)
    mean = d.mean()
    if lo <= 0 <= hi:
        return f"NOISE (CI crosses 0, n={n})"
    if abs(mean) < mde:
        return f"NOISE (effect under the MDE, n={n})"
    return f"B - A {mean:+.4g}{unit}, up {int((d > 0).sum())}/{n} seeds, 95% CI [{lo:+.4g}, {hi:+.4g}], MDE {mde:.3g}"
