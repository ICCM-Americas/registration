<?php

namespace ConferenceTools\Registration\Tests\Feature;

use ConferenceTools\Registration\Enums\Gender;
use ConferenceTools\Registration\Enums\QuestionScope;
use ConferenceTools\Registration\Models\ConferenceArchive;
use ConferenceTools\Registration\Models\Group;
use ConferenceTools\Registration\Models\Payment;
use ConferenceTools\Registration\Models\PrayerPalsAssignment;
use ConferenceTools\Registration\Models\PrayerPalsGroup;
use ConferenceTools\Registration\Models\Question;
use ConferenceTools\Registration\Models\Room;
use ConferenceTools\Registration\Models\RoomAssignment;
use ConferenceTools\Registration\Models\Section;
use ConferenceTools\Registration\Models\Setting;
use ConferenceTools\Registration\Services\RegistrationArchiver;
use ConferenceTools\Registration\Services\RegistrationExportBundle;
use ConferenceTools\Registration\Services\RegistrationSnapshotIdentities;
use ConferenceTools\Registration\Services\ReportQuestions;
use ConferenceTools\Registration\Tests\Concerns\BuildsRegistrationData;
use ConferenceTools\Registration\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Builds the export bundle's non-answer sheets — groups, payments, room
 * assignments, Prayer Pals — from a snapshot, resolving each row's person
 * through {@see RegistrationSnapshotIdentities}
 * rather than a raw id.
 */
#[TestDox('Registration Export Bundle')]
class RegistrationExportBundleTest extends TestCase
{
    use BuildsRegistrationData, RefreshDatabase;

    /** A registrant with a badge name, a payment, a room assignment, and a Prayer Pals assignment. */
    private function seedFixture(): array
    {
        Setting::put(ReportQuestions::BADGE_NAME_KEY, 'badgename');
        $participant = Section::factory()->create(['scope' => QuestionScope::Participant, 'position' => 0]);
        Question::factory()->create(['section_id' => $participant->id, 'key' => 'badgename', 'label' => 'Badge Name', 'position' => 0]);

        $group = Group::factory()->create(['name' => 'The Engines', 'is_group' => true, 'checked_out' => true]);
        $host = $this->makeUser(['group_id' => $group->id, 'is_group_admin' => true]);
        $this->storeAnswers($host, QuestionScope::Participant, ['badgename' => 'Ada Lovelace']);

        Payment::factory()->paid()->create(['user_id' => $host->id, 'amount' => 42.50, 'notes' => 'Scholarship']);

        $room = Room::factory()->create(['wing' => 'A', 'floor' => '1', 'name' => '101']);
        RoomAssignment::factory()->create(['room_id' => $room->id, 'assignable_type' => get_class($host), 'assignable_id' => $host->id]);

        $prayerGroup = PrayerPalsGroup::factory()->create(['sex' => Gender::Male, 'position' => 1]);
        PrayerPalsAssignment::factory()->create(['prayer_pals_group_id' => $prayerGroup->id, 'assignable_type' => get_class($host), 'assignable_id' => $host->id]);

        return [$group, $host];
    }

    /** @return array{0: array, 1: Collection} */
    private function sheet(string $name, array $snapshot): array
    {
        return app(RegistrationExportBundle::class)->sheets($snapshot)[$name];
    }

    #[TestDox('the groups sheet lists each group with its flags and its members names')]
    public function test_the_groups_sheet_lists_each_group_with_its_flags_and_members_names(): void
    {
        $this->seedFixture();

        [$headers, $rows] = $this->sheet('groups', app(RegistrationArchiver::class)->snapshot());

        $this->assertSame([
            __('registration::admin.export_group_name'),
            __('registration::admin.export_is_group'),
            __('registration::admin.export_checked_out'),
            __('registration::admin.export_members'),
        ], $headers);
        $this->assertSame([['The Engines', 'Yes', 'Yes', 'Ada Lovelace']], $rows->all());
    }

    #[TestDox('the payments sheet lists each payment against its registrants identity')]
    public function test_the_payments_sheet_lists_each_payment_against_its_registrants_identity(): void
    {
        [, $host] = $this->seedFixture();

        [, $rows] = $this->sheet('payments', app(RegistrationArchiver::class)->snapshot());

        $this->assertSame([['Ada Lovelace', $host->email, 'Yes', '42.50', 'Scholarship']], $rows->all());
    }

    #[TestDox('the room assignments sheet names the room and its occupants identity')]
    public function test_the_room_assignments_sheet_names_the_room_and_its_occupants_identity(): void
    {
        $this->seedFixture();

        [, $rows] = $this->sheet('room-assignments', app(RegistrationArchiver::class)->snapshot());

        $this->assertSame([['A / 1 / 101', 'Ada Lovelace', __('registration::admin.report_entry_attendee')]], $rows->all());
    }

    #[TestDox('the Prayer Pals sheet names the group, sex, and assignees identity')]
    public function test_the_prayer_pals_sheet_names_the_group_sex_and_assignees_identity(): void
    {
        $this->seedFixture();

        [, $rows] = $this->sheet('prayer-pals', app(RegistrationArchiver::class)->snapshot());

        $this->assertSame([['1', 'Male', 'Ada Lovelace', __('registration::admin.report_entry_attendee')]], $rows->all());
    }

    #[TestDox('an archived snapshot produces the same sheets the live snapshot did before archiving')]
    public function test_an_archived_snapshot_produces_the_same_sheets_the_live_snapshot_did_before_archiving(): void
    {
        $this->seedFixture();
        $bundle = app(RegistrationExportBundle::class);
        $archiver = app(RegistrationArchiver::class);

        $liveSheets = $bundle->sheets($archiver->snapshot());
        $liveRows = array_map(fn (array $sheet) => $sheet[1]->all(), $liveSheets);

        $archiver->archive();

        $archivedSheets = $bundle->sheets(ConferenceArchive::sole()->data);
        $archivedRows = array_map(fn (array $sheet) => $sheet[1]->all(), $archivedSheets);

        $this->assertSame($liveRows, $archivedRows);
    }
}
