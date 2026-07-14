<?php

namespace ConferenceTools\Registration\Enums;

/**
 * How a condition group combines its children (nested groups and/or leaf
 * conditions) when evaluating a section's or question's visibility. Groups nest,
 * so AND/OR trees of arbitrary depth are expressible.
 */
enum BooleanOperator: string
{
    case And = 'and';
    case Or = 'or';

    /** The case values, for validation rules. */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
