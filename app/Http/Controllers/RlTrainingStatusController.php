<?php

namespace Modules\AI\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;

/**
 * The learned-economy-policy run on one page: generation, training, closed loop, gates and the machine.
 *
 * DEV TOOLING like the harness page next to it: it renders `storage/rl/status.json`, which
 * `rl/scripts/rl_status.py` rewrites every few seconds from the run's own logs. It reads one file,
 * writes nothing and is unreachable outside a local environment.
 */
class RlTrainingStatusController
{
    /** A status file older than this means the collector is not running, not that the run is quiet. */
    private const STALE_SECONDS = 30;

    public function index(): View
    {
        abort_unless(app()->isLocal(), 404);

        return view('ai::rl');
    }

    public function poll(): JsonResponse
    {
        abort_unless(app()->isLocal(), 404);

        $file = storage_path('rl/status.json');
        $status = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (!is_array($status)) {
            return response()->json(['missing' => true]);
        }

        return response()->json($status + ['age' => max(0, time() - (int) ($status['at'] ?? 0)), 'stale_after' => self::STALE_SECONDS]);
    }
}
