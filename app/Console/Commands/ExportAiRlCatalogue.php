<?php

namespace Modules\AI\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Modules\AI\Actions\ExportAiEconomyCatalogueAction;
use Modules\AI\Console\Commands\Concerns\OpensRlUniverse;
use Modules\AI\Models\AiProfile;
use OGame\Factories\PlayerServiceFactory;

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
    {--max-level=40 : Highest level tabulated per object}')]
class ExportAiRlCatalogue extends Command
{
    use OpensRlUniverse;

    public function handle(): int
    {
        $this->openRlUniverse((string) $this->argument('universe'));

        $planets = AiProfile::query()->orderBy('player_id')->pluck('player_id')
            ->map(static fn (int $playerId) => app(PlayerServiceFactory::class)->make($playerId, true)->planets->first())
            ->all();

        $catalogue = app(ExportAiEconomyCatalogueAction::class)->handle($planets, max(1, (int) $this->option('max-level')));
        file_put_contents((string) $this->argument('out'), json_encode($catalogue, JSON_THROW_ON_ERROR));

        $this->info(sprintf('RL-CATALOGUE: %s, %d object(s), %d time modifier(s), %d planet(s)', $this->argument('out'), count($catalogue['objects']), count($catalogue['time_modifiers']), count($catalogue['planets'])));

        return self::SUCCESS;
    }
}
