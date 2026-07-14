<?php

namespace ConferenceTools\Registration\Tests\Feature;

use ConferenceTools\Registration\Enums\GuestType;
use ConferenceTools\Registration\Enums\RoomDesignation;
use ConferenceTools\Registration\Models\Guest;
use ConferenceTools\Registration\Models\Room;
use ConferenceTools\Registration\Models\RoomAssignment;
use ConferenceTools\Registration\Services\GuestQuestions;
use ConferenceTools\Registration\Tests\Concerns\BuildsReportData;
use ConferenceTools\Registration\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestDox;

/** Feature tests for Room Assignment Controller. */
#[TestDox('Room Assignment Controller')]
class RoomAssignmentControllerTest extends TestCase
{
    use BuildsReportData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->allowRegistrationManagement();
        $this->seedReportQuestions();
    }

    #[TestDox('assignments page requires the gate')]
    public function test_assignments_page_requires_the_gate(): void
    {
        $this->denyRegistrationManagement();

        $this->actingAs($this->makeUser())
            ->get(route('registration.admin.rooms.assignments'))
            ->assertForbidden();
    }

    #[TestDox('index shows occupants and the unassigned')]
    public function test_index_shows_occupants_and_the_unassigned(): void
    {
        $room = Room::factory()->create(['wing' => 'A', 'floor' => '1', 'name' => '101']);
        $housed = $this->makeRegistrant('Alan', 'Turing');
        $homeless = $this->makeRegistrant('Grace', 'Hopper', ['roommate' => 'Ada Lovelace']);
        $guest = $this->makeGuest($homeless, GuestType::Adult, ['guestname' => 'Guest Occupant']);
        RoomAssignment::create(['room_id' => $room->id, 'assignable_type' => $housed->getMorphClass(), 'assignable_id' => $housed->getKey()]);

        $this->actingAs($this->makeUser())
            ->get(route('registration.admin.rooms.assignments'))
            ->assertOk()
            ->assertSee('Alan Turing')
            ->assertSee('Grace Hopper')
            // The wish is surfaced so the admin can honor it by hand.
            ->assertSee('Ada Lovelace')
            // A non-attending guest appears in the pool alongside registrants.
            ->assertSee('Guest Occupant');
    }

    #[TestDox('index shows how many rooms are occupied and how many are empty')]
    public function test_index_shows_how_many_rooms_are_occupied_and_how_many_are_empty(): void
    {
        $occupiedA = Room::factory()->create(['name' => '101']);
        $occupiedB = Room::factory()->create(['name' => '102']);
        Room::factory()->create(['name' => '103']);
        $turing = $this->makeRegistrant('Alan', 'Turing');
        $hopper = $this->makeRegistrant('Grace', 'Hopper');
        RoomAssignment::create(['room_id' => $occupiedA->id, 'assignable_type' => $turing->getMorphClass(), 'assignable_id' => $turing->getKey()]);
        RoomAssignment::create(['room_id' => $occupiedB->id, 'assignable_type' => $hopper->getMorphClass(), 'assignable_id' => $hopper->getKey()]);

        $content = $this->actingAs($this->makeUser())
            ->get(route('registration.admin.rooms.assignments'))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/'.preg_quote(__('registration::admin.assignments_occupied_label'), '/').'\s*<\/span>\s*<strong>2<\/strong>/',
            $content,
        );
        $this->assertMatchesRegularExpression(
            '/'.preg_quote(__('registration::admin.assignments_empty_label'), '/').'\s*<\/span>\s*<strong>1<\/strong>/',
            $content,
        );
    }

    #[TestDox('index shows the full name, not the badge name')]
    public function test_index_shows_the_full_name_not_the_badge_name(): void
    {
        $room = Room::factory()->create();
        $this->makeRegistrant('Alan', 'Turing', ['badgename' => 'Prof. T']);
        $host = $this->makeRegistrant('Grace', 'Hopper', ['badgename' => 'Amazing Grace']);
        app(GuestQuestions::class)->update([GuestQuestions::BADGE_NAME_KEY => 'guestbadgename']);
        $guest = $this->makeGuest($host, GuestType::Adult, ['guestname' => 'Formal Guest', 'guestbadgename' => 'Nickname']);
        RoomAssignment::create(['room_id' => $room->id, 'assignable_type' => $guest->getMorphClass(), 'assignable_id' => $guest->getKey()]);

        $this->actingAs($this->makeUser())
            ->get(route('registration.admin.rooms.assignments'))
            ->assertOk()
            // Alan (unassigned pool) and the guest (placed in a room) each
            // exercise a different code path that builds the occupant name.
            ->assertSee('Alan Turing')
            ->assertDontSee('Prof. T')
            ->assertSee('Grace Hopper')
            ->assertDontSee('Amazing Grace')
            ->assertSee('Formal Guest')
            ->assertDontSee('Nickname');
    }

    #[TestDox('a room card shows the roommate wish and, for a guest, their host attendee')]
    public function test_a_room_card_shows_the_roommate_wish_and_for_a_guest_their_host_attendee(): void
    {
        $room = Room::factory()->create();
        $host = $this->makeRegistrant('Alan', 'Turing', ['roommate' => 'Grace Hopper']);
        RoomAssignment::create(['room_id' => $room->id, 'assignable_type' => $host->getMorphClass(), 'assignable_id' => $host->getKey()]);
        $guest = $this->makeGuest($host, GuestType::Adult, ['guestname' => 'Guest Occupant']);
        RoomAssignment::create(['room_id' => $room->id, 'assignable_type' => $guest->getMorphClass(), 'assignable_id' => $guest->getKey()]);

        $this->actingAs($this->makeUser())
            ->get(route('registration.admin.rooms.assignments'))
            ->assertOk()
            ->assertSee('Roommate: Grace Hopper')
            ->assertSee('Attendee: Alan Turing');
    }

    #[TestDox('assign places and moves a registrant')]
    public function test_assign_places_and_moves_a_registrant(): void
    {
        $roomA = Room::factory()->create(['name' => '101']);
        $roomB = Room::factory()->create(['name' => '102']);
        $user = $this->makeRegistrant('Alan', 'Turing');

        $this->actingAs($this->makeUser())
            ->post(route('registration.admin.rooms.assignments.assign'), [
                'occupant_type' => 'user', 'occupant_id' => $user->getKey(), 'room_id' => $roomA->id,
            ])->assertRedirect(route('registration.admin.rooms.assignments'));

        $this->assertSame($roomA->id, RoomAssignment::where('assignable_type', $user->getMorphClass())->where('assignable_id', $user->getKey())->value('room_id'));

        // Assigning again moves rather than duplicating.
        $this->actingAs($this->makeUser())
            ->post(route('registration.admin.rooms.assignments.assign'), [
                'occupant_type' => 'user', 'occupant_id' => $user->getKey(), 'room_id' => $roomB->id,
            ]);

        $this->assertSame(1, RoomAssignment::count());
        $this->assertSame($roomB->id, RoomAssignment::where('assignable_type', $user->getMorphClass())->where('assignable_id', $user->getKey())->value('room_id'));
    }

    #[TestDox('assign places a non attending guest')]
    public function test_assign_places_a_non_attending_guest(): void
    {
        $room = Room::factory()->create(['name' => '101']);
        $host = $this->makeRegistrant('Alan', 'Turing');
        $guest = $this->makeGuest($host, GuestType::Minor, ['guestname' => 'Minor Guest']);

        $this->actingAs($this->makeUser())
            ->post(route('registration.admin.rooms.assignments.assign'), [
                'occupant_type' => 'guest', 'occupant_id' => $guest->getKey(), 'room_id' => $room->id,
            ])->assertRedirect(route('registration.admin.rooms.assignments'));

        $this->assertSame($room->id, RoomAssignment::where('assignable_type', Guest::class)->where('assignable_id', $guest->getKey())->value('room_id'));
    }

    #[TestDox('assign rejects non registrants and unknown rooms')]
    public function test_assign_rejects_non_registrants_and_unknown_rooms(): void
    {
        $room = Room::factory()->create();
        $notRegistered = $this->makeUser();

        $this->actingAs($this->makeUser())
            ->post(route('registration.admin.rooms.assignments.assign'), [
                'occupant_type' => 'user', 'occupant_id' => $notRegistered->id, 'room_id' => $room->id,
            ])->assertSessionHasErrors('occupant_id');

        $registrant = $this->makeRegistrant('Alan', 'Turing');

        $this->actingAs($this->makeUser())
            ->post(route('registration.admin.rooms.assignments.assign'), [
                'occupant_type' => 'user', 'occupant_id' => $registrant->getKey(), 'room_id' => $room->id + 999,
            ])->assertSessionHasErrors('room_id');
    }

    #[TestDox('assign rejects an unknown guest')]
    public function test_assign_rejects_an_unknown_guest(): void
    {
        $room = Room::factory()->create();

        $this->actingAs($this->makeUser())
            ->post(route('registration.admin.rooms.assignments.assign'), [
                'occupant_type' => 'guest', 'occupant_id' => 999999, 'room_id' => $room->id,
            ])->assertSessionHasErrors('occupant_id');
    }

    #[TestDox('unassign removes the placement')]
    public function test_unassign_removes_the_placement(): void
    {
        $room = Room::factory()->create();
        $user = $this->makeRegistrant('Alan', 'Turing');
        $assignment = RoomAssignment::create(['room_id' => $room->id, 'assignable_type' => $user->getMorphClass(), 'assignable_id' => $user->getKey()]);

        $this->actingAs($this->makeUser())
            ->delete(route('registration.admin.rooms.assignments.unassign', $assignment))
            ->assertRedirect(route('registration.admin.rooms.assignments'));

        $this->assertSame(0, RoomAssignment::count());
    }

    #[TestDox('unassign responds with no content for the AJAX remove button')]
    public function test_unassign_responds_with_no_content_for_the_ajax_remove_button(): void
    {
        $room = Room::factory()->create();
        $user = $this->makeRegistrant('Alan', 'Turing');
        $assignment = RoomAssignment::create(['room_id' => $room->id, 'assignable_type' => $user->getMorphClass(), 'assignable_id' => $user->getKey()]);

        $this->actingAs($this->makeUser())
            ->deleteJson(route('registration.admin.rooms.assignments.unassign', $assignment))
            ->assertNoContent();

        $this->assertSame(0, RoomAssignment::count());
    }

    #[TestDox('unassign all removes every placement')]
    public function test_unassign_all_removes_every_placement(): void
    {
        $roomA = Room::factory()->create();
        $roomB = Room::factory()->create();
        $turing = $this->makeRegistrant('Alan', 'Turing');
        $hopper = $this->makeRegistrant('Grace', 'Hopper');
        RoomAssignment::create(['room_id' => $roomA->id, 'assignable_type' => $turing->getMorphClass(), 'assignable_id' => $turing->getKey()]);
        RoomAssignment::create(['room_id' => $roomB->id, 'assignable_type' => $hopper->getMorphClass(), 'assignable_id' => $hopper->getKey()]);

        $this->actingAs($this->makeUser())
            ->delete(route('registration.admin.rooms.assignments.unassign_all'))
            ->assertRedirect(route('registration.admin.rooms.assignments'));

        $this->assertSame(0, RoomAssignment::count());
    }

    #[TestDox('the first pass assigns and reports its result')]
    public function test_the_first_pass_assigns_and_reports_its_result(): void
    {
        Room::factory()->create(['designation' => RoomDesignation::Men, 'capacity' => 2]);
        $this->makeRegistrant('Alan', 'Turing', ['gender' => 'm']);
        $this->makeRegistrant('Grace', 'Hopper', ['gender' => 'f']);

        $this->actingAs($this->makeUser())
            ->post(route('registration.admin.rooms.assignments.first_pass'))
            ->assertRedirect(route('registration.admin.rooms.assignments'))
            ->assertSessionHas('assignments_status', __('registration::admin.assignments_first_pass_done', [
                'assigned' => 1, 'unassigned' => 1,
            ]));

        $this->assertSame(1, RoomAssignment::count());
    }
}
