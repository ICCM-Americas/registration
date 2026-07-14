<?php

namespace ConferenceTools\Registration\Database\Factories;

use ConferenceTools\Registration\Models\Currency;
use Illuminate\Database\Eloquent\Factories\Factory;

/** Model factory for Currency rows. */
class CurrencyFactory extends Factory
{
    protected $model = Currency::class;

    public function definition(): array
    {
        return [
            'code' => $this->faker->unique()->currencyCode(),
            'name' => $this->faker->word(),
            'symbol' => $this->faker->randomElement(['$', '€', '£', 'KSh']),
            'rate' => $this->faker->randomFloat(8, 0.5, 150),
            'def' => false,
            'enabled' => true,
        ];
    }

    /** Mark this currency as not allowed (hidden from checkout). */
    public function disabled(): static
    {
        return $this->state(fn () => ['enabled' => false]);
    }

    /** Mark this currency as the default (base) currency. */
    public function default(): static
    {
        return $this->state(fn () => ['def' => true, 'rate' => 1]);
    }
}
