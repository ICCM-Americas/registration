{{--
    A departing group leader's successor picker in the delete confirmation —
    or, when no member would remain, the note that the group goes too.
    Renders nothing for a registrant who leads no group.

    Expects: $row (a RegistrationDeletion::preview() registrant row), $fieldId,
    $onlyIf (the picker only applies if the admin chooses the whole
    registration, so it isn't required).
--}}
@if ($row['successors'] !== null)
    @if ($row['successors']->isEmpty())
        <small class="form-text text-muted">{{ __('registration::admin.search_delete_group_removed') }}</small>
    @else
        <div class="form-group mb-0 mt-2">
            <label for="{{ $fieldId }}" class="small">{{ __('registration::admin.search_delete_new_leader', ['name' => $row['name']]) }}</label>
            <select id="{{ $fieldId }}" name="leaders[{{ $row['id'] }}]" class="form-control form-control-sm" @required(! $onlyIf)>
                <option value="">{{ __('registration::admin.search_delete_pick_leader') }}</option>
                @foreach ($row['successors'] as $member)
                    <option value="{{ $member['id'] }}">{{ $member['name'] }}</option>
                @endforeach
            </select>
            @if ($onlyIf)
                <small class="form-text text-muted">{{ __('registration::admin.search_delete_leader_only_if') }}</small>
            @endif
        </div>
    @endif
@endif
