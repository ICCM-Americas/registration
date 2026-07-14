<?php

namespace ConferenceTools\Registration\Database\Factories;

use ConferenceTools\Registration\Enums\Gender;
use ConferenceTools\Registration\Models\PrayerPalsGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/** Model factory for PrayerPalsGroup rows. */
class PrayerPalsGroupFactory extends Factory
{
    protected $model = PrayerPalsGroup::class;

    public function definition(): array
    {
        return [
            'sex' => Gender::Male,
            'position' => 1,
        ];
    }
}
