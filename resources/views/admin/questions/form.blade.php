@extends(config('registration.layout'))

@section('title')
{{ __('registration::admin.questions_title') }}
@endsection

@section('content')
    @include('registration::partials.admin-nav')

    {{-- A locked question still takes edits to its texts; only adding a
         question is locked outright. --}}
    @php($textOnly = $locked && $question->exists)
    @php($textsLocked = $locked && ! $question->exists)

    <h1>{{ $question->exists ? __('registration::admin.edit') : __('registration::admin.add_question') }}</h1>

    @if ($textOnly)
        <div class="alert alert-info">{{ __('registration::admin.questions_texts_only') }}</div>
    @endif

    <form method="POST" action="{{ $question->exists ? route($routeName('admin.questions.update'), $question) : route($routeName('admin.questions.store')) }}"{!! $question->exists ? ' class="js-answer-sync"' : '' !!}>
        @csrf
        @if ($question->exists) @method('PUT') @endif

        <div class="form-group">
            <label for="section_id">{{ __('registration::admin.question_section') }}</label>
            <select name="section_id" id="section_id" class="form-control @error('section_id') is-invalid @enderror" required {{ $locked ? 'disabled' : '' }}>
                @foreach ($sections as $scopeValue => $scopeSections)
                    <optgroup label="{{ $scopeValue }}">
                        @foreach ($scopeSections as $section)
                            <option value="{{ $section->id }}" {{ (int) old('section_id', $question->section_id) === $section->id ? 'selected' : '' }}>
                                {{ $section->title }}
                            </option>
                        @endforeach
                    </optgroup>
                @endforeach
            </select>
            @error('section_id')<span class="invalid-feedback">{{ $message }}</span>@enderror
        </div>

        <div class="form-group">
            <label for="label">{{ __('registration::admin.question_label') }}</label>
            <textarea name="label" id="label" rows="3" maxlength="4096" class="form-control @error('label') is-invalid @enderror" required {{ $textsLocked ? 'disabled' : '' }}>{{ old('label', $question->label) }}</textarea>
            <small class="form-text text-muted">{{ __('registration::admin.question_label_hint') }}</small>
            <small class="form-text text-muted"><span id="label-count">0</span> / <span id="label-max"></span> {{ __('registration::admin.question_label_characters') }}</small>
            @error('label')<span class="invalid-feedback">{{ $message }}</span>@enderror
        </div>

        <div class="form-group">
            <label for="key">{{ __('registration::admin.question_key') }}</label>
            <input type="text" name="key" id="key" class="form-control @error('key') is-invalid @enderror" value="{{ old('key', $question->key) }}" {{ $locked || $question->is_system ? 'disabled' : '' }}>
            <small class="form-text text-muted">{{ $question->is_system ? __('registration::admin.question_system_locked_hint') : __('registration::admin.question_key_hint') }}</small>
            @error('key')<span class="invalid-feedback">{{ $message }}</span>@enderror
        </div>

        <div class="form-group">
            <label for="type">{{ __('registration::admin.question_type') }}</label>
            {{-- A protected question's type can't change — disabling the
                 select would drop "type" from the submission entirely
                 (required), so its real value rides along as a hidden
                 input instead, and the visible select is display-only. --}}
            @if ($question->is_system)
                <input type="hidden" name="type" value="{{ $question->type->value }}">
                <select id="type" class="form-control" disabled>
                    <option>{{ $question->type->value }}</option>
                </select>
                <small class="form-text text-muted">{{ __('registration::admin.question_system_locked_hint') }}</small>
            @else
                <select name="type" id="type" class="form-control" required {{ $locked ? 'disabled' : '' }}>
                    @foreach ($types as $type)
                        <option value="{{ $type->value }}" {{ old('type', $question->type?->value) === $type->value ? 'selected' : '' }}>{{ $type->value }}</option>
                    @endforeach
                </select>
            @endif
        </div>

        <div class="form-group form-check">
            <input type="hidden" name="required" value="0">
            <input type="checkbox" name="required" id="required" value="1" class="form-check-input" {{ old('required', $question->required) ? 'checked' : '' }} {{ $locked ? 'disabled' : '' }}>
            <label class="form-check-label" for="required">{{ __('registration::admin.question_required') }}</label>
        </div>

        <div class="form-group">
            <label for="help_text">{{ __('registration::admin.question_help') }}</label>
            <textarea name="help_text" id="help_text" rows="3" maxlength="1000" class="form-control" {{ $textsLocked ? 'disabled' : '' }}>{{ old('help_text', $question->help_text) }}</textarea>
            <small class="form-text text-muted">{{ __('registration::admin.question_help_hint') }}</small>
        </div>

        {{-- Only text-entry types render a placeholder; the group follows the
             chosen type like the Options fieldset below. --}}
        <div class="form-group" id="placeholder-group">
            <label for="placeholder">{{ __('registration::admin.question_placeholder') }}</label>
            <input type="text" name="placeholder" id="placeholder" class="form-control" value="{{ old('placeholder', $question->placeholder) }}">
        </div>

        {{-- One Options section: each option is an inline-editable row, ordered
             by drag & drop, with its visibility rule edited through the shared
             modal. A rule needs a row id, so on a saved question a new row is
             persisted as soon as its line is committed (blur/Enter), giving it
             its Visibility button right away. --}}
        @unless ($question->is_system)
            <fieldset class="form-group" id="options-group">
                <div class="form-group form-check">
                    <input type="hidden" name="translate_value" value="0">
                    <input type="checkbox" name="translate_value" id="translate_value" value="1" class="form-check-input"
                        {{ old('translate_value', $question->translate_value) ? 'checked' : '' }} {{ $locked ? 'disabled' : '' }}>
                    <label class="form-check-label" for="translate_value">{{ __('registration::admin.question_translate_value') }}</label>
                    <small class="form-text text-muted">{{ __('registration::admin.question_translate_value_hint') }}</small>
                </div>

                <label id="options_label">{{ __('registration::admin.question_options') }}</label>
                <ul class="list-group mb-2" id="options-list">
                    @foreach ($optionRows as $index => $row)
                        @include('registration::admin.questions.option-row', ['index' => $index, 'row' => $row, 'locked' => $locked, 'textOnly' => $textOnly])
                    @endforeach
                </ul>
                @error('options')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                <button type="button" id="option-add" class="btn btn-sm btn-outline-secondary" {{ $locked ? 'disabled' : '' }}>{{ __('registration::admin.option_add') }}</button>
                <small class="form-text text-muted">{{ $textOnly ? __('registration::admin.question_options_texts_hint') : __('registration::admin.question_options_hint') }}</small>
            </fieldset>

            <template id="option-row-template">
                @include('registration::admin.questions.option-row', ['index' => '__INDEX__', 'row' => null, 'locked' => $locked, 'textOnly' => false])
            </template>
        @endunless

        <button type="submit" class="btn btn-primary" {{ $textsLocked ? 'disabled' : '' }}>{{ __('registration::admin.save') }}</button>
        {{-- The anchor returns to roughly where this question sits on the list:
             its own row once saved, otherwise its section. --}}
        <a href="{{ route($routeName('admin.questions')) }}#{{ $question->exists ? 'question-'.$question->id : 'section-'.$question->section_id }}" class="btn btn-danger">{{ __('registration::admin.cancel') }}</a>
        {{-- Editing visibility/translations needs a saved row; the shared modal
             (same one the console list uses) opens over AJAX from these hrefs.
             Both stay editable even while locked. --}}
        @if ($question->exists)
            <a href="{{ route($routeName('admin.questions.visibility'), $question) }}" class="btn btn-outline-secondary js-editor-link">{{ __('registration::admin.visibility') }}</a>
            <a href="{{ route($routeName('admin.translations'), ['question', $question->id]) }}" class="btn btn-outline-secondary js-editor-link">{{ __('registration::admin.translations') }}</a>
        @endif
    </form>

    @if ($question->exists)
        @include('registration::partials.editor-modal')
        @include('registration::partials.answer-sync-modal')
    @endif

    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js"></script>
    <script nonce="{{ $cspNonce ?? '' }}">
        (function () {
            var locked = @json($locked);
            var textsLocked = @json($textsLocked);
            var optionTypes = @json(collect($types)->filter->usesOptions()->pluck('value')->values());
            var placeholderTypes = @json(collect($types)->filter->usesPlaceholder()->pluck('value')->values());
            var typeSelect = document.getElementById('type');
            var optionsGroup = document.getElementById('options-group');
            var optionsList = document.getElementById('options-list');
            var placeholderGroup = document.getElementById('placeholder-group');
            var placeholderInput = document.getElementById('placeholder');

            var labelBox = document.getElementById('label');
            var labelCount = document.getElementById('label-count');
            document.getElementById('label-max').textContent = labelBox.getAttribute('maxlength');

            function updateLabelCount() {
                labelCount.textContent = labelBox.value.length;
            }

            labelBox.addEventListener('input', updateLabelCount);
            updateLabelCount();

            // The Options fieldset and the Placeholder field both follow the
            // chosen type: shown only where they apply, and disabled while
            // hidden so a hidden field submits nothing (the server then clears
            // a stale placeholder / drops the option rows on save).
            function toggleTypeFields() {
                var options = optionTypes.indexOf(typeSelect.value) !== -1;
                if (optionsGroup) {
                    optionsGroup.disabled = !options || textsLocked;
                    optionsGroup.style.display = options ? '' : 'none';
                }

                var placeholder = placeholderTypes.indexOf(typeSelect.value) !== -1;
                placeholderInput.disabled = !placeholder || textsLocked;
                placeholderGroup.style.display = placeholder ? '' : 'none';
            }

            typeSelect.addEventListener('change', toggleTypeFields);
            toggleTypeFields();

            // A protected question renders no options editor at all (its
            // Yes/No pair is fixed) — none of the wiring below applies.
            if (optionsList) {
                // Rows are ordered by dragging; the server reads the submitted
                // row order, so no position inputs are needed. Skipped while
                // locked — the rows' own disabled inputs and the guard below
                // already make the list inert; this also stops the drag
                // interaction itself.
                if (!locked) {
                    new Sortable(optionsList, { handle: '.option-drag', animation: 150 });
                }

                // Add a blank row from the template under a fresh input key
                // ("nN" never collides with the rendered rows' numeric keys).
                // New rows start in edit mode.
                var counter = 0;
                document.getElementById('option-add').addEventListener('click', function () {
                    var html = document.getElementById('option-row-template').innerHTML.replace(/__INDEX__/g, 'n' + counter++);
                    optionsList.insertAdjacentHTML('beforeend', html);
                    optionsList.lastElementChild.querySelector('.option-line').focus();
                });

                // Mirrors the server's parse of a "value | label | cost | days |
                // guests | help" line, for redrawing a row's read view on blur.
                var daysText = @json(__('registration::admin.option_days_display'));
                var parseLine = function (line) {
                    var parts = line.split('|').map(function (s) { return s.trim(); });
                    return {
                        value: parts[0] || '',
                        label: parts[1] || parts[0] || '',
                        cost: parts[2] !== undefined && parts[2] !== '' && !isNaN(parts[2]) ? Number(parts[2]).toFixed(2) : null,
                        days: parseInt(parts[3], 10) > 0 ? parseInt(parts[3], 10) : 0,
                        guests: ['guests', 'both', 'attendee_and_guests'].indexOf((parts[4] || '').toLowerCase()) !== -1,
                    };
                };

                optionsList.addEventListener('click', function (event) {
                    if (locked) {
                        return;
                    }

                    // Removing a row simply drops it from the submission; the
                    // server deletes options absent from the posted set.
                    var remove = event.target.closest('.option-remove');
                    if (remove) {
                        remove.closest('li').remove();
                        return;
                    }

                    // Click the read view to edit the row as a single line. (The
                    // read view is a plain span, not a form control, so unlike
                    // the other row controls it needs this explicit lock check.)
                    var display = event.target.closest('.option-display');
                    if (display) {
                        var input = display.closest('li').querySelector('.option-line');
                        display.classList.add('d-none');
                        input.classList.remove('d-none');
                        input.focus();
                    }
                });

                // Enter in the edit box ends the edit (the blur below redraws the
                // read view) — it must not submit the whole form.
                optionsList.addEventListener('keydown', function (event) {
                    if (event.key === 'Enter' && event.target.closest('.option-line')) {
                        event.preventDefault();
                        event.target.blur();
                    }
                });

                // Leaving the edit box redraws the read view from the edited line;
                // a blank line stays in edit mode (saving it removes the option).
                optionsList.addEventListener('focusout', function (event) {
                    var input = event.target.closest('.option-line');
                    if (!input) {
                        return;
                    }

                    var parts = parseLine(input.value);
                    if (parts.value === '') {
                        return;
                    }

                    var row = input.closest('li');
                    var display = row.querySelector('.option-display');
                    display.querySelector('.option-display-value').textContent = parts.value;
                    display.querySelector('.option-display-label').textContent = parts.label;
                    var cost = display.querySelector('.option-display-cost');
                    cost.textContent = '— ' + parts.cost;
                    cost.classList.toggle('d-none', parts.cost === null);
                    var days = display.querySelector('.option-display-days');
                    days.textContent = '— ' + daysText.replace(':days', parts.days);
                    days.classList.toggle('d-none', parts.days === 0);
                    display.querySelector('.option-display-guests').classList.toggle('d-none', !parts.guests);
                    input.classList.add('d-none');
                    display.classList.remove('d-none');

                    persistNewRow(row, input.value);
                });

                // On a saved question, committing a new row's line persists the
                // option right away (its visibility rule needs an id), so the row
                // gains its Visibility button immediately. The form save then
                // reconciles the row by this id — later edits, reorders and
                // removals of the row still land with the form as before. On an
                // unsaved question there is nothing to attach to yet; the buttons
                // appear once the question is saved.
                var optionStoreUrl = @json($question->exists ? route($routeName('admin.questions.options.store'), $question) : null);
                var visibilityLabel = @json(__('registration::admin.visibility'));

                var persistNewRow = function (row, line) {
                    var idInput = row.querySelector('input[name$="[id]"]');
                    if (!optionStoreUrl || idInput.value !== '' || row.dataset.persisting) {
                        return;
                    }

                    row.dataset.persisting = '1';
                    fetch(optionStoreUrl, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('input[name="_token"]').value,
                            'Accept': 'application/json'
                        },
                        body: JSON.stringify({ line: line })
                    }).then(function (response) {
                        if (!response.ok) throw new Error(response.status);
                        return response.json();
                    }).then(function (data) {
                        idInput.value = data.id;
                        var link = document.createElement('a');
                        link.href = data.visibility_url;
                        link.className = 'btn btn-sm btn-outline-secondary js-editor-link';
                        link.textContent = visibilityLabel;
                        row.insertBefore(link, row.querySelector('.option-remove'));
                    }).catch(function () {
                        // Not fatal: the form save still creates the option; only
                        // the immediate Visibility button is unavailable.
                    }).finally(function () {
                        delete row.dataset.persisting;
                    });
                };
            }
        })();
    </script>
@endsection
