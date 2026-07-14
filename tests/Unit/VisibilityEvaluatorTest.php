<?php

namespace ConferenceTools\Registration\Tests\Unit;

use ConferenceTools\Registration\Enums\BooleanOperator;
use ConferenceTools\Registration\Enums\ConditionOperator;
use ConferenceTools\Registration\Enums\ConditionSubject;
use ConferenceTools\Registration\Models\Condition;
use ConferenceTools\Registration\Models\ConditionGroup;
use ConferenceTools\Registration\Models\Question;
use ConferenceTools\Registration\Models\QuestionOption;
use ConferenceTools\Registration\Models\Section;
use ConferenceTools\Registration\Services\VisibilityEvaluator;
use Illuminate\Database\Eloquent\Collection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Pure-logic coverage of the visibility rule evaluator. The models are built in
 * memory (no database) and wired together with setRelation(), which is all the
 * evaluator reads.
 */
#[TestDox('VisibilityEvaluator')]
class VisibilityEvaluatorTest extends TestCase
{
    /** The evaluator under test, resolved from the container. */
    private function evaluator(): VisibilityEvaluator
    {
        return new VisibilityEvaluator;
    }

    /** A single condition testing controlling-question $ctrlKey. */
    private function condition(ConditionOperator $op, ?string $value, string $ctrlKey = 'q'): Condition
    {
        $condition = new Condition(['operator' => $op, 'value' => $value]);
        $condition->setRelation('question', new Question(['key' => $ctrlKey]));

        return $condition;
    }

    /**
     * @param  array<int, Condition>  $conditions
     * @param  array<int, ConditionGroup>  $children
     */
    private function group(BooleanOperator $operator, array $conditions = [], array $children = []): ConditionGroup
    {
        $group = new ConditionGroup(['operator' => $operator]);
        $group->setRelation('conditions', new Collection($conditions));
        $group->setRelation('children', new Collection($children));

        return $group;
    }

    /** A single condition testing a built-in subject (e.g. the guest's own type) instead of a question. */
    private function subjectCondition(ConditionOperator $op, ?string $value, ConditionSubject $subject): Condition
    {
        return new Condition(['operator' => $op, 'value' => $value, 'subject' => $subject]);
    }

    /**
     * @param  array<int, ConditionGroup>  $groups
     */
    private function question(array $groups = [], bool $hidden = false): Question
    {
        $node = new Question(['key' => 'target', 'config' => $hidden ? ['hidden' => true] : null]);
        $node->setRelation('conditionGroups', new Collection($groups));

        return $node;
    }

    /**
     * @return iterable<string, array{ConditionOperator, ?string, array<string, mixed>, bool}>
     */
    public static function operatorCases(): iterable
    {
        yield 'equals scalar match' => [ConditionOperator::Equals, 'red', ['q' => 'red'], true];
        yield 'equals scalar mismatch' => [ConditionOperator::Equals, 'red', ['q' => 'blue'], false];
        yield 'equals array contains' => [ConditionOperator::Equals, 'red', ['q' => ['blue', 'red']], true];
        yield 'equals array missing' => [ConditionOperator::Equals, 'red', ['q' => ['blue']], false];

        yield 'not-equals match is false' => [ConditionOperator::NotEquals, 'red', ['q' => 'red'], false];
        yield 'not-equals differ is true' => [ConditionOperator::NotEquals, 'red', ['q' => 'blue'], true];

        yield 'in scalar present' => [ConditionOperator::In, 'a, b ,c', ['q' => 'b'], true];
        yield 'in scalar absent' => [ConditionOperator::In, 'a,b,c', ['q' => 'z'], false];
        yield 'in array intersects' => [ConditionOperator::In, 'a,b', ['q' => ['z', 'b']], true];
        yield 'in array disjoint' => [ConditionOperator::In, 'a,b', ['q' => ['z']], false];

        yield 'not-in present is false' => [ConditionOperator::NotIn, 'a,b', ['q' => 'a'], false];
        yield 'not-in absent is true' => [ConditionOperator::NotIn, 'a,b', ['q' => 'z'], true];

        yield 'contains scalar substring' => [ConditionOperator::Contains, 'ell', ['q' => 'hello'], true];
        yield 'contains scalar no substring' => [ConditionOperator::Contains, 'xyz', ['q' => 'hello'], false];
        yield 'contains empty expected is false' => [ConditionOperator::Contains, '', ['q' => 'hello'], false];
        yield 'contains null expected is false' => [ConditionOperator::Contains, null, ['q' => 'hello'], false];
        yield 'contains array has value' => [ConditionOperator::Contains, 'red', ['q' => ['red', 'blue']], true];
        yield 'contains array missing value' => [ConditionOperator::Contains, 'red', ['q' => ['blue']], false];

        yield 'starts-with scalar match' => [ConditionOperator::StartsWith, 'hel', ['q' => 'hello'], true];
        yield 'starts-with scalar no match' => [ConditionOperator::StartsWith, 'ell', ['q' => 'hello'], false];
        yield 'starts-with empty expected is false' => [ConditionOperator::StartsWith, '', ['q' => 'hello'], false];
        yield 'starts-with null expected is false' => [ConditionOperator::StartsWith, null, ['q' => 'hello'], false];
        yield 'starts-with array has matching element' => [ConditionOperator::StartsWith, 'he', ['q' => ['blue', 'hello']], true];
        yield 'starts-with array no matching element' => [ConditionOperator::StartsWith, 'he', ['q' => ['blue']], false];

        yield 'ends-with scalar match' => [ConditionOperator::EndsWith, 'llo', ['q' => 'hello'], true];
        yield 'ends-with scalar no match' => [ConditionOperator::EndsWith, 'ell', ['q' => 'hello'], false];
        yield 'ends-with empty expected is false' => [ConditionOperator::EndsWith, '', ['q' => 'hello'], false];
        yield 'ends-with null expected is false' => [ConditionOperator::EndsWith, null, ['q' => 'hello'], false];
        yield 'ends-with array has matching element' => [ConditionOperator::EndsWith, 'llo', ['q' => ['blue', 'hello']], true];
        yield 'ends-with array no matching element' => [ConditionOperator::EndsWith, 'llo', ['q' => ['blue']], false];

        yield 'is-answered when answered' => [ConditionOperator::IsAnswered, null, ['q' => 'x'], true];
        yield 'is-answered when blank' => [ConditionOperator::IsAnswered, null, ['q' => ''], false];
        yield 'is-answered when missing' => [ConditionOperator::IsAnswered, null, [], false];
        yield 'is-answered when empty array' => [ConditionOperator::IsAnswered, null, ['q' => []], false];

        yield 'is-not-answered when blank' => [ConditionOperator::IsNotAnswered, null, ['q' => ''], true];
        yield 'is-not-answered when answered' => [ConditionOperator::IsNotAnswered, null, ['q' => 'x'], false];

        yield 'greater-than true' => [ConditionOperator::GreaterThan, '5', ['q' => '10'], true];
        yield 'greater-than false' => [ConditionOperator::GreaterThan, '5', ['q' => '3'], false];
        yield 'greater-than non-numeric answer' => [ConditionOperator::GreaterThan, '5', ['q' => 'abc'], false];
        yield 'greater-than non-numeric expected' => [ConditionOperator::GreaterThan, 'x', ['q' => '10'], false];

        yield 'less-than true' => [ConditionOperator::LessThan, '5', ['q' => '3'], true];
        yield 'less-than false' => [ConditionOperator::LessThan, '5', ['q' => '10'], false];
        yield 'less-than non-numeric answer' => [ConditionOperator::LessThan, '5', ['q' => 'abc'], false];
    }

    /**
     * @param  array<string, mixed>  $answers
     */
    #[Test]
    #[TestDox('evaluates each condition operator')]
    #[DataProvider('operatorCases')]
    public function evaluates_each_operator(ConditionOperator $op, ?string $value, array $answers, bool $expected): void
    {
        $node = $this->question([$this->group(BooleanOperator::And, [$this->condition($op, $value)])]);

        $this->assertSame($expected, $this->evaluator()->isVisible($node, $answers));
    }

    #[Test]
    #[TestDox('a hidden question is never visible, whatever its rules say')]
    public function hidden_question_is_never_visible(): void
    {
        // A passing rule would otherwise show it.
        $node = $this->question([$this->group(BooleanOperator::And, [$this->condition(ConditionOperator::IsAnswered, null)])], hidden: true);

        $this->assertFalse($this->evaluator()->isVisible($node, ['q' => 'x']));
    }

    #[Test]
    #[TestDox('a subject condition reads its value from the reserved answers key instead of a question')]
    public function subject_condition_reads_from_reserved_key(): void
    {
        $node = $this->question([$this->group(BooleanOperator::And, [
            $this->subjectCondition(ConditionOperator::Equals, 'minor', ConditionSubject::GuestType),
        ])]);

        $this->assertTrue($this->evaluator()->isVisible($node, ['guest_type' => 'minor']));
        $this->assertFalse($this->evaluator()->isVisible($node, ['guest_type' => 'adult']));
        $this->assertFalse($this->evaluator()->isVisible($node, []));
    }

    #[Test]
    #[TestDox('a node with no rule groups is always visible')]
    public function node_without_rules_is_visible(): void
    {
        $this->assertTrue($this->evaluator()->isVisible($this->question(), []));
    }

    /**
     * @param  array<int, ConditionGroup>  $groups
     */
    private function section(array $groups = [], bool $enabled = true): Section
    {
        $node = new Section(['key' => 'step', 'enabled' => $enabled]);
        $node->setRelation('conditionGroups', new Collection($groups));

        return $node;
    }

    #[Test]
    #[TestDox('a disabled section is never visible, whatever its rules say')]
    public function disabled_section_is_never_visible(): void
    {
        // A passing rule would otherwise show it.
        $node = $this->section([$this->group(BooleanOperator::And, [$this->condition(ConditionOperator::IsAnswered, null)])], enabled: false);

        $this->assertFalse($this->evaluator()->isVisible($node, ['q' => 'x']));
    }

    #[Test]
    #[TestDox('an enabled section follows its rule; without one it always shows')]
    public function enabled_section_follows_its_rule(): void
    {
        $rule = fn (): array => [$this->group(BooleanOperator::And, [$this->condition(ConditionOperator::Equals, 'red')])];

        $this->assertTrue($this->evaluator()->isVisible($this->section($rule()), ['q' => 'red']));
        $this->assertFalse($this->evaluator()->isVisible($this->section($rule()), ['q' => 'blue']));
        $this->assertTrue($this->evaluator()->isVisible($this->section(), []));
    }

    #[Test]
    #[TestDox('an empty group imposes no constraint (visible)')]
    public function empty_group_is_visible(): void
    {
        $this->assertTrue($this->evaluator()->isVisible($this->question([$this->group(BooleanOperator::And)]), []));
    }

    #[Test]
    #[TestDox('an OR group needs only one passing member')]
    public function or_group_needs_one_true(): void
    {
        $pass = $this->condition(ConditionOperator::Equals, 'red');
        $fail = $this->condition(ConditionOperator::Equals, 'green');

        $orTrue = $this->question([$this->group(BooleanOperator::Or, [$fail, $pass])]);
        $orFalse = $this->question([$this->group(BooleanOperator::Or, [$fail, $fail])]);

        $this->assertTrue($this->evaluator()->isVisible($orTrue, ['q' => 'red']));
        $this->assertFalse($this->evaluator()->isVisible($orFalse, ['q' => 'red']));
    }

    #[Test]
    #[TestDox('an AND group requires every member to pass')]
    public function and_group_requires_all_true(): void
    {
        $pass = $this->condition(ConditionOperator::Equals, 'red');
        $fail = $this->condition(ConditionOperator::Equals, 'green');

        $node = $this->question([$this->group(BooleanOperator::And, [$pass, $fail])]);

        $this->assertFalse($this->evaluator()->isVisible($node, ['q' => 'red']));
    }

    #[Test]
    #[TestDox('nested child groups are evaluated recursively')]
    public function nested_child_groups_are_evaluated(): void
    {
        // Root (AND) has one passing condition and a child OR group; the child
        // decides the outcome.
        $child = $this->group(BooleanOperator::Or, [$this->condition(ConditionOperator::Equals, 'no')]);
        $root = $this->group(BooleanOperator::And, [$this->condition(ConditionOperator::IsAnswered, null)], [$child]);

        $this->assertFalse($this->evaluator()->isVisible($this->question([$root]), ['q' => 'yes']));
    }

    #[Test]
    #[TestDox('multiple root groups are combined with AND')]
    public function multiple_root_groups_are_anded(): void
    {
        $pass = $this->group(BooleanOperator::And, [$this->condition(ConditionOperator::Equals, 'red')]);
        $fail = $this->group(BooleanOperator::And, [$this->condition(ConditionOperator::Equals, 'red', 'other')]);

        $node = $this->question([$pass, $fail]);

        $this->assertFalse($this->evaluator()->isVisible($node, ['q' => 'red', 'other' => 'blue']));
    }

    #[Test]
    #[TestDox('passes with no root groups imposes nothing')]
    public function passes_with_no_root_groups_imposes_nothing(): void
    {
        // The bare-tree entry point used by report row/cell rules.
        $this->assertTrue($this->evaluator()->passes([], []));
    }

    #[Test]
    #[TestDox('passes combines multiple root groups with AND')]
    public function passes_combines_multiple_root_groups_with_and(): void
    {
        $pass = $this->group(BooleanOperator::And, [$this->condition(ConditionOperator::Equals, 'red')]);
        $fail = $this->group(BooleanOperator::And, [$this->condition(ConditionOperator::Equals, 'red', 'other')]);

        $this->assertTrue($this->evaluator()->passes([$pass], ['q' => 'red']));
        $this->assertFalse($this->evaluator()->passes([$pass, $fail], ['q' => 'red', 'other' => 'blue']));
    }

    /** An option offered only when controlling-question q equals $when (unconditional when null). */
    private function option(string $value, string $label, ?string $when = null): QuestionOption
    {
        $option = new QuestionOption(['value' => $value, 'label' => $label]);
        $option->setRelation('conditionGroups', new Collection($when === null ? [] : [
            $this->group(BooleanOperator::And, [$this->condition(ConditionOperator::Equals, $when)]),
        ]));

        return $option;
    }

    /**
     * A choice question offering a shared-value pair ("full" labeled for reds
     * vs blues), a conditional unique value ("extra", reds only) and an
     * unconditional value ("day").
     */
    private function choiceQuestion(): Question
    {
        $node = $this->question();
        $node->setRelation('options', new Collection([
            $this->option('full', 'Full (Red)', 'red'),
            $this->option('full', 'Full (Blue)', 'blue'),
            $this->option('extra', 'Extra', 'red'),
            $this->option('day', 'Day'),
        ]));

        return $node;
    }

    #[Test]
    #[TestDox('visibleOptions offers each option by its own rule')]
    public function visible_options_follow_each_options_rule(): void
    {
        $labels = fn (array $answers): array => $this->evaluator()
            ->visibleOptions($this->choiceQuestion(), $answers)->pluck('label')->all();

        $this->assertSame(['Full (Red)', 'Extra', 'Day'], $labels(['q' => 'red']));
        $this->assertSame(['Full (Blue)', 'Day'], $labels(['q' => 'blue']));
        // No controlling answer yet: only the unconditional option shows.
        $this->assertSame(['Day'], $labels([]));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, ?string}>
     */
    public static function optionForCases(): iterable
    {
        yield 'shared value resolves to the offered variant (red)' => [['q' => 'red'], 'full', 'Full (Red)'];
        yield 'shared value resolves to the offered variant (blue)' => [['q' => 'blue'], 'full', 'Full (Blue)'];
        yield 'no visible variant falls back to the first match' => [[], 'full', 'Full (Red)'];
        yield 'unknown value resolves to nothing' => [['q' => 'red'], 'week', null];
    }

    /**
     * @param  array<string, mixed>  $answers
     */
    #[Test]
    #[TestDox('optionFor resolves a submitted value to the offered variant')]
    #[DataProvider('optionForCases')]
    public function option_for_resolves_the_offered_variant(array $answers, string $value, ?string $label): void
    {
        $option = $this->evaluator()->optionFor($this->choiceQuestion(), $value, $answers);

        $this->assertSame($label, $option?->label);
    }
}
