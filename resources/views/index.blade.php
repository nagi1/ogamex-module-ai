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
                    @include('ai::partials.console-tabs', ['tab' => $tab])

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
