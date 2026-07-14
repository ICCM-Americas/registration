<?php

namespace ConferenceTools\Registration\Enums;

/**
 * How a report column presents a question's answer: the stored value itself,
 * the label of the option that value came from (when it still matches one),
 * or the column's own value -> text mapping (see ReportColumn::$mapping).
 */
enum ReportColumnDisplay: string
{
    case Value = 'value';
    case Label = 'label';
    case Mapped = 'mapped';

    /** The case values, for validation rules. */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
