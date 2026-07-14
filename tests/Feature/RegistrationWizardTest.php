<?php

namespace ConferenceTools\Registration\Tests\Feature;

use ConferenceTools\Registration\Database\Seeders\SystemQuestionsSeeder;
use ConferenceTools\Registration\Models\Draft;
use ConferenceTools\Registration\Models\Section;
use ConferenceTools\Registration\Services\RegistrationWizard;
use ConferenceTools\Registration\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestDox;

/** Feature tests for Registration Wizard. */
#[TestDox('Registration Wizard')]
class RegistrationWizardTest extends TestCase
{
    use RefreshDatabase;

    #[TestDox('current step is null when no sections are configured')]
    public function test_current_step_is_null_when_no_sections_are_configured(): void
    {
        // Nothing else is seeded, but the migration always seeds the live
        // guest/group system-questions section — disable it too, so no scope
        // yields a section and the wizard genuinely has no steps at all;
        // currentStep() then has nowhere to land.
        Section::where('key', SystemQuestionsSeeder::SECTION_KEY)->update(['enabled' => false]);
        $draft = Draft::factory()->create();

        $this->assertNull(app(RegistrationWizard::class)->currentStep($draft));
    }
}
