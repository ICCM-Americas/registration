<?php

namespace ConferenceTools\Registration\Models;

use ConferenceTools\Registration\Enums\ReportColumnDisplay;
use ConferenceTools\Registration\Enums\ReportColumnMappingGuest;
use ConferenceTools\Registration\Enums\ReportField;
use ConferenceTools\Registration\Models\Concerns\HasRegistrationTable;
use ConferenceTools\Registration\Services\ReportRunner;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * One column of an admin-defined report: a configured question's answer, a
 * built-in field, or — when neither question_id nor field is set — a blank
 * custom column with only a header, for the admin to fill in by hand. May
 * carry its own per-row rule tree — a failing rule blanks the cell for that
 * row. A question column may also nominate guest_question_id, a second
 * question its cell reads from on guest rows instead of question_id — one
 * column, two sources, e.g. a registrant's own consent question against
 * their guest's own equivalent. A question column in "mapped" display also
 * carries its own $mapping: a list of {value, guest, text} entries (see
 * {@see ReportColumnMappingGuest} and
 * {@see ReportRunner}) — a blank value
 * matches any stored answer, and the guest filter lets the same column show
 * different text on guest rows (e.g. the registrant's or their guest's
 * name), independent of any other column on the same question.
 */
class ReportColumn extends Model
{
    use HasFactory, HasRegistrationTable;

    protected $fillable = [
        'report_id', 'question_id', 'guest_question_id', 'field', 'display', 'mapping', 'header', 'position',
    ];

    protected $casts = [
        'display' => ReportColumnDisplay::class,
        'field' => ReportField::class,
        'mapping' => 'array',
        'position' => 'integer',
    ];

    /** Model-level lifecycle wiring for a column and its rule tree. */
    protected static function booted(): void
    {
        // The per-row rule tree is polymorphic — no DB cascade cleans it up.
        static::deleting(fn (ReportColumn $column) => $column->conditionGroups()->get()->each->delete());
    }

    /** The report this column belongs to. */
    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }

    /** The configured question this column shows, for question columns. */
    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }

    /** The question this column shows on a guest row instead, when nominated. */
    public function guestQuestion(): BelongsTo
    {
        return $this->belongsTo(Question::class, 'guest_question_id');
    }

    /**
     * The root of this column's per-row rule, if any. A column with no root
     * group shows its cell on every row.
     */
    public function conditionGroups(): MorphMany
    {
        return $this->morphMany(ConditionGroup::class, 'conditionable')->whereNull('parent_group_id');
    }

    /** The column's heading: the admin override, question label, or built-in label. */
    public function heading(): string
    {
        return $this->header
            ?? $this->question?->label
            ?? $this->field?->label()
            ?? '';
    }
}
