<?php

namespace ConferenceTools\Registration\Concerns;

use ConferenceTools\Registration\Enums\GuestType;
use ConferenceTools\Registration\Models\BaseCharge;
use ConferenceTools\Registration\Models\Currency;
use ConferenceTools\Registration\Models\Group;
use ConferenceTools\Registration\Models\Guest;
use ConferenceTools\Registration\Models\Payment;
use ConferenceTools\Registration\Services\PerDiem;
use ConferenceTools\Registration\Support\AnswerBag;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Add this trait to the host application's user model to expose the conference
 * registration relationships and helpers. A registrant IS a host user; the
 * package's migration adds only the structural columns it owns (group_id,
 * mail_id, checked_out, is_group_admin) to the host users table.
 *
 *     class User extends Authenticatable
 *     {
 *         use \ConferenceTools\Registration\Concerns\InteractsWithRegistration;
 *     }
 *
 * The registrant's actual answers (last name, passport, gender, accommodation,
 * products, …) live in the configurable EAV answer store, not in columns. They
 * are read through {@see registrationAnswers()}; the profile accessors below
 * (e.g. $user->lastname) are thin conveniences over it for the package's own
 * views and emails.
 */
trait InteractsWithRegistration
{
    private ?AnswerBag $registrationAnswerBag = null;

    /** The registration group (organization) this registrant belongs to. */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    /** This registrant's non-attending guests (adults and minors). */
    public function guests(): HasMany
    {
        return $this->hasMany(Guest::class);
    }

    /** This registrant's manually-recorded payment state (paid/amount/notes), if any has been entered. */
    public function payment(): HasOne
    {
        return $this->hasOne(Payment::class);
    }

    /** This registrant's configured answers, read from the EAV answer store. */
    public function registrationAnswers(): AnswerBag
    {
        return $this->registrationAnswerBag ??= AnswerBag::forOwner($this);
    }

    /** Drop the memoized answers (call after the registrant's answers change). */
    public function refreshRegistrationAnswers(): void
    {
        $this->registrationAnswerBag = null;
    }

    /**
     * This registrant's final cost: the flat base charges plus their chosen priced
     * options (accommodation, products…), their guests' own priced options and
     * per-diem, plus their own per-diem (room & board), with any discount code
     * they entered applied to the total. Never less than zero.
     */
    public function cost(): float
    {
        $answers = $this->registrationAnswers();
        $guestsCost = $this->guests->sum(fn (Guest $guest): float => $guest->cost());
        $adultGuests = $this->guests->where('type', GuestType::Adult)->count();
        $minorGuests = $this->guests->where('type', GuestType::Minor)->count();

        $subtotal = BaseCharge::total() + $answers->cost() + $guestsCost
            + app(PerDiem::class)->total($answers, $adultGuests, $minorGuests);

        return $answers->applyDiscount($subtotal);
    }

    /** This registrant's cost formatted in the default currency. */
    public function currencyString(): string
    {
        return Currency::def()->format($this->cost());
    }

    /**
     * The itemized breakdown behind {@see cost()}'s total, split so a guest's
     * cost is never mixed into the registrant's own lines: `lines` is the
     * registrant's own base charges, chosen priced options and per-diem (plus
     * a discount line, when they entered a code), and `guestTotals` is each
     * guest's full total (their own priced options plus their per-diem
     * share), keyed by guest id — every number the admin Payments list needs
     * to show its numbers adding up to {@see cost()}.
     *
     * @return array{lines: array<int, array{label: string, amount: float}>, guestTotals: array<int, float>}
     */
    public function paymentsBreakdown(): array
    {
        $answers = $this->registrationAnswers();
        $adultGuests = $this->guests->where('type', GuestType::Adult)->count();
        $minorGuests = $this->guests->where('type', GuestType::Minor)->count();
        $perDiem = app(PerDiem::class)->breakdown($answers, $adultGuests, $minorGuests);

        $lines = [...BaseCharge::lines(), ...$answers->costLines(), ...$perDiem->attendeeLines()];
        $guestTotals = $this->guests->mapWithKeys(
            fn (Guest $guest) => [$guest->getKey() => round($guest->cost() + $perDiem->amountForGuest($guest->type), 2)]
        )->all();

        if ($discount = $answers->discount()) {
            $subtotal = round(array_sum(array_column($lines, 'amount')) + array_sum($guestTotals), 2);
            $lines[] = [
                'label' => __('registration::common.cost_discount').' ('.$discount->code.')',
                'amount' => round($discount->apply($subtotal) - $subtotal, 2),
            ];
        }

        return ['lines' => $lines, 'guestTotals' => $guestTotals];
    }

    /** The registrant's last-name answer. */
    public function getLastnameAttribute(): ?string
    {
        return $this->registrationAnswers()->value('lastname');
    }

    /** The registrant's nickname answer. */
    public function getNicknameAttribute(): ?string
    {
        return $this->registrationAnswers()->value('nickname');
    }

    /** The registrant's passport-name answer. */
    public function getPassportAttribute(): ?string
    {
        return $this->registrationAnswers()->value('passport');
    }

    /** The registrant's gender answer. */
    public function getGenderAttribute(): ?string
    {
        return $this->registrationAnswers()->value('gender');
    }

    /** The registrant's residence answer. */
    public function getResidenceAttribute(): ?string
    {
        return $this->registrationAnswers()->value('residence');
    }
}
