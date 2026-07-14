<?php

namespace ConferenceTools\Registration\Database\Factories;

use ConferenceTools\Registration\Models\InfoStep;
use Illuminate\Database\Eloquent\Factories\Factory;

/** Model factory for InfoStep rows. */
class InfoStepFactory extends Factory
{
    protected $model = InfoStep::class;

    public function definition(): array
    {
        return [
            'heading' => $this->faker->sentence(3),
            'body' => $this->faker->sentence(10),
            'position' => $this->faker->unique()->numberBetween(1, 1000),
            'enabled' => true,
        ];
    }

    /** Mark this step as hidden from the landing page. */
    public function disabled(): static
    {
        return $this->state(fn () => ['enabled' => false]);
    }
}
