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
- Any other feature is numeric too, except a text value, which is compared by
  equality like an identity. The module's own formula draws the line in the same
  place, and the driver's measure is only meaningful if it agrees with it.

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


def _feature_similarity(key: str, case_value: Any, query_value: Any) -> float:
    """Similarity for one feature both the case and the query state.

    Identity and text are compared by equality: a different building is not a
    neighbour of the one asked about, and neither is a different resource, so an
    id must never leak faint similarity to the next id and a word has no distance
    at all. This is also the module's own rule, and the driver's measure has to
    agree with it or the comparison the driver exists for measures nothing.
    """

    if case_value is None:
        return _UNKNOWN_FEATURE_SCORE

    if (
        key in _IDENTITY_FEATURES
        or isinstance(case_value, str)
        or isinstance(query_value, str)
    ):
        return 1.0 if case_value == query_value else 0.0

    try:
        case_number = float(case_value)
        query_number = float(query_value)
    except (TypeError, ValueError):
        # A value with no order (a nested object, say) is not comparable. Scoring
        # it zero lowers one case's evidence rather than failing the whole
        # retrieval, which the module would then have to answer natively.
        return _UNKNOWN_FEATURE_SCORE

    return max(
        0.0,
        1.0
        - abs(case_number - query_number)
        / max(1.0, abs(case_number), abs(query_number)),
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
        weighted += weight * _feature_similarity(key, x[key], y[key])
        total_weight += weight

    return weighted / total_weight if total_weight else 0.0


# No dropout limit on purpose: the module decides how much evidence a ranking may
# consider, and it rejects a response that omits any case it sent. A truncating
# driver would therefore look like a contract violation instead of a bound.
ogame_experience_retriever = cbrkit.retrieval.build(feature_similarity)


if __name__ == "__main__":
    # The measure's own check. Run it inside the driver image:
    #   docker compose -f Modules/AI/docker/cognition/docker-compose.yml \
    #     run --rm --entrypoint python cbrkit /driver/retriever.py
    assert _feature_similarity("object_id", 2, 2) == 1.0
    assert _feature_similarity("object_id", 3, 2) == 0.0
    assert _feature_similarity("target_level", 6, 6) == 1.0
    assert abs(_feature_similarity("target_level", 5, 6) - (1 - 1 / 6)) < 1e-12
    # A text value is categorical. This is the case that used to raise and answer 500.
    assert _feature_similarity("resource", "crystal", "crystal") == 1.0
    assert _feature_similarity("resource", "metal", "crystal") == 0.0
    assert _feature_similarity("resource", "crystal", 1) == 0.0
    assert _feature_similarity("target_level", None, 6) == 0.0
    # A value with no order is not comparable, and scores zero rather than raising.
    assert _feature_similarity("meta", {"a": 1}, {"a": 1}) == 0.0
    # The whole-case measure still ties two objects that are numerically near.
    assert feature_similarity(
        {"object_id": 3, "target_level": 6}, {"object_id": 2, "target_level": 6}
    ) == feature_similarity(
        {"object_id": 9, "target_level": 6}, {"object_id": 2, "target_level": 6}
    )
    print("retriever self-check passed")
