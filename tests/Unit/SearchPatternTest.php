<?php

namespace ConferenceTools\Registration\Tests\Unit;

use ConferenceTools\Registration\Support\Search\InvalidSearchPattern;
use ConferenceTools\Registration\Support\Search\SearchPattern;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** Compiling the admin search term and rendering bolded snippets of matched text. */
#[TestDox('Search Pattern')]
class SearchPatternTest extends TestCase
{
    /** Compiled pattern, text, and how many matches it has. */
    public static function matchCases(): array
    {
        return [
            'literal, any case' => [SearchPattern::compile('veg', false, false), 'Vegan VEGETARIAN veg', 3],
            'literal, case sensitive' => [SearchPattern::compile('veg', false, true), 'Vegan VEGETARIAN veg', 1],
            'metacharacters literal when regex is off' => [SearchPattern::compile('a.c', false, false), 'abc a.c', 1],
            'regex' => [SearchPattern::compile('^d\w+t$', true, false), 'Diet', 1],
            'regex, case sensitive' => [SearchPattern::compile('^d\w+t$', true, true), 'Diet', 0],
            'zero-length matches ignored' => [SearchPattern::compile('x*', true, false), 'abc', 0],
            'tilde in a regex' => [SearchPattern::compile('a~b', true, false), 'a~b', 1],
            'multibyte, any case' => [SearchPattern::compile('ÉTÉ', false, false), 'un été', 1],
            'null text' => [SearchPattern::compile('a', false, false), null, 0],
            'empty text' => [SearchPattern::compile('a', false, false), '', 0],
        ];
    }

    #[DataProvider('matchCases')]
    #[TestDox('matches $_dataName')]
    public function test_matches(SearchPattern $pattern, ?string $text, int $expected): void
    {
        $this->assertCount($expected, $pattern->matches($text));
    }

    #[TestDox('an invalid regular expression is refused at compile time')]
    public function test_an_invalid_regular_expression_is_refused_at_compile_time(): void
    {
        $this->expectException(InvalidSearchPattern::class);
        $this->expectExceptionMessage('missing closing parenthesis at offset 9');

        SearchPattern::compile('(unclosed', true, false);
    }

    #[TestDox('a regex that exhausts the backtrack limit is refused while matching')]
    public function test_a_regex_that_exhausts_the_backtrack_limit_is_refused_while_matching(): void
    {
        $limit = ini_get('pcre.backtrack_limit');
        $jit = ini_get('pcre.jit');
        ini_set('pcre.backtrack_limit', '10');
        ini_set('pcre.jit', '0');

        try {
            $this->expectException(InvalidSearchPattern::class);
            SearchPattern::compile('(a+)+$', true, false)->matches(str_repeat('a', 30).'b');
        } finally {
            ini_set('pcre.backtrack_limit', $limit);
            ini_set('pcre.jit', $jit);
        }
    }

    /** Regex term, text, expected snippet HTML, and the count of matches left out. */
    public static function snippetCases(): array
    {
        $long = str_repeat('x', 100).' needle '.str_repeat('y', 100).' needle';

        return [
            'every match bolded' => ['a', 'banana', 'b<strong>a</strong>n<strong>a</strong>n<strong>a</strong>', 0],
            'text escaped around and inside matches' => ['<b>', 'x <b> & y', 'x <strong>&lt;b&gt;</strong> &amp; y', 0],
            'long text trimmed with ellipses and a count of the rest' => ['needle', $long, '…'.str_repeat('x', 59).' <strong>needle</strong> '.str_repeat('y', 59).'…', 1],
            'window widened to finish a match it would split' => ['ab+', 'ab'.str_repeat(' ', 59).'abbbb tail', '<strong>ab</strong>'.str_repeat(' ', 59).'<strong>abbbb</strong>…', 0],
        ];
    }

    #[DataProvider('snippetCases')]
    #[TestDox('snippet: $_dataName')]
    public function test_snippet(string $term, string $text, string $html, int $more): void
    {
        $snippet = SearchPattern::compile($term, true, false)->snippet($text);

        $this->assertSame($html, $snippet->html->toHtml());
        $this->assertSame($more, $snippet->more);
    }

    #[TestDox('snippet never splits a multibyte character at a cut end')]
    public function test_snippet_never_splits_a_multibyte_character_at_a_cut_end(): void
    {
        $snippet = SearchPattern::compile('needle', false, false)->snippet(str_repeat('é', 40).'needle'.str_repeat('ü', 40));

        $this->assertTrue(mb_check_encoding($snippet->html->toHtml(), 'UTF-8'));
        $this->assertStringContainsString('<strong>needle</strong>', $snippet->html->toHtml());
    }

    #[TestDox('snippet is null when nothing matches')]
    public function test_snippet_is_null_when_nothing_matches(): void
    {
        $this->assertNull(SearchPattern::compile('zzz', false, false)->snippet('abc'));
    }
}
