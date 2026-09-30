<?php

/**
 * Drives the declared diplomacy policy a modder edits:
 * resources/behavior/diplomacy.yaml.
 */

it('keeps every declared relation inside the closed set of relation kinds', function () {
    $document = aiDiplomacyPolicyDocument();
    $kinds = (array) ($document['relation_kinds'] ?? []);
    $relations = (array) ($document['relations'] ?? []);

    expect($kinds)->not->toBeEmpty()
        ->and($kinds)->toBe(array_values(array_unique($kinds)))
        ->and($relations)->not->toBeEmpty()
        ->and(aiDiplomacyRelationsOutsideClosedSet($relations, $kinds))->toBe([]);
});

it('flags a relation outside the closed set, so the closed-set check bites', function () {
    $relations = ['pact' => 'friendly', 'ceasefire' => 'neutral'];
    $kinds = ['pact', 'nap', 'war'];

    expect(aiDiplomacyRelationsOutsideClosedSet($relations, $kinds))->toBe(['ceasefire']);
});

it('returns the declared default stance for a relation the policy does not list', function () {
    $document = aiDiplomacyPolicyDocument();
    $default = $document['default_stance'] ?? null;

    expect($default)->toBeString()->not->toBeEmpty()
        ->and(aiDiplomacyStanceFor($document, 'ceasefire'))->toBe($default);
});

it('returns the declared stance for every relation the policy lists', function () {
    $document = aiDiplomacyPolicyDocument();
    $relations = (array) $document['relations'];

    expect($relations)->not->toBeEmpty();

    foreach ($relations as $relation => $stance) {
        expect($stance)->toBeString()->not->toBeEmpty()
            ->and(aiDiplomacyStanceFor($document, (string) $relation))->toBe($stance);
    }
});

it('falls back to the declared default when the policy lists no relations at all', function () {
    $document = ['relation_kinds' => [], 'default_stance' => 'neutral', 'relations' => []];

    expect(aiDiplomacyRelationsOutsideClosedSet([], []))->toBe([])
        ->and(aiDiplomacyStanceFor($document, 'pact'))->toBe('neutral');
});

it('resolves the same stance on every repeated read of the policy', function () {
    $first = aiDiplomacyPolicyDocument();
    $second = aiDiplomacyPolicyDocument();

    expect($second)->toBe($first)
        ->and(aiDiplomacyStanceFor($second, 'ceasefire'))->toBe(aiDiplomacyStanceFor($first, 'ceasefire'));
});

function aiDiplomacyPolicyPath(): string
{
    return dirname(__DIR__, 2) . '/resources/behavior/diplomacy.yaml';
}

/**
 * @return array<string, mixed>
 */
function aiDiplomacyPolicyDocument(): array
{
    $path = aiDiplomacyPolicyPath();

    expect(is_file($path))->toBeTrue();

    return aiDiplomacyPolicyDocumentFrom((string) file_get_contents($path));
}

/**
 * The policy file uses a fixed two-level subset of YAML -- scalar pairs, indented
 * mappings and one block sequence -- so it is read directly instead of depending on a
 * YAML extension the host may not have installed.
 *
 * @return array<string, mixed>
 */
function aiDiplomacyPolicyDocumentFrom(string $contents): array
{
    $document = [];
    $section = null;

    foreach (preg_split('/\R/', $contents) ?: [] as $line) {
        $trimmed = trim($line);

        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            continue;
        }

        if (str_starts_with($trimmed, '- ')) {
            if ($section === null) {
                continue;
            }

            $document[$section][] = aiDiplomacyPolicyScalar(substr($trimmed, 2));

            continue;
        }

        [$key, $value] = array_pad(explode(':', $trimmed, 2), 2, '');
        $key = trim($key);
        $value = trim($value);

        if ($line !== ltrim($line) && $section !== null) {
            $document[$section][$key] = aiDiplomacyPolicyScalar($value);

            continue;
        }

        $section = $value === '' ? $key : null;
        $document[$key] = $value === '' ? [] : aiDiplomacyPolicyScalar($value);
    }

    return $document;
}

function aiDiplomacyPolicyScalar(string $value): string|int|bool
{
    $unquoted = trim($value, "\"'");

    return match (true) {
        $unquoted === 'true' => true,
        $unquoted === 'false' => false,
        preg_match('/^-?\d+$/', $unquoted) === 1 => (int) $unquoted,
        default => $unquoted,
    };
}

/**
 * @param array<string, mixed> $relations
 * @param array<int, string> $kinds
 * @return array<int, string>
 */
function aiDiplomacyRelationsOutsideClosedSet(array $relations, array $kinds): array
{
    return array_values(array_diff(array_keys($relations), $kinds));
}

/**
 * @param array<string, mixed> $document
 */
function aiDiplomacyStanceFor(array $document, string $relation): mixed
{
    $relations = (array) ($document['relations'] ?? []);

    return $relations[$relation] ?? ($document['default_stance'] ?? null);
}
