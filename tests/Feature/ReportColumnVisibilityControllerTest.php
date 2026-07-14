<?php

namespace ConferenceTools\Registration\Tests\Feature;

use ConferenceTools\Registration\Enums\BooleanOperator;
use ConferenceTools\Registration\Enums\ConditionOperator;
use ConferenceTools\Registration\Enums\ReportField;
use ConferenceTools\Registration\Models\Condition;
use ConferenceTools\Registration\Models\ConditionGroup;
use ConferenceTools\Registration\Models\Question;
use ConferenceTools\Registration\Models\Report;
use ConferenceTools\Registration\Models\ReportColumn;
use ConferenceTools\Registration\Tests\Concerns\BuildsReportData;
use ConferenceTools\Registration\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * The per-column cell-rule editor: the shared rule-tree editing is exercised
 * through {@see QuestionVisibilityControllerTest}; here we cover what is
 * column-specific — the editor fragment, the rule lifecycle on a column, the
 * two-scope controlling questions, ownership, and the absence of the answer
 * lock.
 */
#[TestDox('Report Column Visibility Controller')]
class ReportColumnVisibilityControllerTest extends TestCase
{
    use BuildsReportData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->allowRegistrationManagement();
        $this->seedReportQuestions();
    }

    /** A built-in email column on a fresh report. */
    private function column(): ReportColumn
    {
        return ReportColumn::factory()
            ->builtin(ReportField::Email)
            ->create(['report_id' => Report::factory()->create()->id]);
    }

    #[TestDox('the editor renders for a column without the hidden state')]
    public function test_the_editor_renders_for_a_column_without_the_hidden_state(): void
    {
        $this->actingAs($this->makeUser())
            ->get(route('registration.admin.report_columns.visibility', $this->column()))
            ->assertOk()
            ->assertSee(__('registration::admin.report_builtin_email'))
            ->assertSee('data-tags=\'{"conditional":false}\'', false)
            ->assertDontSee(__('registration::admin.visibility_never_set'));
    }

    #[TestDox('requires the gate')]
    public function test_requires_the_gate(): void
    {
        $this->denyRegistrationManagement();

        $this->actingAs($this->makeUser())
            ->get(route('registration.admin.report_columns.visibility', $this->column()))
            ->assertForbidden();
    }

    #[TestDox('can start a rule add a condition and remove the rule')]
    public function test_can_start_a_rule_add_a_condition_and_remove_the_rule(): void
    {
        $admin = $this->makeUser();
        $column = $this->column();
        $controlling = Question::firstWhere('key', 'directorypref');

        $this->actingAs($admin)
            ->post(route('registration.admin.report_columns.rule.store', $column))
            ->assertOk()
            ->assertSee('data-tags=\'{"conditional":true}\'', false);

        $group = $column->fresh()->conditionGroups->sole();

        $this->actingAs($admin)->post(route('registration.admin.report_columns.conditions.store', $column), [
            'condition_group_id' => $group->id,
            'question_id' => $controlling->id,
            'operator' => ConditionOperator::In->value,
            'value' => 'ShowEmail',
        ])->assertOk();

        $condition = Condition::where('condition_group_id', $group->id)->sole();
        $this->assertSame($controlling->id, $condition->question_id);

        $this->actingAs($admin)
            ->delete(route('registration.admin.report_columns.rule.destroy', $column))
            ->assertOk();

        $this->assertTrue($column->fresh()->conditionGroups->isEmpty());
        $this->assertNull(Condition::find($condition->id));
    }

    #[DataProvider('controllingScopes')]
    #[TestDox('participant and guest scope questions may both control a cell rule')]
    public function test_participant_and_guest_scope_questions_may_both_control_a_cell_rule(string $key): void
    {
        // Cell rules run on guest rows against a merged registrant+guest
        // context, so both scopes are meaningful controllers.
        $column = $this->column();
        $group = $column->conditionGroups()->create(['operator' => BooleanOperator::And->value]);

        $this->actingAs($this->makeUser())->post(route('registration.admin.report_columns.conditions.store', $column), [
            'condition_group_id' => $group->id,
            'question_id' => Question::firstWhere('key', $key)->id,
            'operator' => ConditionOperator::IsAnswered->value,
        ])->assertOk();

        $this->assertSame(1, Condition::where('condition_group_id', $group->id)->count());
    }

    /** The controlling scopes for the data provider. */
    public static function controllingScopes(): array
    {
        return [
            'participant question' => ['directorypref'],
            'guest question' => ['guestphoto'],
        ];
    }

    #[TestDox('ownership distinguishes node types not just ids')]
    public function test_ownership_distinguishes_node_types_not_just_ids(): void
    {
        // A report's row-rule group must not be reachable through a column
        // editor: ownership compares the morph type, not just the id.
        $column = $this->column();
        $foreignGroup = $column->report->conditionGroups()->create(['operator' => BooleanOperator::And->value]);

        $this->actingAs($this->makeUser())
            ->delete(route('registration.admin.report_columns.groups.destroy', [$column, $foreignGroup]))
            ->assertNotFound();

        $this->assertNotNull(ConditionGroup::find($foreignGroup->id));
    }

    #[TestDox('cell rules never honor the answer lock')]
    public function test_cell_rules_never_honor_the_answer_lock(): void
    {
        // admin() seeds real answers, which locks question/option rules —
        // cell rules must stay editable mid-conference regardless.
        $admin = $this->admin();
        $column = $this->column();

        $this->actingAs($admin)
            ->post(route('registration.admin.report_columns.rule.store', $column))
            ->assertOk();

        $this->assertCount(1, $column->fresh()->conditionGroups);
    }
}
