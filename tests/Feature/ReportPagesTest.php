<?php

namespace ConferenceTools\Registration\Tests\Feature;

use ConferenceTools\Registration\Enums\GuestType;
use ConferenceTools\Registration\Services\ConferenceEdition;
use ConferenceTools\Registration\Services\GuestQuestions;
use ConferenceTools\Registration\Services\ShuttlePlanner;
use ConferenceTools\Registration\Tests\Concerns\BuildsReportData;
use ConferenceTools\Registration\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Feature tests for the console report pages that remain hard-coded (name
 * badges and the shuttle schedule) — the six former list reports are now
 * admin-defined (see ReportControllerTest and DefaultReportsSeederTest).
 */
#[TestDox('Report Pages')]
class ReportPagesTest extends TestCase
{
    use BuildsReportData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->allowRegistrationManagement();
        $this->seedReportQuestions();
    }

    #[DataProvider('reportRoutes')]
    #[TestDox('report pages require the gate')]
    public function test_report_pages_require_the_gate(string $route): void
    {
        $this->denyRegistrationManagement();

        $this->actingAs($this->makeUser())
            ->get(route($route))
            ->assertForbidden();
    }

    /** The report routes for the data provider. */
    public static function reportRoutes(): array
    {
        return [
            'badges' => ['registration.admin.logistics.badges'],
            'shuttles' => ['registration.admin.logistics.shuttles'],
        ];
    }

    /** The translated label every report's final Count row/cell carries. */
    private function reportCountLabel(): string
    {
        return __('registration::admin.report_count_label');
    }

    #[DataProvider('reportRoutes')]
    #[TestDox('every csv export ends with a blank row and a self counting formula')]
    public function test_every_csv_export_ends_with_a_blank_row_and_a_self_counting_formula(string $route): void
    {
        // The blank separator row plus a formula anchored to its own row
        // (=ROW()-3), not a fixed range — see Controller::appendCsvCount —
        // so it keeps counting correctly however many rows get inserted or
        // deleted above it once the file is opened in a spreadsheet.
        $content = $this->actingAs($this->makeUser())
            ->get(route($route.'.csv'))
            ->assertOk()
            ->streamedContent();

        $this->assertStringEndsWith("\n\n".$this->reportCountLabel().",=ROW()-3\n", $content);
    }

    #[DataProvider('reportRoutes')]
    #[TestDox('every report offers a pdf export button and a csv export')]
    public function test_every_report_offers_a_pdf_export_button_and_a_csv_export(string $route): void
    {
        // The PDF is generated client-side: the page carries the export
        // button, its own generator, and the shared jsPDF loader.
        $this->actingAs($this->makeUser())
            ->get(route($route))
            ->assertOk()
            ->assertSee(__('registration::admin.export_pdf'))
            ->assertSee('unpkg.com/jspdf', false);

        $this->assertFalse(Route::has($route.'.pdf'), 'The server-side PDF route is gone.');

        $this->actingAs($this->makeUser())
            ->get(route($route.'.csv'))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    }

    #[TestDox('pdf payloads use us letter paper for a us locale and a4 otherwise')]
    public function test_pdf_payloads_use_us_letter_paper_for_a_us_locale_and_a4_otherwise(): void
    {
        $usContent = $this->actingAs($this->makeUser())
            ->get(route('registration.admin.logistics.shuttles'))
            ->assertOk()
            ->getContent();
        $this->assertSame('letter', $this->pdfPayload($usContent)['paper']);

        app()->setLocale('en-GB');

        $gbContent = $this->actingAs($this->makeUser())
            ->get(route('registration.admin.logistics.shuttles'))
            ->assertOk()
            ->getContent();
        $this->assertSame('a4', $this->pdfPayload($gbContent)['paper']);
    }

    #[TestDox('badges size picker defaults to the locale appropriate badge size')]
    public function test_badges_size_picker_defaults_to_the_locale_appropriate_badge_size(): void
    {
        $usContent = $this->actingAs($this->makeUser())
            ->get(route('registration.admin.logistics.badges'))
            ->assertOk()
            ->getContent();
        $this->assertMatchesRegularExpression('/value="avery_5392"[^>]*checked/', $usContent);

        app()->setLocale('en-GB');

        $gbContent = $this->actingAs($this->makeUser())
            ->get(route('registration.admin.logistics.badges'))
            ->assertOk()
            ->getContent();
        $this->assertMatchesRegularExpression('/value="avery_l4728"[^>]*checked/', $gbContent);
    }

    #[TestDox('badges size picker reflects an explicit choice and falls back for an unknown one')]
    public function test_badges_size_picker_reflects_an_explicit_choice_and_falls_back_for_an_unknown_one(): void
    {
        $chosen = $this->actingAs($this->makeUser())
            ->get(route('registration.admin.logistics.badges', ['size' => 'avery_c32011']))
            ->assertOk()
            ->getContent();
        $this->assertMatchesRegularExpression('/value="avery_c32011"[^>]*checked/', $chosen);

        $fallback = $this->actingAs($this->makeUser())
            ->get(route('registration.admin.logistics.badges', ['size' => 'not-a-real-size']))
            ->assertOk()
            ->getContent();
        $this->assertMatchesRegularExpression('/value="avery_5392"[^>]*checked/', $fallback);
    }

    #[DataProvider('badgeSheetSizes')]
    #[TestDox('badges pdf payload follows the chosen sheet size')]
    public function test_badges_pdf_payload_follows_the_chosen_sheet_size(string $size, string $paper, array $card, array $grid, array $margins): void
    {
        $content = $this->actingAs($this->makeUser())
            ->get(route('registration.admin.logistics.badges', ['size' => $size]))
            ->assertOk()
            ->getContent();

        $payload = $this->pdfPayload($content, 'badges-pdf-data');

        $this->assertSame($paper, $payload['paper']);
        $this->assertEqualsWithDelta($card, $payload['card'], 0.001);
        $this->assertSame($grid, $payload['grid']);
        // The card grid is centered on the page exactly as Avery die-cuts the
        // stock (e.g. the 5371's own 0.75in/0.5in template margins).
        $this->assertEqualsWithDelta($margins, $payload['margins'], 0.001);
    }

    /** The badge sheet sizes for the data provider. */
    public static function badgeSheetSizes(): array
    {
        return [
            'Avery 5390/5392 badge, US Letter' => ['avery_5392', 'letter', [101.6, 76.2], [2, 3], [6.35, 25.4]],
            'Avery L4728 badge, A4' => ['avery_l4728', 'a4', [90.0, 60.0], [2, 4], [15.0, 28.5]],
            'Avery 5371/8371 business card, US Letter' => ['avery_5371', 'letter', [88.9, 50.8], [2, 5], [19.05, 12.7]],
            'Avery C32011 business card, A4' => ['avery_c32011', 'a4', [85.0, 54.0], [2, 5], [20.0, 13.5]],
        ];
    }

    /** The embedded client-side PDF payload (see the report views' generators). */
    private function pdfPayload(string $content, string $id = 'report-pdf-data'): array
    {
        preg_match('/<script type="application\/json" id="'.$id.'">(.*?)<\/script>/s', $content, $match);
        $this->assertNotEmpty($match, "Missing embedded PDF payload #{$id}");

        return json_decode($match[1], true, 512, JSON_THROW_ON_ERROR);
    }

    #[DataProvider('badgeSheetGrids')]
    #[TestDox('badges pdf payload carries every badge and the full avery grid')]
    public function test_badges_pdf_payload_carries_every_badge_and_the_full_avery_grid(string $size, int $perSheet): void
    {
        for ($i = 0; $i < $perSheet + 1; $i++) {
            $this->makeRegistrant('First'.$i, 'Last'.$i, ['badgename' => 'Badge '.$i]);
        }

        $content = $this->actingAs($this->makeUser())
            ->get(route('registration.admin.logistics.badges', ['size' => $size]))
            ->assertOk()
            ->getContent();

        $payload = $this->pdfPayload($content, 'badges-pdf-data');

        // The client-side generator chunks badges by the grid: a sheet-worth
        // plus one spills onto a second page. The payload must carry the full
        // grid and every badge for that math to come out right.
        [$columns, $rows] = $payload['grid'];
        $this->assertSame($perSheet, $columns * $rows);
        $this->assertCount($perSheet + 1, $payload['badges']);
        $this->assertSame('Badge 0', $payload['badges'][0]['name']);
    }

    /** The badge sheet grids for the data provider. */
    public static function badgeSheetGrids(): array
    {
        return [
            'Avery 5390/5392 badge, US Letter' => ['avery_5392', 6],
            'Avery L4728 badge, A4' => ['avery_l4728', 8],
            'Avery 5371/8371 business card, US Letter' => ['avery_5371', 10],
            'Avery C32011 business card, A4' => ['avery_c32011', 10],
        ];
    }

    #[TestDox('badges show the conference the badge name and the organization')]
    public function test_badges_show_the_conference_the_badge_name_and_the_organization(): void
    {
        app(ConferenceEdition::class)->update('ICCM Test', '2026');
        $this->makeRegistrant('Ada', 'Lovelace', ['badgename' => 'Ada L.']);

        $this->actingAs($this->makeUser())
            ->get(route('registration.admin.logistics.badges'))
            ->assertOk()
            ->assertSee('ICCM Test 2026')
            ->assertSee('Ada L.')
            ->assertSee('Analytical Engines');
    }

    #[TestDox('badges include eligible guests and respect the minors setting')]
    public function test_badges_include_eligible_guests_and_respect_the_minors_setting(): void
    {
        $host = $this->makeRegistrant('Host', 'Registrant');
        $this->makeGuest($host, GuestType::Adult, ['guestname' => 'Adult Guest']);
        $this->makeGuest($host, GuestType::Minor, ['guestname' => 'Minor Guest']);

        // Minors get no badge by default.
        $this->actingAs($this->makeUser())
            ->get(route('registration.admin.logistics.badges'))
            ->assertOk()
            ->assertSee('Adult Guest')
            ->assertDontSee('Minor Guest');

        app(GuestQuestions::class)->updateMinorsGetBadges(true);

        $this->actingAs($this->makeUser())
            ->get(route('registration.admin.logistics.badges'))
            ->assertOk()
            ->assertSee('Adult Guest')
            ->assertSee('Minor Guest');
    }

    #[TestDox('a guests badge name falls back to their display name until nominated')]
    public function test_a_guests_badge_name_falls_back_to_their_display_name_until_nominated(): void
    {
        $host = $this->makeRegistrant('Host', 'Registrant');
        $this->makeGuest($host, GuestType::Adult, ['guestname' => 'Formal Name', 'guestbadgename' => 'Nickname']);

        $this->actingAs($this->makeUser())
            ->get(route('registration.admin.logistics.badges'))
            ->assertOk()
            ->assertSee('Formal Name')
            ->assertDontSee('Nickname');

        app(GuestQuestions::class)->update([GuestQuestions::BADGE_NAME_KEY => 'guestbadgename']);

        $this->actingAs($this->makeUser())
            ->get(route('registration.admin.logistics.badges'))
            ->assertOk()
            ->assertSee('Nickname')
            ->assertDontSee('Formal Name');
    }

    #[TestDox('the shuttle page includes guests who travel with their flying registrant')]
    public function test_the_shuttle_page_includes_guests_who_travel_with_their_flying_registrant(): void
    {
        $flyingHost = $this->makeRegistrant('Flying', 'Host', ['arrivalday' => 'monday', 'arrivalflight' => '11:00']);
        $this->makeGuest($flyingHost, GuestType::Adult, ['guestname' => 'Flying Guest']);
        $this->makeGuest($flyingHost, GuestType::Minor, ['guestname' => 'Flying Minor']);

        // A guest whose own registrant never answers the flight questions
        // never appears — guests travel with their registrant, not on their
        // own separate itinerary.
        $groundHost = $this->makeRegistrant('Ground', 'Host');
        $this->makeGuest($groundHost, GuestType::Adult, ['guestname' => 'Ground Transport Guest']);

        $this->actingAs($this->makeUser())
            ->get(route('registration.admin.logistics.shuttles'))
            ->assertOk()
            ->assertSee('Flying Guest')
            ->assertSee('Flying Minor')
            ->assertDontSee('Ground Transport Guest');
    }

    #[TestDox('the shuttle page shows pickups and returns')]
    public function test_the_shuttle_page_shows_pickups_and_returns(): void
    {
        $this->makeRegistrant('Air', 'Rival', ['arrivalday' => 'monday', 'arrivalflight' => '10:00']);
        $this->makeRegistrant('Fly', 'Home', ['departureflight' => '18:00']);

        $response = $this->actingAs($this->makeUser())
            ->get(route('registration.admin.logistics.shuttles'))
            ->assertOk()
            ->assertSee(__('registration::admin.shuttles_pickups'))
            ->assertSee('Air Rival')
            ->assertSee(__('registration::admin.shuttles_returns'))
            ->assertSee('Fly Home')
            ->assertSee(__('registration::admin.shuttles_run_at', ['time' => '10:00']))
            ->assertSee(__('registration::admin.shuttles_run_at', ['time' => ShuttlePlanner::AFTERNOON_RUN]));

        // The pickup day's header counts its people; the returns entry has no
        // day header.
        $onePassenger = trans_choice('registration::admin.shuttles_passengers', 1);
        $this->assertMatchesRegularExpression(
            '/<strong>Monday<\/strong>\s*—\s*'.preg_quote($onePassenger, '/').'/',
            $response->getContent(),
        );

        $this->assertMatchesRegularExpression(
            '/<strong>'.preg_quote($this->reportCountLabel(), '/').'<\/strong>\s*<span>2<\/span>/',
            $response->getContent(),
        );
        $payload = $this->pdfPayload($response->getContent());
        $this->assertSame($this->reportCountLabel(), $payload['countLabel']);
        $this->assertSame(2, $payload['count']);
        $this->assertSame("Monday — {$onePassenger}", $payload['sections'][0]['days'][0]['label']);
        $this->assertSame('', $payload['sections'][1]['days'][0]['label']);
    }

    #[TestDox('the shuttle page shows the full name, not the badge name')]
    public function test_the_shuttle_page_shows_the_full_name_not_the_badge_name(): void
    {
        $this->makeRegistrant('Air', 'Rival', ['arrivalday' => 'monday', 'arrivalflight' => '10:00', 'badgename' => 'Sky Traveler']);

        $response = $this->actingAs($this->makeUser())
            ->get(route('registration.admin.logistics.shuttles'))
            ->assertOk()
            ->assertSee('Air Rival')
            ->assertDontSee('Sky Traveler');

        $payload = $this->pdfPayload($response->getContent());
        $this->assertStringContainsString('Air Rival', $payload['sections'][0]['days'][0]['runs'][0]['names']);

        $csv = $this->actingAs($this->makeUser())
            ->get(route('registration.admin.logistics.shuttles.csv'))
            ->assertOk()
            ->streamedContent();
        $this->assertStringContainsString('Air Rival', $csv);
        $this->assertStringNotContainsString('Sky Traveler', $csv);
    }

    #[TestDox('a guest on the shuttle page is shown by their display name, not their badge name')]
    public function test_a_guest_on_the_shuttle_page_is_shown_by_their_display_name_not_their_badge_name(): void
    {
        app(GuestQuestions::class)->update([GuestQuestions::BADGE_NAME_KEY => 'guestbadgename']);
        $host = $this->makeRegistrant('Flying', 'Host', ['arrivalday' => 'monday', 'arrivalflight' => '11:00']);
        $this->makeGuest($host, GuestType::Adult, ['guestname' => 'Formal Guest', 'guestbadgename' => 'Nickname']);

        $this->actingAs($this->makeUser())
            ->get(route('registration.admin.logistics.shuttles'))
            ->assertOk()
            ->assertSee('Formal Guest')
            ->assertDontSee('Nickname');
    }

    #[TestDox('the shuttle page flags return passengers beyond the fleet size for hand scheduling')]
    public function test_the_shuttle_page_flags_return_passengers_beyond_the_fleet_size_for_hand_scheduling(): void
    {
        app(ShuttlePlanner::class)->updateSettings(1, null, 1);
        $this->makeRegistrant('Fly', 'Home', ['departureflight' => '18:00']);
        $this->makeRegistrant('Stay', 'Behind', ['departureflight' => '19:00']);

        $response = $this->actingAs($this->makeUser())
            ->get(route('registration.admin.logistics.shuttles'))
            ->assertOk()
            ->assertSee(__('registration::admin.shuttles_overflow'))
            ->assertSee('Fly Home')
            ->assertSee('Stay Behind');

        $payload = $this->pdfPayload($response->getContent());
        $this->assertStringContainsString('Stay Behind', $payload['sections'][0]['days'][0]['runs'][0]['overflow']);

        $csv = $this->actingAs($this->makeUser())
            ->get(route('registration.admin.logistics.shuttles.csv'))
            ->assertOk()
            ->streamedContent();
        $this->assertStringContainsString(__('registration::admin.shuttles_overflow'), $csv);
        $this->assertStringContainsString('Stay Behind', $csv);
    }
}
