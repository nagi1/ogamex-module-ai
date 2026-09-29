<?php

use Modules\AI\Enums\AiCandidateActionType;
use Modules\AI\Enums\AiCandidateReason;
use Modules\AI\Enums\AiWorkKind;

uses(Tests\IsolatedAccountTestCase::class);

/*
 * Expedition logs carry no doctrine and no numbers, so the account must not decide anything from
 * them. These tests pin that absence: the day an expedition-log branch, enum case or config key
 * appears, the plan has to name the behaviour data file a modder would edit.
 */
if (! defined('AI_EXPEDITION_LOG_TOKEN')) {
    define('AI_EXPEDITION_LOG_TOKEN', '/(?:expedition[_-]?logs?|logs?[_-]?expedition)/i');
}

if (! function_exists('aiExpeditionLogSourcePaths')) {
    /**
     * Every runtime source and configuration file that could carry an expedition-log rule.
     *
     * @return array<int, string>
     */
    function aiExpeditionLogSourcePaths(): array
    {
        $moduleRoot = dirname(__DIR__, 2);
        $paths = [];

        foreach ([$moduleRoot . '/app', $moduleRoot . '/config'] as $sourceRoot) {
            if (! is_dir($sourceRoot)) {
                continue;
            }

            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sourceRoot, FilesystemIterator::SKIP_DOTS));

            foreach ($files as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $paths[] = $file->getPathname();
                }
            }
        }

        return $paths;
    }
}

it('has no expedition-log decision branch in the AI runtime sources', function () {
    $offenders = array_values(array_filter(
        aiExpeditionLogSourcePaths(),
        fn (string $path): bool => preg_match(AI_EXPEDITION_LOG_TOKEN, (string) file_get_contents($path)) === 1
    ));

    sort($offenders);

    expect($offenders)->toBe([]);
});

it('has no expedition-log case on the values the planner decides with', function () {
    $decisionValues = [];

    foreach ([AiCandidateActionType::class, AiCandidateReason::class, AiWorkKind::class] as $enum) {
        foreach ($enum::cases() as $case) {
            $decisionValues[] = $case->name;

            if ($case instanceof BackedEnum && is_string($case->value)) {
                $decisionValues[] = $case->value;
            }
        }
    }

    $offenders = array_values(array_filter(
        $decisionValues,
        fn (string $value): bool => preg_match(AI_EXPEDITION_LOG_TOKEN, $value) === 1
    ));

    expect($offenders)->toBe([]);
});

it('keeps the expedition-log spec note free of numeric mechanics', function () {
    $specPath = dirname(__DIR__, 2) . '/plan/ogame/expedition-logs.md';

    expect(is_file($specPath))->toBeTrue();

    $numbers = [];
    preg_match_all('/(?<![\w-])\d+(?:[.,]\d+)?(?![\w-])/', (string) file_get_contents($specPath), $numbers);

    expect($numbers[0])->toBe([]);
});
