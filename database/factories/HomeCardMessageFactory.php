<?php

namespace ConferenceTools\Registration\Database\Factories;

use ConferenceTools\Registration\Models\HomeCardMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/** Model factory for HomeCardMessage rows. */
class HomeCardMessageFactory extends Factory
{
    protected $model = HomeCardMessage::class;

    public function definition(): array
    {
        return [
            'key' => $this->faker->unique()->randomElement(HomeCardMessage::KEYS),
            'body' => $this->faker->paragraph(),
        ];
    }
}
