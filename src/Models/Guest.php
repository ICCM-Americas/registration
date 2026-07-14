<?php

namespace ConferenceTools\Registration\Models;

use ConferenceTools\Registration\Enums\GuestType;
use ConferenceTools\Registration\Models\Concerns\HasRegistrationTable;
use ConferenceTools\Registration\Services\GuestQuestions;
use ConferenceTools\Registration\Services\ReportQuestions;
use ConferenceTools\Registration\Support\AnswerBag;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A non-attending guest (adult or minor) a registrant brings. A guest's own
 * details (name, sex, …) live in the EAV answer store, exactly like a host
 * user's or a Group's — this model owns only its identity (who brought it,
 * which type) as columns.
 */
class Guest extends Model
{
    use HasFactory, HasRegistrationTable;

    protected $fillable = ['user_id', 'type', 'position'];

    protected $casts = [
        'type' => GuestType::class,
        'position' => 'integer',
    ];

    private ?AnswerBag $answerBag = null;

    /**
     * A guest is a polymorphic owner of its own Answer rows, and can be a
     * polymorphic occupant of a RoomAssignment/PrayerPalsAssignment — none of
     * these has a DB-level foreign key back to this table (package
     * convention: see AnswerStore/AnswerPurge), so the DB would leave them
     * all orphaned; delete them as models instead when a guest is removed
     * (see MyGuestController::destroy()).
     */
    protected static function booted(): void
    {
        static::deleting(function (Guest $guest) {
            Answer::where('owner_type', $guest->getMorphClass())->where('owner_id', $guest->id)->delete();
            RoomAssignment::where('assignable_type', $guest->getMorphClass())->where('assignable_id', $guest->id)->delete();
            PrayerPalsAssignment::where('assignable_type', $guest->getMorphClass())->where('assignable_id', $guest->id)->delete();
        });
    }

    /** The registrant who brought this guest. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(config('registration.user_model'));
    }

    /** This guest's configured (guest-scope) answers, read from the EAV store. */
    public function registrationAnswers(): AnswerBag
    {
        return $this->answerBag ??= AnswerBag::forOwner($this);
    }

    /** Drop the memoized answers (call after the guest's answers change). */
    public function refreshRegistrationAnswers(): void
    {
        $this->answerBag = null;
    }

    /** This guest's own priced-option total (their own guest-scope answers only). */
    public function cost(): float
    {
        return $this->registrationAnswers()->cost();
    }

    /**
     * Whether this guest gets a badge: adults always do; minors only when the
     * admin has enabled it (some host locations require a badge for meal
     * access — see {@see GuestQuestions::minorsGetBadges()}).
     */
    public function isBadgeEligible(bool $minorsGetBadges): bool
    {
        return $this->type === GuestType::Adult || $minorsGetBadges;
    }

    /**
     * A pass-through to the registrant's organization, so
     * {@see ReportQuestions::organization()}
     * works unchanged against a Guest (it reads $registrant->group directly).
     */
    public function getGroupAttribute(): ?Group
    {
        return $this->user?->group;
    }
}
