/**
 * AgentOS memory-only verification.
 *
 * Proves the driver completes an encode/retrieve cycle with no provider keys,
 * no generative call and no model download. Run with the module's node_modules:
 *
 *   node verify.mjs
 *
 * Exits non-zero if recall does not return the encoded trace, so it doubles as
 * the conformance probe for this driver.
 */

import * as root from '@framers/agentos';
import * as memory from '@framers/agentos/memory';
import { InMemoryWorkingMemory } from '@framers/agentos/cognition/substrate/memory/InMemoryWorkingMemory';
import { LocalEmbeddingManager } from './local-embedding-manager.mjs';

const traits = {
    openness: 0.7,
    conscientiousness: 0.6,
    emotionality: 0.5,
    extraversion: 0.5,
    agreeableness: 0.5,
    honesty: 0.5,
};

const mood = { valence: 0.2, arousal: 0.4, dominance: 0 };
const scope = { scope: 'user', scopeId: 'ai-42' };

function assert(condition, message) {
    if (!condition) {
        console.error(`FAIL: ${message}`);
        process.exit(1);
    }
    console.log(`ok: ${message}`);
}

const providerKeys = Object.keys(process.env).filter((key) => /API_KEY|TOKEN|SECRET/i.test(key));
assert(providerKeys.length === 0, `no provider credentials in the environment (${providerKeys.length})`);

const vectorStore = new root.InMemoryVectorStore();
// The type literal is snake_case; 'in-memory' is rejected at runtime.
await vectorStore.initialize({ id: 'ogamex-ai-memory', type: 'in_memory', providerId: 'in-memory' });

const manager = new memory.CognitiveMemoryManager();
await manager.initialize({
    workingMemory: new memory.CognitiveWorkingMemory(new InMemoryWorkingMemory(), { traits }),
    knowledgeGraph: new memory.GraphologyMemoryGraph(),
    vectorStore,
    embeddingManager: new LocalEmbeddingManager(),
    agentId: 'ogamex-ai',
    traits,
    moodProvider: () => mood,
    // Keyword detection keeps feature extraction off any model.
    featureDetectionStrategy: 'keyword',
    // The default 'knowledge-graph' backend wraps the passed graph in a
    // knowledge-graph adapter and fails; this driver supplies a memory graph.
    graph: { backend: 'graphology' },
});

const attack = await manager.encode('The Player attacked my colony with 500 fighters', mood, 'content', {
    type: 'episodic',
    ...scope,
    sourceType: 'observation',
    tags: ['attack', 'player'],
});
assert(attack?.id?.startsWith('mt_'), `encode returned a trace id (${attack?.id})`);

await manager.encode('The Player offered a ceasefire yesterday', mood, 'content', {
    type: 'semantic',
    ...scope,
    sourceType: 'user_statement',
    tags: ['ceasefire', 'player'],
});

const result = await manager.retrieve('player attack colony fighters', mood, { topK: 3 });
const retrieved = result?.retrieved ?? [];

assert(retrieved.length > 0, `retrieve returned traces (${retrieved.length})`);
assert(retrieved[0]?.id === attack.id, 'the attack trace ranked first for an attack query');
assert(retrieved[0]?.provenance?.sourceType === 'observation', 'provenance survived the round trip');
assert(typeof retrieved[0]?.encodingStrength === 'number', 'encoding strength is present');
assert(typeof retrieved[0]?.stability === 'number', 'decay stability is present');
assert(Array.isArray(result?.partiallyRetrieved), 'tip-of-the-tongue bucket is reported');

console.log('\nPASS: AgentOS memory-only cycle completed with zero provider calls.');
