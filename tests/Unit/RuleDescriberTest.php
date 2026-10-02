<?php

namespace ConferenceTools\Registration\Tests\Unit;

use ConferenceTools\Registration\Enums\ConditionOperator;
use ConferenceTools\Registration\Enums\ConditionSubject;
use ConferenceTools\Registration\Models\Condition;
use ConferenceTools\Registration\Models\ConditionGroup;
use ConferenceTools\Registration\Models\Question;
use ConferenceTools\Registration\Support\Search\RuleDescriber;
use ConferenceTools\Registration\Tests\TestCase;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Lang;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;

/** Reading visibility rule conditions as sentences for the admin search. */
#[TestDox('Rule Describer')]
class RuleDescriberTest extends TestCase
{
    /** Operator, stored value, and the expected sentence. */
    public static function conditions(): array
    {
        return [
            'equals' => [ConditionOperator::Equals, 'veg', 'Diet (diet) equals veg'],
            'list operator spaced' => [ConditionOperator::NotIn, 'veg,vegan , halal', 'Diet (diet) is not one of veg, vegan, halal'],
            'presence operator without its value' => [ConditionOperator::IsAnswered, 'ignored', 'Diet (diet) is answered'],
        ];
    }

    #[DataProvider('conditions')]
    #[TestDox('describes a question condition: $_dataName')]
    public function test_describes_a_question_condition(ConditionOperator $operator, string $value, string $expected): void
    {
        $this->assertSame($expected, (new RuleDescriber)->describe($this->condition($operator, $value)));
    }

    #[TestDox('describes a built-in subject condition by its label')]
    public function test_describes_a_built_in_subject_condition_by_its_label(): void
    {
        $condition = new Condition(['subject' => ConditionSubject::GuestType->value, 'operator' => ConditionOperator::Equals->value, 'value' => 'minor']);
        $condition->setRelation('question', null);

        $this->assertSame(ConditionSubject::GuestType->label().' equals minor', (new RuleDescriber)->describe($condition));
    }

    #[TestDox('lists a nested rule depth-first, one line per condition')]
    public function test_lists_a_nested_rule_depth_first(): void
    {
        $child = $this->group([$this->condition(ConditionOperator::Equals, 'b')], []);
        $root = $this->group([$this->condition(ConditionOperator::Equals, 'a')], [$child]);

        $this->assertSame(['Diet (diet) equals a', 'Diet (diet) equals b'], (new RuleDescriber)->lines($root));
    }

    #[TestDox('has wording for every condition operator')]
    public function test_has_wording_for_every_condition_operator(): void
    {
        foreach (ConditionOperator::cases() as $operator) {
            $this->assertTrue(Lang::has('registration::admin.search_operator_'.$operator->value, 'en'), $operator->value);
        }
    }

    /** An unsaved condition on a "Diet" question. */
    private function condition(ConditionOperator $operator, string $value): Condition
    {
        $condition = new Condition(['operator' => $operator->value, 'value' => $value]);
        $condition->setRelation('question', new Question(['key' => 'diet', 'label' => 'Diet']));

        return $condition;
    }

    /** An unsaved group with the given conditions and child groups. */
    private function group(array $conditions, array $children): ConditionGroup
    {
        $group = new ConditionGroup;
        $group->setRelation('conditions', new Collection($conditions));
        $group->setRelation('children', new Collection($children));

        return $group;
    }
}
