<?php

namespace ConferenceTools\Registration\Database\Factories;

use ConferenceTools\Registration\Models\Report;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** Model factory for Report rows. */
class ReportFactory extends Factory
{
    protected $model = Report::class;

    public function definition(): array
    {
        return [
            'name' => Str::ucfirst($this->faker->unique()->words(2, true)),
            'description' => $this->faker->optional()->sentence(),
            'include_adult_guests' => false,
            'include_minor_guests' => false,
            'position' => 0,
        ];
    }

    public function withGuests(): static
    {
        return $this->state(['include_adult_guests' => true, 'include_minor_guests' => true]);
    }
}
