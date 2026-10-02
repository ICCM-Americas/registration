<?php

namespace ConferenceTools\Registration\Support\Search;

use ConferenceTools\Registration\Enums\ConditionOperator;
use ConferenceTools\Registration\Models\Condition;
use ConferenceTools\Registration\Models\ConditionGroup;

/** Reads a visibility rule tree as plain sentences, one per condition, for the admin search. */
class RuleDescriber
{
    /**
     * Every condition in the group's subtree, depth-first, as a readable line.
     *
     * @return list<string>
     */
    public function lines(ConditionGroup $group): array
    {
        $lines = $group->conditions->map(fn (Condition $condition): string => $this->describe($condition))->all();

        foreach ($group->children as $child) {
            array_push($lines, ...$this->lines($child));
        }

        return $lines;
    }

    /** One condition as "Label (key) operator value". */
    public function describe(Condition $condition): string
    {
        $subject = $condition->question
            ? $condition->question->label.' ('.$condition->question->key.')'
            : (string) $condition->subject?->label();
        $operator = __('registration::admin.search_operator_'.$condition->operator->value);

        if (! $condition->operator->needsValue()) {
            return $subject.' '.$operator;
        }

        return $subject.' '.$operator.' '.$this->value($condition);
    }

    /** The condition's value, a list operator's comma-separated values spaced for reading. */
    private function value(Condition $condition): string
    {
        if (! in_array($condition->operator, [ConditionOperator::In, ConditionOperator::NotIn], true)) {
            return (string) $condition->value;
        }

        return implode(', ', array_map('trim', explode(',', (string) $condition->value)));
    }
}
