{{-- One choice in the account table: the same answer once, or both answers when they differ. --}}
@if ($answer['agrees'])
    <span class="same">✓</span> {{ $answer['model'] }}
@else
    <span class="diff">≠</span> {{ __('t_ai.lab_rules_say') }} <b>{{ $answer['planner'] }}</b>, {{ __('t_ai.lab_model_says') }} <b>{{ $answer['model'] }}</b>
@endif
@if (($answer['in_game'] ?? 'model') === 'planner')
    <span class="muted">({{ __('t_ai.lab_rules_fly') }})</span>
@endif
