<?php

namespace ConferenceTools\Registration\Enums;

/**
 * Registrant gender, stored on the host users table. Values match the existing
 * single-character column ('m'/'f').
 */
enum Gender: string
{
    case Male = 'm';
    case Female = 'f';

    /** Backing values, e.g. for validation rules or migration column definitions. */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** The label shown in admin listings. */
    public function label(): string
    {
        return match ($this) {
            self::Male => 'Male',
            self::Female => 'Female',
        };
    }
}
