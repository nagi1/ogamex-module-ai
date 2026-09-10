<?php

namespace Modules\AI\Http\Controllers;

use Illuminate\View\View;
use OGame\Http\Controllers\OGameController;

class AIController extends OGameController
{
    public function index(): View
    {
        $this->setBodyId('overview');

        return view('ai::index', [
            'title' => __('t_ai.title'),
            'welcome' => __('t_ai.welcome'),
        ]);
    }
}
