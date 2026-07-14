<?php

namespace ConferenceTools\Registration\Database\Factories;

use ConferenceTools\Registration\Models\ClosedMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/** Model factory for ClosedMessage rows. */
class ClosedMessageFactory extends Factory
{
    protected $model = ClosedMessage::class;

    public function definition(): array
    {
        return [
            'key' => $this->faker->unique()->randomElement(ClosedMessage::KEYS),
            'body' => $this->faker->paragraph(),
        ];
    }
}
