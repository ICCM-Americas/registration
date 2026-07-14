<?php

namespace ConferenceTools\Registration\Tests\Feature;

use ConferenceTools\Registration\Enums\Gender;
use ConferenceTools\Registration\Enums\QuestionScope;
use ConferenceTools\Registration\Models\Setting;
use ConferenceTools\Registration\Services\ReportQuestions;
use ConferenceTools\Registration\Tests\Concerns\BuildsReportData;
use ConferenceTools\Registration\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;

/** Feature tests for Report Questions. */
#[TestDox('Report Questions')]
class ReportQuestionsTest extends TestCase
{
    use BuildsReportData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReportQuestions();
    }

    /** The ReportQuestions service under test. */
    private function questions(): ReportQuestions
    {
        return app(ReportQuestions::class);
    }

    #[TestDox('question key reads the setting and null while unset')]
    public function test_question_key_reads_the_setting_and_null_while_unset(): void
    {
        $this->assertSame('badgename', $this->questions()->questionKey(ReportQuestions::BADGE_NAME_KEY));

        Setting::put(ReportQuestions::BADGE_NAME_KEY, null);
        $this->assertNull($this->questions()->questionKey(ReportQuestions::BADGE_NAME_KEY));
    }

    #[TestDox('has stale matches is false with no value list settings left')]
    public function test_has_stale_matches_is_false_with_no_value_list_settings_left(): void
    {
        // Every ReportQuestions value list retired with the hard-coded
        // reports; staleness now only arises from GuestQuestions' lists.
        $this->assertSame([], ReportQuestions::VALUE_QUESTION_KEYS);
        $this->assertFalse($this->questions()->hasStaleMatches());
    }

    #[TestDox('update persists known settings and clears blank ones')]
    public function test_update_persists_known_settings_and_clears_blank_ones(): void
    {
        $this->questions()->update([
            ReportQuestions::BADGE_NAME_KEY => 'roommate',
            ReportQuestions::GENDER_KEY => '',
            'unrelated' => 'ignored',
        ]);

        $this->assertSame('roommate', $this->questions()->questionKey(ReportQuestions::BADGE_NAME_KEY));
        $this->assertNull($this->questions()->questionKey(ReportQuestions::GENDER_KEY));
        // Untouched settings keep their values; unknown keys are never stored.
        $this->assertSame('roommate', $this->questions()->questionKey(ReportQuestions::ROOMMATE_KEY));
        $this->assertNull(Setting::get('unrelated'));
    }

    #[TestDox('badge name uses the nominated questions answer')]
    public function test_badge_name_uses_the_nominated_questions_answer(): void
    {
        $user = $this->makeRegistrant('Ada', 'Lovelace', ['badgename' => 'Ada L.']);

        $this->assertSame('Ada L.', $this->questions()->badgeName($user));
    }

    /** There is no fallback — the badge name is null while unnominated. */
    #[TestDox('badge name is null while unnominated')]
    public function test_badge_name_is_null_while_unnominated(): void
    {
        $user = $this->makeRegistrant('Charles', 'Babbage', ['badgename' => 'Charles B.']);
        Setting::put(ReportQuestions::BADGE_NAME_KEY, null);

        $this->assertNull($this->questions()->badgeName($user));
    }

    #[TestDox('first and last name read the nominated questions')]
    public function test_first_and_last_name_read_the_nominated_questions(): void
    {
        $user = $this->makeRegistrant('Ada', 'Lovelace');

        $this->assertSame('Ada', $this->questions()->firstName($user));
        $this->assertSame('Lovelace', $this->questions()->lastName($user));
    }

    #[DataProvider('fullNameCases')]
    #[TestDox('full name joins first and last, trimmed to handle either being blank')]
    public function test_full_name_joins_first_and_last(array $answers, ?string $expected): void
    {
        $user = $this->makeRegistrant('Ada', 'Lovelace', $answers);

        $this->assertSame($expected, $this->questions()->fullName($user));
    }

    /** The first/last name answer combinations for the data provider. */
    public static function fullNameCases(): array
    {
        return [
            'both present' => [[], 'Ada Lovelace'],
            'no last name' => [['lastname' => ''], 'Ada'],
            'no first name' => [['name' => ''], 'Lovelace'],
            'both blank' => [['name' => '', 'lastname' => ''], null],
        ];
    }

    #[TestDox('organization falls back from group answer to group name')]
    public function test_organization_falls_back_from_group_answer_to_group_name(): void
    {
        $user = $this->makeRegistrant('Ada', 'Lovelace');

        // The organization question is group-scoped: the group's answer wins.
        $this->storeAnswers($user->group, QuestionScope::Group, ['organization' => 'Engines Ltd']);
        $user->group->refreshRegistrationAnswers();
        $this->assertSame('Engines Ltd', $this->questions()->organization($user));

        // Without a nominated question, the group (organization) name stands in.
        Setting::put(ReportQuestions::ORGANIZATION_KEY, null);
        $this->assertSame('Analytical Engines', $this->questions()->organization($user));
    }

    #[DataProvider('genders')]
    #[TestDox('gender is derived leniently')]
    public function test_gender_is_derived_leniently(?string $answer, ?Gender $expected): void
    {
        $user = $this->makeRegistrant('Pat', 'Test', ['gender' => $answer]);

        $this->assertSame($expected, $this->questions()->gender($user));
    }

    /** The genders for the data provider. */
    public static function genders(): array
    {
        return [
            'enum value' => ['m', Gender::Male],
            'word' => ['Female', Gender::Female],
            'unrecognized' => ['other', null],
            'unanswered' => [null, null],
        ];
    }

    #[TestDox('travel answers are read raw')]
    public function test_travel_answers_are_read_raw(): void
    {
        $user = $this->makeRegistrant('Pat', 'Test', [
            'roommate' => 'Sam Test',
            'arrivalday' => 'monday', 'arrivalflight' => '10:30',
            'departureflight' => '16:00',
        ]);

        $this->assertSame('Sam Test', $this->questions()->roommateName($user));
        $this->assertSame('monday', $this->questions()->arrivalDay($user));
        $this->assertSame('10:30', $this->questions()->flightArrivalTime($user));
        $this->assertSame('16:00', $this->questions()->flightDepartureTime($user));

        $blank = $this->makeRegistrant('Alex', 'Blank');
        $this->assertNull($this->questions()->arrivalDay($blank));
        $this->assertNull($this->questions()->flightArrivalTime($blank));
    }

    #[TestDox('flight times are extracted from around the flight numbers')]
    public function test_flight_times_are_extracted_from_around_the_flight_numbers(): void
    {
        $user = $this->makeRegistrant('Pat', 'Test', [
            'arrivalflight' => 'UA 0921 lands 10:30 am',
            'departureflight' => 'UA-0922 at 16:00',
        ]);

        $this->assertSame('10:30', $this->questions()->flightArrivalTime($user));
        $this->assertSame('16:00', $this->questions()->flightDepartureTime($user));
    }

    #[TestDox('a shared travel plans question splits into arrival and departure by time order')]
    public function test_a_shared_travel_plans_question_splits_into_arrival_and_departure_by_time_order(): void
    {
        Setting::put(ReportQuestions::FLIGHT_DEPARTURE_KEY, 'arrivalflight');

        $both = $this->makeRegistrant('Pat', 'Test', [
            'arrivalflight' => 'Arriving AA1234 at 9:15 AM, departing AA-4321 at 4:40 PM',
        ]);
        $this->assertSame('09:15', $this->questions()->flightArrivalTime($both));
        $this->assertSame('16:40', $this->questions()->flightDepartureTime($both));

        // One readable time can't say which flight it is, so the answer
        // passes through unparsed for the planner to surface for hand
        // scheduling.
        $ambiguous = $this->makeRegistrant('Sam', 'Test', ['arrivalflight' => 'DL5678 at 9:15 AM']);
        $this->assertSame('DL5678 at 9:15 AM', $this->questions()->flightArrivalTime($ambiguous));
        $this->assertSame('DL5678 at 9:15 AM', $this->questions()->flightDepartureTime($ambiguous));
    }
}
