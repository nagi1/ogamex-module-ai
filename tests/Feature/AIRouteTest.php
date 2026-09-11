<?php

use Illuminate\Foundation\Application;
use Modules\AI\Actions\QueueAiBuildingAction;
use Modules\AI\Actions\RunAiSessionAction;
use Modules\AI\Contracts\QueueAiBuilding;
use Modules\AI\Contracts\RunAiSession;
use Tests\IsolatedAccountTestCase;

class AiRouteModuleTestCase extends IsolatedAccountTestCase
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

        parent::tearDown();
    }
}

uses(AiRouteModuleTestCase::class);

test('an admin can open the AI module page', function (): void {
    $this->artisan('ogamex:admin:assign-role', ['username' => $this->currentUsername]);

    $response = $this->get('/admin/ai');

    $response->assertOk();
    $response->assertSee('AI Players');
    $response->assertSee('AI module is loaded');
    expect(app(RunAiSession::class))->toBeInstanceOf(RunAiSessionAction::class);
    expect(app(QueueAiBuilding::class))->toBeInstanceOf(QueueAiBuildingAction::class);
});
