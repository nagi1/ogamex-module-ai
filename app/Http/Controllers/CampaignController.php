<?php

namespace Modules\AI\Http\Controllers;

use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Modules\AI\Actions\SummarizeAiCampaignAction;
use OGame\Http\Controllers\OGameController;

/**
 * The coalition-facing campaign page: a read-only situation log of the cooperative campaign.
 * It reads what the module and the host already record and writes nothing, so viewing the page
 * can never change the campaign.
 */
class CampaignController extends OGameController
{
    public function index(): Factory|View
    {
        $this->setBodyId('overview');

        /** @var view-string $view */
        $view = 'ai::campaign';

        return view($view, [
            'campaign' => app(SummarizeAiCampaignAction::class)->handle(),
        ]);
    }
}
