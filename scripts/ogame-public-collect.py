#!/usr/bin/env python3
"""Collect the documented public OGame API into normalized, provenance-manifested records.

Why this exists: the module trains on the real game, and the official public API
(`/api/{serverData,universe,players,alliances,highscore}.xml`) is a permitted,
unauthenticated source of *populations* and *longitudinal standings*. Those give the
simulator a realistic neighbour population to seed and a real growth curve to judge
against, which no synthetic universe provides.

It only COLLECTS public data: an ordinary GET with a browser user agent against a
documented endpoint. No login, no private or team endpoint, no CAPTCHA or access-control
bypass, and it refuses to run against anything but the OGame public API host shape.

Privacy: the API publishes player and alliance NAMES. Those are dropped here, and every
numeric id is remapped through a per-contribution salt so joins survive inside one
contribution while identities do not. The result is pseudonymized, never "anonymous".

Usage:
    scripts/ogame-public-collect.py --universe s1-en.ogame.gameforge.com --out storage/rl/external
    scripts/ogame-public-collect.py --universe ... --highscore 1:0 --highscore 1:1
    scripts/ogame-public-collect.py --offline-dir DIR --out DIR   # re-normalize saved payloads
    scripts/ogame-public-collect.py --self-check

The raw payloads and normalized records are large and public: keep them out of git.
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
import urllib.parse
import urllib.request
import xml.etree.ElementTree as ET
from concurrent.futures import ThreadPoolExecutor, as_completed

UA = (
    "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 "
    "(KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36"
)
PARSER_VERSION = "1"
MAX_BYTES = 128 * 1024 * 1024
HOST = re.compile(r"^s\d+-[a-z]{2}\.ogame\.gameforge\.com$")

# The five documented endpoints. `highscore` takes a category:type pair and repeats.
ENDPOINTS = {
    "serverData": "serverData.xml",
    "universe": "universe.xml",
    "players": "players.xml",
    "alliances": "alliances.xml",
}


def pseudo(salt, kind, raw):
    """A stable, join-preserving pseudonym for one id within one contribution."""
    if raw in (None, ""):
        return None
    return hmac.new(salt.encode(), f"{kind}:{raw}".encode(), hashlib.sha256).hexdigest()[:16]


def text(node, name, default=None):
    found = node.find(name)
    return found.text if found is not None and found.text is not None else default


def number(value):
    try:
        return int(value)
    except (TypeError, ValueError):
        return None


def parse_server(payload, salt, meta):
    root = ET.fromstring(payload)
    settings = {child.tag: (child.text or "") for child in root if child.tag}
    record = {
        "type": "server",
        "source_id": meta["source_id"],
        "captured_at": meta["captured_at"],
        "parser_version": PARSER_VERSION,
        "server_number": number(settings.get("number")),
        "language": settings.get("language"),
        "timezone": settings.get("timezone"),
        "engine_version": settings.get("version"),
        "speed": number(settings.get("speed")),
        "speed_fleet_peaceful": number(settings.get("speedFleetPeaceful")),
        "speed_fleet_war": number(settings.get("speedFleetWar")),
        "speed_fleet_holding": number(settings.get("speedFleetHolding")),
        "galaxies": number(settings.get("galaxies")),
        "systems": number(settings.get("systems")),
        "acs": number(settings.get("acs")),
    }
    return [record]


def parse_universe(payload, salt, meta):
    root = ET.fromstring(payload)
    out = []
    for planet in root.findall("planet"):
        coords = (planet.get("coords") or "").split(":")
        moon = planet.find("moon")
        out.append({
            "type": "planet",
            "source_id": meta["source_id"],
            "captured_at": meta["captured_at"],
            "parser_version": PARSER_VERSION,
            "planet_id": pseudo(salt, "planet", planet.get("id")),
            "owner_id": pseudo(salt, "player", planet.get("player")),
            "galaxy": number(coords[0]) if len(coords) == 3 else None,
            "system": number(coords[1]) if len(coords) == 3 else None,
            "position": number(coords[2]) if len(coords) == 3 else None,
            "has_moon": moon is not None,
            "moon_size": number(moon.get("size")) if moon is not None else None,
        })
    return out


def parse_players(payload, salt, meta):
    root = ET.fromstring(payload)
    return [{
        "type": "player",
        "source_id": meta["source_id"],
        "captured_at": meta["captured_at"],
        "parser_version": PARSER_VERSION,
        "player_id": pseudo(salt, "player", player.get("id")),
        "rank_status": player.get("status") or "",
        "alliance_id": pseudo(salt, "alliance", player.get("alliance")),
    } for player in root.findall("player")]


def parse_alliances(payload, salt, meta):
    root = ET.fromstring(payload)
    out = []
    for alliance in root.findall("alliance"):
        members = [pseudo(salt, "player", member.get("id")) for member in alliance.findall("player")]
        out.append({
            "type": "alliance",
            "source_id": meta["source_id"],
            "captured_at": meta["captured_at"],
            "parser_version": PARSER_VERSION,
            "alliance_id": pseudo(salt, "alliance", alliance.get("id")),
            "founder_id": pseudo(salt, "player", alliance.get("founder")),
            "found_date": number(alliance.get("foundDate")),
            "open": alliance.get("open") == "1",
            "member_count": len(members),
            "member_ids": members,
        })
    return out


def parse_highscore(payload, salt, meta, category, hs_type):
    root = ET.fromstring(payload)
    return [{
        "type": "score",
        "source_id": meta["source_id"],
        "captured_at": meta["captured_at"],
        "parser_version": PARSER_VERSION,
        "category": category,
        "score_type": hs_type,
        "position": number(player.get("position")),
        "player_id": pseudo(salt, "player", player.get("id")),
        "score": number(player.get("score")),
    } for player in root.findall("player")]


def fetch(url):
    """One bounded, retrying GET of a documented public endpoint."""
    last = None
    for attempt in range(3):
        try:
            request = urllib.request.Request(url, headers={"User-Agent": UA})
            with urllib.request.urlopen(request, timeout=60) as response:
                body = response.read(MAX_BYTES + 1)
            if len(body) > MAX_BYTES:
                raise ValueError(f"{url} exceeds the {MAX_BYTES}-byte cap")
            return response.status, body
        except (urllib.error.URLError, TimeoutError, ValueError) as error:
            last = error
            time.sleep(2 ** attempt)
    raise RuntimeError(f"GET {url} failed after retries: {last}")


def api_timestamp(payload):
    try:
        root = ET.fromstring(payload)
    except ET.ParseError:
        return None
    return root.get("timestamp") or root.get("serverId")


def write_jsonl(path, records):
    """Atomic: a partial read never lands in an accepted dataset."""
    tmp = path + ".tmp"
    with open(tmp, "w", encoding="utf-8") as handle:
        for record in records:
            handle.write(json.dumps(record, ensure_ascii=False, sort_keys=True) + "\n")
    os.replace(tmp, path)


def read_raw(raw_dir, stem):
    """A saved payload, plain or gzipped; the collector writes only the gzip for bulk runs."""
    plain = os.path.join(raw_dir, f"{stem}.xml")
    if os.path.exists(plain):
        return open(plain, "rb").read()
    packed = os.path.join(raw_dir, f"{stem}.xml.gz")
    return gzip.open(packed, "rb").read() if os.path.exists(packed) else None


def normalize(raw_dir, out_dir, source_id, salt, captured_at):
    """Turn saved payloads into typed records. Shared by the live path and --offline-dir."""
    meta = {"source_id": source_id, "captured_at": captured_at}
    records = {}
    for name, parser in (("serverData", parse_server), ("universe", parse_universe),
                         ("players", parse_players), ("alliances", parse_alliances)):
        payload = read_raw(raw_dir, name)
        if payload is None:
            continue
        for record in parser(payload, salt, meta):
            records.setdefault(record["type"], []).append(record)
    for filename in sorted(os.listdir(raw_dir)):
        match = re.match(r"highscore-(\d+)-(\d+)\.xml(?:\.gz)?$", filename)
        if not match:
            continue
        payload = read_raw(raw_dir, filename.split(".xml")[0])
        for record in parse_highscore(payload, salt, meta, int(match.group(1)), int(match.group(2))):
            records.setdefault("score", []).append(record)
    out = os.path.join(out_dir, "normalized")
    os.makedirs(out, exist_ok=True)
    for record_type, rows in records.items():
        write_jsonl(os.path.join(out, f"{record_type}.jsonl"), rows)
    return {record_type: len(rows) for record_type, rows in records.items()}


def save_raw(name, url, status, body, raw_dir):
    digest = hashlib.sha256(body).hexdigest()
    with gzip.open(os.path.join(raw_dir, f"{name}.xml.gz"), "wb") as handle:
        handle.write(body)
    return {"name": name, "url": url, "http_status": status, "bytes": len(body),
            "sha256": digest, "api_timestamp": api_timestamp(body)}


def write_manifest(out_dir, manifest):
    with open(os.path.join(out_dir, "manifest.json"), "w", encoding="utf-8") as handle:
        json.dump(manifest, handle, indent=2, sort_keys=True)


def merge_index(out, fresh):
    """The aggregate index, kept across runs so a re-run of a subset does not clobber it."""
    path = os.path.join(out, "manifest.json")
    known = []
    if os.path.exists(path):
        try:
            known = json.load(open(path, encoding="utf-8")).get("sources", [])
        except ValueError:
            known = []
    by_id = {item["source_id"]: item for item in known}
    for item in fresh:
        by_id[item["source_id"]] = item
    return [by_id[key] for key in sorted(by_id)]


def now():
    return datetime.datetime.now(datetime.timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z")


def payload_captured_at(raw_dir):
    """The newest `timestamp` the saved payloads carry, as ISO UTC; None when none carries one."""
    stamps = []
    for filename in sorted(os.listdir(raw_dir)):
        match = re.match(r"(.+)\.xml(?:\.gz)?$", filename)
        payload = read_raw(raw_dir, match.group(1)) if match else None
        try:
            stamp = int(ET.fromstring(payload).get("timestamp")) if payload else None
        except (ET.ParseError, TypeError, ValueError):
            stamp = None
        if stamp:
            stamps.append(stamp)
    if not stamps:
        return None
    return datetime.datetime.fromtimestamp(max(stamps), datetime.timezone.utc).isoformat().replace("+00:00", "Z")


def collect_one(host, out, highscore, salt):
    """Fetch one public universe, normalize it and write its manifest. Raises on a non-public host."""
    if not HOST.match(host):
        raise ValueError(f"refusing {host!r}: only an OGame public API host is collected")
    base = f"https://{host}/api/"
    endpoints, raw_dir, source_id = [], None, None
    for name, path in ENDPOINTS.items():
        status, body = fetch(base + path)
        source_id = source_id or "PUB-" + (ET.fromstring(body).get("serverId") or host)
        raw_dir = raw_dir or os.path.join(out, "sources", source_id, "raw")
        os.makedirs(raw_dir, exist_ok=True)
        endpoints.append(save_raw(name, base + path, status, body, raw_dir))
    for category, hs_type in highscore:
        url = f"{base}highscore.xml?category={category}&type={hs_type}"
        status, body = fetch(url)
        endpoints.append(save_raw(f"highscore-{category}-{hs_type}", url, status, body, raw_dir))
    salt = salt or hashlib.sha256(source_id.encode()).hexdigest()
    captured = now()
    counts = normalize(raw_dir, os.path.join(out, "sources", source_id), source_id, salt, captured)
    manifest = {
        "source_id": source_id, "universe": host, "access": "public OGame API (unauthenticated)",
        "captured_at": captured, "parser_version": PARSER_VERSION, "pseudonymized": True,
        "salt_fingerprint": hashlib.sha256(salt.encode()).hexdigest()[:12],
        "endpoints": endpoints, "record_counts": counts,
    }
    write_manifest(os.path.join(out, "sources", source_id), manifest)
    return manifest


def normalize_dir(offline_dir, out, salt):
    """Re-normalize a saved source dir with no network, for a reproducible import."""
    raw_dir = os.path.join(offline_dir, "raw") if os.path.isdir(os.path.join(offline_dir, "raw")) else offline_dir
    server_id = os.path.basename(os.path.normpath(offline_dir))
    settings = os.path.join(raw_dir, "serverData.xml")
    if os.path.exists(settings):
        server_id = ET.fromstring(open(settings, "rb").read()).get("serverId") or server_id
    source_id = f"PUB-{server_id}"
    salt = salt or hashlib.sha256(source_id.encode()).hexdigest()
    # Saved payloads were captured when the API published them, not when they are re-normalized: the
    # newest payload timestamp is that instant, and it keeps two re-normalizations byte-identical.
    captured = payload_captured_at(raw_dir) or now()
    counts = normalize(raw_dir, os.path.join(out, "sources", source_id), source_id, salt, captured)
    manifest = {
        "source_id": source_id, "universe": "", "access": "public OGame API (unauthenticated)",
        "captured_at": captured, "parser_version": PARSER_VERSION, "pseudonymized": True,
        "salt_fingerprint": hashlib.sha256(salt.encode()).hexdigest()[:12],
        "endpoints": [], "record_counts": counts,
    }
    write_manifest(os.path.join(out, "sources", source_id), manifest)
    return manifest


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--universe", action="append", default=[], metavar="HOST",
                        help="public API host, e.g. s1-en.ogame.gameforge.com (repeatable)")
    parser.add_argument("--universes-file", metavar="FILE",
                        help="a file of public API hosts, one per line (# comments allowed)")
    parser.add_argument("--offline-dir", help="a saved source dir to re-normalize without network")
    parser.add_argument("--out", default="storage/rl/external", help="output root (default: storage/rl/external)")
    parser.add_argument("--highscore", action="append", default=[], metavar="CAT:TYPE",
                        help="highscore pair to collect (repeatable; default 1:0)")
    parser.add_argument("--salt", help="per-contribution id salt (default: a hash of the source id)")
    parser.add_argument("--delay", type=float, default=1.0, help="seconds between hosts (default 1.0)")
    parser.add_argument("--jobs", type=int, default=1, help="hosts collected in parallel (default 1)")
    parser.add_argument("--self-check", action="store_true")
    args = parser.parse_args(argv)

    if args.self_check:
        self_check()
        return 0

    highscore = [tuple(int(x) for x in pair.split(":")) for pair in (args.highscore or ["1:0"])]
    os.makedirs(args.out, exist_ok=True)

    if args.offline_dir:
        manifest = normalize_dir(args.offline_dir, args.out, args.salt)
        print(f"{manifest['source_id']}: {manifest['record_counts']} (manifest written)")
        return 0

    hosts = list(args.universe)
    if args.universes_file:
        hosts += [line.strip() for line in open(args.universes_file) if line.strip() and not line.startswith("#")]
    if not hosts:
        parser.error("give --universe, --universes-file or --offline-dir")

    index = []
    with ThreadPoolExecutor(max_workers=max(1, args.jobs)) as pool:
        futures = {}
        for position, host in enumerate(hosts):
            if position:
                time.sleep(args.delay)  # pace the submissions, not just the completions
            futures[pool.submit(collect_one, host, args.out, highscore, args.salt)] = host
        for future in as_completed(futures):
            host = futures[future]
            try:
                manifest = future.result()
            except Exception as error:  # one dead universe must not lose the batch
                print(f"SKIP {host}: {error}", file=sys.stderr, flush=True)
                continue
            index.append({key: manifest[key] for key in ("source_id", "universe", "captured_at", "salt_fingerprint", "record_counts")})
            print(f"{manifest['source_id']}: {manifest['record_counts']}", flush=True)
    write_manifest(args.out, {"parser_version": PARSER_VERSION, "sources": merge_index(args.out, index)})
    print(f"{len(index)} source(s); index at {os.path.join(args.out, 'manifest.json')}")
    return 0 if index else 1


# --self-check: a real parse and remap of tiny payloads, and nothing else. The live path is the
# same functions, so a change that breaks them fails here first.
SETTINGS = ('<serverData timestamp="1" serverId="en1"><number>1</number><language>en</language>'
            '<timezone>Europe/London</timezone><version>13.0.1-r2hf</version><speed>8</speed>'
            '<speedFleetPeaceful>1</speedFleetPeaceful><speedFleetWar>1</speedFleetWar>'
            '<speedFleetHolding>1</speedFleetHolding><galaxies>9</galaxies><systems>499</systems>'
            '<acs>1</acs></serverData>')
UNIVERSE = ('<universe timestamp="1" serverId="en1">'
            '<planet id="1" player="1" name="Arakis" coords="1:1:2"><moon id="9" name="Moon" size="5000"/></planet>'
            '<planet id="107176" player="101769" name="Buying Deut" coords="1:387:5"/></universe>')
PLAYERS = ('<players timestamp="1" serverId="en1">'
           '<player id="1" name="Legor" status="a"/>'
           '<player id="101769" name="jean michel" status="vi" alliance="500449"/></players>')
ALLIANCES = ('<alliances timestamp="1" serverId="en1">'
             '<alliance id="500449" name="JediBites" tag="JeB" founder="1" foundDate="1095890237" '
             'homepage="http://x/" logo="http://y/" open="1"><player id="1"/><player id="101769"/></alliance></alliances>')
HIGHSCORE = ('<highscore category="1" type="0" timestamp="1" serverId="en1">'
             '<player position="1" id="1" score="1013516250537"/></highscore>')


def self_check():
    meta = {"source_id": "PUB-en1", "captured_at": "2026-10-09T00:00:00Z"}
    salt = "selfcheck"
    server = parse_server(SETTINGS, salt, meta)[0]
    assert server["speed"] == 8 and server["galaxies"] == 9 and server["engine_version"] == "13.0.1-r2hf"
    planets = parse_universe(UNIVERSE, salt, meta)
    assert planets[0]["galaxy"] == 1 and planets[0]["system"] == 1 and planets[0]["position"] == 2
    assert planets[0]["has_moon"] and planets[0]["moon_size"] == 5000
    assert not planets[1]["has_moon"]
    players = parse_players(PLAYERS, salt, meta)
    assert players[0]["rank_status"] == "a" and players[1]["alliance_id"] is not None
    # A name is dropped: the record has no key that carries one.
    assert "name" not in players[1] and "name" not in planets[1], "names must not survive normalization"
    # Joins hold: the planet's owner is the same pseudonym the player record uses.
    assert planets[0]["owner_id"] == players[0]["player_id"]
    # The alliance a player points at is the alliance record's own id.
    alliances = parse_alliances(ALLIANCES, salt, meta)
    assert players[1]["alliance_id"] == alliances[0]["alliance_id"]
    assert alliances[0]["member_count"] == 2 and alliances[0]["open"] is True
    # Remapping is deterministic within a salt and different across salts.
    assert pseudo(salt, "player", "1") == pseudo(salt, "player", "1")
    assert pseudo(salt, "player", "1") != pseudo("other", "player", "1")
    assert pseudo(salt, "player", "1") != pseudo(salt, "planet", "1"), "a kind change is a different id space"
    assert pseudo(salt, "player", None) is None
    scores = parse_highscore(HIGHSCORE, salt, meta, 1, 0)
    assert scores[0]["position"] == 1 and scores[0]["score"] == 1013516250537
    assert scores[0]["player_id"] == players[0]["player_id"], "a score joins the player by pseudonym"
    print("self-check ok (parse, name-drop, join-preserving pseudonyms)")


if __name__ == "__main__":
    sys.exit(main())
