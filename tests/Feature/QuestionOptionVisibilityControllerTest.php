<?php

namespace ConferenceTools\Registration\Tests\Feature;

use ConferenceTools\Registration\Enums\BooleanOperator;
use ConferenceTools\Registration\Enums\ConditionOperator;
use ConferenceTools\Registration\Models\Condition;
use ConferenceTools\Registration\Models\ConditionGroup;
use ConferenceTools\Registration\Models\Question;
use ConferenceTools\Registration\Models\QuestionOption;
use ConferenceTools\Registration\Tests\Concerns\BuildsRegistrationData;
use ConferenceTools\Registration\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * The per-option visibility editor: the shared rule-tree editing is exercised
 * through {@see QuestionVisibilityControllerTest}; here we cover what is
 * option-specific — the editor fragment, the rule lifecycle on an option, the
 * controlling-question restrictions and ownership across node types.
 */
#[TestDox('Question Option Visibility Controller')]
class QuestionOptionVisibilityControllerTest extends TestCase
{
    use BuildsRegistrationData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->allowRegistrationManagement();
        $this->seedQuestionConfig();
    }

    /** One option of the question, by value. */
    private function option(string $questionKey, string $value): QuestionOption
    {
        return Question::firstWhere('key', $questionKey)->options()->firstWhere('value', $value);
    }

    #[TestDox('the editor renders for an option without the hidden state')]
    public function test_the_editor_renders_for_an_option_without_the_hidden_state(): void
    {
        $admin = $this->makeUser();
        $option = $this->option('accommodation', 'hotel');

        $this->actingAs($admin)
            ->get(route('registration.admin.options.visibility', $option))
            ->assertOk()
            // Option tags carry no "hidden" flag, and the never-visible
            // buttons are question-only.
            ->assertSee('data-tags=\'{"conditional":false}\'', false)
            ->assertDontSee(__('registration::admin.visibility_never_set'));
    }

    #[TestDox('requires the gate')]
    public function test_requires_the_gate(): void
    {
        $this->denyRegistrationManagement();

        $this->actingAs($this->makeUser())
            ->get(route('registration.admin.options.visibility', $this->option('accommodation', 'hotel')))
            ->assertForbidden();
    }

    #[TestDox('can start a rule add a condition and remove the rule')]
    public function test_can_start_a_rule_add_a_condition_and_remove_the_rule(): void
    {
        $admin = $this->makeUser();
        $option = $this->option('accommodation', 'hotel');
        $controlling = Question::firstWhere('key', 'gender');

        $this->actingAs($admin)
            ->post(route('registration.admin.options.rule.store', $option))
            ->assertOk()
            ->assertSee('data-tags=\'{"conditional":true}\'', false);

        $group = $option->fresh()->conditionGroups->sole();

        $this->actingAs($admin)->post(route('registration.admin.options.conditions.store', $option), [
            'condition_group_id' => $group->id,
            'question_id' => $controlling->id,
            'operator' => ConditionOperator::Equals->value,
            'value' => 'f',
        ])->assertOk();

        $condition = Condition::where('condition_group_id', $group->id)->sole();
        $this->assertSame($controlling->id, $condition->question_id);

        $this->actingAs($admin)
            ->delete(route('registration.admin.options.rule.destroy', $option))
            ->assertOk();

        $this->assertTrue($option->fresh()->conditionGroups->isEmpty());
        $this->assertNull(Condition::find($condition->id));
    }

    #[TestDox('an option cannot be controlled by its own question')]
    public function test_an_option_cannot_be_controlled_by_its_own_question(): void
    {
        $admin = $this->makeUser();
        $option = $this->option('accommodation', 'hotel');
        $group = $option->conditionGroups()->create(['operator' => BooleanOperator::And->value]);

        $this->actingAs($admin)->postJson(route('registration.admin.options.conditions.store', $option), [
            'condition_group_id' => $group->id,
            'question_id' => $option->question_id,
            'operator' => ConditionOperator::Equals->value,
            'value' => 'hotel',
        ])->assertStatus(422)->assertJsonValidationErrors('question_id');
    }

    #[TestDox('ownership distinguishes node types not just ids')]
    public function test_ownership_distinguishes_node_types_not_just_ids(): void
    {
        // A question's rule group must not be reachable through an option
        // editor — even via an option whose id happens to match: ownership
        // compares the morph type, not just the id.
        $question = Question::firstWhere('key', 'nickname');
        $foreignGroup = $question->conditionGroups()->create(['operator' => BooleanOperator::And->value]);

        $this->actingAs($this->makeUser())
            ->delete(route('registration.admin.options.groups.destroy', [$this->option('accommodation', 'hotel'), $foreignGroup]))
            ->assertNotFound();

        $this->assertNotNull(ConditionGroup::find($foreignGroup->id));
    }

    #[TestDox('deleting an option removes its rule tree')]
    public function test_deleting_an_option_removes_its_rule_tree(): void
    {
        $option = $this->option('accommodation', 'hotel');
        $group = $option->conditionGroups()->create(['operator' => BooleanOperator::And->value]);
        $group->conditions()->create([
            'question_id' => Question::firstWhere('key', 'gender')->id,
            'operator' => ConditionOperator::Equals->value,
            'value' => 'f',
        ]);

        $option->delete();

        $this->assertNull(ConditionGroup::find($group->id));
        $this->assertSame(0, Condition::where('condition_group_id', $group->id)->count());
    }

    /**
     * admin() seeds real answers via its group, unlike every test above
     * (which deliberately uses an answer-free makeUser() actor) — rules stay
     * editable even then, so one a renamed option value broke can be fixed.
     */
    #[TestDox('the rule stays editable once answers are locked')]
    public function test_the_rule_stays_editable_once_answers_are_locked(): void
    {
        $admin = $this->admin();
        $option = $this->option('accommodation', 'hotel');

        $this->actingAs($admin)
            ->get(route('registration.admin.options.visibility', $option))
            ->assertOk()
            ->assertDontSee('disabled', false);

        $this->actingAs($admin)
            ->postJson(route('registration.admin.options.rule.store', $option))
            ->assertOk();

        $this->assertCount(1, $option->fresh()->conditionGroups);
    }
}
