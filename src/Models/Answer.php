<?php

namespace ConferenceTools\Registration\Models;

use ConferenceTools\Registration\Enums\PerDiemScope;
use ConferenceTools\Registration\Models\Concerns\HasRegistrationTable;
use ConferenceTools\Registration\Services\AnswerStore;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One stored answer: a question answered by an owner (a host user for
 * participant-scoped questions, a Group for group-scoped ones). "value" is
 * always the final, report-ready text — for a choice answer it was already
 * resolved and interpolated at write time (see
 * {@see AnswerStore}), so nothing here
 * needs to reach back to the question's options. There is no option_id: an
 * answer, once written, is independent of later edits to (or deletion of) the
 * option it matched. A choice answer instead snapshots that option's pricing
 * onto cost/per_diem_days/per_diem_scope, all null for a free-text answer. A
 * multi-value question (Checkbox) records one Answer row per selected option.
 */
class Answer extends Model
{
    use HasFactory, HasRegistrationTable;

    protected $fillable = ['question_id', 'owner_type', 'owner_id', 'value', 'cost', 'per_diem_days', 'per_diem_scope'];

    protected $casts = [
        'cost' => 'decimal:2',
        'per_diem_days' => 'integer',
        'per_diem_scope' => PerDiemScope::class,
    ];

    /** The question this answers. */
    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }

    /** The answering entity — a host user or a Group. */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }
}
