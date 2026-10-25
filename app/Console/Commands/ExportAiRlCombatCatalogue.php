<?php

namespace Modules\AI\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Modules\AI\Actions\ExportAiCombatCatalogueAction;
use Modules\AI\Console\Commands\Concerns\OpensRlUniverse;
use Modules\AI\Models\AiProfile;
use OGame\Factories\PlayerServiceFactory;

/**
 * Writes the host's combat tables for the raid simulator (plan/rl M8, plan/rl/raid-sim-spec.md): every ship and
 * defence, valued for one account of an `ai:rl-universe` file. Reads the file only; nothing is saved.
 *
 * DEV TOOLING: it opens only the file it is given and never touches the configured database.
 */
#[Description('Export the host ships and defences as combat tables for the raid simulator (plan/rl).')]
#[Signature('ai:rl-combat-catalogue
    {universe : A SQLite file made by ai:rl-universe}
    {out : The JSON file to write}
    {--player= : The account whose class bonuses apply (default: the first AI account)}
    {--max-level=20 : Highest research level tabulated}')]
class ExportAiRlCombatCatalogue extends Command
{
    use OpensRlUniverse;

    public function handle(): int
    {
        $this->openRlUniverse((string) $this->argument('universe'));

        $playerId = (int) ($this->option('player') ?? AiProfile::query()->orderBy('player_id')->value('player_id'));
        $player = app(PlayerServiceFactory::class)->make($playerId, true);

        $catalogue = app(ExportAiCombatCatalogueAction::class)->handle($player, max(1, (int) $this->option('max-level')));
        file_put_contents((string) $this->argument('out'), json_encode($catalogue, JSON_THROW_ON_ERROR));

        $this->info(sprintf('RL-COMBAT-CATALOGUE: %s, %d unit(s), player %d', $this->argument('out'), count($catalogue['units']), $playerId));

        return self::SUCCESS;
    }
}
