<?php

use Illuminate\Foundation\Application;
use OGame\Services\ModuleSlotService;
use Tests\IsolatedAccountTestCase;

class DiameterGuardModuleTestCase extends IsolatedAccountTestCase
{
    private string $statusesFile;

    public function createApplication(): Application
    {
        $trackedFile = dirname(__DIR__, 5) . '/modules_statuses.json';
        $statuses = json_decode((string) file_get_contents($trackedFile), true) ?? [];
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

        // The slot registry is static, so a booted module would leak its nav link into the
        // next test.
        ModuleSlotService::resetSlots();

        parent::tearDown();
    }
}

uses(DiameterGuardModuleTestCase::class);

const AI_DIAMETER_GUARD_CODE_DIRECTORIES = [
    'app/Actions',
    'app/Ai',
    'app/Domain',
    'app/Enums',
    'app/Support',
];

const AI_DIAMETER_GUARD_BEHAVIOUR_DIRECTORY = 'resources/behavior';

const AI_DIAMETER_GUARD_TOKEN = 'diameter';

const AI_DIAMETER_GUARD_READ_PROBE = '<?php';

function aiDiameterGuardRoot(): string
{
    return dirname(__DIR__, 3);
}

/**
 * Every file the engine can read a planet value from: its own code plus the behaviour data the
 * planner reads at runtime.
 *
 * @return array<int, string>
 */
function aiDiameterGuardFiles(string $moduleRoot): array
{
    $files = [];

    foreach (AI_DIAMETER_GUARD_CODE_DIRECTORIES as $relative) {
        $files = array_merge($files, aiDiameterGuardFilesIn($moduleRoot . DIRECTORY_SEPARATOR . $relative));
    }

    return array_merge($files, aiDiameterGuardFilesIn(
        $moduleRoot . DIRECTORY_SEPARATOR . AI_DIAMETER_GUARD_BEHAVIOUR_DIRECTORY
    ));
}

/**
 * @return array<int, string>
 */
function aiDiameterGuardFilesIn(string $directory): array
{
    if (! is_dir($directory)) {
        return [];
    }

    $files = [];
    $entries = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($entries as $entry) {
        if (! $entry->isFile()) {
            continue;
        }

        $files[] = $entry->getPathname();
    }

    return $files;
}

/**
 * Code, not prose: comments are dropped so a docblock that mentions the word cannot fail the
 * guard, while a real read stays visible.
 *
 * @return array<int, string>
 */
function aiDiameterGuardCodeLines(string $path): array
{
    $source = (string) file_get_contents($path);
    $withoutBlockComments = (string) preg_replace('#/\*.*?\*/#s', ' ', $source);

    $lines = [];

    foreach (preg_split('/\R/', $withoutBlockComments) ?: [] as $line) {
        $code = (string) preg_split('#(//|\#)#', $line, 2)[0];

        if (trim($code) === '') {
            continue;
        }

        $lines[] = $code;
    }

    return $lines;
}

/**
 * @param array<int, string> $files
 * @return array<int, string>
 */
function aiDiameterGuardOffenders(array $files, string $token): array
{
    $offenders = [];

    foreach ($files as $file) {
        foreach (aiDiameterGuardCodeLines($file) as $line) {
            if (stripos($line, $token) !== false) {
                $offenders[] = $file;
            }
        }
    }

    return array_values(array_unique($offenders));
}

test('the AI engine reads planet state but never a planet diameter', function (): void {
    $files = aiDiameterGuardFiles(aiDiameterGuardRoot());

    // The probe proves the scan reads file contents; without it an empty result could simply
    // mean the paths were wrong.
    expect($files)->not->toBe([]);
    expect(aiDiameterGuardOffenders($files, AI_DIAMETER_GUARD_READ_PROBE))->not->toBe([]);
    expect(aiDiameterGuardOffenders($files, AI_DIAMETER_GUARD_TOKEN))->toBe([]);
});

test('the guard flags a source file that reads a planet diameter', function (): void {
    $sample = sys_get_temp_dir() . '/ai_diameter_guard_' . uniqid('', true) . '.php';
    file_put_contents($sample, "<?php\n\n\$planetFieldCount = \$planet->" . AI_DIAMETER_GUARD_TOKEN . ";\n");

    try {
        expect(aiDiameterGuardOffenders([$sample], AI_DIAMETER_GUARD_TOKEN))->toBe([$sample]);
    } finally {
        unlink($sample);
    }
});

test('no behaviour data file names planet diameter as an input', function (): void {
    $files = aiDiameterGuardFilesIn(
        aiDiameterGuardRoot() . DIRECTORY_SEPARATOR . AI_DIAMETER_GUARD_BEHAVIOUR_DIRECTORY
    );

    expect($files)->not->toBe([]);
    expect(aiDiameterGuardOffenders($files, AI_DIAMETER_GUARD_TOKEN))->toBe([]);
});
