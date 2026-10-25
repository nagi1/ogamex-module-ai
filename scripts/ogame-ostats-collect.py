#!/usr/bin/env python3
"""Collect the OStats public JSON API into normalized, provenance-manifested records.

Why this exists: the official OGame API (see ogame-public-collect.py) gives a *current*
snapshot. OStats (`https://ostats.eu/api`, robots.txt allows `/api/` except auth/admin)
also keeps an hourly *history* per player - score and rank over time - which is the
longitudinal evidence the module's growth evaluation wants and the official API cannot
give. Its universe catalogue also covers more universes than a host probe finds.

Only public GETs of documented JSON endpoints. No login, no private or team endpoint.
Names and tags are dropped; ids are remapped with the same per-universe salt as the
official collector, so a player recorded by both joins on the pseudonym.

Usage:
    scripts/ogame-ostats-collect.py --out storage/rl/external --history-per-universe 5 --jobs 4
    scripts/ogame-ostats-collect.py --offline-dir DIR --out DIR   # re-normalize saved JSON
    scripts/ogame-ostats-collect.py --self-check
"""

import argparse
import datetime
import gzip
import hashlib
import hmac
import json
import os
import re
import sys
import time
import urllib.error
import urllib.request
from concurrent.futures import ThreadPoolExecutor, as_completed

API = "https://ostats.eu/api"
UA = "Mozilla/5.0 (compatible; OGameX-AI-research/1.0)"
PARSER_VERSION = "1"
UNIVERSE = re.compile(r"^s(\d+)-([a-z]{2,3})$")


def pseudo(salt, kind, raw):
    """A stable, join-preserving pseudonym for one id within one contribution."""
    if raw in (None, ""):
        return None
    return hmac.new(salt.encode(), f"{kind}:{raw}".encode(), hashlib.sha256).hexdigest()[:16]


def universe_salt(universe):
    """The same salt the official collector uses for this universe, so ids join across both."""
    match = UNIVERSE.match(universe or "")
    server_id = f"{match.group(2)}{match.group(1)}" if match else (universe or "")
    return hashlib.sha256(f"PUB-{server_id}".encode()).hexdigest()


def now():
    return datetime.datetime.now(datetime.timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z")


def fetch_json(url):
    """A bounded, retrying GET of one public JSON endpoint."""
    last = None
    for attempt in range(3):
        try:
            request = urllib.request.Request(url, headers={"User-Agent": UA, "Accept": "application/json"})
            with urllib.request.urlopen(request, timeout=60) as response:
                return json.load(response)
        except (urllib.error.URLError, TimeoutError, ValueError) as error:
            last = error
            time.sleep(2 ** attempt)
    raise RuntimeError(f"GET {url} failed after retries: {last}")


def save_json(path, payload):
    tmp = path + ".tmp"
    with gzip.open(tmp, "wb") as handle:
        handle.write(json.dumps(payload, ensure_ascii=False).encode())
    os.replace(tmp, path)


def read_json(path):
    opener = gzip.open if path.endswith(".gz") else open
    with opener(path, "rb") as handle:
        return json.load(handle)


def saved(directory, stem):
    """A saved payload, gzipped (live) or plain (a fixture), else None."""
    for name in (f"{stem}.json.gz", f"{stem}.json"):
        path = os.path.join(directory, name)
        if os.path.exists(path):
            return path
    return None


def write_jsonl(path, records):
    tmp = path + ".tmp"
    with open(tmp, "w", encoding="utf-8") as handle:
        for record in records:
            handle.write(json.dumps(record, ensure_ascii=False, sort_keys=True) + "\n")
    os.replace(tmp, path)


def base(universe, kind, captured):
    return {"source_id": "OSTATS", "universe": universe, "type": kind,
            "captured_at": captured, "parser_version": PARSER_VERSION}


def payload_universe(payload):
    """The universe id. The roster endpoints return an object there, history a string."""
    value = payload.get("universe")
    return value.get("universe") if isinstance(value, dict) else value


def norm_players(payload, captured):
    universe = payload_universe(payload)
    salt = universe_salt(universe)
    return [dict(base(universe, "player", captured),
                 player_id=pseudo(salt, "player", item["id"]),
                 rank_status=item.get("status") or "",
                 alliance_id=pseudo(salt, "alliance", item.get("allianceId")))
            for item in payload.get("items", [])]


def norm_alliances(payload, captured):
    universe = payload_universe(payload)
    salt = universe_salt(universe)
    return [dict(base(universe, "alliance", captured), alliance_id=pseudo(salt, "alliance", item["id"]))
            for item in payload.get("items", [])]


def norm_history(payload, captured):
    universe = payload_universe(payload)
    salt = universe_salt(universe)
    pid = pseudo(salt, "player", payload["id"])
    return [dict(base(universe, "score_point", captured),
                 player_id=pid, category=payload.get("category"), score_type=payload.get("type"),
                 observed_at=item.get("capturedAt"), position=item.get("position"), score=item.get("score"))
            for item in payload.get("items", [])]


def norm_catalog(payload, captured):
    out = []
    for item in payload.get("items", []):
        out.append(dict(base(item.get("universe"), "universe_catalogue", captured),
                        community=item.get("community"), number=item.get("number"), universe_name=item.get("name"),
                        players=item.get("playerCount"), players_online=item.get("playersOnline"),
                        opened=item.get("opened"), server_closed=item.get("serverClosed"),
                        speed_economy=item.get("economySpeed"), speed_fleet_war=item.get("fleetSpeedWar")))
    return out


def normalize(raw_root, out_root, captured):
    """Turn saved JSON into typed records. Shared by the live path and --offline-dir."""
    records = {}
    catalog = saved(raw_root, "catalog")
    if catalog:
        for record in norm_catalog(read_json(catalog), captured):
            records.setdefault(record["type"], []).append(record)
    for directory in sorted(os.listdir(raw_root)):
        path = os.path.join(raw_root, directory)
        if not os.path.isdir(path):
            continue
        for stem, builder in (("players", norm_players), ("alliances", norm_alliances)):
            found = saved(path, stem)
            if found:
                for record in builder(read_json(found), captured):
                    records.setdefault(record["type"], []).append(record)
        for name in sorted(os.listdir(path)):
            if name.startswith("history-") and name.endswith((".json", ".json.gz")):
                for record in norm_history(read_json(os.path.join(path, name)), captured):
                    records.setdefault(record["type"], []).append(record)
    normalized = os.path.join(out_root, "normalized")
    os.makedirs(normalized, exist_ok=True)
    for record_type, rows in records.items():
        write_jsonl(os.path.join(normalized, f"{record_type}.jsonl"), rows)
    return {record_type: len(rows) for record_type, rows in records.items()}


def collect_universe(universe, raw_root, history_per_universe):
    """Roster always; histories for the first N players (the list is rank-ordered)."""
    directory = os.path.join(raw_root, universe)
    os.makedirs(directory, exist_ok=True)
    requests = 0
    players = fetch_json(f"{API}/players?universe={universe}")
    save_json(os.path.join(directory, "players.json.gz"), players)
    requests += 1
    alliances = fetch_json(f"{API}/alliances?universe={universe}")
    save_json(os.path.join(directory, "alliances.json.gz"), alliances)
    requests += 1
    for item in players.get("items", [])[:history_per_universe]:
        history = fetch_json(f"{API}/player-history?universe={universe}&id={item['id']}")
        save_json(os.path.join(directory, f"history-{item['id']}.json.gz"), history)
        requests += 1
    return universe, requests


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--out", default="storage/rl/external", help="output root (default: storage/rl/external)")
    parser.add_argument("--offline-dir", help="a saved raw dir to re-normalize without network")
    parser.add_argument("--limit-universes", type=int, default=0, help="only the first N universes (0 = all)")
    parser.add_argument("--history-per-universe", type=int, default=0, help="hourly histories per universe (0 = none)")
    parser.add_argument("--jobs", type=int, default=1, help="universes collected in parallel (default 1)")
    parser.add_argument("--delay", type=float, default=0.5, help="seconds between submissions (default 0.5)")
    parser.add_argument("--self-check", action="store_true")
    args = parser.parse_args(argv)

    if args.self_check:
        self_check()
        return 0

    out_root = os.path.join(args.out, "sources", "OSTATS")
    raw_root = os.path.join(out_root, "raw")
    os.makedirs(raw_root, exist_ok=True)
    captured = now()

    if args.offline_dir:
        source = os.path.join(args.offline_dir, "raw") if os.path.isdir(os.path.join(args.offline_dir, "raw")) else args.offline_dir
        counts = normalize(source, out_root, captured)
        write_manifest(out_root, {"record_counts": counts, "captured_at": captured})
        print(f"OSTATS: {counts} (offline)")
        return 0

    catalogue = fetch_json(f"{API}/universes")
    save_json(os.path.join(raw_root, "catalog.json.gz"), catalogue)
    universes = [item["universe"] for item in catalogue.get("items", [])]
    if args.limit_universes:
        universes = universes[:args.limit_universes]

    done, requests = 0, 1
    with ThreadPoolExecutor(max_workers=max(1, args.jobs)) as pool:
        futures = {}
        for position, universe in enumerate(universes):
            if position:
                time.sleep(args.delay)
            futures[pool.submit(collect_universe, universe, raw_root, args.history_per_universe)] = universe
        for future in as_completed(futures):
            universe = futures[future]
            try:
                _, used = future.result()
            except Exception as error:  # one dead universe must not lose the batch
                print(f"SKIP {universe}: {error}", file=sys.stderr, flush=True)
                continue
            requests += used
            done += 1
            print(f"OSTATS {universe} ok ({done}/{len(universes)})", flush=True)

    counts = normalize(raw_root, out_root, captured)
    manifest = {
        "source_id": "OSTATS", "access": "public OStats API (robots-allowed /api/)",
        "captured_at": captured, "parser_version": PARSER_VERSION, "pseudonymized": True,
        "universes": done, "requests": requests, "record_counts": counts,
    }
    write_manifest(out_root, manifest)
    print(f"OSTATS: {done} universes, {requests} requests; {counts}")
    return 0 if done else 1


def write_manifest(out_dir, manifest):
    with open(os.path.join(out_dir, "manifest.json"), "w", encoding="utf-8") as handle:
        json.dump(manifest, handle, indent=2, sort_keys=True)


# --self-check: real parses of tiny payloads, and the salt mapping that makes ids join the
# official collector. The live path is the same functions, so a break here fails first.
CATALOGUE = {"items": [{"universe": "s189-pl", "community": "pl", "number": 189, "name": "Kerberos",
                        "playerCount": 6543, "playersOnline": 105, "opened": "2022-11-04T13:00:00+0000",
                        "serverClosed": False, "economySpeed": 0}]}
PLAYERS = {"universe": {"universe": "s189-pl", "community": "pl", "number": 189},
           "items": [{"id": 100250, "name": "Real Name", "status": "", "allianceId": 500005}]}
ALLIANCES = {"universe": {"universe": "s189-pl", "community": "pl", "number": 189},
             "items": [{"id": 500005, "tag": "TAG", "name": "An Alliance"}]}
HISTORY = {"universe": "s189-pl", "category": 1, "type": 0, "id": 100250,
           "items": [{"capturedAt": "2026-10-09T19:00:00Z", "position": 731, "score": 55614840, "name": None}]}


def self_check():
    captured = "2026-10-09T00:00:00Z"
    salt = universe_salt("s189-pl")
    # The salt matches the official collector's for the same universe (source id PUB-pl189).
    assert salt == hashlib.sha256(b"PUB-pl189").hexdigest()
    assert universe_salt("s1-en") == hashlib.sha256(b"PUB-en1").hexdigest()
    universe = norm_catalog(CATALOGUE, captured)[0]
    assert universe["type"] == "universe_catalogue" and universe["number"] == 189 and universe["players"] == 6543
    player = norm_players(PLAYERS, captured)[0]
    assert "name" not in player and player["player_id"] == pseudo(salt, "player", 100250)
    assert player["alliance_id"] == pseudo(salt, "alliance", 500005)
    assert norm_alliances(ALLIANCES, captured)[0]["alliance_id"] == player["alliance_id"]
    points = norm_history(HISTORY, captured)
    assert len(points) == 1 and points[0]["position"] == 731 and points[0]["score"] == 55614840
    assert points[0]["player_id"] == player["player_id"], "a history point joins its player"
    assert points[0]["observed_at"] == "2026-10-09T19:00:00Z"
    assert pseudo(salt, "player", 100250) != pseudo(salt, "player", 100251)
    print("self-check ok (catalogue, roster, hourly history; salt joins the official collector)")


if __name__ == "__main__":
    sys.exit(main())
