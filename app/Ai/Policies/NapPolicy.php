<?php

declare(strict_types=1);

namespace Modules\AI\Ai\Policies;

final class NapPolicy
{
    /**
     * @param  list<int>  $napTargetPlayerIds  Player ids the host declares as NAP partners.
     */
    public function __construct(private readonly array $napTargetPlayerIds)
    {
    }

    /**
     * The pipeline owns the action taxonomy, so it offers only aggressive candidates here;
     * this policy owns the pact and nothing else. Null means "no veto", which is how every
     * candidate that is not aimed at a declared partner reaches the pipeline untouched.
     */
    public function reviewAggressive(int $targetPlayerId): ?string
    {
        if (! in_array($targetPlayerId, $this->napTargetPlayerIds, true)) {
            return null;
        }

        return sprintf('Target player %d is a declared NAP partner.', $targetPlayerId);
    }
}
