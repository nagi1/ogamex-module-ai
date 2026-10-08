"""Collect the social training data every finished late world holds into one JSONL corpus.

    python3 Modules/AI/rl/scripts/social_corpus.py [storage/rl/social/corpus.jsonl]

One line per record, `kind` says which: `dialogue` (a chat message between players, with the sender's persona, the
relationship between the two at the end of the world, and whether the language model wrote it), `exchange` (a social
exchange with its decision and reason), `reading` (a classifier reading: text and answers), `judge` (the veteran judge's
scores for the world). Worlds already in the corpus are skipped, so the file only grows.
"""

import json
import re
import sqlite3
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[4]
WORLDS = ROOT / "storage/rl/late"
OUT = Path(sys.argv[1]) if len(sys.argv) > 1 else ROOT / "storage/rl/social/corpus.jsonl"
ARCHETYPES = {1: "miner", 2: "raider", 3: "fleeter", 4: "turtle", 5: "hybrid", 6: "trader", 7: "casual"}


def rows(db, sql):
    db.row_factory = sqlite3.Row
    return [dict(row) for row in db.execute(sql)]


def table_exists(db, name):
    return db.execute("select 1 from sqlite_master where name = ?", (name,)).fetchone() is not None


def worlds_done():
    if not OUT.exists():
        return set()
    return {json.loads(line)["world"] for line in OUT.open() if line.strip()}


def judge_scores(path):
    if not path.exists():
        return None
    head = path.read_text().splitlines()[0]
    found = {k: int(v) for k, v in re.findall(r"([a-z\- ]+?) (\d)/3", head.replace(", ", ",").replace(",", " ,"))}
    return {"line": head, "scores": found}


def collect(world, db):
    out = []
    archetype = {r["player_id"]: str(r["archetype"]) for r in rows(db, "select player_id, archetype from ai_profiles")}
    relation = {(r["player_id"], r["other_player_id"]): r for r in rows(db, "select * from ai_relationships")}
    model_written = set()
    if table_exists(db, "ai_language_requests"):
        for r in rows(db, "select r.delivered_chat_message_id as m, l.input_tokens as i, l.output_tokens as o, l.cost as c "
                          "from ai_language_requests l join ai_conversation_replies r on r.id = l.conversation_reply_id"):
            model_written.add(r["m"])
    for m in rows(db, "select id, sender_id, recipient_id, alliance_id, message, created_at from chat_messages "
                      "where sender_id in (select player_id from ai_profiles)"):
        rel = relation.get((m["sender_id"], m["recipient_id"])) or {}
        out.append({"kind": "dialogue", "world": world, "id": m["id"], "at": m["created_at"], "from": archetype.get(m["sender_id"]),
                    "to": archetype.get(m["recipient_id"], "human"), "alliance": m["alliance_id"] is not None, "text": m["message"],
                    "model_written": m["id"] in model_written,
                    "relation": {k: rel.get(k) for k in ("trust", "threat", "affinity", "respect")} if rel else None})
    for e in rows(db, "select * from ai_social_exchanges"):
        out.append({"kind": "exchange", "world": world, "id": e["id"], "at": e["created_at"], "type": e["type"], "response": e["response"],
                    "reason": e["response_reason"], "from": archetype.get(e["player_id"]), "to": archetype.get(e["counterparty_player_id"], "human")})
    if table_exists(db, "ai_text_readings"):
        for r in rows(db, "select purpose, text, answers, model from ai_text_readings"):
            out.append({"kind": "reading", "world": world, "purpose": r["purpose"], "text": r["text"], "answers": json.loads(r["answers"]), "model": r["model"]})
    judged = judge_scores(WORLDS / world / "judge.md")
    if judged:
        out.append({"kind": "judge", "world": world, **judged})
    return out


def main():
    OUT.parent.mkdir(parents=True, exist_ok=True)
    done = worlds_done()
    added = {}
    with OUT.open("a") as sink:
        for end in sorted(WORLDS.glob("*/end.sqlite")):
            world = end.parent.name
            if world in done:
                continue
            try:
                records = collect(world, sqlite3.connect(f"file:{end}?mode=ro", uri=True))
            except sqlite3.Error as error:
                print(f"skipped {world}: {error}")
                continue
            for record in records:
                sink.write(json.dumps(record) + "\n")
            added[world] = len(records)
    print(f"added {sum(added.values())} records from {len(added)} world(s)")
    counts = {}
    for line in OUT.open():
        record = json.loads(line)
        key = record["kind"] + (":model" if record.get("model_written") else "")
        counts[key] = counts.get(key, 0) + 1
    print(counts)


main()
