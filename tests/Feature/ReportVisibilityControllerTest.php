<?php

namespace ConferenceTools\Registration\Tests\Feature;

use ConferenceTools\Registration\Enums\BooleanOperator;
use ConferenceTools\Registration\Enums\ConditionOperator;
use ConferenceTools\Registration\Models\Condition;
use ConferenceTools\Registration\Models\ConditionGroup;
use ConferenceTools\Registration\Models\Question;
use ConferenceTools\Registration\Models\Report;
use ConferenceTools\Registration\Tests\Concerns\BuildsReportData;
use ConferenceTools\Registration\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * The report row-rule editor: the shared rule-tree editing is exercised
 * through {@see QuestionVisibilityControllerTest}; here we cover what is
 * report-specific — the editor fragment, the rule lifecycle on a report, the
 * Participant-only controlling questions, ownership across node types, and
 * the absence of the answer lock.
 */
#[TestDox('Report Visibility Controller')]
class ReportVisibilityControllerTest extends TestCase
{
    use BuildsReportData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->allowRegistrationManagement();
        $this->seedReportQuestions();
    }

    #[TestDox('the editor renders for a report without the hidden state')]
    public function test_the_editor_renders_for_a_report_without_the_hidden_state(): void
    {
        $report = Report::factory()->create(['name' => 'Kitchen Crew']);

        $this->actingAs($this->makeUser())
            ->get(route('registration.admin.reports.visibility', $report))
            ->assertOk()
            ->assertSee('Kitchen Crew')
            ->assertSee('data-tags=\'{"conditional":false}\'', false)
            ->assertDontSee(__('registration::admin.visibility_never_set'));
    }

    #[TestDox('requires the gate')]
    public function test_requires_the_gate(): void
    {
        $this->denyRegistrationManagement();

        $this->actingAs($this->makeUser())
            ->get(route('registration.admin.reports.visibility', Report::factory()->create()))
            ->assertForbidden();
    }

    #[TestDox('can start a rule add a condition and remove the rule')]
    public function test_can_start_a_rule_add_a_condition_and_remove_the_rule(): void
    {
        $admin = $this->makeUser();
        $report = Report::factory()->create();
        $controlling = Question::firstWhere('key', 'firsttime');

        $this->actingAs($admin)
            ->post(route('registration.admin.reports.rule.store', $report))
            ->assertOk()
            ->assertSee('data-tags=\'{"conditional":true}\'', false);

        $group = $report->fresh()->conditionGroups->sole();

        $this->actingAs($admin)->post(route('registration.admin.reports.conditions.store', $report), [
            'condition_group_id' => $group->id,
            'question_id' => $controlling->id,
            'operator' => ConditionOperator::In->value,
            'value' => 'yes',
        ])->assertOk();

        $condition = Condition::where('condition_group_id', $group->id)->sole();
        $this->assertSame($controlling->id, $condition->question_id);

        $this->actingAs($admin)
            ->delete(route('registration.admin.reports.rule.destroy', $report))
            ->assertOk();

        $this->assertTrue($report->fresh()->conditionGroups->isEmpty());
        $this->assertNull(Condition::find($condition->id));
    }

    #[TestDox('only participant scope questions may control a row rule')]
    public function test_only_participant_scope_questions_may_control_a_row_rule(): void
    {
        $report = Report::factory()->create();
        $group = $report->conditionGroups()->create(['operator' => BooleanOperator::And->value]);

        $this->actingAs($this->makeUser())->postJson(route('registration.admin.reports.conditions.store', $report), [
            'condition_group_id' => $group->id,
            'question_id' => Question::firstWhere('key', 'guestname')->id,
            'operator' => ConditionOperator::IsAnswered->value,
        ])->assertStatus(422)->assertJsonValidationErrors('question_id');
    }

    #[TestDox('ownership distinguishes node types not just ids')]
    public function test_ownership_distinguishes_node_types_not_just_ids(): void
    {
        // A question's rule group must not be reachable through a report
        // editor: ownership compares the morph type, not just the id.
        $question = Question::firstWhere('key', 'firsttime');
        $foreignGroup = $question->conditionGroups()->create(['operator' => BooleanOperator::And->value]);

        $this->actingAs($this->makeUser())
            ->delete(route('registration.admin.reports.groups.destroy', [Report::factory()->create(), $foreignGroup]))
            ->assertNotFound();

        $this->assertNotNull(ConditionGroup::find($foreignGroup->id));
    }

    #[TestDox('row rules never honor the answer lock')]
    public function test_row_rules_never_honor_the_answer_lock(): void
    {
        // admin() seeds real answers, which locks question/option rules —
        // report rules must stay editable mid-conference regardless.
        $admin = $this->admin();
        $report = Report::factory()->create();

        $this->actingAs($admin)
            ->post(route('registration.admin.reports.rule.store', $report))
            ->assertOk();

        $this->assertCount(1, $report->fresh()->conditionGroups);
    }
}
