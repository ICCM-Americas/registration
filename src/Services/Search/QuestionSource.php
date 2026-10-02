<?php

namespace ConferenceTools\Registration\Services\Search;

use ConferenceTools\Registration\Enums\QuestionScope;
use ConferenceTools\Registration\Models\Question;
use ConferenceTools\Registration\Models\Section;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/** A search source over the questionnaire, walked in the question console's order. */
abstract class QuestionSource extends SearchSource
{
    /** {@inheritDoc} */
    public function category(): string
    {
        return 'questions';
    }

    /**
     * Every section with the given relations, grouped by scope as the console lists them, then by position.
     *
     * @return Collection<int, Section>
     */
    protected function sections(array $with): Collection
    {
        $scopes = array_flip(QuestionScope::values());

        return Section::with($with)->orderBy('position')->get()
            ->sortBy(fn (Section $section): int => $scopes[$section->scope->value])
            ->values();
    }

    /** The breadcrumb naming a section: its scope's heading, then its title. */
    protected function sectionContext(Section $section): string
    {
        return __('registration::admin.scope_'.$section->scope->value).' › '.$section->title;
    }

    /** A question's label, shortened for a result title. */
    protected function questionTitle(Question $question): string
    {
        return Str::limit($question->label, 100);
    }
}
