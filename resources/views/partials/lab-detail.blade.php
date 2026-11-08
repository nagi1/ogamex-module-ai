{{-- One account explained; the page includes it on a direct link, the Why? button fetches it on its own. --}}
@if ($d['error'] !== null)
    <p class="lede no">{{ $d['error'] }}</p>
@else
    <p class="box_highlight textCenter no_buddies">{{ __('t_ai.lab_why_heading', ['player' => $d['player_id'], 'archetype' => $d['archetype']]) }}</p>
    @if ($d['fleet'] !== null)
        @include('ai::partials.lab-why', ['title' => __('t_ai.lab_col_fleet'), 'answer' => $d['fleet']])
    @endif
    @foreach ($d['economy'] as $answer)
        @include('ai::partials.lab-why', ['title' => __('t_ai.lab_col_build') . ' · ' . $answer['where'], 'answer' => $answer])
    @endforeach
@endif
