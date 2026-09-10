<?php

namespace Modules\AI\Tests\Feature;

use Illuminate\Foundation\Application;
use Tests\IsolatedAccountTestCase;

class AIRouteTest extends IsolatedAccountTestCase
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

    public function test_admin_can_open_the_ai_module_page(): void
    {
        $this->artisan('ogamex:admin:assign-role', ['username' => $this->currentUsername]);

        $response = $this->get('/admin/ai');

        $response->assertOk();
        $response->assertSee('AI Players');
        $response->assertSee('AI module is loaded');
    }
}
