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

/**
 * The host confirms one mechanic on diameter: a moon's size sets the chance a Death Star strike destroys it, and the
 * chance the Death Stars die (MoonDestructionMission; WIK-196 quotes the same two formulas). The galaxy view shows
 * that size to every player, so these files may name it, and no other:
 * - WideErrandEncoder prices a moon strike on the moon's size, as MoonDestructionMission does;
 * - the mission referees record what the host wrote (a new colony's row, the moon before a strike);
 * - the world replay writes the host column a colony is created with.
 * None of them reads a planet's diameter to choose what to build.
 */
const AI_DIAMETER_GUARD_MOON_STRIKE_FILES = [
    'app/Domain/Choice/WideErrandEncoder.php',
    'app/Actions/DrawAiMissionCasesAction.php',
    'app/Actions/DrawAiMoreMissionCasesAction.php',
    'app/Actions/ReplayAiWorldAction.php',
];

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
    if (!is_dir($directory)) {
        return [];
    }

    $files = [];
    $entries = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($entries as $entry) {
        if (!$entry->isFile()) {
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
    $root = aiDiameterGuardRoot();
    $allowed = array_map(static fn (string $relative): string => $root . DIRECTORY_SEPARATOR . $relative, AI_DIAMETER_GUARD_MOON_STRIKE_FILES);
    $files = array_values(array_diff(aiDiameterGuardFiles($root), $allowed));

    // The probe proves the scan reads file contents; without it an empty result could simply
    // mean the paths were wrong.
    expect($files)->not->toBe([]);
    expect(aiDiameterGuardOffenders($files, AI_DIAMETER_GUARD_READ_PROBE))->not->toBe([]);
    expect(aiDiameterGuardOffenders($files, AI_DIAMETER_GUARD_TOKEN))->toBe([]);
});

test('the moon strike exception names files that exist and still read the moon size', function (): void {
    // A stale entry would let a future planet-diameter read hide behind a file name that no longer means anything.
    foreach (AI_DIAMETER_GUARD_MOON_STRIKE_FILES as $relative) {
        $path = aiDiameterGuardRoot() . DIRECTORY_SEPARATOR . $relative;

        expect(is_file($path))->toBeTrue($relative)
            ->and(aiDiameterGuardOffenders([$path], AI_DIAMETER_GUARD_TOKEN))->toBe([$path], $relative);
    }
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
