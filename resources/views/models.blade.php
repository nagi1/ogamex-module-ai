@extends('ingame.layouts.main')

@section('content')
    @if (file_exists(public_path('modules/ai/build/manifest.json')))
        @vite(['resources/css/ai-console.css', 'resources/js/ai-console.js'], 'modules/ai/build')
    @endif
    <style>
        .ai-lab { line-height: 1.5; }
        .ai-lab .lede { margin: 8px 6px; font-size: 12px; }
        .ai-lab .cards { display: flex; flex-wrap: wrap; gap: 8px; margin: 6px 0 10px; }
        .ai-lab .card { flex: 1 1 260px; border: 1px solid #2a3340; padding: 10px; background: rgba(0,0,0,.25); }
        .ai-lab .card h3 { margin: 0 0 4px; font-size: 13px; color: #fff; }
        .ai-lab .big { font-size: 20px; color: #fff; }
        .ai-lab .ok { color: #6c6; } .ai-lab .no { color: #ec4; } .ai-lab .off { color: #8a8f98; }
        .ai-lab .muted { color: #8a8f98; font-size: 11px; }
        .ai-lab table.defaultTable { width: 100%; }
        .ai-lab td, .ai-lab th { padding: 4px 6px; vertical-align: top; text-align: left; }
        .ai-lab tr.open { background: rgba(111,159,216,.15); }
        .ai-lab .same { color: #6c6; }
        .ai-lab .diff { color: #ec4; font-weight: bold; }
        .ai-lab .opt { display: flex; align-items: center; gap: 8px; margin: 2px 0; }
        .ai-lab .opt .name { flex: 0 0 210px; }
        .ai-lab .opt .bar { flex: 1; height: 10px; background: rgba(255,255,255,.07); border-radius: 3px; position: relative; max-width: 260px; }
        .ai-lab .opt .bar span { position: absolute; left: 0; top: 0; bottom: 0; background: #6f9fd8; border-radius: 3px; }
        .ai-lab .opt .pct { flex: 0 0 44px; text-align: right; font-family: monospace; }
        .ai-lab .tag { display: inline-block; font-size: 10px; padding: 0 5px; border-radius: 3px; margin-left: 4px; background: #345; color: #cde; }
        .ai-lab .tag.rules { background: #553; color: #ffd; }
        .ai-lab .box { border: 1px solid #2a3340; padding: 10px; margin: 8px 0; background: rgba(0,0,0,.2); }
        .ai-lab .box h4 { margin: 0 0 6px; color: #fff; font-size: 12px; }
        .ai-lab ul { margin: 4px 0 4px 18px; padding: 0; }
    </style>

    <div id="overviewcomponent" class="maincontent">
        <div id="planet" class="shortHeader"><h2>{{ $title }}</h2></div>
        <div id="buttonz">
            <div class="header"><h2>{{ $title }}</h2></div>
            <div class="content">
                <div class="buddylistContent">
                    <div class="ai-console ai-lab">
                        @include('ai::partials.console-tabs', ['tab' => $tab])

                        <p class="lede">{{ __('t_ai.lab_intro') }}</p>

                        {{-- The two models, one sentence each. --}}
                        <div class="cards">
                            @foreach (['fleet', 'economy'] as $kind)
                                @php($card = $lab[$kind])
                                <div class="card">
                                    <h3>{{ __('t_ai.lab_card_' . $kind) }}</h3>
                                    <div class="muted">{{ __('t_ai.lab_card_' . $kind . '_what') }}</div>
                                    @if (!$card['loaded'])
                                        <p class="off">{{ __('t_ai.lab_card_missing') }}</p>
                                    @else
                                        <p>
                                            <span class="{{ $card['in_game'] ? 'ok' : 'off' }}">● {{ $card['in_game'] ? __('t_ai.lab_card_on') : __('t_ai.lab_card_off') }}</span>
                                            @if ($kind === 'fleet' && $card['in_game'])<span class="muted">{{ __('t_ai.lab_card_fleet_who') }}</span>@endif
                                        </p>
                                        <p><span class="big">{{ $card['agree'] }} / {{ $card['asked'] }}</span> {{ __('t_ai.lab_card_agree') }}</p>
                                    @endif
                                    <div class="muted">{{ __('t_ai.lab_card_file') }}: {{ $card['file'] }}</div>
                                </div>
                            @endforeach
                        </div>

                        {{-- Every account, one row. --}}
                        <table class="defaultTable">
                            <tr>
                                <th>{{ __('t_ai.lab_col_account') }}</th>
                                <th>{{ __('t_ai.lab_col_fleet') }}</th>
                                <th>{{ __('t_ai.lab_col_build') }}</th>
                                <th></th>
                            </tr>
                            @foreach ($lab['accounts'] as $account)
                                <tr class="{{ $lab['detail'] !== null && $lab['detail']['player_id'] === $account['player_id'] ? 'open' : '' }}">
                                    <td>#{{ $account['player_id'] }} {{ $account['archetype'] }}</td>
                                    @if ($account['error'] !== null)
                                        <td colspan="2" class="no">{{ $account['error'] }}</td>
                                    @else
                                        <td>
                                            @if ($account['fleet'] !== null)
                                                @include('ai::partials.lab-answer', ['answer' => $account['fleet']])
                                            @endif
                                        </td>
                                        <td>
                                            @forelse ($account['economy'] as $answer)
                                                <div><span class="muted">{{ $answer['where'] }}:</span> @include('ai::partials.lab-answer', ['answer' => $answer])</div>
                                            @empty
                                                <span class="muted">{{ __('t_ai.lab_queues_busy') }}</span>
                                            @endforelse
                                        </td>
                                    @endif
                                    <td><a class="btn_blue" href="{{ route('ai.models', ['player' => $account['player_id']]) }}#why">{{ __('t_ai.lab_why') }}</a></td>
                                </tr>
                            @endforeach
                        </table>
                        <p class="lede muted">{{ __('t_ai.lab_legend') }}</p>

                        {{-- One account, in plain words. --}}
                        @php($d = $lab['detail'])
                        @if ($d !== null && $d['error'] === null)
                            <p class="box_highlight textCenter no_buddies" id="why">{{ __('t_ai.lab_why_heading', ['player' => $d['player_id'], 'archetype' => $d['archetype']]) }}</p>

                            @if ($d['fleet'] !== null)
                                @include('ai::partials.lab-why', ['title' => __('t_ai.lab_col_fleet'), 'answer' => $d['fleet']])
                            @endif
                            @foreach ($d['economy'] as $answer)
                                @include('ai::partials.lab-why', ['title' => __('t_ai.lab_col_build') . ' · ' . $answer['where'], 'answer' => $answer])
                            @endforeach
                        @endif
                    </div>
                </div>
            </div>
            <div class="footer"></div>
        </div>
    </div>
@endsection
