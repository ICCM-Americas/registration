<?php

namespace ConferenceTools\Registration\Support;

use ConferenceTools\Registration\Enums\GuestType;
use ConferenceTools\Registration\Enums\PerDiemMode;
use ConferenceTools\Registration\Enums\PerDiemScope;
use ConferenceTools\Registration\Services\PerDiem;

/**
 * The computed per-diem for one registrant: the day contributions that make it
 * up and the money they come to, kept as separate factors so a future invoice
 * can render "N days × rate (× guests)" line by line rather than a lump sum.
 *
 * The attendee is billed for every contribution's days (governed by
 * {@see PerDiemMode}). Each accompanying
 * guest is billed, at their own type's rate (adult or minor), for a
 * separately-computed day count that is independent of that mode — see
 * {@see PerDiem::guestDays()}.
 */
final class PerDiemBreakdown
{
    /**
     * @param  array<int, array{label: string, days: int, scope: PerDiemScope}>  $contributions
     */
    public function __construct(
        public readonly array $contributions,
        private readonly int $guestDaysTotal,
        public readonly int $adultGuestCount,
        public readonly int $minorGuestCount,
        public readonly float $attendeeRate,
        public readonly float $adultGuestRate,
        public readonly float $minorGuestRate,
    ) {}

    /** Total per-diem days billed for the attendee. */
    public function attendeeDays(): int
    {
        return array_sum(array_column($this->contributions, 'days'));
    }

    /** Per-diem days billed for each accompanying guest: the conference's own days, always, plus any guest-inclusive extra days. */
    public function guestDays(): int
    {
        return $this->guestDaysTotal;
    }

    /** The attendee's per-diem charge (days × rate). */
    public function attendeeAmount(): float
    {
        return round($this->attendeeRate * $this->attendeeDays(), 2);
    }

    /**
     * The attendee's per-diem charge itemized: one line per day contribution
     * (the conference's own days, when billed, plus each chosen option's
     * extra days), priced at the attendee rate — never a guest's own share,
     * which {@see amountForGuest()} prices per guest instead.
     *
     * @return array<int, array{label: string, amount: float}>
     */
    public function attendeeLines(): array
    {
        return array_map(fn (array $c) => [
            'label' => __('registration::common.cost_per_diem_line', ['label' => $c['label'], 'days' => $c['days']]),
            'amount' => round($this->attendeeRate * $c['days'], 2),
        ], $this->contributions);
    }

    /** One accompanying guest's own per-diem charge, at their type's rate for {@see guestDays()}. */
    public function amountForGuest(GuestType $type): float
    {
        return round(($type === GuestType::Adult ? $this->adultGuestRate : $this->minorGuestRate) * $this->guestDays(), 2);
    }

    /** The accompanying adult guests' per-diem charge. */
    public function adultGuestAmount(): float
    {
        return round($this->adultGuestRate * $this->guestDays() * $this->adultGuestCount, 2);
    }

    /** The accompanying minor guests' per-diem charge. */
    public function minorGuestAmount(): float
    {
        return round($this->minorGuestRate * $this->guestDays() * $this->minorGuestCount, 2);
    }

    /** Total guest per-diem amount, adult and minor combined. */
    public function guestAmount(): float
    {
        return round($this->adultGuestAmount() + $this->minorGuestAmount(), 2);
    }

    /** The whole per-diem charge for this registrant (attendee plus any guests). */
    public function total(): float
    {
        return round($this->attendeeAmount() + $this->guestAmount(), 2);
    }

    /** Nothing configured to bill, for the attendee or any guest. */
    public function isEmpty(): bool
    {
        return $this->total() <= 0.0;
    }
}
