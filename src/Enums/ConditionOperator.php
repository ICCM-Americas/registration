<?php

namespace ConferenceTools\Registration\Enums;

/**
 * How a single visibility condition compares the answer of another question
 * against its configured value. Evaluation lives in the visibility service; this
 * enum just enumerates the supported comparisons and which of them need a value.
 */
enum ConditionOperator: string
{
    case Equals = 'equals';
    case NotEquals = 'not_equals';
    case In = 'in';
    case NotIn = 'not_in';
    case Contains = 'contains';
    case StartsWith = 'starts_with';
    case EndsWith = 'ends_with';
    case IsAnswered = 'is_answered';
    case IsNotAnswered = 'is_not_answered';
    case GreaterThan = 'greater_than';
    case LessThan = 'less_than';

    /** The case values, for validation rules. */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** Presence operators ignore (and need not store) a comparison value. */
    public function needsValue(): bool
    {
        return ! in_array($this, [self::IsAnswered, self::IsNotAnswered], true);
    }
}
