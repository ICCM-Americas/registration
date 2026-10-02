<?php

namespace ConferenceTools\Registration\Tests\Feature;

use ConferenceTools\Registration\Enums\BooleanOperator;
use ConferenceTools\Registration\Enums\ConditionOperator;
use ConferenceTools\Registration\Enums\ConditionSubject;
use ConferenceTools\Registration\Enums\QuestionScope;
use ConferenceTools\Registration\Enums\QuestionType;
use ConferenceTools\Registration\Http\Controllers\Admin\QuestionVisibilityController;
use ConferenceTools\Registration\Models\Condition;
use ConferenceTools\Registration\Models\ConditionGroup;
use ConferenceTools\Registration\Models\Question;
use ConferenceTools\Registration\Models\Section;
use ConferenceTools\Registration\Tests\Concerns\BuildsRegistrationData;
use ConferenceTools\Registration\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestDox;

/** Feature tests for Question Visibility Controller. */
#[TestDox('Question Visibility Controller')]
class QuestionVisibilityControllerTest extends TestCase
{
    use BuildsRegistrationData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->allowRegistrationManagement();
        $this->seedQuestionConfig();
    }

    #[TestDox('the editor renders an existing rule with its tag state')]
    public function test_the_editor_renders_an_existing_rule_with_its_tag_state(): void
    {
        // orgtypeother is seeded with a rule (visible when orgtype == other),
        // so its "conditional" tag is on.
        $question = Question::where('key', 'orgtypeother')->first();

        $this->actingAs($this->makeUser())
            ->get(route('registration.admin.questions.visibility', $question))
            ->assertOk()
            ->assertSee('orgtype', false)
            ->assertSee('data-tags=\'{"hidden":false,"conditional":true}\'', false);
    }

    #[TestDox('requires the gate')]
    public function test_requires_the_gate(): void
    {
        $this->denyRegistrationManagement();
        $question = Question::where('key', 'nickname')->first();

        $this->actingAs($this->makeUser())
            ->get(route('registration.admin.questions.visibility', $question))
            ->assertForbidden();
    }

    #[TestDox('can start and remove a rule')]
    public function test_can_start_and_remove_a_rule(): void
    {
        $question = Question::where('key', 'nickname')->first();
        $this->assertTrue($question->conditionGroups->isEmpty());

        // Mutations return the refreshed fragment (the modal swaps it in
        // place) carrying the row's new tag state.
        $this->actingAs($this->makeUser())
            ->post(route('registration.admin.questions.rule.store', $question))
            ->assertOk()
            ->assertSee('data-tags=\'{"hidden":false,"conditional":true}\'', false);

        $this->assertCount(1, $question->fresh()->conditionGroups);

        $this->actingAs($this->makeUser())
            ->delete(route('registration.admin.questions.rule.destroy', $question))
            ->assertOk();

        $this->assertTrue($question->fresh()->conditionGroups->isEmpty());
    }

    /** A Guest-scope question, for the guest_type-subject coverage below. */
    private function guestScopeQuestion(): Question
    {
        $section = Section::create([
            'scope' => QuestionScope::Guest->value, 'key' => 'guest-details', 'title' => 'Guest Details', 'position' => 0, 'enabled' => true,
        ]);

        return Question::create([
            'section_id' => $section->id, 'key' => 'guest_photos', 'type' => QuestionType::Radio->value,
            'label' => 'Photos?', 'position' => 0, 'required' => false, 'enabled' => true,
        ]);
    }

    #[TestDox('a Guest-scope question offers Guest Type as a controlling subject; other scopes do not')]
    public function test_a_guest_scope_question_offers_guest_type_as_a_controlling_subject(): void
    {
        // The subject picker lives in the add-condition form, which only
        // renders once a rule (root group) exists.
        $guestQuestion = $this->guestScopeQuestion();
        $guestQuestion->conditionGroups()->create(['operator' => BooleanOperator::And->value]);

        $this->actingAs($this->makeUser())
            ->get(route('registration.admin.questions.visibility', $guestQuestion))
            ->assertOk()
            ->assertSee(__('registration::admin.condition_subject_guest_type'));

        $participantQuestion = Question::where('key', 'nickname')->first();
        $participantQuestion->conditionGroups()->create(['operator' => BooleanOperator::And->value]);

        $this->actingAs($this->makeUser())
            ->get(route('registration.admin.questions.visibility', $participantQuestion))
            ->assertOk()
            ->assertDontSee(__('registration::admin.condition_subject_guest_type'));
    }

    #[TestDox('can add a subject condition to a Guest-scope question')]
    public function test_can_add_a_subject_condition_to_a_guest_scope_question(): void
    {
        $guestQuestion = $this->guestScopeQuestion();
        $group = $guestQuestion->conditionGroups()->create(['operator' => BooleanOperator::And->value]);

        $this->actingAs($this->makeUser())->post(route('registration.admin.questions.conditions.store', $guestQuestion), [
            'condition_group_id' => $group->id,
            'subject' => ConditionSubject::GuestType->value,
            'operator' => ConditionOperator::Equals->value,
            'value' => 'adult',
        ])->assertOk();

        $condition = Condition::where('condition_group_id', $group->id)->first();
        $this->assertNull($condition->question_id);
        $this->assertSame(ConditionSubject::GuestType, $condition->subject);
        $this->assertSame('adult', $condition->value);
    }

    #[TestDox('a subject condition is rejected on a non-Guest-scope question')]
    public function test_a_subject_condition_is_rejected_on_a_non_guest_scope_question(): void
    {
        $question = Question::where('key', 'nickname')->first();
        $group = $question->conditionGroups()->create(['operator' => BooleanOperator::And->value]);

        $this->actingAs($this->makeUser())->postJson(route('registration.admin.questions.conditions.store', $question), [
            'condition_group_id' => $group->id,
            'subject' => ConditionSubject::GuestType->value,
            'operator' => ConditionOperator::Equals->value,
            'value' => 'adult',
        ])->assertStatus(422)->assertJsonValidationErrors('subject');
    }

    #[TestDox('can add a condition to a group')]
    public function test_can_add_a_condition_to_a_group(): void
    {
        $question = Question::where('key', 'nickname')->first();
        $controlling = Question::where('key', 'gender')->first();
        $group = $question->conditionGroups()->create(['operator' => BooleanOperator::And->value]);

        $this->actingAs($this->makeUser())->post(route('registration.admin.questions.conditions.store', $question), [
            'condition_group_id' => $group->id,
            'question_id' => $controlling->id,
            'operator' => ConditionOperator::Equals->value,
            'value' => 'f',
        ])->assertOk();

        $condition = Condition::where('condition_group_id', $group->id)->first();
        $this->assertSame($controlling->id, $condition->question_id);
        $this->assertSame('f', $condition->value);
    }

    #[TestDox('presence operator drops the value')]
    public function test_presence_operator_drops_the_value(): void
    {
        $question = Question::where('key', 'nickname')->first();
        $controlling = Question::where('key', 'gender')->first();
        $group = $question->conditionGroups()->create(['operator' => BooleanOperator::And->value]);

        $this->actingAs($this->makeUser())->post(route('registration.admin.questions.conditions.store', $question), [
            'condition_group_id' => $group->id,
            'question_id' => $controlling->id,
            'operator' => ConditionOperator::IsAnswered->value,
            'value' => 'ignored',
        ])->assertOk();

        $this->assertNull(Condition::where('condition_group_id', $group->id)->first()->value);
    }

    #[TestDox('can nest a subgroup and change its operator')]
    public function test_can_nest_a_subgroup_and_change_its_operator(): void
    {
        $question = Question::where('key', 'nickname')->first();
        $root = $question->conditionGroups()->create(['operator' => BooleanOperator::And->value]);

        $this->actingAs($this->makeUser())->post(route('registration.admin.questions.groups.store', $question), [
            'parent_group_id' => $root->id,
            'operator' => BooleanOperator::Or->value,
        ])->assertOk();

        $child = ConditionGroup::where('parent_group_id', $root->id)->first();
        $this->assertSame(BooleanOperator::Or, $child->operator);

        $this->actingAs($this->makeUser())->patch(route('registration.admin.questions.groups.update', [$question, $child]), [
            'operator' => BooleanOperator::And->value,
        ])->assertOk();

        $this->assertSame(BooleanOperator::And, $child->fresh()->operator);
    }

    #[TestDox('hide and show toggle the never visible flag')]
    public function test_hide_and_show_toggle_the_never_visible_flag(): void
    {
        $question = Question::where('key', 'nickname')->first();
        // A rule that hiding must discard.
        $question->conditionGroups()->create(['operator' => BooleanOperator::And->value]);

        $this->actingAs($this->makeUser())
            ->post(route('registration.admin.questions.hide', $question))
            ->assertOk()
            ->assertSee('data-tags=\'{"hidden":true,"conditional":false}\'', false);

        $question->refresh();
        $this->assertTrue($question->isHidden());
        $this->assertTrue($question->conditionGroups->isEmpty());

        $this->actingAs($this->makeUser())
            ->post(route('registration.admin.questions.show', $question))
            ->assertOk();

        $this->assertFalse($question->fresh()->isHidden());
    }

    #[TestDox('can delete an owned group and condition')]
    public function test_can_delete_an_owned_group_and_condition(): void
    {
        $question = Question::where('key', 'nickname')->first();
        $controlling = Question::where('key', 'gender')->first();
        $root = $question->conditionGroups()->create(['operator' => BooleanOperator::And->value]);
        $child = ConditionGroup::create(['parent_group_id' => $root->id, 'operator' => BooleanOperator::Or->value]);
        $condition = Condition::create([
            'condition_group_id' => $root->id, 'question_id' => $controlling->id,
            'operator' => ConditionOperator::Equals->value, 'value' => 'f',
        ]);

        // A nested subgroup that belongs to this question (via its root) is removable.
        $this->actingAs($this->makeUser())
            ->delete(route('registration.admin.questions.groups.destroy', [$question, $child]))
            ->assertOk();
        $this->assertNull(ConditionGroup::find($child->id));

        $this->actingAs($this->makeUser())
            ->delete(route('registration.admin.questions.conditions.destroy', [$question, $condition]))
            ->assertOk();
        $this->assertNull(Condition::find($condition->id));
    }

    #[TestDox('deleting a condition in a foreign tree 404s')]
    public function test_deleting_a_condition_in_a_foreign_tree_404s(): void
    {
        $question = Question::where('key', 'nickname')->first();
        $other = Question::where('key', 'passport')->first();
        $foreignGroup = $other->conditionGroups()->create(['operator' => BooleanOperator::And->value]);
        $foreignCondition = Condition::create([
            'condition_group_id' => $foreignGroup->id,
            'question_id' => Question::where('key', 'gender')->first()->id,
            'operator' => ConditionOperator::IsAnswered->value, 'value' => null,
        ]);

        $this->actingAs($this->makeUser())
            ->delete(route('registration.admin.questions.conditions.destroy', [$question, $foreignCondition]))
            ->assertNotFound();
    }

    #[TestDox('ownership walk rejects a missing group')]
    public function test_ownership_walk_rejects_a_missing_group(): void
    {
        // The ownership walk is defensive about a null group (a condition whose
        // group row vanished): it must deny, not fail. Unreachable over HTTP while
        // foreign keys hold, so the guard is exercised directly.
        $question = Question::where('key', 'nickname')->first();

        $method = new \ReflectionMethod(QuestionVisibilityController::class, 'ownsGroup');

        $this->assertFalse($method->invoke(app(QuestionVisibilityController::class), $question, null));
    }

    #[TestDox('cannot touch a group belonging to another question')]
    public function test_cannot_touch_a_group_belonging_to_another_question(): void
    {
        $question = Question::where('key', 'nickname')->first();
        $other = Question::where('key', 'passport')->first();
        $foreignGroup = $other->conditionGroups()->create(['operator' => BooleanOperator::And->value]);

        // Adding a condition referencing a group from another question is
        // rejected — as 422 JSON, which the modal's fetch() displays.
        $this->actingAs($this->makeUser())->postJson(route('registration.admin.questions.conditions.store', $question), [
            'condition_group_id' => $foreignGroup->id,
            'question_id' => Question::where('key', 'gender')->first()->id,
            'operator' => ConditionOperator::Equals->value,
            'value' => 'f',
        ])->assertStatus(422)->assertJsonValidationErrors('condition_group_id');

        // And deleting the foreign group through this question 404s.
        $this->actingAs($this->makeUser())
            ->delete(route('registration.admin.questions.groups.destroy', [$question, $foreignGroup]))
            ->assertNotFound();
    }

    /**
     * admin() seeds real answers via its group, unlike every test above
     * (which deliberately uses an answer-free makeUser() actor) — rules stay
     * editable even then, so one a renamed option value broke can be fixed.
     */
    #[TestDox('the rule stays editable once answers are locked')]
    public function test_the_rule_stays_editable_once_answers_are_locked(): void
    {
        $question = Question::where('key', 'nickname')->first();
        $admin = $this->admin();

        $this->actingAs($admin)
            ->get(route('registration.admin.questions.visibility', $question))
            ->assertOk()
            ->assertDontSee('disabled', false);

        $this->actingAs($admin)
            ->postJson(route('registration.admin.questions.rule.store', $question))
            ->assertOk();

        $this->assertCount(1, $question->fresh()->conditionGroups);
    }

    #[TestDox('hide and show work once answers are locked')]
    public function test_hide_and_show_work_once_answers_are_locked(): void
    {
        $question = Question::where('key', 'nickname')->first();
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('registration.admin.questions.hide', $question))->assertOk();
        $this->assertTrue($question->fresh()->isHidden());

        $this->actingAs($admin)->post(route('registration.admin.questions.show', $question))->assertOk();
        $this->assertFalse($question->fresh()->isHidden());
    }
}
