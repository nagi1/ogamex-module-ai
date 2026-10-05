<?php

use Modules\AI\Actions\StageAiAccountAction;
use Modules\AI\Domain\Decision\QueueableBuildingPlanner;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Models\AiProfile;
use OGame\Factories\PlayerServiceFactory;
use OGame\Services\ObjectService;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

// `ai:rl-universe --stage-budget` starts training accounts in the mid and late game. A staged account must be one the
// planner can keep playing: levels the host's requirements allow, fields left for what adds fields, stores to spend.

dataset('stage budgets', [
    'mid game' => [5e7],
    'late game' => [2e11],
]);

test('a staged account holds the levels its budget buys and the planner keeps building', function (float $budget): void {
    AiProfile::create([
        'player_id' => $this->currentUserId,
        'archetype' => AiArchetype::Miner,
        'skill_band' => AiSkillBand::Standard,
        'random_seed' => 7,
        'enabled' => true,
    ]);

    $steps = app(StageAiAccountAction::class)->handle($this->currentUserId, $budget);
    $player = app(PlayerServiceFactory::class)->make($this->currentUserId, true);
    $planet = $player->planets->first();
    $researched = array_sum(array_map(static fn ($object): int => $player->getResearchLevel($object->machine_name), ObjectService::getResearchObjects()));

    expect($steps)->toBeGreaterThan(50)
        ->and($researched)->toBeGreaterThan(0)
        ->and($planet->getBuildingCount())->toBeLessThan($planet->getPlanetFieldMax())
        ->and($planet->getResources()->metal->get())->toBeGreaterThanOrEqual($planet->metalStorage()->get())
        ->and(app(QueueableBuildingPlanner::class)->steps($this->currentUserId, $player))->not->toBeEmpty();
})->with('stage budgets');

test('a larger budget reaches deeper into the catalogue', function (): void {
    $levels = [];
    foreach ([5e7, 2e11] as $budget) {
        $player = app(PlayerServiceFactory::class)->make($this->currentUserId, true);
        foreach (ObjectService::getResearchObjects() as $object) {
            $player->setResearchLevel($object->machine_name, 0);
        }
        app(StageAiAccountAction::class)->handle($this->currentUserId, $budget);
        $player = app(PlayerServiceFactory::class)->make($this->currentUserId, true);
        $levels[] = array_sum(array_map(static fn ($object): int => $player->getResearchLevel($object->machine_name), ObjectService::getResearchObjects()));
    }

    expect($levels[1])->toBeGreaterThan($levels[0]);
});
