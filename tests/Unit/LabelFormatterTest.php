<?php

namespace ConferenceTools\Registration\Tests\Unit;

use ConferenceTools\Registration\Support\LabelFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** Unit tests for LabelFormatter. */
#[TestDox('LabelFormatter')]
class LabelFormatterTest extends TestCase
{
    /**
     * @return iterable<string, array{?string, string}>
     */
    public static function formatCases(): iterable
    {
        yield 'null renders as empty' => [null, ''];
        yield 'empty renders as empty' => ['', ''];
        yield 'plain text is unchanged' => ['Your name', 'Your name'];

        yield 'raw HTML is escaped, not emitted' => ['<b onclick="x">hi</b>', '&lt;b onclick=&quot;x&quot;&gt;hi&lt;/b&gt;'];
        yield 'ampersand is escaped' => ['Tom & Jerry', 'Tom &amp; Jerry'];

        yield 'newline becomes a break' => ["a\nb", "a<br />\nb"];
        yield 'bold' => ['**loud**', '<b>loud</b>'];
        yield 'italics' => ['_soft_', '<i>soft</i>'];
        yield 'bold then italics nests in order' => ['**_both_**', '<b><i>both</i></b>'];
        yield 'italics then bold nests in order' => ['_**both**_', '<i><b>both</b></i>'];

        yield 'markers and a break together' => ["**Name**\n_required_", "<b>Name</b><br />\n<i>required</i>"];
        yield 'unbalanced marker renders as itself' => ['a ** b', 'a ** b'];
    }

    #[Test]
    #[TestDox('renders labels as safe HTML with newline, bold and italic markers')]
    #[DataProvider('formatCases')]
    public function formats_label(?string $text, string $expected): void
    {
        $this->assertSame($expected, LabelFormatter::format($text));
    }
}
