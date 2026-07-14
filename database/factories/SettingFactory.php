<?php

namespace ConferenceTools\Registration\Database\Factories;

use ConferenceTools\Registration\Models\Setting;
use Illuminate\Database\Eloquent\Factories\Factory;

/** Model factory for Setting rows. */
class SettingFactory extends Factory
{
    protected $model = Setting::class;

    public function definition(): array
    {
        return [
            'key' => $this->faker->unique()->word(),
            'value' => $this->faker->sentence(3),
        ];
    }
}
