@php /** @var array<string, mixed> $setting */ @endphp
<div class="fieldwrapper">
    <label class="styled textBeefy" for="ai-setting-{{ $setting['field'] }}">{{ __('t_ai.setting_' . $setting['field']) }}</label>
    <div class="thefield">
        @if ($setting['type'] === 'bool')
            <input type="hidden" name="{{ $setting['field'] }}" value="0">
            <input type="checkbox" id="ai-setting-{{ $setting['field'] }}" name="{{ $setting['field'] }}" value="1" data-default="{{ $setting['default'] ? '1' : '0' }}" @checked($setting['value'])>
        @elseif ($setting['type'] === 'select')
            <select id="ai-setting-{{ $setting['field'] }}" name="{{ $setting['field'] }}" data-default="{{ $setting['default'] }}">
                @foreach ($setting['options'] as $option)
                    <option value="{{ $option }}" @selected($setting['value'] === $option)>{{ $option }}</option>
                @endforeach
            </select>
            <span class="undermark">{{ __('t_ai.default_label', ['default' => $setting['default']]) }}</span>
        @else
            <input class="textInput w50 textCenter" type="number" id="ai-setting-{{ $setting['field'] }}" name="{{ $setting['field'] }}" value="{{ $setting['value'] }}" data-default="{{ $setting['default'] }}" min="0" step="{{ $setting['type'] === 'float' ? '0.01' : '1' }}">
            <span class="undermark">{{ __('t_ai.default_label', ['default' => $setting['default']]) }}</span>
        @endif
        <button type="button" class="btn_blue" data-reset-for="ai-setting-{{ $setting['field'] }}">{{ __('t_ai.setting_reset') }}</button>
    </div>
    <div class="definition">
        @include('ai::partials.definition-card', ['definition' => $setting['definition']])
    </div>
</div>
