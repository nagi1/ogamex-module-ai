<?php

namespace Modules\AI\Http\Controllers;

use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use OGame\Http\Controllers\OGameController;

class AIController extends OGameController
{
    public function index(): Factory|View
    {
        $this->setBodyId('overview');
        /** @var view-string $view */
        $view = 'ai::index';

        return view($view, [
            'title' => __('t_ai.title'),
            'welcome' => __('t_ai.welcome'),
        ]);
    }
}
