<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Modules\AI\Domain\Decision\QueueableBuildingPlanner;
use Modules\AI\Domain\Decision\QueueableResearch;
use Modules\AI\Models\AiProfile;
use OGame\Factories\PlayerServiceFactory;
use OGame\GameObjects\Models\Enums\GameObjectType;
use OGame\Services\ObjectService;

/**
 * Why each planet of an account is or is not building, read from the planner's own candidate
 * lists and the host gates it asks. DEV TOOLING, read-only; required by cohort-scenario.php and
 * verify-cohorts.php, so a failed economy situation names its cause in the same run.
 *
 * @return list<string> one line per planet
 */
function explain_economy(int $playerId): array
{
    $profile = AiProfile::query()->where('player_id', $playerId)->first();
    if ($profile === null) {
        return ["player {$playerId} has no AI profile"];
    }

    $planner = app(QueueableBuildingPlanner::class);
    $player = app(PlayerServiceFactory::class)->make($playerId, true);
    $taken = [];
    foreach ($planner->steps($playerId, $player) as $step) {
        $taken[$step->planetId] = $step;
    }

    $lines = [];
    foreach ($player->planets->all() as $planet) {
        $id = $planet->getPlanetId();
        $resources = $planet->getResources();
        $stock = sprintf('metal %s crystal %s deut %s', number_format($resources->metal->get()),
            number_format($resources->crystal->get()), number_format($resources->deuterium->get()));

        if (DB::table('building_queues')->where('planet_id', $id)->where('processed', 0)->where('canceled', 0)->exists()) {
            $lines[] = "p{$id} building | {$stock}";

            continue;
        }

        if (isset($taken[$id])) {
            $objectId = $taken[$id] instanceof QueueableResearch ? $taken[$id]->researchId : $taken[$id]->buildingId;
            $lines[] = "p{$id} idle, would queue ".ObjectService::getObjectById($objectId)->machine_name." ({$taken[$id]->reason}) | {$stock}";

            continue;
        }

        $why = [];
        foreach ($planner->passes($profile) as $pass => $candidates) {
            $reasons = [];
            foreach ($candidates($planet) as $candidate) {
                $reason = ObjectService::getObjectById($candidate->buildingId)->type === GameObjectType::Research
                    ? 'technology (lab already taken or refused)'
                    : ($planner->refusal($planet, $candidate) ?? 'queueable');
                $reasons[$reason] = ($reasons[$reason] ?? 0) + 1;
            }
            $why[] = $pass.': '.($reasons === [] ? 'no candidate' : implode(', ', array_map(
                static fn (string $reason, int $count): string => "{$reason} x{$count}",
                array_keys($reasons),
                $reasons,
            )));
        }

        $lines[] = "p{$id} IDLE — ".implode('; ', $why)." | {$stock}";
    }

    return $lines;
}
