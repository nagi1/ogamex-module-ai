@php
/** @var Modules\AI\Domain\Operability\AiOperabilityOverview $overview */
/** @var Modules\AI\Domain\Operability\AiLivenessOverview $liveness */
/** @var Modules\AI\Domain\Operability\AiStorageHealthOverview $storage */
/** @var Modules\AI\Domain\Operability\AiProviderVisibilityOverview $providers */
/** @var Modules\AI\Domain\Operability\AiSituationOverview $situation */
/** @var Modules\AI\Domain\Operability\AiAuthenticityOverview $authenticity */
/** @var array<string, mixed> $pilot */
@endphp

<p class="box_highlight textCenter no_buddies">{{ __('t_ai.health_now_heading') }}</p>
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

<p class="box_highlight textCenter no_buddies">{{ __('t_ai.health_budget_heading') }}</p>
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
        @if ($providers->monthlyCeiling > 0 && $providers->monthToDateCost >= $providers->monthlyCeiling)
            <p class="box_highlight">{{ __('t_ai.health_budget_wall_reached') }}</p>
        @endif
    @endif
</div>

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

<p class="box_highlight textCenter no_buddies">{{ __('t_ai.health_running_out_heading') }}</p>

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
    @if ($providers->configured)
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
                    <td>{{ $vendor['avgLatencyMs'] === null ? '–' : $vendor['avgLatencyMs'] . 'ms' }}</td>
                    <td>{{ $vendor['tokens'] }}</td>
                    <td>${{ number_format($vendor['cost'], 6) }}</td>
                </tr>
            @endforeach
        </table>
    @endif
</div>

<p class="box_highlight textCenter no_buddies">{{ __('t_ai.health_last_days_heading') }}</p>
<p>{{ __('t_ai.pilot_note') }}</p>
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
        <input type="hidden" name="tab" value="health">
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
