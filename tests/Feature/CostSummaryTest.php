<?php

namespace ConferenceTools\Registration\Tests\Feature;

use ConferenceTools\Registration\Enums\BooleanOperator;
use ConferenceTools\Registration\Enums\ConditionOperator;
use ConferenceTools\Registration\Enums\PerDiemMode;
use ConferenceTools\Registration\Enums\PerDiemScope;
use ConferenceTools\Registration\Enums\QuestionScope;
use ConferenceTools\Registration\Enums\QuestionType;
use ConferenceTools\Registration\Models\BaseCharge;
use ConferenceTools\Registration\Models\DiscountCode;
use ConferenceTools\Registration\Models\Guest;
use ConferenceTools\Registration\Models\Question;
use ConferenceTools\Registration\Models\Section;
use ConferenceTools\Registration\Services\AnswerStore;
use ConferenceTools\Registration\Services\CostSummaryBuilder;
use ConferenceTools\Registration\Services\PerDiem;
use ConferenceTools\Registration\Tests\Concerns\BuildsRegistrationData;
use ConferenceTools\Registration\Tests\Fixtures\QuestionConfigSeeder;
use ConferenceTools\Registration\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * The invoice-style cost summary: line items derived live from the configured
 * base charges, the priced options behind the answers, the per-diem settings
 * and any discount — with a total that matches what the registrant is charged
 * (InteractsWithRegistration::cost()).
 */
#[TestDox('Cost Summary')]
class CostSummaryTest extends TestCase
{
    use BuildsRegistrationData, RefreshDatabase;

    /** The builder under test, resolved from the container. */
    private function builder(): CostSummaryBuilder
    {
        return app(CostSummaryBuilder::class);
    }

    /** Seed the questionnaire these tests submit against. */
    private function seedForm(): void
    {
        $this->defaultCurrency();
        $this->seed(QuestionConfigSeeder::class);
    }

    #[TestDox('lines come from charges options and per diem and the total matches cost')]
    public function test_lines_come_from_charges_options_and_per_diem_and_the_total_matches_cost(): void
    {
        $this->seedForm();
        BaseCharge::factory()->create(['name' => 'Conference Fee', 'amount' => 250, 'enabled' => true]);
        BaseCharge::factory()->create(['name' => 'Disabled Fee', 'amount' => 999, 'enabled' => false]);

        // A per-diem-bearing option, priced from the per-diem settings rather
        // than a fixed option cost.
        $section = Section::firstWhere('key', 'accommodation');
        $partner = Question::create([
            'section_id' => $section->id, 'key' => 'partner', 'type' => QuestionType::Radio->value,
            'label' => 'Partner', 'position' => 3, 'required' => false, 'enabled' => true,
        ]);
        $partner->options()->create(['value' => 'yes', 'label' => 'Partner program', 'per_diem_days' => 3, 'per_diem_scope' => PerDiemScope::AttendeeAndGuests, 'position' => 0]);
        app(PerDiem::class)->update(40.0, 30.0, 20.0, null, PerDiemMode::ExtraOnly);

        // A Guest-scope priced option (e.g. an add-on a guest can choose),
        // exercised by the adult guest below.
        $guestSection = Section::create([
            'scope' => QuestionScope::Guest->value, 'key' => 'guest-extras', 'title' => 'Guest Extras', 'position' => 0, 'enabled' => true,
        ]);
        $tshirt = Question::create([
            'section_id' => $guestSection->id, 'key' => 'tshirt', 'type' => QuestionType::Radio->value,
            'label' => 'T-shirt', 'position' => 0, 'required' => false, 'enabled' => true,
        ]);
        $tshirt->options()->create(['value' => 'yes', 'label' => 'T-shirt', 'cost' => 15, 'position' => 0]);

        $answers = [
            'accommodation' => 'hotel',       // priced option, 100
            'products' => ['dinner'],         // priced multi-value option, 20
            'partner' => 'yes',
        ];
        $guests = [
            ['id' => 'g1', 'type' => 'adult', 'answers' => ['tshirt' => 'yes']],
            ['id' => 'g2', 'type' => 'minor', 'answers' => []],
        ];

        $summary = $this->builder()->fromAnswers($answers, $guests);

        $this->assertSame(
            [
                ['Conference Fee', 250.0],
                ['Hotel', 100.0],
                ['Dinner', 20.0],
                ['T-shirt', 15.0],
                ['Partner program (3 days)', 120.0],                 // 40 × 3
                ['Accompanying adult guests (1 × 3 days)', 90.0],     // 30 × 3 × 1
                ['Accompanying minor guests (1 × 3 days)', 60.0],     // 20 × 3 × 1
            ],
            array_map(fn (array $line) => [$line['label'], $line['amount']], $summary->lines),
        );
        $this->assertSame(655.0, $summary->subtotal);
        $this->assertNull($summary->discount);
        $this->assertSame(655.0, $summary->total);
        $this->assertFalse($summary->isEmpty());

        // The same answers and guests committed through the answer store are
        // charged exactly the summary's total.
        $user = $this->makeUser();
        $this->storeAnswers($user, QuestionScope::Participant, $answers);
        foreach ($guests as $position => $entry) {
            $guest = Guest::create(['user_id' => $user->id, 'type' => $entry['type'], 'position' => $position]);
            app(AnswerStore::class)->store(QuestionScope::Guest, $guest, $entry['answers']);
        }
        $this->assertSame($summary->total, $user->fresh()->cost());
    }

    #[TestDox('a discount code answer adjusts the total and renders its own lines')]
    public function test_a_discount_code_answer_adjusts_the_total_and_renders_its_own_lines(): void
    {
        $this->seedForm();
        // "90%" = the subtotal becomes 90% (see DiscountFormula).
        DiscountCode::factory()->create(['code' => 'SAVE10', 'formula' => '90%']);
        $details = Section::firstWhere('key', 'your-details');
        Question::create([
            'section_id' => $details->id, 'key' => 'discount', 'type' => QuestionType::DiscountCode->value,
            'label' => 'Discount code', 'position' => 9, 'required' => false, 'enabled' => true,
        ]);

        $summary = $this->builder()->fromAnswers(['accommodation' => 'hotel', 'discount' => 'save10']);

        $this->assertSame(100.0, $summary->subtotal);
        $this->assertSame('SAVE10', $summary->discount->code);
        $this->assertSame(10.0, $summary->discountAmount());
        $this->assertSame(90.0, $summary->total);

        // The text rendering (the {cost_summary} token) shows subtotal and
        // discount lines only when a discount applies.
        $this->assertSame(
            "Hotel: $ 100.00\nSubtotal: $ 100.00\nDiscount (SAVE10): -$ 10.00\nTotal: $ 90.00",
            $summary->toText($this->defaultCurrency()),
        );
    }

    #[TestDox('zero cost options are listed and unknown or free values are not')]
    public function test_zero_cost_options_are_listed_and_unknown_or_free_values_are_not(): void
    {
        $this->seedForm();

        // "No accommodation" is explicitly priced at 0 → listed; a value that
        // matches no option, and answers to unpriced/free-text questions,
        // produce no lines.
        $summary = $this->builder()->fromAnswers([
            'accommodation' => 'none',
            'products' => ['bogus'],
            'name' => 'Ada',
            'gender' => 'f', // option question whose options carry no cost
        ]);

        $this->assertSame([['No accommodation', 0.0]], array_map(
            fn (array $line) => [$line['label'], $line['amount']],
            $summary->lines,
        ));
        $this->assertSame(0.0, $summary->total);
    }

    #[TestDox('a hidden questions answer is not priced')]
    public function test_a_hidden_questions_answer_is_not_priced(): void
    {
        $this->defaultCurrency();
        $section = Section::factory()->create(['scope' => QuestionScope::Participant, 'position' => 0]);
        $trigger = Question::factory()->for($section)->ofType(QuestionType::Radio)->create(['key' => 'trigger', 'position' => 0]);
        $trigger->options()->createMany([
            ['value' => 'a', 'label' => 'A', 'position' => 0],
            ['value' => 'b', 'label' => 'B', 'position' => 1],
        ]);

        // "extra" is priced but only visible when trigger == a.
        $extra = Question::factory()->for($section)->ofType(QuestionType::Radio)->create(['key' => 'extra', 'position' => 1]);
        $extra->options()->create(['value' => 'yes', 'label' => 'Extra', 'cost' => 50, 'position' => 0]);
        $group = $extra->conditionGroups()->create(['operator' => BooleanOperator::And->value]);
        $group->conditions()->create(['question_id' => $trigger->id, 'operator' => ConditionOperator::Equals->value, 'value' => 'a']);

        // A stale "extra" answer while the rule fails prices nothing…
        $hidden = $this->builder()->fromAnswers(['trigger' => 'b', 'extra' => 'yes']);
        $this->assertSame([], $hidden->lines);
        $this->assertSame(0.0, $hidden->total);

        // …and is priced normally once the rule passes.
        $shown = $this->builder()->fromAnswers(['trigger' => 'a', 'extra' => 'yes']);
        $this->assertSame(50.0, $shown->total);
    }

    #[TestDox('an unconfigured conference prices nothing')]
    public function test_an_unconfigured_conference_prices_nothing(): void
    {
        // No charges, no questions: nothing to show, and the plain-text token
        // still renders a deterministic total (without a currency configured,
        // amounts fall back to plain numbers).
        $summary = $this->builder()->fromAnswers([]);

        $this->assertTrue($summary->isEmpty());
        $this->assertSame([], $summary->lines);
        $this->assertSame(0.0, $summary->discountAmount());
        $this->assertSame('Total: 0.00', $summary->toText(null));
    }

    #[TestDox('a group members split summary puts base charges on the leader and everything else on the member')]
    public function test_a_group_members_split_summary_puts_base_charges_on_the_leader_and_everything_else_on_the_member(): void
    {
        $this->seedForm();
        BaseCharge::factory()->create(['name' => 'Conference Fee', 'amount' => 250, 'enabled' => true]);
        BaseCharge::factory()->create(['name' => 'Disabled Fee', 'amount' => 999, 'enabled' => false]);

        $answers = ['accommodation' => 'hotel', 'products' => ['dinner']];
        $guests = [['id' => 'g1', 'type' => 'adult', 'answers' => []]];

        $summary = $this->builder()->fromAnswersForMember($answers, $guests);

        $this->assertSame([['Conference Fee', 250.0]], array_map(
            fn (array $line) => [$line['label'], $line['amount']],
            $summary->leaderLines,
        ));
        $this->assertSame(250.0, $summary->leaderTotal);

        $this->assertSame(
            [['Hotel', 100.0], ['Dinner', 20.0]],
            array_map(fn (array $line) => [$line['label'], $line['amount']], $summary->memberLines),
        );
        $this->assertSame(120.0, $summary->memberSubtotal);
        $this->assertNull($summary->discount);
        $this->assertSame(120.0, $summary->memberTotal);
        $this->assertFalse($summary->isEmpty());

        // Nothing was double-counted or dropped in the split: the two totals
        // together reconcile against the equivalent solo summary.
        $solo = $this->builder()->fromAnswers($answers, $guests);
        $this->assertSame($solo->total, round($summary->leaderTotal + $summary->memberTotal, 2));
    }

    #[TestDox('a discount code the member enters reduces only their own total, never the leaders')]
    public function test_a_discount_code_the_member_enters_reduces_only_their_own_total_never_the_leaders(): void
    {
        $this->seedForm();
        BaseCharge::factory()->create(['name' => 'Conference Fee', 'amount' => 250, 'enabled' => true]);
        DiscountCode::factory()->create(['code' => 'SAVE10', 'formula' => '90%']);
        $details = Section::firstWhere('key', 'your-details');
        Question::create([
            'section_id' => $details->id, 'key' => 'discount', 'type' => QuestionType::DiscountCode->value,
            'label' => 'Discount code', 'position' => 9, 'required' => false, 'enabled' => true,
        ]);

        $summary = $this->builder()->fromAnswersForMember(['accommodation' => 'hotel', 'discount' => 'save10']);

        $this->assertSame(250.0, $summary->leaderTotal);
        $this->assertSame(100.0, $summary->memberSubtotal);
        $this->assertSame('SAVE10', $summary->discount->code);
        $this->assertSame(10.0, $summary->discountAmount());
        $this->assertSame(90.0, $summary->memberTotal);
    }

    #[TestDox('the split summary renders as two labeled text blocks')]
    public function test_the_split_summary_renders_as_two_labeled_text_blocks(): void
    {
        $this->seedForm();
        BaseCharge::factory()->create(['name' => 'Conference Fee', 'amount' => 250, 'enabled' => true]);

        $summary = $this->builder()->fromAnswersForMember(['accommodation' => 'hotel']);

        $this->assertSame(
            "Covered by Your Group Leader:\nConference Fee: $ 250.00\nTotal Covered: $ 250.00\n\n"
                ."Your Responsibility:\nHotel: $ 100.00\nTotal: $ 100.00",
            $summary->toText($this->defaultCurrency()),
        );
    }

    #[TestDox('an empty group member summary has nothing on either side')]
    public function test_an_empty_group_member_summary_has_nothing_on_either_side(): void
    {
        $summary = $this->builder()->fromAnswersForMember([]);

        $this->assertTrue($summary->isEmpty());
        $this->assertSame([], $summary->leaderLines);
        $this->assertSame([], $summary->memberLines);
    }
}
