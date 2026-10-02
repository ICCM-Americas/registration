{{--
    One option row of the question form's Options list. Read view: the value,
    label, cost and per-diem days with "guests"/"conditional" tags — click it
    to swap in the row's single edit box, a "value | label | cost | per-diem
    days | guests | help text" line. The drag handle orders the rows; the
    Visibility button opens the shared modal (a new row gains its button as
    soon as its line is committed — the form's script persists the option
    then, since a rule needs a row id); Delete drops the row from the
    submission.

    Expects: $index (the row's input key — a literal "__INDEX__" inside the
    <template> the add-option button clones), $row (a plain array from
    QuestionBuilderController::optionRows(), or null for the blank template
    row, which renders straight in edit mode as a blank line does),
    $locked (whether the parent question is locked against edits), and
    $textOnly (a locked, saved question: the row's value, label and help
    text render as their own inputs, everything else read-only).

    Styling comes from the published stylesheets (registration.css and the
    shared iccm-* utilities), which the layout links once per page — this
    partial is rendered once per existing option AND, via <template>, cloned
    afresh (by string, re-parsed through insertAdjacentHTML) on every "Add
    option" click, so anything it carried itself would pile up in the DOM.
--}}
@php($row = $row ?? [
    'id' => null, 'line' => '', 'value' => '', 'label' => '',
    'cost' => null, 'per_diem_days' => 0, 'per_diem_guests' => false, 'conditional' => false,
    'description' => '',
])
@php($textOnly = $textOnly ?? false)
@php($editing = $row['value'] === '')
<li @if ($row['id']) id="option-{{ $row['id'] }}" @endif class="list-group-item option-row js-badge-row d-flex flex-wrap align-items-center py-2 iccm-gap">
    <input type="hidden" name="options[{{ $index }}][id]" value="{{ $row['id'] }}">
    <span class="text-muted option-drag {{ $locked ? 'iccm-drag-locked' : 'iccm-drag' }}">⠿</span>

    @if ($textOnly)
        <input type="text" name="options[{{ $index }}][value]" value="{{ old("options.$index.value", $row['value']) }}" maxlength="255"
            class="form-control form-control-sm option-text" placeholder="{{ __('registration::admin.option_value') }}" aria-label="{{ __('registration::admin.option_value') }}" required>
        <input type="text" name="options[{{ $index }}][label]" value="{{ old("options.$index.label", $row['label']) }}" maxlength="255"
            class="form-control form-control-sm option-text" placeholder="{{ __('registration::admin.option_label') }}" aria-label="{{ __('registration::admin.option_label') }}">
        <input type="text" name="options[{{ $index }}][description]" value="{{ old("options.$index.description", $row['description']) }}" maxlength="2048"
            class="form-control form-control-sm option-text" placeholder="{{ __('registration::admin.option_help') }}" aria-label="{{ __('registration::admin.option_help') }}">
    @endif

    <span @unless ($textOnly) role="button" title="{{ __('registration::admin.option_edit_title') }}" @endunless class="option-display flex-grow-1{{ $editing ? ' d-none' : '' }}">
        @unless ($textOnly)
            <code class="option-display-value">{{ $row['value'] }}</code>
            <span class="option-display-label">{{ $row['label'] }}</span>
        @endunless
        <span class="text-muted option-display-cost{{ $row['cost'] === null ? ' d-none' : '' }}">— {{ $row['cost'] }}</span>
        <span class="text-muted option-display-days{{ $row['per_diem_days'] > 0 ? '' : ' d-none' }}">— {{ __('registration::admin.option_days_display', ['days' => $row['per_diem_days']]) }}</span>
        <span class="badge badge-secondary option-display-guests{{ $row['per_diem_guests'] ? '' : ' d-none' }}">{{ __('registration::admin.badge_guests') }}</span>
        {{-- Kept in the DOM (d-none when off) so the visibility modal can toggle it on close. --}}
        <span class="badge badge-light border{{ $row['conditional'] ? '' : ' d-none' }}" data-badge="conditional">{{ __('registration::admin.badge_conditional') }}</span>
    </span>

    @unless ($textOnly)
        <input type="text" name="options[{{ $index }}][line]" value="{{ $row['line'] }}"
            class="form-control form-control-sm flex-grow-1 option-line{{ $editing ? '' : ' d-none' }}"
            placeholder="{{ __('registration::admin.option_line_placeholder') }}"
            aria-label="{{ __('registration::admin.question_options') }}" {{ $locked ? 'disabled' : '' }}>
    @endunless

    @if ($row['id'])
        <a href="{{ route($routeName('admin.options.visibility'), $row['id']) }}" class="btn btn-sm btn-outline-secondary js-editor-link">{{ __('registration::admin.visibility') }}</a>
    @endif
    <button type="button" class="btn btn-sm btn-danger option-remove" {{ $locked ? 'disabled' : '' }}>{{ __('registration::admin.delete') }}</button>
</li>
