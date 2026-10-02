{{--
    The delete-registrations confirmation, fetched over AJAX into the shared
    editor modal from the search page. Lists each registration to delete;
    asks, for each selected guest, whether to delete just the guest or the
    whole registration; and asks each departing group leader's successor.

    Expects: $registrants and $guests, as built by RegistrationDeletion::preview().
--}}
<div class="js-editor" data-title="{{ __('registration::admin.search_delete_title') }}">
    <div class="js-editor-errors"></div>

    @if (empty($registrants) && empty($guests))
        <div class="alert alert-secondary">{{ __('registration::admin.search_delete_nothing') }}</div>
    @else
        <p>{{ __('registration::admin.search_delete_intro') }}</p>

        <form method="POST" action="{{ route($routeName('admin.search.registrations.destroy')) }}">
            @csrf
            @method('DELETE')

            @if ($registrants)
                <ul class="list-group mb-3">
                    @foreach ($registrants as $registrant)
                        <li class="list-group-item">
                            <input type="hidden" name="registrants[]" value="{{ $registrant['id'] }}">
                            <strong>{{ $registrant['name'] }}</strong>
                            <span class="text-muted">({{ trans_choice('registration::admin.search_delete_guest_count', $registrant['guests']) }})</span>
                            @include('registration::admin.search.leader-choice', ['row' => $registrant, 'fieldId' => 'search-leader-'.$loop->index, 'onlyIf' => false])
                        </li>
                    @endforeach
                </ul>
            @endif

            @if ($guests)
                <h3 class="h6">{{ __('registration::admin.search_delete_guests_heading') }}</h3>
                <p class="text-muted">{{ __('registration::admin.search_delete_guests_intro') }}</p>
                @foreach ($guests as $guest)
                    @php($owner = $guest['owner'])
                    <fieldset class="border rounded p-2 mb-2">
                        <input type="hidden" name="guest_targets[]" value="{{ $guest['target'] }}">
                        <label class="iccm-checkbox-row">
                            <input type="radio" id="search-guest-{{ $loop->index }}-guest" name="guests[{{ $guest['target'] }}]" value="guest" required>
                            {{ __('registration::admin.search_delete_only_guest', ['guest' => $guest['label'], 'name' => $owner['name']]) }}
                        </label>
                        <label class="iccm-checkbox-row">
                            <input type="radio" id="search-guest-{{ $loop->index }}-registrant" name="guests[{{ $guest['target'] }}]" value="registrant" required>
                            {{ trans_choice('registration::admin.search_delete_whole', $owner['guests'], ['name' => $owner['name']]) }}
                        </label>
                        @include('registration::admin.search.leader-choice', ['row' => $owner, 'fieldId' => 'search-guest-leader-'.$loop->index, 'onlyIf' => true])
                    </fieldset>
                @endforeach
            @endif

            <div class="d-flex flex-wrap align-items-center iccm-gap mt-3">
                <button type="submit" class="btn btn-danger">{{ __('registration::admin.search_delete_confirm') }}</button>
                <button type="button" class="btn btn-secondary" data-dismiss="modal">{{ __('registration::admin.cancel') }}</button>
            </div>
        </form>
    @endif
</div>
