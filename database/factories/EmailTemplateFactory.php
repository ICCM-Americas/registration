<?php

namespace ConferenceTools\Registration\Database\Factories;

use ConferenceTools\Registration\Models\EmailTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/** Model factory for EmailTemplate rows. */
class EmailTemplateFactory extends Factory
{
    protected $model = EmailTemplate::class;

    public function definition(): array
    {
        return [
            'key' => $this->faker->unique()->randomElement(EmailTemplate::KEYS),
            'subject' => $this->faker->sentence(4),
            'body' => $this->faker->paragraph(),
        ];
    }
}
