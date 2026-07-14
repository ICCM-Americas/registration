@extends(config('registration.layout'))

@section('title')
{{ __('registration::common.guest_hub_title') }}
@endsection

@php
    // Literal per-key calls (a concatenated key would not be seen by the
    // translation-coverage guard — see pricing/index.blade.php's
    // $perDiemModeLabels for the same pattern).
    $guestTypeLabels = [
        'adult' => __('registration::common.guest_type_adult'),
        'minor' => __('registration::common.guest_type_minor'),
    ];
@endphp

@section('content')

<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-8">
            @include('registration::partials.test-mode-banner')

            <div class="card">
                <div class="card-header">{{ __('registration::common.guest_hub_title') }}</div>

                <div class="card-body">
                    <p class="text-muted">{{ __('registration::common.guest_hub_intro') }}</p>

                    @if ($guests->isEmpty())
                        <p class="text-muted fst-italic">{{ __('registration::common.guest_list_empty') }}</p>
                    @else
                        <ul class="list-group mb-3">
                            @foreach ($guests as $guest)
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    <span>
                                        {{ $guest['name'] ?? __('registration::common.guest_unnamed') }}
                                        <span class="badge bg-secondary ms-2">
                                            {{ $guestTypeLabels[$guest['type']->value] }}
                                        </span>
                                    </span>
                                    <span class="d-flex iccm-gap">
                                        <a href="{{ route($routeName($routePrefix.'.edit'), $guest['id']) }}" class="btn btn-sm btn-outline-secondary">
                                            {{ __('registration::common.guest_edit') }}
                                        </a>
                                        <form method="POST" action="{{ route($routeName($routePrefix.'.destroy'), $guest['id']) }}"
                                            class="js-confirm-submit" data-confirm="{{ __('registration::common.confirm_delete_guest') }}">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-outline-danger">
                                                {{ __('registration::common.guest_remove') }}
                                            </button>
                                        </form>
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    <a href="{{ route($routeName($routePrefix.'.create')) }}" class="btn btn-primary mb-3">
                        {{ __('registration::common.guest_add') }}
                    </a>

                    <div class="d-flex justify-content-end mt-3">
                        <a href="{{ $backUrl }}" class="btn btn-outline-secondary">
                            {{ __('registration::common.guest_hub_continue') }}
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

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
