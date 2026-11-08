{{-- The console's tabs; the model lab appears only when config ai.model_lab.enabled is on. --}}
<p class="box_highlight textCenter no_buddies">
    <a class="btn_blue {{ $tab === 'health' ? 'active' : '' }}" href="{{ route('ai.index', ['tab' => 'health']) }}">{{ __('t_ai.tab_health') }}</a>
    <a class="btn_blue {{ $tab === 'llm' ? 'active' : '' }}" href="{{ route('ai.index', ['tab' => 'llm']) }}">{{ __('t_ai.tab_llm') }}</a>
    <a class="btn_blue {{ $tab === 'players' ? 'active' : '' }}" href="{{ route('ai.index', ['tab' => 'players']) }}">{{ __('t_ai.tab_players') }}</a>
    <a class="btn_blue {{ $tab === 'settings' ? 'active' : '' }}" href="{{ route('ai.index', ['tab' => 'settings']) }}">{{ __('t_ai.tab_settings') }}</a>
    <a class="btn_blue {{ $tab === 'operations' ? 'active' : '' }}" href="{{ route('ai.index', ['tab' => 'operations']) }}">{{ __('t_ai.tab_operations') }}</a>
    <a class="btn_blue {{ $tab === 'campaigns' ? 'active' : '' }}" href="{{ route('ai.index', ['tab' => 'campaigns']) }}">{{ __('t_ai.tab_campaigns') }}</a>
    @if (config('ai.model_lab.enabled'))
        <a class="btn_blue {{ $tab === 'models' ? 'active' : '' }}" href="{{ route('ai.models') }}">{{ __('t_ai.tab_models') }}</a>
    @endif
</p>
