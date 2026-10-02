<?php

namespace ConferenceTools\Registration\Services\Search;

use ConferenceTools\Registration\Models\Report;
use Illuminate\Support\Collection;

/** A search source over the admin-defined reports, in the reports list's order. */
abstract class ReportSource extends SearchSource
{
    /** {@inheritDoc} */
    public function category(): string
    {
        return 'reports';
    }

    /**
     * Every report with the given relations, in display order.
     *
     * @return Collection<int, Report>
     */
    protected function reports(array $with): Collection
    {
        return Report::with($with)->orderBy('position')->get();
    }

    /** The URL of a report's edit page. */
    protected function editUrl(Report $report): string
    {
        return $this->route('admin.reports.edit', $report);
    }

    /**
     * The secondary link to a report's edit page.
     *
     * @return list<array{label: string, url: string, modal: bool}>
     */
    protected function editLink(Report $report): array
    {
        return [['label' => __('registration::admin.search_edit_report'), 'url' => $this->editUrl($report), 'modal' => false]];
    }
}
