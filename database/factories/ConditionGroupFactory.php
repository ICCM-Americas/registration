<?php

namespace ConferenceTools\Registration\Database\Factories;

use ConferenceTools\Registration\Enums\BooleanOperator;
use ConferenceTools\Registration\Models\ConditionGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/** Model factory for ConditionGroup rows. */
class ConditionGroupFactory extends Factory
{
    protected $model = ConditionGroup::class;

    public function definition(): array
    {
        return [
            'operator' => BooleanOperator::And,
            'position' => 0,
        ];
    }
}
