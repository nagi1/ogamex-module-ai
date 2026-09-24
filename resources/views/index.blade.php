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
                        <a class="btn_blue {{ $tab === 'llm' ? 'active' : '' }}" href="{{ route('ai.index', ['tab' => 'llm']) }}">{{ __('t_ai.tab_llm') }}</a>
                        <a class="btn_blue {{ $tab === 'players' ? 'active' : '' }}" href="{{ route('ai.index', ['tab' => 'players']) }}">{{ __('t_ai.tab_players') }}</a>
                        <a class="btn_blue {{ $tab === 'settings' ? 'active' : '' }}" href="{{ route('ai.index', ['tab' => 'settings']) }}">{{ __('t_ai.tab_settings') }}</a>
                        <a class="btn_blue {{ $tab === 'operations' ? 'active' : '' }}" href="{{ route('ai.index', ['tab' => 'operations']) }}">{{ __('t_ai.tab_operations') }}</a>
                        <a class="btn_blue {{ $tab === 'campaigns' ? 'active' : '' }}" href="{{ route('ai.index', ['tab' => 'campaigns']) }}">{{ __('t_ai.tab_campaigns') }}</a>
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

                    @if ($tab === 'llm')
                        @include('ai::partials.llm', ['llm' => $llm])
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
                                @php($lastGroup = null)
                                @foreach ($settingsPanel['live'] as $setting)
                                    @if ($setting['group'] !== $lastGroup)
                                        @php($lastGroup = $setting['group'])
                                        <p class="textBeefy">{{ __('t_ai.settings_group_' . $setting['group']) }}</p>
                                    @endif
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
