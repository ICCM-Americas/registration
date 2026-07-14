<?php

namespace ConferenceTools\Registration\Http\Controllers;

use ConferenceTools\Registration\Services\CsvExport;
use ConferenceTools\Registration\Services\CsvFormula;
use ConferenceTools\Registration\Services\CsvZipExport;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Base class for the package's controllers: route naming and the shared report/CSV helpers. */
abstract class Controller extends BaseController
{
    use AuthorizesRequests, ValidatesRequests;

    /** Fully-qualified package route name, honoring the configured name prefix. */
    protected function routeName(string $name): string
    {
        return config('registration.route_name_prefix').$name;
    }

    /** The PDF-export paper size to print easily on: US letter for a US locale, A4 otherwise. */
    protected function pdfPaperSize(): string
    {
        return $this->isUsLocale() ? 'letter' : 'a4';
    }

    /** The label every report's final "Count" summary row/cell carries. */
    protected function countLabel(): string
    {
        return __('registration::admin.report_count_label');
    }

    /**
     * Append every CSV export's summary footer: a blank separator row, then
     * the "Count" label paired with a formula that counts the data rows
     * above it. The formula is anchored to its own row (=ROW()-3: minus the
     * header row, the blank separator, and its own row) rather than a fixed
     * range, so it keeps counting correctly however many rows a spreadsheet
     * user inserts or deletes above it after opening the file.
     */
    protected function appendCsvCount(iterable $rows): Collection
    {
        return collect($rows)->push([])->push([$this->countLabel(), new CsvFormula('=ROW()-3')]);
    }

    /**
     * Stream a report CSV named "<stem>-<timestamp>.csv" with the standard
     * count footer appended.
     *
     * @param  string  $stem  filename prefix identifying the report
     * @param  array<int, mixed>  $head  header row
     * @param  iterable  $rows  data rows, without the count footer
     */
    protected function downloadCsv(CsvExport $exporter, string $stem, array $head, iterable $rows): StreamedResponse
    {
        return $exporter->download(
            $stem.'-'.now()->format('Ymd-His').'.csv',
            $head,
            $this->appendCsvCount($rows),
        );
    }

    /**
     * The final row a report's on-screen table/PDF list ends with: the
     * "Count" label, then the total spanning whatever other columns the
     * table has. A single-column report (nothing left to span) instead
     * folds both into one cell, "Count: N".
     */
    protected function countPdfRow(int $count, int $columns): array
    {
        if ($columns <= 1) {
            return [$this->countLabel().': '.$count];
        }

        return [$this->countLabel(), ['content' => $count, 'colSpan' => $columns - 1]];
    }

    /**
     * Stream a .ZIP named "<stem>-<timestamp>.zip" containing one CSV per
     * sheet, each with the standard count footer appended.
     *
     * @param  array<string, array{0: array, 1: iterable}>  $sheets  sheet name (no .csv) => [header row, data rows]
     */
    protected function downloadCsvZip(CsvZipExport $exporter, string $stem, array $sheets): BinaryFileResponse
    {
        $withCounts = collect($sheets)
            ->map(fn (array $sheet): array => [$sheet[0], $this->appendCsvCount($sheet[1])])
            ->all();

        return $exporter->download($stem.'-'.now()->format('Ymd-His').'.zip', $withCounts);
    }

    /** Whether the current app locale is a US one (bare "en" is this app's US-English default). */
    protected function isUsLocale(): bool
    {
        $parts = explode('-', str_replace('_', '-', app()->getLocale()));

        return isset($parts[1]) ? strtoupper($parts[1]) === 'US' : strtolower($parts[0]) === 'en';
    }
}
