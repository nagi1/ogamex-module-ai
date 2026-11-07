<?php

namespace Modules\AI\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Modules\AI\Console\Commands\Concerns\OpensRlUniverse;
use Modules\AI\Models\AiProfile;
use Modules\AI\Support\SimulatedTime;
use OGame\Factories\PlanetServiceFactory;
use OGame\Factories\PlayerServiceFactory;
use OGame\GameObjects\Models\Abstracts\GameObject;
use OGame\GameObjects\Models\Enums\GameObjectType;
use OGame\Models\Officer;
use OGame\Models\Planet;
use OGame\Models\Planet\Coordinate;
use OGame\Models\Resources;
use OGame\Models\User;
use OGame\Services\ObjectService;
use OGame\Services\OfficerService;
use OGame\Services\PlanetService;
use OGame\Services\PlayerService;
use Throwable;

/**
 * A training universe that starts where a simulator season stopped (plan/rl, STRONG-PLAYER-PLAN 4 Nov): the season's
 * `levels` dump (`rl/ogsim/examples/season.rs`, arm `levels`) gives every account's planets, moons, levels, research,
 * ships, defence, resources, class and dark matter, and each of the strongest active accounts is rebuilt on one AI
 * account of a fresh `ai:rl-universe`. PHP and the simulator then play the same late-game state over the same window,
 * so the late game is measured in minutes instead of a PHP year.
 *
 * DEV TOOLING: it writes only the file it is given and never touches the configured database.
 */
#[Description('Create a training universe whose AI accounts carry a simulator season\'s end state (plan/rl).')]
#[Signature('ai:rl-import-sim
    {path : The SQLite file to create (overwritten)}
    {levels : The season\'s levels dump ({out}.levels.json)}
    {--accounts=12 : The strongest active accounts of the dump to rebuild}
    {--speed=8 : Economy, research and fleet speed}
    {--seed=1 : Seed for the universe}
    {--at=2026-10-05T00:00:00Z : The instant the universe is created at; start ai:sim --from here}')]
class ImportAiRlSimState extends Command
{
    use OpensRlUniverse;

    /**
     * The simulator's world (the season data's `fleet-settings-u1.json`, which every season flies on): its galaxies and
     * systems, so every coordinate of the dump exists here and the donut wraps where it wraps there, and its war, peaceful
     * and holding fleet speeds and deuterium consumption, so a fleet takes the flight time and fuel it took there.
     */
    private const SETTINGS = ['number_of_galaxies' => 6, 'number_of_systems' => 499, 'fleet_speed_war' => 2, 'fleet_speed_peaceful' => 5, 'fleet_speed_holding' => 3,
        'deuterium_consumption' => 0.5];

    public function handle(): int
    {
        $path = (string) $this->argument('path');
        $dump = json_decode((string) file_get_contents((string) $this->argument('levels')), true, 512, JSON_THROW_ON_ERROR);
        $active = array_values(array_filter($dump, static fn (array $account): bool => !$account['inactive']));
        usort($active, static fn (array $a, array $b): int => $b['total'] <=> $a['total']);
        $chosen = array_slice($active, 0, max(1, (int) $this->option('accounts')));

        Artisan::call('ai:rl-universe', [
            'path' => $path,
            '--accounts' => count($chosen),
            '--speed' => (int) $this->option('speed'),
            '--seed' => (int) $this->option('seed'),
            '--at' => (string) $this->option('at'),
            '--settings' => json_encode(self::SETTINGS, JSON_THROW_ON_ERROR),
        ]);
        $this->openRlUniverse($path);
        SimulatedTime::freezeAt((string) $this->option('at'));

        $players = AiProfile::query()->orderBy('player_id')->pluck('player_id')->all();
        foreach ($chosen as $index => $account) {
            $this->rebuild((int) $players[$index], $account);
        }

        foreach (['ogamex:scheduler:generate-highscores', 'ogamex:scheduler:generate-highscore-ranks'] as $command) {
            Artisan::call($command);
        }

        $this->info(sprintf('RL-IMPORT: %s, %d account(s) from day %s of %s', $path, count($chosen), $chosen[0]['day'] ?? '?', $this->argument('levels')));

        return self::SUCCESS;
    }

    /**
     * @param array<string, mixed> $account
     */
    private function rebuild(int $playerId, array $account): void
    {
        // Active by construction (the dump's inactive accounts are not rebuilt): seen now, as the galaxy view will show it.
        User::query()->whereKey($playerId)->update(['dark_matter' => (int) $account['dark_matter'], 'character_class' => $account['class'], 'time' => (string) now()->getTimestamp()]);
        $this->officers(User::query()->findOrFail($playerId), $account['officers'] ?? []);
        $player = app(PlayerServiceFactory::class)->make($playerId, true);
        $home = $player->planets->first();

        foreach ($account['planets'] as $index => $body) {
            $planet = $index === 0 ? $this->home($home, $body) : $this->colony($player, $body);
            if ($planet === null) {
                continue;
            }

            $this->fill($player, $planet, $body);
            if (isset($body['moon'])) {
                $moon = app(PlanetServiceFactory::class)->createMoonForPlanet($planet, 2_000_000, 20, 15);
                $this->fill($player, $moon, $body['moon']);
            }
        }
    }

    /**
     * The officers the account still has hired, for the seconds they have left (by the host's own keys).
     *
     * @param array<string, int|float> $left
     */
    private function officers(User $user, array $left): void
    {
        $service = app(OfficerService::class);
        $officer = $service->getOfficer($user);
        foreach (array_intersect_key($left, array_flip(Officer::OFFICER_TYPES)) as $key => $seconds) {
            $officer->setAttribute($key . '_until', now()->addSeconds((int) $seconds));
        }
        $officer->save();
        $service->clearCache($user);
    }

    /**
     * The seeded home moved to the dump's coordinate when that slot is free: every distance, flight and fuel the account
     * reads starts there in the simulator.
     *
     * @param array<string, mixed> $body
     */
    private function home(PlanetService|null $home, array $body): PlanetService|null
    {
        if ($home === null) {
            return null;
        }
        $at = ['galaxy' => (int) $body['galaxy'], 'system' => (int) $body['system'], 'planet' => (int) $body['position']];
        if (Planet::query()->where($at)->exists()) {
            return $home;
        }
        Planet::query()->whereKey($home->getPlanetId())->update($at);

        return app(PlanetServiceFactory::class)->make($home->getPlanetId(), true) ?? $home;
    }

    /**
     * The colony at the dump's coordinate, or the nearest free slot of its system when a seeded home holds it.
     *
     * @param array<string, mixed> $body
     */
    private function colony(PlayerService $player, array $body): PlanetService|null
    {
        $galaxy = (int) $body['galaxy'];
        $system = (int) $body['system'];
        $wanted = (int) $body['position'];
        $positions = range(1, 15);
        usort($positions, static fn (int $a, int $b): int => abs($a - $wanted) <=> abs($b - $wanted));

        foreach ($positions as $position) {
            if (Planet::query()->where('galaxy', $galaxy)->where('system', $system)->where('planet', $position)->exists()) {
                continue;
            }

            return app(PlanetServiceFactory::class)->createAdditionalPlanetForPlayer($player, new Coordinate($galaxy, $system, $position));
        }

        return null;
    }

    /**
     * Levels (research goes to the account), ships and defence by host id, and the store.
     *
     * @param array<string, mixed> $body
     */
    private function fill(PlayerService $player, PlanetService $planet, array $body): void
    {
        foreach ($body['levels'] as $id => $level) {
            $object = $this->object((int) $id);
            if ($object === null) {
                continue;
            }

            if ($object->type === GameObjectType::Research) {
                $player->setResearchLevel($object->machine_name, (int) $level);

                continue;
            }

            $planet->setObjectLevel($object->id, (int) $level, false);
        }

        foreach ($body['units'] as $id => $amount) {
            $object = $this->object((int) $id);
            if ($object !== null) {
                $planet->addUnit($object->machine_name, (int) $amount, false);
            }
        }

        // The stores and rates the new levels give, as a finished build leaves them; and the dump's stock, not the
        // seeded planet's starting stock beside it.
        $planet->updateResourceStorageStats(false);
        $planet->updateResourceProductionStats(false);
        $planet->deductResources($planet->getResources(), false);
        [$metal, $crystal, $deuterium] = array_map(static fn ($value): float => max(0.0, (float) $value), $body['res']);
        $planet->addResources(new Resources($metal, $crystal, $deuterium, 0), false);
        $planet->save();
    }

    private function object(int $id): GameObject|null
    {
        try {
            return ObjectService::getObjectById($id);
        } catch (Throwable) {
            return null;
        }
    }
}
