/**
 * Deterministic local embedding manager for the AgentOS memory driver.
 *
 * AgentOS ships no local embedder: `EmbeddingManager` requires
 * `embeddingModels[].providerId` plus an `AIModelProviderManager`, so the stock
 * configuration reaches an external provider and would violate the module's
 * zero-generative rule. This implementation satisfies the exported
 * `IEmbeddingManager` contract with a deterministic, dependency-free hashing
 * embedder, so recall runs with no network and no model download.
 *
 * It is a lexical bag-of-words embedding, not a semantic one: it exists to keep
 * the driver offline and reproducible. Replacing it with a pinned local
 * multilingual model is a separate, measurement-gated decision.
 */

export const DIMENSIONS = 256;

const FNV_OFFSET = 2166136261;
const FNV_PRIME = 16777619;

function hashToken(token) {
    let hash = FNV_OFFSET;
    for (let index = 0; index < token.length; index += 1) {
        hash ^= token.charCodeAt(index);
        hash = Math.imul(hash, FNV_PRIME);
    }
    return hash >>> 0;
}

export function embedText(text) {
    const vector = new Array(DIMENSIONS).fill(0);

    for (const token of String(text).toLowerCase().match(/[a-z0-9]+/g) ?? []) {
        vector[hashToken(token) % DIMENSIONS] += 1;
    }

    const norm = Math.hypot(...vector);

    return norm === 0 ? vector : vector.map((value) => value / norm);
}

export class LocalEmbeddingManager {
    async initialize() {}

    async generateEmbeddings(request) {
        const texts = Array.isArray(request.texts) ? request.texts : [request.texts];

        return {
            embeddings: texts.map((text) => embedText(text)),
            modelId: 'local-hash',
            providerId: 'local',
            usage: { inputTokens: 0, totalTokens: 0, costUSD: 0 },
        };
    }

    async getEmbeddingModelInfo() {
        return { modelId: 'local-hash', providerId: 'local', dimensions: DIMENSIONS };
    }

    async getEmbeddingDimension() {
        return DIMENSIONS;
    }

    async checkHealth() {
        return { isHealthy: true };
    }

    async shutdown() {}
}
