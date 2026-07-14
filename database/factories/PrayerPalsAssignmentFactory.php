<?php

namespace ConferenceTools\Registration\Database\Factories;

use ConferenceTools\Registration\Models\Guest;
use ConferenceTools\Registration\Models\PrayerPalsAssignment;
use ConferenceTools\Registration\Models\PrayerPalsGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/** Model factory for PrayerPalsAssignment rows. */
class PrayerPalsAssignmentFactory extends Factory
{
    protected $model = PrayerPalsAssignment::class;

    public function definition(): array
    {
        return [
            'prayer_pals_group_id' => PrayerPalsGroup::factory(),
            // No host-user factory exists in the package; tests pass a real id.
            'assignable_type' => config('registration.user_model'),
            'assignable_id' => $this->faker->unique()->numberBetween(1, 100000),
        ];
    }

    /** A non-attending Guest is placed in the group instead of a registrant. */
    public function forGuest(): static
    {
        return $this->state(fn () => [
            'assignable_type' => Guest::class,
        ]);
    }
}
