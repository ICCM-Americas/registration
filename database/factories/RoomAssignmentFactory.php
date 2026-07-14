<?php

namespace ConferenceTools\Registration\Database\Factories;

use ConferenceTools\Registration\Models\Guest;
use ConferenceTools\Registration\Models\Room;
use ConferenceTools\Registration\Models\RoomAssignment;
use Illuminate\Database\Eloquent\Factories\Factory;

/** Model factory for RoomAssignment rows. */
class RoomAssignmentFactory extends Factory
{
    protected $model = RoomAssignment::class;

    public function definition(): array
    {
        return [
            'room_id' => Room::factory(),
            // No host-user factory exists in the package; tests pass a real id.
            'assignable_type' => config('registration.user_model'),
            'assignable_id' => $this->faker->unique()->numberBetween(1, 100000),
        ];
    }

    /** A non-attending Guest occupies the room instead of a registrant. */
    public function forGuest(): static
    {
        return $this->state(fn () => [
            'assignable_type' => Guest::class,
        ]);
    }
}
