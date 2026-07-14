<?php

namespace ConferenceTools\Registration\Models;

use ConferenceTools\Registration\Enums\QuestionScope;
use ConferenceTools\Registration\Models\Concerns\HasRegistrationTable;
use ConferenceTools\Registration\Models\Concerns\TranslatesFields;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * One wizard step of the registration form. A section groups questions that are
 * shown together on a single view; sections are ordered within their scope and
 * their questions ordered within them (both drag-and-drop reorderable). A
 * section may itself be made conditional via its visibility rule.
 */
class Section extends Model
{
    use HasFactory, HasRegistrationTable, TranslatesFields;

    protected $fillable = ['scope', 'key', 'title', 'description', 'position', 'enabled', 'is_system'];

    /** The fields admins may translate. */
    public function translatableFields(): array
    {
        return ['title', 'description'];
    }

    /** Model-level lifecycle wiring for a section and its dependents. */
    protected static function booted(): void
    {
        // The DB would cascade-delete the questions without firing their model
        // events, orphaning their (and their options') translations; delete
        // them as models instead.
        static::deleting(fn (Section $section) => $section->questions()->get()->each->delete());
    }

    protected $casts = [
        'scope' => QuestionScope::class,
        'position' => 'integer',
        'enabled' => 'boolean',
        'is_system' => 'boolean',
    ];

    /** Questions on this step, in display order. */
    public function questions(): HasMany
    {
        return $this->hasMany(Question::class)->orderBy('position');
    }

    /**
     * The root of this section's visibility rule, if any. A section with no root
     * group is always shown.
     */
    public function conditionGroups(): MorphMany
    {
        return $this->morphMany(ConditionGroup::class, 'conditionable')->whereNull('parent_group_id');
    }

    /**
     * Whether this section is marked never-visible ("never show") in the
     * builder — the disabled state, which keeps the step out of the wizard
     * entirely and overrides its questions' own visibility.
     */
    public function isHidden(): bool
    {
        return ! $this->enabled;
    }

    /** Sections of the given scope, in display order. */
    public function scopeForScope($query, QuestionScope|string $scope)
    {
        return $query->where('scope', $scope instanceof QuestionScope ? $scope->value : $scope)
            ->orderBy('position');
    }
}
