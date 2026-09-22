<?php

namespace Modules\AI\Actions;

use Modules\AI\Enums\AiOperation;
use Modules\AI\Models\AiOperationLog;

/**
 * The Operations tab: the fixed set of operations and the latest runs. One action, one answer —
 * the view renders this and queries nothing.
 */
class BuildAiOperationsPanelAction
{
    private const RECENT_RUNS = 10;

    /**
     * @return array{operations: list<AiOperation>, runs: list<array<string, mixed>>}
     */
    public function handle(): array
    {
        return [
            'operations' => AiOperation::cases(),
            'runs' => $this->runs(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function runs(): array
    {
        return AiOperationLog::query()
            ->latest('id')
            ->limit(self::RECENT_RUNS)
            ->get(['operation', 'status', 'result', 'created_at'])
            ->map(fn (AiOperationLog $log): array => [
                'operation' => $log->operation,
                'status' => $log->status,
                'result' => $log->result,
                'started_at' => $log->created_at?->toDateTimeString(),
            ])
            ->all();
    }
}
