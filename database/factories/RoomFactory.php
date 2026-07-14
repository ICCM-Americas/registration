<?php

namespace ConferenceTools\Registration\Database\Factories;

use ConferenceTools\Registration\Enums\RoomDesignation;
use ConferenceTools\Registration\Models\Room;
use Illuminate\Database\Eloquent\Factories\Factory;

/** Model factory for Room rows. */
class RoomFactory extends Factory
{
    protected $model = Room::class;

    public function definition(): array
    {
        return [
            'wing' => 'A',
            'floor' => '1',
            'name' => (string) $this->faker->unique()->numberBetween(100, 999),
            'designation' => RoomDesignation::Men,
            'capacity' => 2,
        ];
    }
}
