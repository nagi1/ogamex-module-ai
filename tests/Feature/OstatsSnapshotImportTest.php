<?php

use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

function ostatsCollectorPath(): string
{
    return dirname(__DIR__, 2) . '/scripts/ogame-ostats-collect.py';
}

function ostatsFixturePath(): string
{
    return dirname(__DIR__, 2) . '/tests/Fixtures/external/ostats';
}

function ostatsCollect(string $out): void
{
    $command = sprintf(
        'python3 %s --offline-dir %s --out %s 2>&1',
        escapeshellarg(ostatsCollectorPath()),
        escapeshellarg(ostatsFixturePath()),
        escapeshellarg($out),
    );
    $output = [];
    $status = 0;
    exec($command, $output, $status);

    expect($status)->toBe(0, implode("\n", $output));
}

/** @return array<int, array<string, mixed>> */
function ostatsRecords(string $out, string $type): array
{
    $path = $out . '/sources/OSTATS/normalized/' . $type . '.jsonl';

    return array_map(fn (string $line) => json_decode($line, true, flags: JSON_THROW_ON_ERROR), file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
}

// EXT-004: the OStats collector turns the public catalogue, rosters and hourly histories into
// typed, pseudonymized records, off the network. Its ids use the same salt as the official
// collector, so a player recorded by both is one pseudonym.
it('normalizes saved OStats payloads into typed, pseudonymized records that join the official data', function (): void {
    if (!function_exists('exec') || !is_file(ostatsCollectorPath())) {
        $this->markTestSkipped('the collector and a shell are required');
    }

    $out = sys_get_temp_dir() . '/ogame-ostats-' . getmypid();
    exec('rm -rf ' . escapeshellarg($out));

    try {
        ostatsCollect($out);

        $manifest = json_decode(file_get_contents($out . '/sources/OSTATS/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        expect($manifest['record_counts'])->toBe(['alliance' => 1, 'player' => 2, 'score_point' => 2, 'universe_catalogue' => 1]);

        // Identities are gone at any depth.
        $files = glob($out . '/sources/OSTATS/normalized/*.jsonl');
        $text = implode('', array_map('file_get_contents', $files));
        expect($text)->not->toContain('"name"')
            ->and($text)->not->toContain('Real Name')
            ->and($text)->not->toContain('An Alliance Name');

        // The hourly history joins its player, and each point is a 16-hex pseudonym.
        $players = array_column(ostatsRecords($out, 'player'), 'player_id');
        $points = ostatsRecords($out, 'score_point');
        expect($points)->toHaveCount(2);
        foreach ($points as $point) {
            expect($point['player_id'])->toMatch('/^[0-9a-f]{16}$/')
                ->and($players)->toContain($point['player_id']);
        }
        expect($points[0]['observed_at'])->toBe('2026-10-09T20:00:00Z')
            ->and($points[0]['score'])->toBe(55614840);

        // The salt is the same the official collector uses for this universe (PUB-pl189).
        $official = hash_hmac('sha256', 'player:100250', hash('sha256', 'PUB-pl189'));
        expect($players)->toContain(substr($official, 0, 16));
    } finally {
        exec('rm -rf ' . escapeshellarg($out));
    }
});
