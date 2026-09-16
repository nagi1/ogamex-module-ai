"""OGame experience retrieval for the CBRKit driver.

The AI module owns the canonical cases, their owner scoping and the versioned
feature semantics. This retriever is the driver's own measure, not a port of the
module's uniform-mean formula: each feature has a weight and a kind, so a
native-versus-driver comparison can observe a difference instead of equivalence.

Feature kinds and weights are retrieval taste over the module's
``AiBuildingExperienceFeature`` values:

- ``object_id`` and ``planet_id`` are categorical identities. An outcome for one
  building or planet is not evidence about a neighbour, so they score 1 only on
  equality and 0 otherwise; a numeric id must never leak faint similarity to a
  neighbouring id.
- ``target_level`` is numeric: a closer level is stronger precedent.

The server is stateless: every request carries its own bounded, owner-scoped
casebase, which the module builds from its own records.
"""

from typing import Any

import cbrkit

# Categorical identity features: compared by equality only.
_IDENTITY_FEATURES = {"object_id", "planet_id"}

# Per-feature weights. Identity dominates (a different building is weak
# precedent); the level transfers across objects, so it carries weight too.
_FEATURE_WEIGHTS = {
    "object_id": 2.0,
    "target_level": 1.0,
    "planet_id": 0.5,
}

_UNKNOWN_FEATURE_SCORE = 0.0


def _identity_similarity(case_value: Any, query_value: Any) -> float:
    if case_value is None:
        return _UNKNOWN_FEATURE_SCORE

    return 1.0 if case_value == query_value else 0.0


def _numeric_similarity(case_value: Any, query_value: Any) -> float:
    if case_value is None:
        return _UNKNOWN_FEATURE_SCORE

    return max(
        0.0,
        1.0
        - abs(float(case_value) - float(query_value))
        / max(1.0, abs(float(case_value)), abs(float(query_value))),
    )


def feature_similarity(x: dict, y: dict) -> float:
    """Weighted similarity over the query's known features.

    CBRKit passes the case as ``x`` and the query as ``y``. A feature the query
    does not state (``None`` or absent) is not compared, and an unknown case
    value still counts toward the weighted denominator and scores zero, so
    missing case evidence lowers the score the way the module's contract
    expects.
    """

    known = [key for key, value in y.items() if key in x and value is not None]

    if not known:
        return 0.0

    weighted = 0.0
    total_weight = 0.0

    for key in known:
        weight = _FEATURE_WEIGHTS.get(key, 1.0)
        score = (
            _identity_similarity(x[key], y[key])
            if key in _IDENTITY_FEATURES
            else _numeric_similarity(x[key], y[key])
        )
        weighted += weight * score
        total_weight += weight

    return weighted / total_weight if total_weight else 0.0


# No dropout limit on purpose: the module decides how much evidence a ranking may
# consider, and it rejects a response that omits any case it sent. A truncating
# driver would therefore look like a contract violation instead of a bound.
ogame_experience_retriever = cbrkit.retrieval.build(feature_similarity)
