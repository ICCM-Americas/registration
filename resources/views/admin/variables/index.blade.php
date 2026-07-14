@extends(config('registration.layout'))

@section('title')
{{ __('registration::admin.variables_title') }}
@endsection

@section('content')
    @include('registration::partials.admin-nav')


    <h1>{{ __('registration::admin.variables_title') }}</h1>
    <p class="text-muted">{{ __('registration::admin.variables_intro') }}</p>

    @if ($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="card mb-4">
        <div class="card-header">{{ __('registration::admin.variables') }}</div>
        <div class="card-body">
            @foreach ($variables as $variable)
                <div class="iccm-row">
                    <form method="POST" action="{{ route($routeName('admin.variables.update'), $variable) }}" class="iccm-row iccm-row-tight iccm-row-fill">
                        @csrf
                        @method('PUT')
                        {{-- Shown with its braces so the admin can copy the exact token. --}}
                        <code class="iccm-field-12 variables-token">{{ '{'.$variable->name.'}' }}</code>
                        <input type="text" name="value" value="{{ $variable->value }}" class="form-control form-control-sm iccm-field-fill" required>
                        <button type="submit" class="btn btn-sm btn-primary">{{ __('registration::admin.save') }}</button>
                    </form>
                    <form method="POST" action="{{ route($routeName('admin.variables.destroy'), $variable) }}" class="js-confirm-submit" data-confirm="{{ __('registration::admin.confirm_delete_variable') }}">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-sm btn-outline-danger">{{ __('registration::admin.delete') }}</button>
                    </form>
                </div>
            @endforeach

            <form method="POST" action="{{ route($routeName('admin.variables.store')) }}" class="iccm-row iccm-row-spaced">
                @csrf
                <input type="text" name="name" maxlength="64" placeholder="{{ __('registration::admin.name') }}" pattern="[A-Za-z][A-Za-z0-9_]*" class="form-control form-control-sm iccm-field-12" required>
                <input type="text" name="value" placeholder="{{ __('registration::admin.value') }}" class="form-control form-control-sm iccm-field-fill" required>
                <button type="submit" class="btn btn-sm btn-secondary">{{ __('registration::admin.add') }}</button>
            </form>
            <small class="form-text text-muted">{{ __('registration::admin.variable_name_hint') }}</small>
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
