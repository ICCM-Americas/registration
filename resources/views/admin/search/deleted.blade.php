{{--
    The delete-registrations outcome, swapped into the shared editor modal;
    data-reload makes closing it reload the search page, rerunning the search.

    Expects: $registrations and $guests (how many of each were deleted).
--}}
<div class="js-editor" data-title="{{ __('registration::admin.search_deleted_title') }}" data-reload="1">
    <div class="alert alert-success">
        {{ __('registration::admin.search_deleted', [
            'registrations' => trans_choice('registration::admin.search_deleted_registrations', $registrations),
            'guests' => trans_choice('registration::admin.search_delete_guest_count', $guests),
        ]) }}
    </div>
    <button type="button" class="btn btn-primary" data-dismiss="modal">{{ __('registration::admin.close') }}</button>
</div>
