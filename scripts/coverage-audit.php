<?php

/**
 * North-star coverage: does the AI cohort use everything a human player has in the game?
 * Reads the object catalogue, the mission types and the aspect tables from the host, so a new object, ship or
 * mission is audited without an edit here. Prints one `GAP <kind> <name>: <detail>` line per thing no AI account
 * uses, and `COVER <what>: <n>` lines for the totals. Run inside the app container:
 *   php artisan tinker --execute='require "Modules/AI/scripts/coverage-audit.php";'
 */

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\AI\Models\AiProfile;
use OGame\Services\ObjectService;

$ai = AiProfile::query()->pluck('player_id')->all();
$accounts = count($ai);
$gaps = 0;
$gap = function (string $kind, string $name, string $detail) use (&$gaps): void {
    $gaps++;
    echo "GAP $kind $name: $detail\n";
};
echo "COVER accounts: $accounts\n";

// Buildings, stations, ships, defence: columns of the planets table; research: columns of users_tech.
foreach (app(ObjectService::class)->getObjects() as $object) {
    $name = $object->machine_name;
    $type = is_object($object->type) ? $object->type->name : (string) $object->type;
    $table = $type === 'Research' ? 'users_tech' : 'planets';
    $owner = 'user_id';
    if (!Schema::hasColumn($table, $name)) {
        continue;
    }
    $holders = DB::table($table)->whereIn($owner, $ai)->where($name, '>', 0)->distinct()->count($owner);
    if ($holders === 0) {
        $gap('object', "$type/$name", "no AI account has any");
    } elseif ($holders * 10 < $accounts) {
        echo "THIN object $type/$name: $holders of $accounts accounts\n";
    }
}

// Every mission type the host implements, flown by an AI account in the last 48 hours.
$flown = DB::table('fleet_missions')->whereIn('user_id', $ai)->where('time_departure', '>', time() - 48 * 3600)
    ->select('mission_type', DB::raw('count(*) c'))->groupBy('mission_type')->pluck('c', 'mission_type')->all();
foreach (glob(base_path('app/GameMissions/*Mission.php')) as $file) {
    $class = 'OGame\\GameMissions\\' . basename($file, '.php');
    if (!class_exists($class) || (new ReflectionClass($class))->isAbstract() || !method_exists($class, 'getTypeId')) {
        continue;
    }
    $id = $class::getTypeId();
    if (!isset($flown[$id])) {
        $gap('mission', basename($file, '.php'), "no AI fleet flew it in 48 h (type $id)");
    }
}

// Aspects: fights, defence, alliances, hatred and friendship, colonies, moons.
$battles = DB::table('battle_reports')->where('created_at', '>', now()->subDay())->count();
echo "COVER battles last 24h: $battles\n";
$battles === 0 && $gap('aspect', 'raids', 'no battle report in 24 h');
$members = Schema::hasTable('alliance_members') ? DB::table('alliance_members')->whereIn('user_id', $ai)->count() : 0;
$apps = Schema::hasTable('alliance_applications') ? DB::table('alliance_applications')->whereIn('user_id', $ai)->count() : 0;
echo "COVER alliance members: $members, applications: $apps\n";
$members === 0 && $gap('aspect', 'alliances', 'no AI account is in an alliance');
$hate = DB::table('ai_relationships')->whereIn('player_id', $ai)->where('affinity', '<', -0.2)->count();
$friend = DB::table('ai_relationships')->whereIn('player_id', $ai)->where('affinity', '>', 0.2)->count();
echo "COVER hatred links: $hate, friendship links: $friend\n";
$hate === 0 && $gap('aspect', 'hatred', 'no AI relationship below -0.2 affinity');
$friend === 0 && $gap('aspect', 'friendship', 'no AI relationship above 0.2 affinity');
$colonies = DB::table('planets')->whereIn('user_id', $ai)->where('planet_type', 1)->count();
$moons = DB::table('planets')->whereIn('user_id', $ai)->where('planet_type', 3)->count();
echo "COVER planets: $colonies, moons: $moons\n";
$colonies <= $accounts && $gap('aspect', 'colonies', "$colonies planets for $accounts accounts: nobody colonised");
$moons === 0 && $gap('aspect', 'moons', 'no AI account owns a moon');
$defended = DB::table('planets')->whereIn('user_id', $ai)->where('planet_type', 1)->where('rocket_launcher', '>', 0)->count();
echo "COVER planets with defence: $defended of $colonies\n";

echo "AUDIT gaps: $gaps\n";
