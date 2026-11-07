"""social-gateway: one front door for the cognition engines (SOCIAL-ENGINES-CONTRACT.md).

It never answers for an engine: every answer is an engine's HTTP answer, proxied or replayed from a cache keyed on
the exact request and the engine image that produced it.

Routes
  ANY  /v1/<engine>/<route>   proxy one request to a replica (slot-based round robin, retry on busy/timeout)
  POST /v1/batch              {"items":[item,...]} -> {"results":[answer | {"error":...}, ...]} in order, concurrent
  GET  /v1/stats              cache hits/misses, errors, retries, latency per engine, replica image ids
  GET  /health                gateway liveness

A batch item is either one request  {"engine","route","body"?,"method"?="POST","no_cache"?}
or a stateful sequence              {"engine","steps":[{"route","body"?,"method"?}, ...],"no_cache"?}
run on one replica while it holds that replica's slot (FAtiMA sessions: reload, beliefs, perception, read). A
sequence answers with its last step's answer.
"""

import asyncio
import hashlib
import json
import os
import sqlite3
import time
from collections import defaultdict
from urllib.parse import urlparse

import aiohttp
from aiohttp import web

ENGINES = ["fatima", "psychsim", "cbrkit", "agentos"]
DEFAULTS = {
    "fatima": ("http://fatima3:8000,http://fatima4:8000,http://fatima5:8000,http://fatima6:8000", 1),
    "psychsim": ("http://psychsim:8080", 1),
    "cbrkit": ("http://cbrkit:8080", 8),
    "agentos": ("http://agentos:8080", 4),
}
TIMEOUT = float(os.environ.get("GATEWAY_TIMEOUT", "30"))
RETRIES = int(os.environ.get("GATEWAY_RETRIES", "3"))
CACHE_PATH = os.environ.get("GATEWAY_CACHE", "/cache/gateway.sqlite")
DOCKER_SOCK = os.environ.get("DOCKER_SOCK", "/var/run/docker.sock")
COMPOSE_PROJECT = os.environ.get("COMPOSE_PROJECT", "ogamex-ai-cognition")
# Single requests to these route prefixes mutate engine session state, so they are never cached on their own
# (a whole sequence is cached instead).
NOCACHE_PREFIXES = {e: [p for p in os.environ.get(f"{e.upper()}_NOCACHE_PREFIXES", "").split(",") if p]
                    for e in ENGINES}
NOCACHE_PREFIXES["fatima"] = NOCACHE_PREFIXES["fatima"] or ["/scenarios"]
RETRY_STATUS = {429, 502, 503, 504}


class Busy(Exception):
    pass


class Engine:
    def __init__(self, name):
        urls, conc = DEFAULTS[name]
        self.name = name
        self.urls = [u.strip().rstrip("/") for u in os.environ.get(f"{name.upper()}_URLS", urls).split(",") if u.strip()]
        self.concurrency = int(os.environ.get(f"{name.upper()}_CONCURRENCY", conc))
        self.slots = asyncio.Queue()
        # Interleave so consecutive acquisitions rotate over replicas (round robin), each with `concurrency` slots.
        for _ in range(self.concurrency):
            for u in self.urls:
                self.slots.put_nowait(u)
        self.images = {}  # url -> image id
        self.stats = {"requests": 0, "hits": 0, "misses": 0, "errors": 0, "retries": 0, "lat": [], "per_replica": defaultdict(int)}

    def image_key(self):
        ids = sorted(set(self.images.get(u) or os.environ.get(f"{self.name.upper()}_IMAGE_ID", "unknown") for u in self.urls))
        return ",".join(ids)


class Cache:
    def __init__(self, path):
        os.makedirs(os.path.dirname(path), exist_ok=True)
        self.db = sqlite3.connect(path, check_same_thread=False, isolation_level=None)
        self.db.execute("PRAGMA journal_mode=WAL")
        self.db.execute("PRAGMA synchronous=NORMAL")
        self.db.execute("CREATE TABLE IF NOT EXISTS c (k TEXT PRIMARY KEY, status INT, body BLOB, ts REAL)")

    def get(self, k):
        row = self.db.execute("SELECT status, body FROM c WHERE k=?", (k,)).fetchone()
        return row

    def put(self, k, status, body):
        self.db.execute("INSERT OR REPLACE INTO c VALUES (?,?,?,?)", (k, status, body, time.time()))

    def size(self):
        return self.db.execute("SELECT COUNT(*) FROM c").fetchone()[0]


def canonical(body):
    if body is None:
        return b""
    if isinstance(body, (bytes, bytearray)):
        try:
            body = json.loads(body)
        except ValueError:
            return bytes(body)
    return json.dumps(body, sort_keys=True, separators=(",", ":"), ensure_ascii=False).encode()


def cache_key(engine, steps):
    h = hashlib.sha256()
    h.update(engine.name.encode() + b"\0" + engine.image_key().encode() + b"\0")
    for method, route, body in steps:
        h.update(method.encode() + b"\0" + route.encode() + b"\0" + canonical(body) + b"\0")
    return h.hexdigest()


def norm_route(route):
    return "/" + route.lstrip("/")


class Gateway:
    def __init__(self):
        self.engines = {e: Engine(e) for e in ENGINES}
        self.cache = Cache(CACHE_PATH)
        self.session = None
        self.inflight = {}
        self.started = time.time()

    async def start(self, _app):
        self.session = aiohttp.ClientSession(timeout=aiohttp.ClientTimeout(total=TIMEOUT),
                                             connector=aiohttp.TCPConnector(limit=0))
        await self.refresh_images()
        _app["image_task"] = asyncio.create_task(self.image_loop())

    async def stop(self, _app):
        _app["image_task"].cancel()
        await self.session.close()

    async def image_loop(self):
        while True:
            await asyncio.sleep(30)
            await self.refresh_images()

    async def refresh_images(self):
        """Map each replica host (compose service name) to the image id its container runs, via the Docker API."""
        if not os.path.exists(DOCKER_SOCK):
            return
        try:
            flt = json.dumps({"label": [f"com.docker.compose.project={COMPOSE_PROJECT}"]})
            async with aiohttp.ClientSession(connector=aiohttp.UnixConnector(path=DOCKER_SOCK)) as s:
                async with s.get("http://docker/containers/json", params={"filters": flt}, timeout=aiohttp.ClientTimeout(total=5)) as r:
                    containers = await r.json()
            by_service = {c["Labels"].get("com.docker.compose.service"): c["ImageID"] for c in containers}
            for e in self.engines.values():
                for u in e.urls:
                    host = urlparse(u).hostname
                    if host in by_service:
                        e.images[u] = by_service[host]
        except Exception:  # noqa: BLE001 - image ids stay as last known; the env fallback still applies
            pass

    async def call(self, engine, steps, use_cache):
        """Run steps [(method, route, body)] on one replica slot; return (status, body bytes)."""
        engine.stats["requests"] += 1
        key = None
        if use_cache:
            key = cache_key(engine, steps)
            row = self.cache.get(key)
            if row:
                engine.stats["hits"] += 1
                return row[0], row[1], "hit"
            if key in self.inflight:  # an identical request is already on its way: share its answer
                engine.stats["hits"] += 1
                status, body, _ = await asyncio.shield(self.inflight[key])
                return status, body, "hit"
            engine.stats["misses"] += 1
            fut = self.inflight[key] = asyncio.get_running_loop().create_future()
            try:
                result = await self.fetch(engine, steps, key)
                fut.set_result(result)
                return result
            except BaseException as exc:
                fut.set_exception(exc)
                fut.exception()  # mark retrieved when nobody else waits
                raise
            finally:
                del self.inflight[key]
        return await self.fetch(engine, steps, None)

    async def fetch(self, engine, steps, key):
        t0 = time.perf_counter()
        last = None
        for attempt in range(RETRIES + 1):
            url = await engine.slots.get()
            try:
                status, body = await self.run_steps(url, steps)
                if status in RETRY_STATUS:
                    raise Busy(f"{url} answered {status}")
                engine.stats["per_replica"][url] += 1
                engine.stats["lat"].append(time.perf_counter() - t0)
                if len(engine.stats["lat"]) > 5000:
                    del engine.stats["lat"][:1000]
                if key and 200 <= status < 300:
                    self.cache.put(key, status, body)
                return status, body, "miss" if key else "bypass"
            except (Busy, aiohttp.ClientError, asyncio.TimeoutError) as exc:
                last = exc
                if attempt < RETRIES:
                    engine.stats["retries"] += 1
                    await asyncio.sleep(0.05 * (attempt + 1))
            finally:
                engine.slots.put_nowait(url)
        engine.stats["errors"] += 1
        raise Busy(f"{engine.name}: no replica answered after {RETRIES + 1} attempts: {last!r}")

    async def run_steps(self, url, steps):
        status, body = 0, b""
        for method, route, payload in steps:
            data = None if payload is None else (payload if isinstance(payload, (bytes, bytearray)) else json.dumps(payload).encode())
            async with self.session.request(method, url + route, data=data,
                                            headers={"Content-Type": "application/json", "Accept": "application/json"}) as r:
                status, body = r.status, await r.read()
            if status in RETRY_STATUS or status >= 500:
                return status, body
        return status, body

    def cacheable(self, engine, steps, no_cache):
        if no_cache:
            return False
        if len(steps) == 1:
            route = steps[0][1]
            return not any(route.startswith(p) for p in NOCACHE_PREFIXES[engine.name])
        return True

    def engine(self, name):
        if name not in self.engines or not self.engines[name].urls:
            raise web.HTTPNotFound(text=json.dumps({"error": f"unknown engine {name!r}"}), content_type="application/json")
        return self.engines[name]

    # ------------------------------------------------------------- handlers

    async def proxy(self, request):
        engine = self.engine(request.match_info["engine"])
        route = norm_route(request.match_info["route"])
        if request.query_string:
            route += "?" + request.query_string
        raw = await request.read()
        steps = [(request.method, route, raw if raw else None)]
        no_cache = request.headers.get("X-No-Cache") == "1"
        try:
            status, body, how = await self.call(engine, steps, self.cacheable(engine, steps, no_cache))
        except Busy as exc:
            return web.json_response({"error": str(exc)}, status=503)
        return web.Response(status=status, body=body, content_type="application/json", headers={"X-Cache": how})

    async def batch(self, request):
        try:
            items = (await request.json())["items"]
            assert isinstance(items, list)
        except Exception:  # noqa: BLE001
            return web.json_response({"error": "body must be {\"items\": [...]}"}, status=400)
        header_no_cache = request.headers.get("X-No-Cache") == "1"

        async def one(item):
            try:
                engine = self.engine(item["engine"])
                if "steps" in item:
                    steps = [(s.get("method", "POST").upper(), norm_route(s["route"]), s.get("body")) for s in item["steps"]]
                else:
                    steps = [(item.get("method", "POST").upper(), norm_route(item["route"]), item.get("body"))]
                use = self.cacheable(engine, steps, header_no_cache or item.get("no_cache"))
                status, body, _ = await self.call(engine, steps, use)
                try:
                    answer = json.loads(body or b"null")
                except ValueError:
                    answer = body.decode(errors="replace")
                if not 200 <= status < 300:
                    return {"error": f"HTTP {status}", "status": status, "body": answer}
                return answer
            except web.HTTPException as exc:
                return json.loads(exc.text)
            except Exception as exc:  # noqa: BLE001 - per-item errors never fail the batch
                return {"error": str(exc) or type(exc).__name__}

        results = await asyncio.gather(*(one(i) for i in items))
        return web.json_response({"results": results})

    async def stats(self, _request):
        out = {}
        for e in self.engines.values():
            s = e.stats
            lat = sorted(s["lat"])
            pct = (lambda q: round(lat[min(len(lat) - 1, int(q * len(lat)))] * 1000, 2)) if lat else (lambda q: None)
            out[e.name] = {
                "replicas": e.urls, "concurrency": e.concurrency, "images": e.images, "image_key": e.image_key(),
                "requests": s["requests"], "hits": s["hits"], "misses": s["misses"], "errors": s["errors"],
                "retries": s["retries"], "per_replica": dict(s["per_replica"]),
                "latency_ms": {"mean": round(sum(lat) / len(lat) * 1000, 2) if lat else None, "p50": pct(0.5), "p95": pct(0.95)},
            }
        return web.json_response({"uptime_s": round(time.time() - self.started), "cache_entries": self.cache.size(), "engines": out})

    async def health(self, _request):
        return web.json_response({"status": "ok"})


def app():
    gw = Gateway()
    a = web.Application(client_max_size=64 * 1024 * 1024)
    a.on_startup.append(gw.start)
    a.on_cleanup.append(gw.stop)
    a.router.add_get("/health", gw.health)
    a.router.add_get("/v1/stats", gw.stats)
    a.router.add_post("/v1/batch", gw.batch)
    a.router.add_route("*", "/v1/{engine}/{route:.*}", gw.proxy)
    return a


if __name__ == "__main__":
    web.run_app(app(), host="0.0.0.0", port=int(os.environ.get("PORT", "8099")), access_log=None)
