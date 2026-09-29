<?php

// The acceptance for this note asks for a unit test over plan/specs/ogame/mission.md. This
// module collects no test under tests/Unit, so the guard runs here instead and asserts the
// same markers: provenance, claim type, host status, and the absence of any mechanic value.

/** The note sits at the module root, beside the code it describes. */
function unconfirmedMissionNotePath(): string
{
    return dirname(__DIR__, 2) . '/plan/specs/ogame/mission.md';
}

/** WIK-212 is a provenance identifier, not a mechanic value, so identifiers are stripped first. */
function unconfirmedMissionNoteWithoutIdentifiers(string $note): string
{
    return (string) preg_replace('/\b[A-Z]+-\d+\b/', '', $note);
}

it('records the mission concept page as documented and pending host confirmation', function () {
    $path = unconfirmedMissionNotePath();

    expect(is_file($path))->toBeTrue("Spec note missing at {$path}.");

    $note = (string) file_get_contents($path);

    expect($note)
        ->toContain('Source provenance: WIK-212')
        ->toContain('Claim type: DOCUMENTED')
        ->toContain('Host status: pending host confirmation');
});

it('carries no mechanic value the source did not state', function () {
    $path = unconfirmedMissionNotePath();

    expect(is_file($path))->toBeTrue("Spec note missing at {$path}.");

    $note = (string) file_get_contents($path);

    preg_match_all('/\d+/', unconfirmedMissionNoteWithoutIdentifiers($note), $found);

    expect($found[0])->toBe([]);
});

it('catches a stated number, so the guard over the note is not vacuous', function () {
    // The opposite situation: a note that does state a value must fail the same scan.
    expect(unconfirmedMissionNoteWithoutIdentifiers('Source provenance: WIK-212; claim type DOCUMENTED.'))
        ->not->toMatch('/\d/');

    expect(unconfirmedMissionNoteWithoutIdentifiers('Fleet slots per planet: 5.'))
        ->toMatch('/\d/');
});
