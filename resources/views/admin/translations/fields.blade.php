{{--
    One locale's inputs for every translatable field of every item on the page
    (the entity itself, plus each option when the entity is a question). The
    base-language text is shown under each input for reference; leaving an
    input empty removes that translation (the fallback shows again).

    Expects: $items (Collection of translatable models), $locale (?string —
    null for the "add a language" form, which starts empty).
--}}
@php
    // Literal per-field labels (a concatenated lang key would not be seen by
    // the translation-coverage guard).
    $fieldLabels = [
        'heading' => __('registration::admin.field_heading'),
        'body' => __('registration::admin.field_body'),
        'title' => __('registration::admin.field_title'),
        'description' => __('registration::admin.field_description'),
        'label' => __('registration::admin.field_label'),
        'help_text' => __('registration::admin.field_help_text'),
        'placeholder' => __('registration::admin.field_placeholder'),
        'value' => __('registration::admin.field_value'),
    ];
@endphp
@foreach ($items as $item)
    @php($key = $item instanceof \ConferenceTools\Registration\Models\QuestionOption ? 'option-'.$item->id : 'self')

    @if ($key !== 'self')
        <h6 class="mt-3">{{ __('registration::admin.translations_option', ['value' => $item->value]) }}</h6>
    @endif

    @foreach ($item->translatableFields() as $field)
        @php($base = $item->getAttribute($field))
        @php($current = $locale === null ? null : $item->translations->where('field', $field)->firstWhere('locale', $locale)?->value)

        {{-- Nothing to translate: no base text and no stored translation. --}}
        @continue(($base === null || $base === '') && $item->translations->where('field', $field)->isEmpty())

        <div class="form-group">
            <label for="{{ $key }}-{{ $field }}-{{ $locale ?? 'new' }}">{{ $fieldLabels[$field] }}</label>
            @if (in_array($field, ['body', 'description', 'help_text'], true))
                <textarea id="{{ $key }}-{{ $field }}-{{ $locale ?? 'new' }}" name="texts[{{ $key }}][{{ $field }}]" rows="3" class="form-control form-control-sm">{{ $current }}</textarea>
            @else
                <input type="text" id="{{ $key }}-{{ $field }}-{{ $locale ?? 'new' }}" name="texts[{{ $key }}][{{ $field }}]" value="{{ $current }}" class="form-control form-control-sm">
            @endif
            @if ($base !== null && $base !== '')
                <small class="form-text text-muted">{{ $base }}</small>
            @endif
        </div>
    @endforeach
@endforeach
