<?php

namespace Modules\AI\Domain\Login;

use Modules\AI\Enums\GamePhase;
use OGame\Services\PlayerService;
use OGame\Services\SettingsService;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * The account's game phase, from host reads only (architecture step 4, RV-011): one answer every manager,
 * the doctrine and the raid ladder read instead of restating a milestone of their own.
 *
 * The class names no object and holds no threshold. `resources/behavior/phase.yaml` names what marks each
 * phase — the planets the account has settled beyond the ones the universe registers for it, and the
 * researched technology whose level means it has outgrown its mines — so a modded universe moves the
 * phases without an edit here.
 */
class GamePhaseMachine
{
    /** Module root relative, so the phase's reads are loaded by name. */
    private const POLICY = '/resources/behavior/phase.yaml';

    /** @var array<string, mixed>|null */
    private static ?array $policy = null;

    /** How far the account has come: the opening settled nothing of its own, mid has, late outgrew its mines. */
    public function of(PlayerService $player): GamePhase
    {
        if ($this->outgrownTheMines($player)) {
            return GamePhase::Late;
        }

        $settled = $player->planets->planetCount() - $this->registrationPlanets();

        return $settled >= $this->int('mid.settled_planets', 1) ? GamePhase::Mid : GamePhase::Early;
    }

    /**
     * RV-011's target-class rung: which opponents the account may hit, which the source ties to the
     * planets it owns — the opening is a single planet, and a second one lets it answer a neighbour who
     * is at the keyboard. A universe that hands a new account more than one planet at registration
     * reaches that rung with its own first colony, which is why the rung counts owned planets and the
     * account's phase counts only the ones the account settled itself.
     */
    public function targetRung(PlayerService $player): GamePhase
    {
        if ($this->outgrownTheMines($player)) {
            return GamePhase::Late;
        }

        return $player->planets->planetCount() >= $this->rungPlanets() ? GamePhase::Mid : GamePhase::Early;
    }

    /**
     * The planets RV-011's rung asks an account to own: what the account's own goal aims at, read from
     * the same key as the rung itself so the goal and the rung cannot drift apart.
     */
    public function rungPlanets(): int
    {
        return $this->int('mid.owned_planets', 2);
    }

    /**
     * Whether the account has researched the late marker at its level. A universe whose data names no
     * technology, or names one the host does not have, keeps every account below late rather than
     * failing the read.
     */
    private function outgrownTheMines(PlayerService $player): bool
    {
        $technology = (string) ($this->policy()['late']['technology'] ?? '');
        if ($technology === '') {
            return false;
        }

        try {
            return $player->getResearchLevel($technology) >= $this->int('late.level', PHP_INT_MAX);
        } catch (Throwable) {
            return false;
        }
    }

    /** The planets the host hands every new account, which the account did not settle itself. */
    private function registrationPlanets(): int
    {
        return app(SettingsService::class)->registrationPlanetAmount();
    }

    private function int(string $path, int $fallback): int
    {
        $value = $this->policy();

        foreach (explode('.', $path) as $segment) {
            $value = is_array($value) ? ($value[$segment] ?? null) : null;
        }

        return is_numeric($value) ? (int) $value : $fallback;
    }

    /** @return array<string, mixed> */
    private function policy(): array
    {
        if (self::$policy !== null) {
            return self::$policy;
        }

        $path = dirname(__DIR__, 3) . self::POLICY;
        $parsed = is_file($path) ? Yaml::parseFile($path) : [];

        return self::$policy = is_array($parsed) ? $parsed : [];
    }
}
