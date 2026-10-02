<?php

namespace ConferenceTools\Registration\Services\Search;

use ConferenceTools\Registration\Models\Report;
use ConferenceTools\Registration\Models\ReportColumn;
use ConferenceTools\Registration\Support\Search\SearchHit;
use ConferenceTools\Registration\Support\Search\SearchOptions;
use ConferenceTools\Registration\Support\Search\SearchPattern;
use Illuminate\Support\Collection;

/** A report column's admin-entered texts: its header override and its custom-value mapping entries. */
class ReportColumnSource extends ReportSource
{
    /** {@inheritDoc} */
    public function enabled(SearchOptions $options): bool
    {
        return $options->has(SearchOptions::REPORT_COLUMNS);
    }

    /** {@inheritDoc} */
    public function search(SearchPattern $pattern, SearchOptions $options): Collection
    {
        return $this->reports(['columns.question'])
            ->flatMap(fn (Report $report): Collection => $report->columns
                ->flatMap(fn (ReportColumn $column): Collection => $this->headerHits($pattern, $report, $column)
                    ->concat($this->mappingHits($pattern, $report, $column))))
            ->values();
    }

    /** @return Collection<int, SearchHit> */
    private function headerHits(SearchPattern $pattern, Report $report, ReportColumn $column): Collection
    {
        $snippet = $pattern->snippet($column->header);

        return collect($snippet ? [new SearchHit(
            title: $report->name,
            field: $this->fieldLabel('column_header'),
            snippet: $snippet,
            url: $this->editUrl($report).'#column-'.$column->id,
        )] : []);
    }

    /** One hit per matching value or text of a mapping entry. */
    private function mappingHits(SearchPattern $pattern, Report $report, ReportColumn $column): Collection
    {
        return collect($column->mapping ?? [])
            ->flatMap(fn (array $entry): array => [
                ['mapping_value', $entry['value'] ?? null],
                ['mapping_text', $entry['text'] ?? null],
            ])
            ->map(fn (array $field): ?SearchHit => ($snippet = $pattern->snippet($field[1])) ? new SearchHit(
                title: $report->name,
                field: $this->fieldLabel($field[0]),
                snippet: $snippet,
                url: $this->route('admin.report_columns.mapping', $column),
                modal: true,
                context: $column->heading(),
                links: $this->editLink($report),
            ) : null)
            ->filter()
            ->values();
    }
}
