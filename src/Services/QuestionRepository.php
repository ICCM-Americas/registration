<?php

namespace ConferenceTools\Registration\Services;

use ConferenceTools\Registration\Enums\QuestionScope;
use ConferenceTools\Registration\Models\Question;
use ConferenceTools\Registration\Models\Section;
use Illuminate\Support\Collection;

/**
 * Loads the configured form for a scope: its enabled sections (wizard steps) in
 * order, each with its enabled questions, their options and their visibility
 * rule trees eager-loaded. Centralizing the load here keeps the validator, the
 * answer store and the renderer reading the same shape.
 */
class QuestionRepository
{
    /** Eager-load paths for a question's options, translations and (a few levels of) its rule tree. */
    private const QUESTION_WITH = [
        'options.translations',
        // Options can carry their own visibility rules (conditionally-offered
        // options), loaded to the same nesting depth as a section's rule.
        'options.conditionGroups.conditions.question',
        'options.conditionGroups.children.conditions.question',
        'translations',
        'conditionGroups.conditions.question',
        'conditionGroups.children.conditions.question',
        'conditionGroups.children.children.conditions.question',
    ];

    /**
     * Enabled sections for the scope, ordered, with their enabled questions
     * (ordered) and each question's options and rules.
     *
     * @return Collection<int, Section>
     */
    public function sectionsForScope(QuestionScope $scope): Collection
    {
        return Section::forScope($scope)
            ->where('enabled', true)
            // The "enabled" constraint must be set in the same with() call as
            // the nested sub-relations below — registering them via a
            // separate with('questions.*') call would re-register the
            // "questions" key unconstrained, silently dropping the filter.
            ->with(['questions' => fn ($q) => $q->where('enabled', true)->with(self::QUESTION_WITH)])
            // The section's own visibility rule (a section can be conditional too)
            // and title/description translations.
            ->with([
                'translations',
                'conditionGroups.conditions.question',
                'conditionGroups.children.conditions.question',
            ])
            ->get();
    }

    /**
     * All enabled questions for the scope, flattened across sections in display
     * order (section order, then question order within each section).
     *
     * @return Collection<int, Question>
     */
    public function questionsForScope(QuestionScope $scope): Collection
    {
        return $this->sectionsForScope($scope)
            ->flatMap(fn (Section $section) => $section->questions)
            ->values();
    }

    /**
     * Canonicalize raw form input into a map of question key => answer, so the
     * rest of the pipeline (validation, visibility, storage) reads every answer
     * by its question key regardless of how the field was named in the form.
     *
     * Choice questions that render one input per option (config
     * input_name_per_option — e.g. the legacy "product_{id}" checkboxes) are
     * collapsed into a single array of the selected option values under the
     * question key.
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    public function normalizeInput(QuestionScope $scope, array $raw): array
    {
        $data = $raw;

        foreach ($this->questionsForScope($scope) as $question) {
            if (! data_get($question->config, 'input_name_per_option')) {
                continue;
            }

            $prefix = (string) data_get($question->config, 'input_name_prefix', '');
            $data[$question->key] = $question->options
                ->filter(fn ($option) => ! empty($raw[$prefix.$option->value]))
                ->map(fn ($option) => (string) $option->value)
                ->values()
                ->all();
        }

        return $data;
    }
}
