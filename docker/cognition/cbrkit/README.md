# CBRKit experience driver

Optional, module-owned headless driver behind `Modules\AI\Contracts\ExperienceEngine`.
It is a **stateless similarity scorer**: the module sends the bounded, owner-scoped
casebase with every request and keeps the canonical cases in its own tables, so the
driver can never become the case database.

Nothing in the Phase 3 baseline depends on this image. With the driver absent,
disabled or failing, the module uses `NativeExperienceEngine`.

## Pinned implementation

| Item | Value |
| --- | --- |
| Package | `cbrkit[api]` — <https://pypi.org/project/cbrkit/> |
| Version | `1.6.0` |
| License | MIT |
| Runtime | CPython ≥ 3.13 (`python:3.13-slim`) |
| Endpoint | `POST /retrieve` (FastAPI), default port 8080 |
| Generative calls | none — no provider, embedding or synthesis extra is installed |

## Run it

```bash
docker compose -f Modules/AI/docker/cognition/docker-compose.yml up -d --build
```

Then point the module at it and opt in:

```dotenv
AI_EXPERIENCE_DRIVER=cbrkit
AI_EXPERIENCE_CBRKIT_URL=http://host.docker.internal:8091
```

Confirm the contract with the conformance command:

```bash
php artisan ai:experience-conformance --confirm
```

## Why the casebase travels in the request

`POST /retrieve` accepts `{casebase, queries}` in the body. The module already owns
outcome cases, their owner scope, feature/ruleset versions and the deterministic
tie-break, so sending them per request keeps one authoritative store and makes the
driver replaceable without a migration. The retriever in `retriever.py` is the
driver's own measure: a per-feature weighted similarity where object and planet ids
are categorical identities and the target level is numeric. It is deliberately not
a port of the module's uniform-mean formula, so a native-versus-driver comparison
can observe a real difference.

The held-out measurement that decides whether the driver earns the hybrid default
lives in `eval_retriever.py`; run it inside the container with
`python /driver/eval_retriever.py`.

## Server deployment

On a shared server, do not publish the port publicly. Either bind the service to the
private network the application uses, or run this Compose file on the application
host and keep `AI_CBRKIT_BIND=127.0.0.1` with the application configured to reach the
published port directly.
