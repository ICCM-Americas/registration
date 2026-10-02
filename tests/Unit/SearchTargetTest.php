<?php

namespace ConferenceTools\Registration\Tests\Unit;

use ConferenceTools\Registration\Support\Search\SearchTarget;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** Encoding an answer hit's registration as a checkbox value, and reading it back. */
#[TestDox('Search Target')]
class SearchTargetTest extends TestCase
{
    /** Each kind of target with its encoding and whether it is a guest. */
    public static function targets(): array
    {
        return [
            'registrant' => [new SearchTarget(5), 'user:5', false],
            'committed guest' => [new SearchTarget(5, 12), 'guest:5:12', true],
            'draft guest' => [new SearchTarget(5, null, 'a-b:c'), 'draft-guest:5:a-b:c', true],
        ];
    }

    #[DataProvider('targets')]
    #[TestDox('round-trips a $_dataName')]
    public function test_round_trips(SearchTarget $target, string $encoded, bool $isGuest): void
    {
        $this->assertSame($encoded, $target->encode());
        $this->assertEquals($target, SearchTarget::decode($encoded));
        $this->assertSame($isGuest, $target->isGuest());
    }

    /** Encodings that name no target. */
    public static function malformed(): array
    {
        return [
            'no user' => ['user:0'],
            'unknown kind' => ['group:5'],
            'registrant with extra part' => ['user:5:1'],
            'guest without id' => ['guest:5'],
            'guest with non-numeric id' => ['guest:5:x'],
            'draft guest without id' => ['draft-guest:5:'],
            'garbage' => ['nonsense'],
        ];
    }

    #[DataProvider('malformed')]
    #[TestDox('rejects $_dataName')]
    public function test_rejects(string $encoded): void
    {
        $this->assertNull(SearchTarget::decode($encoded));
    }
}
