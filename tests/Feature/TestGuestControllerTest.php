<?php

namespace ConferenceTools\Registration\Tests\Feature;

use ConferenceTools\Registration\Enums\QuestionScope;
use ConferenceTools\Registration\Enums\QuestionType;
use ConferenceTools\Registration\Models\Draft;
use ConferenceTools\Registration\Models\Guest;
use ConferenceTools\Registration\Models\Question;
use ConferenceTools\Registration\Models\Section;
use ConferenceTools\Registration\Services\GuestQuestions;
use ConferenceTools\Registration\Support\TestDraft;
use ConferenceTools\Registration\Tests\Concerns\BuildsRegistrationData;
use ConferenceTools\Registration\Tests\Concerns\WalksRegistrationWizard;
use ConferenceTools\Registration\Tests\Fixtures\QuestionConfigSeeder;
use ConferenceTools\Registration\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * The admin test drive's Guest List hub — mirrors GuestControllerTest, but
 * against the session-held TestDraft: nothing is ever persisted, and the
 * session round-trip must carry the drafted guests across requests (see
 * TestDraft::fromSession()/save()).
 */
#[TestDox('Test Guest Controller')]
class TestGuestControllerTest extends TestCase
{
    use BuildsRegistrationData, RefreshDatabase, WalksRegistrationWizard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->allowRegistrationManagement();
        $this->seedGuestQuestions();
    }

    /** Drive the walker over the admin test-drive routes instead of the real flow. */
    protected function wizardRoutes(): array
    {
        return [
            'step' => 'registration.admin.test',
            'store' => 'registration.admin.test.store',
            'committed' => 'registration.admin.dashboard',
        ];
    }

    /** Move the seeded guest question and nominate the Guest-scope questions. */
    private function seedGuestQuestions(): void
    {
        $triggerSection = Section::create([
            'scope' => QuestionScope::Participant->value, 'key' => 'guest-trigger', 'title' => 'Guests', 'position' => -1, 'enabled' => true,
        ]);
        // The migration already seeded the question (live, in its own
        // dedicated section) — just move it here.
        Question::where('key', Question::GUEST_TRIGGER_KEY)->firstOrFail()->update([
            'section_id' => $triggerSection->id,
        ]);

        $guestSection = Section::create([
            'scope' => QuestionScope::Guest->value, 'key' => 'guest-details', 'title' => 'Guest Details', 'position' => 0, 'enabled' => true,
        ]);
        Question::create([
            'section_id' => $guestSection->id, 'key' => 'guestname', 'type' => QuestionType::Text->value,
            'label' => 'Guest name', 'position' => 0, 'required' => false, 'enabled' => true,
        ]);

        app(GuestQuestions::class)->update(['guest_name_key' => 'guestname']);
    }

    /** Seed the session run as if the trigger step were already submitted "Yes". */
    private function startTriggeredRun(): void
    {
        session([TestDraft::SESSION_KEY => ['answers' => [Question::GUEST_TRIGGER_KEY => 'Yes'], 'guests' => []]]);
    }

    #[TestDox('hub 404s until the trigger is answered yes')]
    public function test_hub_404s_until_the_trigger_is_answered_yes(): void
    {
        session([TestDraft::SESSION_KEY => ['answers' => [], 'guests' => []]]);

        $this->actingAs($this->makeUser())
            ->get(route('registration.admin.test.guests'))
            ->assertNotFound();
    }

    #[TestDox('add a guest persists nothing but survives the session round trip')]
    public function test_add_a_guest_persists_nothing_but_survives_the_session_round_trip(): void
    {
        $this->actingAs($this->makeUser());
        $this->startTriggeredRun();

        $this->post(route('registration.admin.test.guests.store'), [
            'guest_type' => 'adult', 'guestname' => 'Session Guest',
        ])->assertRedirect(route('registration.admin.test.guests'));

        // Nothing hit the database.
        $this->assertSame(0, Guest::count());

        // But the session round-trip carried it: a fresh request still sees it.
        $this->get(route('registration.admin.test.guests'))
            ->assertOk()
            ->assertSee('Session Guest');

        $draft = TestDraft::fromSession();
        $this->assertCount(1, $draft->guests);
        $this->assertSame('adult', $draft->guests[0]['type']);
    }

    #[TestDox('edit and remove a guest')]
    public function test_edit_and_remove_a_guest(): void
    {
        $this->actingAs($this->makeUser());
        $this->startTriggeredRun();

        $this->post(route('registration.admin.test.guests.store'), [
            'guest_type' => 'minor', 'guestname' => 'Kid',
        ]);
        $guestId = TestDraft::fromSession()->guests[0]['id'];

        $this->get(route('registration.admin.test.guests.edit', $guestId))
            ->assertOk()
            ->assertSee('value="Kid"', false);

        $this->post(route('registration.admin.test.guests.update', $guestId), ['guestname' => 'Kid Updated'])
            ->assertRedirect(route('registration.admin.test.guests'));
        $this->assertSame('Kid Updated', TestDraft::fromSession()->guests[0]['answers']['guestname']);

        $this->delete(route('registration.admin.test.guests.destroy', $guestId))
            ->assertRedirect(route('registration.admin.test.guests'));
        $this->assertSame([], TestDraft::fromSession()->guests);
    }

    #[TestDox('completing the run through the hub never creates a guest row')]
    public function test_completing_the_run_through_the_hub_never_creates_a_guest_row(): void
    {
        $this->defaultCurrency();
        $this->seed(QuestionConfigSeeder::class);
        $admin = $this->makeUser();

        $this->completeWizard($admin, array_merge($this->fixtureAnswers(), [Question::GUEST_TRIGGER_KEY => 'Yes']))
            ->assertRedirect(route('registration.admin.test.guests'));

        $this->post(route('registration.admin.test.guests.store'), [
            'guest_type' => 'adult', 'guestname' => 'Never Saved',
        ])->assertRedirect(route('registration.admin.test.guests'));
        $this->assertCount(1, TestDraft::fromSession()->guests);

        $this->completeWizard($admin, array_merge($this->fixtureAnswers(), [Question::GUEST_TRIGGER_KEY => 'Yes']))
            ->assertRedirect(route('registration.admin.dashboard'));

        // Nothing was recorded at all — no draft, and, in particular, no Guest row.
        $this->assertSame(0, Draft::count());
        $this->assertSame(0, Guest::count());
        $this->assertFalse(session()->has(TestDraft::SESSION_KEY));
    }
}
