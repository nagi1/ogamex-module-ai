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
            <div class="header"><h2>{{ __('t_ai.switch_heading') }}</h2></div>
            <div class="content">
                <p class="box_highlight textCenter no_buddies">{{ $welcome }}</p>
                <p>
                    {{ $overview->workEnabled ? __('t_ai.switch_running') : __('t_ai.switch_stopped') }}
                    @if ($overview->switchedAt !== null)
                        {{ __('t_ai.switch_last_change', [
                            'actor' => $overview->switchedByPlayerId ?? __('t_ai.unknown_actor'),
                            'at' => $overview->switchedAt,
                        ]) }}
                    @endif
                </p>
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

            <div class="header"><h2>{{ __('t_ai.limits_heading') }}</h2></div>
            <div class="content">
                <div class="group bborder">
                    <table class="defaultTable">
                        <tr>
                            <th>{{ __('t_ai.limit') }}</th>
                            <th>{{ __('t_ai.value') }}</th>
                        </tr>
                        @foreach ($overview->limits as $name => $value)
                            <tr>
                                <td>{{ __('t_ai.limit_' . $name) }}</td>
                                <td>{{ $value }}</td>
                            </tr>
                        @endforeach
                    </table>
                </div>
            </div>

            <div class="header"><h2>{{ __('t_ai.population_heading') }}</h2></div>
            <div class="content">
                <div class="group bborder">
                    <table class="defaultTable">
                        @foreach ($overview->population as $name => $value)
                            <tr>
                                <td>{{ __('t_ai.count_' . $name) }}</td>
                                <td>{{ $value }}</td>
                            </tr>
                        @endforeach
                        @foreach ($overview->language as $name => $value)
                            <tr>
                                <td>{{ __('t_ai.count_' . $name) }}</td>
                                <td>{{ $value }}</td>
                            </tr>
                        @endforeach
                    </table>
                </div>
            </div>

            <div class="header"><h2>{{ __('t_ai.actions_heading') }}</h2></div>
            <div class="content">
                <div class="group bborder">
                    <table class="defaultTable">
                        <tr>
                            <th>{{ __('t_ai.action_state') }}</th>
                            <th>{{ __('t_ai.value') }}</th>
                        </tr>
                        @foreach ($overview->actions as $state => $value)
                            <tr>
                                <td>{{ __('t_ai.action_state_' . $state) }}</td>
                                <td>{{ $value }}</td>
                            </tr>
                        @endforeach
                    </table>
                </div>
            </div>

            <div class="header"><h2>{{ __('t_ai.stop_reasons_heading') }}</h2></div>
            <div class="content">
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
            </div>
        </div>
    </div>
@endsection

