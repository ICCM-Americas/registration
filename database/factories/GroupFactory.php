<?php

namespace ConferenceTools\Registration\Database\Factories;

use ConferenceTools\Registration\Models\Group;
use Illuminate\Database\Eloquent\Factories\Factory;

/** Model factory for Group rows. */
class GroupFactory extends Factory
{
    protected $model = Group::class;

    public function definition(): array
    {
        // The group owns only its name and booking state; its registration
        // details live in the EAV answer store.
        return [
            'name' => $this->faker->company(),
            'checked_out' => false,
        ];
    }
}
