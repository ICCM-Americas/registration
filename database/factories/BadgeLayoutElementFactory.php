<?php

namespace ConferenceTools\Registration\Database\Factories;

use ConferenceTools\Registration\Enums\BadgeElementType;
use ConferenceTools\Registration\Enums\BadgeSheetSize;
use ConferenceTools\Registration\Models\BadgeLayoutElement;
use Illuminate\Database\Eloquent\Factories\Factory;

/** Model factory for BadgeLayoutElement rows. */
class BadgeLayoutElementFactory extends Factory
{
    protected $model = BadgeLayoutElement::class;

    public function definition(): array
    {
        return [
            'sheet_size' => BadgeSheetSize::Avery5392,
            'type' => BadgeElementType::StaticText,
            'x_pct' => 10,
            'y_pct' => 10,
            'width_pct' => 50,
            'font_size_pt' => 12,
            'bold' => false,
            'italic' => false,
            'align' => 'center',
            'text' => $this->faker->words(3, true),
        ];
    }

    public function ofType(BadgeElementType $type): static
    {
        return $this->state(['type' => $type, 'font_size_pt' => $type->defaultFontSizePt()]);
    }

    public function forSize(BadgeSheetSize $size): static
    {
        return $this->state(['sheet_size' => $size]);
    }

    public function image(string $mime = 'image/png'): static
    {
        return $this->state([
            'type' => BadgeElementType::StaticImage,
            'font_size_pt' => null,
            'text' => null,
            'image_data' => base64_encode('fake-image-bytes'),
            'image_mime' => $mime,
        ]);
    }
}
