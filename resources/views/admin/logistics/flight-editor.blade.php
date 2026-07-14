{{--
    The travel-answer editor for one passenger, fetched into the shared
    editor modal (registration::partials.editor-modal) from an "unreadable"
    name on the Shuttle Schedule: the flight answer exactly as entered, for
    the admin to fix into something the schedules can read. The status lines
    read the answer back the way the planner will. After a save the fragment
    returns marked data-reload, so closing the modal re-renders the page
    with the corrected schedules.

    Expects: $flight, $registrant, $question, $value, $name, $statuses
    (arrival/departure => normalized "HH:MM", or null while unreadable —
    only the flights this question feeds, none while unanswered), $saved.
--}}
<div class="js-editor" data-title="{{ __('registration::admin.shuttles_flight_title', ['name' => $name]) }}"{!! $saved ? ' data-reload="1"' : '' !!}>
    <div class="js-editor-errors"></div>

    @if ($saved)
        <div class="alert alert-success">{{ __('registration::admin.shuttles_flight_saved') }}</div>
    @endif

    <p class="text-muted">{{ __('registration::admin.shuttles_flight_intro') }}</p>

    <form method="POST" action="{{ route($routeName('admin.logistics.shuttles.flight.update'), [$flight, $registrant->getKey()]) }}">
        @csrf
        @method('PUT')
        <div class="form-group">
            <label for="flight-answer">{{ __($question->translate('label')) }}</label>
            <textarea id="flight-answer" name="value" rows="4" class="form-control">{{ $value }}</textarea>
            <small class="form-text text-muted">{{ __('registration::admin.shuttles_flight_clear_hint') }}</small>
        </div>

        @foreach ($statuses as $which => $time)
            @php
                $label = __($which === 'arrival'
                    ? 'registration::admin.shuttles_flight_arrival'
                    : 'registration::admin.shuttles_flight_departure');
            @endphp
            <p class="mb-1 {{ $time !== null ? 'text-success' : 'text-danger' }}">
                {{ $time !== null
                    ? __('registration::admin.shuttles_flight_read', ['flight' => $label, 'time' => $time])
                    : __('registration::admin.shuttles_flight_unread', ['flight' => $label]) }}
            </p>
        @endforeach

        <button type="submit" class="btn btn-sm btn-primary mt-2">{{ __('registration::admin.save') }}</button>
    </form>
</div>
