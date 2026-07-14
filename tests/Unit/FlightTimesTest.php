<?php

namespace ConferenceTools\Registration\Tests\Unit;

use ConferenceTools\Registration\Support\FlightTimes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** Unit tests for FlightTimes. */
#[TestDox('FlightTimes')]
class FlightTimesTest extends TestCase
{
    /**
     * @return iterable<string, array{string, array<int, string>}>
     */
    public static function answers(): iterable
    {
        yield 'plain 24-hour times' => ['Arriving at 10:30, departing at 16:00', ['10:30', '16:00']];
        yield '12-hour times with dotted and plain meridiems' => ['lands 9:15 a.m., leaves 4:40 PM', ['09:15', '16:40']];
        yield 'bare meridiem hours' => ['in at 7pm, out by 9 a.m.', ['09:00', '19:00']];
        yield 'noon and midnight' => ['12:00 am and 12:30 pm', ['00:00', '12:30']];

        yield 'flight numbers are never read as times' => ['AA1234, DL-5678 and UA 9012', []];
        yield 'joined, dashed and spaced flight numbers around real times' => [
            'Arriving AA1234 at 9:15 AM, departing DL-5678 at 4:40 PM.',
            ['09:15', '16:40'],
        ];

        yield 'dates order the times across days' => [
            "Arriving UA 0921 on July 14 at 10:05 PM\nDeparting UA-0922 on 7/18 at 6:30 AM",
            ['22:05', '06:30'],
        ];
        yield 'day-first dates and a slashed year' => [
            'BA2201 18 July at 8:00 am; BA 2202 on 7/14/2026 at 9:00 pm',
            ['21:00', '08:00'],
        ];
        yield 'an impossible date is ignored, not ordered by' => ['13/45 at 9:00, 7/14 at 8:00', ['09:00', '08:00']];

        yield 'impossible clock values are skipped' => ['25:10, 9:75 or 0pm', []];
        yield 'no readable times at all' => ['Flight details TBD, connecting through Omaha.', []];
        yield 'empty answer' => ['', []];
    }

    #[Test]
    #[TestDox('extracts clock times around flight numbers, ordered by date then time')]
    #[DataProvider('answers')]
    public function extracts_times(string $text, array $expected): void
    {
        $this->assertSame($expected, FlightTimes::extract($text));
    }
}
