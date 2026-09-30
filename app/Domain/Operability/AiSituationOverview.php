<?php

namespace Modules\AI\Domain\Operability;

use RuntimeException;

/**
 * The five review-loop questions, answered (improvement-loop.md). Each row carries the question
 * (as a language key), the figure, the window and the evidence class — measured, code-read or
 * inferred — never an impression. The view renders this; it never recomputes it.
 *
 * The same overview answers the Galaxy View inactivity question for a player's last-login age.
 * The thresholds and the marker strings themselves live in resources/behavior, so retuning how
 * long an account may sleep before it is flagged is a data edit, not a code edit.
 *
 * Fleet observations carry their provenance for the same reason: a phalanx reading proves a fleet
 * is in flight, it does not measure when the fleet is back. The overview therefore marks that row
 * unverified and reports no return time, instead of printing an inferred timing as if it had been
 * observed.
 *
 * A scanned player is banded by points as well: the source states general protection with a 1:5
 * ratio up to 50.000 points and a 1:10 ratio up to 500.000, so a target that is out of reach is
 * visible before an attack is planned rather than discovered in the combat report. The source
 * bands overlap at 50.000 points; the reading taken here gives that point to the higher band, and
 * the bounds that resolve the overlap live in resources/behavior/newbie_protection.json so a
 * modder retunes them without touching this class. Above 500.000 points the source states nothing,
 * so no band is claimed there.
 *
 * @property list<array{question: string, figure: string, evidence: string, window: int}> $questions
 * @property list<array{label: string, source: string, return_at?: int}> $observations
 * @property list<array{name?: string, points?: int}> $scannedPlayers
 */
readonly class AiSituationOverview
{
    /**
     * Galaxy View writes nothing next to a player who logged in recently.
     */
    public const NO_INACTIVITY_MARKER = '';

    /**
     * The label a fleet observation derived from a phalanx reading is rendered with. The source
     * states no threshold and no doctrine variant, so this label is the whole of the rule.
     */
    public const UNVERIFIED_MARKER = 'unverified';

    /**
     * An observation read straight off the fleet carries its own timing; nothing is written next
     * to it.
     */
    public const NO_PROVENANCE_MARKER = '';

    /**
     * Source tag of a fleet seen through a sensor phalanx.
     */
    public const PHALANX_SOURCE = 'phalanx';

    /**
     * Source tag of a fleet seen through a direct scan.
     */
    public const DIRECT_SCAN_SOURCE = 'scan';

    private const MARKER_STEPS_FILE = '/resources/behavior/galaxy_inactivity_markers.json';

    private const PROTECTION_BANDS_FILE = '/resources/behavior/newbie_protection.json';

    /**
     * @param list<array{question: string, figure: string, evidence: string, window: int}> $questions
     * @param list<array{label: string, source: string, return_at?: int}> $observations
     * @param list<array{name?: string, points?: int}> $scannedPlayers
     */
    public function __construct(
        public int $days = 1,
        public array $questions = [],
        public array $observations = [],
        public array $scannedPlayers = [],
    ) {
    }

    /**
     * The marker for an account whose last login is $inactiveDays days old: nothing below the
     * first threshold, otherwise the marker of the highest threshold that age has reached.
     */
    public function inactivityMarkerFor(int $inactiveDays): string
    {
        $marker = self::NO_INACTIVITY_MARKER;

        // The steps are ordered by threshold, so the first step not yet reached ends the walk.
        foreach (self::markerSteps() as $step) {
            if ($inactiveDays < $step['from_days']) {
                return $marker;
            }

            $marker = $step['marker'];
        }

        return $marker;
    }

    /**
     * The fleet observations as the view renders them: a phalanx-derived row is marked unverified
     * and reports no return time, every other source keeps the timing it was read with.
     *
     * @return list<array{label: string, provenance: string, return_at: ?int}>
     */
    public function renderedObservations(): array
    {
        return array_map($this->renderObservation(...), $this->observations);
    }

    /**
     * The protection band a player at $points falls into, or the unknown label when the source
     * states no band for that many points.
     */
    public function protectionBandFor(int $points): string
    {
        $data = self::protectionData();

        // The bands are ordered by points, so the first band not yet reached ends the walk.
        foreach ($data['protection_bands'] as $band) {
            if ($points < $band['from_points']) {
                return $data['unknown_label'];
            }

            if ($points <= $band['to_points']) {
                return $band['label'];
            }
        }

        return $data['unknown_label'];
    }

    /**
     * The scanned players as the view renders them: each one carries the protection band its
     * points put it in, so a target that cannot be attacked is visible before an attack is
     * planned.
     *
     * @return list<array{name: string, points: int, protection_band: string}>
     */
    public function scannedPlayersWithProtectionBand(): array
    {
        return array_map($this->renderScannedPlayer(...), $this->scannedPlayers);
    }

    /**
     * @param array{name?: string, points?: int} $observation
     * @return array{label: string, provenance: string, return_at: ?int}
     */
    private function renderObservation(array $observation): array
    {
        $label = $observation['label'] ?? '';

        // A phalanx reading is an inference about the return, so the time is dropped rather than
        // shown as a figure nothing measured.
        if (($observation['source'] ?? '') === self::PHALANX_SOURCE) {
            return [
                'label' => $label,
                'provenance' => self::UNVERIFIED_MARKER,
                'return_at' => null,
            ];
        }

        return [
            'label' => $label,
            'provenance' => self::NO_PROVENANCE_MARKER,
            'return_at' => $observation['return_at'] ?? null,
        ];
    }

    /**
     * @param array{name?: string, points?: int} $player
     * @return array{name: string, points: int, protection_band: string}
     */
    private function renderScannedPlayer(array $player): array
    {
        $points = (int) ($player['points'] ?? 0);

        return [
            'name' => (string) ($player['name'] ?? ''),
            'points' => $points,
            'protection_band' => $this->protectionBandFor($points),
        ];
    }

    /**
     * @return list<array{from_days: int, marker: string}>
     */
    private static function markerSteps(): array
    {
        static $steps = null;

        if ($steps !== null) {
            return $steps;
        }

        $path = dirname(__DIR__, 3) . self::MARKER_STEPS_FILE;
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException('Missing inactivity marker data file: ' . $path);
        }

        $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

        return $steps = array_values(array_map(
            static fn (array $step): array => [
                'from_days' => (int) $step['from_days'],
                'marker' => (string) $step['marker'],
            ],
            $decoded['inactivity_markers'],
        ));
    }

    /**
     * @return array{unknown_label: string, protection_bands: list<array{from_points: int, to_points: int, label: string}>}
     */
    private static function protectionData(): array
    {
        static $data = null;

        if ($data !== null) {
            return $data;
        }

        $path = dirname(__DIR__, 3) . self::PROTECTION_BANDS_FILE;
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException('Missing newbie protection data file: ' . $path);
        }

        $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

        return $data = [
            'unknown_label' => (string) $decoded['unknown_label'],
            'protection_bands' => array_values(array_map(
                static fn (array $band): array => [
                    'from_points' => (int) $band['from_points'],
                    'to_points' => (int) $band['to_points'],
                    'label' => (string) $band['label'],
                ],
                $decoded['protection_bands'],
            )),
        ];
    }
}
