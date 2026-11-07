<?php

namespace Modules\AI\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\AI\Actions\SeedAiTestUniverseAction;
use Modules\AI\Domain\Persona\AiPersonaFactory;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiStockpileStrategy;
use Modules\AI\Models\AiProfile;
use OGame\Actions\Fortify\CreateNewUser;
use OGame\Models\Planet;
use OGame\Models\User;
use OGame\Services\SettingsService;
use RuntimeException;

/**
 * The play universe for the human sessions (WORK-ORDER, WORLD lane): the administrator (Legor), one human player and twenty
 * AI accounts on three galaxies, 8 miners, 7 raiders and 5 savers, with no inactive neighbours (owner rule: no farms).
 *
 * Every account is registered through the host's own path. The administrator is the host's own Legor, created by a
 * host migration and protected from probes and attacks by name; the human is an ordinary, attackable player.
 * Placement only steers the host's own planet placement (`last_assigned_*`): each account starts at the system its
 * slot names, so every galaxy holds a cluster of neighbours that can reach each other.
 *
 * Run by `scripts/play-universe.sh` on a fresh schema; refuses a universe that already has accounts.
 */
class PlayUniverseSeeder extends Seeder
{
    /**
     * Galaxy => list of [system, persona]. Galaxy 1 holds the human (system 50) with two raiders beside it.
     *
     * @var array<int, list<array{0:int, 1:string}>>
     */
    public const LAYOUT = [
        1 => [[45, 'raider'], [40, 'miner'], [55, 'saver'], [60, 'raider'], [35, 'miner'], [65, 'saver'], [70, 'miner']],
        2 => [[40, 'raider'], [45, 'miner'], [50, 'raider'], [55, 'miner'], [60, 'saver'], [65, 'raider'], [70, 'miner']],
        3 => [[40, 'miner'], [45, 'raider'], [50, 'saver'], [55, 'raider'], [60, 'miner'], [65, 'saver']],
    ];

    public const HUMAN_GALAXY = 1;
    public const HUMAN_SYSTEM = 50;

    /** @var array<string, string> filled by run(): what the operator needs to log in */
    public array $credentials = [];

    /** @var list<array{player_id:int, persona:string, archetype:string, coords:string}> */
    public array $accounts = [];

    public function __construct(private string $humanName = 'Orion', private string $humanEmail = 'orion@play.ogamex.test')
    {
    }

    public function run(): void
    {
        // A fresh schema holds only the host's own administrator, Legor (created by a host migration, protected from
        // probes and attacks by name, skipped by the AI's targeting).
        $admin = User::query()->where('username', 'Legor')->first();
        if ($admin === null || User::query()->count() !== 1) {
            throw new RuntimeException('The play universe is seeded on a fresh schema only (Legor alone).');
        }

        $settings = app(SettingsService::class);

        // 1. The administrator: a known password so the operator reaches the admin AI panel.
        $adminPassword = Str::password(16, symbols: false);
        $admin->forceFill(['password' => Hash::make($adminPassword)])->save();
        $this->credentials['admin'] = $admin->username . ' / ' . $admin->email . ' / ' . $adminPassword;

        // 2. The human player: an ordinary account in the middle of galaxy 1's cluster.
        $this->place($settings, self::HUMAN_GALAXY, self::HUMAN_SYSTEM);
        $humanPassword = Str::password(16, symbols: false);
        $human = app(CreateNewUser::class)->create(['email' => $this->humanEmail, 'password' => $humanPassword]);
        $human->forceFill(['username' => $this->humanName])->save();
        $this->credentials['human'] = $this->humanName . ' / ' . $this->humanEmail . ' / ' . $humanPassword;

        // 3. Twenty AI accounts, one at a time, so each lands on its own slot.
        $action = app(SeedAiTestUniverseAction::class);
        $personas = app(AiPersonaFactory::class);
        $index = 0;
        foreach (self::LAYOUT as $galaxy => $slots) {
            foreach ($slots as [$system, $persona]) {
                $index++;
                $this->place($settings, $galaxy, $system);
                $seeded = $action->handle($index)[$index - 1];
                $this->assignPersona($personas, $seeded['player_id'], $persona);
            }
        }
    }

    private function place(SettingsService $settings, int $galaxy, int $system): void
    {
        $settings->set('last_assigned_galaxy', $galaxy);
        $settings->set('last_assigned_system', $system);
    }

    /**
     * The simulator's three personas on the module's archetypes: a miner is a Miner, a raider a Raider, and a saver a
     * Fleeter that banks by fleet-saving (the simulator's saver keeps a fleet and leaves before an attack lands).
     */
    private function assignPersona(AiPersonaFactory $personas, int $playerId, string $persona): void
    {
        $archetype = match ($persona) {
            'miner' => AiArchetype::Miner,
            'raider' => AiArchetype::Raider,
            'saver' => AiArchetype::Fleeter,
        };

        /** @var AiProfile $profile */
        $profile = AiProfile::query()->where('player_id', $playerId)->firstOrFail();
        $skill = $profile->skill_band;
        $seed = (int) $profile->random_seed;
        $made = $personas->create($archetype, $skill, $seed);

        $settingsBag = is_array($profile->settings) ? $profile->settings : [];
        $settingsBag['play_persona'] = $persona;

        $profile->forceFill([
            'archetype' => $archetype,
            'activity_band' => $made->activityBand,
            'defense_doctrine' => $made->defenseDoctrine,
            'stockpile_strategy' => $persona === 'saver' ? AiStockpileStrategy::FleetSaveBanker : $made->stockpileStrategy,
            'economic_role' => $made->economicRole,
            'settings' => $settingsBag,
        ])->save();

        $user = User::query()->findOrFail($playerId);
        $user->forceFill(['character_class' => $personas->characterClass($archetype, $skill, $seed)->value])->save();

        $planet = Planet::query()->where('user_id', $playerId)->orderBy('id')->first();
        $this->accounts[] = [
            'player_id' => $playerId,
            'persona' => $persona,
            'archetype' => $archetype->name,
            'coords' => $planet === null ? '?' : $planet->galaxy . ':' . $planet->system . ':' . $planet->planet,
        ];
    }
}
