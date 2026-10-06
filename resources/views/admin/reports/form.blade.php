@extends(config('registration.layout'))

@section('title')
{{ $report->exists ? __('registration::admin.report_edit_title') : __('registration::admin.report_create_title') }}
@endsection

@section('content')
    @include('registration::partials.admin-nav')


    <h1>{{ $report->exists ? __('registration::admin.report_edit_title') : __('registration::admin.report_create_title') }}</h1>

    @if (session('reports_status'))
        <div class="alert alert-success">{{ session('reports_status') }}</div>
    @endif

    <form method="POST" action="{{ $report->exists ? route($routeName('admin.reports.update'), $report) : route($routeName('admin.reports.store')) }}">
        @csrf
        @if ($returnTo ?? null)
            <input type="hidden" name="_return" value="{{ $returnTo }}">
        @endif
        @if ($report->exists)
            @method('PUT')
        @endif

        <div class="card mb-4">
            <div class="card-body">
                @if ($report->exists)
                    <p>
                        {{ __('registration::admin.report_type') }}: <strong>{{ $report->type->label() }}</strong>
                        <small class="text-muted d-block">{{ $report->type->description() }}</small>
                    </p>
                @else
                    <fieldset class="form-group" id="report-type">
                        <legend class="col-form-label pt-0">{{ __('registration::admin.report_type') }}</legend>
                        @foreach ($types as $type)
                            <label class="iccm-checkbox-row">
                                <input type="radio" name="type" value="{{ $type->value }}" required @checked(old('type') === $type->value)>
                                <span><strong>{{ $type->label() }}</strong> — <span class="font-weight-normal">{{ $type->description() }}</span></span>
                            </label>
                        @endforeach
                        @error('type')<span class="invalid-feedback d-block">{{ $message }}</span>@enderror
                    </fieldset>
                @endif

                <div class="form-group">
                    <label for="report-name">{{ __('registration::admin.report_name') }}</label>
                    <input type="text" id="report-name" name="name" value="{{ old('name', $report->name) }}"
                           class="form-control @error('name') is-invalid @enderror" required>
                    @error('name')<span class="invalid-feedback">{{ $message }}</span>@enderror
                </div>

                <div class="form-group">
                    <label for="report-description">{{ __('registration::admin.report_description') }}</label>
                    <textarea id="report-description" name="description" rows="2"
                              class="form-control @error('description') is-invalid @enderror">{{ old('description', $report->description) }}</textarea>
                    @error('description')<span class="invalid-feedback">{{ $message }}</span>@enderror
                </div>

                <div class="form-group">
                    <label for="report-header">{{ __('registration::admin.report_header') }}</label>
                    <textarea id="report-header" name="header" rows="2"
                              class="form-control @error('header') is-invalid @enderror">{{ old('header', $report->header) }}</textarea>
                    @error('header')<span class="invalid-feedback">{{ $message }}</span>@enderror
                    <small class="form-text text-muted">{{ __('registration::admin.report_header_hint') }}</small>
                </div>

                <div class="form-group">
                    <label for="report-footer">{{ __('registration::admin.report_footer') }}</label>
                    <textarea id="report-footer" name="footer" rows="2"
                              class="form-control @error('footer') is-invalid @enderror">{{ old('footer', $report->footer) }}</textarea>
                    @error('footer')<span class="invalid-feedback">{{ $message }}</span>@enderror
                    <small class="form-text text-muted">{{ __('registration::admin.report_footer_hint') }}</small>
                </div>

                <div class="form-group mb-2">
                    <input type="hidden" name="include_adult_guests" value="0">
                    <label class="iccm-checkbox-row">
                        <input type="checkbox" name="include_adult_guests" id="include_adult_guests" value="1"
                               {{ old('include_adult_guests', $report->include_adult_guests) ? 'checked' : '' }}>
                        <span>{{ __('registration::admin.report_include_adult_guests') }}</span>
                    </label>
                </div>

                <div class="form-group mb-3">
                    <input type="hidden" name="include_minor_guests" value="0">
                    <label class="iccm-checkbox-row">
                        <input type="checkbox" name="include_minor_guests" id="include_minor_guests" value="1"
                               {{ old('include_minor_guests', $report->include_minor_guests) ? 'checked' : '' }}>
                        <span>{{ __('registration::admin.report_include_minor_guests') }}</span>
                    </label>
                </div>

                <button type="submit" class="btn btn-primary">{{ __('registration::admin.save') }}</button>
                <a href="{{ ($returnTo ?? null) ?: route($routeName('admin.reports')) }}" class="btn btn-outline-secondary">{{ __('registration::admin.cancel') }}</a>
            </div>
        </div>
    </form>

    @if ($report->exists)
        <div class="card mb-4">
            <div class="card-header">{{ __('registration::admin.report_columns') }}</div>
            <div class="card-body">
                <p class="text-muted">{{ __('registration::admin.report_columns_intro') }}</p>

                <div id="report-columns-builder"
                     data-reorder-url="{{ route($routeName('admin.reports.columns.reorder'), $report) }}"
                     data-csrf="{{ csrf_token() }}">
                    <div id="report-columns-status" class="mb-2 iccm-status-line"></div>

                    <p class="text-muted" id="report-columns-empty" {{ $report->columns->isEmpty() ? '' : 'hidden' }}>{{ __('registration::admin.report_columns_empty') }}</p>

                    <div class="report-column-list">
                        @foreach ($report->columns as $column)
                            @include('registration::admin.reports.column-row', ['report' => $report, 'column' => $column, 'displays' => $displays, 'builtins' => $builtins, 'questionGroups' => $questionGroups])
                        @endforeach
                    </div>
                </div>

                <form method="POST" action="{{ route($routeName('admin.reports.columns.store'), $report) }}"
                      id="add-column-form" class="mt-3 iccm-row iccm-row-tight">
                    @csrf
                    <select name="source" class="form-control form-control-sm js-column-source @error('source') is-invalid @enderror report-field-select-lg" required aria-label="{{ __('registration::admin.report_column_source') }}">
                        <option value="">{{ __('registration::admin.report_column_source') }}</option>
                        <optgroup label="{{ __('registration::admin.report_column_custom_group') }}">
                            <option value="none">{{ __('registration::admin.report_column_custom_blank') }}</option>
                        </optgroup>
                        <optgroup label="{{ __('registration::admin.report_column_builtin_group') }}">
                            @foreach ($builtins as $builtin)
                                <option value="field:{{ $builtin->value }}">{{ $builtin->label() }}</option>
                            @endforeach
                        </optgroup>
                        @foreach ($questionGroups as $group)
                            @if ($group['questions']->isNotEmpty())
                                <optgroup label="{{ $group['label'] }}">
                                    @foreach ($group['questions'] as $question)
                                        <option value="question:{{ $question->id }}">{{ $question->key }}</option>
                                    @endforeach
                                </optgroup>
                            @endif
                        @endforeach
                    </select>
                    <select name="display" class="form-control form-control-sm js-column-display report-field-select-md" aria-label="{{ __('registration::admin.report_column_display') }}">
                        @foreach ($displays as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    <input type="text" name="header" value="{{ old('header') }}" placeholder="{{ __('registration::admin.report_column_header') }}"
                           class="form-control form-control-sm report-field-select-sm" aria-label="{{ __('registration::admin.report_column_header') }}">
                    <button type="submit" class="btn btn-secondary btn-sm">{{ __('registration::admin.report_column_add') }}</button>
                    <small class="form-text text-muted report-field-full">{{ __('registration::admin.report_column_header_hint') }}</small>
                    <div class="alert alert-danger d-none report-field-full" id="add-column-errors"></div>
                </form>
            </div>
        </div>

        <div class="card mb-4 js-badge-row">
            <div class="card-header">{{ __('registration::admin.report_rules') }}</div>
            <div class="card-body">
                <p class="text-muted">
                    {{ $report->type === \ConferenceTools\Registration\Enums\ReportType::Individual ? __('registration::admin.report_individual_rules_intro') : __('registration::admin.report_rules_intro') }}
                    <span class="badge badge-light border{{ $report->conditionGroups->isNotEmpty() ? '' : ' d-none' }}" data-badge="conditional">{{ __('registration::admin.badge_conditional') }}</span>
                </p>
                <a href="{{ route($routeName('admin.reports.visibility'), $report) }}" class="btn btn-outline-secondary js-editor-link">
                    {{ __('registration::admin.report_rules_edit') }}
                </a>
            </div>
        </div>

        @include('registration::partials.editor-modal')

        <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js"></script>
        <script nonce="{{ $cspNonce ?? '' }}">
        (function () {
            var builder = document.getElementById('report-columns-builder');
            if (!builder || typeof Sortable === 'undefined') return;

            var url = builder.dataset.reorderUrl;
            var csrf = builder.dataset.csrf;
            var statusEl = document.getElementById('report-columns-status');
            var list = builder.querySelector('.report-column-list');
            var emptyEl = document.getElementById('report-columns-empty');

            // Delegated (not per-row) because rows added by the AJAX "add
            // column" flow below did not exist when the page loaded.
            list.addEventListener('submit', function (e) {
                var form = e.target.closest('.js-confirm-submit');
                if (form && !confirm(form.dataset.confirm)) {
                    e.preventDefault();
                }
            });

            function serialize() {
                var columns = [];
                list.querySelectorAll('.column').forEach(function (item, i) {
                    columns.push({ id: parseInt(item.dataset.columnId, 10), position: i });
                });
                return { columns: columns };
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
                handle: '.column-drag',
                animation: 150,
                onEnd: save
            });

            // A blank or built-in column has nothing for the display select
            // to apply to, so it's disabled the moment its source is chosen —
            // both on the add form and on every row's own edit form. The
            // guest-question override only means anything for a question
            // column, so it's disabled outside that case too (it isn't on
            // the add form at all — only existing rows carry it).
            function syncDisplay(source) {
                var form = source.closest('form');
                var display = form && form.querySelector('.js-column-display');
                if (display) display.disabled = source.value === 'none';
                var guestQuestion = form && form.querySelector('.js-column-guest-question');
                if (guestQuestion) guestQuestion.disabled = source.value.indexOf('question:') !== 0;
            }

            document.addEventListener('change', function (e) {
                var source = e.target.closest('.js-column-source');
                if (source) syncDisplay(source);
            });

            document.querySelectorAll('.js-column-source').forEach(syncDisplay);

            // Adding a column happens over AJAX: the new row is inserted in
            // place and the form resets, so the page never scrolls back to
            // the top the way a full-page redirect would.
            var addForm = document.getElementById('add-column-form');
            var addErrors = document.getElementById('add-column-errors');

            function addError(message) {
                addErrors.textContent = message;
                addErrors.classList.remove('d-none');
            }

            addForm.addEventListener('submit', function (e) {
                e.preventDefault();
                addErrors.classList.add('d-none');

                fetch(addForm.action, {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                    body: new FormData(addForm)
                }).then(function (r) {
                    if (r.ok) return r.json().then(function (data) {
                        list.insertAdjacentHTML('beforeend', data.html);
                        emptyEl.hidden = true;
                        syncDisplay(list.lastElementChild.querySelector('.js-column-source'));
                        addForm.reset();
                        syncDisplay(addForm.querySelector('.js-column-source'));
                    });
                    if (r.status === 422) return r.json().then(function (data) {
                        addError(Object.values(data.errors || {}).map(function (messages) { return messages.join(' '); }).join(' '));
                    });
                    throw new Error(r.status);
                }).catch(function () {
                    addError(@json(__('registration::admin.editor_failed')));
                });
            });
        })();
        </script>
    @endif
@endsection
