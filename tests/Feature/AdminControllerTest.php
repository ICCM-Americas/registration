<?php

namespace ConferenceTools\Registration\Tests\Feature;

use ConferenceTools\Registration\Tests\Concerns\BuildsRegistrationData;
use ConferenceTools\Registration\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestDox;

/** Feature tests for Admin Controller. */
#[TestDox('Admin Controller')]
class AdminControllerTest extends TestCase
{
    use BuildsRegistrationData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->allowRegistrationManagement();
    }

    #[TestDox('dashboard renders')]
    public function test_dashboard_renders(): void
    {
        $this->actingAs($this->admin())
            ->get(route('registration.admin.dashboard'))
            ->assertOk();
    }

    #[TestDox('admin routes require the gate')]
    public function test_admin_routes_require_the_gate(): void
    {
        $this->denyRegistrationManagement();

        $this->actingAs($this->admin())
            ->get(route('registration.admin.dashboard'))
            ->assertForbidden();
    }
}
