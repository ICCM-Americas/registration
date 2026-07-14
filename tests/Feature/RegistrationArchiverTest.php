<?php

namespace ConferenceTools\Registration\Tests\Feature;

use ConferenceTools\Registration\Enums\GuestType;
use ConferenceTools\Registration\Models\Answer;
use ConferenceTools\Registration\Models\ConferenceArchive;
use ConferenceTools\Registration\Models\Group;
use ConferenceTools\Registration\Models\Guest;
use ConferenceTools\Registration\Models\Payment;
use ConferenceTools\Registration\Models\PrayerPalsAssignment;
use ConferenceTools\Registration\Models\PrayerPalsGroup;
use ConferenceTools\Registration\Models\RoomAssignment;
use ConferenceTools\Registration\Services\ConferenceEdition;
use ConferenceTools\Registration\Services\RegistrationArchiver;
use ConferenceTools\Registration\Tests\Concerns\BuildsRegistrationData;
use ConferenceTools\Registration\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * The "start next conference" archive: snapshots the outgoing conference's
 * registration data into the single retained {@see ConferenceArchive} row,
 * then clears the live tables so registrants can sign up again.
 */
#[TestDox('Registration Archiver')]
class RegistrationArchiverTest extends TestCase
{
    use BuildsRegistrationData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->allowRegistrationManagement();
    }

    #[TestDox('archiving snapshots and clears every piece of registration data')]
    public function test_archiving_snapshots_and_clears_every_piece_of_registration_data(): void
    {
        $group = $this->makeGroupWithMembers();
        $admin = $group->admin();
        $member = $group->users()->where('is_group_admin', false)->first();

        RoomAssignment::factory()->create(['assignable_type' => $member->getMorphClass(), 'assignable_id' => $member->id]);
        $guest = Guest::factory()->create(['user_id' => $member->id, 'type' => GuestType::Adult]);
        Payment::factory()->paid()->create(['user_id' => $admin->id]);
        $prayerPalsGroup = PrayerPalsGroup::factory()->create();
        PrayerPalsAssignment::factory()->create([
            'prayer_pals_group_id' => $prayerPalsGroup->id,
            'assignable_type' => $admin->getMorphClass(),
            'assignable_id' => $admin->id,
        ]);

        app(ConferenceEdition::class)->update('ICCM Americas', '2026');

        $this->assertGreaterThan(0, Answer::query()->count());

        app(RegistrationArchiver::class)->archive();

        $this->assertSame(0, Answer::query()->count());
        $this->assertSame(0, Group::query()->count());
        $this->assertSame(0, Guest::query()->count());
        $this->assertSame(0, RoomAssignment::query()->count());
        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(0, PrayerPalsGroup::query()->count());
        $this->assertSame(0, PrayerPalsAssignment::query()->count());

        $archive = ConferenceArchive::sole();
        $this->assertSame('ICCM Americas', $archive->conference_name);
        $this->assertSame('2026', $archive->conference_year);
        $this->assertNotEmpty($archive->data['answers']);
        $this->assertNotEmpty($archive->data['groups']);
        $this->assertNotEmpty($archive->data['guests']);
        $this->assertNotEmpty($archive->data['payments']);
        $this->assertNotEmpty($archive->data['room_assignments']);
        $this->assertNotEmpty($archive->data['prayer_pals_groups']);
        $this->assertNotEmpty($archive->data['prayer_pals_assignments']);
        $this->assertNotEmpty($archive->data['registrants']);
    }

    #[TestDox('snapshot() reports the current data without clearing anything')]
    public function test_snapshot_reports_the_current_data_without_clearing_anything(): void
    {
        $group = $this->makeGroupWithMembers();

        $snapshot = app(RegistrationArchiver::class)->snapshot();

        $this->assertNotEmpty($snapshot['answers']);
        $this->assertNotEmpty($snapshot['registrants']);
        $this->assertSame(
            $group->users->pluck('id')->sort()->values()->all(),
            collect($snapshot['registrants'])->pluck('id')->sort()->values()->all(),
        );
        $this->assertGreaterThan(0, Answer::query()->count());
        $this->assertGreaterThan(0, Group::query()->count());
        $this->assertNull(ConferenceArchive::first());
    }

    #[TestDox('only one archive is ever kept, the previous one is discarded')]
    public function test_only_one_archive_is_ever_kept_the_previous_one_is_discarded(): void
    {
        ConferenceArchive::factory()->create(['conference_name' => 'Old Conference']);

        app(ConferenceEdition::class)->update('ICCM Americas', '2026');
        app(RegistrationArchiver::class)->archive();

        $archive = ConferenceArchive::sole();
        $this->assertSame('ICCM Americas', $archive->conference_name);
    }

    #[TestDox('starting the next conference from the dashboard archives and clears registration data')]
    public function test_starting_the_next_conference_from_the_dashboard_archives_and_clears_registration_data(): void
    {
        $this->makeGroupWithMembers();
        app(ConferenceEdition::class)->update('ICCM Americas', '2026');

        $this->actingAs($this->makeUser())
            ->post(route('registration.admin.conference.next'))
            ->assertRedirect(route('registration.admin.dashboard'));

        $this->assertSame(0, Answer::query()->count());
        $this->assertSame(0, Group::query()->count());
        $this->assertSame('ICCM Americas', ConferenceArchive::sole()->conference_name);
    }
}
