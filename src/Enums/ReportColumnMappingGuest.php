<?php

namespace ConferenceTools\Registration\Enums;

use ConferenceTools\Registration\Models\ReportColumn;

/**
 * Which rows a report column's "Custom Values" mapping entry (see
 * {@see ReportColumn}) applies to: every
 * row, only guest rows (either type), only non-guest (registrant) rows, or
 * only adult/minor guest rows specifically — lets the same column show
 * different mapped text depending on the row's guest-ness, e.g. the
 * registrant's or their guest's name, or a fixed text for minors regardless
 * of a guest-only question's answer (a blank entry value plus AdultGuest/
 * MinorGuest bypasses that question entirely for the type it targets).
 */
enum ReportColumnMappingGuest: string
{
    case Any = 'any';
    case Guest = 'guest';
    case NonGuest = 'non_guest';
    case AdultGuest = 'adult_guest';
    case MinorGuest = 'minor_guest';

    /** The case values, for validation rules. */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** Whether a row of the given guest type (null for a registrant row) matches this filter. */
    public function matches(?GuestType $guestType): bool
    {
        return match ($this) {
            self::Any => true,
            self::Guest => $guestType !== null,
            self::NonGuest => $guestType === null,
            self::AdultGuest => $guestType === GuestType::Adult,
            self::MinorGuest => $guestType === GuestType::Minor,
        };
    }

    /** The filter's translated label, for the mapping editor's select. */
    public function label(): string
    {
        return __('registration::admin.report_column_mapping_guest_'.$this->value);
    }
}
