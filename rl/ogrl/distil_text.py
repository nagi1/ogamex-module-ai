"""Distil one classifier question into a tiny in-process model (llm-classifier-everywhere.md, the distillation ladder).

Reads the JSONL `ai:export-text-readings` writes, trains a hashed bag-of-words logistic regression with numpy, and
writes the JSON `Modules\\AI\\Domain\\Language\\DistilledTextModel` evaluates in PHP. The feature hashing must match
the PHP side exactly: crc32 of each lowercased word and adjacent word pair, modulo the bucket count.

    python -m ogrl.distil_text storage/rl/text/inbound.jsonl act resources/models/text/inbound.act.json
"""

import argparse
import json
import re
import zlib

import numpy as np

WORD = re.compile(r"\w+", re.UNICODE)


def features(text: str, buckets: int) -> list[int]:
    words = WORD.findall(text.lower())
    tokens = words + [f"{a} {b}" for a, b in zip(words, words[1:])]
    return [zlib.crc32(token.encode("utf-8")) % buckets for token in tokens]


def label_of(answer: dict) -> tuple[str, str] | None:
    """The kind of the question and the label the classifier gave, from the stored answer's shape."""
    if "choice" in answer:
        return "choice", str(answer["choice"])
    if "probability" in answer:
        return "boolean", "true" if float(answer["probability"]) >= 0.5 else "false"
    if "score" in answer:
        return "score", str(int(round(float(answer["score"]))))
    return None


def train(rows: list[tuple[list[int], int]], labels: int, buckets: int, epochs: int, rate: float, l2: float):
    weights = np.zeros((buckets, labels))
    bias = np.zeros(labels)
    for _ in range(epochs):
        for bucket_ids, target in rows:
            logits = weights[bucket_ids].sum(axis=0) + bias
            probabilities = np.exp(logits - logits.max())
            probabilities /= probabilities.sum()
            gradient = probabilities
            gradient[target] -= 1.0
            np.add.at(weights, bucket_ids, -rate * (gradient + l2 * weights[bucket_ids]))
            bias -= rate * gradient
    return weights, bias


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("readings")
    parser.add_argument("question")
    parser.add_argument("out")
    parser.add_argument("--buckets", type=int, default=4096)
    parser.add_argument("--epochs", type=int, default=8)
    parser.add_argument("--rate", type=float, default=0.1)
    parser.add_argument("--l2", type=float, default=1e-4)
    parser.add_argument("--holdout", type=float, default=0.2)
    args = parser.parse_args()

    examples, kind = [], None
    with open(args.readings, encoding="utf-8") as handle:
        for line in handle:
            row = json.loads(line)
            answer = row["answers"].get(args.question)
            labelled = label_of(answer) if answer else None
            if labelled:
                kind = labelled[0]
                examples.append((row["text"], labelled[1]))

    labels = sorted({label for _, label in examples}, key=lambda value: (len(value), value))
    if kind == "score":
        labels = [str(level) for level in range(int(max(labels, key=int)) + 1)]
    index = {label: position for position, label in enumerate(labels)}
    rows = [(features(text, args.buckets), index[label]) for text, label in examples]
    rng = np.random.default_rng(7)
    rng.shuffle(rows)
    cut = int(len(rows) * (1 - args.holdout))
    weights, bias = train(rows[:cut], len(labels), args.buckets, args.epochs, args.rate, args.l2)

    held = rows[cut:]
    correct = sum(int(np.argmax(weights[ids].sum(axis=0) + bias) == target) for ids, target in held)
    accuracy = correct / max(1, len(held))
    print(f"{len(examples)} readings, {len(labels)} labels, held-out accuracy {accuracy:.3f}")

    sparse = {str(bucket): {str(label): round(float(value), 5) for label, value in enumerate(row) if abs(value) > 1e-4}
              for bucket, row in enumerate(weights) if np.abs(row).max() > 1e-4}
    with open(args.out, "w", encoding="utf-8") as handle:
        json.dump({"kind": kind, "examples": len(examples), "accuracy": round(accuracy, 4), "labels": labels, "buckets": args.buckets, "bias": [float(value) for value in bias], "weights": sparse}, handle)


if __name__ == "__main__":
    main()
