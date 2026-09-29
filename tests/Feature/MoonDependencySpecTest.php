<?php

/**
 * WIK-071 carries no numbers and the module has no opinion on the moon dependency tree, so the only
 * behaviour worth locking down is the recorded spec: it must stay marked as unconfirmed and free of
 * values nobody measured. A later bundle that fills the empty section in with invented numbers
 * trips this test and forces a review.
 */
function aiMoonDependencySpecPath(): string
{
    // The module's own suite sits at modules/AI/tests/Feature, the repository's at tests/Feature.
    $repoRoots = [dirname(__DIR__, 4), dirname(__DIR__, 2)];

    $candidates = [];
    foreach ($repoRoots as $repoRoot) {
        $candidates[] = $repoRoot . '/plan/specs/moon-dependency-tree.md';
        $candidates[] = $repoRoot . '/modules/AI/plan/specs/moon-dependency-tree.md';
    }

    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }

    throw new RuntimeException('Moon dependency spec not found at ' . implode(' or ', $candidates));
}

function aiMoonDependencySpec(): string
{
    return (string) file_get_contents(aiMoonDependencySpecPath());
}

test('the moon dependency spec records the source as documented but unconfirmed', function () {
    $spec = aiMoonDependencySpec();

    expect($spec)->toContain('DOCUMENTED')
        ->and($spec)->toContain('unconfirmed');
});

test('the moon dependency spec carries no number other than the source id', function () {
    $withoutSourceId = str_replace('WIK-071', '', aiMoonDependencySpec());

    expect($withoutSourceId)->not->toMatch('/\d/');
});

test('the moon dependency spec invents no rule ids', function () {
    preg_match_all('/\b[A-Z]{2,}-\d+\b/', aiMoonDependencySpec(), $matches);

    expect($matches[0])->toBe(['WIK-071']);
});
