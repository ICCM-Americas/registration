<?php

namespace ConferenceTools\Registration\Tests\Unit;

use ConferenceTools\Registration\Services\CsvExport;
use ConferenceTools\Registration\Services\CsvFormula;
use ConferenceTools\Registration\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;

/** Unit tests for Csv Export. */
#[TestDox('Csv Export')]
class CsvExportTest extends TestCase
{
    /** The page's response body as a string. */
    private function content(array $headers, iterable $rows): string
    {
        $response = app(CsvExport::class)->download('export.csv', $headers, $rows);

        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    }

    #[TestDox('emits a utf8 bom and the header row')]
    public function test_emits_a_utf8_bom_and_the_header_row(): void
    {
        $csv = $this->content(['Name', 'Organization'], [['Ada', 'Analytical Engines']]);

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('Name,Organization', $csv);
        $this->assertStringContainsString('Ada', $csv);
        $this->assertStringContainsString('Analytical Engines', $csv);
    }

    #[TestDox('sets the csv content type and filename')]
    public function test_sets_the_csv_content_type_and_filename(): void
    {
        $response = app(CsvExport::class)->download('badges-20260705.csv', ['Name'], [['Ada']]);

        $this->assertSame('text/csv; charset=UTF-8', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('badges-20260705.csv', (string) $response->headers->get('Content-Disposition'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function dangerousPrefixes(): iterable
    {
        yield 'equals' => ['=cmd'];
        yield 'plus' => ['+cmd'];
        yield 'minus' => ['-cmd'];
        yield 'at' => ['@cmd'];
        yield 'tab' => ["\tcmd"];
        yield 'carriage return' => ["\rcmd"];
    }

    #[DataProvider('dangerousPrefixes')]
    #[TestDox('sanitizes formula injection in cell values')]
    public function test_sanitizes_formula_injection_in_cell_values(string $value): void
    {
        $csv = $this->content(['Name'], [[$value]]);

        // The value is prefixed with a quote so spreadsheets treat it as text.
        $this->assertStringContainsString("'".$value, $csv);
    }

    #[TestDox('null cells render empty')]
    public function test_null_cells_render_empty(): void
    {
        $csv = $this->content(['Name', 'Email'], [['Ada', null]]);

        $this->assertStringContainsString("Ada,\n", str_replace("\r\n", "\n", $csv));
    }

    #[TestDox('a csv formula cell is emitted unquoted unlike a plain leading equals value')]
    public function test_a_csv_formula_cell_is_emitted_unquoted_unlike_a_plain_leading_equals_value(): void
    {
        $csv = $this->content(['Name', 'Count'], [['Count', new CsvFormula('=ROW()-3')]]);

        $this->assertStringContainsString('Count,=ROW()-3', $csv);
        $this->assertStringNotContainsString("'=ROW()-3", $csv);
    }
}
