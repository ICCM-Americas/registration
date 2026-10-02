<?php

namespace ConferenceTools\Registration\Services;

use ConferenceTools\Registration\Services\Search\AnswerSource;
use ConferenceTools\Registration\Services\Search\DraftSource;
use ConferenceTools\Registration\Services\Search\OptionSource;
use ConferenceTools\Registration\Services\Search\QuestionRuleSource;
use ConferenceTools\Registration\Services\Search\QuestionTextSource;
use ConferenceTools\Registration\Services\Search\ReportColumnSource;
use ConferenceTools\Registration\Services\Search\ReportRuleSource;
use ConferenceTools\Registration\Services\Search\ReportTextSource;
use ConferenceTools\Registration\Services\Search\SearchSource;
use ConferenceTools\Registration\Support\Search\SearchHit;
use ConferenceTools\Registration\Support\Search\SearchOptions;
use ConferenceTools\Registration\Support\Search\SearchTarget;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/** The admin Search page's engine: runs the checked sources and pages each result category. */
class AdminSearch
{
    /** Hits shown per page of each category. */
    public const PER_PAGE = 25;

    /** The sources, in display order within their categories. */
    private const SOURCES = [
        QuestionTextSource::class, OptionSource::class, QuestionRuleSource::class,
        ReportTextSource::class, ReportColumnSource::class, ReportRuleSource::class,
        AnswerSource::class, DraftSource::class,
    ];

    /**
     * Every hit of each category with at least one checked place, keyed by category.
     *
     * @return array<string, Collection<int, SearchHit>>
     */
    public function search(SearchOptions $options): array
    {
        $pattern = $options->pattern();
        $results = [];

        foreach ($this->sources($options) as $source) {
            $results[$source->category()] = ($results[$source->category()] ?? collect())
                ->concat($source->search($pattern, $options));
        }

        return $results;
    }

    /**
     * The search's hits, one page per category, each paged by its own "{category}_page" query parameter.
     *
     * @return array<string, LengthAwarePaginator>
     */
    public function paginate(SearchOptions $options, callable $page): array
    {
        return collect($this->search($options))->map(function (Collection $hits, string $category) use ($page): LengthAwarePaginator {
            $pageName = $category.'_page';
            $current = max(1, (int) $page($pageName));

            return (new LengthAwarePaginator($hits->forPage($current, self::PER_PAGE)->values(), $hits->count(), self::PER_PAGE, $current, [
                'path' => LengthAwarePaginator::resolveCurrentPath(),
                'pageName' => $pageName,
            ]))->withQueryString();
        })->all();
    }

    /**
     * The distinct registrations behind every answer hit, across all pages.
     *
     * @return Collection<int, SearchTarget>
     */
    public function answerTargets(SearchOptions $options): Collection
    {
        return collect($this->search($options)['answers'] ?? [])
            ->map(fn (SearchHit $hit): SearchTarget => $hit->target)
            ->unique(fn (SearchTarget $target): string => $target->encode())
            ->values();
    }

    /**
     * The checked sources.
     *
     * @return list<SearchSource>
     */
    private function sources(SearchOptions $options): array
    {
        return array_values(array_filter(
            array_map(fn (string $class): SearchSource => app($class), self::SOURCES),
            fn (SearchSource $source): bool => $source->enabled($options),
        ));
    }
}
