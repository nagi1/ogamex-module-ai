<?php

namespace Modules\AI\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\AI\Actions\ExportAiEconomyCatalogueAction;
use Modules\AI\Console\Commands\Concerns\OpensRlUniverse;
use Modules\AI\Models\AiProfile;
use OGame\Factories\PlanetServiceFactory;
use OGame\Factories\PlayerServiceFactory;
use OGame\Services\PlanetService;

/**
 * Writes the host's economy tables for the fast simulator (plan/rl, rl/ogsim), with every AI account's home planet
 * of an `ai:rl-universe` file as one start state. Reads the file only; nothing is saved.
 *
 * DEV TOOLING: it opens only the file it is given and never touches the configured database.
 */
#[Description('Export the host economy as tables for the fast simulator (plan/rl).')]
#[Signature('ai:rl-catalogue
    {universe : A SQLite file made by ai:rl-universe}
    {out : The JSON file to write}
    {--max-level=40 : Highest level tabulated per object}
    {--sites= : A JSON list of colonies to tabulate, each {planet, position, temp_max, fields}}
    {--moons : Tabulate a moon beside each home instead of the home (made and rolled back), with the buildings a moon takes}')]
class ExportAiRlCatalogue extends Command
{
    use OpensRlUniverse;

    public function handle(): int
    {
        $this->openRlUniverse((string) $this->argument('universe'));

        $planets = AiProfile::query()->orderBy('player_id')->pluck('player_id')
            ->map(static fn (int $playerId) => app(PlayerServiceFactory::class)->make($playerId, true)->planets->first())
            ->all();

        // A moon is a planet of its own type, with its own buildings and queue: the host makes one beside each home
        // inside a transaction that is rolled back once its tables are read.
        $moons = (bool) $this->option('moons');
        if ($moons) {
            DB::beginTransaction();
            $planets = array_map(static fn (PlanetService $home): PlanetService => app(PlanetServiceFactory::class)->createMoonForPlanet($home, 0, 0, 20), $planets);
        }

        $sites = $this->option('sites') ? json_decode((string) file_get_contents((string) $this->option('sites')), true, 512, JSON_THROW_ON_ERROR) : [];
        $catalogue = app(ExportAiEconomyCatalogueAction::class)->handle($planets, max(1, (int) $this->option('max-level')), ExportAiEconomyCatalogueAction::UNIT_GRID, $sites);
        if ($moons) {
            DB::rollBack();
        }
        file_put_contents((string) $this->argument('out'), json_encode($catalogue, JSON_THROW_ON_ERROR));

        $this->info(sprintf('RL-CATALOGUE: %s, %d object(s), %d time modifier(s), %d planet(s)', $this->argument('out'), count($catalogue['objects']), count($catalogue['time_modifiers']), count($catalogue['planets'])));

        return self::SUCCESS;
    }
}
