<?php

namespace ConferenceTools\Registration\Services\Search;

use ConferenceTools\Registration\Models\Report;
use ConferenceTools\Registration\Support\Search\SearchHit;
use ConferenceTools\Registration\Support\Search\SearchOptions;
use ConferenceTools\Registration\Support\Search\SearchPattern;
use ConferenceTools\Registration\Support\Search\Snippet;
use Illuminate\Support\Collection;

/** A report's own texts: name, description, header, footer. */
class ReportTextSource extends ReportSource
{
    /** {@inheritDoc} */
    public function enabled(SearchOptions $options): bool
    {
        return $options->has(SearchOptions::REPORT_TEXT);
    }

    /** {@inheritDoc} */
    public function search(SearchPattern $pattern, SearchOptions $options): Collection
    {
        return $this->reports([])->flatMap(fn (Report $report): Collection => $this->fieldHits($pattern, [
            'report_name' => $report->name,
            'report_description' => $report->description,
            'report_header' => $report->header,
            'report_footer' => $report->footer,
        ], fn (string $field, Snippet $snippet): SearchHit => new SearchHit(
            title: $report->name,
            field: $field,
            snippet: $snippet,
            url: $this->editUrl($report),
        )))->values();
    }
}
