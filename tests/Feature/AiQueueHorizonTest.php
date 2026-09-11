<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Laravel\Horizon\ProvisioningPlan;
use Laravel\Horizon\SupervisorOptions;
use Modules\AI\Console\Commands\RunDueAiWork;
use Modules\AI\Enums\AiQueueName;
use Modules\AI\Enums\AiWorkKind;
use Modules\AI\Enums\AiWorkState;
use Modules\AI\Jobs\ProcessAiWork;
use Modules\AI\Models\AiWorkItem;
use Modules\AI\Support\HorizonConfiguration;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\IsolatedAccountTestCase;

/**
 * Boots the host application with the AI module enabled so its providers — and the
 * Horizon lanes and schedules they register — are loaded. Mirrors AIRouteTest.
 */
class AiQueueModuleTestCase extends IsolatedAccountTestCase
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

uses(AiQueueModuleTestCase::class);

test('ai work is queued on the module lane with tags', function (): void {
    $job = app()->makeWith(ProcessAiWork::class, ['workItemId' => 7]);

    expect($job->queue)->toBe(AiQueueName::Ai->value)
        ->and($job->tags())->toContain('ai')
        ->and($job->tags())->toContain('ai:work-item:7')
        // The job timeout must stay below the module's Horizon supervisor timeout.
        ->and($job->timeout)->toBeLessThan(config('horizon.defaults.supervisor-ai.timeout'));
});

test('the module contributes its horizon lanes to every environment', function (): void {
    $environments = array_keys((array) config('horizon.environments'));

    expect($environments)->not->toBeEmpty()
        ->and(config('horizon.defaults.supervisor-ai.queue'))->toBe([AiQueueName::Ai->value])
        ->and(config('horizon.defaults.supervisor-ai-language.queue'))->toBe([AiQueueName::AiLanguage->value])
        ->and(config('horizon.waits.redis:' . AiQueueName::Ai->value))->toBe(120)
        ->and(config('queue.module_queue_names'))->toContain(AiQueueName::Ai->value)
        ->and(config('queue.module_queue_names'))->toContain(AiQueueName::AiLanguage->value);

    foreach ($environments as $environment) {
        expect(config("horizon.environments.{$environment}.supervisor-ai.maxProcesses"))->toBeInt()
            ->and(config("horizon.environments.{$environment}.supervisor-ai-language.maxProcesses"))->toBeInt();
    }
});

test('the horizon plan survives a config cache without the merged module config', function (): void {
    // A cached config built before the module was enabled has no ai.horizon key.
    config(['ai.horizon' => null, 'horizon.defaults.supervisor-ai' => null]);

    app(HorizonConfiguration::class)->contribute();

    expect(config('horizon.defaults.supervisor-ai.queue'))->toBe([AiQueueName::Ai->value]);
});

test('the due work dispatcher enqueues onto the module lane', function (): void {
    AiWorkItem::create([
        'player_id' => $this->currentUserId,
        'kind' => AiWorkKind::BuildFirstBuilding,
        'due_at' => now()->subMinute(),
        'idempotency_key' => 'horizon-lane-' . $this->currentUserId,
        'state' => AiWorkState::Pending,
    ]);
    Bus::fake();
    $command = app(RunDueAiWork::class);
    $command->setLaravel($this->app);

    $command->run(
        app()->makeWith(ArrayInput::class, ['parameters' => ['--limit' => 10]]),
        app(NullOutput::class),
    );

    Bus::assertDispatched(
        ProcessAiWork::class,
        static fn (ProcessAiWork $job): bool => $job->queue === AiQueueName::Ai->value,
    );
});

test('the module schedules the due work dispatcher every minute', function (): void {
    $events = collect(app(Schedule::class)->events())
        ->filter(static fn (object $event): bool => str_contains((string) $event->command, 'ai:run-due-work'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('* * * * *');
});

test('Horizon accepts the module plan for production, local and an unlisted environment', function (string $environment): void {
    // ProvisioningPlan is what the Horizon master supervisor builds on start.
    $plan = ProvisioningPlan::get('test-master');

    // Mirror Horizon's own environment selection (Str::is in ProvisioningPlan::deploy).
    $resolved = collect($plan->parsed)->keys()->first(
        static fn (string $name): bool => Str::is($name, $environment),
    );

    expect($resolved)->toBeString();

    $work = $plan->optionsFor($resolved, 'supervisor-ai');
    $language = $plan->optionsFor($resolved, 'supervisor-ai-language');

    expect($work)->toBeInstanceOf(SupervisorOptions::class)
        ->and($work->connection)->toBe('redis')
        ->and($work->queue)->toBe(AiQueueName::Ai->value)
        ->and($work->minProcesses)->toBeGreaterThanOrEqual(1)
        ->and($work->maxProcesses)->toBeGreaterThanOrEqual(1)
        ->and((int) $work->timeout)->toBe(30)
        ->and((int) $work->maxTries)->toBe(3)
        ->and($language)->toBeInstanceOf(SupervisorOptions::class)
        ->and($language->queue)->toBe(AiQueueName::AiLanguage->value)
        ->and((int) $language->timeout)->toBe(60)
        ->and((int) $language->maxTries)->toBe(1);
})->with(['production', 'local', 'qa']);

test('the applied config provisions the module lanes in every host environment', function (): void {
    $environments = array_keys((array) config('horizon.environments'));

    expect($environments)->toContain('production', 'staging', 'local', '*');

    foreach ($environments as $environment) {
        expect(config("horizon.environments.{$environment}.supervisor-ai.maxProcesses"))->toBeInt()
            ->and(config("horizon.environments.{$environment}.supervisor-ai-language.maxProcesses"))->toBeInt();
    }
});
