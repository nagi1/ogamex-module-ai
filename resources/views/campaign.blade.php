@extends('ingame.layouts.main')

@section('content')
    <div id="overviewcomponent" class="maincontent">
        <div id="planet" class="shortHeader">
            <h2>{{ __('t_ai.campaign_heading') }}</h2>
        </div>

        @if ($campaign === null)
            <div id="buttonz">
                <div class="content">
                    <p>{{ __('t_ai.campaign_none') }}</p>
                </div>
            </div>
        @else
            <div id="buttonz">
                <div class="header"><h2>{{ __('t_ai.campaign_state_heading') }}</h2></div>
                <div class="content">
                    <p>
                        {{ __('t_ai.campaign_state_' . strtolower($campaign['state']->name)) }}
                        @if ($campaign['startsAt'] !== null && $campaign['endsAt'] !== null)
                            <span class="lime">{{ $campaign['startsAt']->toDateTimeString() }} — {{ $campaign['endsAt']->toDateTimeString() }}</span>
                        @endif
                    </p>
                </div>

                <div class="header"><h2>{{ __('t_ai.objective_progress') }}</h2></div>
                <div class="content">
                    <p>
                        {{ $campaign['completed'] }} / {{ $campaign['total'] }}
                        {{ __('t_ai.objective_progress_label') }}
                    </p>
                    <p>
                        {{ __('t_ai.faction_momentum', ['momentum' => $campaign['factionMomentum'], 'total' => $campaign['total']]) }}
                    </p>
                    <table class="defaultTable">
                        <tr>
                            <th>{{ __('t_ai.stronghold') }}</th>
                            <th>{{ __('t_ai.status') }}</th>
                        </tr>
                        @foreach ($campaign['strongholds'] as $stronghold)
                            <tr>
                                <td>{{ $stronghold['coordinates'] }}</td>
                                <td>{{ $stronghold['completed'] ? __('t_ai.stronghold_completed') : __('t_ai.stronghold_standing') }}</td>
                            </tr>
                        @endforeach
                    </table>
                </div>

                <div class="header"><h2>{{ __('t_ai.losses_heading') }}</h2></div>
                <div class="content">
                    <table class="defaultTable">
                        <tr>
                            <th>{{ __('t_ai.side') }}</th>
                            <th>{{ __('t_ai.military_lost') }}</th>
                        </tr>
                        <tr>
                            <td>{{ __('t_ai.coalition') }}</td>
                            <td>{{ number_format($campaign['coalitionLosses']) }}</td>
                        </tr>
                        <tr>
                            <td>{{ __('t_ai.faction') }}</td>
                            <td>{{ number_format($campaign['factionLosses']) }}</td>
                        </tr>
                    </table>
                </div>
            </div>
        @endif
    </div>
@endsection
