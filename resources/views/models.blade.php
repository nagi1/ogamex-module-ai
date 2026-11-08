@extends('ingame.layouts.main')

@section('content')
    @if (file_exists(public_path('modules/ai/build/manifest.json')))
        @vite(['resources/css/ai-console.css', 'resources/js/ai-console.js'], 'modules/ai/build')
    @endif
    <style>
        .ai-lab table.defaultTable { width: 100%; }
        .ai-lab td, .ai-lab th { padding: 3px 5px; vertical-align: top; }
        .ai-lab .num { text-align: right; font-family: monospace; white-space: nowrap; }
        .ai-lab .bar { position: relative; height: 10px; min-width: 90px; background: rgba(255,255,255,.06); border-radius: 2px; }
        .ai-lab .bar > span { position: absolute; top: 0; bottom: 0; left: 0; background: #6f9fd8; border-radius: 2px; }
        .ai-lab .bar.z > span { left: 50%; background: #9c9; }
        .ai-lab .bar.z > span.neg { left: auto; right: 50%; background: #d99; }
        .ai-lab .bar.z::after { content: ''; position: absolute; left: 50%; top: -2px; bottom: -2px; border-left: 1px solid rgba(255,255,255,.35); }
        .ai-lab .pass { color: #6c6; } .ai-lab .warn { color: #ec4; } .ai-lab .fail { color: #f55; }
        .ai-lab .pick-model { background: rgba(111,159,216,.18); }
        .ai-lab .tag { display: inline-block; padding: 0 4px; margin-left: 3px; border-radius: 2px; font-size: 10px; background: #345; color: #cde; }
        .ai-lab .tag.teacher { background: #553; color: #ffd; }
        .ai-lab .muted { color: #8a8f98; }
        .ai-lab .cards { display: flex; flex-wrap: wrap; gap: 6px; }
        .ai-lab .card { flex: 1 1 200px; border: 1px solid #2a3340; padding: 6px; background: rgba(0,0,0,.2); }
        .ai-lab .card.off { opacity: .65; }
        .ai-lab .card.on { border-color: #6f9fd8; }
        .ai-lab .lede { margin: 6px 4px; line-height: 1.45; }
        .ai-lab details summary { cursor: pointer; }
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

                        {{-- The models: what each file is, and whether it can run on the game's state. --}}
                        <p class="box_highlight textCenter no_buddies">{{ __('t_ai.lab_models_heading') }}</p>
                        <div class="cards">
                            @foreach ($lab['models'] as $key => $model)
                                <div class="card {{ $key === $lab['selected'] ? 'on' : '' }} {{ $model['runnable'] ? '' : 'off' }}">
                                    <b>{{ $model['label'] }}</b>
                                    @if ($model['deployed'])<span class="tag">{{ __('t_ai.lab_deployed') }}</span>@endif
                                    <div class="muted" style="word-break: break-all;">{{ \Illuminate\Support\Str::after($model['path'], 'runs/') }}</div>
                                    <div class="muted">{{ __('t_ai.lab_trained') }}: {{ $model['trained_at'] ?? '-' }}</div>
                                    @if ($model['shape'] !== [])
                                        <details><summary class="muted">{{ __('t_ai.lab_layers') }}</summary>
                                            @foreach ($model['shape'] as $layer => $shape)
                                                <div class="num" style="text-align:left">{{ $layer }}: {{ $shape }}</div>
                                            @endforeach
                                        </details>
                                    @endif
                                    @if ($model['runnable'])
                                        <div class="pass">{{ __('t_ai.lab_runnable') }}</div>
                                        @if ($key !== $lab['selected'])
                                            <a class="btn_blue" href="{{ route('ai.models', array_filter(['model' => $key, 'player' => $lab['player_id']])) }}">{{ __('t_ai.lab_use') }}</a>
                                        @endif
                                    @else
                                        <div class="fail">{{ $model['why_not'] }}</div>
                                    @endif
                                </div>
                            @endforeach
                        </div>

                        @if ($lab['selected'] === '')
                            <p class="lede fail">{{ __('t_ai.lab_none_runnable') }}</p>
                        @else
                            {{-- Every account's login now, scored by the selected model. --}}
                            <p class="box_highlight textCenter no_buddies">{{ __('t_ai.lab_overview_heading', ['model' => $lab['models'][$lab['selected']]['label']]) }}</p>
                            <p class="lede">
                                {{ __('t_ai.lab_agreement', ['agree' => $lab['agreement']['agree'], 'decided' => $lab['agreement']['decided']]) }}
                                @foreach ($lab['agreement']['picks'] as $kind => $count)
                                    <span class="tag">{{ $kind }} × {{ $count }}</span>
                                @endforeach
                            </p>
                            <table class="defaultTable">
                                <tr>
                                    <th>{{ __('t_ai.lab_account') }}</th>
                                    <th>{{ __('t_ai.lab_live_pilot') }}</th>
                                    <th class="num">{{ __('t_ai.lab_rows') }}</th>
                                    <th>{{ __('t_ai.lab_teacher_pick') }}</th>
                                    <th>{{ __('t_ai.lab_model_pick') }}</th>
                                    <th class="num">{{ __('t_ai.lab_sure') }}</th>
                                    <th class="num">{{ __('t_ai.lab_value') }}</th>
                                    <th>{{ __('t_ai.lab_checks') }}</th>
                                    <th></th>
                                </tr>
                                @foreach ($lab['overview'] as $row)
                                    <tr class="{{ $row['player_id'] === $lab['player_id'] ? 'pick-model' : '' }}">
                                        <td>#{{ $row['player_id'] }} {{ $row['archetype'] }}</td>
                                        @if ($row['error'] !== null)
                                            <td colspan="7" class="fail">{{ $row['error'] }}</td>
                                        @else
                                            <td>
                                                {{ $row['flies'] === 'model' ? __('t_ai.lab_flies_model') : __('t_ai.lab_flies_scripted') }}
                                                @if ($row['skipped'])<div class="muted">{{ $row['skipped'] }}</div>@endif
                                            </td>
                                            <td class="num">{{ $row['rows'] }}</td>
                                            <td>{{ $row['teacher'] }}</td>
                                            <td>{{ $row['model'] }} @if ($row['agrees'])<span class="pass">✓</span>@else<span class="warn">≠</span>@endif</td>
                                            <td class="num">{{ $row['share'] === null ? '-' : number_format($row['share'] * 100, 0) . '%' }}</td>
                                            <td class="num">{{ $row['value'] === null ? '-' : number_format($row['value'], 2) }}</td>
                                            <td class="{{ $row['checks'] }}">{{ $row['checks'] }}</td>
                                        @endif
                                        <td><a class="btn_blue" href="{{ route('ai.models', ['model' => $lab['selected'], 'player' => $row['player_id']]) }}">{{ __('t_ai.lab_open') }}</a></td>
                                    </tr>
                                @endforeach
                            </table>
                            <p class="lede muted">{{ __('t_ai.lab_overview_note') }}</p>
                        @endif

                        @php($d = $lab['detail'])
                        @if ($d !== null && ($d['error'] ?? null) !== null)
                            <p class="lede fail">{{ $d['error'] }}</p>
                        @elseif ($d !== null)
                            <p class="box_highlight textCenter no_buddies" id="detail">
                                {{ __('t_ai.lab_detail_heading', ['player' => $d['profile']->player_id, 'archetype' => $d['profile']->archetype->name]) }}
                            </p>
                            <p class="lede">
                                {{ __('t_ai.lab_detail_engine', ['count' => $d['engine_candidates'], 'selected' => $d['engine_selected']]) }}
                                {{ __('t_ai.lab_detail_rows', ['rows' => count($d['rows'])]) }}
                                <span class="muted">({{ $d['milliseconds'] }} ms)</span>
                                @if ($d['skipped'])<br><span class="warn">{{ $d['skipped'] }}</span>@endif
                                @if ($d['flies'] !== 'model')<br><span class="muted">{{ __('t_ai.lab_detail_scripted') }}</span>@endif
                            </p>

                            {{-- 1. What the model reads about the account. --}}
                            <p class="box_highlight textCenter no_buddies">{{ __('t_ai.lab_state_heading') }}</p>
                            <table class="defaultTable">
                                <tr><th>{{ __('t_ai.lab_feature') }}</th><th class="num">{{ __('t_ai.lab_raw') }}</th><th class="num">z</th><th>{{ __('t_ai.lab_vs_training') }}</th></tr>
                                @foreach ($d['state'] as $column)
                                    <tr>
                                        <td>{{ $column['name'] }} @if ($column['outlier'])<span class="warn">⚠</span>@endif</td>
                                        <td class="num">{{ number_format($column['value'], 3) }}</td>
                                        <td class="num {{ $column['outlier'] ? 'warn' : '' }}">{{ number_format($column['z'], 2) }}</td>
                                        <td><div class="bar z"><span class="{{ $column['z'] < 0 ? 'neg' : '' }}" style="width: {{ min(50, abs($column['z']) / $lab['sigma'] * 50) }}%"></span></div></td>
                                    </tr>
                                @endforeach
                            </table>

                            {{-- 2. The menu: each row the engine offered, the model's score and its share. --}}
                            <p class="box_highlight textCenter no_buddies">{{ __('t_ai.lab_rows_heading') }}</p>
                            <table class="defaultTable">
                                <tr>
                                    <th class="num">#</th><th>{{ __('t_ai.lab_kind') }}</th><th>{{ __('t_ai.lab_engine_action') }}</th>
                                    <th class="num">{{ __('t_ai.lab_engine_score') }}</th><th class="num">{{ __('t_ai.lab_model_score') }}</th>
                                    <th>{{ __('t_ai.lab_share') }}</th>
                                </tr>
                                @foreach ($d['rows'] as $row)
                                    <tr class="{{ $row['model'] ? 'pick-model' : '' }}">
                                        <td class="num">{{ $row['index'] }}</td>
                                        <td>
                                            {{ $row['kind'] }}
                                            @if ($row['model'])<span class="tag">{{ __('t_ai.lab_tag_model') }}</span>@endif
                                            @if ($row['teacher'])<span class="tag teacher">{{ __('t_ai.lab_tag_teacher') }}</span>@endif
                                        </td>
                                        <td>
                                            {{ $row['action'] }} <span class="muted">{{ $row['reason'] }} {{ $row['target'] }}</span>
                                            <details><summary class="muted">{{ __('t_ai.lab_row_features', ['count' => count($row['columns']), 'outliers' => $row['outliers']]) }}</summary>
                                                <table>
                                                    @foreach ($row['columns'] as $column)
                                                        <tr>
                                                            <td>{{ $column['name'] }}</td>
                                                            <td class="num">{{ number_format($column['value'], 3) }}</td>
                                                            <td class="num {{ $column['outlier'] ? 'warn' : 'muted' }}">z {{ number_format($column['z'], 1) }}</td>
                                                        </tr>
                                                    @endforeach
                                                </table>
                                            </details>
                                        </td>
                                        <td class="num">{{ $row['engine_score'] === null ? '-' : number_format($row['engine_score'], 1) }}</td>
                                        <td class="num">{{ $row['score'] === null ? '-' : number_format($row['score'], 3) }}</td>
                                        <td>
                                            <div class="bar"><span style="width: {{ ($row['share'] ?? 0) * 100 }}%"></span></div>
                                            <span class="num muted">{{ $row['share'] === null ? '' : number_format($row['share'] * 100, 1) . '%' }}</span>
                                        </td>
                                    </tr>
                                @endforeach
                            </table>
                            <p class="lede muted">{{ __('t_ai.lab_rows_note') }}</p>

                            {{-- 3. Does it behave: the checks, and every model on the same login. --}}
                            <p class="box_highlight textCenter no_buddies">{{ __('t_ai.lab_checks_heading') }}</p>
                            <table class="defaultTable">
                                @foreach ($d['checks'] as $check)
                                    <tr><td class="{{ $check['status'] }}">{{ $check['status'] }}</td><td>{{ $check['name'] }}</td><td class="muted">{{ $check['detail'] }}</td></tr>
                                @endforeach
                            </table>

                            <p class="box_highlight textCenter no_buddies">{{ __('t_ai.lab_compare_heading') }}</p>
                            <table class="defaultTable">
                                <tr><th>{{ __('t_ai.lab_model') }}</th><th>{{ __('t_ai.lab_model_pick') }}</th><th class="num">{{ __('t_ai.lab_sure') }}</th><th class="num">{{ __('t_ai.lab_value') }}</th></tr>
                                @foreach ($d['compare'] as $other)
                                    <tr class="{{ $other['key'] === $lab['selected'] ? 'pick-model' : '' }}">
                                        <td>{{ $other['label'] }}</td>
                                        <td>{{ $other['pick'] }} @if ($other['agrees'])<span class="pass">✓ {{ __('t_ai.lab_tag_teacher') }}</span>@endif</td>
                                        <td class="num">{{ $other['share'] === null ? '-' : number_format($other['share'] * 100, 0) . '%' }}</td>
                                        <td class="num">{{ $other['value'] === null ? '-' : number_format($other['value'], 2) }}</td>
                                    </tr>
                                @endforeach
                            </table>
                        @endif
                    </div>
                </div>
            </div>
            <div class="footer"></div>
        </div>
    </div>
@endsection
