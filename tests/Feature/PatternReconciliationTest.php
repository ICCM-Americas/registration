<?php

namespace ConferenceTools\Registration\Tests\Feature;

use ConferenceTools\Branding\Contracts\BrandingProvider;
use ConferenceTools\Branding\Services\BrandingService;
use ConferenceTools\Registration\Models\Draft;
use ConferenceTools\Registration\Support\DefaultBranding;
use ConferenceTools\Registration\Tests\Fixtures\User;
use ConferenceTools\Registration\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestDox;

/** Feature tests for Pattern Reconciliation. */
#[TestDox('Pattern Reconciliation')]
class PatternReconciliationTest extends TestCase
{
    use RefreshDatabase;

    /** No hard FK into users — the deleting listener cleans up the wizard draft. */
    #[TestDox('deleting a user removes their draft')]
    public function test_deleting_a_user_removes_their_draft(): void
    {
        $user = User::create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'x']);
        Draft::factory()->create(['user_id' => $user->getKey()]);

        $this->assertSame(1, Draft::count());

        $user->delete(); // must not raise a foreign-key violation

        $this->assertSame(0, Draft::count());
    }

    /**
     * The replaceable branding seam resolves to the branding package's real
     * service — registered after this package, as in production — while the
     * package's own DefaultBranding stays a working fallback.
     */
    #[TestDox('branding seam is bound to the branding package service')]
    public function test_branding_seam_is_bound_to_the_branding_package_service(): void
    {
        $this->assertInstanceOf(BrandingService::class, app(BrandingProvider::class));
        $this->assertNotSame('', app(BrandingProvider::class)->siteName());
        $this->assertNotSame('', (new DefaultBranding)->siteName());
    }

    /** Core views render through the package translations and layout. */
    #[TestDox('core views render with translations')]
    public function test_core_views_render_with_translations(): void
    {
        $this->assertStringContainsString(
            'registration admin',
            strtolower(view('registration::admin.index', [
                'registrationsComplete' => 0,
                'registrationsIncomplete' => 0,
                'attendeesCount' => 0,
                'guestsCount' => 0,
                'specialNeedsCount' => 0,
                'arrivalsByDay' => collect(),
                'shuttleRunsCount' => 0,
                'roomAssignmentCoverage' => ['assigned' => 0, 'total' => 0, 'complete' => false],
                'prayerPalsCoverage' => ['assigned' => 0, 'total' => 0, 'complete' => false],
            ])->render())
        );
    }
}
