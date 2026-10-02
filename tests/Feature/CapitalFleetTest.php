<?php

use Modules\AI\Domain\Decision\QueueableUnit;
use Modules\AI\Domain\Decision\QueueableUnitPlanner;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Models\AiProfile;
use OGame\Models\Resources;
use OGame\Services\ObjectService;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

// LIFE-002: an account with a yard and nothing urgent grows a war fleet from the strongest military hull the
// host lets it build, so large battles and, once research allows, death stars appear without the module naming one.

function capitalProfile(int $playerId): void
{
    AiProfile::create([
        'player_id' => $playerId,
        'archetype' => AiArchetype::Fleeter,
        'skill_band' => AiSkillBand::Standard,
        'random_seed' => 7,
        'enabled' => true,
    ]);
}

test('a rich account with a capable yard orders the strongest military hull it can build', function (): void {
    capitalProfile($this->currentUserId);
    $this->planetAddResources(new Resources(50_000_000, 30_000_000, 20_000_000));
    $this->planetSetObjectLevel('shipyard', 8);
    $this->planetSetObjectLevel('robot_factory', 10);
    $this->playerSetResearchLevel('combustion_drive', 6);
    $this->playerSetResearchLevel('impulse_drive', 6);
    $this->playerSetResearchLevel('weapon_technology', 6);
    $this->playerSetResearchLevel('shielding_technology', 6);
    $this->playerSetResearchLevel('armor_technology', 6);
    $this->planetAddUnit('large_cargo', 5);
    $this->planetAddUnit('small_cargo', 5);
    $this->planetAddUnit('espionage_probe', 1);
    $this->planetAddUnit('colony_ship', 1);
    $this->planetAddUnit('light_fighter', 1);
    $this->planetAddUnit('rocket_launcher', 20_000);

    $plan = app(QueueableUnitPlanner::class)->plan($this->currentUserId);

    expect($plan)->toBeInstanceOf(QueueableUnit::class)
        ->and($plan->reason)->toStartWith('role:capital:');

    // The hull is the dearest the host lets this planet build, read from the catalogue's own prices.
    $dearest = 0.0;
    foreach (ObjectService::getMilitaryShipObjects() as $hull) {
        if (ObjectService::objectRequirementsMet($hull->machine_name, $this->planetService) && $hull->machine_name !== 'espionage_probe') {
            $price = ObjectService::getObjectPrice($hull->machine_name, $this->planetService);
            $dearest = max($dearest, $price->metal->get() + $price->crystal->get() + $price->deuterium->get());
        }
    }
    $chosen = ObjectService::getObjectPrice(str_replace('role:capital:', '', $plan->reason), $this->planetService);

    expect($chosen->metal->get() + $chosen->crystal->get() + $chosen->deuterium->get())->toBe($dearest)
        ->and($plan->amount)->toBeGreaterThan(1);
});

test('a poor account with the same yard orders no capital hull', function (): void {
    capitalProfile($this->currentUserId);
    $this->planetAddResources(new Resources(500, 500, 0));
    $this->planetSetObjectLevel('shipyard', 8);

    $plan = app(QueueableUnitPlanner::class)->plan($this->currentUserId);

    expect($plan === null || ! str_starts_with($plan->reason, 'role:capital:'))->toBeTrue();
});
