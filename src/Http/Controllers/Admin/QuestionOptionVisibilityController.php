<?php

namespace ConferenceTools\Registration\Http\Controllers\Admin;

use ConferenceTools\Registration\Models\Condition;
use ConferenceTools\Registration\Models\ConditionGroup;
use ConferenceTools\Registration\Models\Question;
use ConferenceTools\Registration\Models\QuestionOption;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Builds one option's visibility rule — when this option of a choice question
 * is offered, based on answers to earlier questions. The tree editing is
 * shared with the question-level editor ({@see VisibilityRuleController});
 * options have no "always hidden" state (remove the option line instead).
 */
class QuestionOptionVisibilityController extends VisibilityRuleController
{
    /** Open the editor for an option's rule. */
    public function edit(QuestionOption $option)
    {
        return $this->editor($option);
    }

    /** Start a rule on an option. */
    public function storeRoot(QuestionOption $option)
    {
        return $this->storeRootFor($option);
    }

    /** Remove an option's whole rule. */
    public function destroyRule(QuestionOption $option)
    {
        return $this->destroyRuleFor($option);
    }

    /** Add a subgroup to an option's rule. */
    public function storeGroup(Request $request, QuestionOption $option)
    {
        return $this->storeGroupFor($request, $option);
    }

    /** Switch a group's AND/OR operator. */
    public function updateGroup(Request $request, QuestionOption $option, ConditionGroup $group)
    {
        return $this->updateGroupFor($request, $option, $group);
    }

    /** Remove a group from an option's rule. */
    public function destroyGroup(QuestionOption $option, ConditionGroup $group)
    {
        return $this->destroyGroupFor($option, $group);
    }

    /** Add a leaf condition to an option's rule. */
    public function storeCondition(Request $request, QuestionOption $option)
    {
        return $this->storeConditionFor($request, $option);
    }

    /** Remove a leaf condition from an option's rule. */
    public function destroyCondition(QuestionOption $option, Condition $condition)
    {
        return $this->destroyConditionFor($option, $condition);
    }

    /** Questions in the option's scope may control it — except its own question. */
    protected function controllingQuestions(Model $node): Collection
    {
        return $this->questions->questionsForScope($node->question->section->scope)
            ->reject(fn (Question $q) => $q->id === $node->question_id)
            ->values();
    }

    /** @return list<string> */
    protected function editorEagerLoads(): array
    {
        return ['question.section', 'conditionGroups.conditions.question'];
    }

    /** @return array<string, mixed> */
    protected function editorData(Model $node): array
    {
        return [
            'visPrefix' => 'admin.options',
            'canHide' => false,
            'noun' => __('registration::admin.visibility_noun_option'),
            'tags' => ['conditional' => $node->conditionGroups->isNotEmpty()],
            'intro' => __('registration::admin.visibility_intro_option'),
            'subjectLabel' => $node->question->label.': '.$node->label,
            'subjectKey' => $node->value,
        ];
    }
}
