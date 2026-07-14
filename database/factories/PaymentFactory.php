<?php

namespace ConferenceTools\Registration\Database\Factories;

use ConferenceTools\Registration\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;

/** Model factory for Payment rows. */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    /**
     * Construct via forceFill, not the constructor's fillable-gated fill():
     * is_paid/amount are deliberately excluded from $fillable (they must
     * never be settable from raw request input), but factory-authored test
     * data is trusted and needs to set them directly.
     */
    public function newModel(array $attributes = []): Payment
    {
        return (new Payment)->forceFill($attributes);
    }

    public function definition(): array
    {
        return [
            'user_id' => $this->faker->unique()->numberBetween(1, 100000),
            'is_paid' => false,
            'amount' => null,
            'notes' => null,
        ];
    }

    public function paid(): static
    {
        return $this->state(['is_paid' => true]);
    }
}
