<?php
// Parity: load MySQL state (port 3307) into in-memory SQLite or play it on MySQL directly, run ai:sim, print a state digest.
// usage: php parity.php mysql|sqlite HOURS FROM
$mode = $argv[1]; $hours = (float)$argv[2]; $from = $argv[3];
if ($mode === 'sqlite') { foreach (['DB_CONNECTION'=>'sqlite','DB_DATABASE'=>':memory:'] as $k=>$v) { putenv("$k=$v"); $_ENV[$k]=$v; $_SERVER[$k]=$v; } }
if ($mode === 'mysql') { foreach (['DB_PORT'=>'3307'] as $k=>$v) { putenv("$k=$v"); $_ENV[$k]=$v; $_SERVER[$k]=$v; } }
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\{Artisan, DB, Schema};
$o = new Symfony\Component\Console\Output\BufferedOutput();
if ($mode === 'sqlite') {
  config(['database.connections.src' => array_merge(config('database.connections.mysql'), ['host'=>'127.0.0.1','port'=>3307,'database'=>'laravel'])]);
  Artisan::call('migrate', ['--force'=>true], $o);
  $t = microtime(true); $rows = 0;
  DB::statement('PRAGMA foreign_keys = OFF');
  foreach (DB::connection('src')->select('show tables') as $r) { $table = array_values((array)$r)[0];
    if ($table === 'migrations' || !Schema::hasTable($table)) continue;
    DB::table($table)->delete();
    DB::connection('src')->table($table)->orderByRaw('1')->chunk(2000, function ($chunk) use ($table, &$rows) { DB::table($table)->insert(array_map(fn($x)=>(array)$x, $chunk->all())); $rows += count($chunk); });
  }
  printf("copied %d rows from MySQL into :memory: in %.1f s\n", $rows, microtime(true)-$t);
}
$out = new Symfony\Component\Console\Output\BufferedOutput();
$t = microtime(true);
Artisan::call('ai:sim', ['--hours'=>$hours,'--native-cognition'=>true,'--from'=>$from,'--force-db'=>true], $out);
$wall = microtime(true)-$t;
foreach (explode("\n", $out->fetch()) as $l) if (str_starts_with($l,'SIM: ') || str_contains($l,'due work') || str_contains($l,'  work item') ) echo $l, "\n";
printf("WALL %.1f s\n", $wall);
// digest of game state
$cols = ['metal_mine','crystal_mine','deuterium_synthesizer','solar_plant','robot_factory','shipyard','research_lab','metal_store','crystal_store','small_cargo','light_fighter','espionage_probe','rocket_launcher','light_laser'];
foreach (DB::table('planets')->orderBy('id')->get() as $p) { echo "P{$p->id} u{$p->user_id} "; foreach ($cols as $c) echo $p->$c, ','; printf(" m=%.0f c=%.0f d=%.0f\n", $p->metal, $p->crystal, $p->deuterium); }
echo "techs: ", json_encode(DB::table('users_tech')->orderBy('user_id')->get(['user_id','energy_technology','computer_technology','combustion_drive','espionage_technology'])->map(fn($r)=>array_values((array)$r))), "\n";
echo "counts: work_items=", DB::table('ai_work_items')->count(), " traces=", DB::table('ai_decision_traces')->count(), " bq=", DB::table('building_queues')->count(), " rq=", DB::table('research_queues')->count(), " uq=", DB::table('unit_queues')->count(), " fm=", DB::table('fleet_missions')->count(), "\n";
echo "selected actions: ", json_encode(DB::table('ai_decision_traces')->selectRaw('selected_action, count(*) n')->groupBy('selected_action')->orderBy('selected_action')->pluck('n','selected_action')), "\n";
