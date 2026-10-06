<?php

namespace ConferenceTools\Registration\Services;

use ConferenceTools\Registration\Enums\BooleanOperator;
use ConferenceTools\Registration\Enums\ConditionOperator;
use ConferenceTools\Registration\Enums\QuestionScope;
use ConferenceTools\Registration\Models\Condition;
use ConferenceTools\Registration\Models\ConditionGroup;
use ConferenceTools\Registration\Models\Question;
use ConferenceTools\Registration\Models\QuestionOption;
use ConferenceTools\Registration\Models\Section;
use Illuminate\Support\Collection;

/**
 * Decides whether a question, section or single question option is visible
 * given the answers collected so far, by evaluating its visibility rule tree
 * (the nested AND/OR condition groups attached to it). A node with no rule is
 * always visible.
 *
 * Answers are passed as a map of question key => answer (a scalar, or an array
 * for multi-value questions), which is exactly the shape of the submitted form
 * input — so the same evaluator drives both server-side validation and rendering.
 * The bare tree evaluation ({@see passes()}) also drives the admin-defined
 * reports' row and cell rules, against committed answers.
 */
class VisibilityEvaluator
{
    /**
     * @param  array<string, mixed>  $answers  question key => answer value(s)
     */
    public function isVisible(Question|Section|QuestionOption $node, array $answers): bool
    {
        // A question or section can be marked never-visible ("always hidden")
        // in the builder; that overrides any rule tree.
        if (! $node instanceof QuestionOption && $node->isHidden()) {
            return false;
        }

        return $this->passes($node->conditionGroups, $answers);
    }

    /**
     * Whether every root group in a rule passes for the given answers — an
     * empty set imposes nothing. Multiple root groups are combined with AND
     * (each must pass), though one root group is the normal case. This is the
     * bare tree evaluation, for conditionables with no hidden state (e.g. an
     * admin-defined report's row and cell rules).
     *
     * @param  iterable<int, ConditionGroup>  $rootGroups
     * @param  array<string, mixed>  $answers  question key => answer value(s)
     */
    public function passes(iterable $rootGroups, array $answers): bool
    {
        return $this->rootsPass($rootGroups, $answers, null);
    }

    /**
     * {@see passes()} for one row of an Individual report: a condition on a
     * question of another scope than the row's is skipped, so a registrant
     * question decides only registrant rows and a guest question only guest
     * rows. A subgroup whose every condition was skipped drops out of its
     * parent, and a rule left with nothing that applies fails: only a
     * condition of the row's own scope can keep it. No rule at all passes.
     *
     * @param  iterable<int, ConditionGroup>  $rootGroups
     * @param  array<string, mixed>  $answers  question key => answer value(s)
     */
    public function passesForScope(iterable $rootGroups, array $answers, QuestionScope $rowScope): bool
    {
        return $this->rootsPass($rootGroups, $answers, $rowScope);
    }

    /**
     * The question's options that are currently offered: each option's own
     * visibility rule evaluated against the answers (rule-less options always
     * show).
     *
     * @param  array<string, mixed>  $answers
     * @return Collection<int, QuestionOption>
     */
    public function visibleOptions(Question $question, array $answers): Collection
    {
        return $question->options
            ->filter(fn (QuestionOption $option): bool => $this->isVisible($option, $answers))
            ->values();
    }

    /**
     * Resolve a submitted value to the option row the registrant was actually
     * offered. Several options may share a value (conditionally-offered
     * variants differing only in label), so the first *visible* match wins;
     * the fallback to the first match at all keeps a misconfigured rule from
     * losing the answer entirely.
     *
     * @param  array<string, mixed>  $answers
     */
    public function optionFor(Question $question, string $value, array $answers): ?QuestionOption
    {
        $matching = $question->options->where('value', $value);

        return $matching->first(fn (QuestionOption $option): bool => $this->isVisible($option, $answers))
            ?? $matching->first();
    }

    /** Whether every root group passes; with a row scope, a root with nothing applicable (null) fails. */
    private function rootsPass(iterable $rootGroups, array $answers, ?QuestionScope $rowScope): bool
    {
        foreach ($rootGroups as $group) {
            if ($this->evaluateGroup($group, $answers, $rowScope) !== true) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether a condition group passes: AND needs every child, OR needs any.
     * With a row scope, conditions on other scopes' questions are skipped,
     * and a group left with nothing applicable is null rather than passing.
     */
    private function evaluateGroup(ConditionGroup $group, array $answers, ?QuestionScope $rowScope): ?bool
    {
        $results = [];

        foreach ($group->conditions as $condition) {
            if ($rowScope === null || $this->appliesTo($condition, $rowScope)) {
                $results[] = $this->evaluateCondition($condition, $answers);
            }
        }

        foreach ($group->children as $child) {
            $result = $this->evaluateGroup($child, $answers, $rowScope);
            if ($result !== null) {
                $results[] = $result;
            }
        }

        // An empty group imposes no constraint.
        if ($results === []) {
            return $rowScope === null ? true : null;
        }

        return $group->operator === BooleanOperator::Or
            ? in_array(true, $results, true)
            : ! in_array(false, $results, true);
    }

    /** Whether a condition tests a question of the row's scope (a built-in subject always applies). */
    private function appliesTo(Condition $condition, QuestionScope $rowScope): bool
    {
        return $condition->subject !== null || $condition->question?->section?->scope === $rowScope;
    }

    /** Whether one leaf condition holds against the answers. */
    private function evaluateCondition(Condition $condition, array $answers): bool
    {
        // A subject condition (e.g. a guest's own type) reads the same
        // answers map by its reserved key instead of a question's key —
        // {@see ConditionSubject}.
        $key = $condition->subject?->value ?? $condition->question?->key;
        $answer = $key !== null ? ($answers[$key] ?? null) : null;
        $expected = $condition->value;

        return match ($condition->operator) {
            ConditionOperator::Equals => $this->equals($answer, $expected),
            ConditionOperator::NotEquals => ! $this->equals($answer, $expected),
            ConditionOperator::In => $this->in($answer, $expected),
            ConditionOperator::NotIn => ! $this->in($answer, $expected),
            ConditionOperator::Contains => $this->contains($answer, $expected),
            ConditionOperator::StartsWith => $this->startsWith($answer, $expected),
            ConditionOperator::EndsWith => $this->endsWith($answer, $expected),
            ConditionOperator::IsAnswered => ! $this->isBlank($answer),
            ConditionOperator::IsNotAnswered => $this->isBlank($answer),
            ConditionOperator::GreaterThan => is_numeric($answer) && is_numeric($expected) && $answer > $expected,
            ConditionOperator::LessThan => is_numeric($answer) && is_numeric($expected) && $answer < $expected,
        };
    }

    /** Whether the answer equals the expected value (multi-value: any element). */
    private function equals(mixed $answer, ?string $expected): bool
    {
        if (is_array($answer)) {
            return in_array((string) $expected, array_map('strval', $answer), true);
        }

        return (string) $answer === (string) $expected;
    }

    /** Whether the answer falls within the comma-separated value list. */
    private function in(mixed $answer, ?string $expected): bool
    {
        $allowed = array_map('trim', explode(',', (string) $expected));

        if (is_array($answer)) {
            return array_intersect(array_map('strval', $answer), $allowed) !== [];
        }

        return in_array((string) $answer, $allowed, true);
    }

    /** Whether the multi-value answer includes the value. */
    private function contains(mixed $answer, ?string $expected): bool
    {
        if (is_array($answer)) {
            return in_array((string) $expected, array_map('strval', $answer), true);
        }

        return $expected !== null && $expected !== '' && str_contains((string) $answer, (string) $expected);
    }

    /** Whether the answer starts with the expected value (multi-value: any element). */
    private function startsWith(mixed $answer, ?string $expected): bool
    {
        if (is_array($answer)) {
            foreach ($answer as $value) {
                if (str_starts_with((string) $value, (string) $expected)) {
                    return true;
                }
            }

            return false;
        }

        return $expected !== null && $expected !== '' && str_starts_with((string) $answer, (string) $expected);
    }

    /** Whether the answer ends with the expected value (multi-value: any element). */
    private function endsWith(mixed $answer, ?string $expected): bool
    {
        if (is_array($answer)) {
            foreach ($answer as $value) {
                if (str_ends_with((string) $value, (string) $expected)) {
                    return true;
                }
            }

            return false;
        }

        return $expected !== null && $expected !== '' && str_ends_with((string) $answer, (string) $expected);
    }

    /** Whether the answer counts as unanswered. */
    private function isBlank(mixed $answer): bool
    {
        if (is_array($answer)) {
            return $answer === [];
        }

        return $answer === null || trim($answer) === '';
    }
}
