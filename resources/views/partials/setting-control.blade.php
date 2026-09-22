@php /** @var array<string, mixed> $setting */ @endphp
<div class="fieldwrapper">
    <label class="styled textBeefy" for="ai-setting-{{ $setting['field'] }}">{{ __('t_ai.setting_' . $setting['field']) }}</label>
    <div class="thefield">
        @if ($setting['type'] === 'bool')
            <input type="hidden" name="{{ $setting['field'] }}" value="0">
            <input type="checkbox" id="ai-setting-{{ $setting['field'] }}" name="{{ $setting['field'] }}" value="1" @checked($setting['value'])>
        @elseif ($setting['type'] === 'select')
            <select id="ai-setting-{{ $setting['field'] }}" name="{{ $setting['field'] }}">
                @foreach ($setting['options'] as $option)
                    <option value="{{ $option }}" @selected($setting['value'] === $option)>{{ $option }}</option>
                @endforeach
            </select>
            <span class="undermark">{{ __('t_ai.default_label', ['default' => $setting['default']]) }}</span>
        @else
            <input class="textInput w50 textCenter" type="number" id="ai-setting-{{ $setting['field'] }}" name="{{ $setting['field'] }}" value="{{ $setting['value'] }}" min="0" step="{{ $setting['type'] === 'float' ? '0.01' : '1' }}">
            <span class="undermark">{{ __('t_ai.default_label', ['default' => $setting['default']]) }}</span>
        @endif
    </div>
    <div class="definition">
        @include('ai::partials.definition-card', ['definition' => $setting['definition']])
    </div>
</div>
