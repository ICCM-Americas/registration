<?php

namespace ConferenceTools\Registration\Http\Controllers\Admin;

use ConferenceTools\Registration\Enums\QuestionScope;
use ConferenceTools\Registration\Models\Condition;
use ConferenceTools\Registration\Models\ConditionGroup;
use ConferenceTools\Registration\Models\ReportColumn;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Builds one report column's per-row cell rule — when the cell shows on a
 * given row (a failing rule blanks it). On guest rows the rule sees the
 * registrant's answers overlaid with the guest's own, so both Participant-
 * and Guest-scope questions may control it. The tree editing is shared with
 * the question-level editor ({@see VisibilityRuleController}); columns have
 * no "always hidden" state and their rules never lock.
 */
class ReportColumnVisibilityController extends VisibilityRuleController
{
    /** Open the editor for a column's cell rule. */
    public function edit(ReportColumn $column)
    {
        return $this->editor($column);
    }

    /** Start a cell rule on a column. */
    public function storeRoot(ReportColumn $column)
    {
        return $this->storeRootFor($column);
    }

    /** Remove a column's whole cell rule. */
    public function destroyRule(ReportColumn $column)
    {
        return $this->destroyRuleFor($column);
    }

    /** Add a subgroup to a column's cell rule. */
    public function storeGroup(Request $request, ReportColumn $column)
    {
        return $this->storeGroupFor($request, $column);
    }

    /** Switch a group's AND/OR operator. */
    public function updateGroup(Request $request, ReportColumn $column, ConditionGroup $group)
    {
        return $this->updateGroupFor($request, $column, $group);
    }

    /** Remove a group from a column's cell rule. */
    public function destroyGroup(ReportColumn $column, ConditionGroup $group)
    {
        return $this->destroyGroupFor($column, $group);
    }

    /** Add a leaf condition to a column's cell rule. */
    public function storeCondition(Request $request, ReportColumn $column)
    {
        return $this->storeConditionFor($request, $column);
    }

    /** Remove a leaf condition from a column's cell rule. */
    public function destroyCondition(ReportColumn $column, Condition $condition)
    {
        return $this->destroyConditionFor($column, $condition);
    }

    /** Cell rules run on registrant and guest rows, so both scopes may control them. */
    protected function controllingQuestions(Model $node): Collection
    {
        return $this->questions->questionsForScope(QuestionScope::Participant)
            ->concat($this->questions->questionsForScope(QuestionScope::Guest))
            ->values();
    }

    /** @return list<string> */
    protected function editorEagerLoads(): array
    {
        return ['question', 'conditionGroups.conditions.question'];
    }

    /** @return array<string, mixed> */
    protected function editorData(Model $node): array
    {
        return [
            'visPrefix' => 'admin.report_columns',
            'canHide' => false,
            'noun' => __('registration::admin.visibility_noun_report_column'),
            'tags' => ['conditional' => $node->conditionGroups->isNotEmpty()],
            'intro' => __('registration::admin.report_cell_rules_modal_intro'),
            'subjectLabel' => $node->heading(),
            'subjectKey' => $node->question?->key ?? $node->field?->value ?? '',
        ];
    }
}
