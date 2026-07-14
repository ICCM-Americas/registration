<?php

namespace ConferenceTools\Registration\Enums;

use ConferenceTools\Registration\Models\ReportColumn;
use ConferenceTools\Registration\Services\VariableInterpolator;
use ConferenceTools\Registration\Services\VisibilityEvaluator;

/**
 * A condition's controlling side when it isn't another question's answer —
 * a built-in fact about the row instead, the same duality
 * {@see ReportColumn} already has
 * between question_id and field. Each case's value doubles as the key its
 * value is read from in the answers map a visibility rule evaluates against
 * (see {@see VisibilityEvaluator}) —
 * a question sharing that literal key in the same scope would collide with
 * it, same as any other reserved-word clash in this codebase (e.g.
 * {@see VariableInterpolator}'s
 * cost_summary token).
 */
enum ConditionSubject: string
{
    /** The guest's own type (adult or minor) — only meaningful for a Guest-scope question's own rule, since a guest answers as themselves. */
    case GuestType = 'guest_type';

    /** The case values, for validation rules. */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** The subject's translated label, for the visibility editor's picker. */
    public function label(): string
    {
        return __('registration::admin.condition_subject_'.$this->value);
    }
}
