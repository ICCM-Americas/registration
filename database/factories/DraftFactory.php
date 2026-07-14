<?php

namespace ConferenceTools\Registration\Database\Factories;

use ConferenceTools\Registration\Models\Draft;
use Illuminate\Database\Eloquent\Factories\Factory;

/** Model factory for Draft rows. */
class DraftFactory extends Factory
{
    protected $model = Draft::class;

    public function definition(): array
    {
        return [
            'user_id' => $this->faker->unique()->numberBetween(1, 100000),
            'current_question_id' => null,
            'answers' => [],
        ];
    }
}
