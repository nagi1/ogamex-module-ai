<?php

use Illuminate\Support\Facades\File;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

it('declares no symbol derived from an unverified help-wiki concept', function () {
    // A page that states no numbers cannot decide anything, so no symbol in this module may be
    // named after one: the pilot's behaviour comes from resources/behavior/, never from a wiki
    // page. The trailing lookahead keeps a legitimate "Helper"/"Helpful" name out of the net.
    $helpConceptPattern = '/(Help|Wiki|Tutorial|Faq|Faqs)(?=[A-Z0-9_]|$)/i';

    $declaredSymbols = collect(File::allFiles(dirname(__DIR__, 2) . '/app'))
        ->flatMap(function (SplFileInfo $file): array {
            preg_match_all(
                '/\b(?:class|enum|interface|trait|const)\s+([A-Za-z_]\w*)/',
                (string) file_get_contents($file->getPathname()),
                $matches
            );

            return $matches[1];
        })
        ->unique();

    $offenders = $declaredSymbols
        ->filter(fn (string $symbol): bool => preg_match($helpConceptPattern, $symbol) === 1)
        ->values();

    expect($offenders->all())->toBe([]);
});
