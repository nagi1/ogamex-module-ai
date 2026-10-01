<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Modules\AI\Enums\AiActionType;
use Modules\AI\Enums\AiCandidateActionType;
use Modules\AI\Enums\AiWorkKind;
use Modules\AI\Enums\AiWorkState;
use Modules\AI\Models\AiProfile;
use OGame\Factories\GameMissionFactory;
use OGame\Factories\PlayerServiceFactory;
use OGame\Services\ObjectService;

/**
 * One live account, the way the Situation kit explains a test account: what it owns, what is running,
 * what it did lately, what was refused and why, and what its last decisions weighed.
 *
 * DEV TOOLING, read-only (host getters only). When a live situation or aspect fails, this is the
 * answer to "why did *this* account not do it", in one screen instead of five SQL queries.
 *
 *   php Modules/AI/scripts/live-account.php PLAYER_ID [MINUTES=60]
 */

$root = dirname(__DIR__, 3);

require $root.'/vendor/autoload.php';

$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$playerId = (int) ($argv[1] ?? 0);
$minutes = max(1, (int) ($argv[2] ?? 60));
$since = CarbonImmutable::now()->subMinutes($minutes);
$profile = AiProfile::query()->where('player_id', $playerId)->first();
if ($profile === null) {
    echo "ACCOUNT: $playerId is not an AI account in this universe\n";

    exit(1);
}

$player = app(PlayerServiceFactory::class)->make($playerId);
$lastLogin = (int) DB::table('users')->where('id', $playerId)->value('time');
printf("ACCOUNT %d %s — %s, %s, %s; last activity %s\n", $playerId, $player->getUsername(), $profile->archetype->name,
    $profile->skill_band->name, $profile->enabled ? 'enabled' : 'DISABLED', CarbonImmutable::createFromTimestamp($lastLogin)->diffForHumans());

$name = static fn (int $objectId): string => ObjectService::getObjectById($objectId)->machine_name;
$planetIds = [];
foreach ($player->planets->all() as $planet) {
    $planetIds[] = $planet->getPlanetId();
    $at = $planet->getPlanetCoordinates();
    printf("\n  planet %d [%d:%d:%d] metal %s crystal %s deut %s, fields %d/%d\n", $planet->getPlanetId(), $at->galaxy, $at->system, $at->position,
        number_format((int) $planet->metal()->get()), number_format((int) $planet->crystal()->get()), number_format((int) $planet->deuterium()->get()),
        $planet->getBuildingCount(), $planet->getPlanetFieldMax());
    $building = DB::table('building_queues')->where('planet_id', $planet->getPlanetId())->where('processed', 0)->where('canceled', 0)->orderBy('time_start')->get();
    $research = DB::table('research_queues')->where('planet_id', $planet->getPlanetId())->where('processed', 0)->where('canceled', 0)->get();
    $units = DB::table('unit_queues')->where('planet_id', $planet->getPlanetId())->where('processed', 0)->get();
    echo '    build queue: '.($building->isEmpty() ? 'IDLE' : $building->map(fn ($row) => $name((int) $row->object_id).' '.$row->object_level_target.' (ends in '.max(0, (int) round(($row->time_end - time()) / 60)).' min)')->implode(', '))."\n";
    echo '    lab: '.($research->isEmpty() ? 'idle' : $research->map(fn ($row) => $name((int) $row->object_id).' '.$row->object_level_target)->implode(', '))
        .' | shipyard: '.($units->isEmpty() ? 'idle' : $units->map(fn ($row) => $row->object_amount.' '.$name((int) $row->object_id))->implode(', '))."\n";
    $ships = array_filter($planet->getShipUnits()->toArray());
    $defence = array_filter($planet->getDefenseUnits()->toArray());
    echo '    ships: '.($ships === [] ? 'none' : implode(', ', array_map(fn ($k, $v) => "$v $k", array_keys($ships), $ships)))."\n";
    echo '    defence: '.($defence === [] ? 'none' : implode(', ', array_map(fn ($k, $v) => "$v $k", array_keys($defence), $defence)))."\n";
}

$classes = GameMissionFactory::getMissionClasses();
$flying = DB::table('fleet_missions')->where('user_id', $playerId)->where('processed', 0)->where('canceled', 0)->orderBy('time_arrival')->get();
echo "\n  fleets in flight: ".($flying->isEmpty() ? 'none' : '')."\n";
foreach ($flying as $mission) {
    printf("    %s to [%d:%d:%d], arrives in %d min%s\n", preg_replace('/Mission$/', '', class_basename($classes[(int) $mission->mission_type] ?? 'Unknown')),
        $mission->galaxy_to, $mission->system_to, $mission->position_to, max(0, (int) round(($mission->time_arrival - time()) / 60)), $mission->parent_id ? ' (return)' : '');
}

echo "\n  work in the last $minutes min (newest first):\n";
$receipts = DB::table('ai_action_receipts')->where('player_id', $playerId)->where('created_at', '>=', $since)->get()->keyBy('idempotency_key');
foreach (DB::table('ai_work_items')->where('player_id', $playerId)->where('updated_at', '>=', $since)->orderByDesc('id')->limit(20)->get() as $item) {
    $receipt = $receipts[$item->idempotency_key] ?? null;
    $refused = $receipt !== null && (int) $receipt->state === 3 ? ' REFUSED '.AiActionType::from((int) $receipt->action_type)->name.': '.json_encode(json_decode((string) $receipt->result, true)['reason'] ?? null) : '';
    printf("    %s %-18s %-9s%s\n", CarbonImmutable::parse($item->updated_at)->format('H:i'), AiWorkKind::from((int) $item->kind)->name, AiWorkState::from((int) $item->state)->name, $refused);
}

echo "\n  last decisions (ranked candidates):\n";
foreach (DB::table('ai_decision_traces')->where('player_id', $playerId)->orderByDesc('id')->limit(3)->get(['created_at', 'candidates', 'selected_action', 'score_components']) as $trace) {
    $ranked = array_map(static function (array $candidate): string {
        $parts = [];
        foreach (array_filter($candidate['components'] ?? []) as $component => $value) {
            $parts[] = $component.'='.round((float) $value, 1);
        }

        return sprintf('%s %.1f (%s)', $candidate['action'], (float) $candidate['score'], implode(', ', $parts));
    }, array_slice(json_decode((string) $trace->candidates, true) ?: [], 0, 6));
    $rejected = json_decode((string) $trace->score_components, true)['rejections'] ?? [];
    $rejected = $rejected === [] ? '' : "\n          not offered: ".implode(', ', array_map(fn ($k, $v) => "$k ($v)", array_keys($rejected), $rejected));
    echo '    '.CarbonImmutable::parse($trace->created_at)->format('H:i').' chose '.(AiCandidateActionType::tryFrom((int) $trace->selected_action)?->name ?? $trace->selected_action).' from: '.implode('; ', $ranked).$rejected."\n";
}
