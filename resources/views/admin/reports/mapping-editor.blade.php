{{--
    A report column's "Custom Values" mapping: fetched over AJAX by the report
    editor and shown in the shared modal (registration::partials.editor-modal).
    Only consulted by ReportRunner while the column's display mode is
    "mapped", but stored regardless so switching modes on and off never loses
    it. Earlier entries are tried first; a blank "Stored value" matches any
    answer, so combined with "Applies To" it can supply text purely from
    guest-ness (e.g. a registrant's or their guest's name).

    Expects: $column, $guestOptions.
--}}
@php
    // Literal per-key calls (a concatenated key would not be seen by the
    // translation-coverage guard — see pricing/index.blade.php's
    // $perDiemModeLabels for the same pattern).
    $guestLabels = [
        'any' => __('registration::admin.report_column_mapping_guest_any'),
        'guest' => __('registration::admin.report_column_mapping_guest_guest'),
        'non_guest' => __('registration::admin.report_column_mapping_guest_non_guest'),
        'adult_guest' => __('registration::admin.report_column_mapping_guest_adult_guest'),
        'minor_guest' => __('registration::admin.report_column_mapping_guest_minor_guest'),
    ];
@endphp
{{-- .mapping-editor-form/.mapping-field-* are defined in
     partials/editor-modal.blade.php: this fragment is fetched over AJAX and
     injected into that modal, whose nonce (not this response's own) is what
     the page's CSP actually allows. --}}
<div class="js-editor" data-title="{{ __('registration::admin.report_column_mapping_title', ['column' => $column->heading()]) }}">
    <div class="js-editor-errors"></div>

    <p class="text-muted">{!! __('registration::admin.report_column_mapping_intro', [
        'variables' => '<a href="'.route($routeName('admin.variables')).'" target="_blank" rel="noopener">'.__('registration::admin.nav_variables').'</a>',
        'questions' => '<a href="'.route($routeName('admin.questions')).'" target="_blank" rel="noopener">'.__('registration::admin.nav_questions').'</a>',
    ]) !!}</p>

    @if (empty($column->mapping))
        <p class="text-muted">{{ __('registration::admin.report_column_mapping_empty') }}</p>
    @else
        <table class="table table-sm">
            <thead>
                <tr>
                    <th>{{ __('registration::admin.report_column_mapping_from') }}</th>
                    <th>{{ __('registration::admin.report_column_mapping_guest') }}</th>
                    <th>{{ __('registration::admin.report_column_mapping_to') }}</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($column->mapping as $index => $entry)
                    <tr>
                        <td>
                            @if ($entry['value'] === null || $entry['value'] === '')
                                <em class="text-muted">{{ __('registration::admin.report_column_mapping_any_value') }}</em>
                            @else
                                <code>{{ $entry['value'] }}</code>
                            @endif
                        </td>
                        <td>{{ $guestLabels[$entry['guest'] ?? 'any'] }}</td>
                        <td>{{ $entry['text'] }}</td>
                        <td>
                            <form method="POST" action="{{ route($routeName('admin.report_columns.mapping.destroy'), $column) }}">
                                @csrf
                                @method('DELETE')
                                <input type="hidden" name="index" value="{{ $index }}">
                                <button type="submit" class="btn btn-sm btn-outline-danger">&times;</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <form method="POST" action="{{ route($routeName('admin.report_columns.mapping.store'), $column) }}" class="d-flex flex-wrap align-items-end iccm-gap">
        @csrf
        <div class="form-group mb-0">
            <label for="mapping-value">{{ __('registration::admin.report_column_mapping_from') }}</label>
            <input type="text" id="mapping-value" name="value" class="form-control form-control-sm mapping-field-sm" placeholder="{{ __('registration::admin.report_column_mapping_any_value') }}">
            <small class="form-text text-muted">{{ __('registration::admin.report_column_mapping_value_hint') }}</small>
        </div>
        <div class="form-group mb-0">
            <label for="mapping-guest">{{ __('registration::admin.report_column_mapping_guest') }}</label>
            <select id="mapping-guest" name="guest" class="form-control form-control-sm mapping-field-sm">
                @foreach ($guestOptions as $option)
                    <option value="{{ $option->value }}">{{ $option->label() }}</option>
                @endforeach
            </select>
        </div>
        <div class="form-group mb-0">
            <label for="mapping-text">{{ __('registration::admin.report_column_mapping_to') }}</label>
            <input type="text" id="mapping-text" name="text" class="form-control form-control-sm mapping-field-md" required>
        </div>
        <button type="submit" class="btn btn-sm btn-secondary">{{ __('registration::admin.report_column_mapping_add') }}</button>
    </form>
</div>
