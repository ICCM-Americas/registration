<?php

namespace ConferenceTools\Registration\Database\Factories;

use ConferenceTools\Registration\Models\DiscountCode;
use Illuminate\Database\Eloquent\Factories\Factory;

/** Model factory for DiscountCode rows. */
class DiscountCodeFactory extends Factory
{
    protected $model = DiscountCode::class;

    public function definition(): array
    {
        return [
            'code' => strtoupper($this->faker->unique()->bothify('??##??')),
            'formula' => '-'.$this->faker->numberBetween(1, 50),
            'description' => $this->faker->sentence(),
            'enabled' => true,
        ];
    }

    /** Mark this code as disabled (it will not validate or apply). */
    public function disabled(): static
    {
        return $this->state(fn () => ['enabled' => false]);
    }
}
