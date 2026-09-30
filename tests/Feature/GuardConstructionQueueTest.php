<?php

use Illuminate\Foundation\Application;
use Modules\AI\Actions\GuardConstructionQueue;
use Modules\AI\Actions\QueueAiBuildingAction;
use OGame\Services\ModuleSlotService;
use Tests\IsolatedAccountTestCase;

class GuardConstructionQueueModuleTestCase extends IsolatedAccountTestCase
{
    private string $statusesFile;

    public function createApplication(): Application
    {
        $trackedFile = dirname(__DIR__, 4) . '/modules_statuses.json';
        $statuses = json_decode((string) file_get_contents($trackedFile), true);
        $statuses['AI'] = true;
        $this->statusesFile = sys_get_temp_dir() . '/modules_statuses_' . uniqid('', true) . '.json';
        file_put_contents($this->statusesFile, json_encode($statuses, JSON_PRETTY_PRINT));
        putenv('MODULES_STATUSES_FILE=' . $this->statusesFile);

        return parent::createApplication();
    }

    protected function tearDown(): void
    {
        if (is_file($this->statusesFile)) {
            unlink($this->statusesFile);
        }

        putenv('MODULES_STATUSES_FILE');

        // The slot registry is static, so a test that registered the module's nav link would
        // leak it into the next one.
        ModuleSlotService::resetSlots();

        parent::tearDown();
    }
}

uses(GuardConstructionQueueModuleTestCase::class);

it('schedules nothing while the construction queue state is unknown', function (mixed $snapshot) {
    $result = app(QueueAiBuildingAction::class)->handle($snapshot, [
        ['building_id' => 1, 'level' => 5],
    ]);

    expect($result['decision'])->toBe(GuardConstructionQueue::DECISION_HOLD)
        ->and($result['reason'])->toBe(GuardConstructionQueue::REASON_CONSTRUCTION_QUEUE_UNKNOWN)
        ->and($result['orders'])->toBe([])
        ->and(array_keys($result))->toBe(['decision', 'reason', 'orders']);
})->with([
    'absent' => [null],
    'empty' => [[]],
    'unreadable' => ['unparsed queue'],
]);

it('derives the decision from the reported snapshot alone', function () {
    $snapshot = ['slots' => [['building_id' => 3, 'finish_time' => 1900000000]]];
    $orders = [['building_id' => 3, 'level' => 9]];

    $result = app(QueueAiBuildingAction::class)->handle($snapshot, $orders);

    expect($result['decision'])->toBe(GuardConstructionQueue::DECISION_COMMIT)
        ->and($result['snapshot'])->toBe($snapshot)
        ->and($result['orders'])->toBe($orders)
        ->and(array_keys($result))->toBe(['decision', 'snapshot', 'orders']);
});
