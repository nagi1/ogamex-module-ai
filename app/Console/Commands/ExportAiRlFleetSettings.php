<?php

namespace Modules\AI\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Modules\AI\Actions\ExportAiFleetSettingsAction;
use Modules\AI\Console\Commands\Concerns\OpensRlUniverse;
use Modules\AI\Models\AiProfile;
use OGame\Factories\PlayerServiceFactory;

/**
 * Writes the universe settings the simulator's fleet module needs for distance, flight time and fuel
 * (plan/rl/world-sim-spec.md). Reads the file only; nothing is saved.
 *
 * DEV TOOLING: it opens only the file it is given and never touches the configured database.
 */
#[Description('Export the universe fleet settings (distance, speed, fuel) for the world simulator (plan/rl).')]
#[Signature('ai:rl-fleet-settings
    {universe : A SQLite file made by ai:rl-universe}
    {out : The JSON file to write}
    {--player= : The account whose class bonuses apply (default: the first AI account)}')]
class ExportAiRlFleetSettings extends Command
{
    use OpensRlUniverse;

    public function handle(): int
    {
        $this->openRlUniverse((string) $this->argument('universe'));

        $playerId = (int) ($this->option('player') ?? AiProfile::query()->orderBy('player_id')->value('player_id'));
        $player = app(PlayerServiceFactory::class)->make($playerId, true);

        $settings = app(ExportAiFleetSettingsAction::class)->handle($player);
        file_put_contents((string) $this->argument('out'), json_encode($settings, JSON_THROW_ON_ERROR));

        $this->info(sprintf('RL-FLEET-SETTINGS: %s, player %d', $this->argument('out'), $playerId));

        return self::SUCCESS;
    }
}
