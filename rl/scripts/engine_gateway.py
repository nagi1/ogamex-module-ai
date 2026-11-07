#!/usr/bin/env python3
"""Client for the cognition social-gateway (Modules/AI/docker/cognition/gateway, SOCIAL-ENGINES-CONTRACT.md).

    from engine_gateway import batch
    results = batch([{"engine": "psychsim", "route": "/evaluate", "body": {"temptation": 1.2}}, ...])

Items are sent in chunks to POST /v1/batch; results come back in item order. A chunk that fails as a whole (gateway
down, timeout) is retried with backoff; per-item engine errors come back as {"error": ...} and are not retried here
(the gateway already retried busy replicas). Stdlib only.
"""

import json
import os
import time
import urllib.request
from concurrent.futures import ThreadPoolExecutor

GATEWAY_URL = os.environ.get("AI_GATEWAY_URL", "http://localhost:8099")


def _post(url, payload, timeout, no_cache):
    headers = {"Content-Type": "application/json"}
    if no_cache:
        headers["X-No-Cache"] = "1"
    req = urllib.request.Request(url, data=json.dumps(payload).encode(), headers=headers, method="POST")
    return json.loads(urllib.request.urlopen(req, timeout=timeout).read())


def _chunk(items, url, timeout, retries, no_cache):
    delay = 0.5
    for attempt in range(retries + 1):
        try:
            results = _post(url + "/v1/batch", {"items": items}, timeout, no_cache)["results"]
            if len(results) != len(items):
                raise RuntimeError(f"gateway answered {len(results)} results for {len(items)} items")
            return results
        except (OSError, ValueError, KeyError, RuntimeError):
            if attempt == retries:
                raise
            time.sleep(delay)
            delay *= 2


def batch(items, chunk=200, parallel=2, retries=3, timeout=300, no_cache=False, url=None):
    """Send items through the gateway; return one result per item, in order."""
    url = (url or GATEWAY_URL).rstrip("/")
    items = list(items)
    chunks = [items[i:i + chunk] for i in range(0, len(items), chunk)]
    with ThreadPoolExecutor(max(1, parallel)) as pool:
        parts = pool.map(lambda c: _chunk(c, url, timeout, retries, no_cache), chunks)
        return [r for part in parts for r in part]


def call(engine, route, body=None, no_cache=False, url=None, timeout=60):
    """One proxied request: POST /v1/<engine>/<route>."""
    return _post(f"{(url or GATEWAY_URL).rstrip('/')}/v1/{engine}/{route.lstrip('/')}", body, timeout, no_cache)


def stats(url=None):
    return json.loads(urllib.request.urlopen(f"{(url or GATEWAY_URL).rstrip('/')}/v1/stats", timeout=10).read())


if __name__ == "__main__":
    print(json.dumps(stats(), indent=2))
