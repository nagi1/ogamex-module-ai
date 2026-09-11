"""OGame experience retrieval for the CBRKit driver.

The AI module owns the canonical experience cases and the versioned feature
semantics. This retriever deliberately reproduces the module's similarity
formula instead of choosing a different built-in metric, so a comparison against
the native engine is an implementation swap rather than a metric change.

The server is stateless: every request carries its own bounded, owner-scoped
casebase, which the module builds from its own records.
"""

from typing import Any

import cbrkit

# Features compare only where the query knows a value, mirroring the module's
# intersect-and-average rule. An unknown case value still counts toward the
# denominator and scores zero, so missing case evidence lowers the score.
_UNKNOWN_FEATURE_SCORE = 0.0


def _numeric_similarity(case_value: float, query_value: float) -> float:
    """Normalized distance shared by the module's native numeric features."""

    return max(
        0.0,
        1.0
        - abs(case_value - query_value)
        / max(1.0, abs(case_value), abs(query_value)),
    )


def _feature_similarity(case_value: Any, query_value: Any) -> float:
    if case_value is None:
        return _UNKNOWN_FEATURE_SCORE

    if isinstance(case_value, str) or isinstance(query_value, str):
        return 1.0 if case_value == query_value else 0.0

    return _numeric_similarity(float(case_value), float(query_value))


def feature_similarity(x: dict, y: dict) -> float:
    """Global similarity over the query's known features.

    CBRKit passes the case as ``x`` and the query as ``y``.
    """

    known = [key for key, value in y.items() if key in x and value is not None]

    if not known:
        return 0.0

    return sum(_feature_similarity(x[key], y[key]) for key in known) / len(known)


# No dropout limit on purpose: the module decides how much evidence a ranking may
# consider, and it rejects a response that omits any case it sent. A truncating
# driver would therefore look like a contract violation instead of a bound.
ogame_experience_retriever = cbrkit.retrieval.build(feature_similarity)
