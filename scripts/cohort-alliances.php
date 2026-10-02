<?php

/**
 * Alliance life of the whole cohort in under a minute, instead of days of real waiting.
 *
 * DEV TOOLING. Ages the pending applications past the leader's reading time and lifts the host's
 * join/create cooldown, then runs the real alliance pass 60 times and prints members per alliance
 * (0 = none).
 *
 *   docker compose -f local-docker-dev/docker-compose.grand.yml exec -T ogamex-app \
 *     php artisan tinker --execute="require '/var/www/Modules/AI/scripts/cohort-alliances.php';"
 */
$t=microtime(true);
for ($i=1;$i<=60;$i++){
 OGame\Models\User::query()->whereNull('alliance_id')->update(['alliance_left_at'=>null]);
 OGame\Models\AllianceApplication::query()->where('status',OGame\Models\AllianceApplication::STATUS_PENDING)->update(['created_at'=>now()->subDays(2)]);
 app(Modules\AI\Actions\AdvanceAiAllianceLifeAction::class)->handle();
}
$ids=Modules\AI\Models\AiProfile::query()->where('enabled',true)->select('player_id');
echo json_encode(OGame\Models\User::query()->whereIn('id',$ids)->selectRaw('coalesce(alliance_id,0) a,count(*) c')->groupBy('a')->pluck('c','a'))." ".round(microtime(true)-$t,1)."s\n";
