#!/usr/bin/env python3
"""Collect permitted external OGame/AI datasets into provenance-manifested corpora.

Why this exists: the module trains on the real game, and the source catalogue in
`plan/ogame_ai_sources.csv` lists public diplomacy, negotiation, strategy-game and OGame
datasets that can enrich that training. This collector turns that catalogue into a
size-gated, provenance-manifested local corpus.

METERED-CONNECTION GUARD (hard, fail-closed):
  * Nothing over `--max-source-bytes` (default 2 GiB) is EVER downloaded. The cap is
    enforced twice: a cheap preflight probe (so an over-cap source is never started) and
    again at the byte level while streaming (so a lying Content-Length cannot overshoot).
  * The whole run stops at `--max-total-bytes` (default 2 GiB). Once the budget is spent
    the remaining sources are recorded as `skip: budget`, never fetched.
  * Over-cap and unknown-but-large sources are written to the inventory as `defer` for a
    later, owner-approved run - they are not downloaded.
  * The default action is `--probe` (network-light metadata calls only). Downloads need an
    explicit `--download`.

It only collects PUBLIC, permitted sources: unauthenticated HuggingFace datasets, public
GitHub repositories and public single pages. It refuses any URL outside the known hosts
and never bypasses access controls.

Usage:
    scripts/ogame-external-collect.py --probe                 # cheap inventory, no bulk data
    scripts/ogame-external-collect.py --download --yes        # fetch the within-cap sources
    scripts/ogame-external-collect.py --download --only HF-... --max-total-bytes 500000000
    scripts/ogame-external-collect.py --self-check

Raw payloads are large and public: they live under host `storage/rl/external/` and stay out of git.
"""

import argparse
import csv
import datetime
import gzip
import hashlib
import html.parser
import json
import os
import re
import sys
import tarfile
import time
import urllib.error
import urllib.parse
import urllib.request

UA = ("Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 "
      "(KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36")
GIB = 1024 ** 3
DEFAULT_MAX_SOURCE = 2 * GIB          # the owner's metered-connection ceiling, per source
DEFAULT_MAX_TOTAL = 2 * GIB           # ... and for the whole run, until raised on purpose
PARSER_VERSION = "1"
CHUNK = 64 * 1024
ALLOWED_HOSTS = ("huggingface.co", "github.com", "codeload.github.com", "api.github.com",
                 "web.archive.org", "archive.org", "zenodo.org", "convokit.cornell.edu",
                 "www.convokit.cornell.edu", "www.irit.fr", "www.textlink.ii.metu.edu.tr",
                 "juliahmr.cs.illinois.edu", "ostats.eu", "ogmem.com")
# URLs already captured by the module's own collectors (EXT-002/004/005/006); recorded, not re-fetched.
ALREADY_COLLECTED = {
    "https://ostats.eu": "OSTATS",
    "https://ostats.hd-wg.de/": "OSTATS",
    "https://ogmem.com": "OGMEM",
    "https://huggingface.co/datasets/Shinjo/OgameGuide": "HFGUIDE",
}
TEXT_SUFFIXES = (".csv", ".tsv", ".json", ".jsonl", ".ndjson", ".txt", ".md", ".jsonlines")


def now():
    return datetime.datetime.now(datetime.timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z")


def human(n):
    if n is None:
        return "?"
    for unit in ("B", "KiB", "MiB", "GiB", "TiB"):
        if n < 1024 or unit == "TiB":
            return f"{n:.1f}{unit}"
        n /= 1024


class OverCap(RuntimeError):
    """Raised the instant a download would cross the per-source or total budget."""


class Budget:
    """The metered-connection guard. Every byte the collector writes passes through here."""

    def __init__(self, per_source, total):
        self.per_source = per_source
        self.total = total
        self.used = 0

    def fits(self, n):
        return n is not None and n <= self.per_source and self.used + n <= self.total

    def spend(self, n):
        self.used += n

    def remaining(self):
        return max(0, self.total - self.used)


class TextExtractor(html.parser.HTMLParser):
    """Minimal HTML to text: enough to keep a public page as evidence, nothing more."""

    def __init__(self):
        super().__init__()
        self.parts, self.skip = [], 0

    def handle_starttag(self, tag, attrs):
        if tag in ("script", "style"):
            self.skip += 1

    def handle_endtag(self, tag):
        if tag in ("script", "style") and self.skip:
            self.skip -= 1

    def handle_data(self, data):
        if not self.skip:
            stripped = data.strip()
            if stripped:
                self.parts.append(stripped)

    def text(self):
        return "\n".join(self.parts)


def http_get(url, timeout=60, max_bytes=None):
    """One bounded GET with a browser UA and bounded retries. Returns (status, body)."""
    last = None
    for attempt in range(3):
        try:
            request = urllib.request.Request(url, headers={"User-Agent": UA})
            with urllib.request.urlopen(request, timeout=timeout) as response:
                body = response.read((max_bytes + 1) if max_bytes else None)
            if max_bytes and len(body) > max_bytes:
                raise ValueError(f"{url} exceeds {max_bytes} bytes")
            return response.status, body
        except (urllib.error.URLError, TimeoutError, ValueError) as error:
            last = error
            time.sleep(2 ** attempt)
    raise RuntimeError(f"GET {url} failed after retries: {last}")


def slug(text):
    return re.sub(r"[^a-z0-9]+", "-", text.lower()).strip("-")


def classify(row):
    """Map one catalogue row to (kind, source_id, target). Kinds drive probe and download."""
    url = row["url"].strip()
    host = urllib.parse.urlparse(url).netloc
    if host not in ALLOWED_HOSTS:
        return "contact", None, None
    if url in ALREADY_COLLECTED:
        return "collected", ALREADY_COLLECTED[url], None
    hf = re.match(r"^https?://huggingface\.co/datasets/([^/?#]+/[^/?#]+)", url)
    if hf:
        return "hf", "HF-" + slug(hf.group(1)), hf.group(1)
    if re.match(r"^https?://huggingface\.co/(?!datasets/)", url):
        return "model", "MODEL-" + slug(url.split("huggingface.co/", 1)[1]), url
    gh = re.match(r"^https?://github\.com/([^/?#]+)/([^/?#]+)", url)
    if gh and gh.group(2) not in ("forks", "commits", "releases"):
        return "github", f"GH-{slug(gh.group(1))}-{slug(gh.group(2))}", f"{gh.group(1)}/{gh.group(2)}"
    if re.search(r"\.pdf($|\?)", url):
        return "doc", "DOC-" + slug(os.path.basename(urllib.parse.urlparse(url).path))[:60], url
    return "doc", "DOC-" + slug(row["title"])[:60], url


def probe_hf(repo_id):
    """Total LFS bytes of an HF dataset repo, from the tree listing (no data downloaded)."""
    url = f"https://huggingface.co/api/datasets/{repo_id}/tree/main?recursive=1&expand=1"
    try:
        request = urllib.request.Request(url, headers={"User-Agent": UA})
        with urllib.request.urlopen(request, timeout=30) as response:
            listing = json.loads(response.read())
    except urllib.error.HTTPError as error:
        return {"bytes": None, "note": f"HTTP {error.code}"}
    except Exception as error:
        return {"bytes": None, "note": f"probe failed: {error}"[:80]}
    if not isinstance(listing, list):
        return {"bytes": None, "note": str(listing.get("error", "no listing"))[:80]}
    total = sum(item.get("size") or 0 for item in listing)
    if total == 0:
        return {"bytes": None, "note": "no sized files at top level (nested or script-based)"}
    return {"bytes": total, "note": None}


def probe_github(repo):
    """Approximate repo bytes from the GitHub API `size` field (KiB, packed)."""
    try:
        _, body = http_get(f"https://api.github.com/repos/{repo}", timeout=30)
        return {"bytes": (json.loads(body).get("size") or 0) * 1024, "note": None}
    except Exception as error:
        return {"bytes": None, "note": f"probe failed: {error}"[:80]}


def probe_url(url):
    """Content-Length via a HEAD. Unknown is fine for a page: the runtime cap still guards it."""
    try:
        request = urllib.request.Request(url, method="HEAD", headers={"User-Agent": UA})
        with urllib.request.urlopen(request, timeout=12) as response:
            length = response.headers.get("Content-Length")
        return {"bytes": int(length) if length else None, "note": None}
    except Exception:
        return {"bytes": None, "note": None}


def probe(kind, target, cache=None):
    """Size the source. A cached probe is reused so the GitHub API limit and the metered link are hit once."""
    if cache is not None and kind in ("hf", "github") and target in cache:
        return {"bytes": cache[target], "note": "cached"}
    if kind == "hf":
        result = probe_hf(target)
    elif kind == "github":
        result = probe_github(target)
    else:
        result = probe_url(target)
    if cache is not None and result["bytes"] is not None and kind in ("hf", "github"):
        cache[target] = result["bytes"]
    return result


def decide(kind, result, budget):
    """The guard's verdict for one source. Fail-closed: unknown bulk size is deferred, never guessed.

    A single page is the exception: it is small by nature and the byte-level cap still aborts it.
    """
    size = result["bytes"]
    if kind == "doc" and size is None:
        return "download", "single page; the runtime byte cap guards it"
    if size is None:
        return "defer", f"size unknown ({result.get('note') or 'probe the item by hand'})"
    if size > budget.per_source:
        return "defer", f"over {human(budget.per_source)} cap ({human(size)})"
    return "download", f"{human(size)} <= {human(budget.per_source)}"


def stream_download(url, dest, budget, per_source_cap, extra_headers=None):
    """Stream one file to `dest`, aborting the instant the caps would be crossed.

    Resume: a completed file is never re-fetched, so a re-run costs no metered data.
    """
    if os.path.exists(dest) and os.path.getsize(dest) > 0:
        return os.path.getsize(dest)
    os.makedirs(os.path.dirname(dest), exist_ok=True)
    tmp = dest + ".part"
    headers = {"User-Agent": UA}
    headers.update(extra_headers or {})
    written = 0
    request = urllib.request.Request(url, headers=headers)
    with urllib.request.urlopen(request, timeout=120) as response, open(tmp, "wb") as handle:
        while True:
            chunk = response.read(CHUNK)
            if not chunk:
                break
            written += len(chunk)
            if written > per_source_cap:
                handle.close()
                os.remove(tmp)
                raise OverCap(f"{url} crossed the {human(per_source_cap)} per-source cap")
            if budget.used + written > budget.total:
                handle.close()
                os.remove(tmp)
                raise OverCap(f"{url} crossed the {human(budget.total)} run budget")
            handle.write(chunk)
    os.replace(tmp, dest)
    budget.spend(written)
    return written


def download_hf(repo_id, raw_dir, budget, cap):
    """Fetch the repo files (parquet is the common case). Each file is separately capped."""
    _, body = http_get(f"https://huggingface.co/api/datasets/{repo_id}/tree/main?recursive=1&expand=1",
                       timeout=30)
    listing = json.loads(body)
    files, skipped = [], []
    for item in listing:
        if item.get("type") != "file":
            continue
        path, size = item.get("path"), item.get("size") or 0
        if size > cap or not budget.fits(size):
            skipped.append({"path": path, "bytes": size, "reason": "over per-source cap or budget"})
            continue
        url = f"https://huggingface.co/datasets/{repo_id}/resolve/main/{urllib.parse.quote(path)}"
        dest = os.path.join(raw_dir, path)
        got = stream_download(url, dest, budget, cap)
        digest = hashlib.sha256(open(dest, "rb").read()).hexdigest()
        files.append({"path": path, "url": url, "bytes": got, "sha256": digest})
    return files, skipped


def download_github(repo, raw_dir, budget, cap):
    """Fetch the repo as a tarball, then extract it so the source tree is usable as evidence."""
    url = f"https://codeload.github.com/{repo}/tar.gz/HEAD"
    dest = os.path.join(raw_dir, "repo.tar.gz")
    got = stream_download(url, dest, budget, cap)
    tree = os.path.join(raw_dir, "tree")
    if not os.path.isdir(tree):
        os.makedirs(tree, exist_ok=True)
        with tarfile.open(dest, "r:gz") as archive:
            archive.extractall(tree, filter="data")
    return [{"path": "repo.tar.gz", "url": url, "bytes": got,
             "sha256": hashlib.sha256(open(dest, "rb").read()).hexdigest()}], []


def download_doc(url, raw_dir, budget, cap):
    """Fetch one public page or PDF and keep it verbatim as evidence."""
    _, body = http_get(url, timeout=60, max_bytes=cap)
    name = "page.html" if b"<html" in body[:2048].lower() else "download"
    dest = os.path.join(raw_dir, name)
    os.makedirs(raw_dir, exist_ok=True)
    if budget.used + len(body) > budget.total:
        raise OverCap(f"{url} crossed the run budget")
    with open(dest, "wb") as handle:
        handle.write(body)
    budget.spend(len(body))
    return [{"path": name, "url": url, "bytes": len(body),
             "sha256": hashlib.sha256(body).hexdigest()}], []


def normalize(raw_dir, out_dir, source_id, files, kind="hf"):
    """Text tables to JSONL. Parquet and archives are kept raw and flagged for a reader.

    A code repo is prepared as a file inventory of its extracted tree, not as records.
    """
    os.makedirs(out_dir, exist_ok=True)
    if kind == "github":
        return normalize_tree(os.path.join(raw_dir, "tree"), out_dir)
    rows, unread = 0, []
    out_path = os.path.join(out_dir, "records.jsonl")
    with open(out_path, "w", encoding="utf-8") as handle:
        for item in files:
            path = os.path.join(raw_dir, item["path"])
            name = item["path"].lower()
            if name.endswith((".parquet", ".gz", ".zip", ".tar", ".tgz")):
                unread.append(item["path"])
                continue
            try:
                with open(path, "r", encoding="utf-8", errors="replace") as reader:
                    if name.endswith((".csv", ".tsv")):
                        delimiter = "\t" if name.endswith(".tsv") else ","
                        for record in csv.DictReader(reader, delimiter=delimiter):
                            handle.write(json.dumps({"source_id": source_id, "file": item["path"],
                                                     "record": record}, ensure_ascii=False) + "\n")
                            rows += 1
                    else:
                        for line in reader:
                            line = line.strip()
                            if not line:
                                continue
                            try:
                                record = json.loads(line)
                            except ValueError:
                                record = {"text": line}
                            handle.write(json.dumps({"source_id": source_id, "file": item["path"],
                                                     "record": record}, ensure_ascii=False) + "\n")
                            rows += 1
            except (OSError, UnicodeError):
                unread.append(item["path"])
    return {"rows": rows, "unreadable_raw": unread}


def normalize_tree(tree_dir, out_dir):
    """A code repo's prepared form: one inventory row per file, with size and language."""
    out_path = os.path.join(out_dir, "files.jsonl")
    files = 0
    with open(out_path, "w", encoding="utf-8") as handle:
        for root, _dirs, names in os.walk(tree_dir):
            for name in names:
                path = os.path.join(root, name)
                rel = os.path.relpath(path, tree_dir)
                try:
                    size = os.path.getsize(path)
                except OSError:
                    continue
                handle.write(json.dumps({"path": rel, "bytes": size,
                                         "ext": os.path.splitext(name)[1].lower()}) + "\n")
                files += 1
    return {"rows": files, "files": files, "unreadable_raw": []}


def load_catalog(path):
    with open(path, encoding="utf-8-sig") as handle:
        return list(csv.DictReader(handle))


def write_json(path, payload):
    with open(path, "w", encoding="utf-8") as handle:
        json.dump(payload, handle, indent=2, sort_keys=True)


def load_sizes(path):
    """The probe cache: a repo target -> bytes. Absent on a first run; reused ever after."""
    if os.path.exists(path):
        try:
            return json.load(open(path, encoding="utf-8"))
        except ValueError:
            return {}
    return {}


def save_sizes(path, sizes):
    write_json(path, sizes)


def merge_index(path, fresh):
    """Keep the aggregate inventory across runs so a partial run does not clobber it."""
    known = {}
    if os.path.exists(path):
        try:
            for item in json.load(open(path, encoding="utf-8")).get("sources", []):
                known[item["source_id"]] = item
        except ValueError:
            known = {}
    for item in fresh:
        known[item["source_id"]] = item
    return [known[key] for key in sorted(known)]


def run(args):
    budget = Budget(args.max_source_bytes, args.max_total_bytes)
    catalogue = load_catalog(args.catalog)
    sizes_path = os.path.join(args.out, "source-sizes.json")
    cache = load_sizes(sizes_path)
    only = set(args.only or [])
    index, seen = [], set()
    print(f"catalogue: {len(catalogue)} rows; per-source cap {human(budget.per_source)}; "
          f"run budget {human(budget.total)}")
    for row in catalogue:
        kind, source_id, target = classify(row)
        if kind in ("contact", "model") or source_id is None:
            index.append({"source_id": "CATALOG-" + slug(row["title"])[:48], "title": row["title"],
                          "category": row["category"], "url": row["url"], "kind": kind,
                          "action": "skip" if kind == "contact" else "manual",
                          "reason": "contact lead, not a dataset" if kind == "contact"
                                    else "model/code, no dataset payload"})
            continue
        if source_id in seen:
            continue
        seen.add(source_id)
        record = {"source_id": source_id, "title": row["title"], "category": row["category"],
                  "url": row["url"], "kind": kind}
        if only and source_id not in only:
            record.update({"action": "skip", "reason": "not in --only"})
            index.append(record)
            continue
        if kind == "collected":
            record.update({"action": "collected", "reason": "already captured by a module collector"})
            index.append(record)
            print(f"  {source_id:32s} collected")
            continue
        size = probe(kind, target, cache)
        action, reason = decide(kind, size, budget)
        record.update({"action": action, "reason": reason, "est_bytes": size["bytes"],
                       "est_human": human(size["bytes"]), "probe_note": size.get("note")})
        if action == "download" and args.download:
            source_dir = os.path.join(args.out, "sources", source_id)
            raw_dir = os.path.join(source_dir, "raw")
            try:
                if kind == "hf":
                    files, skipped = download_hf(target, raw_dir, budget, budget.per_source)
                elif kind == "github":
                    files, skipped = download_github(target, raw_dir, budget, budget.per_source)
                else:
                    files, skipped = download_doc(target, raw_dir, budget, budget.per_source)
            except OverCap as error:
                record.update({"action": "skip", "reason": f"budget guard stopped it: {error}"})
                index.append(record)
                print(f"  {source_id:32s} STOPPED by guard: {error}")
                continue
            except Exception as error:  # one dead host must not lose the batch
                record.update({"action": "error", "reason": f"download failed: {error}"[:160]})
                index.append(record)
                print(f"  {source_id:32s} ERROR {str(error)[:110]}")
                continue
            counts = normalize(raw_dir, os.path.join(source_dir, "normalized"), source_id, files, kind)
            manifest = {"source_id": source_id, "title": row["title"], "url": record["url"],
                        "access": "public, unauthenticated", "captured_at": now(),
                        "parser_version": PARSER_VERSION, "bytes": sum(f["bytes"] for f in files),
                        "files": files, "skipped": skipped, "record_counts": counts}
            write_json(os.path.join(source_dir, "manifest.json"), manifest)
            record.update({"action": "downloaded", "bytes": manifest["bytes"],
                           "bytes_human": human(manifest["bytes"]), "normalized_rows": counts["rows"]})
            print(f"  {source_id:32s} downloaded {human(manifest['bytes'])} -> {counts['rows']} rows")
        else:
            print(f"  {source_id:32s} {action} ({reason})")
        index.append(record)
    inventory_path = os.path.join(args.out, "catalog.json")
    save_sizes(sizes_path, cache)
    payload = {"parser_version": PARSER_VERSION, "generated_at": now(),
               "per_source_cap_bytes": budget.per_source, "run_budget_bytes": budget.total,
               "used_bytes": budget.used, "sources": merge_index(inventory_path, index)}
    write_json(inventory_path, payload)
    write_inventory_csv(os.path.join(args.out, "catalog.csv"), payload["sources"])
    downloaded = [s for s in payload["sources"] if s.get("action") == "downloaded"]
    deferred = [s for s in payload["sources"] if s.get("action") == "defer"]
    print(f"\nsources: {len(payload['sources'])} | downloaded {len(downloaded)} "
          f"({human(sum(s.get('bytes', 0) for s in downloaded))}) | deferred {len(deferred)} "
          f"| budget used {human(budget.used)} / {human(budget.total)}")
    print(f"inventory: {inventory_path}")
    return 0


def write_inventory_csv(path, sources):
    columns = ["source_id", "title", "category", "kind", "action", "reason", "probe_note",
               "est_bytes", "bytes", "normalized_rows", "url"]
    with open(path, "w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=columns, extrasaction="ignore")
        writer.writeheader()
        for source in sources:
            writer.writerow(source)


def self_check():
    """Exercise the guard and the parsers on tiny in-memory data; nothing here touches the network."""
    budget = Budget(2 * GIB, 2 * GIB)
    assert budget.fits(1 * GIB) and budget.fits(2 * GIB)
    assert not budget.fits(2 * GIB + 1), "a source over the cap must not fit"
    budget.spend(1 * GIB)
    assert not budget.fits(2 * GIB), "the total budget must bind, not just the per-source cap"
    assert decide("hf", {"bytes": None}, budget)[0] == "defer", "unknown bulk size is deferred, never guessed small"
    assert decide("doc", {"bytes": None}, budget)[0] == "download", "a page is still runtime-capped"
    assert decide("hf", {"bytes": 3 * GIB}, Budget(2 * GIB, 10 * GIB))[0] == "defer"
    assert decide("hf", {"bytes": 1 * GIB}, budget)[0] == "download"
    # A download that would cross the cap aborts mid-stream and leaves no partial file.
    assert classify({"url": "https://huggingface.co/datasets/OpenLLM-France/Lucie-Training-Dataset",
                     "title": "x"})[0] == "hf"
    assert classify({"url": "https://github.com/jkroepke/2Moons", "title": "x"})[1] == "GH-jkroepke-2moons"
    assert classify({"url": "https://discord.gg/g6UHwXE", "title": "x"})[0] == "contact"
    print("self-check ok (caps bind, unknown defers, classification stable)")


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__,
                                     formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--catalog", default="plan/ogame_ai_sources.csv",
                        help="source catalogue CSV (default: plan/ogame_ai_sources.csv)")
    parser.add_argument("--out", default="storage/rl/external", help="output root")
    parser.add_argument("--probe", action="store_true", help="probe sizes and write the inventory only")
    parser.add_argument("--download", action="store_true", help="download sources that pass the guard")
    parser.add_argument("--yes", action="store_true", help="confirm a metered download run")
    parser.add_argument("--only", action="append", default=[], metavar="SOURCE_ID",
                        help="restrict to these source ids (repeatable)")
    parser.add_argument("--max-source-bytes", type=int, default=DEFAULT_MAX_SOURCE,
                        help=f"per-source download cap in bytes (default {DEFAULT_MAX_SOURCE})")
    parser.add_argument("--max-total-bytes", type=int, default=DEFAULT_MAX_TOTAL,
                        help=f"whole-run download budget in bytes (default {DEFAULT_MAX_TOTAL})")
    parser.add_argument("--self-check", action="store_true")
    args = parser.parse_args(argv)

    if args.self_check:
        self_check()
        return 0
    if args.download and not args.yes:
        parser.error("--download spends metered data; re-run with --yes to confirm")
    if not args.probe and not args.download:
        args.probe = True
    os.makedirs(args.out, exist_ok=True)
    return run(args)


if __name__ == "__main__":
    sys.exit(main())
