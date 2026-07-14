<?php

namespace ConferenceTools\Registration\Tests\Feature;

use ConferenceTools\Registration\Enums\QuestionScope;
use ConferenceTools\Registration\Enums\QuestionType;
use ConferenceTools\Registration\Models\BaseCharge;
use ConferenceTools\Registration\Models\DiscountCode;
use ConferenceTools\Registration\Models\Group;
use ConferenceTools\Registration\Models\Question;
use ConferenceTools\Registration\Models\Section;
use ConferenceTools\Registration\Tests\Concerns\BuildsRegistrationData;
use ConferenceTools\Registration\Tests\Fixtures\User;
use ConferenceTools\Registration\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * The price a registrant pays is: enabled base charges + chosen priced options,
 * with any entered discount code applied to that total (never below zero).
 */
#[TestDox('Pricing Calculation')]
class PricingCalculationTest extends TestCase
{
    use BuildsRegistrationData, RefreshDatabase;

    private const OPTION_COST = 20.0;

    #[TestDox('enabled base charges are added per registrant')]
    public function test_enabled_base_charges_are_added_per_registrant(): void
    {
        $this->participantQuestions();
        BaseCharge::factory()->create(['amount' => 100]);
        BaseCharge::factory()->disabled()->create(['amount' => 999]); // excluded

        $user = $this->registrant(['fee' => 'yes']);

        // 100 base + 20 option = 120 (the disabled 999 charge is ignored).
        $this->assertSame(120.0, $user->cost());
    }

    #[TestDox('base charge lines list only enabled charges, in configured order')]
    public function test_base_charge_lines_list_only_enabled_charges_in_configured_order(): void
    {
        BaseCharge::factory()->create(['name' => 'Late Fee', 'amount' => 25, 'order' => 1]);
        BaseCharge::factory()->create(['name' => 'Registration Fee', 'amount' => 100, 'order' => 0]);
        BaseCharge::factory()->disabled()->create(['name' => 'Retired Fee', 'amount' => 999]);

        $this->assertSame(
            [['label' => 'Registration Fee', 'amount' => 100.0], ['label' => 'Late Fee', 'amount' => 25.0]],
            BaseCharge::lines(),
        );
    }

    #[TestDox('no base charges or codes leaves cost unchanged')]
    public function test_no_base_charges_or_codes_leaves_cost_unchanged(): void
    {
        $this->participantQuestions();

        $user = $this->registrant(['fee' => 'yes']);

        $this->assertSame(self::OPTION_COST, $user->cost());
    }

    #[DataProvider('formulaExpectations')]
    #[TestDox('discount formula applies to the full subtotal')]
    public function test_discount_formula_applies_to_the_full_subtotal(string $formula, float $expected): void
    {
        $this->participantQuestions();
        BaseCharge::factory()->create(['amount' => 100]); // subtotal = 100 + 20 = 120
        DiscountCode::factory()->create(['code' => 'K7QND2', 'formula' => $formula]);

        $user = $this->registrant(['fee' => 'yes', 'discount' => 'K7QND2']);

        $this->assertSame($expected, $user->cost());
    }

    /** The formula expectations for the data provider. */
    public static function formulaExpectations(): array
    {
        // subtotal is 120 in every case.
        return [
            'add' => ['+30', 150.0],
            'subtract' => ['-50', 70.0],
            'fixed' => ['=0', 0.0],
            'multiply' => ['*0.5', 60.0],
            'percent' => ['90%', 108.0],
            'subtract below zero clamps' => ['-500', 0.0],
            'multiply by zero' => ['*0', 0.0],
        ];
    }

    #[TestDox('unknown or blank code does not change the price')]
    public function test_unknown_or_blank_code_does_not_change_the_price(): void
    {
        $this->participantQuestions();
        DiscountCode::factory()->create(['code' => 'K7QND2', 'formula' => '-50']);

        $unknown = $this->registrant(['fee' => 'yes', 'discount' => 'NOPE99']);
        $this->assertSame(self::OPTION_COST, $unknown->cost());

        $blank = $this->registrant(['fee' => 'yes', 'discount' => '']);
        $this->assertSame(self::OPTION_COST, $blank->cost());
    }

    #[TestDox('disabled code does not apply')]
    public function test_disabled_code_does_not_apply(): void
    {
        $this->participantQuestions();
        DiscountCode::factory()->disabled()->create(['code' => 'K7QND2', 'formula' => '-50']);

        $user = $this->registrant(['fee' => 'yes', 'discount' => 'K7QND2']);

        $this->assertSame(self::OPTION_COST, $user->cost());
    }

    #[TestDox('code matches case insensitively ignoring whitespace')]
    public function test_code_matches_case_insensitively_ignoring_whitespace(): void
    {
        $this->participantQuestions();
        DiscountCode::factory()->create(['code' => 'K7QND2', 'formula' => '-5']);

        $user = $this->registrant(['fee' => 'yes', 'discount' => '  k7qnd2 ']);

        $this->assertSame(15.0, $user->cost()); // 20 - 5
    }

    #[TestDox('group scope code adjusts the group total')]
    public function test_group_scope_code_adjusts_the_group_total(): void
    {
        $this->participantQuestions();
        // A discount-code question on the GROUP scope.
        $groupSection = Section::create([
            'scope' => QuestionScope::Group->value, 'key' => 'billing', 'title' => 'Billing', 'position' => 0, 'enabled' => true,
        ]);
        $this->question($groupSection, 'groupdiscount', QuestionType::DiscountCode);
        DiscountCode::factory()->create(['code' => 'B3LRZ8', 'formula' => '-10']);

        $group = Group::factory()->create();
        $this->makeUser(['group_id' => $group->id]);
        $this->makeUser(['group_id' => $group->id]);
        $group = $group->fresh();
        foreach ($group->users as $u) {
            $this->storeAnswers($u, QuestionScope::Participant, ['fee' => 'yes']);
        }
        $this->storeAnswers($group, QuestionScope::Group, ['groupdiscount' => 'B3LRZ8']);

        // Two registrants × 20 = 40, group code -10 → 30.
        $this->assertSame(30.0, $group->fresh()->cost());
    }

    // -- Setup helpers -------------------------------------------------------

    /** A participant section with a priced option and a discount-code question. */
    private function participantQuestions(): void
    {
        $this->defaultCurrency();

        $section = Section::create([
            'scope' => QuestionScope::Participant->value, 'key' => 'extras', 'title' => 'Extras', 'position' => 0, 'enabled' => true,
        ]);

        $fee = $this->question($section, 'fee', QuestionType::Radio);
        $fee->options()->create(['value' => 'yes', 'label' => 'Yes', 'cost' => self::OPTION_COST, 'position' => 0]);

        $this->question($section, 'discount', QuestionType::DiscountCode);
    }

    /** A question fixture. */
    private function question(Section $section, string $key, QuestionType $type): Question
    {
        return Question::create([
            'section_id' => $section->id, 'key' => $key, 'type' => $type->value,
            'label' => ucfirst($key), 'position' => 0, 'required' => false, 'enabled' => true,
        ]);
    }

    /** A registrant with stored answers. */
    private function registrant(array $answers): User
    {
        $group = Group::factory()->create();
        $user = $this->makeUser(['group_id' => $group->id]);
        $this->storeAnswers($user, QuestionScope::Participant, $answers);

        return $user->fresh();
    }
}
