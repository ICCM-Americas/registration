<?php

namespace ConferenceTools\Registration\Services;

use ConferenceTools\Registration\Enums\PerDiemMode;
use ConferenceTools\Registration\Enums\PerDiemScope;
use ConferenceTools\Registration\Models\Guest;
use ConferenceTools\Registration\Models\Setting;
use ConferenceTools\Registration\Support\AnswerBag;
use ConferenceTools\Registration\Support\PerDiemBreakdown;

/**
 * Per-diem (room & board) pricing. The rates live here once — in the admin
 * pricing settings — instead of being baked into each choice option's fixed
 * cost, so changing the nightly rate never means re-editing every option.
 *
 * The attendee's own per-diem is a sum of day contributions: optionally the
 * conference's own days ({@see PerDiemMode::AllDays}), plus the extra days any
 * chosen option carries ({@see AnswerBag::perDiemContributions()}) — priced at
 * the attendee rate. {@see PerDiemMode} governs the attendee's rate only.
 * Accompanying non-attending guests are billed independently of it: every
 * guest always covers the conference's own days (a guest can't selectively
 * skip the conference they're accompanying someone to), plus the extra days
 * of any guest-inclusive option the attendee chose — each priced, per guest,
 * at that guest's own type's rate (adult or minor — charged as if present the
 * whole time). Every rate unset (0) means no per-diem is charged, so prices
 * are unchanged until admins configure it. Guest headcounts are the caller's
 * responsibility to supply — they come from real
 * {@see Guest} records (or, pre-commit,
 * drafted guest entries), never from a separately-answered question, so they
 * can never drift from the guests actually captured.
 */
class PerDiem
{
    public const RATE_ATTENDEE = 'per_diem_rate_attendee';

    /** Same setting key as the package's original single guest rate — an existing configured value becomes the adult rate. */
    public const RATE_GUEST_ADULT = 'per_diem_rate_guest';

    public const RATE_GUEST_MINOR = 'per_diem_rate_guest_minor';

    public const CONFERENCE_DAYS = 'per_diem_conference_days';

    public const MODE = 'per_diem_mode';

    /** The configured per-diem rate for attendees. */
    public function attendeeRate(): float
    {
        return (float) Setting::get(self::RATE_ATTENDEE);
    }

    /** The configured per-diem rate for adult guests. */
    public function guestAdultRate(): float
    {
        return (float) Setting::get(self::RATE_GUEST_ADULT);
    }

    /** The configured per-diem rate for minor guests. */
    public function guestMinorRate(): float
    {
        return (float) Setting::get(self::RATE_GUEST_MINOR);
    }

    /** The conference's own length: billed to the attendee only in {@see PerDiemMode::AllDays}, but always billed to accompanying guests. */
    public function conferenceDays(): int
    {
        return (int) Setting::get(self::CONFERENCE_DAYS);
    }

    /** The configured per-diem billing mode for the attendee rate; guests are unaffected by it (see class docblock). */
    public function mode(): PerDiemMode
    {
        return PerDiemMode::tryFrom((string) Setting::get(self::MODE)) ?? PerDiemMode::ExtraOnly;
    }

    /** Persist the pricing settings; passing null clears a rate/day (see {@see Setting::put()}). */
    public function update(?float $attendeeRate, ?float $guestAdultRate, ?float $guestMinorRate, ?int $conferenceDays, PerDiemMode $mode): void
    {
        Setting::put(self::RATE_ATTENDEE, $attendeeRate !== null ? (string) $attendeeRate : null);
        Setting::put(self::RATE_GUEST_ADULT, $guestAdultRate !== null ? (string) $guestAdultRate : null);
        Setting::put(self::RATE_GUEST_MINOR, $guestMinorRate !== null ? (string) $guestMinorRate : null);
        Setting::put(self::CONFERENCE_DAYS, $conferenceDays !== null ? (string) $conferenceDays : null);
        Setting::put(self::MODE, $mode->value);
    }

    /** The itemized per-diem for a registrant's answers and guest headcounts, ready to total or invoice. */
    public function breakdown(AnswerBag $answers, int $adultGuestCount = 0, int $minorGuestCount = 0): PerDiemBreakdown
    {
        return $this->breakdownOf($answers->perDiemContributions()->all(), $adultGuestCount, $minorGuestCount);
    }

    /**
     * Price a set of option day contributions — plus the conference's own days
     * when so configured — for a registrant with the given numbers of
     * accompanying adult and minor guests. The AnswerBag-free entry point, so
     * the wizard's uncommitted draft answers can be priced too (see
     * CostSummaryBuilder).
     *
     * @param  array<int, array{label: string, days: int, scope: PerDiemScope}>  $contributions
     */
    public function breakdownOf(array $contributions, int $adultGuestCount = 0, int $minorGuestCount = 0): PerDiemBreakdown
    {
        return new PerDiemBreakdown(
            $this->withConferenceDays($contributions),
            $this->guestDays($contributions),
            $adultGuestCount,
            $minorGuestCount,
            $this->attendeeRate(),
            $this->guestAdultRate(),
            $this->guestMinorRate(),
        );
    }

    /** The per-diem amount to add to a registrant's total. */
    public function total(AnswerBag $answers, int $adultGuestCount = 0, int $minorGuestCount = 0): float
    {
        return $this->breakdown($answers, $adultGuestCount, $minorGuestCount)->total();
    }

    /**
     * The conference's own days first (only in {@see PerDiemMode::AllDays}),
     * then the given chosen-option contributions.
     *
     * @param  array<int, array{label: string, days: int, scope: PerDiemScope}>  $contributions
     * @return array<int, array{label: string, days: int, scope: PerDiemScope}>
     */
    private function withConferenceDays(array $contributions): array
    {
        if (! $this->mode()->billsConferenceDays() || $this->conferenceDays() <= 0) {
            return $contributions;
        }

        return array_merge([[
            'label' => __('registration::admin.per_diem_conference_line'),
            'days' => $this->conferenceDays(),
            'scope' => PerDiemScope::AttendeeAndGuests,
        ]], $contributions);
    }

    /**
     * Every accompanying guest's per-diem days: the conference's own days,
     * always, plus the extra days of any guest-inclusive option the attendee
     * chose — independent of {@see PerDiemMode}, which governs only what the
     * attendee themself is billed.
     *
     * @param  array<int, array{label: string, days: int, scope: PerDiemScope}>  $contributions
     */
    private function guestDays(array $contributions): int
    {
        $extraDays = array_sum(array_map(
            fn (array $contribution) => $contribution['scope']->includesGuests() ? $contribution['days'] : 0,
            $contributions,
        ));

        return $this->conferenceDays() + $extraDays;
    }
}
