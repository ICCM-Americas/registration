<?php

namespace ConferenceTools\Registration\Database\Factories;

use ConferenceTools\Registration\Enums\QuestionType;
use ConferenceTools\Registration\Models\Question;
use ConferenceTools\Registration\Models\Section;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** Model factory for Question rows. */
class QuestionFactory extends Factory
{
    protected $model = Question::class;

    public function definition(): array
    {
        $label = $this->faker->unique()->words(3, true);

        return [
            'section_id' => Section::factory(),
            'key' => Str::slug($label).'-'.$this->faker->unique()->numberBetween(1, 100000),
            'type' => QuestionType::Text,
            'label' => Str::ucfirst($label),
            'help_text' => $this->faker->optional()->sentence(),
            'placeholder' => null,
            'translate_value' => false,
            'required' => false,
            'position' => 0,
            'config' => null,
            'enabled' => true,
        ];
    }

    public function ofType(QuestionType $type): static
    {
        return $this->state(['type' => $type]);
    }

    public function required(): static
    {
        return $this->state(['required' => true]);
    }

    public function translateValue(): static
    {
        return $this->state(['translate_value' => true]);
    }
}
