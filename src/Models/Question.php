<?php

namespace ConferenceTools\Registration\Models;

use ConferenceTools\Registration\Database\Seeders\SystemQuestionsSeeder;
use ConferenceTools\Registration\Enums\QuestionType;
use ConferenceTools\Registration\Models\Concerns\HasRegistrationTable;
use ConferenceTools\Registration\Models\Concerns\TranslatesFields;
use ConferenceTools\Registration\Services\RegistrationWizard;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A single configurable question. Its scope is inherited from its section. The
 * "key" is the stable machine name used as the answer key (and, during the
 * migration of the legacy questions, as the bridge to the old typed columns).
 * Choice questions ({@see QuestionType::usesOptions()}) own a set of options,
 * any of which may carry a cost. "translate_value" is meaningful only for
 * such questions: when set, a chosen option's own translated "value" is what
 * gets recorded on the answer (resolved for the registrant's locale) instead
 * of the raw selected value.
 */
class Question extends Model
{
    use HasFactory, HasRegistrationTable, TranslatesFields;

    /** The fixed key of the seeded system question gating the Guest List hub — see {@see SystemQuestionsSeeder}. */
    public const GUEST_TRIGGER_KEY = 'guest_registering';

    /** The fixed key of the seeded system question gating the Group Member hub. */
    public const GROUP_TRIGGER_KEY = 'group_registering';

    /** The fixed key of the seeded system question collecting an invited group member's name. */
    public const GROUP_MEMBER_NAME_KEY = 'group_member_name';

    /** The fixed key of the seeded system question collecting an invited group member's email address. */
    public const GROUP_MEMBER_EMAIL_KEY = 'group_member_email';

    /** The canonical (non-translated) option value meaning "yes" for a YesNo question. */
    public const YES_VALUE = 'Yes';

    /**
     * Whether $key is one of the seeded trigger questions that gets the
     * wizard's reactive, one-question-at-a-time treatment (an immediate
     * detour to its own hub on a "Yes" answer) instead of batching with its
     * section's other questions — see {@see RegistrationWizard}.
     */
    public static function isTriggerKey(string $key): bool
    {
        return in_array($key, [self::GUEST_TRIGGER_KEY, self::GROUP_TRIGGER_KEY], true);
    }

    protected $fillable = [
        'section_id', 'key', 'type', 'label', 'help_text', 'placeholder',
        'translate_value', 'required', 'position', 'config', 'enabled', 'is_system',
    ];

    /** The fields admins may translate. */
    public function translatableFields(): array
    {
        return ['label', 'help_text', 'placeholder'];
    }

    /** Model-level lifecycle wiring for a question and its dependents. */
    protected static function booted(): void
    {
        // The DB would cascade-delete the options and report columns without
        // firing their model events, orphaning the options' translations and
        // the columns' per-row rule trees; delete them as models instead.
        static::deleting(function (Question $question) {
            $question->options()->get()->each->delete();
            ReportColumn::where('question_id', $question->id)->get()->each->delete();
        });
    }

    protected $casts = [
        'type' => QuestionType::class,
        'required' => 'boolean',
        'translate_value' => 'boolean',
        'position' => 'integer',
        'config' => 'array',
        'enabled' => 'boolean',
        'is_system' => 'boolean',
    ];

    /** The section (wizard step) this question is shown on. */
    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    /** Selectable options for choice questions, in display order. */
    public function options(): HasMany
    {
        return $this->hasMany(QuestionOption::class)->orderBy('position');
    }

    /** Stored answers to this question (across all owners). */
    public function answers(): HasMany
    {
        return $this->hasMany(Answer::class);
    }

    /**
     * The root of this question's visibility rule, if any. A question with no
     * root group is always shown.
     */
    public function conditionGroups(): MorphMany
    {
        return $this->morphMany(ConditionGroup::class, 'conditionable')->whereNull('parent_group_id');
    }

    /** Whether the question presents predefined options. */
    public function usesOptions(): bool
    {
        return $this->type->usesOptions();
    }

    /**
     * Whether this question is marked never-visible ("always hidden") in the
     * builder. This overrides any visibility rule — see {@see VisibilityEvaluator}.
     */
    public function isHidden(): bool
    {
        return (bool) data_get($this->config, 'hidden', false);
    }

    /**
     * A question counts as translated when it or any of its options has a
     * translation — the options are edited on the question's translations page.
     */
    public function isTranslated(): bool
    {
        return $this->translations->isNotEmpty()
            || $this->options->contains(fn (QuestionOption $o): bool => $o->isTranslated());
    }

    /** Whether any of this question's options carry a cost (priced question). */
    public function isPriced(): bool
    {
        return $this->options->contains(fn (QuestionOption $o): bool => $o->cost !== null);
    }

    /**
     * Ids of the questions whose answers this question's visibility depends on
     * (across its whole rule tree). Empty when the question is unconditional.
     *
     * @return array<int, int>
     */
    public function controllingQuestionIds(): array
    {
        return $this->conditionGroups
            ->flatMap(fn (ConditionGroup $g): array => $g->controllingQuestionIds())
            ->unique()
            ->values()
            ->all();
    }
}
