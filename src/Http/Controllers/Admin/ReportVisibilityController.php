<?php

namespace ConferenceTools\Registration\Http\Controllers\Admin;

use ConferenceTools\Registration\Enums\QuestionScope;
use ConferenceTools\Registration\Enums\ReportType;
use ConferenceTools\Registration\Models\Condition;
use ConferenceTools\Registration\Models\ConditionGroup;
use ConferenceTools\Registration\Models\Report;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Builds a report's row rule — the nested AND/OR tree deciding which rows
 * appear on the report: registrants on a Registrant report (included guests
 * follow them), or registrants and guests each on their own on an Individual
 * report. The tree editing is shared with the question-level editor
 * ({@see VisibilityRuleController}); reports have no "always hidden" state
 * and their rules never lock — admins tune reports mid-conference.
 */
class ReportVisibilityController extends VisibilityRuleController
{
    /** Open the editor for a report's row rule. */
    public function edit(Report $report)
    {
        return $this->editor($report);
    }

    /** Start a row rule on a report. */
    public function storeRoot(Report $report)
    {
        return $this->storeRootFor($report);
    }

    /** Remove a report's whole row rule. */
    public function destroyRule(Report $report)
    {
        return $this->destroyRuleFor($report);
    }

    /** Add a subgroup to a report's row rule. */
    public function storeGroup(Request $request, Report $report)
    {
        return $this->storeGroupFor($request, $report);
    }

    /** Switch a group's AND/OR operator. */
    public function updateGroup(Request $request, Report $report, ConditionGroup $group)
    {
        return $this->updateGroupFor($request, $report, $group);
    }

    /** Remove a group from a report's row rule. */
    public function destroyGroup(Report $report, ConditionGroup $group)
    {
        return $this->destroyGroupFor($report, $group);
    }

    /** Add a leaf condition to a report's row rule. */
    public function storeCondition(Request $request, Report $report)
    {
        return $this->storeConditionFor($request, $report);
    }

    /** Remove a leaf condition from a report's row rule. */
    public function destroyCondition(Report $report, Condition $condition)
    {
        return $this->destroyConditionFor($report, $condition);
    }

    /**
     * A Registrant report's rule tests registrants only; an Individual
     * report's tests guests too.
     */
    protected function controllingQuestions(Model $node): Collection
    {
        $questions = $this->questions->questionsForScope(QuestionScope::Participant);

        return $node->type === ReportType::Individual
            ? $questions->concat($this->questions->questionsForScope(QuestionScope::Guest))->values()
            : $questions;
    }

    /** @return list<string> */
    protected function editorEagerLoads(): array
    {
        return ['conditionGroups.conditions.question.section'];
    }

    /** @return array<string, mixed> */
    protected function editorData(Model $node): array
    {
        return [
            'visPrefix' => 'admin.reports',
            'canHide' => false,
            'noun' => __('registration::admin.visibility_noun_report'),
            'tags' => ['conditional' => $node->conditionGroups->isNotEmpty()],
            'intro' => $node->type === ReportType::Individual
                ? __('registration::admin.report_individual_rules_modal_intro')
                : __('registration::admin.report_rules_modal_intro'),
            'subjectLabel' => $node->name,
            'subjectKey' => '',
        ];
    }
}
