{{--
    Renders one section as a card of its questions. Used by the single-page
    add-participant form; the registrant wizard renders its steps separately.

    Expects: $section, $answers, $evaluator, $def.
--}}
<div class="card mb-4" data-section="{{ $section->key }}">
    <div class="card-header">{{ __($section->translate('title')) }}</div>

    <div class="card-body">
        @if ($section->description)
            <p class="text-muted">{{ __($section->translate('description')) }}</p>
        @endif

        @foreach ($section->questions as $question)
            @include('registration::questions.question', compact('question', 'answers', 'evaluator', 'def'))
        @endforeach
    </div>
</div>
