<?php

namespace ConferenceTools\Registration\Enums;

/**
 * The kinds of configurable registration question the package can render and
 * store. Free-form types store their answer in registration_answers.value;
 * choice types (Select/Radio/Checkbox) draw their answers from a question's
 * registration_question_options and may carry a cost on each option (this is how
 * accommodation and additional products are modeled — a choice question whose
 * options have a cost).
 */
enum QuestionType: string
{
    case Text = 'text';
    case Textarea = 'textarea';
    case Email = 'email';
    case Tel = 'tel';
    case Url = 'url';
    case Number = 'number';
    case Date = 'date';
    case Select = 'select';
    case Radio = 'radio';
    case Checkbox = 'checkbox';
    case DiscountCode = 'discount_code';
    case YesNo = 'yes_no';

    /** Backing values, e.g. for validation rules or migration column definitions. */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** Choice types draw their answer(s) from the question's options. */
    public function usesOptions(): bool
    {
        return in_array($this, [self::Select, self::Radio, self::Checkbox, self::YesNo], true);
    }

    /** Types that may record more than one selected value for one registrant. */
    public function isMultiValue(): bool
    {
        return $this === self::Checkbox;
    }

    /**
     * Free-typed text entry, where an in-field placeholder hint makes sense.
     * Choice types and the native date picker have no free text to hint at.
     */
    public function usesPlaceholder(): bool
    {
        return ! $this->usesOptions() && $this !== self::Date;
    }

    /**
     * The attributes of a free-form type's <input>: the type itself plus the
     * inputmode/autocomplete hints that get mobile browsers to offer the
     * matching keyboard and autofill suggestions.
     *
     * @return array<string, string>
     */
    public function inputAttributes(): array
    {
        return match ($this) {
            self::Email => ['type' => 'email', 'inputmode' => 'email', 'autocomplete' => 'email'],
            self::Tel => ['type' => 'tel', 'inputmode' => 'tel', 'autocomplete' => 'tel'],
            self::Url => ['type' => 'url', 'inputmode' => 'url', 'autocomplete' => 'url'],
            self::Number => ['type' => 'number', 'inputmode' => 'numeric'],
            self::Date => ['type' => 'date'],
            default => ['type' => 'text'],
        };
    }

    /**
     * A discount-code field: rendered as a plain text input, validated against the
     * configured discount codes without revealing them, and applied as a price
     * adjustment rather than a stored profile value.
     */
    public function isDiscountCode(): bool
    {
        return $this === self::DiscountCode;
    }
}
