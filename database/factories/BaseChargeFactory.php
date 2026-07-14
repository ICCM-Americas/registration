<?php

namespace ConferenceTools\Registration\Database\Factories;

use ConferenceTools\Registration\Models\BaseCharge;
use Illuminate\Database\Eloquent\Factories\Factory;

/** Model factory for BaseCharge rows. */
class BaseChargeFactory extends Factory
{
    protected $model = BaseCharge::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->words(2, true),
            'amount' => $this->faker->randomFloat(2, 1, 500),
            'enabled' => true,
            'order' => $this->faker->unique()->numberBetween(1, 1000),
        ];
    }

    /** Mark this base charge as disabled (excluded from the total). */
    public function disabled(): static
    {
        return $this->state(fn () => ['enabled' => false]);
    }
}
