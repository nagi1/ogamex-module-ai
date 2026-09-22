@extends('ingame.layouts.main')

@section('content')
    @if (file_exists(public_path('modules/ai/build/manifest.json')))
        @vite(['resources/css/ai-console.css', 'resources/js/ai-console.js'], 'modules/ai/build')
    @endif
    <div id="overviewcomponent" class="maincontent">
        <div id="planet" class="shortHeader">
            <h2>{{ $title }}</h2>
        </div>

        <div id="buttonz">
            <div class="header"><h2>{{ $title }}</h2></div>
            <div class="content">
                <div class="buddylistContent">
                    <div class="ai-console">
                    <p class="box_highlight textCenter no_buddies">
                        <a class="btn_blue" href="{{ route('ai.index', ['tab' => 'players']) }}">{{ __('t_ai.back_to_board') }}</a>
                    </p>

                    <p class="box_highlight textCenter no_buddies">{{ __('t_ai.account_heading') }}</p>
                    <div class="group bborder">
                        @include('ai::partials.metric', ['label' => __('t_ai.account_archetype'), 'value' => $profile->archetype->name])
                        @include('ai::partials.metric', ['label' => __('t_ai.account_skill'), 'value' => $profile->skill_band->name])
                        @include('ai::partials.metric', ['label' => __('t_ai.account_state'), 'value' => $profile->enabled ? __('t_ai.account_enabled') : __('t_ai.account_disabled')])
                        @include('ai::partials.metric', ['label' => __('t_ai.board_delta'), 'value' => $delta === null ? __('t_ai.never') : $delta])
                    </div>

                    <p class="box_highlight textCenter no_buddies">{{ __('t_ai.decisions_heading') }}</p>
                    @if ($decisions === [])
                        <p>{{ __('t_ai.no_decisions') }}</p>
                    @endif
                    @if ($decisions !== [])
                        <div class="group bborder">
                            <table class="defaultTable">
                                <tr>
                                    <th>{{ __('t_ai.decision_trace') }}</th>
                                    <th>{{ __('t_ai.decision_chosen') }}</th>
                                </tr>
                                @foreach ($decisions as $decision)
                                    <tr>
                                        <td>{{ $decision->traceId }}</td>
                                        <td>{{ $decision->selectedAction }} ({{ $decision->selectedReason }})</td>
                                    </tr>
                                    <tr>
                                        <td colspan="2">
                                            <details>
                                                <summary>{{ __('t_ai.decision_weights') }}</summary>
                                                @foreach ($decision->components as $name => $value)
                                                    <div>{{ $name }}: {{ number_format($value, 2) }}</div>
                                                @endforeach
                                                @foreach ($decision->alternatives as $alternative)
                                                    <div>{{ $alternative['action'] }}: {{ number_format($alternative['score'], 2) }}</div>
                                                @endforeach
                                            </details>
                                        </td>
                                    </tr>
                                @endforeach
                            </table>
                        </div>
                    @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
