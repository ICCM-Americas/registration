<?php

namespace ConferenceTools\Registration\Services\Search;

use ConferenceTools\Registration\Models\Question;
use ConferenceTools\Registration\Models\QuestionOption;
use ConferenceTools\Registration\Models\Section;
use ConferenceTools\Registration\Support\Search\SearchHit;
use ConferenceTools\Registration\Support\Search\SearchOptions;
use ConferenceTools\Registration\Support\Search\SearchPattern;
use ConferenceTools\Registration\Support\Search\Snippet;
use Illuminate\Support\Collection;

/** A choice question's options (and their translations): values, labels, descriptions. */
class OptionSource extends QuestionSource
{
    /** {@inheritDoc} */
    public function enabled(SearchOptions $options): bool
    {
        return $options->has(SearchOptions::OPTIONS);
    }

    /** {@inheritDoc} */
    public function search(SearchPattern $pattern, SearchOptions $options): Collection
    {
        return $this->sections(['questions.options.translations'])
            ->flatMap(fn (Section $section): Collection => $section->questions
                ->flatMap(fn (Question $question): Collection => $question->options
                    ->flatMap(fn (QuestionOption $option): Collection => $this->optionHits($pattern, $options, $option, $question, $section))))
            ->values();
    }

    /** @return Collection<int, SearchHit> */
    private function optionHits(SearchPattern $pattern, SearchOptions $options, QuestionOption $option, Question $question, Section $section): Collection
    {
        // Option translations are edited inline on the question's own form.
        $make = fn (string $field, Snippet $snippet, ?string $locale = null): SearchHit => new SearchHit(
            title: $this->questionTitle($question),
            field: $field,
            snippet: $snippet,
            url: $this->route('admin.questions.edit', $question).'#option-'.$option->id,
            key: $question->key,
            context: $this->sectionContext($section),
            locale: $locale,
        );

        return $this->fieldHits($pattern, [
            'option_value' => $option->value,
            'option_label' => $option->label,
            'option_description' => $option->description,
        ], $make)->concat($this->translationHits($pattern, $options, $option, 'option_', $make));
    }
}
