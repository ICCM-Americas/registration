<?php

namespace ConferenceTools\Registration\Models;

use ConferenceTools\Registration\Models\Concerns\HasRegistrationTable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A saved-but-unfinished registration: the server-side wizard's accumulated,
 * validated answers and a pointer to the step the registrant left off on. One
 * per registrant (host user); deleted once the registration is committed, so a
 * surviving draft means the registration is still incomplete.
 */
class Draft extends Model
{
    use HasFactory, HasRegistrationTable;

    protected $fillable = ['user_id', 'current_question_id', 'answers', 'guests', 'group_members'];

    protected $casts = [
        'answers' => 'array',
        'guests' => 'array',
        'group_members' => 'array',
    ];

    /**
     * The first question of the step the registrant is currently on (null
     * falls back to the first step) — a step's stable identity, since one
     * section can now yield more than one step (see RegistrationWizard/Step).
     */
    public function currentQuestion(): BelongsTo
    {
        return $this->belongsTo(Question::class, 'current_question_id');
    }

    /** The number of registrations started but not yet completed. */
    public static function incompleteCount(): int
    {
        return static::count();
    }
}
