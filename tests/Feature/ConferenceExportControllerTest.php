<?php

namespace ConferenceTools\Registration\Tests\Feature;

use ConferenceTools\Registration\Services\RegistrationArchiver;
use ConferenceTools\Registration\Services\RegistrationExportBundle;
use ConferenceTools\Registration\Tests\Concerns\BuildsRegistrationData;
use ConferenceTools\Registration\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestDox;
use ZipArchive;

/**
 * The admin dashboard's "Export Current Registrations"/"Export Archive"
 * download routes: each streams a .ZIP of {@see RegistrationExportBundle}'s
 * five CSV sheets.
 */
#[TestDox('Conference Export Controller')]
class ConferenceExportControllerTest extends TestCase
{
    use BuildsRegistrationData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->allowRegistrationManagement();
    }

    /** Every sheet name the export bundle is expected to produce. */
    private function expectedEntries(): array
    {
        return ['answers.csv', 'groups.csv', 'payments.csv', 'room-assignments.csv', 'prayer-pals.csv'];
    }

    #[TestDox('exporting current registrations downloads a .ZIP containing every sheet')]
    public function test_exporting_current_registrations_downloads_a_zip_containing_every_sheet(): void
    {
        $this->makeGroupWithMembers();

        $response = $this->actingAs($this->makeUser())->get(route('registration.admin.conference.registrations.csv'));

        $response->assertOk();
        $this->assertStringContainsString('registrations-', $response->headers->get('content-disposition'));

        $zip = new ZipArchive;
        $zip->open($response->baseResponse->getFile()->getPathname());
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        $zip->close();

        $this->assertEqualsCanonicalizing($this->expectedEntries(), $names);
    }

    #[TestDox('exporting the archive downloads the same sheets after a rollover')]
    public function test_exporting_the_archive_downloads_the_same_sheets_after_a_rollover(): void
    {
        $this->makeGroupWithMembers();
        app(RegistrationArchiver::class)->archive();

        $response = $this->actingAs($this->makeUser())->get(route('registration.admin.conference.archive.csv'));

        $response->assertOk();
        $this->assertStringContainsString('archive-', $response->headers->get('content-disposition'));

        $zip = new ZipArchive;
        $zip->open($response->baseResponse->getFile()->getPathname());
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        $zip->close();

        $this->assertEqualsCanonicalizing($this->expectedEntries(), $names);
    }

    #[TestDox('exporting the archive 404s while nothing has been archived yet')]
    public function test_exporting_the_archive_404s_while_nothing_has_been_archived_yet(): void
    {
        $this->actingAs($this->makeUser())
            ->get(route('registration.admin.conference.archive.csv'))
            ->assertNotFound();
    }
}
