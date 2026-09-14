<li>
    <a class="{{ Request::is('admin/ai*') ? 'active' : '' }}" href="{{ route('ai.index') }}">
        {{ __('t_ai.title') }}
    </a>
</li>
