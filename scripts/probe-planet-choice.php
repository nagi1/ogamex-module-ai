<?php

/**
 * DEV TOOLING, read-only: which planet does the unit planner choose, and what does the account look
 * like? Answers "why is one planet walled and the rest naked" on the real cohort instead of on a
 * fixture whose two planets may not reproduce it.
 */
use Modules\AI\Domain\Decision\DefenseCompositionPlanner;
use Modules\AI\Domain\Decision\DefenseNeedEvaluator;
use Modules\AI\Domain\Decision\QueueableUnitPlanner;
use Modules\AI\Models\AiProfile;
use OGame\Factories\PlayerServiceFactory;

foreach (AiProfile::query()->where('enabled', true)->orderBy('player_id')->limit(3)->pluck('player_id') as $playerId) {
    $player = app(PlayerServiceFactory::class)->make($playerId, true);
    $plan = app(QueueableUnitPlanner::class)->plan($playerId);

    printf("\nplayer %d: planner chose %s (%s)\n", $playerId,
        $plan === null ? 'nothing' : 'planet '.$plan->planetId,
        $plan === null ? '-' : $plan->reason);

    foreach ($player->planets->all() as $planet) {
        $planet->updateResources(false);
        $planet->updateResourceProductionStats(false);
        $need = app(DefenseNeedEvaluator::class)->evaluate($player, $planet);
        printf("   planet %d %-10s defence=%-7d need=%s\n",
            $planet->getPlanetId(), $planet->getPlanetName(),
            $planet->getDefenseUnits()->getAmount(),
            ($need === null ? 'covered' : number_format($need->defenceValue)).' composition='.(($c = app(DefenseCompositionPlanner::class)->plan($player, $planet, $need)) === null ? 'null' : $c->unit->machine_name.' x'.$c->amount));
    }
}
