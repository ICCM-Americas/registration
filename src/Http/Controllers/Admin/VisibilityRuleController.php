<?php

namespace ConferenceTools\Registration\Http\Controllers\Admin;

use ConferenceTools\Registration\Enums\BooleanOperator;
use ConferenceTools\Registration\Enums\ConditionOperator;
use ConferenceTools\Registration\Enums\ConditionSubject;
use ConferenceTools\Registration\Http\Controllers\Controller;
use ConferenceTools\Registration\Models\Condition;
use ConferenceTools\Registration\Models\ConditionGroup;
use ConferenceTools\Registration\Services\QuestionRepository;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * Shared editing of a visibility rule — the nested AND/OR tree of condition
 * groups and conditions attached to a conditionable node (a question, or one
 * option of a choice question). Every action is a plain form submit that
 * mutates the tree and returns the refreshed editor fragment, so the structure
 * lives on the server and needs no client logic beyond the console's modal.
 *
 * Subclasses bind the concrete node type in their route actions and say which
 * questions may control the node and what the editor fragment shows. Rules
 * are never locked — not even once answers are — so a rule that a renamed
 * option value broke can always be fixed.
 */
abstract class VisibilityRuleController extends Controller
{
    public function __construct(protected QuestionRepository $questions) {}

    /** Questions whose answers this node's rule may test. */
    abstract protected function controllingQuestions(Model $node): Collection;

    /**
     * Built-in subjects (not a question's answer) this node's rule may test
     * instead — none by default; overridden where one is meaningful (see
     * {@see QuestionVisibilityController::controllingSubjects()}).
     *
     * @return list<ConditionSubject>
     */
    protected function controllingSubjects(Model $node): array
    {
        return [];
    }

    /**
     * The node-type-specific presentation of the editor fragment: visPrefix,
     * canHide, noun, tags, intro, subjectLabel, subjectKey.
     *
     * @return array<string, mixed>
     */
    abstract protected function editorData(Model $node): array;

    /**
     * Relations the editor fragment needs eager-loaded on the node.
     *
     * @return list<string>
     */
    protected function editorEagerLoads(): array
    {
        return ['conditionGroups.conditions.question'];
    }

    /**
     * The editor fragment the console fetches into its modal; mutations
     * return it refreshed so the modal swaps content in place.
     */
    protected function editor(Model $node)
    {
        $node->load(...$this->editorEagerLoads());
        $questionGroups = $this->questionGroups($node);

        return view('registration::admin.questions.visibility-editor', array_merge([
            'node' => $node,
            'rootGroups' => $node->conditionGroups,
            'questionGroups' => $questionGroups,
            'pickedScope' => $this->pickedScope($questionGroups),
            'controllingSubjects' => $this->controllingSubjects($node),
            'booleanOperators' => BooleanOperator::cases(),
            'conditionOperators' => ConditionOperator::cases(),
        ], $this->editorData($node)));
    }

    /**
     * The controlling questions grouped by scope, in first-seen order, for
     * the condition picker's registrant/guest toggle.
     *
     * @return list<array{scope: string, label: string, questions: Collection}>
     */
    private function questionGroups(Model $node): array
    {
        return EloquentCollection::make($this->controllingQuestions($node)->all())
            ->loadMissing('section')
            ->groupBy(fn ($question): string => $question->section->scope->value)
            ->map(fn (Collection $questions, string $scope): array => [
                'scope' => $scope,
                'label' => __('registration::admin.visibility_scope_'.$scope),
                'questions' => $questions->values(),
            ])
            ->values()
            ->all();
    }

    /** The picker's selected scope: the one just posted, else the first. */
    private function pickedScope(array $questionGroups): ?string
    {
        $scopes = array_column($questionGroups, 'scope');
        $requested = request()->input('question_scope');

        return in_array($requested, $scopes, true) ? $requested : ($scopes[0] ?? null);
    }

    /** Start a rule: attach a root group to the node. */
    protected function storeRootFor(Model $node)
    {
        $node->conditionGroups()->create(['operator' => BooleanOperator::And->value]);

        return $this->editor($node);
    }

    /** Remove the whole rule — the node becomes unconditional (always shown). */
    protected function destroyRuleFor(Model $node)
    {
        $node->conditionGroups()->get()->each->delete();

        return $this->editor($node);
    }

    /** Add a nested subgroup under an existing group. */
    protected function storeGroupFor(Request $request, Model $node)
    {
        $data = $request->validate([
            'parent_group_id' => ['required', $this->groupBelongsTo($node)],
            'operator' => ['required', Rule::in(BooleanOperator::values())],
        ]);

        ConditionGroup::create([
            'parent_group_id' => $data['parent_group_id'],
            'operator' => $data['operator'],
        ]);

        return $this->editor($node);
    }

    /** Switch an existing group's AND/OR operator. */
    protected function updateGroupFor(Request $request, Model $node, ConditionGroup $group)
    {
        abort_unless($this->ownsGroup($node, $group), 404);

        $group->update($request->validate([
            'operator' => ['required', Rule::in(BooleanOperator::values())],
        ]));

        return $this->editor($node);
    }

    /** Remove a group and its subtree from the rule. */
    protected function destroyGroupFor(Model $node, ConditionGroup $group)
    {
        abort_unless($this->ownsGroup($node, $group), 404);

        $group->delete();

        return $this->editor($node);
    }

    /**
     * Add a leaf condition to a group: the answer of another question, or a
     * built-in subject (see {@see controllingSubjects()}), {operator} value —
     * exactly one of question_id and subject.
     */
    protected function storeConditionFor(Request $request, Model $node)
    {
        $subjectValues = array_map(fn (ConditionSubject $s): string => $s->value, $this->controllingSubjects($node));

        $data = $request->validate([
            'condition_group_id' => ['required', $this->groupBelongsTo($node)],
            'question_id' => ['required_without:subject', 'nullable', Rule::in($this->controllingQuestions($node)->pluck('id')->all())],
            'subject' => ['required_without:question_id', 'nullable', Rule::in($subjectValues)],
            'operator' => ['required', Rule::in(ConditionOperator::values())],
            'value' => ['nullable', 'string', 'max:255'],
        ]);

        $operator = ConditionOperator::from($data['operator']);

        Condition::create([
            'condition_group_id' => $data['condition_group_id'],
            'question_id' => $data['question_id'] ?? null,
            'subject' => $data['subject'] ?? null,
            'operator' => $operator->value,
            'value' => $operator->needsValue() ? $data['value'] : null,
        ]);

        return $this->editor($node);
    }

    /** Remove one leaf condition from the rule. */
    protected function destroyConditionFor(Model $node, Condition $condition)
    {
        abort_unless($this->ownsGroup($node, $condition->group), 404);

        $condition->delete();

        return $this->editor($node);
    }

    /** A validation rule asserting a group id belongs to this node's tree. */
    private function groupBelongsTo(Model $node): callable
    {
        return function (string $attribute, $value, $fail) use ($node) {
            $group = ConditionGroup::find($value);
            if (! $group || ! $this->ownsGroup($node, $group)) {
                $fail(__('That group does not belong to this question.'));
            }
        };
    }

    /** Whether $group is part of $node's rule tree (walks up to a root group). */
    protected function ownsGroup(Model $node, ?ConditionGroup $group): bool
    {
        while ($group) {
            if ($group->parent_group_id === null) {
                return $group->conditionable_type === $node->getMorphClass()
                    && (int) $group->conditionable_id === (int) $node->getKey();
            }
            $group = $group->parent;
        }

        return false;
    }
}
