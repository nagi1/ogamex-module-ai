@php /** @var array<string, mixed> $campaigns */ @endphp
<p class="box_highlight textCenter no_buddies">{{ __('t_ai.campaigns_heading') }}</p>

@if ($campaigns['campaign'] === null)
    <p>{{ __('t_ai.campaign_none') }}</p>
@else
    <div class="group bborder">
        <p class="textBeefy">{{ __('t_ai.campaign_state_heading') }}</p>
        <p>
            {{ __('t_ai.campaign_state_' . strtolower($campaigns['campaign']['state']->name)) }}
            @if ($campaigns['campaign']['startsAt'] !== null && $campaigns['campaign']['endsAt'] !== null)
                <span class="lime">{{ $campaigns['campaign']['startsAt']->toDateTimeString() }} to {{ $campaigns['campaign']['endsAt']->toDateTimeString() }}</span>
            @endif
        </p>
        <p>{{ $campaigns['campaign']['completed'] }} / {{ $campaigns['campaign']['total'] }} {{ __('t_ai.objective_progress_label') }}</p>
        <p>{{ __('t_ai.faction_momentum', ['momentum' => $campaigns['campaign']['factionMomentum'], 'total' => $campaigns['campaign']['total']]) }}</p>
        <table class="defaultTable">
            <tr>
                <th>{{ __('t_ai.stronghold') }}</th>
                <th>{{ __('t_ai.status') }}</th>
            </tr>
            @foreach ($campaigns['campaign']['strongholds'] as $stronghold)
                <tr>
                    <td>{{ $stronghold['coordinates'] }}</td>
                    <td>{{ $stronghold['completed'] ? __('t_ai.stronghold_completed') : __('t_ai.stronghold_standing') }}</td>
                </tr>
            @endforeach
        </table>
        <table class="defaultTable">
            <tr>
                <th>{{ __('t_ai.side') }}</th>
                <th>{{ __('t_ai.military_lost') }}</th>
            </tr>
            <tr>
                <td>{{ __('t_ai.coalition') }}</td>
                <td>{{ number_format($campaigns['campaign']['coalitionLosses']) }}</td>
            </tr>
            <tr>
                <td>{{ __('t_ai.faction') }}</td>
                <td>{{ number_format($campaigns['campaign']['factionLosses']) }}</td>
            </tr>
        </table>
    </div>
@endif

<p class="box_highlight textCenter no_buddies">{{ __('t_ai.campaigns_controls_heading') }}</p>

<div class="group bborder">
    <form method="post" action="{{ route('ai.campaigns') }}">
        @csrf
        <input type="hidden" name="control" value="campaign:open">
        <p class="textBeefy">{{ __('t_ai.campaign_control_open') }}</p>
        <p>
            <label for="campaign-starts">{{ __('t_ai.campaign_starts') }}</label>
            <input id="campaign-starts" type="datetime-local" name="starts_at" required>
            <label for="campaign-ends">{{ __('t_ai.campaign_ends') }}</label>
            <input id="campaign-ends" type="datetime-local" name="ends_at" required>
            <input type="submit" class="btn_blue" value="{{ __('t_ai.operation_run') }}">
        </p>
    </form>
</div>

<div class="group bborder">
    <form method="post" action="{{ route('ai.campaigns') }}">
        @csrf
        <input type="hidden" name="control" value="campaign:declare">
        <p class="textBeefy">{{ __('t_ai.campaign_control_declare') }}</p>
        <p>
            <label for="declare-campaign">{{ __('t_ai.campaign_select') }}</label>
            <select id="declare-campaign" name="campaign_id" required>
                @foreach ($campaigns['campaigns'] as $campaign)
                    <option value="{{ $campaign['id'] }}">{{ $campaign['id'] }} · {{ __('t_ai.campaign_state_' . $campaign['state']) }}</option>
                @endforeach
            </select>
            <label for="declare-planet">{{ __('t_ai.campaign_planet') }}</label>
            <input id="declare-planet" type="number" name="planet_id" min="1" required>
            <input type="submit" class="btn_blue" value="{{ __('t_ai.operation_run') }}">
        </p>
    </form>
</div>

@foreach (['campaign:advance', 'campaign:apply-alliances', 'campaign:bond-alliances'] as $control)
    <div class="group bborder">
        <form method="post" action="{{ route('ai.campaigns') }}">
            @csrf
            <input type="hidden" name="control" value="{{ $control }}">
            <p class="textBeefy">{{ __('t_ai.campaign_control_' . str_replace(['campaign:', '-'], ['', '_'], $control)) }}</p>
            <p class="textCenter"><input type="submit" class="btn_blue" value="{{ __('t_ai.operation_run') }}"></p>
        </form>
    </div>
@endforeach

@if ($campaigns['recent'] !== [])
    <p class="box_highlight textCenter no_buddies">{{ __('t_ai.campaigns_runs_heading') }}</p>
    <div class="group bborder">
        <table class="defaultTable">
            <tr>
                <th>{{ __('t_ai.operation_column') }}</th>
                <th>{{ __('t_ai.operations_column_status') }}</th>
                <th>{{ __('t_ai.operations_column_result') }}</th>
            </tr>
            @foreach ($campaigns['recent'] as $run)
                <tr>
                    <td>{{ __('t_ai.campaign_control_' . str_replace(['campaign:', '-'], ['', '_'], $run['operation'])) }}</td>
                    <td>{{ __('t_ai.operation_status_' . $run['status']) }}</td>
                    <td>{{ $run['result'] }}</td>
                </tr>
            @endforeach
        </table>
    </div>
@endif
