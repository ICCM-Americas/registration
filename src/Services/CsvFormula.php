<?php

namespace ConferenceTools\Registration\Services;

/**
 * A CSV cell holding a spreadsheet formula rather than a literal value —
 * bypasses {@see CsvExport}'s formula-injection sanitizing. Use this only for
 * app-authored formulas (e.g. the reports' Count row), never for a cell built
 * from a registrant's own answer.
 */
final class CsvFormula
{
    /** Wrap a spreadsheet formula so the CSV export leaves it unescaped. */
    public function __construct(private readonly string $formula) {}

    /** The raw formula text. */
    public function __toString(): string
    {
        return $this->formula;
    }
}
