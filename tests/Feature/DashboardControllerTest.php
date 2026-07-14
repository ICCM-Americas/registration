<?php

namespace ConferenceTools\Registration\Tests\Feature;

use ConferenceTools\Registration\Enums\GuestType;
use ConferenceTools\Registration\Models\PrayerPalsAssignment;
use ConferenceTools\Registration\Models\PrayerPalsGroup;
use ConferenceTools\Registration\Models\Room;
use ConferenceTools\Registration\Models\RoomAssignment;
use ConferenceTools\Registration\Tests\Concerns\BuildsReportData;
use ConferenceTools\Registration\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestDox;

/** Feature tests for Dashboard Controller. */
#[TestDox('Dashboard Controller')]
class DashboardControllerTest extends TestCase
{
    use BuildsReportData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->allowRegistrationManagement();
        $this->seedReportQuestions();
    }

    #[TestDox('dashboard requires the gate')]
    public function test_dashboard_requires_the_gate(): void
    {
        $this->denyRegistrationManagement();

        $this->actingAs($this->makeUser())
            ->get(route('registration.admin.dashboard'))
            ->assertForbidden();
    }

    #[TestDox('the registrations card shows headcounts and links through to the consoles')]
    public function test_the_registrations_card_shows_headcounts_and_links_through_to_the_consoles(): void
    {
        $host = $this->makeRegistrant('Alan', 'Turing', ['arrivalday' => 'monday', 'arrivalflight' => '08:00']);
        $this->makeGuest($host, GuestType::Adult, ['guestprayerpals' => 'yes']);
        $this->seedRetiredReportSettings();
        $this->makeRegistrant('Grace', 'Hopper', ['specialneeds' => 'Wheelchair access']);

        $this->actingAs($this->makeUser())
            ->get(route('registration.admin.dashboard'))
            ->assertOk()
            ->assertSee(__('registration::admin.registrations_total_people'))
            ->assertSee(__('registration::admin.registrations_attendees'))
            ->assertSee(__('registration::admin.registrations_guests'))
            ->assertSee(__('registration::admin.registrations_special_needs'))
            ->assertSee(__('registration::admin.registrations_shuttle_runs'))
            ->assertSee(route('registration.admin.reports'))
            ->assertSee(route('registration.admin.logistics.shuttles'));
    }

    #[TestDox('the arrivals card lists each answered day, or says none yet')]
    public function test_the_arrivals_card_lists_each_answered_day_or_says_none_yet(): void
    {
        $this->actingAs($this->makeUser())
            ->get(route('registration.admin.dashboard'))
            ->assertOk()
            ->assertSee(__('registration::admin.arrivals_title'))
            ->assertSee(__('registration::admin.arrivals_none'));

        $this->makeRegistrant('Alan', 'Turing', ['arrivalday' => 'monday']);

        $this->actingAs($this->makeUser())
            ->get(route('registration.admin.dashboard'))
            ->assertOk()
            ->assertSee('Monday')
            ->assertDontSee(__('registration::admin.arrivals_none'));
    }

    #[TestDox('a red warning names how many still need a room, and links to the console')]
    public function test_a_red_warning_names_how_many_still_need_a_room_and_links_to_the_console(): void
    {
        $this->makeRegistrant('Alan', 'Turing');
        $this->makeRegistrant('Grace', 'Hopper');

        $this->actingAs($this->makeUser())
            ->get(route('registration.admin.dashboard'))
            ->assertOk()
            ->assertSee(trans_choice('registration::admin.room_assignments_missing', 2, ['count' => 2]))
            ->assertSee(route('registration.admin.rooms.assignments'));
    }

    #[TestDox('a red warning names how many still need a Prayer Pals group, and links to the console')]
    public function test_a_red_warning_names_how_many_still_need_a_prayer_pals_group_and_links_to_the_console(): void
    {
        $this->makeRegistrant('Alan', 'Turing');

        $this->actingAs($this->makeUser())
            ->get(route('registration.admin.dashboard'))
            ->assertOk()
            ->assertSee(trans_choice('registration::admin.prayer_pals_missing', 1, ['count' => 1]))
            ->assertSee(route('registration.admin.logistics.prayer_pals'));
    }

    #[TestDox('no warning shows once everyone is placed, or while no one is eligible yet')]
    public function test_no_warning_shows_once_everyone_is_placed_or_while_no_one_is_eligible_yet(): void
    {
        // No registrants at all: nothing to warn about.
        $this->actingAs($this->makeUser())
            ->get(route('registration.admin.dashboard'))
            ->assertOk()
            ->assertDontSee(__('registration::admin.assignments_title'))
            ->assertDontSee(__('registration::admin.prayer_pals_title'));

        $user = $this->makeRegistrant('Alan', 'Turing');
        $room = Room::factory()->create();
        RoomAssignment::create(['room_id' => $room->id, 'assignable_type' => $user->getMorphClass(), 'assignable_id' => $user->getKey()]);

        $group = PrayerPalsGroup::factory()->create();
        PrayerPalsAssignment::create(['prayer_pals_group_id' => $group->id, 'assignable_type' => $user->getMorphClass(), 'assignable_id' => $user->getKey()]);

        $this->actingAs($this->makeUser())
            ->get(route('registration.admin.dashboard'))
            ->assertOk()
            ->assertDontSee(__('registration::admin.assignments_title'))
            ->assertDontSee(__('registration::admin.prayer_pals_title'));
    }
}
