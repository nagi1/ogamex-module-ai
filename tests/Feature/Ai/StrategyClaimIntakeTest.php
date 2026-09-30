<?php

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\File;
use OGame\Services\ModuleSlotService;
use Tests\IsolatedAccountTestCase;

class AiStrategyClaimIntakeTestCase extends IsolatedAccountTestCase
{
    private string $statusesFile;

    public function createApplication(): Application
    {
        $moduleRoot = dirname(__DIR__, 3);
        $trackedFile = dirname($moduleRoot, 2) . '/modules_statuses.json';
        $statuses = is_file($trackedFile) ? (json_decode((string) file_get_contents($trackedFile), true) ?: []) : [];
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

uses(AiStrategyClaimIntakeTestCase::class);

$moduleRoot = dirname(__DIR__, 3);

// A claim with no numbers and no doctrine is only a claim. The AI can only hold an opinion in the
// data it decides from -- behaviour files, scenarios and config -- so an unfounded claim must be
// absent from all of them.
$decisionSurfaces = static fn (): array => [
    $moduleRoot . '/resources/behavior',
    $moduleRoot . '/resources/scenarios',
    $moduleRoot . '/config',
];

// Anything that would pin the claim onto an account: a resource, ship or account constant can only
// live in the module's code, config or schema.
$accountSurfaces = static fn (): array => [
    $moduleRoot . '/app',
    $moduleRoot . '/config',
    $moduleRoot . '/database',
];

$filesMentioning = static function (string $token, array $surfaces): array {
    $mentions = [];

    foreach ($surfaces as $surface) {
        if (! is_dir($surface)) {
            continue;
        }

        foreach (File::allFiles($surface) as $file) {
            $contents = (string) file_get_contents($file->getPathname());

            if (preg_match('/\b' . preg_quote($token, '/') . '\b/i', $contents) === 1) {
                $mentions[] = $file->getPathname();
            }
        }
    }

    return $mentions;
};

it('records no AI opinion for a claim with no numbers and no doctrine', function () use ($decisionSurfaces, $filesMentioning) {
    $claim = [
        'numbers' => [],
        'doctrine' => ['prat'],
    ];

    expect($claim['numbers'])->toBe([]);

    expect($filesMentioning($claim['doctrine'][0], $decisionSurfaces()))->toBe([]);
});

it('changes nothing on the account when a claim carries no numbers and no doctrine', function () use ($accountSurfaces, $filesMentioning) {
    $claim = [
        'numbers' => [],
        'doctrine' => ['prat'],
    ];

    expect($filesMentioning($claim['doctrine'][0], $accountSurfaces()))->toBe([]);
});
