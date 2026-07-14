@extends(config('registration.layout'))

@section('title')
{{ __('registration::common.group_member_hub_title') }}
@endsection

@section('content')

<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-8">
            @include('registration::partials.test-mode-banner')

            <div class="card">
                <div class="card-header">{{ __('registration::common.group_member_hub_title') }}</div>

                <div class="card-body">
                    <p class="text-muted">{{ __('registration::common.group_member_hub_intro') }}</p>

                    @if ($members->isEmpty())
                        <p class="text-muted fst-italic">{{ __('registration::common.group_member_list_empty') }}</p>
                    @else
                        <ul class="list-group mb-3">
                            @foreach ($members as $member)
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    <span>
                                        {{ $member['name'] ?? __('registration::common.group_member_unnamed') }}
                                        @if ($member['email'])
                                            <span class="text-muted ms-2">{{ $member['email'] }}</span>
                                        @endif
                                    </span>
                                    @if ($member['registered'] ?? false)
                                        <span class="badge bg-success">{{ __('registration::mine.group_member_registered') }}</span>
                                    @else
                                        <span class="d-flex iccm-gap">
                                            <a href="{{ route($routeName($routePrefix.'.edit'), $member['id']) }}" class="btn btn-sm btn-outline-secondary">
                                                {{ __('registration::common.group_member_edit') }}
                                            </a>
                                            <form method="POST" action="{{ route($routeName($routePrefix.'.destroy'), $member['id']) }}"
                                                class="js-confirm-submit" data-confirm="{{ __('registration::common.confirm_delete_group_member') }}">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="btn btn-sm btn-outline-danger">
                                                    {{ __('registration::common.group_member_remove') }}
                                                </button>
                                            </form>
                                        </span>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    <a href="{{ route($routeName($routePrefix.'.create')) }}" class="btn btn-primary mb-3">
                        {{ __('registration::common.group_member_add') }}
                    </a>

                    <div class="d-flex justify-content-end mt-3">
                        <a href="{{ $backUrl }}" class="btn btn-outline-secondary">
                            {{ __('registration::common.group_member_hub_continue') }}
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
