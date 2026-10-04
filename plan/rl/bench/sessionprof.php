<?php
// Phase profile of one RunAiSessionAction per account at a frozen instant (no writes are rolled back: run on a snapshot).
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
config(['queue.default'=>'sync','ai.cognition.mode'=>'native','ai.cognition.memory.driver'=>'native','ai.cognition.experience.driver'=>'native','ai.cognition.conversation.enabled'=>false,'ai.language.enabled'=>false]);
Illuminate\Support\Facades\Http::preventStrayRequests();
$at = $argv[1] ?? '2026-10-08T00:00:00Z'; Modules\AI\Support\SimulatedTime::freezeAt($at);
$opts = json_decode($argv[2] ?? '{}', true);
foreach (($opts['config'] ?? []) as $k=>$v) config([$k=>$v]);
$q=0; $dbt=0.0; DB::listen(function($e) use(&$q,&$dbt){ $q++; $dbt+=$e->time; });
$phase = []; $t = function(string $name, callable $f) use (&$phase,&$q,&$dbt) { $q0=$q; $d0=$dbt; $s=hrtime(true); $r=$f(); $phase[$name][0]=($phase[$name][0]??0)+(hrtime(true)-$s)/1e6; $phase[$name][1]=($phase[$name][1]??0)+$q-$q0; $phase[$name][2]=($phase[$name][2]??0)+$dbt-$d0; $phase[$name][3]=($phase[$name][3]??0)+1; return $r; };
$profiles = Modules\AI\Models\AiProfile::query()->where('enabled',true)->orderBy('player_id')->get();
$reps = (int)($opts['reps'] ?? 1);
DB::beginTransaction();
for ($r=0;$r<$reps;$r++) foreach ($profiles as $profile) {
  $wi = Modules\AI\Models\AiWorkItem::query()->create(['player_id'=>$profile->player_id,'kind'=>Modules\AI\Enums\AiWorkKind::RunSession,'due_at'=>now(),'schedule_generation'=>9000+$r,'state'=>Modules\AI\Enums\AiWorkState::Leased,'idempotency_key'=>'prof:'.$profile->player_id.':'.$r.':'.uniqid()]);
  $t('1 host advance (all planets update)', function() use($profile){ $p=app(OGame\Services\PlayerGameStateService::class)->advance($profile->player_id,null,false); foreach($p->planets->all() as $pl) $pl->update(); });
  if (!($opts['no_alliance'] ?? false)) $t('2 alliance life', fn()=>app(Modules\AI\Actions\AdvanceAiAllianceLifeAction::class)->handle());
  $trace = $t('3 decision (perceive+candidates+score+trace+next session)', fn()=>app(Modules\AI\Domain\Scheduling\SessionDecisionService::class)->run($profile,$wi));
  $t('4 schedule intents (planners+managers)', fn()=>app(Modules\AI\Actions\ScheduleAiIntentAction::class)->handle($profile,$wi,$trace));
}
// execute the orders the sessions wrote (the executors)
$items = Modules\AI\Models\AiWorkItem::query()->whereIn('state',[1])->where('kind','!=',Modules\AI\Enums\AiWorkKind::RunSession)->where('due_at','<=',now()->addHour())->limit(200)->get();
foreach ($items as $it) { $kind=$it->kind->name; $t('5 execute '.$kind, function() use($it){ try { app()->makeWith(Modules\AI\Jobs\ProcessAiWork::class,['workItemId'=>$it->id])->handle(); } catch (Throwable $e) {} }); }
DB::rollBack();
$tot=0; foreach($phase as $n=>[$ms,$qq,$d,$c]) $tot+=$ms;
foreach($phase as $n=>[$ms,$qq,$d,$c]) printf("%-62s calls %4d  %8.2f ms/call  %6.1f queries/call  DB %4.0f%%  share %4.1f%%\n",$n,$c,$ms/$c,$qq/$c,$ms>0?$d/$ms*100:0,$ms/$tot*100);
printf("TOTAL %.0f ms\n",$tot);
