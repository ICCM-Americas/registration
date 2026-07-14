<?php

namespace ConferenceTools\Registration\Tests\Feature;

use ConferenceTools\Registration\Enums\RoomDesignation;
use ConferenceTools\Registration\Models\Room;
use ConferenceTools\Registration\Models\RoomAssignment;
use ConferenceTools\Registration\Tests\Concerns\BuildsRegistrationData;
use ConferenceTools\Registration\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;

/** Feature tests for Room Controller. */
#[TestDox('Room Controller')]
class RoomControllerTest extends TestCase
{
    use BuildsRegistrationData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->allowRegistrationManagement();
    }

    #[TestDox('rooms page requires the gate')]
    public function test_rooms_page_requires_the_gate(): void
    {
        $this->denyRegistrationManagement();

        $this->actingAs($this->makeUser())
            ->get(route('registration.admin.rooms'))
            ->assertForbidden();
    }

    #[TestDox('index lists the zones and their rooms')]
    public function test_index_lists_the_zones_and_their_rooms(): void
    {
        Room::factory()->create(['wing' => 'B', 'floor' => '2', 'name' => '201']);

        $this->actingAs($this->makeUser())
            ->get(route('registration.admin.rooms'))
            ->assertOk()
            ->assertSee(__('registration::admin.rooms_zone', ['wing' => 'B', 'floor' => '2']))
            ->assertSee('201');
    }

    #[TestDox('store creates the listed rooms and designates the whole zone')]
    public function test_store_creates_the_listed_rooms_and_designates_the_whole_zone(): void
    {
        // The zone already has a men-only room; adding couples rooms restates
        // the designation for all of it, and an existing name is not duplicated.
        Room::factory()->create(['wing' => 'B', 'floor' => '2', 'name' => '201', 'designation' => RoomDesignation::Men]);

        $this->actingAs($this->makeUser())
            ->post(route('registration.admin.rooms.store'), [
                'wing' => 'B', 'floor' => '2', 'names' => '201, 202 203,,',
                'designation' => RoomDesignation::Couples->value, 'capacity' => 3,
            ])->assertRedirect(route('registration.admin.rooms'));

        $zone = Room::inZone('B', '2')->orderBy('name')->get();
        $this->assertSame(['201', '202', '203'], $zone->pluck('name')->all());
        $this->assertTrue($zone->every(fn (Room $room): bool => $room->designation === RoomDesignation::Couples));
        // Only the new rooms take the submitted capacity.
        $this->assertSame([2, 3, 3], $zone->pluck('capacity')->all());
    }

    /** Cases for the range-expansion test: input names field vs. the resulting room names, in name order. */
    public static function roomNameProvider(): array
    {
        return [
            'inclusive numeric range' => ['201-203', ['201', '202', '203']],
            'range mixed with plain names' => ['B1, 201-203, C2', ['201', '202', '203', 'B1', 'C2']],
            'zero-padded range keeps its width' => ['008-010', ['008', '009', '010']],
            'reversed range is kept as a literal name' => ['10-5', ['10-5']],
            'oversized range is kept as a literal name' => ['1-600', ['1-600']],
        ];
    }

    #[TestDox('store expands inclusive numeric ranges into individual room names')]
    #[DataProvider('roomNameProvider')]
    public function test_store_expands_inclusive_numeric_ranges_into_individual_room_names(string $names, array $expected): void
    {
        $this->actingAs($this->makeUser())
            ->post(route('registration.admin.rooms.store'), [
                'wing' => 'B', 'floor' => '2', 'names' => $names,
                'designation' => RoomDesignation::Couples->value, 'capacity' => 2,
            ])->assertRedirect(route('registration.admin.rooms'));

        $this->assertSame($expected, Room::inZone('B', '2')->orderBy('name')->pluck('name')->all());
    }

    #[TestDox('store rejects an unknown designation')]
    public function test_store_rejects_an_unknown_designation(): void
    {
        $this->actingAs($this->makeUser())
            ->post(route('registration.admin.rooms.store'), [
                'wing' => 'B', 'floor' => '2', 'names' => '201',
                'designation' => 'anyone', 'capacity' => 2,
            ])->assertSessionHasErrors('designation');
    }

    #[TestDox('update zone redesignates every room in the combo only')]
    public function test_update_zone_redesignates_every_room_in_the_combo_only(): void
    {
        Room::factory()->create(['wing' => 'B', 'floor' => '2', 'name' => '201', 'designation' => RoomDesignation::Men]);
        Room::factory()->create(['wing' => 'B', 'floor' => '2', 'name' => '202', 'designation' => RoomDesignation::Men]);
        $other = Room::factory()->create(['wing' => 'B', 'floor' => '3', 'name' => '301', 'designation' => RoomDesignation::Men]);

        $this->actingAs($this->makeUser())
            ->put(route('registration.admin.rooms.zone'), [
                'wing' => 'B', 'floor' => '2', 'designation' => RoomDesignation::Women->value,
            ])->assertRedirect(route('registration.admin.rooms'));

        $this->assertTrue(Room::inZone('B', '2')->get()->every(fn (Room $r): bool => $r->designation === RoomDesignation::Women));
        $this->assertSame(RoomDesignation::Men, $other->fresh()->designation);
    }

    #[TestDox('a room can be renamed and resized but not to a taken name')]
    public function test_a_room_can_be_renamed_and_resized_but_not_to_a_taken_name(): void
    {
        $room = Room::factory()->create(['wing' => 'B', 'floor' => '2', 'name' => '201']);
        Room::factory()->create(['wing' => 'B', 'floor' => '2', 'name' => '202']);

        $this->actingAs($this->makeUser())
            ->put(route('registration.admin.rooms.update', $room), ['name' => '202', 'capacity' => 2])
            ->assertSessionHasErrors('name');

        $this->actingAs($this->makeUser())
            ->put(route('registration.admin.rooms.update', $room), ['name' => '210', 'capacity' => 4])
            ->assertRedirect(route('registration.admin.rooms'));

        $this->assertSame('210', $room->fresh()->name);
        $this->assertSame(4, $room->fresh()->capacity);
    }

    #[TestDox('destroying a room or zone removes rooms and their assignments')]
    public function test_destroying_a_room_or_zone_removes_rooms_and_their_assignments(): void
    {
        $user = $this->makeUser();
        $room = Room::factory()->create(['wing' => 'B', 'floor' => '2', 'name' => '201']);
        Room::factory()->create(['wing' => 'B', 'floor' => '2', 'name' => '202']);
        RoomAssignment::create(['room_id' => $room->id, 'assignable_type' => $user->getMorphClass(), 'assignable_id' => $user->id]);

        $this->actingAs($this->makeUser())
            ->delete(route('registration.admin.rooms.destroy', $room))
            ->assertRedirect(route('registration.admin.rooms'));

        $this->assertNull(Room::find($room->id));
        $this->assertSame(0, RoomAssignment::count());

        $this->actingAs($this->makeUser())
            ->delete(route('registration.admin.rooms.zone.destroy'), ['wing' => 'B', 'floor' => '2'])
            ->assertRedirect(route('registration.admin.rooms'));

        $this->assertSame(0, Room::count());
    }
}
