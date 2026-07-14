@extends(config('registration.layout'))

@section('title')
{{ __('registration::admin.title') }}
@endsection

@section('content')
    @include('registration::partials.admin-nav')

    <h1>{{ __('registration::admin.title') }}</h1>

    @include('registration::partials.admin-assignment-warnings')

    @include('registration::partials.admin-registrations-card')

    @include('registration::partials.admin-arrivals-card')

    @include('registration::partials.admin-window-card')

    @include('registration::partials.admin-conference-card')

    @include('registration::partials.admin-answers-card')

    {{-- Shared by every card above with a confirm-before-submit form
         (admin-conference-card's "start next conference", admin-answers-card's
         "delete all answers") — one listener here rather than one per card
         partial, so a page that includes several of them doesn't double-bind. --}}
    <script nonce="{{ $cspNonce ?? '' }}">
    document.querySelectorAll('.js-confirm-submit').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            if (!confirm(form.dataset.confirm)) {
                e.preventDefault();
            }
        });
    });
    </script>
@endsection
