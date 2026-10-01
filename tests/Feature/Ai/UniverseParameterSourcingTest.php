<?php

use Illuminate\Foundation\Application;
use OGame\Services\ModuleSlotService;
use Tests\IsolatedAccountTestCase;

class AiUniverseParameterTestCase extends IsolatedAccountTestCase
{
    private string $statusesFile;

    public function createApplication(): Application
    {
        $trackedFile = dirname(__DIR__, 5) . '/modules_statuses.json';
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

        // The slot registry is static, so a test that registered the module's nav link would
        // leak it into the next one.
        ModuleSlotService::resetSlots();

        parent::tearDown();
    }
}

uses(AiUniverseParameterTestCase::class);

// Universe parameters are host state, not agent state: an agent that carries its own copy of
// the universe id or a speed multiplier answers for the wrong universe. The only acceptable
// source is the configuration the host hands over at runtime.
function aiUniverseParameterAgentsRoot(): string
{
    return dirname(__DIR__, 3) . '/app/Ai';
}

function aiUniverseParameterNames(): array
{
    return ['universeid', 'fleetspeed', 'economyspeed', 'researchspeed'];
}

function aiUniverseParameterMatches(string $tokenText): bool
{
    $normalised = strtolower(preg_replace('/[^a-z]/i', '', $tokenText));

    return in_array($normalised, aiUniverseParameterNames(), true);
}

function aiUniverseParameterNamePrecedes(array $tokens, int $literalIndex): bool
{
    for ($index = $literalIndex - 1; $index >= 0; $index--) {
        $token = $tokens[$index];

        if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        $text = is_array($token) ? $token[1] : $token;

        if (in_array($text, ['=', ':', '=>', '[', ']'], true)) {
            continue;
        }

        return aiUniverseParameterMatches($text);
    }

    return false;
}

function aiUniverseParameterLiteralsIn(string $source): array
{
    $tokens = token_get_all($source);
    $literals = [];

    foreach ($tokens as $index => $token) {
        if (!is_array($token) || $token[0] !== T_LNUMBER) {
            continue;
        }

        if (aiUniverseParameterNamePrecedes($tokens, $index)) {
            $literals[] = $token[1];
        }
    }

    return $literals;
}

function aiUniverseParameterScanFindings(): array
{
    $root = aiUniverseParameterAgentsRoot();

    if (!is_dir($root)) {
        return [];
    }

    $findings = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $literals = aiUniverseParameterLiteralsIn((string) file_get_contents($file->getPathname()));

        if ($literals === []) {
            continue;
        }

        $findings[$file->getPathname()] = $literals;
    }

    return $findings;
}

function aiUniverseParameterFixturePath(string $name): string
{
    $root = aiUniverseParameterAgentsRoot();

    if (!is_dir($root)) {
        mkdir($root, 0777, true);
    }

    return $root . '/' . $name;
}

it('leaves universe parameters to host configuration instead of hardcoding them in the agents', function () {
    expect(aiUniverseParameterScanFindings())->toBe([]);
});

it('detects a hardcoded universe id placed under app/Ai', function () {
    $path = aiUniverseParameterFixturePath('UniverseParameterSourcingFixture.php');

    file_put_contents($path, <<<'PHP'
<?php

final class UniverseParameterSourcingFixture
{
    public const UNIVERSE_ID = 424242;
}
PHP);

    try {
        $findings = aiUniverseParameterScanFindings();

        expect($findings)->toHaveKey($path);
        expect($findings[$path])->toContain('424242');
    } finally {
        unlink($path);
    }
});

it('detects a hardcoded speed multiplier placed under app/Ai', function () {
    $path = aiUniverseParameterFixturePath('UniverseParameterSourcingFixture.php');

    file_put_contents($path, <<<'PHP'
<?php

final class UniverseParameterSourcingFixture
{
    private int $fleetSpeed = 7;
}
PHP);

    try {
        $findings = aiUniverseParameterScanFindings();

        expect($findings)->toHaveKey($path);
        expect($findings[$path])->toContain('7');
    } finally {
        unlink($path);
    }
});
