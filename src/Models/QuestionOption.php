<?php

namespace ConferenceTools\Registration\Models;

use ConferenceTools\Registration\Enums\PerDiemScope;
use ConferenceTools\Registration\Models\Concerns\HasRegistrationTable;
use ConferenceTools\Registration\Models\Concerns\TranslatesFields;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * One selectable option of a choice question. "cost" (in the base currency) is
 * set on options that affect the registrant's total; a null cost is a free
 * choice. "per_diem_days" adds room & board days to the registrant's stay,
 * priced from the per-diem rates rather than a baked-in cost, and "per_diem_scope"
 * says whether those days also cover an accompanying guest.
 *
 * An option may carry its own visibility rule (see {@see conditionGroups()}),
 * offering it only to registrants whose earlier answers match. Several options
 * may share a value — conditionally-offered variants that differ in label —
 * though since answers now record the resolved value text rather than a
 * reference to the option row, such variants are no longer distinguishable
 * from stored answers alone.
 *
 * "value" is translatable (alongside "label" and "description"): a question's
 * translate_value flag, when set, resolves this field for the registrant's
 * locale before interpolating it into the stored answer — e.g. a "value" of
 * "Mr. {q:lastname.value}" (base), "M. {q:lastname.value}" (fr-FR), "Hr.
 * {q:lastname.value}" (de-DE) lets a single option store a locale-appropriate
 * display name. "value" should always be human-readable, since (unlike
 * "label", which is only ever shown in the wizard) it is what gets stored on
 * the answer and read directly by reports.
 */
class QuestionOption extends Model
{
    use HasFactory, HasRegistrationTable, TranslatesFields;

    protected $fillable = ['question_id', 'value', 'label', 'description', 'cost', 'per_diem_days', 'per_diem_scope', 'position', 'is_default'];

    /** Model-level lifecycle wiring for an option and its dependents. */
    protected static function booted(): void
    {
        // No polymorphic FK exists, so remove the option's visibility rule with
        // it (the FK cascade then clears the nested groups and conditions).
        static::deleting(fn (QuestionOption $option) => $option->conditionGroups()->get()->each->delete());
    }

    /** The fields admins may translate. */
    public function translatableFields(): array
    {
        return ['label', 'description', 'value'];
    }

    protected $casts = [
        'cost' => 'decimal:2',
        'per_diem_days' => 'integer',
        'per_diem_scope' => PerDiemScope::class,
        'position' => 'integer',
        'is_default' => 'boolean',
    ];

    /** Whether choosing this option adds any per-diem days to the stay. */
    public function contributesPerDiem(): bool
    {
        return $this->per_diem_days > 0;
    }

    /** The question this option belongs to. */
    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }

    /**
     * The root of this option's visibility rule, if any. An option with no
     * root group is always offered (whenever its question is shown).
     */
    public function conditionGroups(): MorphMany
    {
        return $this->morphMany(ConditionGroup::class, 'conditionable')->whereNull('parent_group_id');
    }
}
