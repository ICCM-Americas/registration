<?php

namespace ConferenceTools\Registration\Tests\Feature;

use ConferenceTools\Registration\Enums\BooleanOperator;
use ConferenceTools\Registration\Enums\ConditionOperator;
use ConferenceTools\Registration\Enums\QuestionScope;
use ConferenceTools\Registration\Models\Condition;
use ConferenceTools\Registration\Models\Currency;
use ConferenceTools\Registration\Models\Draft;
use ConferenceTools\Registration\Models\Group;
use ConferenceTools\Registration\Models\Question;
use ConferenceTools\Registration\Models\QuestionOption;
use ConferenceTools\Registration\Models\ReportColumn;
use ConferenceTools\Registration\Models\Section;
use ConferenceTools\Registration\Tests\Concerns\BuildsRegistrationData;
use ConferenceTools\Registration\Tests\Fixtures\QuestionConfigSeeder;
use ConferenceTools\Registration\Tests\Fixtures\User;
use ConferenceTools\Registration\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestDox;

/** Feature tests for Models. */
#[TestDox('Models')]
class ModelsTest extends TestCase
{
    use BuildsRegistrationData, RefreshDatabase;

    #[TestDox('currency convert and format')]
    public function test_currency_convert_and_format(): void
    {
        $currency = new Currency(['symbol' => '€', 'rate' => 2]);

        $this->assertSame(20.0, $currency->convert(10));
        $this->assertSame('€ 1,234.50', $currency->format(1234.5));
    }

    #[TestDox('currency def returns the default currency')]
    public function test_currency_def_returns_the_default_currency(): void
    {
        Currency::factory()->create(['code' => 'GBP', 'def' => false]);
        $default = $this->defaultCurrency();

        $this->assertTrue($default->is(Currency::def()));
    }

    #[TestDox('currency def is null when no default')]
    public function test_currency_def_is_null_when_no_default(): void
    {
        Currency::factory()->create(['def' => false]);

        $this->assertNull(Currency::def());
    }

    #[TestDox('has registration table prefixes derived names and honors explicit table')]
    public function test_has_registration_table_prefixes_derived_names_and_honors_explicit_table(): void
    {
        $this->assertSame('registration_groups', (new Group)->getTable());
        $this->assertSame('registration_question_options', (new QuestionOption)->getTable());

        $explicit = new QuestionOption;
        $explicit->setTable('custom_table');
        $this->assertSame('custom_table', $explicit->getTable());
    }

    #[TestDox('group relationships admin and cost')]
    public function test_group_relationships_admin_and_cost(): void
    {
        $group = $this->makeGroupWithMembers();

        // two members, each accommodation 100 + product 20 = 120 → 240 total.
        $this->assertEqualsWithDelta(240.0, $group->cost(), 0.001);
        $this->assertInstanceOf(User::class, $group->admin());
        $this->assertTrue((bool) $group->admin()->is_group_admin);
        $this->assertCount(2, $group->users);
    }

    #[TestDox('interacts with registration helpers')]
    public function test_interacts_with_registration_helpers(): void
    {
        $this->defaultCurrency();
        $this->seed(QuestionConfigSeeder::class);

        $user = $this->makeUser();
        $this->storeAnswers($user, QuestionScope::Participant, [
            'name' => 'Ada', 'lastname' => 'Lovelace', 'nickname' => 'Speedy',
            'passport' => 'Ada Lovelace', 'gender' => 'f', 'residence' => 'UK',
            'accommodation' => 'hotel', 'products' => ['dinner'],
        ]);

        // cost comes from the EAV answers, not from typed columns.
        // accommodation "hotel" (100) + product "dinner" (20) = 120.
        $this->assertEqualsWithDelta(120.0, $user->cost(), 0.001);
        $this->assertSame('$ 120.00', $user->currencyString());

        // The thin profile accessors read straight from the answer store.
        $this->assertSame('Ada Lovelace', $user->passport);
        $this->assertSame('f', $user->gender);
        $this->assertSame('UK', $user->residence);
        $this->assertSame('Lovelace', $user->lastname);
        $this->assertSame('Speedy', $user->nickname);
    }

    #[TestDox('cost with no answers is zero')]
    public function test_cost_with_no_answers_is_zero(): void
    {
        $user = $this->makeUser();

        $this->assertSame(0.0, $user->cost());
    }

    #[TestDox('condition tree relationships')]
    public function test_condition_tree_relationships(): void
    {
        // A root group is attached to the section/question it controls...
        $section = Section::factory()->create();
        $group = $section->conditionGroups()->create(['operator' => BooleanOperator::And, 'position' => 0]);
        $this->assertTrue($group->fresh()->conditionable->is($section));

        // ...and a condition belongs back to its group.
        $question = Question::factory()->create();
        $condition = Condition::create([
            'condition_group_id' => $group->id,
            'question_id' => $question->id,
            'operator' => ConditionOperator::Equals,
            'value' => 'x',
            'position' => 0,
        ]);
        $this->assertTrue($condition->group->is($group));
    }

    #[TestDox('draft current question relationship')]
    public function test_draft_current_question_relationship(): void
    {
        $section = Section::factory()->create();
        $question = Question::factory()->for($section)->create();
        $draft = Draft::factory()->create(['current_question_id' => $question->id]);

        $this->assertTrue($draft->currentQuestion->is($question));
    }

    #[TestDox('group profile accessors read from the answer store')]
    public function test_group_profile_accessors_read_from_the_answer_store(): void
    {
        // orgtype = business, website/state left unanswered.
        $group = $this->makeGroupWithMembers();

        $this->assertSame('1 Babbage St', $group->address);
        $this->assertSame('London', $group->town);
        $this->assertSame('00000', $group->zipcode);
        $this->assertSame('UK', $group->country);
        $this->assertSame('12345', $group->telephone);
        $this->assertNull($group->website);
        $this->assertNull($group->state);
        // A concrete orgtype is returned as-is (the non-"other" branch).
        $this->assertSame('business', $group->org_type);

        // orgtype = "other" resolves to the free-text answer instead.
        $other = Group::factory()->create();
        $this->storeAnswers($other, QuestionScope::Group, [
            'organization' => 'X', 'orgtype' => 'other', 'orgtypeother' => 'Nonprofit',
            'address' => 'A', 'town' => 'T', 'zipcode' => 'Z', 'country' => 'C', 'telephone' => '1',
            'website' => 'https://x.example', 'state' => 'CA',
        ]);
        $this->assertSame('Nonprofit', $other->org_type);
        $this->assertSame('https://x.example', $other->website);
        $this->assertSame('CA', $other->state);
    }

    #[TestDox('deleting a question removes its report columns and their rule trees')]
    public function test_deleting_a_question_removes_its_report_columns_and_their_rule_trees(): void
    {
        $question = Question::factory()->create();
        $column = ReportColumn::factory()->create(['question_id' => $question->id]);
        $group = $column->conditionGroups()->create(['operator' => BooleanOperator::And->value]);

        $question->delete();

        // The DB cascade alone would drop the column row but orphan the
        // polymorphic rule tree — the model event deletes both.
        $this->assertNull(ReportColumn::find($column->id));
        $this->assertDatabaseMissing($group->getTable(), ['id' => $group->id]);
    }
}
