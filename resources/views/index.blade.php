@extends('ingame.layouts.main')

@section('content')
    @if (file_exists(public_path('modules/ai/build/manifest.json')))
        @vite(['resources/css/ai-console.css', 'resources/js/ai-console.js'], 'modules/ai/build')
    @endif
    @if (session('success'))
        <script>fadeBox(@json(session('success')), false);</script>
    @endif
    @if (session('error'))
        <script>fadeBox(@json(session('error')), true);</script>
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
                    {{-- The page splits into the same tabs the host's activity log uses, so one view
                         answers one question and the heavy report never slows the default load. --}}
                    <p class="box_highlight textCenter no_buddies">
                        <a class="btn_blue {{ $tab === 'health' ? 'active' : '' }}" href="{{ route('ai.index', ['tab' => 'health']) }}">{{ __('t_ai.tab_health') }}</a>
                        <a class="btn_blue {{ $tab === 'players' ? 'active' : '' }}" href="{{ route('ai.index', ['tab' => 'players']) }}">{{ __('t_ai.tab_players') }}</a>
                        <a class="btn_blue {{ $tab === 'settings' ? 'active' : '' }}" href="{{ route('ai.index', ['tab' => 'settings']) }}">{{ __('t_ai.tab_settings') }}</a>
                        <a class="btn_blue {{ $tab === 'operations' ? 'active' : '' }}" href="{{ route('ai.index', ['tab' => 'operations']) }}">{{ __('t_ai.tab_operations') }}</a>
                        <a class="btn_blue {{ $tab === 'campaigns' ? 'active' : '' }}" href="{{ route('ai.index', ['tab' => 'campaigns']) }}">{{ __('t_ai.tab_campaigns') }}</a>
                        <a class="btn_blue {{ $tab === 'decisions' ? 'active' : '' }}" href="{{ route('ai.index', ['tab' => 'decisions']) }}">{{ __('t_ai.tab_decisions') }}</a>
                    </p>

                    @if ($tab === 'health')
                        @include('ai::partials.health', [
                            'overview' => $overview,
                            'liveness' => $liveness,
                            'storage' => $storage,
                            'providers' => $providers,
                            'situation' => $situation,
                            'authenticity' => $authenticity,
                            'pilot' => $pilot,
                            'pilotDays' => $pilotDays,
                        ])
                    @endif

                    @if ($tab === 'decisions')
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
                                            <td>{{ $decision->traceId }} · {{ $decision->playerId }}</td>
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

                        <p class="box_highlight textCenter no_buddies">{{ __('t_ai.replay_heading') }}</p>
                        <p>{{ __('t_ai.replay_note') }}</p>
                        @if ($replayError !== null)
                            <p class="box_highlight">{{ __('t_ai.replay_error', ['error' => $replayError]) }}</p>
                        @endif
                        <form method="get" action="{{ route('ai.index') }}">
                            <p>
                                <label for="ai-replay-scenario">{{ __('t_ai.replay_scenario') }}</label>
                                <select id="ai-replay-scenario" name="replay">
                                    @foreach ($scenarios as $scenario)
                                        <option value="{{ $scenario }}" @selected($scenario === $replayName)>{{ $scenario }}</option>
                                    @endforeach
                                </select>
                                <input type="hidden" name="tab" value="decisions">
                                <input type="submit" class="btn_blue" value="{{ __('t_ai.replay_run') }}">
                            </p>
                        </form>
                        @if ($replay !== null)
                            <div class="group bborder">
                                <table class="defaultTable">
                                    <tr>
                                        <td>{{ __('t_ai.replay_scenario') }}</td>
                                        <td>{{ $replay->name }} · {{ $replay->persona }}</td>
                                    </tr>
                                    <tr>
                                        <td>{{ __('t_ai.replay_frozen_at') }}</td>
                                        <td>{{ $replay->observedAt?->toDateTimeString() }}</td>
                                    </tr>
                                    <tr>
                                        <td>{{ __('t_ai.decision_chosen') }}</td>
                                        <td>{{ $replay->selectedAction }} ({{ $replay->selectedReason }}) {{ number_format($replay->selectedScore, 2) }}</td>
                                    </tr>
                                    <tr>
                                        <td>{{ __('t_ai.decision_ranked') }}</td>
                                        <td>
                                            @foreach ($replay->alternatives as $alternative)
                                                <div>{{ $alternative['action'] }}: {{ number_format($alternative['score'], 2) }}</div>
                                            @endforeach
                                        </td>
                                    </tr>
                                </table>
                            </div>
                        @endif
                    @endif

                    @if ($tab === 'players')
                        @include('ai::partials.players-roster', ['roster' => $roster])
                    @endif

                    @if ($tab === 'settings')
                        <p class="box_highlight textCenter no_buddies">{{ __('t_ai.settings_live_heading') }}</p>
                        <p>{{ __('t_ai.settings_live_note') }}</p>
                        <form method="post" action="{{ route('ai.settings') }}">
                            @csrf
                            <div class="group bborder">
                                @foreach ($settingsPanel['live'] as $setting)
                                    @include('ai::partials.setting-control', ['setting' => $setting])
                                @endforeach
                            </div>
                            <p class="textCenter"><input type="submit" class="btn_blue" value="{{ __('t_ai.settings_save') }}"></p>
                        </form>

                        @include('ai::partials.settings-deploy', ['settingsPanel' => $settingsPanel])
                    @endif

                    @if ($tab === 'operations')
                        @include('ai::partials.operations', ['operations' => $operations])
                    @endif

                    @if ($tab === 'campaigns')
                        @include('ai::partials.campaigns', ['campaigns' => $campaigns])
                    @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
