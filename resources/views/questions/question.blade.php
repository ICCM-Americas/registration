{{--
    Renders one configured question as a stacked Bootstrap form group (label
    above its control, since question labels tend to be long), driven entirely
    by the question's type/options. The input name is the question key, so the
    posted payload matches what the registration services expect.

    A question's visibility is always decided on the server ($evaluator); it is
    rendered hidden + disabled when the rule does not currently pass, so a hidden
    question neither submits a value nor blocks submission.

    Individual options may carry their own visibility rules (conditionally
    offered options); those are decided on the server only — an option whose
    rule fails is not rendered at all, and a choice question left with no
    visible options is treated as hidden.

    When $clientToggle is true (a within-step rule), the rule is also emitted as
    data-visible-when so the browser can show/hide it live as the user types — the
    server still re-validates. For cross-step rules $clientToggle is false: the
    server alone decides and the rule is not exposed to the browser.

    A priced question's driving control (the <select>, or each radio/checkbox
    <input>) also carries the js-priced-field class — a hook the admin
    Payments answers-edit page uses to know which changes should trigger its
    cost-change preview; no other consumer of this partial reads it.

    Expects: $question, $answers (accumulated/old input, keyed by question key),
    $evaluator (VisibilityEvaluator), $def (default Currency or null).
    Optional: $clientToggle (bool, default true).
--}}
@php
    use ConferenceTools\Registration\Enums\QuestionType;
    use ConferenceTools\Registration\Models\Question;

    $clientToggle = $clientToggle ?? true;
    $name = $question->key;
    $current = data_get($answers, $name);
    $options = $evaluator->visibleOptions($question, $answers);
    $visible = $evaluator->isVisible($question, $answers)
        && ($question->options->isEmpty() || $options->isNotEmpty());
    $rule = ($clientToggle && $question->conditionGroups->isNotEmpty())
        ? $question->conditionGroups->map->toRule()->values()->all()
        : null;
    $disabled = $visible ? '' : 'disabled';
    $invalid = $errors->has($name) ? 'is-invalid' : '';
@endphp

<div class="form-group" data-question="{{ $name }}"
    @if ($rule) data-visible-when="{{ json_encode($rule) }}" @endif
    {{ $visible ? '' : 'hidden' }}>

    <label for="{{ $name }}">
        {!! $label(__($question->translate('label'))) !!}@if ($question->required) *@endif
    </label>

    @switch($question->type)
        @case(QuestionType::Textarea)
            <textarea id="{{ $name }}" name="{{ $name }}" rows="{{ data_get($question->config, 'rows', 3) }}"
                class="form-control {{ $invalid }}" placeholder="{{ $vars($question->translate('placeholder')) }}" {{ $disabled }}
                @if ($question->required) required @endif>{{ $current }}</textarea>
            @break

        @case(QuestionType::Select)
            <select id="{{ $name }}" name="{{ $name }}" class="form-control {{ $invalid }} {{ $question->isPriced() ? 'js-priced-field' : '' }}" {{ $disabled }}
                @if ($question->required) required @endif>
                {{-- An empty first option, so a required select starts with no
                     answer chosen rather than silently defaulting to the first. --}}
                <option value="">{{ $vars($question->translate('placeholder') ?: __('Please choose…')) }}</option>
                @foreach ($options as $option)
                    <option value="{{ $option->value }}" {{ (string) $current === (string) $option->value ? 'selected' : '' }}>
                        {{ $vars(__($option->translate('label'))) }}@if ($option->cost !== null) — {{ $def?->format($option->cost) ?? $option->cost }}@endif
                    </option>
                @endforeach
            </select>
            @break

        @case(QuestionType::Radio)
            @foreach ($options as $option)
                <div class="form-check">
                    <input type="radio" id="{{ $name }}_{{ $option->value }}" name="{{ $name }}" value="{{ $option->value }}"
                        class="form-check-input {{ $invalid }} {{ $question->isPriced() ? 'js-priced-field' : '' }}" {{ (string) $current === (string) $option->value ? 'checked' : '' }} {{ $disabled }}
                        @if ($question->required) required @endif>
                    <label class="form-check-label" for="{{ $name }}_{{ $option->value }}">
                        {!! $label(__($option->translate('label'))) !!}@if ($option->cost !== null) <span class="text-muted">— {{ $def?->format($option->cost) ?? $option->cost }}</span>@endif
                    </label>
                    @if ($option->description)
                        <small class="form-text text-muted">{!! $label($option->translate('description')) !!}</small>
                    @endif
                </div>
            @endforeach
            @break

        @case(QuestionType::YesNo)
            <div class="btn-group js-yesno d-flex flex-wrap iccm-gap" role="group">
                @foreach ($options as $option)
                    <label class="btn btn-outline-primary js-yesno-btn question-yesno-btn {{ (string) $current === (string) $option->value ? 'active' : '' }}">
                        {{-- Invisible but full-sized (not clipped to a point),
                             so it stays a real, independently clickable target
                             the same size as the button — a zero-size hidden
                             input would leave only the label itself clickable. --}}
                        <input type="radio" name="{{ $name }}" value="{{ $option->value }}" class="js-yesno-input question-yesno-input"
                            {{ (string) $current === (string) $option->value ? 'checked' : '' }} {{ $disabled }}
                            @if ($question->required) required @endif>
                        {{ $option->value === Question::YES_VALUE ? __('registration::common.yes') : __('registration::common.no') }}
                    </label>
                @endforeach
            </div>
            @break

        @case(QuestionType::Checkbox)
            @php
                $perOption = data_get($question->config, 'input_name_per_option', false);
                $prefix = data_get($question->config, 'input_name_prefix', '');
                $selected = collect((array) $current)->map('strval');
            @endphp
            @foreach ($options as $option)
                @php
                    $optName = $perOption ? $prefix.$option->value : $name.'[]';
                    $checked = $perOption
                        ? (old($prefix.$option->value) || $selected->contains((string) $option->value))
                        : $selected->contains((string) $option->value);
                @endphp
                <div class="form-check">
                    <input type="checkbox" id="{{ $name }}_{{ $option->value }}" name="{{ $optName }}" value="{{ $perOption ? 1 : $option->value }}"
                        class="form-check-input {{ $question->isPriced() ? 'js-priced-field' : '' }}" {{ $checked ? 'checked' : '' }} {{ $disabled }}>
                    <label class="form-check-label" for="{{ $name }}_{{ $option->value }}">
                        {!! $label(__($option->translate('label'))) !!}@if ($option->cost !== null) <span class="text-muted">— {{ $def?->format($option->cost) ?? $option->cost }}</span>@endif
                    </label>
                    @if ($option->description)
                        <small class="form-text text-muted">{!! $label($option->translate('description')) !!}</small>
                    @endif
                </div>
            @endforeach
            @break

        @default
            {{-- type plus the inputmode/autocomplete hints for mobile keyboards and autofill --}}
            <input @foreach ($question->type->inputAttributes() as $attribute => $value) {{ $attribute }}="{{ $value }}" @endforeach id="{{ $name }}" name="{{ $name }}" value="{{ $current }}"
                class="form-control {{ $invalid }}" placeholder="{{ $vars($question->translate('placeholder')) }}" {{ $disabled }}
                @if ($question->required) required @endif>
    @endswitch

    @if ($question->help_text)
        <small class="form-text text-muted">{!! $label(__($question->translate('help_text'))) !!}</small>
    @endif

    @error($name)
        <span class="invalid-feedback d-block" role="alert"><strong>{{ $message }}</strong></span>
    @enderror
</div>
