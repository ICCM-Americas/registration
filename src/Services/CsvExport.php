<?php

namespace ConferenceTools\Registration\Services;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a CSV download for any of the reports (badges, directory, arrivals,
 * shuttles, photos): a UTF-8 BOM for spreadsheet compatibility (Excel), and
 * cells sanitized against formula injection, matching the convention already
 * used by the bof-scheduler package's SuggestionCsvExporter.
 */
class CsvExport
{
    /**
     * @param  list<string>  $headers
     * @param  iterable<list<mixed>>  $rows
     */
    public function download(string $filename, array $headers, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows) {
            echo $this->toString($headers, $rows);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * One sheet's CSV bytes: a UTF-8 BOM, the header row, then sanitized data
     * rows — used directly by {@see download()}, and by {@see CsvZipExport}
     * to bundle several sheets into one .ZIP.
     *
     * @param  list<string>  $headers
     * @param  iterable<list<mixed>>  $rows
     */
    public function toString(array $headers, iterable $rows): string
    {
        $handle = fopen('php://temp', 'r+');

        fwrite($handle, "\xEF\xBB\xBF");

        fputcsv($handle, $headers);

        foreach ($rows as $row) {
            fputcsv($handle, array_map($this->sanitize(...), $row));
        }

        rewind($handle);
        $contents = stream_get_contents($handle);
        fclose($handle);

        return $contents;
    }

    /**
     * Neutralize leading characters that spreadsheet software may interpret
     * as a formula — except a {@see CsvFormula}, an app-authored formula
     * (e.g. the reports' Count row) that is meant to be evaluated.
     */
    private function sanitize(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof CsvFormula) {
            return (string) $value;
        }

        $value = (string) $value;

        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$value;
        }

        return $value;
    }
}
