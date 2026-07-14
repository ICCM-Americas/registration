<?php

namespace ConferenceTools\Registration\Database\Factories;

use ConferenceTools\Registration\Models\Variable;
use Illuminate\Database\Eloquent\Factories\Factory;

/** Model factory for Variable rows. */
class VariableFactory extends Factory
{
    protected $model = Variable::class;

    public function definition(): array
    {
        return [
            'name' => str_replace('-', '_', $this->faker->unique()->slug(2)),
            'value' => $this->faker->words(3, true),
        ];
    }
}
