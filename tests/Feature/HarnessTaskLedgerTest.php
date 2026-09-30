<?php

declare(strict_types=1);

use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

/**
 * The harness page is dev tooling: it answers nowhere but a local machine, so a test has to say it is
 * local before it may read anything. The environment is put back afterwards because the whole suite
 * shares one application instance.
 */
function withLocalEnvironment(Closure $scenario): void
{
    $environment = app()->environment();
    app()->detectEnvironment(static fn (): string => 'local');

    try {
        $scenario();
    } finally {
        app()->detectEnvironment(static fn (): string => $environment);
    }
}

test('a local machine gets the page and the whole ledger', function (): void {
    $page = null;
    $ledger = null;

    withLocalEnvironment(function () use (&$page, &$ledger): void {
        $page = $this->get(route('ai.harness.index'));
        $ledger = $this->getJson(route('ai.harness.tasks'));
    });

    $page->assertOk();
    $ledger->assertOk();

    $payload = $ledger->json();

    expect($payload['total'])->toBeGreaterThan(0)
        ->and($payload['tasks'])->toHaveCount($payload['total'])
        ->and($payload['at'])->not->toBe('');
});

test('every row carries every column, its dependencies and the harness history', function (): void {
    $payload = null;

    withLocalEnvironment(function () use (&$payload): void {
        $payload = $this->getJson(route('ai.harness.tasks'))->json();
    });

    $database = new PDO('sqlite:'.module_path('AI', 'plan/tasks/tasks.db'));
    $stored = (int) $database->query('SELECT COUNT(*) FROM tasks')->fetchColumn();
    $edges = (int) $database->query('SELECT COUNT(*) FROM dependencies')->fetchColumn();

    expect($payload['total'])->toBe($stored);

    $columns = ['id', 'code', 'title', 'kind', 'status', 'priority', 'assignee', 'gap_ref', 'principle_refs',
        'algorithm_ref', 'doc_refs', 'file_ref', 'notes', 'updated_at', 'deps', 'ready', 'attempts', 'proved'];

    $codes = array_column($payload['tasks'], 'code');
    $resolved = 0;

    foreach ($payload['tasks'] as $task) {
        expect(array_diff($columns, array_keys($task)))->toBe([]);

        // Both ends of a dependency edge are ids in the store, so an unresolved code here would mean the
        // page was printing a number no reader can look up.
        foreach ($task['deps'] as $dependency) {
            expect($codes)->toContain($dependency['code']);
            $resolved++;
        }

        // "ready" and "waits on" are two answers to the same question, so they may not disagree.
        $unmet = array_filter($task['deps'], static fn (array $dep): bool => $dep['status'] !== 'done');
        expect($task['ready'])->toBe($task['status'] === 'todo' && $unmet === [])
            ->and($task['attempts'])->toBeGreaterThanOrEqual(0)
            ->and($task['proved'])->toBeBool();
    }

    expect($resolved)->toBe($edges);
});

test('the harness refuses to answer outside a local machine', function (): void {
    $this->get(route('ai.harness.index'))->assertNotFound();
    $this->getJson(route('ai.harness.tasks'))->assertNotFound();
});

test('the overview snapshot reports what the pipeline is doing', function (): void {
    $snapshot = null;

    withLocalEnvironment(function () use (&$snapshot): void {
        // No cursor, so the poll answers on its first pass instead of holding the connection open.
        $snapshot = $this->getJson(route('ai.harness.poll'))->json();
    });

    expect($snapshot['fingerprint'])->not->toBe('')
        ->and($snapshot['tasks']['total'])->toBeGreaterThan(0)
        ->and($snapshot['sources'])->toHaveKeys(['raw', 'proposals', 'validated', 'implemented', 'edit_only', 'stuck'])
        ->and($snapshot['harness'])->toHaveKeys(['active', 'idle', 'phase', 'detail', 'heartbeat', 'log', 'lines'])
        ->and($snapshot['activity'])->toHaveKeys(['recent', 'provedLastHour', 'attempts', 'unproved'])
        ->and($snapshot['workers'])->toBeArray()
        ->and($snapshot['feed'])->toBeArray();
});
