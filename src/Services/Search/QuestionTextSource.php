<?php

namespace ConferenceTools\Registration\Services\Search;

use ConferenceTools\Registration\Models\Question;
use ConferenceTools\Registration\Models\Section;
use ConferenceTools\Registration\Support\Search\SearchHit;
use ConferenceTools\Registration\Support\Search\SearchOptions;
use ConferenceTools\Registration\Support\Search\SearchPattern;
use ConferenceTools\Registration\Support\Search\Snippet;
use Illuminate\Support\Collection;

/** Section and question texts (and their translations): titles, labels, keys, help texts, placeholders. */
class QuestionTextSource extends QuestionSource
{
    /** {@inheritDoc} */
    public function enabled(SearchOptions $options): bool
    {
        return $options->has(SearchOptions::QUESTION_TEXT);
    }

    /** {@inheritDoc} */
    public function search(SearchPattern $pattern, SearchOptions $options): Collection
    {
        return $this->sections(['translations', 'questions.translations'])
            ->flatMap(fn (Section $section): Collection => $this->sectionHits($pattern, $options, $section)
                ->concat($section->questions->flatMap(fn (Question $question): Collection => $this->questionHits($pattern, $options, $question, $section))))
            ->values();
    }

    /** @return Collection<int, SearchHit> */
    private function sectionHits(SearchPattern $pattern, SearchOptions $options, Section $section): Collection
    {
        $url = $this->route('admin.questions').'#section-'.$section->id;
        $translationsUrl = $this->route('admin.translations', ['section', $section->id]);
        $make = fn (string $field, Snippet $snippet, ?string $locale = null): SearchHit => new SearchHit(
            title: $section->title,
            field: $field,
            snippet: $snippet,
            url: $locale ? $translationsUrl : $url,
            modal: $locale !== null,
            key: $section->key,
            context: __('registration::admin.scope_'.$section->scope->value),
            locale: $locale,
            links: $locale ? [['label' => __('registration::admin.search_open_console'), 'url' => $url, 'modal' => false]] : [],
        );

        return $this->fieldHits($pattern, [
            'section_title' => $section->title,
            'section_key' => $section->key,
            'section_description' => $section->description,
        ], $make)->concat($this->translationHits($pattern, $options, $section, 'section_', $make));
    }

    /** @return Collection<int, SearchHit> */
    private function questionHits(SearchPattern $pattern, SearchOptions $options, Question $question, Section $section): Collection
    {
        $url = $this->route('admin.questions.edit', $question);
        $translationsUrl = $this->route('admin.translations', ['question', $question->id]);
        $make = fn (string $field, Snippet $snippet, ?string $locale = null): SearchHit => new SearchHit(
            title: $this->questionTitle($question),
            field: $field,
            snippet: $snippet,
            url: $locale ? $translationsUrl : $url,
            modal: $locale !== null,
            key: $question->key,
            context: $this->sectionContext($section),
            locale: $locale,
            links: $locale ? [['label' => __('registration::admin.search_edit_question'), 'url' => $url, 'modal' => false]] : [],
        );

        return $this->fieldHits($pattern, [
            'label' => $question->label,
            'key' => $question->key,
            'help_text' => $question->help_text,
            'placeholder' => $question->placeholder,
        ], $make)->concat($this->translationHits($pattern, $options, $question, '', $make));
    }
}
