<?php

namespace Modules\AI\Domain\Routine;

use Carbon\CarbonImmutable;
use Modules\AI\Models\AiProfile;
use Modules\AI\Support\RandomSource;

/** Plans finite sessions in the account's wall-clock timezone. */
class SessionPlanner
{
    private const VARIATION_FRACTION = 0.25;

    public function __construct(private RandomSource $randomSource)
    {
    }

    public function plan(AiProfile $profile, CarbonImmutable $now, int $generation): SessionPlan
    {
        $routine = RoutineProfile::fromAiProfile($profile);
        $localNow = $now->setTimezone($routine->timezone);
        // Local date keeps the routine human-looking while generation keeps a
        // replayed day deterministic instead of sampling fresh randomness.
        $context = 'session:' . $generation . ':' . $localNow->toDateString();
        $variation = $this->randomSource->unitInterval($profile->random_seed, $context) - 0.5;
        $gapMinutes = max(1, (int) round($routine->sessionGapMinutes * (1 + ($variation * self::VARIATION_FRACTION))));

        return app()->makeWith(SessionPlan::class, [
            'sessionEndsAt' => $now->addMinutes($routine->sessionMinutes),
            'nextDueAt' => $now->addMinutes($gapMinutes),
        ]);
    }
}
