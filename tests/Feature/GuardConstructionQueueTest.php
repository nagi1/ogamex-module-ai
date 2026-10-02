<?php

use Modules\AI\Actions\GuardConstructionQueue;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

it('schedules nothing while the construction queue state is unknown', function (mixed $snapshot) {
    $result = app(GuardConstructionQueue::class)->handle($snapshot);

    expect($result)->toBe([
        'decision' => GuardConstructionQueue::DECISION_HOLD,
        'reason' => GuardConstructionQueue::REASON_CONSTRUCTION_QUEUE_UNKNOWN,
    ]);
})->with([
    'absent' => [null],
    'empty' => [[]],
    'unreadable' => ['unparsed queue'],
]);

it('commits against the reported snapshot alone', function () {
    $snapshot = ['slots' => [['building_id' => 3, 'finish_time' => 1900000000]]];

    expect(app(GuardConstructionQueue::class)->handle($snapshot))->toBe([
        'decision' => GuardConstructionQueue::DECISION_COMMIT,
        'snapshot' => $snapshot,
    ]);
});
