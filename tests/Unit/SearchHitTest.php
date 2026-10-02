<?php

namespace ConferenceTools\Registration\Tests\Unit;

use ConferenceTools\Registration\Support\Search\SearchHit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** Search result links that carry the results page back to the editor they open. */
#[TestDox('Search Hit')]
class SearchHitTest extends TestCase
{
    /** Link, whether it opens the modal, and the link the results page renders. */
    public static function links(): array
    {
        $return = 'http://x/search?q=a b';

        return [
            'page link' => ['/edit', false, '/edit?_return='.urlencode($return)],
            'page link with a query' => ['/edit?highlight=k', false, '/edit?highlight=k&_return='.urlencode($return)],
            'page link with a fragment' => ['/edit#option-3', false, '/edit?_return='.urlencode($return).'#option-3'],
            'modal link left alone' => ['/visibility', true, '/visibility'],
        ];
    }

    #[DataProvider('links')]
    #[TestDox('returning: $_dataName')]
    public function test_returning(string $url, bool $modal, string $expected): void
    {
        $this->assertSame($expected, SearchHit::returning($url, $modal, 'http://x/search?q=a b'));
    }
}
