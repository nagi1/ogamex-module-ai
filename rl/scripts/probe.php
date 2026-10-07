<?php

/*
 * Fast late-game loop: asks every planner, on a saved snapshot, what each AI account would do now and why it
 * refuses -- seconds instead of a simulated day. Read-only: run it on a copy (probe.sh makes one).
 *
 *   DB_CONNECTION=sqlite DB_DATABASE=<copy.sqlite> CACHE_STORE=array php Modules/AI/rl/scripts/probe.php [player ...]
 */

require __DIR__ . '/../../../../vendor/autoload.php';
$app = require __DIR__ . '/../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Cache;
use Modules\AI\Domain\Decision\QueueableBuildingPlanner;
use Modules\AI\Domain\Decision\QueueableUnitPlanner;
use Modules\AI\Domain\Decision\RaidPlanner;
use Modules\AI\Domain\Login\GamePhaseMachine;
use Modules\AI\Models\AiProfile;
use OGame\Factories\PlayerServiceFactory;
use OGame\Models\EspionageReport;
use OGame\Models\Message;
use OGame\Services\ObjectService;

$reasons = ['no_target', 'target_ineligible', 'newbie', 'bashing_limit', 'cooldown', 'refused_at_dispatch', 'target_already_offered',
    'blacklisted', 'phalanx_refuses', 'no_samples', 'survival_floor', 'unprofitable', 'no_launch_units', 'not_enough_fuel',
    'fuel_tank_short', 'below_loot_tier', 'no_origin', 'origin_refused_at_dispatch'];
$fmt = static fn ($r): string => $r === null ? 'none' : number_format($r->metal->get(), 0, '.', ',') . '/' . number_format($r->crystal->get(), 0, '.', ',') . '/' . number_format($r->deuterium->get(), 0, '.', ',');
$call = static function (object $o, string $m, ...$a) {
    $r = new ReflectionMethod($o, $m);

    return $r->invoke($o, ...$a);
};

$ids = array_map('intval', array_slice($argv, 1)) ?: AiProfile::query()->where('enabled', true)->orderBy('player_id')->pluck('player_id')->all();
$units = app(QueueableUnitPlanner::class);
$builder = app(QueueableBuildingPlanner::class);
$raids = app(RaidPlanner::class);

foreach ($ids as $pid) {
    $player = app(PlayerServiceFactory::class)->make($pid, true);
    $profile = AiProfile::query()->where('player_id', $pid)->first();
    $planets = $player->planets->all();
    if ($planets === [] || $profile === null) {
        continue;
    }
    $home = $planets[0];
    echo "\n== player {$pid} · {$profile->archetype->name} · phase " . app(GamePhaseMachine::class)->of($player)->value . ' · rung ' . app(GamePhaseMachine::class)->targetRung($player)->value . ' · planets ' . count($planets) . "\n";
    echo '   home held ' . $fmt($home->getResources()) . ' · saving ' . $fmt($builder->savingFor($home, $profile)) . "\n";

    $step = $builder->plan($pid, $player);
    echo '   build/research next: ' . ($step === null ? 'nothing' : ($step->reason ?? get_class($step))) . "\n";
    $yard = $units->plan($pid, $player);
    echo '   yard next: ' . ($yard === null ? 'nothing' : $yard->reason . ' x' . $yard->amount) . "\n";

    $capital = $units->capitalFleetOrder($pid, $player);
    $busy = $capital === null ? 0 : OGame\Models\UnitQueue::query()->where('planet_id', $capital->planetId)->where('processed', 0)->count();
    echo '   capital order: ' . ($capital === null ? 'nothing' : ObjectService::getUnitObjectById($capital->unitId)->machine_name . ' x' . $capital->amount . " on planet {$capital->planetId} (yard holds {$busy} order(s))") . "\n";

    // Every warship and defence the host has: buildable here, and if so what holds it back.
    $blocked = [];
    foreach ([...ObjectService::getMilitaryShipObjects(), ...ObjectService::getDefenseObjects()] as $unit) {
        $why = !$call($units, 'requirementsMet', $home, $unit) ? 'requirements'
            : ($call($units, 'affordable', $home, $unit) < 1 ? 'unaffordable'
            : ($call($units, 'starvesSaving', $home, $profile, $unit, 1) ? 'saving' : 'ok'));
        $blocked[$why][] = $unit->machine_name;
    }
    foreach ($blocked as $why => $names) {
        echo "   units {$why}: " . implode(' ', $names) . "\n";
    }

    // The raid screen on every fresh report the account holds, with the reason each one is refused.
    $reportIds = Message::query()->where('user_id', $pid)->whereNotNull('espionage_report_id')->latest('id')->limit(40)->pluck('espionage_report_id')->unique();
    foreach ($reasons as $reason) {
        Cache::forget('ai:raid-rejected:' . $reason);
    }
    $offered = 0;
    foreach ($reportIds as $reportId) {
        $offered += $raids->plan($pid, (int) $reportId) !== null ? 1 : 0;
    }
    $counts = array_filter(array_combine($reasons, array_map(static fn (string $r): int => (int) Cache::get('ai:raid-rejected:' . $r, 0), $reasons)));
    arsort($counts);
    $redacted = EspionageReport::query()->whereIn('id', $reportIds)->where(fn ($q) => $q->whereNull('ships')->orWhereNull('defense'))->count();
    echo '   raids: ' . count($reportIds) . " reports ({$redacted} redacted), {$offered} raid(s) offered; refused " . json_encode($counts) . "\n";
    echo '   probes on home: ' . $home->getObjectAmount('espionage_probe') . "\n";
}
