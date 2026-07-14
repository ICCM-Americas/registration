@extends(config('registration.layout'))

@section('title')
{{ __('registration::admin.logistics_title') }}
@endsection

@section('content')
    @include('registration::partials.admin-nav')


    <h1>{{ __('registration::admin.logistics_title') }}</h1>
    <p class="text-muted">{{ __('registration::admin.logistics_intro') }}</p>

    @if (session('logistics_status'))
        <div class="alert alert-success">{{ session('logistics_status') }}</div>
    @endif

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
        <div class="card-header">{{ __('registration::admin.logistics_pages') }}</div>
        <div class="card-body">
            @foreach ($pages as $page)
                <div class="iccm-row iccm-row-wide">
                    <a href="{{ $page['url'] }}" class="btn btn-primary iccm-field-12">{{ $page['label'] }}</a>
                    <span class="text-muted">{{ $page['description'] }}</span>
                </div>
            @endforeach
        </div>
    </div>

    <form method="POST" action="{{ route($routeName('admin.logistics.settings')) }}" id="logistics-settings-form">
        @csrf
        @method('PUT')

        <div class="card mb-4">
            <div class="card-header">{{ __('registration::admin.reports_settings') }}</div>
            <div class="card-body">
                <p class="text-muted">{{ __('registration::admin.reports_settings_intro') }}</p>

                @foreach ($questionFields as $field)
                    <div class="p-3 mb-3 border rounded bg-light">
                        <p class="mb-2">{{ $field['hint'] }}</p>
                        <div class="form-group row mb-0">
                            <label for="{{ $field['name'] }}" class="col-sm-4 col-form-label">{{ $field['label'] }}</label>
                            <div class="col-sm-8">
                                <select id="{{ $field['name'] }}" name="{{ $field['name'] }}" class="form-control form-control-sm js-question-select">
                                    <option value="">{{ __('registration::admin.setting_none') }}</option>
                                    @foreach ($questionChoices as $key)
                                        <option value="{{ $key }}" @selected($field['current'] === $key)>{{ $key }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        @foreach ($field['valueFields'] as $vf)
                            <div class="p-2 pl-3 mt-3 js-value-field logistics-value-field" data-question-setting="{{ $vf['questionSetting'] }}" data-widget="{{ $vf['widget'] }}">
                                <p class="mb-2">{{ $vf['hint'] }}</p>
                                <div class="form-group row mb-0">
                                    <label for="{{ $vf['name'] }}" class="col-sm-4 col-form-label">{{ $vf['label'] }}</label>
                                    <div class="col-sm-8">
                                        @if ($vf['widget'] === 'radio')
                                            <select id="{{ $vf['name'] }}" name="{{ $vf['name'] }}" class="form-control form-control-sm js-radio-select @error($vf['name']) is-invalid @enderror" {{ $field['current'] === null ? 'disabled' : '' }}>
                                                <option value="">{{ __('registration::admin.setting_none') }}</option>
                                                @foreach ($vf['optionValues'] ?? [] as $optionValue)
                                                    <option value="{{ $optionValue }}" @selected(in_array($optionValue, $vf['selected'], true))>{{ $optionValue }}</option>
                                                @endforeach
                                            </select>
                                        @elseif ($vf['widget'] === 'arrows')
                                            <div class="js-dual-list logistics-dual-list">
                                                <select multiple size="6" class="form-control form-control-sm js-dual-available logistics-dual-select" {{ $field['current'] === null ? 'disabled' : '' }}>
                                                    @foreach (array_diff($vf['optionValues'] ?? [], $vf['selected']) as $optionValue)
                                                        <option value="{{ $optionValue }}">{{ $optionValue }}</option>
                                                    @endforeach
                                                </select>
                                                <div class="logistics-dual-buttons">
                                                    <button type="button" class="btn btn-sm btn-outline-secondary js-dual-add" {{ $field['current'] === null ? 'disabled' : '' }}>{{ __('registration::admin.report_answer_add') }}</button>
                                                    <button type="button" class="btn btn-sm btn-outline-secondary js-dual-remove" {{ $field['current'] === null ? 'disabled' : '' }}>{{ __('registration::admin.report_answer_remove') }}</button>
                                                </div>
                                                <select multiple size="6" class="form-control form-control-sm js-dual-chosen @error($vf['name']) is-invalid @enderror logistics-dual-select" {{ $field['current'] === null ? 'disabled' : '' }}>
                                                    @foreach ($vf['selected'] as $optionValue)
                                                        <option value="{{ $optionValue }}">{{ $optionValue }}</option>
                                                    @endforeach
                                                </select>
                                                <input type="hidden" name="{{ $vf['name'] }}" class="js-dual-hidden" value="{{ implode(',', $vf['selected']) }}" {{ $field['current'] === null ? 'disabled' : '' }}>
                                            </div>
                                        @else
                                            <input type="text" id="{{ $vf['name'] }}" name="{{ $vf['name'] }}" value="{{ old($vf['name'], $vf['value']) }}"
                                                   placeholder="{{ $vf['default'] }}"
                                                   class="form-control form-control-sm @error($vf['name']) is-invalid @enderror"
                                                   {{ $field['current'] === null ? 'disabled' : '' }}>
                                        @endif
                                        @if ($vf['stale'])
                                            <div class="invalid-feedback d-block">{{ __('registration::admin.report_answer_stale') }}</div>
                                        @endif
                                        @error($vf['name'])<span class="invalid-feedback d-block">{{ $message }}</span>@enderror
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endforeach

                <div class="form-group row mb-2">
                    <label for="shuttle_seats" class="col-sm-4 col-form-label">{{ __('registration::admin.setting_shuttle_seats') }}</label>
                    <div class="col-sm-8">
                        <input type="number" id="shuttle_seats" name="shuttle_seats" min="1" value="{{ $planner->seats() }}" class="form-control form-control-sm logistics-field-narrow">
                        <small class="form-text text-muted">{{ __('registration::admin.setting_shuttle_seats_hint') }}</small>
                    </div>
                </div>

                <div class="form-group row mb-2">
                    <label for="shuttle_travel_minutes" class="col-sm-4 col-form-label">{{ __('registration::admin.setting_shuttle_travel_minutes') }}</label>
                    <div class="col-sm-8">
                        <input type="number" id="shuttle_travel_minutes" name="shuttle_travel_minutes" min="1" value="{{ $planner->travelMinutes() }}" class="form-control form-control-sm logistics-field-narrow">
                        <small class="form-text text-muted">{{ __('registration::admin.setting_shuttle_travel_minutes_hint') }}</small>
                    </div>
                </div>

                <div class="form-group row mb-2">
                    <label for="shuttle_count" class="col-sm-4 col-form-label">{{ __('registration::admin.setting_shuttle_count') }}</label>
                    <div class="col-sm-8">
                        <input type="number" id="shuttle_count" name="shuttle_count" min="1" value="{{ $planner->shuttleCount() }}" class="form-control form-control-sm logistics-field-narrow">
                        <small class="form-text text-muted">{{ __('registration::admin.setting_shuttle_count_hint') }}</small>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header">{{ __('registration::admin.guest_report_settings') }}</div>
            <div class="card-body">
                <p class="text-muted">{{ __('registration::admin.guest_report_settings_intro') }}</p>

                <div class="form-group form-check mb-3">
                    <input type="hidden" name="guest_minors_get_badges" value="0">
                    <input type="checkbox" name="guest_minors_get_badges" id="guest_minors_get_badges" value="1" class="form-check-input" {{ $guestQuestions->minorsGetBadges() ? 'checked' : '' }}>
                    <label class="form-check-label" for="guest_minors_get_badges">{{ __('registration::admin.setting_guest_minors_get_badges') }}</label>
                </div>

                @foreach ($guestQuestionFields as $field)
                    <div class="p-3 mb-3 border rounded bg-light">
                        <p class="mb-2">{{ $field['hint'] }}</p>
                        <div class="form-group row mb-0">
                            <label for="{{ $field['name'] }}" class="col-sm-4 col-form-label">{{ $field['label'] }}</label>
                            <div class="col-sm-8">
                                <select id="{{ $field['name'] }}" name="{{ $field['name'] }}" class="form-control form-control-sm js-question-select">
                                    <option value="">{{ __('registration::admin.setting_none') }}</option>
                                    @foreach ($guestQuestionChoices as $key)
                                        <option value="{{ $key }}" @selected($field['current'] === $key)>{{ $key }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        @foreach ($field['valueFields'] as $vf)
                            <div class="p-2 pl-3 mt-3 js-value-field logistics-value-field" data-question-setting="{{ $vf['questionSetting'] }}" data-widget="{{ $vf['widget'] }}">
                                <p class="mb-2">{{ $vf['hint'] }}</p>
                                <div class="form-group row mb-0">
                                    <label for="{{ $vf['name'] }}" class="col-sm-4 col-form-label">{{ $vf['label'] }}</label>
                                    <div class="col-sm-8">
                                        @if ($vf['widget'] === 'radio')
                                            <select id="{{ $vf['name'] }}" name="{{ $vf['name'] }}" class="form-control form-control-sm js-radio-select @error($vf['name']) is-invalid @enderror" {{ $field['current'] === null ? 'disabled' : '' }}>
                                                <option value="">{{ __('registration::admin.setting_none') }}</option>
                                                @foreach ($vf['optionValues'] ?? [] as $optionValue)
                                                    <option value="{{ $optionValue }}" @selected(in_array($optionValue, $vf['selected'], true))>{{ $optionValue }}</option>
                                                @endforeach
                                            </select>
                                        @elseif ($vf['widget'] === 'arrows')
                                            <div class="js-dual-list logistics-dual-list">
                                                <select multiple size="6" class="form-control form-control-sm js-dual-available logistics-dual-select" {{ $field['current'] === null ? 'disabled' : '' }}>
                                                    @foreach (array_diff($vf['optionValues'] ?? [], $vf['selected']) as $optionValue)
                                                        <option value="{{ $optionValue }}">{{ $optionValue }}</option>
                                                    @endforeach
                                                </select>
                                                <div class="logistics-dual-buttons">
                                                    <button type="button" class="btn btn-sm btn-outline-secondary js-dual-add" {{ $field['current'] === null ? 'disabled' : '' }}>{{ __('registration::admin.report_answer_add') }}</button>
                                                    <button type="button" class="btn btn-sm btn-outline-secondary js-dual-remove" {{ $field['current'] === null ? 'disabled' : '' }}>{{ __('registration::admin.report_answer_remove') }}</button>
                                                </div>
                                                <select multiple size="6" class="form-control form-control-sm js-dual-chosen @error($vf['name']) is-invalid @enderror logistics-dual-select" {{ $field['current'] === null ? 'disabled' : '' }}>
                                                    @foreach ($vf['selected'] as $optionValue)
                                                        <option value="{{ $optionValue }}">{{ $optionValue }}</option>
                                                    @endforeach
                                                </select>
                                                <input type="hidden" name="{{ $vf['name'] }}" class="js-dual-hidden" value="{{ implode(',', $vf['selected']) }}" {{ $field['current'] === null ? 'disabled' : '' }}>
                                            </div>
                                        @else
                                            <input type="text" id="{{ $vf['name'] }}" name="{{ $vf['name'] }}" value="{{ old($vf['name'], $vf['value']) }}"
                                                   placeholder="{{ $vf['default'] }}"
                                                   class="form-control form-control-sm @error($vf['name']) is-invalid @enderror"
                                                   {{ $field['current'] === null ? 'disabled' : '' }}>
                                        @endif
                                        @if ($vf['stale'])
                                            <div class="invalid-feedback d-block">{{ __('registration::admin.report_answer_stale') }}</div>
                                        @endif
                                        @error($vf['name'])<span class="invalid-feedback d-block">{{ $message }}</span>@enderror
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endforeach
            </div>
        </div>

        <button type="submit" class="btn btn-primary">{{ __('registration::admin.save') }}</button>
    </form>

    <script nonce="{{ $cspNonce ?? '' }}">
        (function () {
            // key => {kind: 'radio'|'arrows'|'text', options: [...]}, one entry
            // per configured question — lets a paired matching-answer control
            // refresh live when the admin swaps to another question of the
            // same kind. Swapping to a different-kind question reshapes the
            // control (droplist / arrows / text) only after Save reloads the
            // page with the new widget.
            var questionMeta = @json($questionMeta);

            document.querySelectorAll('.js-value-field').forEach(function (wrapper) {
                var questionSelect = document.getElementById(wrapper.dataset.questionSetting);
                if (!questionSelect) {
                    return;
                }

                function refreshRadio(info) {
                    var select = wrapper.querySelector('.js-radio-select');
                    var current = select.value;
                    select.querySelectorAll('option').forEach(function (option, index) {
                        if (index > 0) {
                            option.remove();
                        }
                    });
                    info.options.forEach(function (value) {
                        var option = document.createElement('option');
                        option.value = value;
                        option.textContent = value;
                        option.selected = value === current;
                        select.appendChild(option);
                    });
                }

                function refreshArrows(info) {
                    var available = wrapper.querySelector('.js-dual-available');
                    var chosenValues = Array.from(wrapper.querySelectorAll('.js-dual-chosen option')).map(function (o) { return o.value; });
                    available.innerHTML = '';
                    info.options.filter(function (value) { return chosenValues.indexOf(value) === -1; }).forEach(function (value) {
                        var option = document.createElement('option');
                        option.value = value;
                        option.textContent = value;
                        available.appendChild(option);
                    });
                }

                questionSelect.addEventListener('change', function () {
                    var info = questionMeta[questionSelect.value];
                    var enabled = questionSelect.value !== '';

                    wrapper.querySelectorAll('select, input, button').forEach(function (el) {
                        el.disabled = !enabled;
                    });

                    if (enabled && info && info.kind === wrapper.dataset.widget) {
                        if (wrapper.dataset.widget === 'radio') {
                            refreshRadio(info);
                        } else if (wrapper.dataset.widget === 'arrows') {
                            refreshArrows(info);
                        }
                    }
                });
            });

            // Dual-listbox move buttons: move the highlighted options across,
            // then keep the hidden input (the field actually submitted) in
            // sync with the "chosen" box's contents and order.
            document.querySelectorAll('.js-dual-list').forEach(function (list) {
                var available = list.querySelector('.js-dual-available');
                var chosen = list.querySelector('.js-dual-chosen');
                var hidden = list.querySelector('.js-dual-hidden');

                function sync() {
                    hidden.value = Array.from(chosen.options).map(function (o) { return o.value; }).join(',');
                }

                function move(from, to) {
                    Array.from(from.selectedOptions).forEach(function (option) {
                        to.appendChild(option);
                    });
                    sync();
                }

                list.querySelector('.js-dual-add').addEventListener('click', function () { move(available, chosen); });
                list.querySelector('.js-dual-remove').addEventListener('click', function () { move(chosen, available); });
            });
        })();
    </script>
@endsection
