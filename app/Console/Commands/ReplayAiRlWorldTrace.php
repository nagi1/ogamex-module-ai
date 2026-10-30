<?php

namespace Modules\AI\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\AI\Actions\ReplayAiWorldAction;
use Modules\AI\Console\Commands\Concerns\OpensRlUniverse;

/**
 * W14 (plan/rl/WORLD-SIM-PLAN.md): plays a whole simulated world's trace (`cargo run --example trace`) on the same host
 * universe through the host's own queues, fleets and clock, and writes both sides next to each other. The world runs in
 * one transaction that is rolled back.
 *
 * DEV TOOLING: it opens only the universe file it is given and leaves it unchanged.
 */
#[Description('Replay an ogsim world trace on the host and report the differences (plan/rl W14).')]
#[Signature('ai:rl-world-replay
    {universe : The ai:rl-universe SQLite file the catalogue was exported from}
    {trace : JSON written by the ogsim trace example}
    {out : JSON file for the side-by-side result}')]
class ReplayAiRlWorldTrace extends Command
{
    use OpensRlUniverse;

    public function handle(): int
    {
        $this->openRlUniverse((string) $this->argument('universe'));
        $trace = json_decode((string) file_get_contents((string) $this->argument('trace')), true, 512, JSON_THROW_ON_ERROR);

        DB::beginTransaction();
        $planets = app(ReplayAiWorldAction::class)->handle($trace['planets'], (int) $trace['planets'][0]['final']['t'], $trace['yard_blocked_by'] ?? []);
        DB::rollBack();

        file_put_contents((string) $this->argument('out'), json_encode(['planets' => $planets], JSON_THROW_ON_ERROR));
        $this->info(sprintf('RL-WORLD-REPLAY: %d planet(s) -> %s', count($planets), $this->argument('out')));

        return self::SUCCESS;
    }
}
