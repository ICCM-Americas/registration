<?php

namespace ConferenceTools\Registration\Enums;

use ConferenceTools\Registration\Services\PerDiem;

/**
 * Who a priced option's extra per-diem days are billed for, on top of the
 * conference's own days that every accompanying guest is always billed for
 * regardless of scope. Some things an attendee selects incur room & board for
 * the attendee alone (a committee meeting they attend early); others also
 * cover an accompanying non-attending adult who is present for the same days.
 * Guests are charged as if present the whole time — their extra per-diem days
 * are exactly the attendee's days from guest-inclusive options (see
 * {@see PerDiem}).
 */
enum PerDiemScope: string
{
    case Attendee = 'attendee';
    case AttendeeAndGuests = 'attendee_and_guests';

    /** Whether these per-diem days are also billed for accompanying guests. */
    public function includesGuests(): bool
    {
        return $this === self::AttendeeAndGuests;
    }
}
