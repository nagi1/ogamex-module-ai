<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Modules\AI\Contracts\SocialCognition;
use Modules\AI\Domain\Conversation\NativeSocialCognition;
use Modules\AI\Domain\Conversation\SocialExchangeContext;
use Modules\AI\Domain\Decision\SaveFailurePolicy;
use Modules\AI\Domain\Routine\SessionPlanner;
use Modules\AI\Enums\AiSocialExchangeType;
use Modules\AI\Enums\AiSocialTerm;
use Modules\AI\Models\AiProfile;
use Modules\AI\Models\AiSocialExchange;
use Modules\AI\Support\SeededRandomSource;

/**
 * The adversarial neighbour, run live (verification-and-monitoring.md §2).
 *
 * Reads a capacity universe (seed one first with `ai:seed-test-universe`) and prints a
 * fixed-field JSON report the review loop parses and diffs. Every figure is deterministic per
 * account seed or a bounded one-pass read of rows the cohort already writes, so the same cohort
 * produces the same figures and a live pilot is never touched on the read path.
 *
 *   php provoke-ai.php
 *
 * Refuses to run on an empty universe, because a report over zero accounts would read as a pass.
 */

$root = dirname(__DIR__, 3);

require $root.'/vendor/autoload.php';

$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

app()->bind(SocialCognition::class, NativeSocialCognition::class);

$profiles = AiProfile::query()->where('enabled', true)->orderBy('player_id')->get();

if ($profiles->isEmpty()) {
    fwrite(STDERR, "No enabled AI profiles. Seed a capacity universe first: php artisan ai:seed-test-universe --players=5\n");

    exit(1);
}

$report = [
    'generated_at' => CarbonImmutable::now('UTC')->toIso8601String(),
    'accounts' => $profiles->count(),
    'reaction_lead_seconds' => reactionLeadStats($profiles),
    'save_skip_rate' => saveSkipRate($profiles),
    'forgiveness_refusal' => forgivenessResponse(),
    'thank_you_count' => AiSocialExchange::query()->where('type', AiSocialExchangeType::TransportThanks->value)->count(),
    'dark_hours_min' => minDarkHours($profiles),
];

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";

exit(0);

/**
 * The deterministic 120–180 s reaction lead per account seed, so the same inbound always gets
 * the same reaction and never lands below the host's 10 s detector floor.
 *
 * @param \Illuminate\Database\Eloquent\Collection<int, AiProfile> $profiles
 * @return array{min: int, max: int, mean: float}
 */
function reactionLeadStats($profiles): array
{
    $random = app(SeededRandomSource::class);
    $leads = $profiles->map(static fn (AiProfile $profile): int => 120 + (int) round(60 * $random->unitInterval((int) $profile->random_seed, 'reaction-wake')));

    return ['min' => $leads->min(), 'max' => $leads->max(), 'mean' => round($leads->avg(), 2)];
}

/**
 * The fraction of accounts whose save-failure draw skips a sample save, so a save that never
 * fails reads as a bot timer. The draw is deterministic per seed for a fixed inbound key.
 *
 * @param \Illuminate\Database\Eloquent\Collection<int, AiProfile> $profiles
 */
function saveSkipRate($profiles): float
{
    $policy = app(SaveFailurePolicy::class);
    $skips = $profiles->filter(static fn (AiProfile $profile): bool => $policy->shouldSkip((int) $profile->random_seed, 1) !== null)->count();

    return round($skips / $profiles->count(), 4);
}

/**
 * A cheap apology with no earned trust is refused, never forgiven on warmth alone.
 */
function forgivenessResponse(): string
{
    $evaluation = app(SocialCognition::class)->evaluateSocialExchange(app()->makeWith(SocialExchangeContext::class, [
        'exchangeId' => 1,
        'type' => AiSocialExchangeType::Apology,
        'terms' => [AiSocialTerm::AcknowledgesHarm->value => true],
        'trust' => 0.0,
        'affinity' => 0.9,
        'threat' => 0.1,
        'outstandingCommitments' => 0,
        'availableAmount' => 0,
        'evaluatedAt' => CarbonImmutable::now('UTC'),
    ]));

    return $evaluation->response->name;
}

/**
 * The smallest dark period across the cohort, so a population that never sleeps reads as a
 * round-the-clock signal rather than as busy players.
 *
 * @param \Illuminate\Database\Eloquent\Collection<int, AiProfile> $profiles
 */
function minDarkHours($profiles): int
{
    $planner = app(SessionPlanner::class);
    $day = CarbonImmutable::now('UTC')->startOfDay();

    return $profiles
        ->map(static function (AiProfile $profile) use ($planner, $day): int {
            $dark = 0;
            for ($hour = 0; $hour < 24; $hour++) {
                if (!$planner->isAwake($profile, $day->addHours($hour))) {
                    $dark++;
                }
            }

            return $dark;
        })
        ->min();
}
