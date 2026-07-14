<?php

namespace ConferenceTools\Registration\Database\Factories;

use ConferenceTools\Registration\Models\Translation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** Model factory for Translation rows. */
class TranslationFactory extends Factory
{
    protected $model = Translation::class;

    public function definition(): array
    {
        return [
            'field' => 'label',
            'locale' => 'fr',
            'value' => $this->faker->words(3, true),
        ];
    }
}
