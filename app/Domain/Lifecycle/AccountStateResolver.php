<?php

namespace Modules\AI\Domain\Lifecycle;

use Modules\AI\Enums\AiAccountState;
use OGame\Factories\PlayerServiceFactory;
use OGame\Models\User;

/**
 * Names what an account currently is, from the host's own facts.
 *
 * The module owns profiles; the host owns accounts, and a profile can outlive
 * the account it describes. The host refuses to load an account it does not
 * have, so the state is resolved in one place and asked for rather than
 * discovered as an exception or a null halfway through a session.
 */
class AccountStateResolver
{
    public function __construct(private PlayerServiceFactory $playerServiceFactory)
    {
    }

    public function resolve(int $playerId): AiAccountState
    {
        if (!User::query()->whereKey($playerId)->exists()) {
            return AiAccountState::Final;
        }

        $player = $this->playerServiceFactory->make($playerId, true);

        // The host is the authority on whether the account may play at all, and
        // it says so with two flags rather than with the absence of planets.
        if ($player->isBanned() || $player->isInVacationMode()) {
            return AiAccountState::Suspended;
        }

        return $player->planets->all() === [] ? AiAccountState::Empty : AiAccountState::Active;
    }
}
