<?php

/**
 * What the AI accounts have actually built, next to the doctrine each one adopted.
 *
 * DEV TOOLING — read-only, never shipped behaviour, never run in a universe. It answers
 * the only question that matters about the wiki work: did the strategy reach the player?
 *
 *   cd /var/www && php artisan tinker Modules/AI/scripts/measure-defence.php
 *
 * Everything it prints comes from the host's own services, so the unit names are the
 * host's object names and no module constant is trusted.
 */

use Modules\AI\Enums\AiActivityBand;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiDefenseDoctrine;
use Modules\AI\Models\AiProfile;
use OGame\Factories\PlanetServiceFactory;
use OGame\Models\Planet;
use OGame\Models\UnitQueue;
use OGame\Services\ObjectService;
use OGame\Services\PlayerGameStateService;

echo "\n=== AI defence measurement — " . now()->toDateTimeString() . " ===\n";

$profiles = AiProfile::all();

if ($profiles->isEmpty()) {
    echo "No AI accounts exist. Seed one first: php artisan ai:seed-test-universe --players=3 --confirm\n";
    return;
}

$planets = $planets ?? app(PlayerGameStateService::class);

// Every defence object the host knows about, by machine name, so nothing is hardcoded here.
$defence = collect(ObjectService::getDefenseObjects())
    ->mapWithKeys(fn ($object) => [$object->machine_name => $object->title])
    ->all();

foreach ($profiles as $profile) {
    $name = fn (string $field, string $enum): string =>
        $enum::tryFrom((int) $profile->getRawOriginal($field))?->name ?? 'none';

    printf(
        "\nplayer %d  archetype=%s  doctrine=%s  band=%s\n",
        $profile->player_id,
        $name('archetype', AiArchetype::class),
        $name('defense_doctrine', AiDefenseDoctrine::class),
        $name('activity_band', AiActivityBand::class)
    );

    foreach (Planet::query()->where('user_id', $profile->player_id)->pluck('id') as $planetId) {
        try {
            $state = $planets->advance($profile->player_id, $planetId);
            $planet = app(PlanetServiceFactory::class)
                ->makeForPlayer($state, $planetId, false);

            $built = [];
            foreach ($defence as $machineName => $title) {
                $amount = $planet->getObjectAmount($machineName);
                if ($amount > 0) {
                    $built[$title] = $amount;
                }
            }

            $queued = UnitQueue::query()
                ->where('planet_id', $planetId)
                ->where('processed', 0)
                ->count();

            printf(
                "  planet %d  defence: %s  queued rows: %d\n",
                $planetId,
                $built === [] ? 'none yet' : json_encode($built),
                $queued
            );

            // The decision itself, on this real planet: what the wall should be worth and what the
            // doctrine says to build next. Either it queues, or this explains exactly why not.
            $need = app(\Modules\AI\Domain\Decision\DefenseNeedEvaluator::class)
                ->evaluate($state, $planet);
            $composition = app(\Modules\AI\Domain\Decision\DefenseCompositionPlanner::class)
                ->plan($state, $planet, $need);

            printf(
                "    need: %s (%s)\n    doctrine says: %s\n",
                $need === null ? 'none — wall already covers it' : number_format($need->defenceValue, 0),
                $need->reason ?? '-',
                $composition === null
                    ? 'nothing queueable at this need'
                    : $composition->unit . ' x' . $composition->amount . ' (' . $composition->reason . ')'
            );
        } catch (Throwable $exception) {
            printf("  planet %d  measurement failed: %s\n", $planetId, $exception->getMessage());
        }
    }
}

echo "\ndecisions recorded: " . DB::table('ai_decision_traces')->count()
    . "  action receipts: " . DB::table('ai_action_receipts')->count()
    . "  work items: " . DB::table('ai_work_items')->count()
    . " (due now: " . DB::table('ai_work_items')->where('due_at', '<=', now())->count() . ")\n";
