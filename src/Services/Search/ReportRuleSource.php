<?php

namespace ConferenceTools\Registration\Services\Search;

use ConferenceTools\Registration\Models\Report;
use ConferenceTools\Registration\Models\ReportColumn;
use ConferenceTools\Registration\Support\Search\SearchHit;
use ConferenceTools\Registration\Support\Search\SearchOptions;
use ConferenceTools\Registration\Support\Search\SearchPattern;
use ConferenceTools\Registration\Support\Search\Snippet;
use Illuminate\Support\Collection;

/** A report's row rule and its columns' cell rules, each condition read as a sentence. */
class ReportRuleSource extends ReportSource
{
    /** {@inheritDoc} */
    public function enabled(SearchOptions $options): bool
    {
        return $options->has(SearchOptions::REPORT_RULES);
    }

    /** {@inheritDoc} */
    public function search(SearchPattern $pattern, SearchOptions $options): Collection
    {
        return $this->reports([...$this->ruleRelations(), 'columns.question', ...$this->ruleRelations('columns.')])
            ->flatMap(fn (Report $report): Collection => $this->ruleHits($pattern, $report, 'report_rule', fn (string $field, Snippet $snippet): SearchHit => new SearchHit(
                title: $report->name,
                field: $field,
                snippet: $snippet,
                url: $this->route('admin.reports.visibility', $report),
                modal: true,
                links: $this->editLink($report),
            ))->concat($report->columns->flatMap(fn (ReportColumn $column): Collection => $this->ruleHits($pattern, $column, 'column_rule', fn (string $field, Snippet $snippet): SearchHit => new SearchHit(
                title: $report->name,
                field: $field,
                snippet: $snippet,
                url: $this->route('admin.report_columns.visibility', $column),
                modal: true,
                context: $column->heading(),
                links: $this->editLink($report),
            )))))
            ->values();
    }
}
