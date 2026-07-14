<?php

namespace ConferenceTools\Registration\Services;

use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

/**
 * Bundles several named CSV sheets into one .ZIP download — used for exports
 * spanning more than one table (registrations, archive), where a single CSV
 * has nowhere to put a second table. Reuses {@see CsvExport}'s CSV-writing
 * and formula-injection sanitizing for every sheet.
 */
class CsvZipExport
{
    public function __construct(private CsvExport $csv) {}

    /**
     * @param  array<string, array{0: list<string>, 1: iterable<list<mixed>>}>  $sheets  sheet name (no .csv) => [headers, rows]
     */
    public function download(string $filename, array $sheets): BinaryFileResponse
    {
        $path = tempnam(sys_get_temp_dir(), 'export');

        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);

        foreach ($sheets as $name => [$headers, $rows]) {
            $zip->addFromString($name.'.csv', $this->csv->toString($headers, $rows));
        }

        $zip->close();

        return response()->download($path, $filename)->deleteFileAfterSend();
    }
}
