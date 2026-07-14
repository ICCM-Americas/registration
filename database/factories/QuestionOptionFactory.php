<?php

namespace ConferenceTools\Registration\Database\Factories;

use ConferenceTools\Registration\Enums\PerDiemScope;
use ConferenceTools\Registration\Models\Question;
use ConferenceTools\Registration\Models\QuestionOption;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** Model factory for QuestionOption rows. */
class QuestionOptionFactory extends Factory
{
    protected $model = QuestionOption::class;

    public function definition(): array
    {
        $label = $this->faker->unique()->words(2, true);

        return [
            'question_id' => Question::factory(),
            'value' => Str::slug($label),
            'label' => Str::title($label),
            'cost' => null,
            'per_diem_days' => 0,
            'per_diem_scope' => PerDiemScope::Attendee,
            'position' => 0,
            'is_default' => false,
        ];
    }

    /** An option that contributes to the checkout total. */
    public function priced(?float $cost = null): static
    {
        return $this->state(['cost' => $cost ?? $this->faker->randomFloat(2, 0, 500)]);
    }

    /** An option that adds per-diem days (optionally covering an accompanying guest). */
    public function perDiem(int $days = 1, PerDiemScope $scope = PerDiemScope::Attendee): static
    {
        return $this->state(['per_diem_days' => $days, 'per_diem_scope' => $scope]);
    }
}
