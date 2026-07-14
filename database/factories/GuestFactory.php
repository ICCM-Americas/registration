<?php

namespace ConferenceTools\Registration\Database\Factories;

use ConferenceTools\Registration\Enums\GuestType;
use ConferenceTools\Registration\Models\Guest;
use Illuminate\Database\Eloquent\Factories\Factory;

/** Model factory for Guest rows. */
class GuestFactory extends Factory
{
    protected $model = Guest::class;

    public function definition(): array
    {
        return [
            'user_id' => $this->faker->unique()->numberBetween(1, 100000),
            'type' => GuestType::Adult,
            'position' => 0,
        ];
    }

    public function minor(): static
    {
        return $this->state(['type' => GuestType::Minor]);
    }
}
