<?php

namespace ConferenceTools\Registration\Database\Factories;

use ConferenceTools\Registration\Enums\QuestionScope;
use ConferenceTools\Registration\Models\Section;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** Model factory for Section rows. */
class SectionFactory extends Factory
{
    protected $model = Section::class;

    public function definition(): array
    {
        $title = $this->faker->unique()->words(2, true);

        return [
            'scope' => QuestionScope::Participant,
            'key' => Str::slug($title).'-'.$this->faker->unique()->numberBetween(1, 100000),
            'title' => Str::title($title),
            'description' => $this->faker->optional()->sentence(),
            'position' => 0,
            'enabled' => true,
        ];
    }

    public function group(): static
    {
        return $this->state(['scope' => QuestionScope::Group]);
    }
}
