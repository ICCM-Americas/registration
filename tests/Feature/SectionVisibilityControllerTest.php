<?php

namespace ConferenceTools\Registration\Tests\Feature;

use ConferenceTools\Registration\Enums\BooleanOperator;
use ConferenceTools\Registration\Enums\ConditionOperator;
use ConferenceTools\Registration\Models\Condition;
use ConferenceTools\Registration\Models\ConditionGroup;
use ConferenceTools\Registration\Models\Question;
use ConferenceTools\Registration\Models\Section;
use ConferenceTools\Registration\Tests\Concerns\BuildsRegistrationData;
use ConferenceTools\Registration\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestDox;

/** Feature tests for Section Visibility Controller. */
#[TestDox('Section Visibility Controller')]
class SectionVisibilityControllerTest extends TestCase
{
    use BuildsRegistrationData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->allowRegistrationManagement();
        $this->seedQuestionConfig();
    }

    /** The accommodation question fixture. */
    private function accommodation(): Section
    {
        return Section::where('key', 'accommodation')->first();
    }

    #[TestDox('the editor renders with the section noun and tag state')]
    public function test_the_editor_renders_with_the_section_noun_and_tag_state(): void
    {
        $this->actingAs($this->admin())
            ->get(route('registration.admin.sections.visibility', $this->accommodation()))
            ->assertOk()
            // The fixed texts talk about the section (and its questions), not
            // "this question" — the fragment is shared with the other editors.
            ->assertSee(__('registration::admin.visibility_intro_section'))
            ->assertSee('data-tags=\'{"hidden":false,"conditional":false}\'', false);
    }

    #[TestDox('requires the gate')]
    public function test_requires_the_gate(): void
    {
        $this->denyRegistrationManagement();

        $this->actingAs($this->admin())
            ->get(route('registration.admin.sections.visibility', $this->accommodation()))
            ->assertForbidden();
    }

    #[TestDox('controlling questions exclude the sections own')]
    public function test_controlling_questions_exclude_the_sections_own(): void
    {
        // The step's visibility is decided before it renders, so its own
        // questions (accommodation, products) can never control it; the
        // earlier step's questions can.
        $section = $this->accommodation();
        $section->conditionGroups()->create(['operator' => BooleanOperator::And->value]);

        $this->actingAs($this->admin())
            ->get(route('registration.admin.sections.visibility', $section))
            ->assertOk()
            // The controlling-question droplist shows only the key.
            ->assertSee('>gender<', false)
            ->assertDontSee('products');
    }

    #[TestDox('can start and remove a rule')]
    public function test_can_start_and_remove_a_rule(): void
    {
        $section = $this->accommodation();
        $this->assertTrue($section->conditionGroups->isEmpty());

        // Mutations return the refreshed fragment (the modal swaps it in
        // place) carrying the row's new tag state.
        $this->actingAs($this->admin())
            ->post(route('registration.admin.sections.rule.store', $section))
            ->assertOk()
            ->assertSee('data-tags=\'{"hidden":false,"conditional":true}\'', false);

        $this->assertCount(1, $section->fresh()->conditionGroups);

        $this->actingAs($this->admin())
            ->delete(route('registration.admin.sections.rule.destroy', $section))
            ->assertOk();

        $this->assertTrue($section->fresh()->conditionGroups->isEmpty());
    }

    #[TestDox('can add and remove conditions and subgroups')]
    public function test_can_add_and_remove_conditions_and_subgroups(): void
    {
        $section = $this->accommodation();
        $controlling = Question::where('key', 'gender')->first();
        $root = $section->conditionGroups()->create(['operator' => BooleanOperator::And->value]);

        $this->actingAs($this->admin())->post(route('registration.admin.sections.conditions.store', $section), [
            'condition_group_id' => $root->id,
            'question_id' => $controlling->id,
            'operator' => ConditionOperator::Equals->value,
            'value' => 'f',
        ])->assertOk();

        $condition = Condition::where('condition_group_id', $root->id)->first();
        $this->assertSame($controlling->id, $condition->question_id);
        $this->assertSame('f', $condition->value);

        $this->actingAs($this->admin())->post(route('registration.admin.sections.groups.store', $section), [
            'parent_group_id' => $root->id,
            'operator' => BooleanOperator::Or->value,
        ])->assertOk();

        $child = ConditionGroup::where('parent_group_id', $root->id)->first();
        $this->assertSame(BooleanOperator::Or, $child->operator);

        $this->actingAs($this->admin())->patch(route('registration.admin.sections.groups.update', [$section, $child]), [
            'operator' => BooleanOperator::And->value,
        ])->assertOk();

        $this->assertSame(BooleanOperator::And, $child->fresh()->operator);

        $this->actingAs($this->admin())
            ->delete(route('registration.admin.sections.groups.destroy', [$section, $child]))
            ->assertOk();
        $this->assertNull(ConditionGroup::find($child->id));

        $this->actingAs($this->admin())
            ->delete(route('registration.admin.sections.conditions.destroy', [$section, $condition]))
            ->assertOk();
        $this->assertNull(Condition::find($condition->id));
    }

    #[TestDox('a condition cannot test the sections own question')]
    public function test_a_condition_cannot_test_the_sections_own_question(): void
    {
        // The dropdown never offers them, but a crafted submission must be
        // rejected too — as 422 JSON, which the modal's fetch() displays.
        $section = $this->accommodation();
        $root = $section->conditionGroups()->create(['operator' => BooleanOperator::And->value]);

        $this->actingAs($this->admin())->postJson(route('registration.admin.sections.conditions.store', $section), [
            'condition_group_id' => $root->id,
            'question_id' => Question::where('key', 'accommodation')->first()->id,
            'operator' => ConditionOperator::Equals->value,
            'value' => 'hotel',
        ])->assertStatus(422)->assertJsonValidationErrors('question_id');
    }

    #[TestDox('hide and show toggle the enabled flag')]
    public function test_hide_and_show_toggle_the_enabled_flag(): void
    {
        // "Never show" maps to the section's existing enabled flag, and
        // discards any rule; "always show" simply re-enables it.
        $section = $this->accommodation();
        $section->conditionGroups()->create(['operator' => BooleanOperator::And->value]);

        $this->actingAs($this->admin())
            ->post(route('registration.admin.sections.hide', $section))
            ->assertOk()
            ->assertSee('data-tags=\'{"hidden":true,"conditional":false}\'', false);

        $section->refresh();
        $this->assertFalse($section->enabled);
        $this->assertTrue($section->isHidden());
        $this->assertTrue($section->conditionGroups->isEmpty());

        $this->actingAs($this->admin())
            ->post(route('registration.admin.sections.show', $section))
            ->assertOk()
            ->assertSee('data-tags=\'{"hidden":false,"conditional":false}\'', false);

        $this->assertTrue($section->fresh()->enabled);
    }

    #[TestDox('cannot touch a group belonging to another section')]
    public function test_cannot_touch_a_group_belonging_to_another_section(): void
    {
        $section = $this->accommodation();
        $other = Section::where('key', 'your-details')->first();
        $foreignGroup = $other->conditionGroups()->create(['operator' => BooleanOperator::And->value]);

        $this->actingAs($this->admin())->postJson(route('registration.admin.sections.conditions.store', $section), [
            'condition_group_id' => $foreignGroup->id,
            'question_id' => Question::where('key', 'gender')->first()->id,
            'operator' => ConditionOperator::Equals->value,
            'value' => 'f',
        ])->assertStatus(422)->assertJsonValidationErrors('condition_group_id');

        $this->actingAs($this->admin())
            ->delete(route('registration.admin.sections.groups.destroy', [$section, $foreignGroup]))
            ->assertNotFound();
    }

    /**
     * Sections are exempt from the answers-locked rule that governs Question
     * and Option editing (see QuestionVisibilityControllerTest /
     * QuestionOptionVisibilityControllerTest) — every test above already
     * mutates through admin(), whose group seeds real answers, and passes
     * regardless; this pins that down explicitly, including the editor's
     * locked view data (always false for a section).
     */
    #[TestDox('section mutations are never locked')]
    public function test_section_mutations_are_never_locked(): void
    {
        $section = $this->accommodation();
        $admin = $this->admin();

        $this->actingAs($admin)
            ->get(route('registration.admin.sections.visibility', $section))
            ->assertOk()
            ->assertDontSee('class="btn btn-primary" disabled', false);

        $this->actingAs($admin)
            ->post(route('registration.admin.sections.rule.store', $section))
            ->assertOk();

        $this->assertCount(1, $section->fresh()->conditionGroups);
    }
}
