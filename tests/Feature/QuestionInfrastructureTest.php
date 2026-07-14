<?php

namespace ConferenceTools\Registration\Tests\Feature;

use ConferenceTools\Registration\Enums\BooleanOperator;
use ConferenceTools\Registration\Enums\ConditionOperator;
use ConferenceTools\Registration\Enums\QuestionScope;
use ConferenceTools\Registration\Enums\QuestionType;
use ConferenceTools\Registration\Models\Answer;
use ConferenceTools\Registration\Models\Condition;
use ConferenceTools\Registration\Models\ConditionGroup;
use ConferenceTools\Registration\Models\Group;
use ConferenceTools\Registration\Models\Question;
use ConferenceTools\Registration\Models\QuestionOption;
use ConferenceTools\Registration\Models\Section;
use ConferenceTools\Registration\Tests\Concerns\BuildsRegistrationData;
use ConferenceTools\Registration\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestDox;

/** Feature tests for Question Infrastructure. */
#[TestDox('Question Infrastructure')]
class QuestionInfrastructureTest extends TestCase
{
    use BuildsRegistrationData, RefreshDatabase;

    #[TestDox('tables use the registration prefix')]
    public function test_tables_use_the_registration_prefix(): void
    {
        $this->assertSame('registration_sections', (new Section)->getTable());
        $this->assertSame('registration_questions', (new Question)->getTable());
        $this->assertSame('registration_question_options', (new QuestionOption)->getTable());
        $this->assertSame('registration_answers', (new Answer)->getTable());
        $this->assertSame('registration_condition_groups', (new ConditionGroup)->getTable());
        $this->assertSame('registration_conditions', (new Condition)->getTable());
    }

    #[TestDox('section orders its questions by position')]
    public function test_section_orders_its_questions_by_position(): void
    {
        $section = Section::factory()->create();
        $second = Question::factory()->for($section)->create(['position' => 2]);
        $first = Question::factory()->for($section)->create(['position' => 1]);

        $this->assertSame(
            [$first->id, $second->id],
            $section->questions->pluck('id')->all()
        );
    }

    #[TestDox('scope is cast and scoped query filters and orders')]
    public function test_scope_is_cast_and_scoped_query_filters_and_orders(): void
    {
        Section::factory()->group()->create(['position' => 0]);
        $p2 = Section::factory()->create(['position' => 2]);
        $p1 = Section::factory()->create(['position' => 1]);

        // The protected system-questions section (seeded by migration) is
        // also Participant-scope; exclude it, unrelated to this scope/order check.
        $participant = Section::forScope(QuestionScope::Participant)->get()->where('is_system', false);

        $this->assertInstanceOf(QuestionScope::class, $participant->first()->scope);
        $this->assertSame([$p1->id, $p2->id], $participant->pluck('id')->all());
    }

    #[TestDox('choice question owns priced options')]
    public function test_choice_question_owns_priced_options(): void
    {
        $question = Question::factory()->ofType(QuestionType::Radio)->create();
        QuestionOption::factory()->for($question)->create(['cost' => null]);
        QuestionOption::factory()->for($question)->priced(100)->create();

        $question->load('options');

        $this->assertTrue($question->usesOptions());
        $this->assertTrue($question->isPriced());
        $this->assertCount(2, $question->options);
    }

    #[TestDox('participant and group answers use polymorphic owner')]
    public function test_participant_and_group_answers_use_polymorphic_owner(): void
    {
        $group = Group::factory()->create();
        $user = $this->makeUser();

        $participantQuestion = Question::factory()->create();
        $groupQuestion = Question::factory()->create();

        $userAnswer = $participantQuestion->answers()->create([
            'owner_type' => $user->getMorphClass(),
            'owner_id' => $user->getKey(),
            'value' => 'Lovelace',
        ]);
        $groupAnswer = $groupQuestion->answers()->create([
            'owner_type' => $group->getMorphClass(),
            'owner_id' => $group->getKey(),
            'value' => 'Analytical Engines Ltd',
        ]);

        $this->assertTrue($user->is($userAnswer->fresh()->owner));
        $this->assertTrue($group->is($groupAnswer->fresh()->owner));
    }

    #[TestDox('choice answer snapshots the options cost')]
    public function test_choice_answer_snapshots_the_options_cost(): void
    {
        $question = Question::factory()->ofType(QuestionType::Radio)->create();
        $option = QuestionOption::factory()->for($question)->priced(50)->create();
        $user = $this->makeUser();

        $answer = $question->answers()->create([
            'owner_type' => $user->getMorphClass(),
            'owner_id' => $user->getKey(),
            'value' => $option->value,
            'cost' => $option->cost,
        ]);

        $this->assertEqualsWithDelta(50.0, (float) $answer->fresh()->cost, 0.001);
    }

    #[TestDox('nested condition tree is navigable')]
    public function test_nested_condition_tree_is_navigable(): void
    {
        $shown = Question::factory()->create();
        $controlling = Question::factory()->create();

        $root = $shown->conditionGroups()->create(['operator' => BooleanOperator::Or]);
        $child = ConditionGroup::factory()->create([
            'parent_group_id' => $root->id,
            'operator' => BooleanOperator::And,
        ]);
        $condition = $child->conditions()->create([
            'question_id' => $controlling->id,
            'operator' => ConditionOperator::Equals,
            'value' => 'yes',
        ]);

        $root = $shown->conditionGroups()->with('children.conditions')->first();

        $this->assertSame(BooleanOperator::Or, $root->operator);
        $this->assertTrue($root->children->first()->is($child));
        $this->assertTrue($root->children->first()->conditions->first()->is($condition));
        $this->assertTrue($controlling->is($condition->question));
    }

    #[TestDox('deleting a section cascades to questions and options')]
    public function test_deleting_a_section_cascades_to_questions_and_options(): void
    {
        $section = Section::factory()->create();
        $question = Question::factory()->for($section)->ofType(QuestionType::Select)->create();
        $option = QuestionOption::factory()->for($question)->create();

        $section->delete();

        $this->assertDatabaseMissing((new Question)->getTable(), ['id' => $question->id]);
        $this->assertDatabaseMissing((new QuestionOption)->getTable(), ['id' => $option->id]);
    }

    #[TestDox('deleting an option leaves existing answers untouched')]
    public function test_deleting_an_option_leaves_existing_answers_untouched(): void
    {
        $question = Question::factory()->ofType(QuestionType::Radio)->create();
        $option = QuestionOption::factory()->for($question)->priced(50)->create();
        $user = $this->makeUser();

        $answer = $question->answers()->create([
            'owner_type' => $user->getMorphClass(),
            'owner_id' => $user->getKey(),
            'value' => $option->value,
            'cost' => $option->cost,
        ]);

        $option->delete();

        $fresh = $answer->fresh();
        $this->assertSame($answer->value, $fresh->value);
        $this->assertEqualsWithDelta(50.0, (float) $fresh->cost, 0.001);
    }
}
