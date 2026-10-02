<?php

namespace ConferenceTools\Registration\Tests\Unit;

use ConferenceTools\Registration\Support\Search\SearchOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** The admin search form's state: building it from input and repeating it as a query. */
#[TestDox('Search Options')]
class SearchOptionsTest extends TestCase
{
    #[TestDox('reads every flag and checked place from the form input')]
    public function test_reads_every_flag_and_checked_place_from_the_form_input(): void
    {
        $options = SearchOptions::fromInput(['q' => 'veg', 'regex' => '1', 'case' => '1', 'translations' => '1', 'in' => ['drafts', 'options']]);

        $this->assertSame('veg', $options->term);
        $this->assertTrue($options->regex && $options->caseSensitive && $options->translations);
        $this->assertSame(['options', 'drafts'], $options->places);
        $this->assertTrue($options->has(SearchOptions::DRAFTS));
        $this->assertFalse($options->has(SearchOptions::ANSWERS));
        $this->assertSame(['q' => 'veg', 'regex' => 1, 'case' => 1, 'translations' => 1, 'in' => ['options', 'drafts']], $options->toQuery());
    }

    /** Form input the options must tolerate. */
    public static function malformedInput(): array
    {
        return [
            'nothing' => [[]],
            'array term and unknown place' => [['q' => ['x'], 'in' => ['bogus']]],
            'scalar places' => [['in' => 'answers']],
            'nested places' => [['in' => [['answers']]]],
        ];
    }

    #[DataProvider('malformedInput')]
    #[TestDox('ignores malformed input: $_dataName')]
    public function test_ignores_malformed_input(array $input): void
    {
        $options = SearchOptions::fromInput($input);

        $this->assertSame('', $options->term);
        $this->assertSame(in_array('answers', (array) ($input['in'] ?? []), true) ? ['answers'] : [], $options->places);
    }

    #[TestDox('lists every place across the categories')]
    public function test_lists_every_place_across_the_categories(): void
    {
        $this->assertSame(
            ['question_text', 'options', 'question_rules', 'report_text', 'report_columns', 'report_rules', 'answers', 'drafts'],
            SearchOptions::places(),
        );
    }
}
