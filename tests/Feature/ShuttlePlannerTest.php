<?php

namespace ConferenceTools\Registration\Tests\Feature;

use ConferenceTools\Registration\Enums\GuestType;
use ConferenceTools\Registration\Models\Setting;
use ConferenceTools\Registration\Services\GuestQuestions;
use ConferenceTools\Registration\Services\ReportQuestions;
use ConferenceTools\Registration\Services\ShuttlePlanner;
use ConferenceTools\Registration\Tests\Concerns\BuildsReportData;
use ConferenceTools\Registration\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestDox;

/** Feature tests for Shuttle Planner. */
#[TestDox('Shuttle Planner')]
class ShuttlePlannerTest extends TestCase
{
    use BuildsReportData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReportQuestions();
    }

    /** The planner under test, resolved from the container. */
    private function planner(): ShuttlePlanner
    {
        return app(ShuttlePlanner::class);
    }

    /** @return string[] */
    private function names($passengers): array
    {
        $questions = app(ReportQuestions::class);

        return $passengers->map(fn (Model $u): ?string => $questions->badgeName($u))->all();
    }

    #[TestDox('settings have defaults and are saved and cleared')]
    public function test_settings_have_defaults_and_are_saved_and_cleared(): void
    {
        $this->assertSame(ShuttlePlanner::DEFAULT_SEATS, $this->planner()->seats());
        $this->assertSame(ShuttlePlanner::DEFAULT_TRAVEL_MINUTES, $this->planner()->travelMinutes());
        $this->assertSame(ShuttlePlanner::DEFAULT_COUNT, $this->planner()->shuttleCount());

        $this->planner()->updateSettings(8, 45, 3);
        $this->assertSame(8, $this->planner()->seats());
        $this->assertSame(45, $this->planner()->travelMinutes());
        $this->assertSame(3, $this->planner()->shuttleCount());

        $this->planner()->updateSettings(null, null, null);
        $this->assertSame(ShuttlePlanner::DEFAULT_SEATS, $this->planner()->seats());
        $this->assertSame(ShuttlePlanner::DEFAULT_COUNT, $this->planner()->shuttleCount());
    }

    #[TestDox('pickups are batched so no one waits over three hours')]
    public function test_pickups_are_batched_so_no_one_waits_over_three_hours(): void
    {
        // 08:00 and 09:00 fit one run (dispatched when the 09:00 flight is
        // in); 11:30 would leave the 08:00 arrival waiting 3.5h, so it gets
        // its own run.
        $this->makeRegistrant('A', 'One', ['arrivalday' => 'monday', 'arrivalflight' => '08:00']);
        $this->makeRegistrant('B', 'Two', ['arrivalday' => 'monday', 'arrivalflight' => '09:00']);
        $this->makeRegistrant('C', 'Three', ['arrivalday' => 'monday', 'arrivalflight' => '11:30']);
        // Not flying: no shuttle.
        $this->makeRegistrant('D', 'Driver', ['arrivalday' => 'monday']);

        $days = $this->planner()->arrivalRuns();

        $this->assertCount(1, $days);
        $this->assertSame(3, $days->first()['count']);
        $runs = $days->first()['runs'];
        $this->assertCount(2, $runs);
        $this->assertSame('09:00', $runs[0]['time']);
        $this->assertSame(['A One', 'B Two'], $this->names($runs[0]['passengers']));
        $this->assertSame('11:30', $runs[1]['time']);
        $this->assertSame(['C Three'], $this->names($runs[1]['passengers']));
    }

    #[TestDox('pickups respect the shuttle seat count')]
    public function test_pickups_respect_the_shuttle_seat_count(): void
    {
        $this->planner()->updateSettings(2, null, null);
        $this->makeRegistrant('A', 'One', ['arrivalday' => 'monday', 'arrivalflight' => '10:00']);
        $this->makeRegistrant('B', 'Two', ['arrivalday' => 'monday', 'arrivalflight' => '10:10']);
        $this->makeRegistrant('C', 'Three', ['arrivalday' => 'monday', 'arrivalflight' => '10:20']);

        $runs = $this->planner()->arrivalRuns()->first()['runs'];

        // All fit the wait window, but only two fit the shuttle.
        $this->assertCount(2, $runs);
        $this->assertSame('10:10', $runs[0]['time']);
        $this->assertSame('10:20', $runs[1]['time']);
    }

    #[TestDox('unreadable flight times are surfaced for hand scheduling')]
    public function test_unreadable_flight_times_are_surfaced_for_hand_scheduling(): void
    {
        $this->makeRegistrant('A', 'One', ['arrivalday' => 'monday', 'arrivalflight' => 'around lunch']);

        $day = $this->planner()->arrivalRuns()->first();

        $this->assertSame([], $day['runs']);
        $this->assertSame(1, $day['count']);
        $this->assertSame(['A One'], $this->names($day['unscheduled']));
    }

    #[TestDox('pickup days follow the arrival question option order')]
    public function test_pickup_days_follow_the_arrival_question_option_order(): void
    {
        $this->makeRegistrant('A', 'One', ['arrivalday' => 'tuesday', 'arrivalflight' => '10:00']);
        $this->makeRegistrant('B', 'Two', ['arrivalday' => 'monday', 'arrivalflight' => '10:00']);

        $this->assertSame(['monday', 'tuesday'], $this->planner()->arrivalRuns()->pluck('day')->all());
    }

    #[TestDox('returns run in the morning and at one pm')]
    public function test_returns_run_in_the_morning_and_at_one_pm(): void
    {
        // Travel 60: the 13:00 run reaches the airport at 14:00, serving
        // flights from 16:00 (check-in 2h). 15:59 must go in the morning; the
        // earliest morning flight (09:00) sets the departure: 9:00 - 2h - 1h.
        $this->makeRegistrant('A', 'Early', ['departureday' => 'sunday', 'departureflight' => '09:00']);
        $this->makeRegistrant('B', 'Tight', ['departureday' => 'sunday', 'departureflight' => '15:59']);
        $this->makeRegistrant('C', 'Late', ['departureday' => 'sunday', 'departureflight' => '16:00']);

        $days = $this->planner()->returnRuns();
        $this->assertSame(3, $days->first()['count']);
        $runs = $days->first()['runs'];

        $this->assertCount(2, $runs);
        $this->assertSame('06:00', $runs[0]['time']);
        $this->assertSame(['A Early', 'B Tight'], $this->names($runs[0]['passengers']));
        $this->assertSame(ShuttlePlanner::AFTERNOON_RUN, $runs[1]['time']);
        $this->assertSame(['C Late'], $this->names($runs[1]['passengers']));
    }

    #[TestDox('a shared travel plans question feeds pickups and returns')]
    public function test_a_shared_travel_plans_question_feeds_pickups_and_returns(): void
    {
        // Both flight settings nominate the same free-text question, the way
        // this conference's travel2 works: the earlier date/time is the
        // arrival, the later the departure, and an answer without two
        // readable times lands on both unscheduled lists.
        Setting::put(ReportQuestions::FLIGHT_DEPARTURE_KEY, 'arrivalflight');

        $this->makeRegistrant('A', 'One', [
            'arrivalday' => 'monday',
            'arrivalflight' => "Arriving AA1234 on July 14 at 10:00 PM\nDeparting AA-4321 on 7/18 at 9:00",
        ]);
        $this->makeRegistrant('B', 'Vague', ['arrivalday' => 'monday', 'arrivalflight' => 'DL 5678, details TBD']);

        $pickup = $this->planner()->arrivalRuns()->first();
        $this->assertSame('22:00', $pickup['runs'][0]['time']);
        $this->assertSame(['A One'], $this->names($pickup['runs'][0]['passengers']));
        $this->assertSame(['B Vague'], $this->names($pickup['unscheduled']));

        // 9:00 take-off minus 2h check-in minus the 1h ride.
        $return = $this->planner()->returnRuns()->first();
        $this->assertSame('06:00', $return['runs'][0]['time']);
        $this->assertSame(['A One'], $this->names($return['runs'][0]['passengers']));
        $this->assertSame(['B Vague'], $this->names($return['unscheduled']));
    }

    #[TestDox('return runs take several shuttles when over capacity')]
    public function test_return_runs_take_several_shuttles_when_over_capacity(): void
    {
        $this->planner()->updateSettings(1, null, null);
        $this->makeRegistrant('A', 'One', ['departureday' => 'saturday', 'departureflight' => '18:00']);
        $this->makeRegistrant('B', 'Two', ['departureday' => 'saturday', 'departureflight' => '19:00']);

        $runs = $this->planner()->returnRuns()->first()['runs'];

        $this->assertCount(1, $runs);
        $this->assertSame(2, $runs[0]['shuttles']);
        $this->assertSame([], $this->names($runs[0]['overflow']));
    }

    #[TestDox('return passengers beyond the fleet size are left for hand scheduling')]
    public function test_return_passengers_beyond_the_fleet_size_are_left_for_hand_scheduling(): void
    {
        // One seat, one shuttle: the run can carry only one passenger — the
        // one with the tightest flight — and bumps the rest.
        $this->planner()->updateSettings(1, null, 1);
        $this->makeRegistrant('A', 'One', ['departureday' => 'saturday', 'departureflight' => '18:00']);
        $this->makeRegistrant('B', 'Two', ['departureday' => 'saturday', 'departureflight' => '19:00']);
        $this->makeRegistrant('C', 'Three', ['departureday' => 'saturday', 'departureflight' => '20:00']);

        $run = $this->planner()->returnRuns()->first()['runs'][0];

        $this->assertSame(1, $run['shuttles']);
        $this->assertSame(['A One'], $this->names($run['passengers']));
        $this->assertSame(['B Two', 'C Three'], $this->names($run['overflow']));
    }

    #[TestDox('the passenger list shows each rider\'s own flight time, not just the run\'s')]
    public function test_the_passenger_list_shows_each_riders_own_flight_time_not_just_the_runs(): void
    {
        // Both fit the same pickup run (dispatched at 09:00), but they landed
        // at different times.
        $this->makeRegistrant('A', 'One', ['arrivalday' => 'monday', 'arrivalflight' => '08:00']);
        $this->makeRegistrant('B', 'Two', ['arrivalday' => 'monday', 'arrivalflight' => '09:00']);

        $run = $this->planner()->arrivalRuns()->first()['runs'][0];

        $this->assertSame(
            'A One (08:00), B Two (09:00)',
            $this->planner()->passengerList($run['passengers'], 'arrival', app(GuestQuestions::class), app(ReportQuestions::class)),
        );
    }

    #[TestDox('the passenger list shows the full name, not the badge name')]
    public function test_the_passenger_list_shows_the_full_name_not_the_badge_name(): void
    {
        $this->makeRegistrant('A', 'One', ['arrivalday' => 'monday', 'arrivalflight' => '08:00', 'badgename' => 'Nickname']);

        $run = $this->planner()->arrivalRuns()->first()['runs'][0];

        $this->assertSame(
            'A One (08:00)',
            $this->planner()->passengerList($run['passengers'], 'arrival', app(GuestQuestions::class), app(ReportQuestions::class)),
        );
    }

    #[TestDox('the passenger list groups a guest with their flying attendee instead of wherever their tied flight time sorted them')]
    public function test_the_passenger_list_groups_a_guest_with_their_flying_attendee(): void
    {
        $hostA = $this->makeRegistrant('A', 'One', ['arrivalday' => 'monday', 'arrivalflight' => '10:00']);
        $hostB = $this->makeRegistrant('B', 'Two', ['arrivalday' => 'monday', 'arrivalflight' => '10:00']);
        $this->makeGuest($hostA, GuestType::Adult, ['guestname' => 'Guest Of A']);
        $this->makeGuest($hostB, GuestType::Adult, ['guestname' => 'Guest Of B']);

        $run = $this->planner()->arrivalRuns()->first()['runs'][0];

        $this->assertSame(
            'A One (10:00), Guest Of A (10:00), B Two (10:00), Guest Of B (10:00)',
            $this->planner()->passengerList($run['passengers'], 'arrival', app(GuestQuestions::class), app(ReportQuestions::class)),
        );
    }
}
