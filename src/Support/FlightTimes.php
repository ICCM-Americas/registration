<?php

namespace ConferenceTools\Registration\Support;

use ConferenceTools\Registration\Services\ReportQuestions;

/**
 * Extracts the clock times from a free-text travel-plans answer such as
 * "Arriving AA1234 on July 14 at 10:35 PM, departing DL-5678 on 7/18 at
 * 8:05 AM". Airline flight numbers (two letters and 3-4 digits, run together
 * or split by a dash or space) are dropped first so their digits are never
 * read as times, and each time picks up a date named in the same clause (a
 * line, or a comma/semicolon-separated phrase). The times come back ordered
 * by date then time-of-day — the caller treats the earliest as the arrival
 * flight and the latest as the departure (see
 * {@see ReportQuestions::flightArrivalTime()}).
 * Years are ignored — conference travel spans days, not months — and a time
 * with no date in its clause sorts ahead of the dated ones.
 */
class FlightTimes
{
    /** An airline flight number, whose digits must never be read as a time. */
    private const FLIGHT_NUMBER = '/\b[A-Za-z]{2}[- ]?\d{3,4}\b/';

    /**
     * A clock time: "10:35 PM", "16:00", or "7pm" — the meridiem may be
     * dotted ("p.m."), and a bare hour needs one to count as a time at all.
     */
    private const TIME = '/\b(\d{1,2}):(\d{2})\s*([ap])\.?m\.?\b|\b(\d{1,2}):(\d{2})\b|\b(\d{1,2})\s*([ap])\.?m\.?\b/i';

    /** A calendar date: "7/18" (optionally "/2026"), "July 18", or "18 July". */
    private const DATE_PATTERNS = [
        '/\b(?<month>\d{1,2})\/(?<day>\d{1,2})(?:\/\d{2,4})?\b/',
        '/\b(?<month>jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*\.?\s+(?<day>\d{1,2})\b/i',
        '/\b(?<day>\d{1,2})\s+(?<month>jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*\b/i',
    ];

    private const MONTHS = [
        'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6,
        'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12,
    ];

    /**
     * Every readable time in the text as "HH:MM", ordered by date then time.
     *
     * @return list<string>
     */
    public static function extract(string $text): array
    {
        $entries = [];
        foreach (preg_split('/[\n,;]+/', preg_replace(self::FLIGHT_NUMBER, ' ', $text)) as $clause) {
            $date = self::dateOrdinal($clause);
            foreach (self::clauseTimes($clause) as $minutes) {
                $entries[] = [$date, $minutes];
            }
        }

        usort($entries, fn (array $a, array $b): int => $a <=> $b);

        return array_map(
            fn (array $entry): string => sprintf('%02d:%02d', intdiv($entry[1], 60), $entry[1] % 60),
            $entries,
        );
    }

    /**
     * Each readable time in the clause as minutes since midnight, in text
     * order; impossible clock values ("25:10", "9:75") are dropped.
     *
     * @return list<int>
     */
    private static function clauseTimes(string $clause): array
    {
        preg_match_all(self::TIME, $clause, $matches, PREG_SET_ORDER);

        $times = [];
        foreach ($matches as $match) {
            if (($match[3] ?? '') !== '') {
                [$hour, $minute, $meridiem] = [(int) $match[1], (int) $match[2], strtolower($match[3])];
            } elseif (($match[4] ?? '') !== '') {
                [$hour, $minute, $meridiem] = [(int) $match[4], (int) $match[5], null];
            } else {
                [$hour, $minute, $meridiem] = [(int) $match[6], 0, strtolower($match[7])];
            }

            if ($minute > 59 || ($meridiem === null ? $hour > 23 : $hour < 1 || $hour > 12)) {
                continue;
            }
            if ($meridiem !== null) {
                $hour = ($hour % 12) + ($meridiem === 'p' ? 12 : 0);
            }

            $times[] = $hour * 60 + $minute;
        }

        return $times;
    }

    /** The month-and-day ordinal of the first date the clause names, or 0 while it names none. */
    private static function dateOrdinal(string $clause): int
    {
        foreach (self::DATE_PATTERNS as $pattern) {
            if (preg_match($pattern, $clause, $match) !== 1) {
                continue;
            }

            $month = is_numeric($match['month']) ? (int) $match['month'] : self::MONTHS[strtolower($match['month'])];
            $day = (int) $match['day'];
            if ($month >= 1 && $month <= 12 && $day >= 1 && $day <= 31) {
                return $month * 31 + $day;
            }
        }

        return 0;
    }
}
