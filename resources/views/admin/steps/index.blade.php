@extends(config('registration.layout'))

@section('title')
{{ __('registration::admin.landing_title') }}
@endsection

@section('content')
    @include('registration::partials.admin-nav')


    <h1>{{ __('registration::admin.landing_title') }}</h1>
    <p class="text-muted">{{ __('registration::admin.landing_intro') }}</p>

    <div id="steps-builder"
        data-reorder-url="{{ route($routeName('admin.steps.reorder')) }}"
        data-csrf="{{ csrf_token() }}">

        <div id="steps-status" class="mb-2 iccm-status-line"></div>

        <div class="card mb-3">
            <div class="card-header d-flex flex-wrap justify-content-between align-items-center iccm-gap">
                <strong>{{ __('registration::admin.landing_steps') }}</strong>
                <a href="{{ route($routeName('admin.steps.create')) }}" class="btn btn-sm btn-primary">{{ __('registration::admin.add_step') }}</a>
            </div>

            <ul class="list-group list-group-flush step-list">
                @forelse ($steps as $step)
                    <li class="list-group-item step js-badge-row d-flex flex-wrap justify-content-between align-items-center iccm-gap iccm-drag" data-step-id="{{ $step->id }}">
                        <span>
                            <span class="text-muted mr-2">⠿</span>
                            <strong>{{ $step->heading }}</strong>
                            @unless ($step->enabled)<span class="badge badge-dark ml-1">{{ __('registration::admin.badge_hidden') }}</span>@endunless
                            {{-- Kept in the DOM (d-none when off) so the translations modal can toggle it on close. --}}
                            <span class="badge badge-primary ml-1{{ $step->isTranslated() ? '' : ' d-none' }}" data-badge="translated">{{ __('registration::admin.badge_translated') }}</span>
                        </span>
                        <span class="d-inline-flex flex-wrap align-items-center iccm-gap">
                            <a href="{{ route($routeName('admin.steps.edit'), $step) }}" class="btn btn-sm btn-outline-primary">{{ __('registration::admin.edit') }}</a>
                            {{-- Steps carry no visibility rules, so the visibility control is a plain hide/show toggle. --}}
                            <form method="POST" action="{{ route($routeName($step->enabled ? 'admin.steps.hide' : 'admin.steps.show'), $step) }}">
                                @csrf
                                <button type="submit" class="btn btn-sm btn-outline-secondary">{{ $step->enabled ? __('registration::admin.hide') : __('registration::admin.show') }}</button>
                            </form>
                            <a href="{{ route($routeName('admin.translations'), ['step', $step->id]) }}" class="btn btn-sm btn-outline-secondary js-editor-link">{{ __('registration::admin.translations') }}</a>
                            <form method="POST" action="{{ route($routeName('admin.steps.destroy'), $step) }}" class="js-confirm-submit" data-confirm="{{ __('registration::admin.confirm_delete_step') }}">
                                @csrf @method('DELETE')
                                <button type="submit" class="btn btn-sm btn-danger">{{ __('registration::admin.delete') }}</button>
                            </form>
                        </span>
                    </li>
                @empty
                    <li class="list-group-item text-muted">{{ __('registration::admin.no_steps') }}</li>
                @endforelse
            </ul>
        </div>
    </div>

    @include('registration::partials.editor-modal')

    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js"></script>
    <script nonce="{{ $cspNonce ?? '' }}">
    (function () {
        document.querySelectorAll('.js-confirm-submit').forEach(function (form) {
            form.addEventListener('submit', function (e) {
                if (!confirm(form.dataset.confirm)) {
                    e.preventDefault();
                }
            });
        });

        var builder = document.getElementById('steps-builder');
        if (!builder || typeof Sortable === 'undefined') return;

        var url = builder.dataset.reorderUrl;
        var csrf = builder.dataset.csrf;
        var statusEl = document.getElementById('steps-status');
        var list = builder.querySelector('.step-list');

        function serialize() {
            var steps = [];
            list.querySelectorAll('.step').forEach(function (item, i) {
                steps.push({ id: parseInt(item.dataset.stepId, 10), position: i });
            });
            return { steps: steps };
        }

        function status(key, ok) {
            statusEl.innerHTML = '<span class="badge badge-' + (ok ? 'success' : 'danger') + '">' + key + '</span>';
        }

        function save() {
            fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                body: JSON.stringify(serialize())
            }).then(function (r) {
                status(r.ok ? @json(__('registration::admin.order_saved')) : @json(__('registration::admin.order_save_failed')), r.ok);
            }).catch(function () {
                status(@json(__('registration::admin.order_save_failed')), false);
            });
        }

        new Sortable(list, {
            draggable: '.step',
            animation: 150,
            onEnd: save
        });
    })();
    </script>
@endsection
