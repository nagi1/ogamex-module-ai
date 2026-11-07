# Social engines contract (phase B, 6 Nov 2026)

Owner direction: use every cognition engine (FAtiMA, PsychSim, CBRKit, AgentOS) for the social world, at full depth,
even if it slows training; put them behind a reverse proxy with batched requests. AGENTS rule stands: we **call** the
engines, we never port or duplicate their algorithms.

## Gateway

`social-gateway` service in `Modules/AI/docker/cognition/docker-compose.yml`, port **8099**.

- `POST /v1/<engine>/<route>` proxies one request to a replica of that engine (round robin, retries on busy).
- `POST /v1/batch` body `{"items":[{"engine":"psychsim","route":"/social/partner","body":{...}}, ...]}` answers
  `{"results":[{...} | {"error":"..."}]}` in order, fanned out concurrently over the replicas.
- Cache: a request's answer is cached by sha256(engine, route, canonical body, engine image id); `GET /v1/stats` gives
  hits, misses, latency per engine. A cache is valid only for the same engine image (rebuild = new cache).
- Engines that answer one request at a time (FAtiMA) run as several replicas; the gateway spreads load.

## Social routes (additive: existing routes and their answers stay byte-identical; the PHP module uses them live)

Every social route takes a batch: `{"items":[...]}` -> `{"results":[...]}`. Inputs are only what a real player can
see (public points, own losses, attacks received, public treaty events, messages received) - never hidden state.

| Engine | Route | Item in | Item out |
|---|---|---|---|
| FAtiMA | `/social/appraise` | `{persona, event, actor_relation, intensity}` events: betrayed, helped, attacked, threatened, pact_offered, pact_kept, tribute_demanded, coup, leak_heard | `{emotions:{name:intensity}, dominant, mood}` |
| FAtiMA | `/social/exchange` | `{persona, other, relation:{trust,respect,fear}, options:[...]}` (social exchanges, CiF-style if the toolkit offers them) | `{choice, scores}` |
| PsychSim | `/social/partner` | `{broken, kept, points_ratio, threat, my_traits}` | `{p_loyal, accept_pact, betray_now}` (recursive belief over partner type) |
| CBRKit | `/social/similar` | `{situation:{...}, k}` over a social case base built from simulator diplomacy logs | `{cases:[{id, similarity, outcome}]}` |
| AgentOS | `/social/recall` | `{scopeId, query, memories:[...]}` relationship memories (who did what to me) | ranked memories with strength |

## Use

- Simulator (rl/ogsim): reads the gateway (batched, cached) behind arm `engines`; a recorded table is the fallback
  and the validation reference (`rl/scripts/engine_tables.py --validate`, target ≥ 95% agreement).
- Live game: the PHP module calls the same routes through the gateway.

## Living minds, not tables (owner, 6 Nov 17:10)

The AI is not static. Every account carries a **mind** that the simulator (and the live module) keeps and sends with
each engine call; the engines compute, the state travels in the request, nothing is stored server-side.

```
mind = {
  persona: {12 traits},                       # drift with experience
  mood: float, emotions: [{name, intensity, since}],
  relations: {other: {trust, liking, fear, respect, debt}},   # friendship vs interest lives here
  memories: [{id, event, actor, at, valence, weight}],        # episodic: harm, help, sacrifice, promise, betrayal
  beliefs: {other: {p_loyal, psychsim_state}},                 # carried call to call
}
```

Decisions read past (memories, relations), present (the event, the visible situation) and future (value of the
relationship over a horizon, expected gain of betrayal or war), and include sacrifice (aid an ally or keep a pact at a
cost now because of trust and future value). Recorded tables remain only as validation references and a fallback when
an engine is down.
