<?php

namespace ConferenceTools\Registration\Enums;

/**
 * Whether a non-attending guest is an adult or a minor — drives pricing (a
 * separate configurable rate per type), report eligibility (badges, photos,
 * Prayer Pals), and which questions they're asked.
 */
enum GuestType: string
{
    case Adult = 'adult';
    case Minor = 'minor';

    /** The case values, for validation rules. */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
