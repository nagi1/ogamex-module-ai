<?php

/**
 * The account's diplomacy stance is declared data, not code, so the module must keep
 * exposing no diplomacy contract, and the data it does ship must say it is unconfirmed.
 */

it('exposes no diplomacy contract anywhere in the module source', function () {
    $appPath = aiDiplomacyAbsenceAppPath();

    expect(is_dir($appPath))->toBeTrue()
        ->and(aiDiplomacyAbsenceContractsIn($appPath))->toBe([]);
});

it('detects a diplomacy contract as soon as one is added, so the absence check bites', function () {
    $directory = sys_get_temp_dir() . '/ai-diplomacy-' . uniqid('', true);
    mkdir($directory);

    $contract = $directory . '/DiplomacyContract.php';
    file_put_contents($contract, "<?php\n\ninterface DiplomacyContract\n{\n}\n");

    try {
        expect(aiDiplomacyAbsenceContractsIn($directory))->toHaveCount(1);
    } finally {
        unlink($contract);
        rmdir($directory);
    }
});

it('records the source as an unconfirmed community wiki entry with unresolved mechanics', function () {
    $path = aiDiplomacyAbsencePolicyPath();

    expect(is_file($path))->toBeTrue();

    $contents = (string) file_get_contents($path);

    expect($contents)
        ->toContain('source_kind: community_wiki')
        ->toContain('confirmed: false')
        ->toContain('host_supplied: true')
        ->toContain('unresolved_mechanics: true');
});

function aiDiplomacyAbsenceAppPath(): string
{
    return dirname(__DIR__, 2) . '/app';
}

function aiDiplomacyAbsencePolicyPath(): string
{
    return dirname(__DIR__, 2) . '/resources/behavior/diplomacy.yaml';
}

/**
 * @return array<int, string>
 */
function aiDiplomacyAbsenceContractsIn(string $directory): array
{
    $contracts = [];

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());

        if (preg_match('/\b(?:interface|class|enum|trait)\s+[\w\\\\]*Diplomacy\w*/i', $source) === 1) {
            $contracts[] = $file->getPathname();
        }
    }

    sort($contracts);

    return $contracts;
}
