<?php

namespace Modules\AI\Domain\Choice;

use Modules\AI\Contracts\ChoicePolicy;
use Modules\AI\Models\AiProfile;

/**
 * Asks a policy server (plan/rl, `rl/serve.py`) over a Unix socket: one JSON line out, one back. Any
 * failure, timeout or illegal answer falls back to the teacher, so a stopped server degrades to today's
 * play instead of stopping the account.
 */
class SocketChoicePolicy implements ChoicePolicy
{
    /** @var resource|null one connection per process */
    private static $connection = null;

    private static int $fallbacks = 0;

    public function choose(ChoicePoint $point, AiProfile $profile): int
    {
        $answer = $this->ask([
            'key' => $point->key,
            'player' => $point->playerId,
            'research' => $point->research,
            'state' => $point->state,
            'cands' => array_map(static fn (ChoiceCandidate $candidate): array => $candidate->features, $point->candidates),
            'legal' => array_map(static fn (ChoiceCandidate $candidate): bool => $candidate->legal, $point->candidates),
        ]);

        $index = is_array($answer) && isset($answer['index']) && is_int($answer['index']) ? $answer['index'] : null;
        if ($index === null || !($point->candidates[$index] ?? null)?->legal) {
            self::$fallbacks++;

            return $point->teacherIndex;
        }

        return $index;
    }

    public function name(): string
    {
        return 'socket';
    }

    public static function fallbacks(): int
    {
        return self::$fallbacks;
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>|null
     */
    private function ask(array $request): ?array
    {
        $connection = $this->connection();
        if ($connection === null) {
            return null;
        }

        if (@fwrite($connection, json_encode($request, JSON_THROW_ON_ERROR) . "\n") === false) {
            self::$connection = null;

            return null;
        }

        $line = fgets($connection);
        if ($line === false) {
            self::$connection = null;

            return null;
        }

        $decoded = json_decode($line, true);

        return is_array($decoded) ? $decoded : null;
    }

    /** @return resource|null */
    private function connection()
    {
        if (self::$connection !== null) {
            return self::$connection;
        }

        $path = config('ai.rl.socket');
        if (!is_string($path) || $path === '') {
            return null;
        }

        $connection = @stream_socket_client('unix://' . $path, $errno, $error, 1.0);
        if ($connection === false) {
            return null;
        }

        $timeout = max(1, (int) config('ai.rl.socket_timeout_ms', 2000));
        stream_set_timeout($connection, intdiv($timeout, 1000), ($timeout % 1000) * 1000);

        return self::$connection = $connection;
    }
}
