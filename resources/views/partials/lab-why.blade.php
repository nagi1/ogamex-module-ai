{{-- One decision explained: what the model looked at, what it weighed, and whether it behaved. --}}
<div class="box">
    <h4>{{ $title }}</h4>
    <p>
        {{ __('t_ai.lab_rules_say') }} <b>{{ $answer['planner'] }}</b>. {{ __('t_ai.lab_model_says') }} <b>{{ $answer['model'] }}</b>.
        @if ($answer['agrees'])<span class="same">{{ __('t_ai.lab_they_agree') }}</span>@else<span class="diff">{{ __('t_ai.lab_they_differ') }}</span>@endif
    </p>
    @if (!empty($answer['skipped']))<p class="no">{{ $answer['skipped'] }}</p>@endif
    @if (($answer['in_game'] ?? 'model') === 'planner')<p class="muted">{{ __('t_ai.lab_rules_fly_long') }}</p>@endif

    <p><b>{{ __('t_ai.lab_saw') }}</b></p>
    <ul>
        @foreach ($answer['facts'] as $fact)<li>{{ $fact }}</li>@endforeach
    </ul>

    <p><b>{{ __('t_ai.lab_weighed') }}</b></p>
    @foreach ($answer['options'] as $option)
        <div class="opt">
            <span class="name">
                {{ $option['label'] }}
                @if ($option['model'])<span class="tag">{{ __('t_ai.lab_tag_model') }}</span>@endif
                @if ($option['planner'])<span class="tag rules">{{ __('t_ai.lab_tag_rules') }}</span>@endif
            </span>
            <span class="bar"><span style="width: {{ round($option['share'] * 100, 1) }}%"></span></span>
            <span class="pct">{{ number_format($option['share'] * 100, 0) }}%</span>
        </div>
    @endforeach

    @if (isset($answer['alone']))
        <p class="muted">{{ __('t_ai.lab_alone', ['what' => $answer['alone']]) }}</p>
    @endif

    <p><b>{{ __('t_ai.lab_behaves') }}</b></p>
    <ul>
        @foreach ($answer['checks'] as $check)
            <li class="{{ $check['ok'] ? 'ok' : 'no' }}">{{ $check['ok'] ? '✓' : '⚠' }} {{ $check['text'] }}</li>
        @endforeach
    </ul>
</div>
