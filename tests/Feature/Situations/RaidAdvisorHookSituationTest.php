<?php

use Modules\AI\Domain\Advisor\Advisor;
use Modules\AI\Domain\Advisor\AdvisorQuestion;
use Modules\AI\Domain\Advisor\AdvisorVerdict;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Support\AiClock;
use Modules\AI\Tests\Support\RaidStory;
use Modules\AI\Tests\Support\Situation;
use Tests\IsolatedAccountTestCase;

require_once __DIR__ . '/../../Support/Situation.php';
require_once __DIR__ . '/../../Support/RaidStory.php';

uses(IsolatedAccountTestCase::class);

// The optional advisor on a costly raid (area 4). Off by default, it is never asked and the raid flies as the rules
// planned it; in advice mode a "wait" holds the fleet. The advisor here is a stand-in: no provider is called.

/** An advisor that answers every question with $choice and counts the questions. */
function raidAdvisorStandIn(string $choice): Advisor
{
    $advisor = new class (app(AiClock::class), $choice) extends Advisor {
        public int $asked = 0;

        public function __construct(AiClock $clock, private readonly string $choice)
        {
            parent::__construct($clock);
        }

        public function consult(AdvisorQuestion $question): AdvisorVerdict
        {
            $this->asked++;

            return new AdvisorVerdict($this->choice);
        }
    };
    app()->instance(Advisor::class, $advisor);

    return $advisor;
}

/** A raider with a fleet and one farm it holds a fresh full report on. */
function raidAdvisorStory(object $test): Situation
{
    $situation = Situation::of($test)
        ->archetype(AiArchetype::Raider)
        ->research('combustion_drive', 8)
        ->ships('large_cargo', 60)
        ->ships('light_fighter', 60)
        ->resources(500_000, 500_000, 1_000_000)
        ->colony();
    RaidStory::report($test, RaidStory::farm($test));

    return $situation;
}

test('with the advisor off a costly raid is not put to it and flies as planned', function (): void {
    $advisor = raidAdvisorStandIn('wait');
    config(['ai.campaign-consultation.advisor.modes.costly' => 'off', 'ai.campaign-consultation.advisor.costly_share' => 0.0]);

    raidAdvisorStory($this)->session()->expectMission('Attack');
    expect($advisor->asked)->toBe(0);
});

test('in advice mode a costly raid the advisor says to wait on is held', function (): void {
    $advisor = raidAdvisorStandIn('wait');
    config(['ai.campaign-consultation.advisor.modes.costly' => 'advice', 'ai.campaign-consultation.advisor.costly_share' => 0.0]);

    raidAdvisorStory($this)->session()->expectNoMission('Attack');
    expect($advisor->asked)->toBeGreaterThan(0);
});

test('in advice mode a costly raid the advisor lets go flies', function (): void {
    raidAdvisorStandIn('go');
    config(['ai.campaign-consultation.advisor.modes.costly' => 'advice', 'ai.campaign-consultation.advisor.costly_share' => 0.0]);

    raidAdvisorStory($this)->session()->expectMission('Attack');
});
