<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
config(['queue.default'=>'sync','ai.cognition.mode'=>'native','ai.cognition.memory.driver'=>'native','ai.cognition.experience.driver'=>'native','ai.cognition.conversation.enabled'=>false,'ai.language.enabled'=>false]);
Modules\AI\Support\SimulatedTime::freezeAt($argv[1] ?? '2026-10-08T00:00:00Z');
$q=0; DB::listen(function($e) use(&$q){ $q++; });
$P=[]; $t=function($n,$f) use(&$P,&$q){$q0=$q;$s=hrtime(true);$r=$f();$P[$n][0]=($P[$n][0]??0)+(hrtime(true)-$s)/1e6;$P[$n][1]=($P[$n][1]??0)+$q-$q0;$P[$n][2]=($P[$n][2]??0)+1;return $r;};
$profiles = Modules\AI\Models\AiProfile::query()->where('enabled',true)->orderBy('player_id')->get();
$fac = app(Modules\AI\Domain\Decision\CandidateActionFactory::class);
$rf = new ReflectionClass($fac);
DB::beginTransaction();
foreach ($profiles as $pr) {
  $perc = $t('perception build', fn()=>app(Modules\AI\Domain\Perception\PlayerPerceptionBuilder::class)->build($pr->player_id, 60));
  foreach (['raidCandidatesFromVisibleReports','eligibleFleetSaveCandidates','eligibleExpeditionCandidates','eligibleTransferCandidates','eligibleRelocationCandidates','eligibleJumpGateCandidates','eligibleMissileCandidates','eligibleTradeCandidates','eligibleDefendCandidates','eligibleRecycleCandidates','phalanxCandidate'] as $m) {
    $mm=$rf->getMethod($m); $t('candidates: '.$m, fn()=>$mm->invoke($fac,$perc));
  }
  $gen = $t('candidates: full create()', fn()=>$fac->create($perc));
  $t('score (UtilityScorer, affect weight on)', fn()=>app(Modules\AI\Domain\Decision\UtilityScorer::class)->score($pr,$gen,'k'));
  $t('building planner steps()', fn()=>app(Modules\AI\Domain\Decision\QueueableBuildingPlanner::class)->steps($pr->player_id));
  $t('unit planner plan()', fn()=>app(Modules\AI\Domain\Decision\QueueableUnitPlanner::class)->plan($pr->player_id));
  $t('PlayerServiceFactory->make(fresh)', fn()=>app(OGame\Factories\PlayerServiceFactory::class)->make($pr->player_id,true));
}
DB::rollBack();
foreach($P as $n=>[$ms,$qq,$c]) printf("%-55s %8.2f ms/call %7.1f q/call\n",$n,$ms/$c,$qq/$c);
$pl = DB::table('planets')->selectRaw('user_id, count(*) c')->groupBy('user_id')->pluck('c'); echo "planets per account: ", $pl->implode(','), "\n";
echo "fleet missions active: ", DB::table('fleet_missions')->where('processed',0)->count(), ", espionage reports: ", DB::table('espionage_reports')->count(), ", battle reports: ", DB::table('battle_reports')->count(), "\n";
