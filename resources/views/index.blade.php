@extends('ingame.layouts.main')

@section('content')
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
                    {{-- The page splits into the same tabs the host's activity log uses, so one view
                         answers one question and the heavy report never slows the default load. --}}
                    <p class="box_highlight textCenter no_buddies">
                        <a class="btn_blue {{ $tab === 'overview' ? 'active' : '' }}" href="{{ route('ai.index', ['tab' => 'overview']) }}">{{ __('t_ai.tab_overview') }}</a>
                        <a class="btn_blue {{ $tab === 'pilot' ? 'active' : '' }}" href="{{ route('ai.index', ['tab' => 'pilot']) }}">{{ __('t_ai.tab_pilot') }}</a>
                        <a class="btn_blue {{ $tab === 'decisions' ? 'active' : '' }}" href="{{ route('ai.index', ['tab' => 'decisions']) }}">{{ __('t_ai.tab_decisions') }}</a>
                        <a class="btn_blue {{ $tab === 'monitoring' ? 'active' : '' }}" href="{{ route('ai.index', ['tab' => 'monitoring']) }}">{{ __('t_ai.tab_monitoring') }}</a>
                        <a class="btn_blue {{ $tab === 'accounts' ? 'active' : '' }}" href="{{ route('ai.index', ['tab' => 'accounts']) }}">{{ __('t_ai.tab_accounts') }}</a>
                        <a class="btn_blue {{ $tab === 'settings' ? 'active' : '' }}" href="{{ route('ai.index', ['tab' => 'settings']) }}">{{ __('t_ai.tab_settings') }}</a>
                        <a class="btn_blue {{ $tab === 'operations' ? 'active' : '' }}" href="{{ route('ai.index', ['tab' => 'operations']) }}">{{ __('t_ai.tab_operations') }}</a>
                    </p>

                    @if ($tab === 'overview')
                        <p class="box_highlight textCenter no_buddies">
                            {{ $overview->workEnabled ? __('t_ai.switch_running') : __('t_ai.switch_stopped') }}
                        </p>
                        <div class="group bborder">
                            @include('ai::partials.metric', ['label' => __('t_ai.count_profiles'), 'value' => $overview->population['profiles']])
                            @include('ai::partials.metric', ['label' => __('t_ai.count_sessions_in_flight'), 'value' => $overview->population['sessions_in_flight']])
                            @include('ai::partials.metric', ['label' => __('t_ai.count_due_work'), 'value' => $overview->population['due_work']])
                        </div>

                        <p class="box_highlight textCenter no_buddies">{{ __('t_ai.switch_heading') }}</p>
                        <div class="group bborder">
                            @if ($overview->switchedAt !== null)
                                <p>{{ __('t_ai.switch_last_change', [
                                    'actor' => $overview->switchedByPlayerId ?? __('t_ai.unknown_actor'),
                                    'at' => $overview->switchedAt,
                                ]) }}</p>
                            @endif
                            @if ($overview->switchReason !== null)
                                <p>{{ __('t_ai.switch_reason', ['reason' => $overview->switchReason]) }}</p>
                            @endif
                            <form method="post" action="{{ route('ai.switch') }}">
                                @csrf
                                <input type="hidden" name="enabled" value="{{ $overview->workEnabled ? '0' : '1' }}">
                                <p>
                                    <label for="ai-switch-reason">{{ __('t_ai.switch_reason_label') }}</label>
                                    <input id="ai-switch-reason" type="text" name="reason" maxlength="255" required>
                                    <input type="submit" class="btn_blue"
                                           value="{{ $overview->workEnabled ? __('t_ai.switch_stop') : __('t_ai.switch_resume') }}">
                                </p>
                            </form>
                        </div>

                        <p class="box_highlight textCenter no_buddies">{{ __('t_ai.limits_heading') }}</p>
                        <div class="group bborder">
                            @foreach ($overview->limits as $name => $value)
                                @include('ai::partials.metric', ['label' => __('t_ai.limit_' . $name), 'value' => $value])
                            @endforeach
                        </div>

                        <p class="box_highlight textCenter no_buddies">{{ __('t_ai.population_heading') }}</p>
                        <div class="group bborder">
                            @include('ai::partials.metric', ['label' => __('t_ai.count_attempts'), 'value' => $overview->language['attempts']])
                            @include('ai::partials.metric', ['label' => __('t_ai.count_in_flight'), 'value' => $overview->language['in_flight']])
                            @include('ai::partials.metric', ['label' => __('t_ai.count_reserved_tokens'), 'value' => $overview->language['reserved_tokens']])
                            @include('ai::partials.metric', ['label' => __('t_ai.count_actual_tokens'), 'value' => $overview->language['actual_tokens']])
                            @include('ai::partials.metric', ['label' => __('t_ai.count_cached_input_tokens'), 'value' => $overview->language['cached_input_tokens']])
                            @include('ai::partials.metric', ['label' => __('t_ai.count_cache_hit_rate'), 'value' => number_format($overview->language['cache_hit_rate'] * 100, 1) . '%'])
                            @include('ai::partials.metric', ['label' => __('t_ai.count_cost'), 'value' => '$' . number_format($overview->language['cost'], 6)])
                        </div>

                        <p class="box_highlight textCenter no_buddies">{{ __('t_ai.actions_heading') }}</p>
                        <div class="group bborder">
                            @if ($overview->actions === [])
                                <p>{{ __('t_ai.no_actions_today') }}</p>
                            @endif
                            @foreach ($overview->actions as $state => $value)
                                @include('ai::partials.metric', ['label' => __('t_ai.action_state_' . $state), 'value' => $value])
                            @endforeach
                        </div>

                        <p class="box_highlight textCenter no_buddies">{{ __('t_ai.stop_reasons_heading') }}</p>
                        @if ($overview->stopReasons === [])
                            <p>{{ __('t_ai.no_stop_reasons') }}</p>
                        @endif
                        @if ($overview->stopReasons !== [])
                            <div class="group bborder">
                                <table class="defaultTable">
                                    <tr>
                                        <th>{{ __('t_ai.stop_reason') }}</th>
                                        <th>{{ __('t_ai.occurrences') }}</th>
                                        <th>{{ __('t_ai.context') }}</th>
                                    </tr>
                                    @foreach ($overview->stopReasons as $entry)
                                        <tr>
                                            <td>{{ __('t_ai.stop_' . $entry['reason']) }}</td>
                                            <td>{{ $entry['occurrences'] }}</td>
                                            <td>{{ json_encode($entry['context']) }}</td>
                                        </tr>
                                    @endforeach
                                </table>
                            </div>
                        @endif
                    @endif

                    @if ($tab === 'pilot')
                        <p>{{ __('t_ai.pilot_note') }}</p>
                        {{-- A GET, like the replay below it: reading a window changes nothing. --}}
                        <form method="get" action="{{ route('ai.index') }}">
                            <p>
                                <label for="ai-pilot-days">{{ __('t_ai.pilot_days') }}</label>
                                <select id="ai-pilot-days" name="days">
                                    @foreach ([1, 7, 30] as $option)
                                        <option value="{{ $option }}" @selected($option === $pilotDays)>
                                            {{ trans_choice('t_ai.pilot_window_days', $option, ['count' => $option]) }}
                                        </option>
                                    @endforeach
                                </select>
                                <input type="hidden" name="tab" value="pilot">
                                <input type="submit" class="btn_blue" value="{{ __('t_ai.pilot_run') }}">
                            </p>
                        </form>

                        <p class="box_highlight textCenter no_buddies">{{ __('t_ai.pilot_work_heading') }}</p>
                        <div class="group bborder">
                            @include('ai::partials.metric', ['label' => __('t_ai.pilot_profiles'), 'value' => $pilot['profiles']])
                            @foreach ($pilot['work'] as $state => $value)
                                @include('ai::partials.metric', ['label' => __('t_ai.pilot_work_' . $state), 'value' => $value])
                            @endforeach
                            @include('ai::partials.metric', [
                                'label' => __('t_ai.pilot_lateness'),
                                'value' => __('t_ai.pilot_lateness_figures', [
                                    'p50' => number_format($pilot['lateness']['p50'], 1),
                                    'p95' => number_format($pilot['lateness']['p95'], 1),
                                    'sessions' => $pilot['lateness']['sessions'],
                                ]),
                            ])
                        </div>

                        <p class="box_highlight textCenter no_buddies">{{ __('t_ai.pilot_provider_heading') }}</p>
                        <div class="group bborder">
                            @include('ai::partials.metric', ['label' => __('t_ai.pilot_language_attempts'), 'value' => $pilot['language']['attempts']])
                            @include('ai::partials.metric', ['label' => __('t_ai.pilot_language_tokens'), 'value' => $pilot['language']['tokens']])
                            @include('ai::partials.metric', ['label' => __('t_ai.pilot_language_cached_input_tokens'), 'value' => $pilot['language']['cached_input_tokens']])
                            @include('ai::partials.metric', ['label' => __('t_ai.pilot_language_cache_hit_rate'), 'value' => number_format($pilot['language']['cache_hit_rate'] * 100, 1) . '%'])
                            @include('ai::partials.metric', ['label' => __('t_ai.pilot_language_cost'), 'value' => '$' . number_format($pilot['language']['cost'], 6)])
                        </div>

                        <p class="box_highlight textCenter no_buddies">{{ __('t_ai.pilot_growth_heading') }}</p>
                        <div class="group bborder">
                            @if ($pilot['score']['enabled'] === false)
                                <p>{{ __('t_ai.pilot_growth_disabled') }}</p>
                            @endif
                            @if ($pilot['score']['enabled'] !== false)
                                @include('ai::partials.metric', ['label' => __('t_ai.pilot_score_accounts'), 'value' => $pilot['score']['accounts']])
                                @include('ai::partials.metric', ['label' => __('t_ai.pilot_score_samples'), 'value' => $pilot['score']['samples']])
                                @include('ai::partials.metric', [
                                    'label' => __('t_ai.pilot_score_delta'),
                                    'value' => __('t_ai.pilot_score_delta_figures', [
                                        'min' => $pilot['score']['general_delta']['min'],
                                        'median' => $pilot['score']['general_delta']['median'],
                                        'max' => $pilot['score']['general_delta']['max'],
                                    ]),
                                ])
                                @include('ai::partials.metric', ['label' => __('t_ai.pilot_score_largest_jump'), 'value' => $pilot['score']['largest_hourly_jump']])
                                @include('ai::partials.metric', ['label' => __('t_ai.pilot_score_zero_growth'), 'value' => $pilot['score']['zero_growth_accounts']])
                                @include('ai::partials.metric', ['label' => __('t_ai.pilot_score_military_lost'), 'value' => $pilot['score']['military_lost']])
                            @endif
                        </div>

                        <p class="box_highlight textCenter no_buddies">{{ __('t_ai.actions_heading') }}</p>
                        <div class="group bborder">
                            @if ($pilot['actions'] === [])
                                <p>{{ __('t_ai.pilot_no_actions') }}</p>
                            @endif
                            @foreach ($pilot['actions'] as $state => $value)
                                @include('ai::partials.metric', ['label' => __('t_ai.action_state_' . $state), 'value' => $value])
                            @endforeach
                        </div>

                        <div class="group bborder">
                            @include('ai::partials.metric', [
                                'label' => __('t_ai.pilot_read_cost'),
                                'value' => __('t_ai.pilot_read_cost_figures', [
                                    'milliseconds' => $pilot['read_cost']['milliseconds'],
                                    'queries' => $pilot['read_cost']['queries'],
                                ]),
                            ])
                        </div>
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

                    @if ($tab === 'monitoring')
                        <p class="box_highlight textCenter no_buddies">{{ __('t_ai.situation_heading') }}</p>
                        <div class="group bborder">
                            @foreach ($situation->questions as $question)
                                @include('ai::partials.metric', [
                                    'label' => __('t_ai.situation_q', ['question' => __($question['question'])]),
                                    'value' => $question['figure'],
                                    'sub' => __('t_ai.evidence_' . $question['evidence']),
                                ])
                            @endforeach
                        </div>

                        <p class="box_highlight textCenter no_buddies">{{ __('t_ai.account_switch_heading') }}</p>
                        <div class="group bborder">
                            <form method="post" action="{{ route('ai.account.switch') }}">
                                @csrf
                                <p>
                                    <label for="ai-account-player">{{ __('t_ai.account_switch_profile') }}</label>
                                    <select id="ai-account-player" name="player_id" required>
                                        @foreach ($profiles as $profile)
                                            <option value="{{ $profile->player_id }}">{{ $profile->player_id }} · {{ $profile->archetype->name }} · {{ $profile->enabled ? __('t_ai.account_enabled') : __('t_ai.account_disabled') }}</option>
                                        @endforeach
                                    </select>
                                </p>
                                <p>
                                    <label for="ai-account-enabled">{{ __('t_ai.account_switch_action') }}</label>
                                    <select id="ai-account-enabled" name="enabled" required>
                                        <option value="0">{{ __('t_ai.account_stop') }}</option>
                                        <option value="1">{{ __('t_ai.account_resume') }}</option>
                                    </select>
                                </p>
                                <p>
                                    <label for="ai-account-reason">{{ __('t_ai.switch_reason_label') }}</label>
                                    <input id="ai-account-reason" type="text" name="reason" maxlength="255" required>
                                    <input type="submit" class="btn_blue" value="{{ __('t_ai.account_switch_submit') }}">
                                </p>
                            </form>
                        </div>

                        <p class="box_highlight textCenter no_buddies">{{ __('t_ai.liveness_heading') }}</p>
                        <div class="group bborder">
                            @include('ai::partials.metric', ['label' => __('t_ai.liveness_last_activity'), 'value' => $liveness->lastActivityAt ?? __('t_ai.never')])
                            @include('ai::partials.metric', ['label' => __('t_ai.liveness_overdue'), 'value' => $liveness->overdueAccounts])
                            @include('ai::partials.metric', ['label' => __('t_ai.count_due_work'), 'value' => $liveness->dueWork])
                            @include('ai::partials.metric', ['label' => __('t_ai.count_sessions_in_flight'), 'value' => $liveness->sessionsInFlight])
                        </div>

                        <p class="box_highlight textCenter no_buddies">{{ __('t_ai.storage_heading') }}</p>
                        <div class="group bborder">
                            <table class="defaultTable">
                                <tr>
                                    <th>{{ __('t_ai.storage_table') }}</th>
                                    <th>{{ __('t_ai.storage_rows') }}</th>
                                    <th>{{ __('t_ai.storage_retention') }}</th>
                                    <th>{{ __('t_ai.storage_oldest') }}</th>
                                </tr>
                                @foreach ($storage->tables as $row)
                                    <tr>
                                        <td>{{ $row['model'] }}</td>
                                        <td>{{ $row['count'] }}</td>
                                        <td>{{ $row['retentionDays'] }}d</td>
                                        <td>{{ $row['oldestAgeDays'] === null ? __('t_ai.never') : $row['oldestAgeDays'] . 'd' }}</td>
                                    </tr>
                                @endforeach
                            </table>
                        </div>

                        <p class="box_highlight textCenter no_buddies">{{ __('t_ai.provider_heading') }}</p>
                        <div class="group bborder">
                            @if (!$providers->configured)
                                <p>{{ __('t_ai.provider_unconfigured') }}</p>
                            @endif
                            @if ($providers->configured)
                                <p class="box_highlight textCenter no_buddies">
                                    {{ __('t_ai.budget_spend', [
                                        'spent' => '$' . number_format($providers->monthToDateCost, 2),
                                        'ceiling' => '$' . number_format($providers->monthlyCeiling, 2),
                                    ]) }}
                                </p>
                                <table class="defaultTable">
                                    <tr>
                                        <th>{{ __('t_ai.provider_vendor') }}</th>
                                        <th>{{ __('t_ai.provider_attempts') }}</th>
                                        <th>{{ __('t_ai.provider_latency') }}</th>
                                        <th>{{ __('t_ai.provider_tokens') }}</th>
                                        <th>{{ __('t_ai.provider_cost') }}</th>
                                    </tr>
                                    @foreach ($providers->vendors as $vendor)
                                        <tr>
                                            <td>{{ $vendor['provider'] }}</td>
                                            <td>{{ $vendor['attempts'] }}</td>
                                            <td>{{ $vendor['avgLatencyMs'] === null ? '—' : $vendor['avgLatencyMs'] . 'ms' }}</td>
                                            <td>{{ $vendor['tokens'] }}</td>
                                            <td>${{ number_format($vendor['cost'], 6) }}</td>
                                        </tr>
                                    @endforeach
                                </table>
                            @endif
                        </div>
                    @endif

                    @if ($tab === 'accounts')
                        <p class="box_highlight textCenter no_buddies">{{ __('t_ai.authenticity_heading') }}</p>
                        <div class="group bborder">
                            @include('ai::partials.metric', ['label' => __('t_ai.authenticity_reactions_in_window'), 'value' => $authenticity->reactionsInsideWindow])
                            @include('ai::partials.metric', ['label' => __('t_ai.authenticity_reactions_outside'), 'value' => $authenticity->reactionsOutsideWindow])
                            @include('ai::partials.metric', ['label' => __('t_ai.authenticity_growth_median'), 'value' => $authenticity->growth['median_delta']])
                            @include('ai::partials.metric', ['label' => __('t_ai.authenticity_growth_zero'), 'value' => $authenticity->growth['zero_growth_accounts']])
                            @include('ai::partials.metric', ['label' => __('t_ai.authenticity_distinct_reasons'), 'value' => $authenticity->distinctReasons])
                            @include('ai::partials.metric', ['label' => __('t_ai.authenticity_distinct_contacts'), 'value' => $authenticity->distinctContacts])
                            @foreach ($authenticity->saveOutcomes as $state => $value)
                                @include('ai::partials.metric', ['label' => __('t_ai.action_state_' . $state), 'value' => $value])
                            @endforeach
                        </div>

                        <p class="box_highlight textCenter no_buddies">{{ __('t_ai.board_heading') }}</p>
                        <div class="group bborder">
                            <table class="defaultTable">
                                <tr>
                                    <th>{{ __('t_ai.board_account') }}</th>
                                    <th>{{ __('t_ai.board_delta') }}</th>
                                    <th>{{ __('t_ai.board_work') }}</th>
                                    <th>{{ __('t_ai.board_last_action') }}</th>
                                    <th>{{ __('t_ai.board_alerts') }}</th>
                                </tr>
                                @foreach ($board->rows as $row)
                                    <tr>
                                        <td><a href="{{ route('ai.account', ['player' => $row['player_id']]) }}">{{ $row['player_id'] }} · {{ $row['archetype'] }}</a></td>
                                        <td>{{ $row['delta'] === null ? '—' : $row['delta'] }}</td>
                                        <td>{{ $row['due_work'] }} / {{ $row['in_flight'] }} / {{ $row['stuck'] }}</td>
                                        <td>{{ $row['last_action'] ?? '—' }}{{ $row['last_state'] !== null ? ' (' . $row['last_state'] . ')' : '' }}</td>
                                        <td>{{ implode(', ', $row['alerts']) }}</td>
                                    </tr>
                                @endforeach
                            </table>
                        </div>
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
                </div>
            </div>
        </div>
    </div>
@endsection

