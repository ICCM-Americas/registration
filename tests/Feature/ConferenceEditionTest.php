<?php

namespace ConferenceTools\Registration\Tests\Feature;

use ConferenceTools\Registration\Services\ConferenceEdition;
use ConferenceTools\Registration\Services\RegistrationStatus;
use ConferenceTools\Registration\Tests\Concerns\BuildsRegistrationData;
use ConferenceTools\Registration\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * The conference edition: the single place the conference name and year are
 * recorded, the "start next conference" rollover, and which edition the
 * closed registration page refers to across that rollover.
 */
#[TestDox('Conference Edition')]
class ConferenceEditionTest extends TestCase
{
    use BuildsRegistrationData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->allowRegistrationManagement();
    }

    /** The conference-edition service under test. */
    private function edition(): ConferenceEdition
    {
        return app(ConferenceEdition::class);
    }

    #[TestDox('the edition is unset by default and stores name and year')]
    public function test_the_edition_is_unset_by_default_and_stores_name_and_year(): void
    {
        $this->assertNull($this->edition()->name());
        $this->assertNull($this->edition()->year());

        $this->edition()->update('ICCM Americas', '2026');

        $this->assertSame('ICCM Americas', $this->edition()->name());
        $this->assertSame('2026', $this->edition()->year());

        // Storing empties clears the edition again.
        $this->edition()->update('', '');
        $this->assertNull($this->edition()->name());
        $this->assertNull($this->edition()->year());
    }

    #[TestDox('starting the next conference advances the year and archives the edition')]
    public function test_starting_the_next_conference_advances_the_year_and_archives_the_edition(): void
    {
        $this->edition()->update('ICCM Americas', '2026');

        $this->edition()->startNext();

        $this->assertSame('ICCM Americas', $this->edition()->name());
        $this->assertSame('2027', $this->edition()->year());
    }

    /** The unadvanceable years for the data provider. */
    public static function unadvanceableYears(): array
    {
        // A year that is not a plain integer is archived but left as-is: an
        // unset year, and a non-numeric placeholder an admin might have typed.
        return [
            'no year set' => [null],
            'non-numeric placeholder' => ['TBD'],
        ];
    }

    #[DataProvider('unadvanceableYears')]
    #[TestDox('a non numeric year is archived but not advanced')]
    public function test_a_non_numeric_year_is_archived_but_not_advanced(?string $year): void
    {
        $this->edition()->update('ICCM Americas', $year);

        $this->edition()->startNext();

        $this->assertSame($year, $this->edition()->year());
    }

    #[TestDox('a rollover without a close date leaves the closed page on the current edition')]
    public function test_a_rollover_without_a_close_date_leaves_the_closed_page_on_the_current_edition(): void
    {
        // Rolled over with only an open date scheduled: with no close date the
        // closed page cannot be pinned to the outgoing edition, so it names
        // the current one (the archive is only used relative to a close date).
        $this->edition()->update('ICCM Americas', '2026');
        app(RegistrationStatus::class)->schedule(now()->subDays(1), null);
        $this->edition()->startNext();

        $this->assertSame('ICCM Americas', $this->edition()->closedName());
        $this->assertSame('2027', $this->edition()->closedYear());
    }

    #[TestDox('the closed page edition follows the rollover relative to the close date')]
    public function test_the_closed_page_edition_follows_the_rollover_relative_to_the_close_date(): void
    {
        $this->edition()->update('ICCM Americas', '2026');
        $status = app(RegistrationStatus::class);

        // Before any rollover the closed page names the current edition.
        $status->schedule(now()->subDays(30), now()->subDays(2));
        $this->assertSame('ICCM Americas', $this->edition()->closedName());
        $this->assertSame('2026', $this->edition()->closedYear());

        // Rolling over after the close keeps the closed page on the outgoing
        // edition while the current one moves to next year.
        $this->edition()->startNext();
        $this->assertSame('2027', $this->edition()->year());
        $this->assertSame('ICCM Americas', $this->edition()->closedName());
        $this->assertSame('2026', $this->edition()->closedYear());

        // Once the next edition's own window has closed (a close date after
        // the rollover), the closed page names the current edition again.
        $status->schedule(now()->subDays(1), now()->addDays(60));
        $this->travelTo(now()->addDays(61));
        $this->assertSame('ICCM Americas', $this->edition()->closedName());
        $this->assertSame('2027', $this->edition()->closedYear());
    }

    #[TestDox('saving the edition from the dashboard card')]
    public function test_saving_the_edition_from_the_dashboard_card(): void
    {
        $this->actingAs($this->makeUser())
            ->put(route('registration.admin.conference.update'), [
                'conference_name' => 'ICCM Americas',
                'conference_year' => '2026',
            ])
            ->assertRedirect(route('registration.admin.dashboard'))
            ->assertSessionHas('conference_status', __('registration::admin.conference_saved'));

        $this->assertSame('ICCM Americas', $this->edition()->name());
        $this->assertSame('2026', $this->edition()->year());
    }

    #[TestDox('the year must be a four digit number')]
    public function test_the_year_must_be_a_four_digit_number(): void
    {
        $this->actingAs($this->makeUser())
            ->put(route('registration.admin.conference.update'), [
                'conference_name' => 'ICCM Americas',
                'conference_year' => 'twenty-six',
            ])
            ->assertSessionHasErrors('conference_year');

        $this->assertNull($this->edition()->year());
    }

    #[TestDox('starting the next conference from the dashboard card')]
    public function test_starting_the_next_conference_from_the_dashboard_card(): void
    {
        $this->edition()->update('ICCM Americas', '2026');

        $this->actingAs($this->makeUser())
            ->post(route('registration.admin.conference.next'))
            ->assertRedirect(route('registration.admin.dashboard'))
            ->assertSessionHas('conference_status', __('registration::admin.conference_next_started'));

        $this->assertSame('2027', $this->edition()->year());
    }

    #[TestDox('the dashboard shows the edition and flags a diverged closed page')]
    public function test_the_dashboard_shows_the_edition_and_flags_a_diverged_closed_page(): void
    {
        $this->edition()->update('ICCM Americas', '2026');
        app(RegistrationStatus::class)->schedule(now()->subDays(30), now()->subDays(2));

        // No rollover yet: no divergence note.
        $this->actingAs($this->makeUser())
            ->get(route('registration.admin.dashboard'))
            ->assertOk()
            ->assertSee(__('registration::admin.conference_title'))
            ->assertSee('ICCM Americas')
            ->assertDontSee(__('registration::admin.conference_closed_shows', ['name' => 'ICCM Americas 2026']));

        $this->edition()->startNext();

        $this->actingAs($this->makeUser())
            ->get(route('registration.admin.dashboard'))
            ->assertOk()
            ->assertSee(__('registration::admin.conference_closed_shows', ['name' => 'ICCM Americas 2026']));
    }

    #[TestDox('the conference routes require the gate')]
    public function test_the_conference_routes_require_the_gate(): void
    {
        $this->denyRegistrationManagement();

        $this->actingAs($this->makeUser())
            ->post(route('registration.admin.conference.next'))
            ->assertForbidden();
    }
}
