<?php

namespace ConferenceTools\Registration\Tests\Feature;

use ConferenceTools\Registration\Enums\GuestType;
use ConferenceTools\Registration\Models\PrayerPalsAssignment;
use ConferenceTools\Registration\Models\PrayerPalsGroup;
use ConferenceTools\Registration\Models\Room;
use ConferenceTools\Registration\Models\RoomAssignment;
use ConferenceTools\Registration\Services\DashboardSummary;
use ConferenceTools\Registration\Tests\Concerns\BuildsReportData;
use ConferenceTools\Registration\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestDox;

/** Feature tests for Dashboard Summary. */
#[TestDox('Dashboard Summary')]
class DashboardSummaryTest extends TestCase
{
    use BuildsReportData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReportQuestions();
    }

    /** The summary service under test, resolved from the container. */
    private function summary(): DashboardSummary
    {
        return app(DashboardSummary::class);
    }

    #[TestDox('counts attendees and their non-attending guests')]
    public function test_counts_attendees_and_their_non_attending_guests(): void
    {
        $alan = $this->makeRegistrant('Alan', 'Turing');
        $this->makeGuest($alan, GuestType::Adult);
        $this->makeGuest($alan, GuestType::Minor);
        $this->makeRegistrant('Grace', 'Hopper');

        $this->assertSame(2, $this->summary()->attendeesCount());
        $this->assertSame(2, $this->summary()->guestsCount());
    }

    #[TestDox('special needs is zero while the question is unnominated')]
    public function test_special_needs_is_zero_while_the_question_is_unnominated(): void
    {
        $this->makeRegistrant('Alan', 'Turing', ['specialneeds' => 'Wheelchair access']);

        $this->assertSame(0, $this->summary()->specialNeedsCount());
    }

    #[TestDox('special needs counts only those who answered')]
    public function test_special_needs_counts_only_those_who_answered(): void
    {
        $this->seedRetiredReportSettings();
        $this->makeRegistrant('Alan', 'Turing', ['specialneeds' => 'Wheelchair access']);
        $this->makeRegistrant('Grace', 'Hopper', ['specialneeds' => '']);

        $this->assertSame(1, $this->summary()->specialNeedsCount());
    }

    #[TestDox('arrivals are grouped and counted by day label')]
    public function test_arrivals_are_grouped_and_counted_by_day_label(): void
    {
        $this->makeRegistrant('Alan', 'Turing', ['arrivalday' => 'monday']);
        $this->makeRegistrant('Grace', 'Hopper', ['arrivalday' => 'monday']);
        $this->makeRegistrant('Ada', 'Lovelace', ['arrivalday' => 'tuesday']);
        // Never answered the arrival-day question: omitted, not zero-counted.
        $this->makeRegistrant('No', 'Answer', ['arrivalday' => '']);

        $this->assertSame(['Monday' => 2, 'Tuesday' => 1], $this->summary()->arrivalsByDay()->all());
    }

    #[TestDox('shuttle runs combine arrivals and returns')]
    public function test_shuttle_runs_combine_arrivals_and_returns(): void
    {
        $this->makeRegistrant('Alan', 'Turing', [
            'arrivalday' => 'monday', 'arrivalflight' => '08:00', 'departureflight' => '10:00',
        ]);

        $this->assertSame(2, $this->summary()->shuttleRunsCount());
    }

    #[TestDox('room assignment coverage counts registrants and guests, both types')]
    public function test_room_assignment_coverage_counts_registrants_and_guests_both_types(): void
    {
        $housed = $this->makeRegistrant('Alan', 'Turing');
        $homeless = $this->makeRegistrant('Grace', 'Hopper');
        $guest = $this->makeGuest($homeless, GuestType::Adult);
        $room = Room::factory()->create();
        RoomAssignment::create(['room_id' => $room->id, 'assignable_type' => $housed->getMorphClass(), 'assignable_id' => $housed->getKey()]);

        $coverage = $this->summary()->roomAssignmentCoverage();

        $this->assertSame(['assigned' => 1, 'total' => 3, 'complete' => false], $coverage);

        RoomAssignment::create(['room_id' => $room->id, 'assignable_type' => $homeless->getMorphClass(), 'assignable_id' => $homeless->getKey()]);
        RoomAssignment::create(['room_id' => $room->id, 'assignable_type' => $guest->getMorphClass(), 'assignable_id' => $guest->getKey()]);

        $this->assertTrue($this->summary()->roomAssignmentCoverage()['complete']);
    }

    #[TestDox('room assignment coverage is not complete while there is no one to house')]
    public function test_room_assignment_coverage_is_not_complete_while_there_is_no_one_to_house(): void
    {
        $this->assertSame(['assigned' => 0, 'total' => 0, 'complete' => false], $this->summary()->roomAssignmentCoverage());
    }

    #[TestDox('prayer pals coverage only counts opted-in adult guests, never minors')]
    public function test_prayer_pals_coverage_only_counts_opted_in_adult_guests_never_minors(): void
    {
        $host = $this->makeRegistrant('Alan', 'Turing', ['gender' => 'm']);
        $optedIn = $this->makeGuest($host, GuestType::Adult, ['guestprayerpals' => 'yes']);
        $this->makeGuest($host, GuestType::Adult, ['guestprayerpals' => 'no']);
        $this->makeGuest($host, GuestType::Minor, ['guestprayerpals' => 'yes']);

        $coverage = $this->summary()->prayerPalsCoverage();
        $this->assertSame(['assigned' => 0, 'total' => 2, 'complete' => false], $coverage);

        $group = PrayerPalsGroup::factory()->create();
        PrayerPalsAssignment::create(['prayer_pals_group_id' => $group->id, 'assignable_type' => $host->getMorphClass(), 'assignable_id' => $host->getKey()]);
        PrayerPalsAssignment::create(['prayer_pals_group_id' => $group->id, 'assignable_type' => $optedIn->getMorphClass(), 'assignable_id' => $optedIn->getKey()]);

        $this->assertTrue($this->summary()->prayerPalsCoverage()['complete']);
    }
}
