"""CBRKit's served API plus the additive social route ``POST /social/similar``.

The app is ``cbrkit.api.app`` itself (``/retrieve``, ``/reuse``, ``/cycle``, ``/synthesize`` answer exactly as before);
this module only adds one route to it.

``/social/similar`` retrieves the most similar past diplomatic decisions from a social case base built from the
simulator's diplomacy logs (``Modules/AI/rl/scripts/social_cases.py``). A case is the situation before a club's
decision (public record plus the pair's own history), the decision, and what happened after it (``outcome``, with
the pair's ``future`` events over the horizon). Batch body:

    {"items": [{"situation": {...}, "k": 5, "casebase": {...optional...}}]}
    -> {"results": [{"cases": [{"id", "similarity", "outcome"}]}]}

As with ``/retrieve``, a request may carry its own ``casebase`` ({id: {situation, outcome}}); without one the case
base baked into the image (``social_cases.json``) is used. Similarity is CBRKit's own: ``attribute_value`` over the
features the query states, ``generic.equality`` for the decision and its cause, ``numbers.linear`` over each
numeric feature's range in the case base, and ``collections.sequence_mapping`` for the ordered relationship
history, pooled by ``aggregator`` with weights; ranking and the top-k cut are ``retrieval.build`` +
``retrieval.dropout``.
"""

import json
import os
from functools import lru_cache
from pathlib import Path
from typing import Any

from pydantic import BaseModel, Field

import cbrkit
from cbrkit.api import app

CASES_PATH = Path(os.environ.get("SOCIAL_CASES", Path(__file__).with_name("social_cases.json")))
CATEGORICAL = ("decision", "cause")
SEQUENCES = ("history",)
WEIGHTS = {"decision": 4.0, "cause": 1.0, "history": 2.0}
MAX_K = 50


@lru_cache(maxsize=1)
def baked_cases() -> dict[str, dict[str, Any]]:
    if not CASES_PATH.exists():
        return {}
    return json.loads(CASES_PATH.read_text())["cases"]


def numeric_spans(situations: dict[str, dict[str, Any]]) -> dict[str, float]:
    lo: dict[str, float] = {}
    hi: dict[str, float] = {}
    for situation in situations.values():
        for key, value in situation.items():
            if isinstance(value, (int, float)) and not isinstance(value, bool):
                lo[key] = min(lo.get(key, value), value)
                hi[key] = max(hi.get(key, value), value)
    return {key: max(1.0, hi[key] - lo[key]) for key in lo}


def similarity_for(query: dict[str, Any], spans: dict[str, float]):
    """CBRKit attribute-value similarity over the features this query states."""
    attributes = {}
    for key, value in query.items():
        if value is None:
            continue
        if key in CATEGORICAL:
            attributes[key] = cbrkit.sim.generic.equality()
        elif key in SEQUENCES:
            attributes[key] = cbrkit.sim.collections.sequence_mapping(cbrkit.sim.generic.equality(), exact=True)
        elif key in spans:
            attributes[key] = cbrkit.sim.numbers.linear(max=spans[key])
    if not attributes:
        raise ValueError("situation states no known feature")
    return cbrkit.sim.attribute_value(
        attributes=attributes,
        aggregator=cbrkit.sim.aggregator(pooling="mean", pooling_weights=WEIGHTS),
        default=0.0,
    )


class SimilarItem(BaseModel):
    situation: dict[str, Any]
    k: int = 5
    casebase: dict[str, dict[str, Any]] | None = None


class SimilarRequest(BaseModel):
    items: list[dict[str, Any]] = Field(default_factory=list)


def similar(item: dict[str, Any]) -> dict[str, Any]:
    try:
        parsed = SimilarItem.model_validate(item)
        cases = parsed.casebase if parsed.casebase is not None else baked_cases()
        situations = {str(key): case["situation"] for key, case in cases.items()}
        query = dict(parsed.situation)
        if "history" in query and situations:
            # The relationship history is compared position by position over the case base's length.
            length = len(next(iter(situations.values())).get("history", []))
            history = [str(x) for x in query["history"]][-length:]
            query["history"] = ["none"] * (length - len(history)) + history
        k = max(1, min(MAX_K, parsed.k))
        retriever = cbrkit.retrieval.dropout(
            cbrkit.retrieval.build(similarity_for(query, numeric_spans(situations))), limit=k)
        result = cbrkit.retrieval.apply_query(situations, query, retriever)
        ranked = result.ranking
        sims = result.similarities
        return {"cases": [{"id": key, "similarity": round(float(getattr(sims[key], "value", sims[key])), 4),
                           "outcome": cases[key]["outcome"]} for key in ranked]}
    except Exception as exc:  # one bad item answers an error, the batch still answers
        return {"error": str(exc)}


@app.post("/social/similar")
def social_similar(request: SimilarRequest) -> dict[str, Any]:
    return {"results": [similar(item) for item in request.items]}
