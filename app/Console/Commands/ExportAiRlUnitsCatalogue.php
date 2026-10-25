<?php

namespace Modules\AI\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Modules\AI\Actions\ExportAiUnitsCatalogueAction;
use Modules\AI\Console\Commands\Concerns\OpensRlUniverse;
use Modules\AI\Models\AiProfile;
use OGame\Factories\PlayerServiceFactory;

/**
 * Writes the host's shipyard tables for the world simulator (plan/rl/world-sim-spec.md, units), with every AI account's
 * home planet of an `ai:rl-universe` file as one start state. Reads the file only; nothing is saved.
 *
 * DEV TOOLING: it opens only the file it is given and never touches the configured database.
 */
#[Description('Export the host ships and defences as shipyard tables for the world simulator (plan/rl).')]
#[Signature('ai:rl-units-catalogue
    {universe : A SQLite file made by ai:rl-universe}
    {out : The JSON file to write}
    {--grid-level=24 : Highest level of each build-time modifier tabulated}')]
class ExportAiRlUnitsCatalogue extends Command
{
    use OpensRlUniverse;

    public function handle(): int
    {
        $this->openRlUniverse((string) $this->argument('universe'));

        $planets = AiProfile::query()->orderBy('player_id')->pluck('player_id')
            ->map(static fn (int $playerId) => app(PlayerServiceFactory::class)->make($playerId, true)->planets->first())
            ->filter()->values()->all();

        $catalogue = app(ExportAiUnitsCatalogueAction::class)->handle($planets, max(1, (int) $this->option('grid-level')));
        file_put_contents((string) $this->argument('out'), json_encode($catalogue, JSON_THROW_ON_ERROR));

        $this->info(sprintf('RL-UNITS-CATALOGUE: %s, %d unit(s), %d time modifier(s), %d planet(s)', $this->argument('out'), count($catalogue['units']), count($catalogue['time_modifiers']), count($catalogue['planets'])));

        return self::SUCCESS;
    }
}
