@php /** @var array<string, mixed> $settingsPanel */ @endphp
<p class="box_highlight textCenter no_buddies">{{ __('t_ai.settings_deploy_heading') }}</p>
<p>{{ __('t_ai.settings_deploy_note') }}</p>

<div class="group bborder">
    <table class="defaultTable">
        <tr>
            <th>{{ __('t_ai.deploy_service') }}</th>
            <th>{{ __('t_ai.deploy_what') }}</th>
            <th>{{ __('t_ai.deploy_selected_by') }}</th>
            <th>{{ __('t_ai.deploy_status') }}</th>
        </tr>
        @foreach ($settingsPanel['services'] as $service)
            <tr>
                <td>{{ $service['service'] }}</td>
                <td>{{ $service['job'] }}</td>
                <td>{{ $service['needed'] ? $service['selects'] : '—' }}</td>
                <td>{{ $service['needed'] ? __('t_ai.deploy_up') : __('t_ai.deploy_not_needed') }}</td>
            </tr>
        @endforeach
    </table>
</div>

<p class="box_highlight textCenter no_buddies">{{ __('t_ai.deploy_apply_heading') }}</p>
<p>{{ __('t_ai.deploy_apply_note') }}</p>

<div class="group bborder">
    <p class="textBeefy">{{ __('t_ai.deploy_step_file') }}</p>
    <pre style="white-space: pre-wrap;">{{ $settingsPanel['deployment'] }}</pre>
</div>

@if ($settingsPanel['up'] !== null)
    <div class="group bborder">
        <p class="textBeefy">{{ __('t_ai.deploy_step_up') }}</p>
        <pre style="white-space: pre-wrap;">{{ $settingsPanel['up'] }}</pre>
    </div>
@endif

@if ($settingsPanel['down'] !== null)
    <div class="group bborder">
        <p class="textBeefy">{{ __('t_ai.deploy_step_down') }}</p>
        <pre style="white-space: pre-wrap;">{{ $settingsPanel['down'] }}</pre>
    </div>
@endif

<p>{{ __('t_ai.deploy_finish_note') }}</p>
