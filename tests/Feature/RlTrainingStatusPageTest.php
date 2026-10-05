<?php

declare(strict_types=1);

use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

/**
 * The ML training page is dev tooling over one file the collector writes. The environment and the
 * storage path are put back afterwards because the whole suite shares one application instance.
 */
function withRlStatus(string $environmentName, ?array $status, Closure $scenario): void
{
    $environment = app()->environment();
    $storage = app()->storagePath();
    $directory = sys_get_temp_dir().'/rl-status-'.bin2hex(random_bytes(4));
    mkdir($directory.'/rl', 0777, true);
    if ($status !== null) {
        file_put_contents($directory.'/rl/status.json', json_encode($status));
    }

    app()->detectEnvironment(static fn (): string => $environmentName);
    app()->useStoragePath($directory);

    try {
        $scenario();
    } finally {
        app()->detectEnvironment(static fn (): string => $environment);
        app()->useStoragePath($storage);
        @unlink($directory.'/rl/status.json');
        @rmdir($directory.'/rl');
        @rmdir($directory);
    }
}

test('a local machine gets the page and the collector status with its age', function (): void {
    $page = null;
    $poll = null;

    withRlStatus('local', ['at' => time() - 7, 'overall' => 'running', 'steps' => []], function () use (&$page, &$poll): void {
        $page = $this->get(route('ai.harness.rl'));
        $poll = $this->getJson(route('ai.harness.rl.poll'));
    });

    $page->assertOk()->assertSee('ML training');
    $poll->assertOk()->assertJsonPath('overall', 'running');
    expect($poll->json('age'))->toBeGreaterThanOrEqual(7)->and($poll->json('stale_after'))->toBe(30);
});

test('the poll says so when no collector has written a status yet', function (): void {
    $poll = null;

    withRlStatus('local', null, function () use (&$poll): void {
        $poll = $this->getJson(route('ai.harness.rl.poll'));
    });

    $poll->assertOk()->assertExactJson(['missing' => true]);
});

test('anywhere but a local machine the page does not exist', function (): void {
    $page = null;
    $poll = null;

    withRlStatus('production', ['at' => time()], function () use (&$page, &$poll): void {
        $page = $this->get(route('ai.harness.rl'));
        $poll = $this->getJson(route('ai.harness.rl.poll'));
    });

    $page->assertNotFound();
    $poll->assertNotFound();
});
