/**
 * AgentOS relationship memory for the social world: `POST /social/recall`.
 *
 * Same stateless design as `/recall`: every item carries the relationship memories the caller
 * authorised ("who did what to me"), the driver rebuilds an AgentOS memory for that item, and
 * nothing survives the request. All memory behaviour is AgentOS's own, configured, never
 * re-implemented here:
 *
 * - encoding: `CognitiveMemoryManager.encode` with the event's valence as content sentiment and
 *   the encoding-time PAD snapshot, so Yerkes-Dodson, mood congruence and flashbulb memories
 *   (intensity > 0.8: x2 strength, x5 stability) come from the package;
 * - decay: Ebbinghaus `computeCurrentStrength` over the trace's stability, evaluated at the
 *   caller's `now` by placing each trace `now - at` in the past;
 * - consolidation: `runConsolidation()` replayed once per elapsed interval between events: pruning
 *   (forgotten traces are soft-deleted, emotional ones are protected), shared-actor co-activation
 *   edges, spaced repetition, and the cognitive mechanisms (emotion regulation = reappraisal
 *   scaled by agreeableness, temporal gist, source-confidence decay);
 * - retrieval: `retrieve()` composite (strength, similarity, recency, emotional congruence with
 *   the current mood, graph spreading activation) plus the tip-of-the-tongue bucket and
 *   retrieval-induced forgetting.
 *
 * The per-actor aggregate is a plain sum over the surviving traces of AgentOS's own current
 * strength, split by the sign of the trace's (possibly regulated) valence, plus the same sum over
 * traces tagged `promise_broken` / `promise_kept`. Persona enters only through AgentOS's own
 * HEXACO `traits`: emotionality scales emotional encoding (vindictive minds encode harm stronger,
 * so it lasts longer) and agreeableness scales reappraisal (forgiving minds regulate it faster). AgentOS has no
 * per-entity relationship model of its own, so this sum is the only thing written here.
 *
 *   POST /social/recall {items:[{scopeId, query, now, limit?, traits?, mood?,
 *                                memories:[{id, text, tags, at, valence, arousal?, actor}]}]}
 *                    -> {results:[{ranking, actors, considered, discarded, forgotten,
 *                                  partiallyRetrieved, consolidation} | {error}]}
 *
 * Times are unix seconds (values above 1e11 are read as milliseconds). Valence is [-1, 1]
 * (negative = harm done to me); arousal is [0, 1] and defaults to 0.5 + 0.5|valence|.
 */
import * as root from '@framers/agentos';
import * as memory from '@framers/agentos/memory';
import { InMemoryWorkingMemory } from '@framers/agentos/cognition/substrate/memory/InMemoryWorkingMemory';
import { LocalEmbeddingManager } from './local-embedding-manager.mjs';

const MAXIMUM_ITEMS = 200;
const MAXIMUM_MEMORIES = 500;
const MAXIMUM_TAGS = 16;
const DEFAULT_LIMIT = 10;
const DAY_MS = 86_400_000;

/**
 * AgentOS's default base stability is one hour (a chat assistant's timescale). An OGame
 * relationship lives for weeks, so the only knob set here is that time constant; the decay law,
 * the flashbulb multipliers and the pruning threshold stay the package's.
 */
const BASE_STABILITY_MS = Number(process.env.AGENTOS_SOCIAL_BASE_STABILITY_MS ?? 7 * DAY_MS);

/**
 * AgentOS consolidates on an interval (one hour by default). Replaying the history, the pipeline
 * runs once per elapsed interval between events, so a trace goes through as many cycles as its
 * age allows. Emotion regulation stops once |valence| <= 0.8, so a few cycles per gap reach the
 * package's own fixed point; the cap only bounds latency.
 */
const CONSOLIDATION_INTERVAL_MS = Number(process.env.AGENTOS_SOCIAL_CONSOLIDATION_INTERVAL_MS ?? DAY_MS);
const MAXIMUM_CYCLES_PER_GAP = 4;

/** Commitment outcomes the caller tags; the per-actor counts sum their remembered strength. */
const PROMISE_BROKEN = 'promise_broken';
const PROMISE_KEPT = 'promise_kept';

const NEUTRAL_TRAITS = {
    openness: 0.5,
    conscientiousness: 0.5,
    emotionality: 0.5,
    extraversion: 0.5,
    agreeableness: 0.5,
    honesty: 0.5,
};

const clamp = (value, low, high) => Math.max(low, Math.min(high, value));
const finite = (value) => typeof value === 'number' && Number.isFinite(value);
const round = (value) => Math.round(value * 1e6) / 1e6;

function toMs(value) {
    return value > 1e11 ? value : value * 1000;
}

function traitsOf(input) {
    const traits = { ...NEUTRAL_TRAITS };

    for (const key of Object.keys(traits)) {
        if (finite(input?.[key])) {
            traits[key] = clamp(input[key], 0, 1);
        }
    }

    return traits;
}

function moodOf(input) {
    return {
        valence: finite(input?.valence) ? clamp(input.valence, -1, 1) : 0,
        arousal: finite(input?.arousal) ? clamp(input.arousal, -1, 1) : 0,
        dominance: finite(input?.dominance) ? clamp(input.dominance, -1, 1) : 0,
    };
}

function usableMemory(entry) {
    return (Number.isInteger(entry?.id) || (typeof entry?.id === 'string' && entry.id !== ''))
        && typeof entry?.text === 'string' && entry.text.trim() !== ''
        && finite(entry?.at);
}

async function buildManager(agentId, traits, mood) {
    const vectorStore = new root.InMemoryVectorStore();
    await vectorStore.initialize({ id: `ogamex-ai-social-${agentId}`, type: 'in_memory', providerId: 'in-memory' });

    const manager = new memory.CognitiveMemoryManager();
    await manager.initialize({
        workingMemory: new memory.CognitiveWorkingMemory(new InMemoryWorkingMemory(), { traits }),
        knowledgeGraph: new memory.GraphologyMemoryGraph(),
        vectorStore,
        embeddingManager: new LocalEmbeddingManager(),
        agentId,
        traits,
        moodProvider: () => mood,
        featureDetectionStrategy: 'keyword',
        graph: { backend: 'graphology' },
        encoding: { baseStabilityMs: BASE_STABILITY_MS },
        // The pipeline is run explicitly once per item; its interval timer stays off.
        consolidation: { enabled: false },
        // Involuntary recall is a random draw; it is off so equal requests answer equally.
        cognitiveMechanisms: { involuntaryRecall: { enabled: false } },
    });

    return manager;
}

async function recallItem(item) {
    const submitted = Array.isArray(item?.memories) ? item.memories.slice(0, MAXIMUM_MEMORIES) : [];
    const query = typeof item?.query === 'string' ? item.query.trim() : '';
    const agentId = typeof item?.scopeId === 'string' && item.scopeId !== '' ? item.scopeId : 'unscoped';
    const usable = submitted.filter(usableMemory);
    const discarded = submitted.length - usable.length;
    const empty = { ranking: [], actors: {}, considered: 0, discarded, forgotten: [], partiallyRetrieved: [] };

    if (!finite(item?.now)) {
        return { error: 'now is required' };
    }

    if (query === '' || usable.length === 0) {
        return empty;
    }

    const nowMs = toMs(item.now);
    const mood = moodOf(item?.mood);
    const manager = await buildManager(agentId, traitsOf(item?.traits), mood);
    const byTrace = new Map();
    const realNow = Date.now();

    try {
        // Oldest first, so the graph and the mechanisms see the history in the order it happened.
        const ordered = [...usable].sort((a, b) => toMs(a.at) - toMs(b.at));
        const consolidation = { pruned: 0, edges: 0, reinforced: 0, cycles: 0 };
        const consolidate = async (gapMs) => {
            const cycles = Math.min(MAXIMUM_CYCLES_PER_GAP, Math.floor(Math.max(0, gapMs) / CONSOLIDATION_INTERVAL_MS));

            for (let cycle = 0; cycle < cycles; cycle += 1) {
                const run = await manager.runConsolidation();
                consolidation.pruned += run.prunedCount;
                consolidation.edges += run.edgesCreated;
                consolidation.reinforced += run.reinforcedCount;
                consolidation.cycles += 1;
            }
        };

        for (const [index, entry] of ordered.entries()) {
            if (index > 0) {
                await consolidate(toMs(entry.at) - toMs(ordered[index - 1].at));
            }

            const valence = finite(entry.valence) ? clamp(entry.valence, -1, 1) : 0;
            const arousal = finite(entry.arousal) ? clamp(entry.arousal, 0, 1) : 0.5 + 0.5 * Math.abs(valence);
            const actor = typeof entry.actor === 'string' || Number.isInteger(entry.actor) ? String(entry.actor) : '';
            // Encoding-time PAD snapshot: AgentOS reads arousal in [-1, 1].
            const encodingMood = { valence, arousal: arousal * 2 - 1, dominance: 0 };
            const trace = await manager.encode(entry.text.trim(), encodingMood, 'social', {
                type: 'episodic',
                scope: 'user',
                scopeId: agentId,
                sourceType: 'observation',
                tags: Array.isArray(entry.tags)
                    ? entry.tags.filter((tag) => typeof tag === 'string' && tag !== '').slice(0, MAXIMUM_TAGS)
                    : [],
                entities: actor === '' ? [] : [actor],
                contentSentiment: valence,
            });

            // Place the trace in the past: the decay clock reads `now - lastAccessedAt`, so an
            // event `now - at` ago is the same trace AgentOS would hold had it been encoded then.
            const ageMs = Math.max(0, nowMs - toMs(entry.at));
            const past = realNow - ageMs;
            trace.createdAt = past;
            trace.lastAccessedAt = past;
            trace.updatedAt = past;
            trace.provenance.sourceTimestamp = past;

            byTrace.set(trace.id, {
                id: entry.id,
                actor,
                flashbulb: memory.isFlashbulbMemory(trace.emotionalContext.intensity),
                promise: trace.tags.includes(PROMISE_BROKEN) ? 'broken' : (trace.tags.includes(PROMISE_KEPT) ? 'kept' : null),
            });
        }

        await consolidate(nowMs - toMs(ordered[ordered.length - 1].at));
        const store = manager.getStore();
        const scoreNow = Date.now();
        const actors = {};
        const forgotten = [];

        for (const [traceId, known] of byTrace) {
            const trace = store.getTrace(traceId);
            const name = known.actor === '' ? '_' : known.actor;
            const bucket = actors[name] ??= {
                grievance: 0, gratitude: 0, brokenPromises: 0, keptPromises: 0, remembered: 0, forgotten: 0, strongest: null,
            };

            if (!trace?.isActive || store.isDeleted(traceId)) {
                bucket.forgotten += 1;
                forgotten.push(known.id);
                continue;
            }

            const strength = memory.computeCurrentStrength(trace, scoreNow);
            const valence = trace.emotionalContext.valence;
            bucket.remembered += 1;
            bucket.grievance += strength * Math.max(0, -valence);
            bucket.gratitude += strength * Math.max(0, valence);

            if (known.promise === 'broken') {
                bucket.brokenPromises += strength;
            } else if (known.promise === 'kept') {
                bucket.keptPromises += strength;
            }

            if (bucket.strongest === null || strength > bucket.strongestStrength) {
                bucket.strongest = known.id;
                bucket.strongestStrength = strength;
            }
        }

        for (const bucket of Object.values(actors)) {
            bucket.grievance = round(bucket.grievance);
            bucket.gratitude = round(bucket.gratitude);
            bucket.brokenPromises = round(bucket.brokenPromises);
            bucket.keptPromises = round(bucket.keptPromises);
            delete bucket.strongestStrength;
        }

        const limit = Number.isInteger(item?.limit) && item.limit > 0 ? Math.min(item.limit, MAXIMUM_MEMORIES) : DEFAULT_LIMIT;
        const result = await manager.retrieve(query, mood, { topK: Math.min(limit, byTrace.size) });
        const ranking = [];

        for (const trace of result?.retrieved ?? []) {
            const known = byTrace.get(trace?.id);

            if (known === undefined) {
                continue;
            }

            const meta = trace.structuredData?.mechanismMetadata ?? {};
            ranking.push({
                id: known.id,
                actor: known.actor,
                score: round(trace.retrievalScore),
                strength: round(trace.scoreBreakdown?.strengthScore ?? 0),
                similarity: round(trace.scoreBreakdown?.similarityScore ?? 0),
                encodingStrength: round(trace.encodingStrength),
                stabilityDays: round(trace.stability / DAY_MS),
                valence: round(trace.emotionalContext.valence),
                flashbulb: known.flashbulb,
                regulated: Array.isArray(meta.reappraisalHistory) && meta.reappraisalHistory.length > 0,
                gisted: meta.gisted === true,
            });
        }

        return {
            ranking,
            actors,
            considered: byTrace.size,
            discarded,
            forgotten,
            partiallyRetrieved: (result?.partiallyRetrieved ?? [])
                .map((partial) => byTrace.get(partial.traceId)?.id)
                .filter((id) => id !== undefined),
            consolidation,
        };
    } finally {
        await manager.shutdown().catch(() => {});
    }
}

export async function socialRecall(body) {
    const items = Array.isArray(body?.items) ? body.items : null;

    if (items === null) {
        throw new Error('items must be an array');
    }

    if (items.length > MAXIMUM_ITEMS) {
        throw new Error(`at most ${MAXIMUM_ITEMS} items`);
    }

    const results = [];

    // Items run one after another: the work is CPU-bound in one Node thread, so interleaving them
    // would only add latency to each without adding throughput. Replicas add throughput.
    for (const item of items) {
        try {
            results.push(await recallItem(item));
        } catch (error) {
            results.push({ error: error instanceof Error ? error.message : 'recall failed' });
        }
    }

    return { results };
}
