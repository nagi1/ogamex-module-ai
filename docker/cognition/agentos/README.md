# AgentOS cognitive-memory driver

Optional, module-owned driver candidate behind `Modules\AI\Contracts\LongTermMemory`.
Nothing in the Phase 3 baseline depends on it: with the driver absent, disabled or
failing, the module uses `NativeLongTermMemory`.

## Pinned implementation

| Item | Value |
| --- | --- |
| Package | `@framers/agentos` — <https://www.npmjs.com/package/@framers/agentos> |
| Version | `0.10.16` |
| Project | AgentOS by Frame / Frame.dev (`framerslab/agentos`, <https://agentos.sh>) |
| License | Apache-2.0 |
| Runtime | Node.js (debian `node:22-slim`) |
| Entry point | `@framers/agentos/memory` (memory subset only) |

## Verified: memory-only cycle with zero provider calls

`verify.mjs` completes an encode/retrieve cycle with **no provider credentials
present** and no model download. It asserts trace identity, provenance survival,
encoding strength, decay stability and the tip-of-the-tongue bucket, and exits
non-zero on any failure.

```
ok: no provider credentials in the environment (0)
ok: encode returned a trace id (mt_16fbc9e8-...)
ok: retrieve returned traces (2)
ok: the attack trace ranked first for an attack query
ok: provenance survived the round trip
ok: encoding strength is present
ok: decay stability is present
ok: tip-of-the-tongue bucket is reported
PASS: AgentOS memory-only cycle completed with zero provider calls.
```

Run it from a throwaway install so `node_modules` never lands in the module:

```bash
docker run --rm -v /tmp:/t alpine sh -c 'rm -rf /t/agentos-run'
mkdir -p /tmp/agentos-run
cp docker/cognition/agentos/* /tmp/agentos-run/
docker run --rm -v /tmp/agentos-run:/app -w /app node:22-slim sh -c \
  'npm install --no-audit --no-fund && node verify.mjs'
```

## What AgentOS actually gives us

`retrieve()` returns `{ retrieved, partiallyRetrieved, diagnostics }`. Each trace
carries fields that map onto our module-owned records:

| Field | Use |
| --- | --- |
| `id`, `type`, `scope`, `scopeId` | owner scope; collections are named `cogmem_{scope}_{scopeId}` |
| `content`, `entities`, `tags` | evidence payload |
| `provenance.{sourceType,sourceId,confidence,verificationCount}` | attribution, matching our provenance rules |
| `emotionalContext.{valence,arousal,intensity}` | PAD snapshot at encoding |
| `encodingStrength`, `stability`, `retrievalCount`, `lastAccessedAt` | Ebbinghaus decay and spaced repetition |
| `policy.usableForAuthorization/FactClaim` | provider-side guard we must still re-check in module code |

## Required blockers and deviations

1. **No local embedder ships.** `EmbeddingManager` requires
   `embeddingModels[].providerId` plus an `AIModelProviderManager`, so the stock
   configuration reaches an external provider. Zero-generative therefore requires
   our own `IEmbeddingManager`. `local-embedding-manager.mjs` implements it with a
   deterministic hashing embedder — offline and reproducible, but **lexical, not
   semantic**. A pinned local multilingual model is a separate, measured decision.
2. **Peer dependencies are not installed by default.** `GraphologyMemoryGraph`
   throws `graphology is required`; `graphology` + `graphology-types` must be
   added explicitly, and npm will prune the package if it is not declared in
   `package.json` first.
3. **Install size is ~920 MB.** `onnxruntime-node` alone is 536 MB, with
   `onnxruntime-web` 92 MB and `@huggingface` 49 MB. The memory subset does not use
   them; they arrive as unconditional dependencies. This is the heaviest of the
   three drivers by a wide margin and needs an explicit size decision.
4. **Configuration traps.** `graph.backend` defaults to `'knowledge-graph'`, which
   wraps the supplied graph in an adapter that then fails on a missing
   `queryEntities`; it must be `'graphology'`. The vector store type literal is
   `'in_memory'`, not `'in-memory'`. The vector store needs an explicit
   `initialize()` that the manager does not call for you.
5. **Deletion is soft only.** `MemoryStore` sets `isActive = false`; there is no
   hard delete, so our complete-deletion-propagation requirement needs a
   provider-specific path.
6. **Agent runtime stays off.** Tool forging, multi-agent orchestration,
   observer/reflector and LLM feature detection must remain disabled: keyword
   feature detection plus omitting the Batch-2 config keeps them inert.
