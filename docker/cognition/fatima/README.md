# FAtiMA / CiF affect driver

Optional, module-owned headless driver behind `Modules\AI\Contracts\AffectEngine`.
Nothing in the Phase 3 baseline depends on it: with the driver absent, disabled or
failing, the module uses `NativeAffectEngine`.

## Pinned implementation

| Item | Value |
| --- | --- |
| Location | `docker/cognition/fatima/toolkit/` — vendored and maintained in this module |
| Upstream | `nagi1/FAtiMAtry` @ `c70f825e138d6ea5f6e58b8e382a2b93d51ab005` (fork of `jtaaaaay/FAtiMAtry`) |
| Vendored engine | `GAIPS/FAtiMA-Toolkit` @ `56b7cbd9` |
| License | Apache-2.0 — see `toolkit/FAtiMA-Toolkit/License.txt` |
| Runtime | .NET 8 (`sdk:8.0` build → `runtime:8.0` run) |
| Server | `Applications/FAtiMAHTTPServer`, `HttpListener`, HTTP + HTTPS |

## Vendored source and maintenance

The whole tracked upstream tree is vendored with no `.git`, so this module owns and
maintains it. Nothing is fetched at build time.

Deliberate changes against the vendored fork:

| Change | Why |
| --- | --- |
| `Applications/FAtiMAHTTPServer/FAtiMAHTTPServer.csproj` retargeted `netcoreapp3.0` → `net8.0` | `netcoreapp3.0` is EOL with no supported runtime image |
| `Applications/CiFSeeder/` added | CiF assets must be authored through the API; hand-written JSON loads as an empty exchange set |
| Root `docker-compose.yml` and `FAtiMA-Toolkit/Dockerfile` removed | Both defined a competing stack; this directory's `Dockerfile` and the cognition Compose file are authoritative |

Build output (`bin/`, `obj/`, `Binaries/`) is excluded via `.gitignore` and is never
committed.

`AuthoringTools/` (WinForms) and `Web/WebAPIWF` (Windows Workflow) are present but
never built: they cannot compile on Linux and the solution file must not be built.
They account for roughly half the vendored bytes; they can be pruned if repository
size ever matters.

Regenerate scenarios after editing the seeder. The toolkit is mounted **read-only**
and built inside a throwaway copy: a direct `dotnet run` against the mounted tree
writes root-owned `bin/`, `obj/` and `Binaries/` into the vendored source, and those
directories need a container to delete again.

```bash
docker run --rm \
  -v "$PWD/toolkit:/src:ro" -v /tmp/cifout:/out -w /work \
  mcr.microsoft.com/dotnet/sdk:8.0 sh -c '
    cp -a /src/. /work/ &&
    dotnet build /work/FAtiMA-Toolkit/Applications/CiFSeeder/CiFSeeder.csproj -c Release -v q --nologo &&
    dotnet /work/FAtiMA-Toolkit/Applications/CiFSeeder/bin/Release/net8.0/CiFSeeder.dll /out/high 5 CiFHigh'
```

The seeder takes `<outputDir> <rapport> <scenarioName>`.

## Run it

```bash
docker compose -f Modules/AI/docker/cognition/docker-compose.yml up -d fatima
```

Set the module to use it:

```dotenv
AI_AFFECT_DRIVER=fatima
AI_AFFECT_FATIMA_URL=http://host.docker.internal:8092
```

## What the engine actually exposes

Enumerated from the running server. Every resource is scoped by scenario and
instance:

| Resource | Methods | Maps to |
| --- | --- | --- |
| `/adminkey` | POST | driver auth (see below) |
| `/scenarios` | GET, POST, DELETE, RESET | scenario lifecycle |
| `/scenarios/{name}/key` | GET | per-scenario auth key |
| `/scenarios/{name}/instances` | GET, POST, DELETE | per-character instance lifecycle |
| `.../instances/{id}/tick` | GET, POST | emotion decay / time advance |
| `.../instances/{id}/actions` | POST | committed action effect |
| `.../instances/{id}/characters` | GET | character list |
| `.../characters/{name}/emotions` | GET | current affect state |
| `.../characters/{name}/perceptions` | POST | **observations to appraise** |
| `.../characters/{name}/decisions` | GET | engine-chosen actions |
| `.../characters/{name}/beliefs` | GET | beliefs |
| `.../characters/{name}/memories` | GET | autobiographic memory |
| `.../instances/{id}/worldmodel` | POST | belief/world updates |

## Verified behaviour (real run, 12 September 2026)

**Appraisal loop.** `POST .../characters/John/perceptions` with
`Event(Action-End, Player, Smile, John)` against the reference scenario produced:

| Observation | Value |
| --- | --- |
| Emotion | `Joy`, intensity `3.5` |
| Cause event retained | `Event(Action-End, Player, Smile, John)` |
| Mood | `-5.0` → `-3.95` |
| Autobiographic memory | event record id 1 |

Mood moved by exactly `3.5 x 0.3` (`EmotionInfluenceOnMoodFactor`), so appraisal,
mood influence and decay wiring all behave as configured.

**CiF volition.** A social exchange authored through the real API
(`Applications/CiFSeeder`) and gated by an influence rule produced a controlled
difference in `GET .../decisions`:

| Rapport | Decisions returned |
| --- | --- |
| 5 (above `RapportLevel(SELF,[x]) > 3`) | two at utility **10.0** from the CiF rule, two at 2.0 from the generic rule |
| 1 (below the starting condition) | only the two at 2.0 |

The `Volition(SocialMove, Step, Target, Mode)` dynamic property therefore does drive
decision making. CiF is reachable, but only through authored decision rules.

### CiF authoring constraints (each one silently fails otherwise)

1. **The exchange target must be a variable**, e.g. `[x]`. `SocialExchange.VolitionValue`
   builds a `Substitution` from the target and `Substitution` throws
   `BadSubstitutionException` for any non-variable name. A constant target makes
   `GET .../decisions` return that exception text instead of a list.
2. **The decision-rule condition must name the counterparty concretely**, e.g.
   `Volition(GiveMetal, Start, Player, *) >= 5`. An unbound `[x]` resolves to no
   possible target inside the dynamic property, so the rule never fires.
3. **Author scenarios through the API, never by hand.** The asset serializes under
   `SocialExchanges`, while the DTO uses `_SocialExchangesDtos`; hand-written JSON
   loads as an empty exchange set. `Applications/CiFSeeder` in this directory is the
   supported authoring path.
4. **Volition only exists at the first step.** `VolitionValue` returns `-Infinity`
   unless `step == Steps.FirstOrDefault()`, so CiF answers "should this exchange
   start", not "what is the current multi-step protocol state".

## Known limits — read before binding a contract

1. **CiF has no HTTP surface.** `CommeillFaut` is loaded into the character and
   registers its `Volition` dynamic property, but no HTTP resource serves social
   exchanges. There is no runtime endpoint to propose, accept, reject, counter, list
   or inspect an exchange, and no way to read a current step or mode. Exchanges are
   fixed at scenario authoring time and reachable only through decision rules, so
   this driver can satisfy `AffectEngine` fully and `SocialCognition` only partially
   (a volition scalar, not ranked responses with reasons).
2. **State is process memory.** `ServerState` holds a
   `ConcurrentDictionary<string, IntegratedAuthoringToolAsset[]>`. There is no
   export/import resource, so character state cannot be snapshotted, and a restart
   loses every instance. The module must be able to re-seed from its own records.
3. **One instance pool of 100 per scenario** (`MAX_INSTANCES = 100`), with index 0
   reserved as the immutable template. Scaling beyond 100 characters per scenario
   needs more scenarios.
4. **One request at a time.** `Run()` is a synchronous `while (true) { GetContext(); … }`
   loop — the server does not handle concurrent requests.
5. **Authorization fails open.** A key is only compared when one has been registered;
   with no admin key and no scenario key, `HasAuthorization` returns `true` for every
   caller. Keys must be set explicitly at deploy time.
6. **Decision making is not ours.** The engine's `EmotionalDecisionMakingAsset`
   proposes actions; module utility policy stays authoritative. Only affect,
   intentions and social importance are consumed.
