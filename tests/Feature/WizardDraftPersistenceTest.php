<?php

namespace ConferenceTools\Registration\Tests\Feature;

use ConferenceTools\Registration\Models\Draft;
use ConferenceTools\Registration\Models\Group;
use ConferenceTools\Registration\Models\Question;
use ConferenceTools\Registration\Tests\Concerns\BuildsRegistrationData;
use ConferenceTools\Registration\Tests\Concerns\WalksRegistrationWizard;
use ConferenceTools\Registration\Tests\Fixtures\QuestionConfigSeeder;
use ConferenceTools\Registration\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * The wizard saves progress to a per-registrant draft after each step, so a
 * half-finished registration can be resumed later, and the draft (deleted on
 * commit) is what the admin dashboard counts as an incomplete registration.
 */
#[TestDox('Wizard Draft Persistence')]
class WizardDraftPersistenceTest extends TestCase
{
    use BuildsRegistrationData, RefreshDatabase, WalksRegistrationWizard;

    protected function setUp(): void
    {
        parent::setUp();

        // The registrant-facing routes are gated on the registration window.
        $this->openRegistration();
    }

    /** Seed the questionnaire these tests submit against. */
    private function seedForm(): void
    {
        $this->defaultCurrency();
        $this->seed(QuestionConfigSeeder::class);
    }

    /** A valid answers map for the fixture questionnaire. */
    private function answers(): array
    {
        return [
            'name' => 'Ada', 'lastname' => 'Lovelace', 'passport' => 'Ada Lovelace',
            'gender' => 'f', 'residence' => 'UK',
            'accommodation' => 'hotel',
            'organization' => 'Engines', 'orgtype' => 'business',
            'address' => '1 St', 'town' => 'London', 'zipcode' => '00000',
            'country' => 'UK', 'telephone' => '12345',
            Question::GUEST_TRIGGER_KEY => 'No', Question::GROUP_TRIGGER_KEY => 'No',
        ];
    }

    /** Submit just the first step and stop. */
    private function submitFirstStep(): int
    {
        $first = $this->currentWizardStep();
        $this->post(route('registration.register.store'), array_merge($this->answers(), [
            '_step' => $first, '_direction' => 'next',
        ]))->assertRedirect(route('registration.register'));

        return $first;
    }

    #[TestDox('each step is saved to a draft')]
    public function test_each_step_is_saved_to_a_draft(): void
    {
        $this->seedForm();
        $user = $this->makeUser();
        $this->actingAs($user);

        $this->assertSame(0, Draft::count());      // nothing saved just by viewing

        $first = $this->submitFirstStep();

        $draft = Draft::where('user_id', $user->getKey())->first();
        $this->assertNotNull($draft);
        $this->assertSame('Ada', data_get($draft->answers, 'name'));
        // The pointer has advanced past the step just completed.
        $this->assertNotSame($first, $draft->current_question_id);
        $this->assertSame(1, Draft::incompleteCount());
    }

    #[TestDox('registrant resumes at the saved step with earlier answers')]
    public function test_registrant_resumes_at_the_saved_step_with_earlier_answers(): void
    {
        $this->seedForm();
        $user = $this->makeUser();
        $this->actingAs($user);

        $this->submitFirstStep();

        // Returning later (a fresh request) lands on the next step, not the start,
        // and the accommodation step is shown.
        $this->get(route('registration.register'))
            ->assertOk()
            // 4, not 2: plus the always-live guest and group trigger steps.
            ->assertSee('Step 2 / 4')
            ->assertSee('name="accommodation"', false);

        // The first step's answer is still held in the draft.
        $draft = Draft::where('user_id', $user->getKey())->first();
        $this->assertSame('Lovelace', data_get($draft->answers, 'lastname'));
    }

    #[TestDox('completing registration deletes the draft')]
    public function test_completing_registration_deletes_the_draft(): void
    {
        $this->seedForm();
        $user = $this->makeUser(['email' => 'ada@example.com']);

        $this->completeWizard($user, $this->answers())
            ->assertRedirect(route('registration.info'));

        $this->assertSame(0, Draft::incompleteCount());
        $this->assertSame(1, Group::registeredParticipantCount());
        $this->assertNotNull($user->fresh()->group);
    }

    #[TestDox('dashboard reports incomplete registrations')]
    public function test_dashboard_reports_incomplete_registrations(): void
    {
        $this->allowRegistrationManagement();
        Draft::factory()->create();
        Draft::factory()->create();

        $this->actingAs($this->makeUser())
            ->get(route('registration.admin.dashboard'))
            ->assertOk()
            ->assertViewHas('registrationsIncomplete', 2);
    }

    #[TestDox('draft is removed when the registrant is deleted')]
    public function test_draft_is_removed_when_the_registrant_is_deleted(): void
    {
        $user = $this->makeUser();
        Draft::factory()->create(['user_id' => $user->getKey()]);

        $this->assertSame(1, Draft::count());

        $user->delete();

        $this->assertSame(0, Draft::count());
    }
}
