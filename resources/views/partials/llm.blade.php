@php /** @var array<string, mixed> $llm */ @endphp

<p class="box_highlight textCenter no_buddies">{{ __('t_ai.llm_budget_heading') }}</p>
<div class="group bborder">
    @include('ai::partials.metric', [
        'label' => __('t_ai.llm_spent'),
        'value' => '$' . number_format($llm['spent'], 2) . ' of $' . number_format($llm['ceiling'], 2),
    ])
</div>

<form method="post" action="{{ route('ai.llm') }}">
    @csrf
    <div class="group bborder">
        <p>
            <label class="styled textBeefy" for="ai-llm-cost">{{ __('t_ai.llm_monthly_ceiling') }}</label>
            <input class="textInput w50 textCenter" type="number" id="ai-llm-cost" name="monthly_cost_usd" value="{{ $llm['budget']['monthly_cost_usd'] }}" min="0" step="0.01">
        </p>
        <p>
            <label class="styled textBeefy">{{ __('t_ai.llm_language_enabled') }}</label>
            <input type="checkbox" name="language_enabled" value="1" @checked($llm['budget']['language_enabled'])>
        </p>
        <p>
            <label class="styled textBeefy">{{ __('t_ai.llm_language_ai_to_ai') }}</label>
            <input type="checkbox" name="language_ai_to_ai" value="1" @checked($llm['budget']['language_ai_to_ai'])>
        </p>
        <p>
            <label class="styled textBeefy" for="ai-llm-campaign">{{ __('t_ai.llm_campaign_mode') }}</label>
            <select id="ai-llm-campaign" name="campaign_mode">
                @foreach (['off', 'observe', 'advice'] as $mode)
                    <option value="{{ $mode }}" @selected($llm['budget']['campaign_mode'] === $mode)>{{ $mode }}</option>
                @endforeach
            </select>
        </p>
        <p class="textCenter"><input type="submit" class="btn_blue" value="{{ __('t_ai.settings_save') }}"></p>
    </div>
</form>

<p class="box_highlight textCenter no_buddies">{{ __('t_ai.llm_providers_heading') }}</p>
<div class="group bborder">
    @if (!$llm['configured'])
        <p>{{ __('t_ai.provider_unconfigured') }}</p>
    @endif
    @if ($llm['configured'])
        <table class="defaultTable">
            <tr>
                <th>{{ __('t_ai.provider_vendor') }}</th>
                <th>{{ __('t_ai.provider_attempts') }}</th>
                <th>{{ __('t_ai.provider_tokens') }}</th>
                <th>{{ __('t_ai.provider_cost') }}</th>
            </tr>
            @foreach ($llm['providers'] as $vendor)
                <tr>
                    <td>{{ $vendor['provider'] }}</td>
                    <td>{{ $vendor['attempts'] }}</td>
                    <td>{{ $vendor['tokens'] }}</td>
                    <td>${{ number_format($vendor['cost'], 6) }}</td>
                </tr>
            @endforeach
        </table>
    @endif
</div>

<p class="box_highlight textCenter no_buddies">{{ __('t_ai.llm_limits_heading') }}</p>
<div class="group bborder">
    <p>{{ __('t_ai.llm_limits_note') }}</p>

    <p class="textBeefy">{{ __('t_ai.llm_model_heading') }}</p>
    @include('ai::partials.metric', ['label' => __('t_ai.llm_provider'), 'value' => $llm['model']['provider']])
    @include('ai::partials.metric', ['label' => __('t_ai.llm_model'), 'value' => $llm['model']['model']])
    @include('ai::partials.metric', ['label' => __('t_ai.llm_timeout'), 'value' => $llm['model']['timeout_seconds'] . 's'])
    @include('ai::partials.metric', ['label' => __('t_ai.llm_context_characters'), 'value' => $llm['model']['context_characters']])
    @include('ai::partials.metric', ['label' => __('t_ai.llm_max_reply_characters'), 'value' => $llm['model']['maximum_reply_characters']])
    @include('ai::partials.metric', ['label' => __('t_ai.llm_max_input_tokens'), 'value' => $llm['model']['maximum_input_tokens']])
    @include('ai::partials.metric', ['label' => __('t_ai.llm_max_output_tokens'), 'value' => $llm['model']['maximum_output_tokens']])

    <p class="textBeefy">{{ __('t_ai.llm_daily_heading') }}</p>

    <p>{{ __('t_ai.llm_daily_language') }}</p>
    <table class="defaultTable">
        <tr>
            <th></th>
            <th>{{ __('t_ai.llm_daily_attempts') }}</th>
            <th>{{ __('t_ai.llm_daily_input') }}</th>
            <th>{{ __('t_ai.llm_daily_output') }}</th>
        </tr>
        @foreach (['universe' => 'llm_scope_universe', 'player' => 'llm_scope_player', 'conversation' => 'llm_scope_conversation'] as $scope => $label)
            <tr>
                <td>{{ __('t_ai.' . $label) }}</td>
                <td>{{ $llm['daily']['language'][$scope]['attempts'] }}</td>
                <td>{{ $llm['daily']['language'][$scope]['input_tokens'] }}</td>
                <td>{{ $llm['daily']['language'][$scope]['output_tokens'] }}</td>
            </tr>
        @endforeach
    </table>

    <p>{{ __('t_ai.llm_daily_campaign') }}</p>
    <table class="defaultTable">
        <tr>
            <th></th>
            <th>{{ __('t_ai.llm_daily_attempts') }}</th>
            <th>{{ __('t_ai.llm_daily_input') }}</th>
            <th>{{ __('t_ai.llm_daily_output') }}</th>
        </tr>
        @foreach (['universe' => 'llm_scope_universe', 'campaign' => 'llm_scope_campaign', 'account' => 'llm_scope_account'] as $scope => $label)
            <tr>
                <td>{{ __('t_ai.' . $label) }}</td>
                <td>{{ $llm['daily']['campaign'][$scope]['attempts'] }}</td>
                <td>{{ $llm['daily']['campaign'][$scope]['input_tokens'] }}</td>
                <td>{{ $llm['daily']['campaign'][$scope]['output_tokens'] }}</td>
            </tr>
        @endforeach
    </table>
</div>
