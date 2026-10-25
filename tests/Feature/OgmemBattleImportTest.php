<?php

use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

function ogmemCollectorPath(): string
{
    return dirname(__DIR__, 2) . '/scripts/ogame-ogmem-collect.py';
}

function ogmemFixturePath(): string
{
    return dirname(__DIR__, 2) . '/tests/Fixtures/external/ogmem';
}

function ogmemCollect(string $out): void
{
    $command = sprintf(
        'python3 %s --offline-dir %s --out %s 2>&1',
        escapeshellarg(ogmemCollectorPath()),
        escapeshellarg(ogmemFixturePath()),
        escapeshellarg($out),
    );
    $output = [];
    $status = 0;
    exec($command, $output, $status);

    expect($status)->toBe(0, implode("\n", $output));
}

/** @return array<int, array<string, mixed>> */
function ogmemRecords(string $out, string $type): array
{
    $path = $out . '/sources/OGMEM/normalized/' . $type . '.jsonl';

    return array_map(fn (string $line) => json_decode($line, true, flags: JSON_THROW_ON_ERROR), file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
}

// EXT-005: the OGMem collector turns public battle-report pages into typed, anonymized battle
// records - the numeric outcome and both fleets, with every player and alliance name dropped.
it('normalizes saved OGMem battle reports into typed, anonymized battle records', function (): void {
    if (!function_exists('exec') || !is_file(ogmemCollectorPath())) {
        $this->markTestSkipped('the collector and a shell are required');
    }

    $out = sys_get_temp_dir() . '/ogame-ogmem-' . getmypid();
    exec('rm -rf ' . escapeshellarg($out));

    try {
        ogmemCollect($out);

        $manifest = json_decode(file_get_contents($out . '/sources/OGMEM/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        expect($manifest['record_counts'])->toBe(['battle' => 1, 'battle_index' => 2, 'battle_unit' => 3]);

        $battle = ogmemRecords($out, 'battle')[0];
        expect($battle['community'])->toBe('de')
            ->and($battle['universe'])->toBe('Eris')
            ->and($battle['attacker_result'])->toBe(24278410533)
            ->and($battle['defender_result'])->toBe(-34733857533)
            ->and($battle['attacker_losses'])->toBe(-3072318000)
            ->and($battle['defender_losses'])->toBe(-31779172000)
            ->and($battle['loot'])->toBe(2954685533)
            ->and($battle['debris'])->toBe(24396043000)
            ->and($battle['debris_metal'])->toBe(12605346600);

        // Both fleets are captured, the attacker's table first.
        $units = ogmemRecords($out, 'battle_unit');
        expect(array_column($units, 'side'))->toBe(['attacker', 'defender', 'defender'])
            ->and($units[0]['unit'])->toBe('Cruiser')
            ->and($units[0]['lost'])->toBe(-105942)
            ->and($units[1]['unit'])->toBe('Large Cargo')
            ->and($units[1]['lost'])->toBe(-3404);

        // Anonymized: no report file carries the title's player, alliance or uploader text.
        $text = implode('', array_map('file_get_contents', glob($out . '/sources/OGMEM/normalized/*.jsonl')));
        expect($text)->not->toContain('Shindy')
            ->and($text)->not->toContain('Bratwurst')
            ->and($text)->not->toContain('Zaphod')
            ->and($text)->not->toContain('"name"');

        expect(ogmemRecords($out, 'battle_index'))->toHaveCount(2);
    } finally {
        exec('rm -rf ' . escapeshellarg($out));
    }
});
