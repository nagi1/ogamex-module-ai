<?php

namespace Modules\AI\Actions;

use Modules\AI\Contracts\RunAiSession;
use Modules\AI\Domain\Scheduling\SessionDecisionService;
use Modules\AI\Models\AiProfile;
use Modules\AI\Models\AiWorkItem;

class RunAiSessionAction implements RunAiSession
{
    public function __construct(private SessionDecisionService $sessionDecisionService)
    {
    }

    public function handle(AiProfile $profile, AiWorkItem $workItem): void
    {
        $this->sessionDecisionService->run($profile, $workItem);
    }
}
