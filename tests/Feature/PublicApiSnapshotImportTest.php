<?php

use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

function publicApiCollectorPath(): string
{
    return dirname(__DIR__, 2) . '/scripts/ogame-public-collect.py';
}

function publicApiFixturePath(): string
{
    return dirname(__DIR__, 2) . '/tests/Fixtures/external/public-api';
}

/** Run the real collector over the saved payloads and return the output root. */
function publicApiCollect(string $out): void
{
    $command = sprintf(
        'python3 %s --offline-dir %s --out %s --salt test 2>&1',
        escapeshellarg(publicApiCollectorPath()),
        escapeshellarg(publicApiFixturePath()),
        escapeshellarg($out),
    );
    $output = [];
    $status = 0;
    exec($command, $output, $status);

    expect($status)->toBe(0, implode("\n", $output));
}

/** @return array<int, array<string, mixed>> */
function publicApiRecords(string $out, string $type): array
{
    $path = $out . '/sources/PUB-en1/normalized/' . $type . '.jsonl';

    return array_map(fn (string $line) => json_decode($line, true, flags: JSON_THROW_ON_ERROR), file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
}

// EXT-001: the public API collector turns documented payloads into typed records and a provenance
// manifest, off the network. This test drives the real tool on saved payloads -- a fixture with the
// shape the live API answered with -- so normalization is checked without reaching a provider.
it('normalizes saved public API payloads into typed, pseudonymized records and a provenance manifest', function (): void {
    if (!function_exists('exec') || !is_file(publicApiCollectorPath())) {
        $this->markTestSkipped('the collector and a shell are required');
    }

    $a = sys_get_temp_dir() . '/ogame-public-collect-a-' . getmypid();
    $b = sys_get_temp_dir() . '/ogame-public-collect-b-' . getmypid();
    exec('rm -rf ' . escapeshellarg($a) . ' ' . escapeshellarg($b));

    try {
        publicApiCollect($a);
        publicApiCollect($b);

        $manifest = json_decode(file_get_contents($a . '/sources/PUB-en1/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        expect($manifest['source_id'])->toBe('PUB-en1')
            ->and($manifest['pseudonymized'])->toBeTrue()
            ->and($manifest['parser_version'])->toBe('1')
            ->and($manifest['record_counts'])->toBe(['alliance' => 1, 'planet' => 3, 'player' => 3, 'score' => 3, 'server' => 1]);

        // Identities are gone: no record, at any depth, carries a published name.
        $files = glob($a . '/sources/PUB-en1/normalized/*.jsonl');
        $text = implode('', array_map('file_get_contents', $files));
        expect($text)->not->toContain('"name"')
            ->and($text)->not->toContain('PlayerOne')
            ->and($text)->not->toContain('TestAlliance');

        // Every pseudonymous id is a 16-hex token, and the two runs are byte-identical: same input,
        // same salt, same records.
        foreach (publicApiRecords($a, 'planet') as $planet) {
            expect($planet['owner_id'])->toMatch('/^[0-9a-f]{16}$/');
        }

        foreach ($files as $file) {
            expect(hash_file('sha256', $file))->toBe(hash_file('sha256', str_replace($a, $b, $file)));
        }

        // A join survives: the planet's owner is one of the players by pseudonym.
        $playerIds = array_column(publicApiRecords($a, 'player'), 'player_id');
        foreach (publicApiRecords($a, 'planet') as $planet) {
            expect($playerIds)->toContain($planet['owner_id']);
        }
    } finally {
        exec('rm -rf ' . escapeshellarg($a) . ' ' . escapeshellarg($b));
    }
});

