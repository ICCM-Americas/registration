<?php

namespace ConferenceTools\Registration\Database\Factories;

use ConferenceTools\Registration\Models\ConferenceArchive;
use Illuminate\Database\Eloquent\Factories\Factory;

/** Model factory for ConferenceArchive rows. */
class ConferenceArchiveFactory extends Factory
{
    protected $model = ConferenceArchive::class;

    public function definition(): array
    {
        return [
            'conference_name' => $this->faker->words(2, true),
            'conference_year' => (string) $this->faker->numberBetween(2000, 2100),
            'archived_at' => now(),
            'data' => [],
        ];
    }
}
