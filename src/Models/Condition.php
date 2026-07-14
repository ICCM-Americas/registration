<?php

namespace ConferenceTools\Registration\Models;

use ConferenceTools\Registration\Enums\ConditionOperator;
use ConferenceTools\Registration\Enums\ConditionSubject;
use ConferenceTools\Registration\Models\Concerns\HasRegistrationTable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single comparison within a visibility rule: "the answer to {question}
 * {operator} {value}". The question referenced here is the controlling question
 * whose answer is tested — not the question whose visibility is being decided.
 * Exactly one of question_id and subject is set: subject tests a built-in
 * fact about the row (see {@see ConditionSubject}) instead of a question's
 * answer, for facts — like a guest's own type — that aren't an answer at all.
 */
class Condition extends Model
{
    use HasFactory, HasRegistrationTable;

    protected $fillable = ['condition_group_id', 'question_id', 'subject', 'operator', 'value', 'position'];

    protected $casts = [
        'subject' => ConditionSubject::class,
        'operator' => ConditionOperator::class,
        'position' => 'integer',
    ];

    /** The group this condition belongs to. */
    public function group(): BelongsTo
    {
        return $this->belongsTo(ConditionGroup::class, 'condition_group_id');
    }

    /** The controlling question whose answer this condition tests. */
    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }
}
