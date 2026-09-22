@php /** @var array<string, mixed> $roster */ @endphp
<p class="box_highlight textCenter no_buddies">{{ __('t_ai.roster_heading') }}</p>

@if ($roster['impersonating'])
    <p class="box_highlight">
        {{ __('t_ai.roster_impersonating', ['username' => $roster['impersonated_username']]) }}
        <a class="btn_blue" href="{{ $roster['impersonate_leave_url'] }}">{{ __('t_ai.roster_leave') }}</a>
    </p>
@endif

<form method="get" action="{{ route('ai.index') }}">
    <input type="hidden" name="tab" value="players">
    <p>
        <label for="roster-search">{{ __('t_ai.roster_search') }}</label>
        <input id="roster-search" type="text" name="search" value="{{ $roster['search'] }}" placeholder="{{ __('t_ai.roster_search_placeholder') }}">
        <label for="roster-state">{{ __('t_ai.roster_state') }}</label>
        <select id="roster-state" name="state">
            <option value="all" @selected($roster['state'] === 'all')>{{ __('t_ai.roster_state_all') }}</option>
            <option value="enabled" @selected($roster['state'] === 'enabled')>{{ __('t_ai.roster_state_enabled') }}</option>
            <option value="stopped" @selected($roster['state'] === 'stopped')>{{ __('t_ai.roster_state_stopped') }}</option>
        </select>
        <label for="roster-alerts"><input id="roster-alerts" type="checkbox" name="alerts" value="1" @checked($roster['alerts_only'])>{{ __('t_ai.roster_alerts_only') }}</label>
        <input type="submit" class="btn_blue" value="{{ __('t_ai.roster_filter') }}">
    </p>
</form>

@if ($roster['rows'] === [])
    <p>{{ __('t_ai.roster_no_results') }}</p>
@endif

@if ($roster['rows'] !== [])
    <div class="group bborder">
        <table class="defaultTable">
            <tr>
                <th>{{ __('t_ai.roster_player') }}</th>
                <th>{{ __('t_ai.roster_how') }}</th>
                <th>{{ __('t_ai.roster_state_col') }}</th>
                <th>{{ __('t_ai.roster_work') }}</th>
                <th>{{ __('t_ai.roster_growth') }}</th>
                <th>{{ __('t_ai.roster_last_action') }}</th>
                <th>{{ __('t_ai.roster_alerts_col') }}</th>
                <th>{{ __('t_ai.roster_controls') }}</th>
            </tr>
            @foreach ($roster['rows'] as $row)
                <tr>
                    <td><a href="{{ route('ai.account', ['player' => $row['player_id']]) }}">{{ $row['username'] }}</a></td>
                    <td>{{ $row['archetype'] }} · {{ $row['skill_band'] }}</td>
                    <td>{{ $row['enabled'] ? __('t_ai.account_enabled') : __('t_ai.account_disabled') }}</td>
                    <td>{{ $row['due_work'] }} / {{ $row['in_flight'] }} / {{ $row['stuck'] }}</td>
                    <td>{{ $row['delta'] === null ? '—' : $row['delta'] }}</td>
                    <td>{{ $row['last_action'] ?? '—' }}{{ $row['last_state'] !== null ? ' (' . $row['last_state'] . ')' : '' }}</td>
                    <td>
                        @foreach ($row['alerts'] as $alert)
                            <div>{{ __('t_ai.alert_' . $alert) }}</div>
                        @endforeach
                    </td>
                    <td>
                        <a class="btn_blue" href="{{ route('ai.account', ['player' => $row['player_id']]) }}">{{ __('t_ai.roster_view') }}</a>
                        <form method="post" action="{{ route('admin.developershortcuts.impersonate') }}" style="display:inline">
                            @csrf
                            <input type="hidden" name="username" value="{{ $row['username'] }}">
                            <input type="submit" class="btn_blue" value="{{ __('t_ai.roster_view_as') }}">
                        </form>
                        <form method="post" action="{{ route('ai.account.switch') }}" style="display:inline">
                            @csrf
                            <input type="hidden" name="player_id" value="{{ $row['player_id'] }}">
                            <input type="hidden" name="enabled" value="{{ $row['enabled'] ? '0' : '1' }}">
                            <input type="hidden" name="reason" value="{{ $row['enabled'] ? __('t_ai.roster_stop_reason') : __('t_ai.roster_resume_reason') }}">
                            <input type="submit" class="btn_blue" value="{{ $row['enabled'] ? __('t_ai.roster_stop') : __('t_ai.roster_resume') }}">
                        </form>
                    </td>
                </tr>
            @endforeach
        </table>
    </div>
@endif
