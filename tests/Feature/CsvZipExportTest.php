<?php

namespace ConferenceTools\Registration\Tests\Feature;

use ConferenceTools\Registration\Services\CsvZipExport;
use ConferenceTools\Registration\Tests\TestCase;
use PHPUnit\Framework\Attributes\TestDox;
use ZipArchive;

/**
 * Bundles several named CSV sheets into one .ZIP download, reusing CsvExport's
 * writing/sanitizing for every sheet.
 */
#[TestDox('Csv Zip Export')]
class CsvZipExportTest extends TestCase
{
    #[TestDox('the download is a .ZIP containing one CSV per sheet, each with its own headers and rows')]
    public function test_the_download_is_a_zip_containing_one_csv_per_sheet(): void
    {
        // Values chosen with no embedded spaces: PHP's fputcsv quoting rules
        // (which differ across PHP versions) aren't this test's concern —
        // that's CsvExport::sanitize()'s, unchanged by this refactor. This
        // test only proves the .ZIP bundles each sheet under its own name.
        $response = app(CsvZipExport::class)->download('export.zip', [
            'answers' => [['Name', 'Email'], [['Ada', 'ada@example.com']]],
            'groups' => [['GroupName', 'Members'], [['TheEngines', 'Ada']]],
        ]);

        $zip = new ZipArchive;
        $zip->open($response->getFile()->getPathname());

        $this->assertSame(2, $zip->numFiles);
        $this->assertSame("Name,Email\nAda,ada@example.com\n", $this->stripBom($zip->getFromName('answers.csv')));
        $this->assertSame("GroupName,Members\nTheEngines,Ada\n", $this->stripBom($zip->getFromName('groups.csv')));

        $zip->close();
    }

    /** The BOM CsvExport writes ahead of every sheet's content, stripped for a plain-text comparison. */
    private function stripBom(string $contents): string
    {
        return str_starts_with($contents, "\xEF\xBB\xBF") ? substr($contents, 3) : $contents;
    }
}
