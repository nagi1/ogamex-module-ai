<?php

namespace Modules\AI\Jobs;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;
use InvalidArgumentException;
use Modules\AI\Actions\DeclareAiCampaignObjectiveAction;
use Modules\AI\Actions\OpenAiCampaignAction;
use Modules\AI\Enums\AiCampaignControl;
use Modules\AI\Enums\AiQueueName;
use Modules\AI\Models\AiOperationLog;
use Throwable;

/**
 * Runs one campaign control off the request path and writes the outcome to the audit row the
 * action created. Opening and declaring call their actions with validated inputs; the three
 * no-input controls run their existing commands.
 */
class RunAiCampaignControlJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /**
     * @param array<string, mixed> $params
     */
    public function __construct(
        public readonly string $control,
        public readonly int $logId,
        public readonly array $params = [],
    ) {
        $this->onQueue(AiQueueName::Ai->value);
    }

    /** @return list<string> */
    public function tags(): array
    {
        return ['ai', 'ai:campaign', 'ai:campaign:'.$this->control];
    }

    public function handle(): void
    {
        $this->finish('completed', $this->run(AiCampaignControl::from($this->control)));
    }

    public function failed(Throwable $exception): void
    {
        $this->finish('failed', $exception->getMessage());
    }

    private function run(AiCampaignControl $control): string
    {
        return match ($control) {
            AiCampaignControl::Open => $this->open(),
            AiCampaignControl::Declare => $this->declare(),
            AiCampaignControl::Advance => $this->command('ai:advance-campaigns'),
            AiCampaignControl::ApplyAlliances => $this->command('ai:advance-alliance-life'),
            AiCampaignControl::BondAlliances => $this->command('ai:bond-alliances'),
        };
    }

    private function open(): string
    {
        $starts = CarbonImmutable::parse((string) $this->params['starts_at']);
        $ends = CarbonImmutable::parse((string) $this->params['ends_at']);

        if ($ends->lte($starts)) {
            throw new InvalidArgumentException('A campaign must end after it starts.');
        }

        $campaign = app(OpenAiCampaignAction::class)->handle($starts, $ends);

        return "Opened campaign {$campaign->id}.";
    }

    private function declare(): string
    {
        $objective = app(DeclareAiCampaignObjectiveAction::class)->handle(
            (int) $this->params['campaign_id'],
            (int) $this->params['planet_id'],
        );

        if ($objective === null) {
            throw new InvalidArgumentException('The campaign or planet no longer exists.');
        }

        return "Declared stronghold {$objective->planet_id} on campaign {$objective->campaign_id}.";
    }

    private function command(string $name): string
    {
        Artisan::call($name);
        $output = trim((string) Artisan::output());

        return $output === '' ? "Ran {$name}." : $output;
    }

    private function finish(string $status, string $result): void
    {
        AiOperationLog::query()->whereKey($this->logId)->update([
            'status' => $status,
            'result' => $result,
            'finished_at' => now(),
        ]);
    }
}
