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
                <td>{{ $service['needed'] ? $service['selects'] : '–' }}</td>
                <td>{{ $service['needed'] ? __('t_ai.deploy_up') : __('t_ai.deploy_not_needed') }}</td>
            </tr>
        @endforeach
    </table>
</div>

<p class="box_highlight textCenter no_buddies">{{ __('t_ai.deploy_apply_heading') }}</p>
<p>{{ __('t_ai.deploy_apply_note') }}</p>

<div class="group bborder">
    <p class="textBeefy">{{ __('t_ai.deploy_step_file') }}</p>
    <pre id="deploy-file" style="white-space: pre-wrap;">{{ $settingsPanel['deployment'] }}</pre>
    <button type="button" class="btn_blue" data-copy-target="deploy-file">{{ __('t_ai.deploy_copy') }}</button>
</div>

@if ($settingsPanel['up'] !== null)
    <div class="group bborder">
        <p class="textBeefy">{{ __('t_ai.deploy_step_up') }}</p>
        <pre id="deploy-up" style="white-space: pre-wrap;">{{ $settingsPanel['up'] }}</pre>
        <button type="button" class="btn_blue" data-copy-target="deploy-up">{{ __('t_ai.deploy_copy') }}</button>
    </div>
@endif

@if ($settingsPanel['down'] !== null)
    <div class="group bborder">
        <p class="textBeefy">{{ __('t_ai.deploy_step_down') }}</p>
        <pre id="deploy-down" style="white-space: pre-wrap;">{{ $settingsPanel['down'] }}</pre>
        <button type="button" class="btn_blue" data-copy-target="deploy-down">{{ __('t_ai.deploy_copy') }}</button>
    </div>
@endif

<p>{{ __('t_ai.deploy_finish_note') }}</p>
