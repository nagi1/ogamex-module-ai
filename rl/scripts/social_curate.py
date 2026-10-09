"""Cut the raw social corpus down to what is worth training on.

    python3 Modules/AI/rl/scripts/social_curate.py [corpus.jsonl] [corpus.train.jsonl]

The raw corpus keeps everything a world said, so a world that ran away (9484: 1,676 "yo/sup/hi" in one channel) or an old
one that ping-ponged greetings would teach the model that a player mostly says hi. Dialogue is capped per world and
overall for any one text; every other kind passes through untouched.
"""

import json
import re
import sys
from collections import Counter
from pathlib import Path

ROOT = Path(__file__).resolve().parents[4]
SOURCE = Path(sys.argv[1]) if len(sys.argv) > 1 else ROOT / "storage/rl/social/corpus.jsonl"
OUT = Path(sys.argv[2]) if len(sys.argv) > 2 else SOURCE.with_name("corpus.train.jsonl")
PER_WORLD = 3
OVERALL = 25


def main():
    per_world, overall, kept, dropped = Counter(), Counter(), Counter(), Counter()
    with SOURCE.open() as source, OUT.open("w") as sink:
        for line in source:
            record = json.loads(line)
            if record["kind"] == "dialogue":
                text = re.sub(r"\s+", " ", record["text"].strip().lower())
                if per_world[(record["world"], text)] >= PER_WORLD or overall[text] >= OVERALL:
                    dropped["dialogue"] += 1
                    continue
                per_world[(record["world"], text)] += 1
                overall[text] += 1
            kept[record["kind"]] += 1
            sink.write(line)
    print("kept", dict(kept), "dropped", dict(dropped))


main()
