<?php

use Laravel\Ai\Classification;
use Laravel\Ai\Responses\Data\BooleanAnswer;
use Modules\AI\Actions\ProposeAiDoctrineFromSourceAction;
use Modules\AI\Ai\Agents\OgameDoctrineExtractionAgent;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

// Wiki to doctrine, offline (llm-classifier-everywhere.md, step 5): screened, extracted, checked against the cited
// sentence, and only objects the host has.

beforeEach(function (): void {
    config(['ai.classification.enabled' => true, 'ai.language.provider' => 'openai', 'ai.language.model' => 'gpt-5-mini']);
});

const DOCTRINE_PAGE = "# Raider\nA raider opens with metal mine 1, solar plant 1 and a shipyard 2 before anything else.";

function doctrineEntry(string $object): array
{
    return ['archetype' => 'raider', 'key' => 'opening', 'steps' => [['object' => 'metal_mine', 'value' => 1], ['object' => $object, 'value' => 2]],
        'quote' => 'A raider opens with metal mine 1, solar plant 1 and a shipyard 2 before anything else.'];
}

test('an entry its source sentence supports is proposed with the quote', function (): void {
    Classification::fake([['advice' => new BooleanAnswer(0.9)], ['agrees' => new BooleanAnswer(0.9)]]);
    OgameDoctrineExtractionAgent::fake([['proposals' => [doctrineEntry('shipyard')]]]);

    $proposals = app(ProposeAiDoctrineFromSourceAction::class)->handle(DOCTRINE_PAGE);

    expect($proposals)->toHaveCount(1)
        ->and($proposals[0]['steps'][1])->toBe(['object' => 'shipyard', 'value' => 2.0])
        ->and($proposals[0]['checked'])->toBeTrue();
});

test('an entry the check finds unsupported, or naming an object the host lacks, is dropped', function (string $object, float $agrees): void {
    Classification::fake([['advice' => new BooleanAnswer(0.9)], ['agrees' => new BooleanAnswer($agrees)]]);
    OgameDoctrineExtractionAgent::fake([['proposals' => [doctrineEntry($object)]]]);

    expect(app(ProposeAiDoctrineFromSourceAction::class)->handle(DOCTRINE_PAGE))->toBe([]);
})->with([
    'an entry the source does not say' => ['shipyard', 0.2],
    'an invented object' => ['warp_gate', 0.9],
]);

test('a page without build advice is never sent to the language model', function (): void {
    Classification::fake([['advice' => new BooleanAnswer(0.1)]]);
    OgameDoctrineExtractionAgent::fake()->preventStrayPrompts();

    expect(app(ProposeAiDoctrineFromSourceAction::class)->handle("# History\nOGame launched in 2002."))->toBe([]);
    OgameDoctrineExtractionAgent::assertNeverPrompted();
});
