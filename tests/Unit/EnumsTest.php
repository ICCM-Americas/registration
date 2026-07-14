<?php

namespace ConferenceTools\Registration\Tests\Unit;

use ConferenceTools\Registration\Enums\Gender;
use ConferenceTools\Registration\Enums\GuestType;
use ConferenceTools\Registration\Enums\QuestionScope;
use ConferenceTools\Registration\Enums\QuestionType;
use ConferenceTools\Registration\Enums\ReportColumnDisplay;
use ConferenceTools\Registration\Enums\ReportField;
use ConferenceTools\Registration\Enums\RoomDesignation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** Unit tests for Enums. */
#[TestDox('Enums')]
class EnumsTest extends TestCase
{
    #[TestDox('gender values and labels')]
    public function test_gender_values_and_labels(): void
    {
        $this->assertSame(['m', 'f'], Gender::values());
        $this->assertSame('Male', Gender::Male->label());
        $this->assertSame('Female', Gender::Female->label());
    }

    #[TestDox('guest type values')]
    public function test_guest_type_values(): void
    {
        $this->assertSame(['adult', 'minor'], GuestType::values());
        $this->assertSame(GuestType::Adult, GuestType::from('adult'));
        $this->assertSame(GuestType::Minor, GuestType::from('minor'));
    }

    #[TestDox('question scope values include guest and group member')]
    public function test_question_scope_values_include_guest(): void
    {
        $this->assertSame(['participant', 'group', 'guest', 'group_member'], QuestionScope::values());
    }

    #[TestDox('report field and column display values')]
    public function test_report_field_and_column_display_values(): void
    {
        $this->assertSame(['email', 'entry_type', 'badge_name', 'organization'], ReportField::values());
        $this->assertSame(['value', 'label', 'mapped'], ReportColumnDisplay::values());
    }

    #[TestDox('room designation values and gender zones')]
    public function test_room_designation_values_and_gender_zones(): void
    {
        $this->assertSame(['men', 'women', 'couples'], RoomDesignation::values());
        $this->assertSame(RoomDesignation::Men, RoomDesignation::forGender(Gender::Male));
        $this->assertSame(RoomDesignation::Women, RoomDesignation::forGender(Gender::Female));
    }

    /**
     * @return iterable<string, array{QuestionType, bool}>
     */
    public static function discountCodeTypes(): iterable
    {
        yield 'discount code' => [QuestionType::DiscountCode, true];
        yield 'plain text' => [QuestionType::Text, false];
        yield 'radio' => [QuestionType::Radio, false];
    }

    #[DataProvider('discountCodeTypes')]
    #[TestDox('is discount code')]
    public function test_is_discount_code(QuestionType $type, bool $expected): void
    {
        $this->assertSame($expected, $type->isDiscountCode());
    }

    /**
     * @return iterable<string, array{QuestionType, bool}>
     */
    public static function placeholderTypes(): iterable
    {
        yield 'text' => [QuestionType::Text, true];
        yield 'textarea' => [QuestionType::Textarea, true];
        yield 'email' => [QuestionType::Email, true];
        yield 'tel' => [QuestionType::Tel, true];
        yield 'url' => [QuestionType::Url, true];
        yield 'number' => [QuestionType::Number, true];
        yield 'discount code' => [QuestionType::DiscountCode, true];
        yield 'date' => [QuestionType::Date, false];
        yield 'select' => [QuestionType::Select, false];
        yield 'radio' => [QuestionType::Radio, false];
        yield 'checkbox' => [QuestionType::Checkbox, false];
    }

    #[DataProvider('placeholderTypes')]
    #[TestDox('uses placeholder')]
    public function test_uses_placeholder(QuestionType $type, bool $expected): void
    {
        $this->assertSame($expected, $type->usesPlaceholder());
    }

    /**
     * @return iterable<string, array{QuestionType, array<string, string>}>
     */
    public static function inputAttributeCases(): iterable
    {
        yield 'email' => [QuestionType::Email, ['type' => 'email', 'inputmode' => 'email', 'autocomplete' => 'email']];
        yield 'tel' => [QuestionType::Tel, ['type' => 'tel', 'inputmode' => 'tel', 'autocomplete' => 'tel']];
        yield 'url' => [QuestionType::Url, ['type' => 'url', 'inputmode' => 'url', 'autocomplete' => 'url']];
        yield 'number' => [QuestionType::Number, ['type' => 'number', 'inputmode' => 'numeric']];
        yield 'date' => [QuestionType::Date, ['type' => 'date']];
        yield 'plain text' => [QuestionType::Text, ['type' => 'text']];
    }

    /**
     * @param  array<string, string>  $expected
     */
    #[DataProvider('inputAttributeCases')]
    #[TestDox('input attributes')]
    public function test_input_attributes(QuestionType $type, array $expected): void
    {
        $this->assertSame($expected, $type->inputAttributes());
    }
}
