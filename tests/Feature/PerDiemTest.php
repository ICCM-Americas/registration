<?php

namespace ConferenceTools\Registration\Tests\Feature;

use ConferenceTools\Registration\Enums\GuestType;
use ConferenceTools\Registration\Enums\PerDiemMode;
use ConferenceTools\Registration\Enums\PerDiemScope;
use ConferenceTools\Registration\Enums\QuestionScope;
use ConferenceTools\Registration\Enums\QuestionType;
use ConferenceTools\Registration\Models\DiscountCode;
use ConferenceTools\Registration\Models\Group;
use ConferenceTools\Registration\Models\Guest;
use ConferenceTools\Registration\Models\Question;
use ConferenceTools\Registration\Models\Section;
use ConferenceTools\Registration\Services\PerDiem;
use ConferenceTools\Registration\Tests\Concerns\BuildsRegistrationData;
use ConferenceTools\Registration\Tests\Fixtures\User;
use ConferenceTools\Registration\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Per-diem (room & board) pricing: the daily rate lives in the pricing settings,
 * and each choice option carries the number of days it adds and whether those
 * days also cover accompanying non-attending guests. The attendee is billed at
 * the attendee rate for every day, governed by the configured mode (extra days
 * only, or every day of the conference too). Each accompanying guest is billed,
 * independently of that mode, at their own type's rate (adult or minor) for
 * every day of the conference plus any guest-inclusive extra days — a guest
 * can't selectively skip the conference itself. Guest headcounts come from
 * real Guest rows, never an answered question.
 */
#[TestDox('Per Diem')]
class PerDiemTest extends TestCase
{
    use BuildsRegistrationData, RefreshDatabase;

    private const ATTENDEE_RATE = 40.0;

    private const GUEST_ADULT_RATE = 30.0;

    private const GUEST_MINOR_RATE = 20.0;

    private const CONFERENCE_DAYS = 5;

    #[DataProvider('costScenarios')]
    #[TestDox('per diem is added to the registrant cost')]
    public function test_per_diem_is_added_to_the_registrant_cost(PerDiemMode $mode, array $answers, int $adultGuests, int $minorGuests, float $expected): void
    {
        $this->perDiemQuestions();
        $this->configure($mode);

        $this->assertSame($expected, $this->registrant($answers, $adultGuests, $minorGuests)->cost());
    }

    /** The cost scenarios for the data provider. */
    public static function costScenarios(): array
    {
        // Rates: attendee 40/day, adult guest 30/day, minor guest 20/day. Conference is 5 days.
        // Guests are always billed for the conference's 5 days plus any guest-inclusive
        // extra days, regardless of the attendee's mode — that mode governs the attendee
        // rate only.
        return [
            // Extra-only: the conference's own days are never billed to the attendee.
            'no per-diem selections' => [PerDiemMode::ExtraOnly, [], 0, 0, 0.0],
            'attendee-only option days' => [PerDiemMode::ExtraOnly, ['setup' => 'yes'], 0, 0, 80.0], // 40×2
            'guest option, no guests' => [PerDiemMode::ExtraOnly, ['partner' => 'yes'], 0, 0, 120.0], // 40×3
            'guest option billed per adult guest' => [PerDiemMode::ExtraOnly, ['partner' => 'yes'], 2, 0, 600.0], // 40×3 + 30×(5+3)×2
            'guest option billed per minor guest' => [PerDiemMode::ExtraOnly, ['partner' => 'yes'], 0, 2, 440.0], // 40×3 + 20×(5+3)×2
            'guest option billed per mixed guests' => [PerDiemMode::ExtraOnly, ['partner' => 'yes'], 1, 1, 520.0], // 40×3 + 30×(5+3)×1 + 20×(5+3)×1
            'attendee-only option excluded from guest days, conference days still billed' => [PerDiemMode::ExtraOnly, ['setup' => 'yes'], 2, 0, 380.0], // 40×2 + 30×5×2
            'no attendee options, guests still billed conference days' => [PerDiemMode::ExtraOnly, [], 2, 0, 300.0], // attendee pays 0; 30×5×2
            // All-days: the conference's 5 days are billed to the attendee too.
            'conference days only' => [PerDiemMode::AllDays, [], 0, 0, 200.0], // 40×5
            'conference days with adult guest' => [PerDiemMode::AllDays, [], 2, 0, 500.0], // 40×5 + 30×5×2
            'conference days with minor guest' => [PerDiemMode::AllDays, [], 0, 2, 400.0], // 40×5 + 20×5×2
            'conference plus attendee option' => [PerDiemMode::AllDays, ['setup' => 'yes'], 0, 0, 280.0], // 40×7
            'conference plus guest option with adult' => [PerDiemMode::AllDays, ['partner' => 'yes'], 1, 0, 560.0], // 40×8 + 30×8×1
            'conference plus guest option with minor' => [PerDiemMode::AllDays, ['partner' => 'yes'], 0, 1, 480.0], // 40×8 + 20×8×1
        ];
    }

    #[TestDox('unconfigured rates leave the cost unchanged')]
    public function test_unconfigured_rates_leave_the_cost_unchanged(): void
    {
        $this->perDiemQuestions();
        // No PerDiem::update() call: rates default to 0, mode to extra-only.

        $this->assertSame(0.0, $this->registrant(['setup' => 'yes'], 2, 1)->cost());
    }

    #[TestDox('per diem is inside the discountable subtotal')]
    public function test_per_diem_is_inside_the_discountable_subtotal(): void
    {
        $this->perDiemQuestions(withDiscount: true);
        $this->configure(PerDiemMode::ExtraOnly);
        DiscountCode::factory()->create(['code' => 'K7QND2', 'formula' => '-30']);

        // 40×2 = 80 per-diem, discount -30 → 50.
        $this->assertSame(50.0, $this->registrant(['setup' => 'yes', 'discount' => 'K7QND2'])->cost());
    }

    #[TestDox('group cost includes each members per diem')]
    public function test_group_cost_includes_each_members_per_diem(): void
    {
        $this->perDiemQuestions();
        $this->configure(PerDiemMode::ExtraOnly);

        $group = Group::factory()->create();
        for ($i = 0; $i < 2; $i++) {
            $user = $this->makeUser(['group_id' => $group->id]);
            $this->storeAnswers($user, QuestionScope::Participant, ['setup' => 'yes']);
        }

        // Two registrants × (40×2) = 160.
        $this->assertSame(160.0, $group->fresh()->cost());
    }

    #[TestDox('breakdown itemizes the contributions')]
    public function test_breakdown_itemizes_the_contributions(): void
    {
        $this->perDiemQuestions();
        $this->configure(PerDiemMode::AllDays);
        $user = $this->registrant(['partner' => 'yes'], 2, 1);

        $breakdown = app(PerDiem::class)->breakdown($user->registrationAnswers(), 2, 1);

        // Conference (5, guests) first, then the chosen guest-inclusive option (3, guests).
        $this->assertSame([5, 3], array_column($breakdown->contributions, 'days'));
        $this->assertSame(8, $breakdown->attendeeDays());
        $this->assertSame(8, $breakdown->guestDays());
        $this->assertSame(2, $breakdown->adultGuestCount);
        $this->assertSame(1, $breakdown->minorGuestCount);
        $this->assertSame(320.0, $breakdown->attendeeAmount()); // 40×8
        $this->assertSame(480.0, $breakdown->adultGuestAmount()); // 30×8×2
        $this->assertSame(160.0, $breakdown->minorGuestAmount()); // 20×8×1
        $this->assertSame(640.0, $breakdown->guestAmount());    // 480 + 160
        $this->assertSame(960.0, $breakdown->total());
        $this->assertFalse($breakdown->isEmpty());
    }

    #[TestDox('breakdown itemizes the attendee lines and prices each guest type independently')]
    public function test_breakdown_itemizes_the_attendee_lines_and_prices_each_guest_type_independently(): void
    {
        $this->perDiemQuestions();
        $this->configure(PerDiemMode::AllDays);
        $user = $this->registrant(['partner' => 'yes'], 2, 1);

        $breakdown = app(PerDiem::class)->breakdown($user->registrationAnswers(), 2, 1);

        // Conference (5 days, guests) then the chosen guest-inclusive option
        // (3 days) — its label is the stored answer text ("yes", the raw
        // selected value here — see AnswerBagTest for why), not the option's
        // own label column.
        $this->assertSame(
            [
                ['label' => 'Conference (5 days)', 'amount' => 200.0], // 40×5
                ['label' => 'yes (3 days)', 'amount' => 120.0], // 40×3
            ],
            $breakdown->attendeeLines(),
        );
        // Each guest's own share (8 days) at their own type's rate — not the
        // aggregate adultGuestAmount()/minorGuestAmount(), which are billed
        // per headcount instead.
        $this->assertSame(240.0, $breakdown->amountForGuest(GuestType::Adult)); // 30×8
        $this->assertSame(160.0, $breakdown->amountForGuest(GuestType::Minor)); // 20×8
    }

    #[TestDox('guest days are billed independently of the attendee mode')]
    public function test_guest_days_are_billed_independently_of_the_attendee_mode(): void
    {
        $this->perDiemQuestions();
        $this->configure(PerDiemMode::ExtraOnly);
        $user = $this->registrant([]); // attendee chooses nothing

        $breakdown = app(PerDiem::class)->breakdown($user->registrationAnswers(), 2, 0);

        // Extra-only bills the attendee nothing, but a guest can't skip the conference.
        $this->assertSame([], $breakdown->contributions);
        $this->assertSame(0, $breakdown->attendeeDays());
        $this->assertSame(self::CONFERENCE_DAYS, $breakdown->guestDays());
        $this->assertSame(0.0, $breakdown->attendeeAmount());
        $this->assertSame(300.0, $breakdown->adultGuestAmount()); // 30×5×2
        $this->assertFalse($breakdown->isEmpty());
    }

    #[TestDox('all days with zero conference days adds no conference line')]
    public function test_all_days_with_zero_conference_days_adds_no_conference_line(): void
    {
        $this->perDiemQuestions();
        app(PerDiem::class)->update(self::ATTENDEE_RATE, self::GUEST_ADULT_RATE, self::GUEST_MINOR_RATE, 0, PerDiemMode::AllDays);
        $user = $this->registrant([]); // nothing chosen and no conference days → no per-diem

        $this->assertSame(0.0, $user->cost());
        $this->assertSame([], app(PerDiem::class)->breakdown($user->registrationAnswers())->contributions);
    }

    #[TestDox('breakdown with days but zero rate is empty')]
    public function test_breakdown_with_days_but_zero_rate_is_empty(): void
    {
        $this->perDiemQuestions();
        // Conference days configured (a contribution exists) but no rate → nothing to bill.
        app(PerDiem::class)->update(0.0, 0.0, 0.0, self::CONFERENCE_DAYS, PerDiemMode::AllDays);
        $breakdown = app(PerDiem::class)->breakdown($this->registrant([])->registrationAnswers());

        $this->assertNotSame([], $breakdown->contributions);
        $this->assertSame(0.0, $breakdown->total());
        $this->assertTrue($breakdown->isEmpty());
    }

    #[TestDox('breakdown is empty when nothing is billed')]
    public function test_breakdown_is_empty_when_nothing_is_billed(): void
    {
        $this->perDiemQuestions();
        $this->configure(PerDiemMode::ExtraOnly);
        $user = $this->registrant([]); // no per-diem option chosen

        $breakdown = app(PerDiem::class)->breakdown($user->registrationAnswers());

        $this->assertSame([], $breakdown->contributions);
        $this->assertTrue($breakdown->isEmpty());
        $this->assertSame(0.0, $breakdown->total());
    }

    #[TestDox('settings round trip and defaults')]
    public function test_settings_round_trip_and_defaults(): void
    {
        $perDiem = app(PerDiem::class);

        // Defaults with nothing stored.
        $this->assertSame(0.0, $perDiem->attendeeRate());
        $this->assertSame(0.0, $perDiem->guestAdultRate());
        $this->assertSame(0.0, $perDiem->guestMinorRate());
        $this->assertSame(PerDiemMode::ExtraOnly, $perDiem->mode());

        $perDiem->update(40.0, 30.0, 20.0, 5, PerDiemMode::AllDays);
        $this->assertSame(40.0, $perDiem->attendeeRate());
        $this->assertSame(30.0, $perDiem->guestAdultRate());
        $this->assertSame(20.0, $perDiem->guestMinorRate());
        $this->assertSame(5, $perDiem->conferenceDays());
        $this->assertSame(PerDiemMode::AllDays, $perDiem->mode());

        // Nulls clear the settings back to their defaults.
        $perDiem->update(null, null, null, null, PerDiemMode::ExtraOnly);
        $this->assertSame(0.0, $perDiem->attendeeRate());
        $this->assertSame(0.0, $perDiem->guestAdultRate());
        $this->assertSame(0.0, $perDiem->guestMinorRate());
        $this->assertSame(0, $perDiem->conferenceDays());
    }

    // -- Setup helpers -------------------------------------------------------

    /** Store the settings the scenario needs. */
    private function configure(PerDiemMode $mode): void
    {
        app(PerDiem::class)->update(self::ATTENDEE_RATE, self::GUEST_ADULT_RATE, self::GUEST_MINOR_RATE, self::CONFERENCE_DAYS, $mode);
    }

    /**
     * A participant section with two per-diem-bearing radios — "setup" (2 days,
     * attendee only) and "partner" (3 days, attendee and guests) — plus,
     * optionally, a discount field.
     */
    private function perDiemQuestions(bool $withDiscount = false): void
    {
        $this->defaultCurrency();

        $section = Section::create([
            'scope' => QuestionScope::Participant->value,
            'key' => 'extras', 'title' => 'Extras', 'position' => 0, 'enabled' => true,
        ]);

        $setup = $this->question($section, 'setup', QuestionType::Radio);
        $setup->options()->create(['value' => 'yes', 'label' => 'Set-up team', 'per_diem_days' => 2, 'per_diem_scope' => PerDiemScope::Attendee, 'position' => 0]);

        $partner = $this->question($section, 'partner', QuestionType::Radio);
        $partner->options()->create(['value' => 'yes', 'label' => 'Partner program', 'per_diem_days' => 3, 'per_diem_scope' => PerDiemScope::AttendeeAndGuests, 'position' => 0]);

        if ($withDiscount) {
            $this->question($section, 'discount', QuestionType::DiscountCode);
        }
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
    private function registrant(array $answers, int $adultGuests = 0, int $minorGuests = 0): User
    {
        $group = Group::factory()->create();
        $user = $this->makeUser(['group_id' => $group->id]);
        $this->storeAnswers($user, QuestionScope::Participant, $answers);

        Guest::factory()->count($adultGuests)->create(['user_id' => $user->id, 'type' => GuestType::Adult]);
        Guest::factory()->count($minorGuests)->create(['user_id' => $user->id, 'type' => GuestType::Minor]);

        return $user->fresh();
    }
}
