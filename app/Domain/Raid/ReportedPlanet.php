<?php

namespace Modules\AI\Domain\Raid;

use Modules\AI\Actions\ExportAiCombatCatalogueAction;
use OGame\Factories\PlanetServiceFactory;
use OGame\GameObjects\Models\UnitObject;
use OGame\Models\EspionageReport;
use OGame\Models\Highscore;
use OGame\Models\Message;
use OGame\Models\Planet;
use OGame\Models\User;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use OGame\Services\PlayerService;

/**
 * A foreign planet as the account's own espionage report saw it, never as it stands now.
 *
 * The raid planner and its battle screen used to load the live target: its real ships, defence and
 * stock. A player only knows what the last report said, so the AI was omniscient (never surprised by a
 * rebuilt wall or a fleet that came home) and timid at once (every defended target was simulated at
 * its true strength, so only empty planets passed and LIFE_FIGHTS sat at 9%). This builds a detached
 * copy of the planet row whose units and resources are the report's: the battle engine, the loot
 * formula and the launch ladder then answer "what does my report say", and the dispatch still meets
 * the live planet, which is where surprises, ninjas and lost fleets come from.
 *
 * The copy is never written: it is marked as not existing, so a stray save cannot reach the real row.
 * A report that could not see the fleet or the defence (too few probes) returns null here; the raid
 * planner reads it through `seenBy`, which fills the hidden section with the worst case a player can
 * read (`estimated`) rather than refusing the raid outright.
 */
class ReportedPlanet
{
    public function __construct(private PlanetServiceFactory $planetServiceFactory)
    {
    }

    /**
     * With `$owner`, the copy's owner is that player (the owner as the report pictures them, research included) instead
     * of the live account, so a battle the engine fights on the copy reads no research the report did not show.
     */
    public function of(EspionageReport $report, PlayerService|null $owner = null): PlanetService|null
    {
        if ($report->ships === null || $report->defense === null) {
            return null;
        }

        $live = Planet::query()
            ->where('galaxy', (int) $report->planet_galaxy)
            ->where('system', (int) $report->planet_system)
            ->where('planet', (int) $report->planet_position)
            ->where('planet_type', (int) $report->planet_type)
            ->where('destroyed', 0)
            ->first();
        if ($live === null) {
            return null;
        }

        $seen = $live->replicate();
        $seen->id = $live->id;
        $seen->exists = false;

        foreach ([...ObjectService::getShipObjects(), ...ObjectService::getDefenseObjects()] as $object) {
            $seen->{$object->machine_name} = (int) ($report->ships[$object->machine_name] ?? $report->defense[$object->machine_name] ?? 0);
        }

        $resources = $report->resources ?? [];
        $seen->metal = (float) ($resources['metal'] ?? 0);
        $seen->crystal = (float) ($resources['crystal'] ?? 0);
        $seen->deuterium = (float) ($resources['deuterium'] ?? 0);

        return $this->planetServiceFactory->makeFromModel($seen, $owner);
    }

    /**
     * The report's planet owned by the owner as the report pictures them: the research the probes read, or the observer's
     * own levels where they read none (`players::forecast`). The battle engine reads the defender's research from the
     * planet's owner, so without this a fight on the copy used research the account never saw (no-cheating rule).
     */
    public function seenBy(EspionageReport $report, PlayerService $observer): PlanetService|null
    {
        return $this->of($this->estimated($report, $observer), $this->pictured($report, $observer));
    }

    /**
     * A redacted report with its hidden sections estimated, the way a player sizes a raid without a full report: every
     * public military point (the highscore's: ships and defence at full price, civil ships at half) the visible sections
     * do not explain is assumed to stand on this body, in the mix the account's own last report that read the section saw
     * here, or else as the cheapest unit of the missing kind that fights with a real weapon. The worst case is the margin:
     * the more is hidden, the larger the picture the battle forecast and the profit gate are run on. A full report comes
     * back as it is; the estimate is a detached copy, never saved (`rl/ogsim` players::guessed_sections).
     */
    public function estimated(EspionageReport $report, PlayerService $observer): EspionageReport
    {
        if ($report->ships !== null && $report->defense !== null) {
            return $report;
        }

        $shown = $this->militaryValue($report->ships ?? []) + $this->militaryValue($report->defense ?? []);
        $points = (int) (Highscore::query()->where('player_id', (int) $report->planet_user_id)->value('military_built') ?? 0);
        $hidden = max(0.0, $points * 1000 - $shown);

        $ships = $report->ships === null ? $this->olderSection($report, $observer, 'ships') : [];
        $defense = $report->defense === null ? $this->olderSection($report, $observer, 'defense') : [];
        if ($this->militaryValue($ships) + $this->militaryValue($defense) <= 0.0) {
            $kind = $report->defense === null ? ObjectService::getDefenseObjects() : ObjectService::getMilitaryShipObjects();
            $cheapest = $this->cheapestFighter($kind, $observer);
            if ($cheapest === null) {
                return $report;
            }
            if ($report->defense === null) {
                $defense = [$cheapest->machine_name => 1];
            } else {
                $ships = [$cheapest->machine_name => 1];
            }
        }

        $scale = $hidden / ($this->militaryValue($ships) + $this->militaryValue($defense));
        $scaled = static fn (array $section): array => array_map(static fn ($amount): int => (int) ceil((int) $amount * $scale), $section);

        $estimate = $report->replicate();
        $estimate->id = $report->id;
        $estimate->exists = false;
        $estimate->ships = $report->ships ?? $scaled($ships);
        $estimate->defense = $report->defense ?? $scaled($defense);

        return $estimate;
    }

    /**
     * The section as the account's own newest report on this body that read it saw it, empty when none did.
     *
     * @return array<string, int>
     */
    private function olderSection(EspionageReport $report, PlayerService $observer, string $section): array
    {
        $own = Message::query()->where('user_id', $observer->getId())->whereNotNull('espionage_report_id')->select('espionage_report_id');
        $older = EspionageReport::query()
            ->whereIn('id', $own)
            ->where('planet_galaxy', $report->planet_galaxy)
            ->where('planet_system', $report->planet_system)
            ->where('planet_position', $report->planet_position)
            ->where('planet_type', $report->planet_type)
            ->whereNotNull($section)
            ->orderByDesc('id')
            ->value($section);

        return is_array($older) ? $older : (is_string($older) ? (array) json_decode($older, true) : []);
    }

    /**
     * The highscore's military value of a report section: ships and defence at full price, civil ships at half.
     *
     * @param array<string, int> $section
     */
    private function militaryValue(array $section): float
    {
        $civil = array_map(static fn (UnitObject $ship): string => $ship->machine_name, ObjectService::getCivilShipObjects());
        $value = 0.0;
        foreach ($section as $machineName => $amount) {
            $price = ObjectService::getObjectRawPrice((string) $machineName)->sum() * (int) $amount;
            $value += in_array($machineName, $civil, true) ? $price / 2 : $price;
        }

        return $value;
    }

    /**
     * The cheapest of these units the host's combat takes that carries a real weapon.
     *
     * @param array<UnitObject> $units
     */
    private function cheapestFighter(array $units, PlayerService $observer): UnitObject|null
    {
        $home = $observer->planets->first();
        $sitsOut = $home === null ? [] : app(ExportAiCombatCatalogueAction::class)->nonCombatants($home);
        $cheapest = null;
        foreach ($units as $unit) {
            if (in_array($unit->id, $sitsOut, true) || $unit->properties->attack->calculate($observer)->totalValue <= 1) {
                continue;
            }
            if ($cheapest === null || ObjectService::getObjectRawPrice($unit->machine_name)->sum() < ObjectService::getObjectRawPrice($cheapest->machine_name)->sum()) {
                $cheapest = $unit;
            }
        }

        return $cheapest;
    }

    /** The report's owner with the research the report shows (the observer's own where it shows none); never saved. */
    public function pictured(EspionageReport $report, PlayerService $observer): PlayerService
    {
        $owner = User::query()->whereKey((int) $report->planet_user_id)->exists() ? (int) $report->planet_user_id : $observer->getId();
        $pictured = resolve(PlayerService::class, ['player_id' => $owner]);
        // The column is null when the probes read no research, whatever the model's annotation says.
        $read = $report->getAttribute('research');
        $seen = is_array($read) && $read !== [] ? $read : null;
        foreach (ObjectService::getResearchObjects() as $research) {
            $pictured->setResearchLevel($research->machine_name, $seen === null ? $observer->getResearchLevel($research->machine_name) : (int) ($seen[$research->machine_name] ?? 0), false);
        }

        return $pictured;
    }
}
