<?php
// Fingerprint of game state for parity checks: planets, research, battles, missions, debris, decisions.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
$cols = array_map(fn($o) => $o->machine_name, [...OGame\Services\ObjectService::getBuildingObjects(), ...OGame\Services\ObjectService::getStationObjects(), ...OGame\Services\ObjectService::getShipObjects(), ...OGame\Services\ObjectService::getDefenseObjects()]);
foreach (DB::table('planets')->orderBy('id')->get() as $p) { echo "P{$p->id} u{$p->user_id} d{$p->destroyed} "; foreach ($cols as $c) echo (int) $p->$c, ','; printf(" m=%.0f c=%.0f d=%.0f\n", $p->metal, $p->crystal, $p->deuterium); }
$tech = array_map(fn($o) => $o->machine_name, OGame\Services\ObjectService::getResearchObjects());
foreach (DB::table('users_tech')->orderBy('user_id')->get() as $t) { echo "T{$t->user_id} "; foreach ($tech as $c) echo (int) $t->$c, ','; echo "\n"; }
echo "battle_reports=", DB::table('battle_reports')->count(), " espionage_reports=", DB::table('espionage_reports')->count(), " debris=", DB::table('debris_fields')->count(), " wreck=", DB::table('wreck_fields')->count(), "\n";
echo "missions: ", json_encode(DB::table('fleet_missions')->selectRaw('mission_type, count(*) n, sum(processed) p')->groupBy('mission_type')->orderBy('mission_type')->get()), "\n";
echo "work_items=", DB::table('ai_work_items')->count(), " traces=", DB::table('ai_decision_traces')->count(), " bq=", DB::table('building_queues')->count(), " rq=", DB::table('research_queues')->count(), " uq=", DB::table('unit_queues')->count(), "\n";
echo "selected: ", json_encode(DB::table('ai_decision_traces')->selectRaw('selected_action, count(*) n')->groupBy('selected_action')->orderBy('selected_action')->pluck('n', 'selected_action')), "\n";
echo "last battle: ", json_encode(DB::table('battle_reports')->orderByDesc('id')->limit(1)->get(['id','planet_galaxy','planet_system','planet_position'])), "\n";
