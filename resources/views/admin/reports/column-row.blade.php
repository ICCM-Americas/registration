{{--
    One report column: its source (a question, a built-in field, or blank),
    display mode, and heading override, plus the mapping/rule/delete actions.
    Shared by the editor's initial list and the AJAX add response, so both
    stay in sync.

    Expects: $report, $column, $displays, $builtins, $questionGroups.

    Styling comes from the published stylesheets (registration.css and the
    shared iccm-* utilities), which the layout links once per page — this
    partial is also re-rendered standalone as the AJAX "add column" response,
    so anything it carried itself would be duplicated into the DOM on every
    add.
--}}
<div id="column-{{ $column->id }}" class="p-2 mb-2 border rounded js-badge-row column iccm-row iccm-row-wide iccm-row-tight" data-column-id="{{ $column->id }}">
    <span class="text-muted column-drag iccm-drag">⠿</span>

    <strong class="report-column-heading">{{ $column->heading() }}</strong>

    <form method="POST" action="{{ route($routeName('admin.reports.columns.update'), [$report, $column]) }}"
          class="js-column-form iccm-row iccm-row-tight report-column-form">
        @csrf @method('PUT')
        <select name="source" class="form-control form-control-sm js-column-source report-field-select-sm" aria-label="{{ __('registration::admin.report_column_source') }}">
            <optgroup label="{{ __('registration::admin.report_column_custom_group') }}">
                <option value="none" @selected($column->question === null && $column->field === null)>{{ __('registration::admin.report_column_custom_blank') }}</option>
            </optgroup>
            <optgroup label="{{ __('registration::admin.report_column_builtin_group') }}">
                @foreach ($builtins as $builtin)
                    <option value="field:{{ $builtin->value }}" @selected($column->field === $builtin)>{{ $builtin->label() }}</option>
                @endforeach
            </optgroup>
            @foreach ($questionGroups as $group)
                @if ($group['questions']->isNotEmpty())
                    <optgroup label="{{ $group['label'] }}">
                        @foreach ($group['questions'] as $question)
                            <option value="question:{{ $question->id }}" @selected($column->question_id === $question->id)>{{ $question->key }}</option>
                        @endforeach
                    </optgroup>
                @endif
            @endforeach
        </select>
        <select name="guest_question_id" class="form-control form-control-sm js-column-guest-question report-field-select-sm"
                aria-label="{{ __('registration::admin.report_column_guest_question') }}" title="{{ __('registration::admin.report_column_guest_question_hint') }}"
                {{ $column->question === null ? 'disabled' : '' }}>
            <option value="">{{ __('registration::admin.report_column_guest_question_none') }}</option>
            @foreach ($questionGroups as $group)
                @if ($group['questions']->isNotEmpty())
                    <optgroup label="{{ $group['label'] }}">
                        @foreach ($group['questions'] as $question)
                            <option value="{{ $question->id }}" @selected($column->guest_question_id === $question->id)>{{ $question->key }}</option>
                        @endforeach
                    </optgroup>
                @endif
            @endforeach
        </select>
        <select name="display" class="form-control form-control-sm js-column-display report-field-select-md" aria-label="{{ __('registration::admin.report_column_display') }}" {{ $column->question === null && $column->field === null ? 'disabled' : '' }}>
            @foreach ($displays as $value => $label)
                <option value="{{ $value }}" @selected($column->display->value === $value)>{{ $label }}</option>
            @endforeach
        </select>
        <input type="text" name="header" value="{{ $column->header }}" placeholder="{{ __('registration::admin.report_column_header') }}"
               class="form-control form-control-sm report-field-select-sm" aria-label="{{ __('registration::admin.report_column_header') }}">
        <button type="submit" class="btn btn-sm btn-secondary">{{ __('registration::admin.save') }}</button>
    </form>

    <span class="badge badge-light border{{ $column->conditionGroups->isNotEmpty() ? '' : ' d-none' }}" data-badge="conditional">{{ __('registration::admin.badge_conditional') }}</span>

    @if ($column->question !== null)
        <a href="{{ route($routeName('admin.report_columns.mapping'), $column) }}" class="btn btn-sm btn-outline-secondary js-editor-link">
            {{ __('registration::admin.report_column_mapping') }}
        </a>
    @endif

    <a href="{{ route($routeName('admin.report_columns.visibility'), $column) }}" class="btn btn-sm btn-outline-secondary js-editor-link">
        {{ __('registration::admin.report_column_shown_when') }}
    </a>

    <form method="POST" action="{{ route($routeName('admin.reports.columns.destroy'), [$report, $column]) }}"
          class="js-confirm-submit" data-confirm="{{ __('registration::admin.report_column_delete_confirm') }}">
        @csrf @method('DELETE')
        <button type="submit" class="btn btn-sm btn-outline-danger">&times;</button>
    </form>
</div>
