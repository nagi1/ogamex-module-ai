"""Real OGame player talk (forum posts, Reddit comments) set against what our accounts say.

    python3 Modules/AI/rl/scripts/real_vs_ours.py [--out storage/rl/social/real-lines.jsonl]

Writes the cleaned short English messages (the language data the composer and the classifier can learn from) and prints:
the share of ours a word-count classifier can tell from real (the lower the better: 0.5 is "cannot tell"), the words
real players use that ours never do, and the words ours use far more than real players (the data-driven slop list).
Real text is held out by thread, ours by world, so the number is not a memorised-line score.
"""

import argparse
import collections
import json
import math
import random
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[4]
SOURCES = ROOT / "storage/rl/external/sources"
OURS = ROOT / "storage/rl/social/corpus.jsonl"
ENGLISH_HOSTS = ("board_en_", "board_us_")
WORD = re.compile(r"[a-z][a-z'!?]{1,}")
QUOTE = re.compile(r"(?is)\[quote.*?\[/quote\]|https?://\S+|\[[^\]]*\]|<[^>]+>")


def clean(text: str) -> str:
    text = QUOTE.sub(" ", text or "")
    return re.sub(r"\s+", " ", text).strip()


def real_messages():
    for path in SOURCES.glob("FORUM/*/normalized/post.jsonl"):
        if not any(h in path.parts[-3] for h in ENGLISH_HOSTS):
            continue
        for line in path.open():
            r = json.loads(line)
            yield r.get("thread_id", ""), "forum", r.get("text", "")
    for line in (SOURCES / "REDDIT/ogame/normalized/comment.jsonl").open():
        r = json.loads(line)
        yield r.get("link_id", ""), "reddit", r.get("body", "")


def words(text: str):
    return WORD.findall(text.lower())


def load_real():
    out = []
    for thread, source, text in real_messages():
        text = clean(text)
        n = len(text)
        if 15 <= n <= 220 and len(words(text)) >= 4:
            out.append({"thread": thread, "source": source, "text": text})
    return out


def load_ours():
    out = []
    for line in OURS.open():
        r = json.loads(line)
        if r["kind"] == "dialogue" and 15 <= len(r["text"]) <= 220:
            out.append({"world": r["world"], "text": r["text"], "model_written": r.get("model_written", False)})
    return out


def bayes(train_a, train_b):
    ca, cb = collections.Counter(), collections.Counter()
    for t in train_a:
        ca.update(set(words(t)))
    for t in train_b:
        cb.update(set(words(t)))
    vocab = set(ca) | set(cb)
    na, nb = len(train_a), len(train_b)

    def score(text):
        s = math.log(na / nb)
        for w in set(words(text)):
            if w in vocab:
                s += math.log((ca[w] + 1) / (na + 2)) - math.log((cb[w] + 1) / (nb + 2))
        return s
    return score, ca, cb


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--out", default=str(ROOT / "storage/rl/social/real-lines.jsonl"))
    args = ap.parse_args()
    random.seed(7)
    real, ours = load_real(), load_ours()
    Path(args.out).parent.mkdir(parents=True, exist_ok=True)
    with open(args.out, "w") as handle:
        for r in real:
            handle.write(json.dumps(r) + "\n")
    print(f"real short messages: {len(real)} (forum {sum(r['source']=='forum' for r in real)}, reddit {sum(r['source']=='reddit' for r in real)}), ours: {len(ours)}")

    threads = sorted({r["thread"] for r in real})
    held = set(random.sample(threads, max(1, len(threads) // 5)))
    worlds = sorted({o["world"] for o in ours})
    held_w = set(worlds[::5])
    real_tr = [r["text"] for r in real if r["thread"] not in held]
    real_te = [r["text"] for r in real if r["thread"] in held]
    ours_tr = [o["text"] for o in ours if o["world"] not in held_w]
    ours_te = [o for o in ours if o["world"] in held_w]
    score, ca, cb = bayes(real_tr, ours_tr)

    # Balanced accuracy: real held-out correct plus ours held-out correct, each over its own count.
    real_ok = sum(score(t) > 0 for t in real_te) / max(1, len(real_te))
    for label, group in (("all ours", ours_te), ("ours worded by model", [o for o in ours_te if o["model_written"]]), ("ours authored", [o for o in ours_te if not o["model_written"]])):
        if group:
            ours_ok = sum(score(o["text"]) <= 0 for o in group) / len(group)
            print(f"classifier tells {label} from real: {ours_ok:.2f} of ours spotted, {real_ok:.2f} of real kept (0.50 each = cannot tell)")

    n_real, n_ours = len(real_tr), len(ours_tr)
    only_real = sorted(((ca[w], w) for w in ca if ca[w] >= 40 and cb[w] == 0), reverse=True)[:40]
    ours_heavy = sorted(((cb[w] / n_ours) / ((ca[w] + 1) / n_real), w) for w in cb if cb[w] >= 25 and cb[w] / n_ours > 8 * (ca[w] + 1) / n_real)
    ours_heavy = sorted(ours_heavy, reverse=True)[:40]
    print("real players say, ours never do:", " ".join(w for _, w in only_real))
    print("ours say far more than real players:", " ".join(w for _, w in ours_heavy))


if __name__ == "__main__":
    main()
