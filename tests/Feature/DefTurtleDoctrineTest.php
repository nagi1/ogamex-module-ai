<?php

/**
 * WIK-017 records a position, not a mechanic: the def-turtle's stockpile doctrine and the
 * etiquette stance on missiles against turtles. A doctrine has no runtime path, so the spec under
 * plan/specs is the whole delivery and these assertions are what keep it pinned to its source.
 */

beforeEach(function () {
    $this->sourceId = 'WIK-017';
    $this->specPath = dirname(__DIR__, 2) . '/plan/specs/def-turtle.md';

    expect(is_file($this->specPath))->toBeTrue();

    $this->spec = (string) file_get_contents($this->specPath);

    expect($this->spec)->toContain($this->sourceId);
});

it('quotes the documented def-turtle position verbatim', function () {
    expect($this->spec)->toContain(
        'the turtler must accumulate a very large resource stockpile to absorb the stated cost (34)'
    );
});

it('states the position as doctrine that carries no counts and no mechanics', function () {
    expect($this->spec)
        ->toContain('This document asserts no unit counts and no mechanics.')
        ->toContain('Kind: written doctrine. This document defines no runtime behaviour.');
});

it('cites the quoted figure once, and only inside the verbatim citation', function () {
    $lines = preg_split('/\R/', $this->spec) ?: [];

    $outsideCitation = array_filter(
        $lines,
        fn (string $line): bool => ! str_starts_with(ltrim($line), '>'),
    );

    $prose = str_replace($this->sourceId, '', implode("\n", $outsideCitation));

    expect($prose)->not->toMatch('/[0-9]/')
        ->and(substr_count($this->spec, '34'))->toBe(1);
});
