<?php

namespace ConferenceTools\Registration\Http\Controllers\Admin;

use ConferenceTools\Registration\Enums\ConditionSubject;
use ConferenceTools\Registration\Enums\QuestionScope;
use ConferenceTools\Registration\Models\Condition;
use ConferenceTools\Registration\Models\ConditionGroup;
use ConferenceTools\Registration\Models\Question;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Builds a question's visibility rule — the nested AND/OR tree of condition
 * groups and conditions that decides when the question is shown. The tree
 * editing itself is shared with the per-option editor
 * ({@see VisibilityRuleController}); this subclass adds the question-only
 * "always hidden" flag.
 */
class QuestionVisibilityController extends VisibilityRuleController
{
    /** Open the editor for a question's rule. */
    public function edit(Question $question)
    {
        return $this->editor($question);
    }

    /** Start a rule on a question. */
    public function storeRoot(Question $question)
    {
        return $this->storeRootFor($question);
    }

    /** Remove a question's whole rule. */
    public function destroyRule(Question $question)
    {
        return $this->destroyRuleFor($question);
    }

    /** Mark the question never-visible ("always hidden"); any rule is discarded. */
    public function hide(Question $question)
    {
        $question->conditionGroups()->get()->each->delete();
        $question->update(['config' => array_merge($question->config ?? [], ['hidden' => true])]);

        return $this->editor($question);
    }

    /** Clear the never-visible flag — the question becomes always shown. */
    public function show(Question $question)
    {
        $config = $question->config ?? [];
        unset($config['hidden']);
        $question->update(['config' => $config ?: null]);

        return $this->editor($question);
    }

    /** Add a subgroup to a question's rule. */
    public function storeGroup(Request $request, Question $question)
    {
        return $this->storeGroupFor($request, $question);
    }

    /** Switch a group's AND/OR operator. */
    public function updateGroup(Request $request, Question $question, ConditionGroup $group)
    {
        return $this->updateGroupFor($request, $question, $group);
    }

    /** Remove a group from a question's rule. */
    public function destroyGroup(Question $question, ConditionGroup $group)
    {
        return $this->destroyGroupFor($question, $group);
    }

    /** Add a leaf condition to a question's rule. */
    public function storeCondition(Request $request, Question $question)
    {
        return $this->storeConditionFor($request, $question);
    }

    /** Remove a leaf condition from a question's rule. */
    public function destroyCondition(Question $question, Condition $condition)
    {
        return $this->destroyConditionFor($question, $condition);
    }

    /** Questions whose answers this question's rule may test: same scope, not itself. */
    protected function controllingQuestions(Model $node): Collection
    {
        return $this->questions->questionsForScope($node->section->scope)
            ->reject(fn (Question $q) => $q->id === $node->id)
            ->values();
    }

    /**
     * A Guest-scope question may also test the guest's own type (adult or
     * minor) — meaningless for any other scope, since only a guest answers
     * as a specific, typed guest.
     *
     * @return list<ConditionSubject>
     */
    protected function controllingSubjects(Model $node): array
    {
        return $node->section->scope === QuestionScope::Guest ? [ConditionSubject::GuestType] : [];
    }

    /** @return list<string> */
    protected function editorEagerLoads(): array
    {
        return ['section', 'conditionGroups.conditions.question'];
    }

    /** @return array<string, mixed> */
    protected function editorData(Model $node): array
    {
        return [
            'visPrefix' => 'admin.questions',
            'canHide' => true,
            'noun' => __('registration::admin.visibility_noun_question'),
            'tags' => ['hidden' => $node->isHidden(), 'conditional' => $node->conditionGroups->isNotEmpty()],
            'intro' => __('registration::admin.visibility_intro'),
            'subjectLabel' => $node->label,
            'subjectKey' => $node->key,
        ];
    }
}
