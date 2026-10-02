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

/** The visibility rules of sections, questions, and options, each condition read as a sentence. */
class QuestionRuleSource extends QuestionSource
{
    /** {@inheritDoc} */
    public function enabled(SearchOptions $options): bool
    {
        return $options->has(SearchOptions::QUESTION_RULES);
    }

    /** {@inheritDoc} */
    public function search(SearchPattern $pattern, SearchOptions $options): Collection
    {
        $with = [
            ...$this->ruleRelations(),
            ...$this->ruleRelations('questions.'),
            ...$this->ruleRelations('questions.options.'),
        ];

        return $this->sections($with)
            ->flatMap(fn (Section $section): Collection => $this->sectionHits($pattern, $section)
                ->concat($section->questions->flatMap(fn (Question $question): Collection => $this->questionHits($pattern, $question, $section))))
            ->values();
    }

    /** @return Collection<int, SearchHit> */
    private function sectionHits(SearchPattern $pattern, Section $section): Collection
    {
        return $this->ruleHits($pattern, $section, 'section_rule', fn (string $field, Snippet $snippet): SearchHit => new SearchHit(
            title: $section->title,
            field: $field,
            snippet: $snippet,
            url: $this->route('admin.sections.visibility', $section),
            modal: true,
            key: $section->key,
            context: __('registration::admin.scope_'.$section->scope->value),
            links: [['label' => __('registration::admin.search_open_console'), 'url' => $this->route('admin.questions').'#section-'.$section->id, 'modal' => false]],
        ));
    }

    /** A question's own rule hits, then its options'. */
    private function questionHits(SearchPattern $pattern, Question $question, Section $section): Collection
    {
        $edit = [['label' => __('registration::admin.search_edit_question'), 'url' => $this->route('admin.questions.edit', $question), 'modal' => false]];

        return $this->ruleHits($pattern, $question, 'question_rule', fn (string $field, Snippet $snippet): SearchHit => new SearchHit(
            title: $this->questionTitle($question),
            field: $field,
            snippet: $snippet,
            url: $this->route('admin.questions.visibility', $question),
            modal: true,
            key: $question->key,
            context: $this->sectionContext($section),
            links: $edit,
        ))->concat($question->options->flatMap(fn (QuestionOption $option): Collection => $this->ruleHits($pattern, $option, 'option_rule', fn (string $field, Snippet $snippet): SearchHit => new SearchHit(
            title: $this->questionTitle($question),
            field: $field,
            snippet: $snippet,
            url: $this->route('admin.options.visibility', $option),
            modal: true,
            key: $question->key,
            context: $this->sectionContext($section).' › '.$option->label,
            links: $edit,
        ))));
    }
}
