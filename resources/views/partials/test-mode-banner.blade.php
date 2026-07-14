{{--
    The admin test drive's banner on the shared wizard views: says nothing will
    be recorded, and offers the way out — back to the Questions console,
    anchored (plain HTML #fragment, no scripted scrolling) to the section being
    tested when the run is on one.

    Rendered only in test mode. Expects: $testing, $exitUrl.
--}}
@if ($testing ?? false)

    <div class="alert alert-secondary d-flex flex-wrap justify-content-between align-items-center test-mode-banner">
        <span>{{ __('registration::admin.test_mode_notice') }}</span>
        <a href="{{ $exitUrl }}" class="btn btn-sm btn-outline-secondary">{{ __('registration::admin.test_exit') }}</a>
    </div>
@endif
