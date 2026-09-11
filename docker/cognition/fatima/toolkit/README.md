# FAtiMA Toolkit trial — dockerized server + terminal chat client

Reproduction of the running example from the FAtiMA Toolkit paper
(Mascarenhas et al., *FAtiMA Toolkit: Toward an Accessible Tool for the
Development of Socio-emotional Agents*, ACM TiiS 12(1), 2022,
[doi:10.1145/3510822](https://doi.org/10.1145/3510822)), running the FAtiMA
HTTP server headless in Docker and talking to the agent from a Python
terminal client.

What is reproduced from the paper:

- the dialogue tree of Figure 2 / Tables 2–3 (states `s1..s4`, lines `d1..d5`)
- the two decision rules of Section 5 (generic Speak rule + a higher-priority
  "Rude" rule active when `Mood(SELF) < 0`)
- the appraisal rule example of Section 3.7 (a `Smile` event is appraised with
  Desirability taken from the `RapportLevel` belief, generating OCC Joy)
- the full agent loop: perceive → appraise (OCC emotions, mood) → decide →
  act, with autobiographic memory and per-tick emotion decay

## Layout

| Path | What it is |
|---|---|
| `FAtiMA-Toolkit/` | Vendored copy of [GAIPS-INESC-ID/FAtiMA-Toolkit](https://github.com/GAIPS-INESC-ID/FAtiMA-Toolkit) at commit `56b7cbd9`, Apache 2.0 (see `FAtiMA-Toolkit/License.txt`) |
| `FAtiMA-Toolkit/Dockerfile` | Added: builds the FAtiMA HTTP server (`Applications/FAtiMAHTTPServer`, retargeted `netcoreapp3.0` → `net8.0` — only local change to upstream code) |
| `FAtiMA-Toolkit/Applications/ScenarioSeeder/` | Added: C# tool that authors the paper's scenario with the real FAtiMA APIs and writes the JSON seed files |
| `FAtiMA-Toolkit/Applications/Scenarios/seed/` | Generated scenario files consumed by the chat client |
| `docker-compose.yml` | Runs the server on `localhost:8000` |
| `chat-client/` | Python terminal client (REST) implementing the canonical action→appraisal→decision→tick loop |

## Run

```bash
# 1. start the FAtiMA server (Docker Desktop must be running)
docker compose up -d

# 2. set up the client once
cd chat-client
python -m venv .venv
.venv/Scripts/python -m pip install -r requirements.txt   # Windows
# .venv/bin/python -m pip install -r requirements.txt     # macOS/Linux

# 3. chat
.venv/Scripts/python chat.py
```

John starts in a negative mood (`-5`, as in the upstream sample character), so
"How are you feeling?" yields *"None of your business."* Smile a few times
(`s`) to stack Joy until his mood turns non-negative, and the same question
yields *"I am feeling great."*

To change the scenario, edit `FAtiMA-Toolkit/Applications/ScenarioSeeder/Program.cs`
and regenerate the seed files:

```bash
cd FAtiMA-Toolkit
docker run --rm -v "${PWD}:/src" -w /src mcr.microsoft.com/dotnet/sdk:8.0 \
  dotnet run --project Applications/ScenarioSeeder/ScenarioSeeder.csproj -- /src/Applications/Scenarios/seed
```

## License

The `FAtiMA-Toolkit/` subtree is Apache 2.0 by GAIPS / INESC-ID (unmodified
except where noted above). Everything else in this repository follows the same
license.
