<?php

namespace ConferenceTools\Registration\Models;

use ConferenceTools\Registration\Enums\BooleanOperator;
use ConferenceTools\Registration\Enums\ConditionSubject;
use ConferenceTools\Registration\Models\Concerns\HasRegistrationTable;
use ConferenceTools\Registration\Services\VisibilityEvaluator;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A node in a visibility rule tree. It combines its child conditions and child
 * groups with AND or OR. A root group is attached to the question or section it
 * controls (the polymorphic "conditionable"); nested groups instead point at a
 * parent group. Evaluating the root group yields a section's/question's
 * visibility for a given set of answers.
 */
class ConditionGroup extends Model
{
    use HasFactory, HasRegistrationTable;

    protected $fillable = [
        'conditionable_type', 'conditionable_id', 'parent_group_id', 'operator', 'position',
    ];

    protected $casts = [
        'operator' => BooleanOperator::class,
        'position' => 'integer',
    ];

    /** The question or section this rule controls (root groups only). */
    public function conditionable(): MorphTo
    {
        return $this->morphTo();
    }

    /** Parent group, for nested groups. */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_group_id');
    }

    /** Nested child groups, in evaluation order. */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_group_id')->orderBy('position');
    }

    /** Leaf conditions in this group, in evaluation order. */
    public function conditions(): HasMany
    {
        return $this->hasMany(Condition::class)->orderBy('position');
    }

    /**
     * Plain-array form of this group's rule subtree, for handing the same
     * AND/OR logic to the browser (the client-side visibility toggle mirrors
     * {@see VisibilityEvaluator}). Each condition is keyed by the controlling
     * question's key, or a subject condition's own reserved key (see
     * {@see ConditionSubject}), so the
     * client can read it straight from the form inputs by that same name.
     *
     * @return array{operator: string, conditions: array<int, array<string, mixed>>, groups: array<int, mixed>}
     */
    public function toRule(): array
    {
        return [
            'operator' => $this->operator->value,
            'conditions' => $this->conditions->map(fn (Condition $c): array => [
                'key' => $c->subject?->value ?? $c->question?->key,
                'operator' => $c->operator->value,
                'value' => $c->value,
            ])->values()->all(),
            'groups' => $this->children->map(fn (self $g): array => $g->toRule())->values()->all(),
        ];
    }

    /**
     * Ids of every question this rule subtree tests (the controlling questions),
     * gathered recursively. Used to tell whether a rule depends only on questions
     * within the same wizard step (safe to toggle client-side) or on answers from
     * other steps (must be decided on the server). A subject condition (no
     * question_id) contributes nothing here — it isn't cross-step, since a
     * subject like the guest's own type is answered right there on the guest
     * form, not on an earlier step.
     *
     * @return array<int, int>
     */
    public function controllingQuestionIds(): array
    {
        return $this->conditions->pluck('question_id')
            ->filter()
            ->concat($this->children->flatMap(fn (self $g): array => $g->controllingQuestionIds()))
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }
}
