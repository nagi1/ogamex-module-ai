<?php

$projectRoot = dirname(__DIR__, 3);

/*
 * The AI module's classes live under `Modules/AI/app` (its `Actions` directory is
 * `Modules/AI/app/Actions`), so the guard has to walk that tree rather than only the
 * application's `app/Ai` and `app/Actions` paths named by the acceptance.
 */
$disbandClassesUnder = static function (array $roots): array {
    $matches = [];

    foreach ($roots as $root) {
        if (! is_dir($root)) {
            continue;
        }

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $className = str_replace(
                DIRECTORY_SEPARATOR,
                '\\',
                substr($file->getPathname(), strlen($root) + 1, -4),
            );

            if (preg_match('/disband/i', $className) === 1) {
                $matches[] = $file->getPathname();
            }
        }
    }

    return $matches;
};

it('keeps the AI module free of any disband class', function () use ($disbandClassesUnder, $projectRoot): void {
    $roots = [
        $projectRoot . '/app/Ai',
        $projectRoot . '/app/Actions',
        $projectRoot . '/Modules/AI/app',
    ];

    expect($disbandClassesUnder($roots))->toBe([]);
});

it('fails the guard as soon as a disband class exists', function () use ($disbandClassesUnder): void {
    $root = sys_get_temp_dir() . '/ogamex-disband-guard-' . bin2hex(random_bytes(6));
    mkdir($root . '/Actions', 0777, true);
    file_put_contents(
        $root . '/Actions/DisbandAction.php',
        "<?php\n\nnamespace Modules\\AI\\Actions;\n\nclass DisbandAction\n{\n}\n",
    );

    try {
        $found = $disbandClassesUnder([$root]);

        expect(count($found))->toBe(1);
        expect(str_ends_with($found[0], 'DisbandAction.php'))->toBeTrue();
    } finally {
        unlink($root . '/Actions/DisbandAction.php');
        rmdir($root . '/Actions');
        rmdir($root);
    }
});

it('records disband as an unconfirmed, fact-only spec', function () use ($projectRoot): void {
    $path = $projectRoot . '/plan/specs/ai/ogame-disband.md';

    expect(is_file($path))->toBeTrue();

    $contents = file_get_contents($path);

    expect(is_string($contents))->toBeTrue();

    foreach (['Variants', 'Facts', 'Unconfirmed'] as $heading) {
        expect($contents)->toMatch('/^#{1,6}[ \t]+' . $heading . '[ \t]*$/m');
    }
});
