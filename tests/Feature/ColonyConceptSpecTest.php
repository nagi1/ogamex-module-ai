<?php

/*
 * The colony concept page is a spec, not mechanics: it records COL-004 as `none`. These helpers
 * read the page the way a reader does, so the checks fail when the page starts carrying values or
 * dangling principle ids instead of failing only because a method exists.
 */
function colonyConceptRoot(): string
{
    return dirname(__DIR__, 2);
}

function colonyConceptSpecPath(): string
{
    return colonyConceptRoot() . '/plan/ogame/concepts/WIK-139-colony.md';
}

function colonyConceptSpec(): string
{
    $markdown = file_get_contents(colonyConceptSpecPath());

    return $markdown === false ? '' : $markdown;
}

function colonyConceptFrontMatter(string $markdown): string
{
    if (preg_match('/\A---\R(.*?)\R---\R/s', $markdown, $matches) !== 1) {
        return '';
    }

    return $matches[1];
}

function colonyConceptBody(string $markdown): string
{
    $body = preg_replace('/\A---\R.*?\R---\R/s', '', $markdown);

    return $body ?? $markdown;
}

function colonyConceptDeclaredPrinciples(string $markdown): array
{
    $ids = [];
    $inList = false;

    foreach (preg_split('/\R/', colonyConceptFrontMatter($markdown)) ?: [] as $line) {
        if (preg_match('/^principles:\s*$/', $line) === 1) {
            $inList = true;
            continue;
        }

        if ($inList && preg_match('/^\s*-\s*(\S+)\s*$/', $line, $matches) === 1) {
            $ids[] = $matches[1];
            continue;
        }

        $inList = false;

        if (preg_match('/^doctrine:\s*(\S+)\s*$/', $line, $matches) === 1) {
            $ids[] = $matches[1];
        }
    }

    return array_values(array_unique($ids));
}

function colonyConceptPlanDocuments(): array
{
    $root = colonyConceptRoot() . '/plan';

    if (! is_dir($root)) {
        return [];
    }

    $paths = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if ($file->isFile() && $file->getExtension() === 'md') {
            $paths[] = $file->getPathname();
        }
    }

    sort($paths);

    return $paths;
}

function colonyConceptRegistry(): array
{
    $ids = [];

    foreach (colonyConceptPlanDocuments() as $path) {
        $markdown = file_get_contents($path);

        if ($markdown === false) {
            continue;
        }

        foreach (colonyConceptDeclaredPrinciples($markdown) as $id) {
            $ids[] = $id;
        }
    }

    return array_values(array_unique($ids));
}

function colonyConceptCitations(string $markdown): array
{
    preg_match_all('/\b[A-Z]{2,5}-\d{3}\b/', colonyConceptBody($markdown), $matches);

    return array_values(array_unique($matches[0]));
}

function colonyConceptSection(string $markdown, string $heading): ?string
{
    $pattern = '/^##\s+' . preg_quote($heading, '/') . '\s*$([\s\S]*?)(?=^##\s|\z)/m';

    if (preg_match($pattern, $markdown, $matches) !== 1) {
        return null;
    }

    return $matches[1];
}

function colonyConceptNumericLiterals(string $markdown): array
{
    // Digits glued to an id token are part of the id, so the page can cite COL-004 while stating
    // no value at all; every other digit run is a value the source never stated.
    preg_match_all('/(?<![\w-])\d+(?![\w-])/', $markdown, $matches);

    return $matches[0];
}

it('loads the colony concept page', function (): void {
    expect(is_file(colonyConceptSpecPath()))->toBeTrue();
    expect(colonyConceptSpec())->toContain('# Colony');
});

it('records an empty numbers section', function (): void {
    $section = colonyConceptSection(colonyConceptSpec(), 'Numbers');

    expect($section)->not->toBeNull();
    expect(trim((string) $section))->toBe('');
});

it('reads a missing section as missing rather than as empty', function (): void {
    expect(colonyConceptSection("# Colony\n", 'Numbers'))->toBeNull();
});

it('keeps a whitespace-only numbers section empty', function (): void {
    $mutant = str_replace("## Numbers\n", "## Numbers\n\n   \n", colonyConceptSpec());

    expect($mutant)->not->toBe(colonyConceptSpec());
    expect(trim((string) colonyConceptSection($mutant, 'Numbers')))->toBe('');
});

it('fails the numbers check as soon as the section carries text', function (): void {
    $mutant = str_replace("## Numbers\n", "## Numbers\n\nNone recorded.\n", colonyConceptSpec());

    expect($mutant)->not->toBe(colonyConceptSpec());
    expect(trim((string) colonyConceptSection($mutant, 'Numbers')))->not->toBe('');
});

it('records no numeric literal in the page', function (): void {
    expect(colonyConceptNumericLiterals(colonyConceptSpec()))->toBe([]);
});

it('fails the numeric literal check at the first recorded value', function (): void {
    $mutant = str_replace(
        'The leaning pages named while scouting the concern are:',
        'The leaning page names span 3 concerns:',
        colonyConceptSpec(),
    );

    expect($mutant)->not->toBe(colonyConceptSpec());
    expect(colonyConceptNumericLiterals($mutant))->toBe(['3']);
});

it('counts a value only where it stands as a value', function (): void {
    expect(colonyConceptNumericLiterals('`COL-004` and `WIK-139`'))->toBe([]);
    expect(colonyConceptNumericLiterals('a colony for 5 units'))->toBe(['5']);
    expect(colonyConceptNumericLiterals('a ratio of 4.2'))->toBe(['4', '2']);
});

it('resolves every principle id the page cites', function (): void {
    $cited = colonyConceptCitations(colonyConceptSpec());

    expect($cited)->toContain('COL-004');
    expect(array_values(array_diff($cited, colonyConceptRegistry())))->toBe([]);
});

it('fails the resolution check as soon as the body cites an id nothing declares', function (): void {
    $mutant = str_replace('recorded as principle `COL-004`', 'recorded as principle `COL-999`', colonyConceptSpec());

    expect($mutant)->not->toBe(colonyConceptSpec());
    expect(array_values(array_diff(colonyConceptCitations($mutant), colonyConceptRegistry())))->toBe(['COL-999']);
});

it('stays vacuous at zero citations, which the page itself does not do', function (): void {
    $mutant = str_replace('`COL-004`', 'the colonization doctrine', colonyConceptSpec());
    $cited = colonyConceptCitations($mutant);

    expect($cited)->toBe([]);
    expect(array_values(array_diff($cited, colonyConceptRegistry())))->toBe([]);
});
