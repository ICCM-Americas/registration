<?php

namespace ConferenceTools\Registration\Models;

use ConferenceTools\Registration\Models\Concerns\HasRegistrationTable;
use ConferenceTools\Registration\Support\AnswerBag;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An invitation for someone to register as a member of a group. Its own
 * captured details (name, email — collected from the group leader, not the
 * invitee) live in the EAV answer store, exactly like a host user's or a
 * Group's — this model owns only its identity (which group, its token,
 * whether/by whom it has been accepted) as columns.
 */
class GroupInvite extends Model
{
    use HasFactory, HasRegistrationTable;

    protected $fillable = ['group_id', 'token', 'consumed_at', 'user_id'];

    protected $casts = [
        'consumed_at' => 'datetime',
    ];

    private ?AnswerBag $answerBag = null;

    /**
     * An invite is a polymorphic owner of its own Answer rows (its captured
     * name/email), with no DB-level foreign key back to this table (package
     * convention: see AnswerStore/AnswerPurge); delete them as models instead
     * when an invite is removed (see MyGroupMemberController::destroy()).
     */
    protected static function booted(): void
    {
        static::deleting(fn (GroupInvite $invite) => Answer::where('owner_type', $invite->getMorphClass())->where('owner_id', $invite->id)->delete());
    }

    /** The group this invite, once accepted, joins its registrant to. */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    /** The resulting registrant, once accepted (null until then). */
    public function user(): BelongsTo
    {
        return $this->belongsTo(config('registration.user_model'));
    }

    /** This invite's configured (group-member-scope) answers, read from the EAV store. */
    public function registrationAnswers(): AnswerBag
    {
        return $this->answerBag ??= AnswerBag::forOwner($this);
    }

    /** Drop the memoized answers (call after the invite's answers change). */
    public function refreshRegistrationAnswers(): void
    {
        $this->answerBag = null;
    }

    /** Whether this invite has already been used to register someone. */
    public function isConsumed(): bool
    {
        return $this->consumed_at !== null;
    }
}
