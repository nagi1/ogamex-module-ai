"""Held-out retrieval comparison for the CBRKit driver.

Measures whether the driver's own per-feature weighted measure beats the module's
uniform-mean formula on held-out building outcomes — the 5D Gate 2 verdict. Both
runs go through cbrkit's retrieval machinery; the top-k metrics are computed here
in plain Python because `cbrkit.eval`'s metric functions require the optional
`ranx` package, which this image deliberately does not install.

Run inside the cbrkit container (or anywhere with `cbrkit` and this directory on
the path):

    python /driver/eval_retriever.py

The fixture is synthetic but principled: for the building-upgrade family the
object is a categorical identity and the level is numeric, so an outcome for one
building is not evidence about a neighbour. The uniform-mean baseline scores a
numeric object id by distance, so it can rank a different building above the same
building at a distant level; the weighted measure cannot.
"""

import math
from typing import Any

import cbrkit

import retriever  # the driver's own weighted measure

# Held-out building-upgrade cases: {case_id: {object_id, target_level}}.
_CASEBASE: dict[int, dict[str, int]] = {
    1: {"object_id": 2, "target_level": 6},
    2: {"object_id": 2, "target_level": 20},
    3: {"object_id": 3, "target_level": 6},
    4: {"object_id": 9, "target_level": 6},
    5: {"object_id": 5, "target_level": 10},
    6: {"object_id": 5, "target_level": 11},
    7: {"object_id": 8, "target_level": 10},
    8: {"object_id": 7, "target_level": 12},
}

# One query per held-out decision; a case is relevant when it is an outcome for
# the same object, because only a same-building outcome is evidence about it.
_QUERIES: dict[str, dict[str, int]] = {
    "build_object_2_level_6": {"object_id": 2, "target_level": 6},
    "build_object_5_level_10": {"object_id": 5, "target_level": 10},
}


def _qrels(query: dict[str, int]) -> dict[int, int]:
    return {
        case_id: (1 if case["object_id"] == query["object_id"] else 0)
        for case_id, case in _CASEBASE.items()
    }


def _native_uniform(x: dict[str, Any], y: dict[str, Any]) -> float:
    """The module's uniform-mean formula, reproduced here only as the measured baseline."""

    known = [key for key, value in y.items() if key in x and value is not None]

    if not known:
        return 0.0

    def feature(case_value: Any, query_value: Any) -> float:
        if case_value is None:
            return 0.0

        if isinstance(case_value, str) or isinstance(query_value, str):
            return 1.0 if case_value == query_value else 0.0

        return max(
            0.0,
            1.0 - abs(float(case_value) - float(query_value))
            / max(1.0, abs(float(case_value)), abs(float(query_value))),
        )

    return sum(feature(x[key], y[key]) for key in known) / len(known)


def _ranking(similarities: dict[object, float]) -> list[int]:
    return [
        int(case_id)
        for case_id, _ in sorted(
            similarities.items(), key=lambda item: (-float(item[1]), int(item[0]))
        )
    ]


def _precision_at_k(qrels: dict[int, int], ranking: list[int], k: int) -> float:
    return sum(qrels.get(case, 0) for case in ranking[:k]) / k


def _ndcg_at_k(qrels: dict[int, int], ranking: list[int], k: int) -> float:
    dcg = sum(
        (2 ** qrels.get(case, 0) - 1) / math.log2(position + 2)
        for position, case in enumerate(ranking[:k])
    )
    ideal = sorted(qrels.values(), reverse=True)[:k]
    idcg = sum(
        (2 ** relevance - 1) / math.log2(position + 2)
        for position, relevance in enumerate(ideal)
    )

    return dcg / idcg if idcg else 0.0


def _measure(similarity) -> dict[str, float]:
    retrievers = cbrkit.retrieval.build(similarity)
    result = cbrkit.retrieval.apply_queries(_CASEBASE, _QUERIES, retrievers)
    metrics: dict[str, list[float]] = {"precision@2": [], "ndcg@2": [], "precision@4": []}

    for step in result.steps:
        for query_key, query_result in step.queries.items():
            qrels = _qrels(_QUERIES[query_key])
            ranking = _ranking(query_result.similarities)
            metrics["precision@2"].append(_precision_at_k(qrels, ranking, 2))
            metrics["ndcg@2"].append(_ndcg_at_k(qrels, ranking, 2))
            metrics["precision@4"].append(_precision_at_k(qrels, ranking, 4))

    return {name: sum(values) / len(values) for name, values in metrics.items()}


def main() -> None:
    driver = _measure(retriever.feature_similarity)
    native = _measure(_native_uniform)

    print("metric        driver  native  delta")
    print("------------  ------  ------  -----")

    for metric in sorted(driver):
        delta = driver[metric] - native[metric]
        print(f"{metric:<12}  {driver[metric]:.4f}  {native[metric]:.4f}  {delta:+.4f}")

    gain = driver["precision@2"] - native["precision@2"]
    print()
    print(f"precision@2 gain over native: {gain * 100:.1f}pp (Gate 2 target: >= 5pp)")
    print(
        "note: this fixture is synthetic; the hybrid default is granted only on the same "
        "measurement over held-out real outcomes, never on this script alone."
    )


if __name__ == "__main__":
    main()
