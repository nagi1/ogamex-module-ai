<?php

use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

const UNVERIFIED_ATTACK_CONCEPT = 'attack-doctrine';

beforeEach(function () {
    $this->moduleRoot = dirname(__DIR__, 2);
    $this->documentPath = $this->moduleRoot . '/plan/unverified-attack-concept.md';
});

it('records the attack concept as unverified documentation', function () {
    expect(is_file($this->documentPath))->toBeTrue();

    $document = (string) file_get_contents($this->documentPath);

    expect($document)->toContain('UNVERIFIED');
});

it('keeps the unverified attack concept free of game values', function () {
    $document = (string) file_get_contents($this->documentPath);

    expect($document)->not->toMatch('/[0-9]/');
});

it('encodes no attack rule into the module runtime', function () {
    $sources = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($this->moduleRoot . '/app')
    );

    foreach ($sources as $source) {
        if (! $source->isFile()) {
            continue;
        }

        if ($source->getExtension() !== 'php') {
            continue;
        }

        expect((string) file_get_contents($source->getPathname()))
            ->not->toContain(UNVERIFIED_ATTACK_CONCEPT);
    }
});
