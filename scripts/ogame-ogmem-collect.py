#!/usr/bin/env python3
"""Collect the public OGMem battle archive into normalized, anonymized battle records.

Why this exists: the module's combat scenarios want real fights - which hulls and defences
meet, what survives, what is looted and what becomes debris. Neither the official API nor
OStats exposes combat; OGMem publishes a public archive of uploaded combat reports
(`https://ogmem.com/cr/archive`, reports at `/show/<id>`), and its robots.txt is absent, so
it is collected as a permitted public page per the ETL plan's method 3.

Privacy: report pages carry player and alliance names; those are dropped entirely. Only the
numeric outcome, the unit names and the universe label survive.

Usage:
    scripts/ogame-ogmem-collect.py --pages 20 --max-reports 400 --jobs 3
    scripts/ogame-ogmem-collect.py --offline-dir DIR --out DIR   # re-normalize saved HTML
    scripts/ogame-ogmem-collect.py --self-check
"""

import argparse
import datetime
import gzip
import html
import json
import os
import re
import sys
import time
import urllib.error
import urllib.request
from concurrent.futures import ThreadPoolExecutor, as_completed

BASE = "https://ogmem.com"
UA = "Mozilla/5.0 (compatible; OGameX-AI-research/1.0)"
PARSER_VERSION = "1"
REPORT_LINK = re.compile(r'data-ogmem-href="/show/(\d+)"')
TITLE = re.compile(r"<title>(.*?)</title>", re.S)
UNIVERSE_LABEL = re.compile(r"OGame\.[a-z]{2}\s+(.+?)\s*$")


def now():
    return datetime.datetime.now(datetime.timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z")


def get(url):
    last = None
    for attempt in range(3):
        try:
            request = urllib.request.Request(url, headers={"User-Agent": UA})
            with urllib.request.urlopen(request, timeout=45) as response:
                return response.read().decode("utf-8", "replace")
        except (urllib.error.URLError, TimeoutError) as error:
            last = error
            time.sleep(2 ** attempt)
    raise RuntimeError(f"GET {url} failed after retries: {last}")


def save_text(path, text):
    tmp = path + ".tmp"
    with gzip.open(tmp, "wb") as handle:
        handle.write(text.encode())
    os.replace(tmp, path)


def read_text(path):
    opener = gzip.open if path.endswith(".gz") else open
    with opener(path, "rb") as handle:
        return handle.read().decode("utf-8", "replace")


def write_jsonl(path, records):
    tmp = path + ".tmp"
    with open(tmp, "w", encoding="utf-8") as handle:
        for record in records:
            handle.write(json.dumps(record, ensure_ascii=False, sort_keys=True) + "\n")
    os.replace(tmp, path)


def cells(row):
    # The details tables carry a leading empty spacer cell; dropping empties lines every table up.
    return [clean for clean in (re.sub(r"\s+", " ", html.unescape(re.sub(r"<[^>]+>", " ", cell))).strip()
                                for cell in re.findall(r"<t[hd].*?</t[hd]>", row, re.S)) if clean]


def tables(document):
    """Every table as a list of rows of cleaned cells."""
    out = []
    for table in re.findall(r"<table.*?</table>", document, re.S):
        rows = [row for row in (cells(r) for r in re.findall(r"<tr.*?</tr>", table, re.S)) if row]
        if rows:
            out.append(rows)
    return out


def num(value):
    """OGMem writes '+1,234', a unicode minus, an en dash for none, or an empty cell."""
    if value is None:
        return None
    cleaned = value.strip().replace("+", "").replace("\u2212", "-").replace(",", "")
    if cleaned in ("", "-", "\u2013", "\u2014"):
        return None
    try:
        return int(cleaned)
    except ValueError:
        return None


def labelled(rows, header):
    """The row whose first cell equals header, as its remaining cells."""
    for row in rows:
        if row and row[0].strip().lower() == header.lower():
            return row[1:]
    return []


def labelled_any(rows, pattern):
    """The row whose first cell matches a pattern ("Loot (50%)" varies), as its remaining cells."""
    for row in rows:
        if row and re.match(pattern, row[0].strip().lower()):
            return row[1:]
    return []


def triple(row):
    return tuple(num(x) for x in (row + [None] * 4)[:4])


def parse_report(document, report_id, captured):
    """A battle report's numeric outcome and both fleets, with every name dropped."""
    title = html.unescape(TITLE.search(document).group(1)) if TITLE.search(document) else ""
    community, universe = "", ""
    match = re.search(r"OGame\.([a-z]{2})\s+([^\u00b7]+?)\s*\u00b7", title)
    if match:
        community, universe = match.group(1), match.group(2).strip()
    battle = {"source_id": "OGMEM", "type": "battle", "report_id": report_id,
              "community": community, "universe": universe,
              "captured_at": captured, "parser_version": PARSER_VERSION}
    units, fleet_tables, survivor_tables = [], 0, 0
    for rows in tables(document):
        head = [cell.strip().lower() for cell in rows[0]]
        labels = [row[0].strip().lower() for row in rows]
        if head[:2] == ["attacker", "defender"]:
            pairs = {"Total": ("attacker_result", "defender_result"),
                     "Honour points": ("honour_attacker", "honour_defender"),
                     "Battle losses": ("attacker_losses", "defender_losses"),
                     "Shots": ("attacker_shots", "defender_shots"),
                     "Firepower": ("attacker_firepower", "defender_firepower")}
            for label, (left, right) in pairs.items():
                if label.lower() in labels:
                    row = labelled(rows, label)
                    battle[left], battle[right] = (num(row[0]), num(row[1])) if len(row) > 1 else (None, None)
        if head[:1] == ["metal"] and any(label.startswith("loot (") for label in labels):
            loot = labelled_any(rows, r"^loot \(")
            battle["loot_metal"], battle["loot_crystal"], battle["loot_deuterium"], battle["loot"] = triple(loot)
            debris = labelled(rows, "Debris field")
            battle["debris_metal"], battle["debris_crystal"], battle["debris_deuterium"], battle["debris"] = triple(debris)
        if head[:3] == ["type", "before", "lost"]:
            side = "attacker" if fleet_tables == 0 else "defender"
            fleet_tables += 1
            for row in rows[1:]:
                if len(row) >= 3:
                    units.append({"source_id": "OGMEM", "type": "battle_unit", "report_id": report_id,
                                  "side": side, "unit": row[0].strip(), "before": num(row[1]), "lost": num(row[2]),
                                  "captured_at": captured, "parser_version": PARSER_VERSION})
        if head[:3] == ["type", "units", "lost"]:
            side = "attacker" if survivor_tables == 0 else "defender"
            survivor_tables += 1
            for row in rows[1:]:
                if len(row) >= 3:
                    units.append({"source_id": "OGMEM", "type": "battle_unit", "report_id": report_id,
                                  "side": side, "unit": row[0].strip(), "surviving": num(row[1]), "lost": num(row[2]),
                                  "captured_at": captured, "parser_version": PARSER_VERSION})
    return battle, units


def parse_archive(document, captured):
    """The archive page's report ids and universe labels."""
    rows = []
    for match in REPORT_LINK.finditer(document):
        span = document[match.end():match.end() + 400]
        badge = re.search(r'class="ogmem-universe-badge">\s*([^<]+?)\s*<', span)
        rows.append({"source_id": "OGMEM", "type": "battle_index", "report_id": match.group(1),
                     "universe_badge": (badge.group(1).strip() if badge else ""),
                     "captured_at": captured, "parser_version": PARSER_VERSION})
    return rows


def normalize(raw_root, out_root, captured):
    records = {}
    archive_files = sorted(f for f in os.listdir(raw_root) if f.startswith("archive-"))
    for name in archive_files:
        for record in parse_archive(read_text(os.path.join(raw_root, name)), captured):
            records.setdefault(record["type"], []).append(record)
    for name in sorted(f for f in os.listdir(raw_root) if f.startswith("report-")):
        report_id = re.sub(r"^report-|\.html(\.gz)?$", "", name)
        battle, units = parse_report(read_text(os.path.join(raw_root, name)), report_id, captured)
        records.setdefault("battle", []).append(battle)
        records.setdefault("battle_unit", []).extend(units)
    normalized = os.path.join(out_root, "normalized")
    os.makedirs(normalized, exist_ok=True)
    for record_type, rows in records.items():
        write_jsonl(os.path.join(normalized, f"{record_type}.jsonl"), rows)
    return {record_type: len(rows) for record_type, rows in records.items()}


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--out", default="storage/rl/external", help="output root (default: storage/rl/external)")
    parser.add_argument("--offline-dir", help="a saved raw dir to re-normalize without network")
    parser.add_argument("--pages", type=int, default=20, help="archive pages to walk (default 20)")
    parser.add_argument("--max-reports", type=int, default=400, help="hard cap on reports fetched")
    parser.add_argument("--jobs", type=int, default=3, help="reports fetched in parallel (default 3)")
    parser.add_argument("--delay", type=float, default=0.3, help="seconds between submissions (default 0.3)")
    parser.add_argument("--self-check", action="store_true")
    args = parser.parse_args(argv)

    if args.self_check:
        self_check()
        return 0

    out_root = os.path.join(args.out, "sources", "OGMEM")
    raw_root = os.path.join(out_root, "raw")
    os.makedirs(raw_root, exist_ok=True)
    captured = now()

    if args.offline_dir:
        source = os.path.join(args.offline_dir, "raw") if os.path.isdir(os.path.join(args.offline_dir, "raw")) else args.offline_dir
        counts = normalize(source, out_root, captured)
        write_manifest(out_root, {"source_id": "OGMEM", "record_counts": counts, "captured_at": captured})
        print(f"OGMEM: {counts} (offline)")
        return 0

    ids = []
    for page in range(1, args.pages + 1):
        document = get(f"{BASE}/cr/archive?page={page}")
        save_text(os.path.join(raw_root, f"archive-{page}.html.gz"), document)
        found = [row["report_id"] for row in parse_archive(document, captured)]
        ids.extend(found)
        if not found:
            break
        time.sleep(args.delay)
    unique = [rid for rid in dict.fromkeys(ids)][:args.max_reports]
    print(f"OGMEM archive: {len(unique)} report(s) from {args.pages} page(s)", flush=True)

    fetched = 0
    with ThreadPoolExecutor(max_workers=max(1, args.jobs)) as pool:
        futures = {}
        for position, report_id in enumerate(unique):
            if os.path.exists(os.path.join(raw_root, f"report-{report_id}.html.gz")):
                continue  # resumable: a report already saved is not fetched again
            if position:
                time.sleep(args.delay)
            futures[pool.submit(get, f"{BASE}/show/{report_id}")] = report_id
        for future in as_completed(futures):
            report_id = futures[future]
            try:
                document = future.result()
            except Exception as error:  # one missing report must not lose the batch
                print(f"SKIP {report_id}: {error}", file=sys.stderr, flush=True)
                continue
            save_text(os.path.join(raw_root, f"report-{report_id}.html.gz"), document)
            fetched += 1
            if fetched % 25 == 0:
                print(f"OGMEM {fetched} report(s)", flush=True)

    counts = normalize(raw_root, out_root, captured)
    manifest = {"source_id": "OGMEM", "access": "public OGMem battle archive (no robots.txt)",
                "captured_at": captured, "parser_version": PARSER_VERSION, "anonymized": True,
                "reports": fetched, "record_counts": counts}
    write_manifest(out_root, manifest)
    print(f"OGMEM: {fetched} report(s); {counts}")
    return 0 if counts.get("battle") else 1


def write_manifest(out_dir, manifest):
    with open(os.path.join(out_dir, "manifest.json"), "w", encoding="utf-8") as handle:
        json.dump(manifest, handle, indent=2, sort_keys=True)


# --self-check: a real parse of tiny report and archive snippets. The live path is the same
# functions, so a break here fails first.
REPORT = """<html><head><title>[Fleet] Shindy vs. Bratwurst 2 &middot; OGame.de Eris &middot; OGMem</title></head>
<body>
<table><tr><th>Attacker</th><th>Defender</th></tr>
<tr><td>Metal</td><td>+11,828,068,696</td><td>\u221217,230,360,096</td></tr>
<tr><td>Total</td><td>+24,278,410,533</td><td>\u221234,733,857,533</td></tr>
<tr><td>Honour points</td><td>\u22122,826,572</td><td>+345,716</td></tr></table>
<table><tr><th>Attacker</th><th>Defender</th></tr>
<tr><td>Battle losses</td><td>\u22123,072,318,000</td><td>\u221231,779,172,000</td></tr>
<tr><td>Loot</td><td>+2,954,685,533</td><td>\u22122,954,685,533</td></tr></table>
<table><tr><th>Metal</th><th>Crystal</th><th>Deuterium</th><th>Total</th></tr>
<tr><td>Loot (50%)</td><td>1,341,562,096</td><td>137,233,471</td><td>1,475,889,966</td><td>2,954,685,533</td></tr>
<tr><td>Debris field</td><td>12,605,346,600</td><td>9,420,115,600</td><td>2,370,580,800</td><td>24,396,043,000</td></tr></table>
<table><tr><th>Type</th><th>Before</th><th>Lost</th></tr><tr><td>Cruiser</td><td>10,000,000</td><td>\u2212105,942</td></tr></table>
<table><tr><th>Type</th><th>Before</th><th>Lost</th></tr><tr><td>Large Cargo</td><td>3,404</td><td>\u22123,404</td></tr></table>
</body></html>"""
ARCHIVE = '<tr data-ogmem-href="/show/1596172"><td class="ogmem-cell-universe"><span class="ogmem-universe-badge">OGame.de Eris</span></td></tr>'


def self_check():
    captured = "2026-10-09T00:00:00Z"
    battle, units = parse_report(REPORT, "1596172", captured)
    assert battle["universe"] == "Eris" and battle["community"] == "de", (battle["community"], battle["universe"])
    assert battle["attacker_result"] == 24278410533 and battle["defender_result"] == -34733857533
    assert battle["honour_attacker"] == -2826572 and battle["honour_defender"] == 345716
    assert battle["attacker_losses"] == -3072318000 and battle["defender_losses"] == -31779172000
    assert battle["loot"] == 2954685533
    assert (battle["loot_metal"], battle["loot_crystal"], battle["loot_deuterium"]) == (1341562096, 137233471, 1475889966)
    assert battle["debris"] == 24396043000 and battle["debris_metal"] == 12605346600
    assert [u["side"] for u in units] == ["attacker", "defender"], "the first fleet table is the attacker's"
    assert units[0]["unit"] == "Cruiser" and units[0]["before"] == 10000000 and units[0]["lost"] == -105942
    assert units[1]["unit"] == "Large Cargo" and units[1]["lost"] == -3404
    # Names are gone: no field carries the title's player or alliance text.
    assert "Shindy" not in json.dumps(battle) and "Bratwurst" not in json.dumps(battle)
    index = parse_archive(ARCHIVE, captured)
    assert index == [{"source_id": "OGMEM", "type": "battle_index", "report_id": "1596172",
                      "universe_badge": "OGame.de Eris", "captured_at": captured, "parser_version": PARSER_VERSION}]
    assert num("\u2013") is None and num("") is None and num("\u221285,000") == -85000
    print("self-check ok (report outcome, both fleets, archive index; every name dropped)")


if __name__ == "__main__":
    sys.exit(main())
