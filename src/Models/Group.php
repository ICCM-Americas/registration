<?php

namespace ConferenceTools\Registration\Models;

use ConferenceTools\Registration\Models\Concerns\HasRegistrationTable;
use ConferenceTools\Registration\Support\AnswerBag;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A registration group (organization): its members, its Group-scope answers, and the roll-up counts the dashboards report. */
class Group extends Model
{
    use HasFactory, HasRegistrationTable;

    /**
     * The group owns only its identity (the organization name) and booking state
     * as columns; its registration details (website, org type, billing address…)
     * live in the EAV answer store and are read through the accessors below.
     */
    protected $fillable = ['name', 'checked_out', 'is_group'];

    protected $attributes = [
        'checked_out' => 0,
    ];

    protected $casts = [
        'checked_out' => 'boolean',
        'is_group' => 'boolean',
    ];

    private ?AnswerBag $answerBag = null;

    /** Registrants (host users) belonging to this group. */
    public function users(): HasMany
    {
        return $this->hasMany(config('registration.user_model'));
    }

    /** This group's configured (group-scope) answers, read from the EAV store. */
    public function registrationAnswers(): AnswerBag
    {
        return $this->answerBag ??= AnswerBag::forOwner($this);
    }

    /** Drop the memoized answers (call after the group's answers change). */
    public function refreshRegistrationAnswers(): void
    {
        $this->answerBag = null;
    }

    /** The group's website, read from its registration answers. */
    public function getWebsiteAttribute(): ?string
    {
        return $this->registrationAnswers()->value('website');
    }

    /** The group's street address, read from its registration answers. */
    public function getAddressAttribute(): ?string
    {
        return $this->registrationAnswers()->value('address');
    }

    /** The group's town, read from its registration answers. */
    public function getTownAttribute(): ?string
    {
        return $this->registrationAnswers()->value('town');
    }

    /** The group's state, read from its registration answers. */
    public function getStateAttribute(): ?string
    {
        return $this->registrationAnswers()->value('state');
    }

    /** The group's postal code, read from its registration answers. */
    public function getZipcodeAttribute(): ?string
    {
        return $this->registrationAnswers()->value('zipcode');
    }

    /** The group's country, read from its registration answers. */
    public function getCountryAttribute(): ?string
    {
        return $this->registrationAnswers()->value('country');
    }

    /** The group's telephone number, read from its registration answers. */
    public function getTelephoneAttribute(): ?string
    {
        return $this->registrationAnswers()->value('telephone');
    }

    /** The organization type, resolving the free-text answer when "other". */
    public function getOrgTypeAttribute(): ?string
    {
        $answers = $this->registrationAnswers();
        $orgType = $answers->value('orgtype');

        return $orgType === 'other' ? $answers->value('orgtypeother') : $orgType;
    }

    /**
     * Number of completed registrations: registrants attached to a group.
     *
     * A registration is "complete" once the participant has been saved into a
     * group (which is the only way group_id is set — see UserRegistrationService).
     * Incomplete registrations (started but not finished) are not yet modeled;
     * once they are, count them separately rather than folding them in here.
     */
    public static function registeredParticipantCount(): int
    {
        return config('registration.user_model')::query()->whereNotNull('group_id')->count();
    }

    /** The group's administrator — the user who registered the group. */
    public function admin()
    {
        return $this->users()->where('is_group_admin', true)->first();
    }

    /**
     * Total cost of all registrants in the group (each already including their
     * base charges, priced options and any participant-scope discount), with any
     * group-scope discount code applied to the total. Never less than zero.
     */
    public function cost(): float
    {
        $cost = 0;
        foreach ($this->users as $u) {
            $cost += $u->cost();
        }

        return $this->registrationAnswers()->applyDiscount($cost);
    }
}
