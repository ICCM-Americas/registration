@extends(config('registration.layout'))

@section('title')
{{ __('registration::admin.reports_title') }}
@endsection

@section('content')
    @include('registration::partials.admin-nav')


    <h1>{{ __('registration::admin.reports_title') }}</h1>
    <p class="text-muted">{{ __('registration::admin.reports_intro') }}</p>

    @if (session('reports_status'))
        <div class="alert alert-success">{{ session('reports_status') }}</div>
    @endif

    <div class="mb-3">
        <a href="{{ route($routeName('admin.reports.create')) }}" class="btn btn-primary">{{ __('registration::admin.report_create') }}</a>
    </div>

    @if ($reports->isEmpty())
        <p class="text-muted">{{ __('registration::admin.reports_empty') }}</p>
    @else
        <div class="card mb-4">
            <div class="card-body">
                @foreach ($reports as $report)
                    <div class="iccm-row iccm-row-wide">
                        <a href="{{ route($routeName('admin.reports.show'), $report) }}" class="btn btn-primary iccm-field-12">{{ $report->name }}</a>
                        <a href="{{ route($routeName('admin.reports.edit'), $report) }}" class="btn btn-outline-secondary btn-sm">{{ __('registration::admin.edit') }}</a>
                        <form method="POST" action="{{ route($routeName('admin.reports.destroy'), $report) }}"
                              class="js-confirm-submit" data-confirm="{{ __('registration::admin.report_delete_confirm') }}">
                            @csrf @method('DELETE')
                            <button type="submit" class="btn btn-outline-danger btn-sm">{{ __('registration::admin.delete') }}</button>
                        </form>
                        <span class="text-muted">{{ $report->description }}</span>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

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
