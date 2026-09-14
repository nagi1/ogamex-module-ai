/**
 * AgentOS cognitive-memory driver: a stateless HTTP surface for ranked recall.
 *
 * Each request carries the owner-scoped memories the module has authorised for that recall,
 * exactly as the CBRKit driver carries its casebase. The driver therefore persists nothing,
 * which is what makes it swappable in both directions: there is no second copy of a memory
 * to keep in step, no restart that can lose one, and no deletion that has to be propagated,
 * because the module's facts table remains the only place memory is stored.
 *
 * Only the memory subset is loaded. The vector store is in-process, the knowledge graph is
 * graphology, and the embedder is the module's own deterministic hashing implementation, so
 * the process makes no model download and no outbound request of any kind.
 *
 *   POST /recall  {scopeId, query, limit, memories:[{id, text, tags}]}
 *              -> {ranking:[{id, ...numeric evidence}], considered, discarded, partiallyRetrieved}
 *   GET  /health  -> {status}
 */
import { createServer } from 'node:http';
import * as root from '@framers/agentos';
import * as memory from '@framers/agentos/memory';
import { InMemoryWorkingMemory } from '@framers/agentos/cognition/substrate/memory/InMemoryWorkingMemory';
import { LocalEmbeddingManager } from './local-embedding-manager.mjs';

const PORT = Number(process.env.PORT ?? 8080);

/** The driver bounds its own input as well; the module caps what it chooses to send. */
const MAXIMUM_BODY_BYTES = 4_000_000;
const MAXIMUM_MEMORIES = 500;
const MAXIMUM_TAGS = 16;
const DEFAULT_LIMIT = 5;

/** Neutral mood: the module owns affect, and a driver must not invent an emotional context. */
const MOOD = { valence: 0, arousal: 0, dominance: 0 };

const TRAITS = {
    openness: 0.5,
    conscientiousness: 0.5,
    emotionality: 0.5,
    extraversion: 0.5,
    agreeableness: 0.5,
    honesty: 0.5,
};

/** Trace fields passed through as evidence. The module ranks by the returned order alone. */
const NUMERIC_FIELDS = ['score', 'similarity', 'relevance', 'encodingStrength', 'stability', 'retrievalCount'];

async function buildManager(scopeId) {
    const vectorStore = new root.InMemoryVectorStore();
    // The type literal is snake_case; 'in-memory' is rejected at runtime.
    await vectorStore.initialize({ id: `ogamex-ai-memory-${scopeId}`, type: 'in_memory', providerId: 'in-memory' });

    const manager = new memory.CognitiveMemoryManager();
    await manager.initialize({
        workingMemory: new memory.CognitiveWorkingMemory(new InMemoryWorkingMemory(), { traits: TRAITS }),
        knowledgeGraph: new memory.GraphologyMemoryGraph(),
        vectorStore,
        embeddingManager: new LocalEmbeddingManager(),
        agentId: 'ogamex-ai',
        traits: TRAITS,
        moodProvider: () => MOOD,
        // Keyword detection keeps feature extraction off any model.
        featureDetectionStrategy: 'keyword',
        // The default 'knowledge-graph' backend wraps the supplied graph in an adapter that
        // then fails; this driver supplies a memory graph instead.
        graph: { backend: 'graphology' },
    });

    return manager;
}

function memoryText(entry) {
    return typeof entry?.text === 'string' ? entry.text.trim() : '';
}

function memoryTags(entry) {
    if (!Array.isArray(entry?.tags)) {
        return [];
    }

    return entry.tags.filter((tag) => typeof tag === 'string' && tag !== '').slice(0, MAXIMUM_TAGS);
}

function requestedLimit(value) {
    return Number.isInteger(value) && value > 0 ? Math.min(value, MAXIMUM_MEMORIES) : DEFAULT_LIMIT;
}

async function recall(body) {
    const submitted = Array.isArray(body?.memories) ? body.memories.slice(0, MAXIMUM_MEMORIES) : [];
    const query = typeof body?.query === 'string' ? body.query.trim() : '';
    const scopeId = typeof body?.scopeId === 'string' && body.scopeId !== '' ? body.scopeId : 'unscoped';
    const usable = submitted.filter((entry) => Number.isInteger(entry?.id) && memoryText(entry) !== '');
    const discarded = submitted.length - usable.length;

    if (query === '' || usable.length === 0) {
        return { ranking: [], considered: 0, discarded, partiallyRetrieved: 0 };
    }

    const manager = await buildManager(scopeId);
    const authorised = new Map();

    for (const entry of usable) {
        const trace = await manager.encode(memoryText(entry), MOOD, 'content', {
            type: 'episodic',
            scope: 'user',
            scopeId,
            sourceType: 'observation',
            tags: memoryTags(entry),
        });

        if (typeof trace?.id === 'string') {
            authorised.set(trace.id, entry.id);
        }
    }

    const result = await manager.retrieve(query, MOOD, { topK: Math.min(requestedLimit(body?.limit), authorised.size) });
    const ranking = [];

    for (const trace of result?.retrieved ?? []) {
        const id = authorised.get(trace?.id);

        // A trace the module never sent is not ours to rank, so it is dropped rather than
        // reported: the module treats an unknown id as a scope leak and ignores the answer.
        if (id === undefined) {
            continue;
        }

        const ranked = { id };

        for (const field of NUMERIC_FIELDS) {
            if (typeof trace?.[field] === 'number' && Number.isFinite(trace[field])) {
                ranked[field] = trace[field];
            }
        }

        ranking.push(ranked);
    }

    return {
        ranking,
        considered: authorised.size,
        discarded,
        partiallyRetrieved: Array.isArray(result?.partiallyRetrieved) ? result.partiallyRetrieved.length : 0,
    };
}

function readBody(request) {
    return new Promise((resolve, reject) => {
        const chunks = [];
        let size = 0;

        request.on('data', (chunk) => {
            size += chunk.length;

            if (size > MAXIMUM_BODY_BYTES) {
                request.destroy();
                reject(new Error('payload too large'));

                return;
            }

            chunks.push(chunk);
        });

        request.on('end', () => {
            if (chunks.length === 0) {
                resolve({});

                return;
            }

            try {
                resolve(JSON.parse(Buffer.concat(chunks).toString('utf8')));
            } catch {
                reject(new Error('invalid JSON'));
            }
        });

        request.on('error', reject);
    });
}

function send(response, status, payload) {
    const body = JSON.stringify(payload);

    response.writeHead(status, { 'content-type': 'application/json', 'content-length': Buffer.byteLength(body) });
    response.end(body);
}

createServer(async (request, response) => {
    if (request.method === 'GET' && request.url === '/health') {
        send(response, 200, { status: 'ok' });

        return;
    }

    if (request.method !== 'POST' || request.url !== '/recall') {
        send(response, 404, { error: 'unknown route' });

        return;
    }

    try {
        send(response, 200, await recall(await readBody(request)));
    } catch (error) {
        send(response, 400, { error: error instanceof Error ? error.message : 'bad request' });
    }
}).listen(PORT, '0.0.0.0', () => {
    console.log(`agentos memory driver listening on ${PORT}`);
});
