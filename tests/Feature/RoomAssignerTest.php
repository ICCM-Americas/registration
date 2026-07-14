<?php

namespace ConferenceTools\Registration\Tests\Feature;

use ConferenceTools\Registration\Enums\GuestType;
use ConferenceTools\Registration\Enums\RoomDesignation;
use ConferenceTools\Registration\Models\Room;
use ConferenceTools\Registration\Models\RoomAssignment;
use ConferenceTools\Registration\Services\RoomAssigner;
use ConferenceTools\Registration\Tests\Concerns\BuildsReportData;
use ConferenceTools\Registration\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestDox;

/** Feature tests for Room Assigner. */
#[TestDox('Room Assigner')]
class RoomAssignerTest extends TestCase
{
    use BuildsReportData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReportQuestions();
    }

    /** A room fixture. */
    private function room(RoomDesignation $designation, string $name = '1', int $capacity = 2): Room
    {
        return Room::factory()->create([
            'wing' => 'A', 'floor' => $designation->value, 'name' => $name,
            'designation' => $designation, 'capacity' => $capacity,
        ]);
    }

    /** The room an occupant was assigned to, or null. */
    private function roomOf(Model $occupant): ?int
    {
        return RoomAssignment::where('assignable_type', $occupant->getMorphClass())
            ->where('assignable_id', $occupant->getKey())
            ->value('room_id');
    }

    #[TestDox('mutually named cross gender roommates share a couples room')]
    public function test_mutually_named_cross_gender_roommates_share_a_couples_room(): void
    {
        $room = $this->room(RoomDesignation::Couples);
        $adam = $this->makeRegistrant('Adam', 'Even', ['gender' => 'm', 'roommate' => 'Eve Even']);
        $eve = $this->makeRegistrant('Eve', 'Even', ['gender' => 'f', 'roommate' => 'Adam Even']);

        $result = app(RoomAssigner::class)->assign();

        $this->assertSame(2, $result['assigned']);
        $this->assertSame($room->id, $this->roomOf($adam));
        $this->assertSame($room->id, $this->roomOf($eve));
    }

    #[TestDox('a one way cross gender roommate answer still pairs the couple')]
    public function test_a_one_way_cross_gender_roommate_answer_still_pairs_the_couple(): void
    {
        $room = $this->room(RoomDesignation::Couples);
        $adam = $this->makeRegistrant('Adam', 'Even', ['gender' => 'm', 'roommate' => 'Eve Even']);
        $eve = $this->makeRegistrant('Eve', 'Even', ['gender' => 'f']);

        app(RoomAssigner::class)->assign();

        $this->assertSame($room->id, $this->roomOf($adam));
        $this->assertSame($room->id, $this->roomOf($eve));
    }

    #[TestDox('an unplaceable couple stays together and unassigned')]
    public function test_an_unplaceable_couple_stays_together_and_unassigned(): void
    {
        // No couples room at all — the pair must NOT fall back to the
        // single-gender wings.
        $this->room(RoomDesignation::Men, capacity: 4);
        $this->room(RoomDesignation::Women, name: '2', capacity: 4);
        $adam = $this->makeRegistrant('Adam', 'Even', ['gender' => 'm', 'roommate' => 'Eve Even']);
        $eve = $this->makeRegistrant('Eve', 'Even', ['gender' => 'f', 'roommate' => 'Adam Even']);

        $result = app(RoomAssigner::class)->assign();

        $this->assertSame(0, $result['assigned']);
        $this->assertEqualsCanonicalizing(
            [$adam->getKey(), $eve->getKey()],
            $result['unassigned']->map->getKey()->all(),
        );
    }

    #[TestDox('a same gender roommate pair goes to its gender room not the couples room')]
    public function test_a_same_gender_roommate_pair_goes_to_its_gender_room_not_the_couples_room(): void
    {
        // A Couples room is available, but a same-gender wish must not be
        // swept into it — it belongs to the per-gender pass.
        $couplesRoom = $this->room(RoomDesignation::Couples);
        $menRoom = $this->room(RoomDesignation::Men, name: '2');
        $alan = $this->makeRegistrant('Alan', 'Turing', ['gender' => 'm', 'roommate' => 'John von Neumann']);
        $john = $this->makeRegistrant('John', 'von Neumann', ['gender' => 'm', 'roommate' => 'Alan Turing']);

        app(RoomAssigner::class)->assign();

        $this->assertSame($menRoom->id, $this->roomOf($alan));
        $this->assertSame($menRoom->id, $this->roomOf($john));
        $this->assertNull(RoomAssignment::where('room_id', $couplesRoom->id)->first());
    }

    #[TestDox('singles go to their gender zone and unknowns stay unassigned')]
    public function test_singles_go_to_their_gender_zone_and_unknowns_stay_unassigned(): void
    {
        $menRoom = $this->room(RoomDesignation::Men);
        $womenRoom = $this->room(RoomDesignation::Women, name: '2');
        $man = $this->makeRegistrant('Alan', 'Turing', ['gender' => 'm']);
        $woman = $this->makeRegistrant('Grace', 'Hopper', ['gender' => 'f']);
        $unknown = $this->makeRegistrant('Kim', 'Unknown', ['gender' => 'other']);

        $result = app(RoomAssigner::class)->assign();

        $this->assertSame($menRoom->id, $this->roomOf($man));
        $this->assertSame($womenRoom->id, $this->roomOf($woman));
        $this->assertNull($this->roomOf($unknown));
        $this->assertSame([$unknown->getKey()], $result['unassigned']->map->getKey()->all());
    }

    #[TestDox('desired roommates of the same gender share a room')]
    public function test_desired_roommates_of_the_same_gender_share_a_room(): void
    {
        // Two rooms so the pairing (not just fill order) decides who shares.
        $this->room(RoomDesignation::Men, name: '1');
        $this->room(RoomDesignation::Men, name: '2');
        $alan = $this->makeRegistrant('Alan', 'Turing', ['gender' => 'm', 'roommate' => 'John von Neumann']);
        $this->makeRegistrant('Kurt', 'Godel', ['gender' => 'm']);
        $john = $this->makeRegistrant('John', 'von Neumann', ['gender' => 'm', 'roommate' => 'Alan Turing']);

        app(RoomAssigner::class)->assign();

        $this->assertNotNull($this->roomOf($alan));
        $this->assertSame($this->roomOf($alan), $this->roomOf($john));
    }

    #[TestDox('a guest can pair with their host registrant as a couple')]
    public function test_a_guest_can_pair_with_their_host_registrant_as_a_couple(): void
    {
        $room = $this->room(RoomDesignation::Couples);
        $host = $this->makeRegistrant('Adam', 'Even', ['gender' => 'm', 'roommate' => 'Eve Guest']);
        $guest = $this->makeGuest($host, GuestType::Adult, [
            'guestname' => 'Eve Guest', 'guestgender' => 'f', 'guestroommate' => 'Adam Even',
        ]);

        $result = app(RoomAssigner::class)->assign();

        $this->assertSame(2, $result['assigned']);
        $this->assertSame($room->id, $this->roomOf($host));
        $this->assertSame($room->id, $this->roomOf($guest));
    }

    #[TestDox('minor guests also join the automated pairing pool')]
    public function test_minor_guests_also_join_the_automated_pairing_pool(): void
    {
        $room = $this->room(RoomDesignation::Women, capacity: 2);
        $host = $this->makeRegistrant('Grace', 'Hopper', ['gender' => 'f', 'roommate' => 'Small Child']);
        $guest = $this->makeGuest($host, GuestType::Minor, [
            'guestname' => 'Small Child', 'guestgender' => 'f', 'guestroommate' => 'Grace Hopper',
        ]);

        app(RoomAssigner::class)->assign();

        $this->assertSame($room->id, $this->roomOf($host));
        $this->assertSame($room->id, $this->roomOf($guest));
    }

    #[TestDox('a minor is not placed alone into a room with an unrelated adult')]
    public function test_a_minor_is_not_placed_alone_into_a_room_with_an_unrelated_adult(): void
    {
        $room = $this->room(RoomDesignation::Men, capacity: 2);
        $unrelatedAdult = $this->makeRegistrant('Alan', 'Turing', ['gender' => 'm']);
        $otherHost = $this->makeRegistrant('Jewell', 'Stracke', ['gender' => 'f']);
        $minor = $this->makeGuest($otherHost, GuestType::Minor, ['guestname' => 'Jerad Stracke', 'guestgender' => 'm']);

        $result = app(RoomAssigner::class)->assign();

        $this->assertSame($room->id, $this->roomOf($unrelatedAdult));
        $this->assertNull($this->roomOf($minor));
        $this->assertTrue($result['unassigned']->contains(fn (Model $o): bool => $o->is($minor)));
    }

    #[TestDox('a minor and an unrelated adult who name each other as roommates are not paired together')]
    public function test_a_minor_and_an_unrelated_adult_who_name_each_other_are_not_paired_together(): void
    {
        $this->room(RoomDesignation::Men, capacity: 4);
        $host = $this->makeRegistrant('Jewell', 'Stracke', ['gender' => 'f']);
        $unrelatedAdult = $this->makeRegistrant('Alan', 'Turing', ['gender' => 'm', 'roommate' => 'Jerad Stracke']);
        $minor = $this->makeGuest($host, GuestType::Minor, ['guestname' => 'Jerad Stracke', 'guestgender' => 'm', 'guestroommate' => 'Alan Turing']);

        app(RoomAssigner::class)->assign();

        // The adult still gets housed on their own; the minor is left for
        // the admin to place by hand rather than rooming with a stranger.
        $this->assertNotNull($this->roomOf($unrelatedAdult));
        $this->assertNull($this->roomOf($minor));
    }

    #[TestDox('a minor placed on their own still lands in their own host\'s room, not a stranger\'s')]
    public function test_a_minor_placed_on_their_own_still_lands_in_their_own_hosts_room(): void
    {
        $room = $this->room(RoomDesignation::Men, capacity: 2);
        $host = $this->makeRegistrant('Alan', 'Turing', ['gender' => 'm']);
        RoomAssignment::create(['room_id' => $room->id, 'assignable_type' => $host->getMorphClass(), 'assignable_id' => $host->getKey()]);
        $minor = $this->makeGuest($host, GuestType::Minor, ['guestname' => 'Tiny Turing', 'guestgender' => 'm']);

        app(RoomAssigner::class)->assign();

        $this->assertSame($room->id, $this->roomOf($minor));
    }

    #[TestDox('existing assignments are left alone and capacity is respected')]
    public function test_existing_assignments_are_left_alone_and_capacity_is_respected(): void
    {
        $room = $this->room(RoomDesignation::Men, capacity: 2);
        $settled = $this->makeRegistrant('Sett', 'Led', ['gender' => 'm']);
        RoomAssignment::create(['room_id' => $room->id, 'assignable_type' => $settled->getMorphClass(), 'assignable_id' => $settled->getKey()]);

        $this->makeRegistrant('New', 'Comer', ['gender' => 'm']);
        $this->makeRegistrant('Over', 'Flow', ['gender' => 'm']);

        $result = app(RoomAssigner::class)->assign();

        // One vacancy existed: one newcomer housed, one left over, and the
        // settled occupant untouched.
        $this->assertSame(1, $result['assigned']);
        $this->assertSame(1, $result['unassigned']->count());
        $this->assertSame($room->id, $this->roomOf($settled));
        $this->assertSame(2, RoomAssignment::where('room_id', $room->id)->count());
    }
}
