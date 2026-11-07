# social-gateway

One front door for the cognition engines (FAtiMA, PsychSim, CBRKit, AgentOS), per
`Modules/AI/plan/rl/SOCIAL-ENGINES-CONTRACT.md`. It proxies, fans batches out over replicas and caches answers. It
never computes an answer itself: every answer is an engine's HTTP answer, live or replayed.

## Run

```bash
docker compose -f Modules/AI/docker/cognition/docker-compose.yml up -d --build --no-deps gateway fatima3 fatima4 fatima5 fatima6
```

Port 8099 (`AI_GATEWAY_PORT`). Code: `server.py` (aiohttp, ~300 lines).

## Routes

| Route | Does |
|---|---|
| `ANY /v1/<engine>/<route>` | one request to a replica; answer header `X-Cache: hit|miss|bypass` |
| `POST /v1/batch` | `{"items":[...]}` -> `{"results":[...]}` in item order, concurrent; a failing item is `{"error":...}` |
| `GET /v1/stats` | per engine: replicas, image ids, requests, hits, misses, errors, retries, per-replica counts, latency |
| `GET /health` | liveness |

Batch item: `{"engine","route","body"?,"method"?="POST","no_cache"?}`, or a stateful sequence
`{"engine","steps":[{"route","body"?,"method"?},...]}` that runs on one replica while holding it (FAtiMA sessions:
reload scenario, write beliefs, perceive, read emotions); a sequence answers with its last step's answer.

## Balancing and retries

Each replica has `<ENGINE>_CONCURRENCY` slots (FAtiMA and PsychSim 1: single-threaded servers; CBRKit 8; AgentOS 4).
Requests take a free slot in round-robin order, so a busy replica is never queued on. Connection errors, timeouts
(`GATEWAY_TIMEOUT`, 30 s) and 429/5xx are retried on the next free replica (`GATEWAY_RETRIES`, 3).

Replicas: `FATIMA_URLS`, `PSYCHSIM_URLS`, `CBRKIT_URLS`, `AGENTOS_URLS` (comma separated). FAtiMA defaults to the
dedicated `fatima3..fatima6` so gateway sessions never interleave with the live PHP module's sessions on
`fatima`/`fatima2`. Each FAtiMA replica idles at ~60 MB.

## Cache

SQLite in the `gateway-cache` volume. Key: sha256(engine, engine image ids, method, route, canonical JSON body) for
every step. Image ids are read from the Docker API (socket mounted read-only, refreshed every 30 s), so a rebuilt
engine image starts a fresh cache; `<ENGINE>_IMAGE_ID` is the fallback without the socket. Identical in-flight
requests share one engine call. Only 2xx answers are cached.

Bypass with header `X-No-Cache: 1` or item `"no_cache": true`. Single FAtiMA requests under `/scenarios` mutate
session state and are never cached alone (`FATIMA_NOCACHE_PREFIXES`); whole sequences are, so start a sequence from
a scenario reload.

After rebuilding the fatima image, run `up -d fatima3 fatima4 fatima5 fatima6` so the replicas run the new image.

## Client

`Modules/AI/rl/scripts/engine_gateway.py`: `batch(items, chunk=200, parallel=2, retries=3)`, `call(engine, route,
body)`, `stats()`. `AI_GATEWAY_URL` defaults to `http://localhost:8099`.
