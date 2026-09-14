<?php

namespace Modules\AI\Domain\Language;

use LogicException;

/**
 * The ordered rungs one language request may be answered by.
 *
 * The order is the whole meaning: the SDK walks it and fails over on a provider error, so the
 * ladder is what lets a cheap vendor answer first without making it the only possibility. An
 * empty ladder is a refusal, not an error -- no credential is a configuration state, and the
 * caller answers with its authored text instead of contacting anyone.
 */
final readonly class AiProviderLadder
{
    /** @param list<array{provider: string, model: string}> $rungs */
    public function __construct(private array $rungs)
    {
    }

    /** @return list<array{provider: string, model: string}> */
    public function rungs(): array
    {
        return $this->rungs;
    }

    public function isEmpty(): bool
    {
        return $this->rungs === [];
    }

    /** @return array{provider: string, model: string}|null */
    public function primary(): array|null
    {
        return $this->rungs[0] ?? null;
    }

    /**
     * The rung that answers unless the SDK fails over.
     *
     * Throws on an empty ladder because an empty ladder is a refusal the caller must already have
     * acted on: reaching a call site with nothing to try is a programming error, not a provider one.
     *
     * @return array{provider: string, model: string}
     */
    public function firstOrFail(): array
    {
        return $this->rungs[0] ?? throw new LogicException('An empty provider ladder has no rung to attribute.');
    }

    /**
     * The shape the SDK's own failover list takes.
     *
     * A provider appears once, because that is all a provider-to-model map can express; when a
     * ladder names the same vendor twice, the first rung is the one that counts.
     *
     * @return array<string, string>
     */
    public function toProviderMap(): array
    {
        $map = [];

        foreach ($this->rungs as $rung) {
            $map[$rung['provider']] ??= $rung['model'];
        }

        return $map;
    }
}
