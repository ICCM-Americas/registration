@extends(config('registration.layout'))

@section('title')
{{ __('registration::admin.payments_edit_title', ['name' => $ownerName]) }}
@endsection

@section('content')
    <p><a href="{{ $backAction }}">&larr; {{ $returnTo ? __('registration::admin.search_back') : __('registration::admin.payments_back') }}</a></p>

    <h1>{{ __('registration::admin.payments_edit_title', ['name' => $ownerName]) }}</h1>
    <p class="text-muted">{{ __('registration::admin.payments_edit_intro') }}</p>

    @if ($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ $action }}" id="js-answers-form" data-highlight="{{ is_string(request()->query('highlight')) ? request()->query('highlight') : '' }}"
        data-preview-url="{{ $previewAction }}" data-baseline-total="{{ $baselineTotal }}" data-baseline-formatted="{{ $baselineFormatted }}">
        @csrf
        @method('PUT')
        @if ($returnTo ?? null)
            <input type="hidden" name="_return" value="{{ $returnTo }}">
        @endif

        @foreach ($sections as $section)
            @include('registration::questions.section', compact('section', 'answers', 'evaluator', 'def'))
        @endforeach

        <button type="submit" class="btn btn-primary">{{ __('registration::admin.payments_save') }}</button>
    </form>

    @include('registration::partials.cost-change-modal')

    <script nonce="{{ $cspNonce ?? '' }}">
    (function () {
        var form = document.getElementById('js-answers-form');
        var previewUrl = form.dataset.previewUrl;
        var baseline = parseFloat(form.dataset.baselineTotal);
        var baselineFormatted = form.dataset.baselineFormatted;
        var csrf = document.querySelector('meta[name="csrf-token"]').content;
        var modal = document.getElementById('cost-change-modal');
        var oldTotalEl = modal.querySelector('.js-cost-old');
        var newTotalEl = modal.querySelector('.js-cost-new');
        var snapshot = null; // { wrapper, html }: the priced question last interacted with, before its change
        var pending = null; // { total, formatted }: the previewed total awaiting OK/Cancel

        // Arriving from a search result: bring the matched question into view.
        var highlighted = form.dataset.highlight && form.querySelector('[data-question="' + CSS.escape(form.dataset.highlight) + '"]');
        if (highlighted) {
            highlighted.classList.add('reg-search-target');
            highlighted.scrollIntoView({ block: 'center' });
        }

        // Mirrors the live checked/selected DOM property back onto the
        // matching attribute, so a plain innerHTML string snapshot (taken
        // just below) actually captures the current selection rather than
        // whatever the page originally rendered.
        function syncAttributes(root) {
            root.querySelectorAll('input[type=checkbox], input[type=radio]').forEach(function (el) {
                el.toggleAttribute('checked', el.checked);
            });
            root.querySelectorAll('select').forEach(function (select) {
                Array.prototype.forEach.call(select.options, function (opt) {
                    opt.toggleAttribute('selected', opt.selected);
                });
            });
        }

        function capture(e) {
            var field = e.target.closest && e.target.closest('.js-priced-field');
            var wrapper = field && field.closest('[data-question]');
            if (!wrapper) return;
            syncAttributes(wrapper);
            snapshot = { wrapper: wrapper, html: wrapper.innerHTML };
        }

        // Captured ahead of the change itself (focus for a select, mousedown
        // for a radio/checkbox click) so the snapshot is always the state
        // immediately before the change the admin is about to make.
        form.addEventListener('focusin', capture);
        form.addEventListener('mousedown', capture);

        function openModal(total, formatted) {
            pending = { total: total, formatted: formatted };
            oldTotalEl.textContent = baselineFormatted;
            newTotalEl.textContent = formatted;
            modal.classList.add('is-open');
            document.body.classList.add('modal-open');
        }

        function closeModal() {
            modal.classList.remove('is-open');
            document.body.classList.remove('modal-open');
            pending = null;
        }

        modal.addEventListener('click', function (e) {
            if (e.target.closest('.js-cost-change-ok')) {
                if (pending) {
                    baseline = pending.total;
                    baselineFormatted = pending.formatted;
                }
                closeModal();
            } else if (e.target.closest('.js-cost-change-cancel') || e.target === modal) {
                if (snapshot) snapshot.wrapper.innerHTML = snapshot.html;
                closeModal();
            }
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && modal.classList.contains('is-open')) {
                if (snapshot) snapshot.wrapper.innerHTML = snapshot.html;
                closeModal();
            }
        });

        form.addEventListener('change', function (e) {
            if (!e.target.classList.contains('js-priced-field')) return;

            fetch(previewUrl, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                body: new FormData(form),
            }).then(function (response) {
                return response.ok ? response.json() : null;
            }).then(function (data) {
                if (!data || Math.abs(data.total - baseline) < 0.005) return;
                openModal(data.total, data.formatted);
            }).catch(function () {});
        });
    })();
    </script>
@endsection
