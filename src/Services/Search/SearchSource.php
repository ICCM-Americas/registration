<?php

namespace ConferenceTools\Registration\Services\Search;

use Closure;
use ConferenceTools\Registration\Models\ConditionGroup;
use ConferenceTools\Registration\Models\Translation;
use ConferenceTools\Registration\Support\Search\RuleDescriber;
use ConferenceTools\Registration\Support\Search\SearchHit;
use ConferenceTools\Registration\Support\Search\SearchOptions;
use ConferenceTools\Registration\Support\Search\SearchPattern;
use ConferenceTools\Registration\Support\Search\Snippet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/** One area the admin search looks through, producing linked hits for one result category. */
abstract class SearchSource
{
    /** The result category (a key of {@see SearchOptions::CATEGORIES}) this source's hits belong to. */
    abstract public function category(): string;

    /** Whether the search options ask for this source. */
    abstract public function enabled(SearchOptions $options): bool;

    /**
     * Every hit in this source, in display order.
     *
     * @return Collection<int, SearchHit>
     */
    abstract public function search(SearchPattern $pattern, SearchOptions $options): Collection;

    /** A package route URL, honoring the configured name prefix. */
    protected function route(string $name, mixed $parameters = []): string
    {
        return route(config('registration.route_name_prefix').$name, $parameters);
    }

    /** A field's display name for the result's badge. */
    protected function fieldLabel(string $field): string
    {
        return __('registration::admin.search_field_'.$field);
    }

    /**
     * One hit per field whose text matches.
     *
     * @param  array<string, ?string>  $fields  field name (see {@see fieldLabel()}) => text
     * @param  Closure(string, Snippet): SearchHit  $make  builds the hit from the field label and snippet
     * @return Collection<int, SearchHit>
     */
    protected function fieldHits(SearchPattern $pattern, array $fields, Closure $make): Collection
    {
        return collect($fields)
            ->map(fn (?string $text, string $field): ?SearchHit => ($snippet = $pattern->snippet($text))
                ? $make($this->fieldLabel($field), $snippet)
                : null)
            ->filter()
            ->values();
    }

    /**
     * One hit per matching condition in a model's visibility rule.
     *
     * @param  Closure(string, Snippet): SearchHit  $make  builds the hit from the field label and snippet
     * @return Collection<int, SearchHit>
     */
    protected function ruleHits(SearchPattern $pattern, Model $owner, string $field, Closure $make): Collection
    {
        $describer = app(RuleDescriber::class);

        return $owner->conditionGroups
            ->flatMap(fn (ConditionGroup $group): array => $describer->lines($group))
            ->map(fn (string $line): ?SearchHit => ($snippet = $pattern->snippet($line))
                ? $make($this->fieldLabel($field), $snippet)
                : null)
            ->filter()
            ->values();
    }

    /** The relations a model's rule needs eager-loaded, under the given relation path prefix. */
    protected function ruleRelations(string $prefix = ''): array
    {
        return [$prefix.'conditionGroups.conditions.question', $prefix.'conditionGroups.children.conditions.question'];
    }

    /**
     * One hit per matching translation of a model's fields, when the options include translations.
     *
     * @param  string  $prefix  prepended to the translated field's name to pick its label
     * @param  Closure(string, Snippet, string): SearchHit  $make  builds the hit from the field label, snippet and locale
     * @return Collection<int, SearchHit>
     */
    protected function translationHits(SearchPattern $pattern, SearchOptions $options, Model $model, string $prefix, Closure $make): Collection
    {
        if (! $options->translations) {
            return collect();
        }

        return $model->translations
            ->sortBy(['locale', 'field'])
            ->map(fn (Translation $t): ?SearchHit => ($snippet = $pattern->snippet($t->value))
                ? $make($this->fieldLabel($prefix.$t->field), $snippet, $t->locale)
                : null)
            ->filter()
            ->values();
    }
}
