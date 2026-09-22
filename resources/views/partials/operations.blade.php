@php /** @var array<string, mixed> $operations */ @endphp
<p class="box_highlight textCenter no_buddies">{{ __('t_ai.operations_heading') }}</p>
<p>{{ __('t_ai.operations_note') }}</p>

@foreach ($operations['operations'] as $operation)
    <div class="group bborder">
        <form method="post" action="{{ route('ai.operations') }}">
            @csrf
            <input type="hidden" name="operation" value="{{ $operation->value }}">
            <p>
                <strong>{{ __('t_ai.operation_' . $operation->value) }}</strong>
                — {{ __('t_ai.operation_' . $operation->value . '_effect') }}
            </p>
            <p class="textCenter"><input type="submit" class="btn_blue" value="{{ __('t_ai.operation_run') }}"></p>
        </form>
    </div>
@endforeach

<p class="box_highlight textCenter no_buddies">{{ __('t_ai.operations_runs_heading') }}</p>
@if ($operations['runs'] === [])
    <p>{{ __('t_ai.operations_no_runs') }}</p>
@endif
@if ($operations['runs'] !== [])
    <div class="group bborder">
        <table class="defaultTable">
            <tr>
                <th>{{ __('t_ai.operation_column') }}</th>
                <th>{{ __('t_ai.operations_column_status') }}</th>
                <th>{{ __('t_ai.operations_column_result') }}</th>
                <th>{{ __('t_ai.operations_column_started') }}</th>
            </tr>
            @foreach ($operations['runs'] as $run)
                <tr>
                    <td>{{ __('t_ai.operation_' . $run['operation']) }}</td>
                    <td>{{ __('t_ai.operation_status_' . $run['status']) }}</td>
                    <td>{{ $run['result'] }}</td>
                    <td>{{ $run['started_at'] }}</td>
                </tr>
            @endforeach
        </table>
    </div>
@endif
