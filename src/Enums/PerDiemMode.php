<?php

namespace ConferenceTools\Registration\Enums;

/**
 * How the conference's own days feed the attendee's per-diem — this governs
 * the attendee rate only. In {@see ExtraOnly} only the extra days an option
 * adds (early arrival for set-up, etc.) are billed — an ordinary attendee pays
 * no per-diem. In {@see AllDays} every day of the conference is billed to the
 * attendee as room & board too, and option days are added on top.
 *
 * Accompanying non-attending guests are unaffected by this setting: they are
 * always billed for the conference's own days (see
 * {@see \ConferenceTools\Registration\Services\PerDiem::guestDays()}), on the
 * premise that a guest can't selectively skip the conference they're
 * accompanying someone to.
 */
enum PerDiemMode: string
{
    case ExtraOnly = 'extra_only';
    case AllDays = 'all_days';

    /** The case values, for validation rules. */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** Whether the conference's own days ({@see PerDiem::conferenceDays()}) are billed. */
    public function billsConferenceDays(): bool
    {
        return $this === self::AllDays;
    }
}
