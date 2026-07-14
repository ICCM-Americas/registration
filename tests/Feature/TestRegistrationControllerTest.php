<?php

namespace ConferenceTools\Registration\Tests\Feature;

use ConferenceTools\Registration\Database\Seeders\SystemQuestionsSeeder;
use ConferenceTools\Registration\Mail\TemplatedMail;
use ConferenceTools\Registration\Models\Answer;
use ConferenceTools\Registration\Models\Draft;
use ConferenceTools\Registration\Models\EmailTemplate;
use ConferenceTools\Registration\Models\Group;
use ConferenceTools\Registration\Models\Section;
use ConferenceTools\Registration\Support\TestDraft;
use ConferenceTools\Registration\Tests\Concerns\BuildsRegistrationData;
use ConferenceTools\Registration\Tests\Concerns\WalksRegistrationWizard;
use ConferenceTools\Registration\Tests\Fixtures\QuestionConfigSeeder;
use ConferenceTools\Registration\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * The admin test drive of the registration process: the real wizard end to end,
 * but recording nothing — not even a draft — and sending both completion emails
 * to the signed-in admin. Deliberately usable while registration is closed
 * (none of these tests opens the registration window).
 */
#[TestDox('Test Registration Controller')]
class TestRegistrationControllerTest extends TestCase
{
    use BuildsRegistrationData, RefreshDatabase, WalksRegistrationWizard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->allowRegistrationManagement();
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

    /** Seed the questionnaire these tests submit against. */
    private function seedForm(): void
    {
        $this->defaultCurrency();
        $this->seed(QuestionConfigSeeder::class);
    }

    #[TestDox('the nav shows the test button as secondary')]
    public function test_the_nav_shows_the_test_button_as_secondary(): void
    {
        $this->defaultCurrency();

        $this->actingAs($this->makeUser())
            ->get(route('registration.admin.dashboard'))
            ->assertOk()
            ->assertSee(
                '<a href="'.route('registration.admin.test').'" class="btn btn-secondary">'
                    .__('registration::admin.nav_test_registration').'</a>',
                false,
            );
    }

    #[TestDox('the test drive requires the admin gate')]
    public function test_the_test_drive_requires_the_admin_gate(): void
    {
        $this->denyRegistrationManagement();

        $this->actingAs($this->makeUser())
            ->get(route('registration.admin.test'))
            ->assertForbidden();
    }

    #[TestDox('the test drive requires authentication')]
    public function test_the_test_drive_requires_authentication(): void
    {
        Route::get('/login', fn () => 'login')->name('login');

        $this->get(route('registration.admin.test'))->assertRedirect(route('login'));
    }

    #[TestDox('the test drive opens straight on the first step with the test banner and test action')]
    public function test_the_test_drive_opens_straight_on_the_first_step_with_the_test_banner_and_test_action(): void
    {
        $this->seedForm();

        // No open window: the test drive must work while registration is closed.
        // There is no separate opening screen — the first step renders right
        // away, posting back to the test flow; the banner's exit button
        // deep-links to the Questions console, anchored to the section being
        // tested (the first step's section — "your-details", position 0).
        $sectionId = Section::firstWhere('key', 'your-details')->id;

        $this->actingAs($this->makeUser())
            ->get(route('registration.admin.test'))
            ->assertOk()
            ->assertSee(__('registration::admin.test_mode_notice'))
            ->assertSee(__('registration::admin.test_exit'))
            ->assertSee('action="'.route('registration.admin.test.store').'"', false)
            ->assertSee('name="_step"', false)
            ->assertSee('href="'.route('registration.admin.questions').'#section-'.$sectionId.'"', false);

        // The run lives in the session only — never the drafts table.
        $this->assertSame(0, Draft::count());
    }

    #[TestDox('completing the run records nothing and sends both emails to the admin')]
    public function test_completing_the_run_records_nothing_and_sends_both_emails_to_the_admin(): void
    {
        Mail::fake();
        $this->seedForm();

        // A saved template proves the {q:...} context resolves from the run's
        // never-stored answers; the admin notification keeps its lang default.
        EmailTemplate::create([
            'key' => EmailTemplate::REGISTRANT,
            'subject' => 'Thanks {q:name.value}',
            'body' => 'Accommodation: {q:accommodation.value}.',
        ]);

        $admin = $this->makeUser(['email' => 'tester@example.com']);
        $this->completeWizard($admin, $this->fixtureAnswers())
            ->assertRedirect(route('registration.admin.dashboard'))
            ->assertSessionHas('test_status', __('registration::admin.test_completed', ['email' => 'tester@example.com']));

        // Nothing was recorded: no group, no answers, and no draft — not even a
        // partial one — while the session run is discarded too.
        $this->assertSame(0, Group::count());
        $this->assertSame(0, Answer::count());
        $this->assertSame(0, Draft::count());
        $this->assertFalse(session()->has(TestDraft::SESSION_KEY));

        // Both emails — the registrant confirmation AND the administrator
        // notification — went to the signed-in admin.
        Mail::assertSent(TemplatedMail::class, 2);
        Mail::assertSent(TemplatedMail::class, fn (TemplatedMail $mail) => $mail->hasTo('tester@example.com')
            && $mail->subjectLine === 'Thanks Ada'
            && $mail->bodyText === 'Accommodation: Hotel.');
        Mail::assertSent(TemplatedMail::class, fn (TemplatedMail $mail) => $mail->hasTo('tester@example.com')
            && $mail->subjectLine === __('registration::mail.admin_notification_subject'));

        // The dashboard the admin lands on confirms the completed run.
        $this->get(route('registration.admin.dashboard'))
            ->assertOk()
            ->assertSee(__('registration::admin.test_completed', ['email' => 'tester@example.com']));
    }

    #[TestDox('back returns to the previous step')]
    public function test_back_returns_to_the_previous_step(): void
    {
        $this->seedForm();
        $this->actingAs($this->makeUser());

        $first = $this->currentWizardStep();
        $this->post(route('registration.admin.test.store'), array_merge($this->fixtureAnswers(), [
            '_step' => $first, '_direction' => 'next',
        ]))->assertRedirect(route('registration.admin.test'));

        $second = $this->currentWizardStep();
        $this->assertNotSame($first, $second);

        $this->post(route('registration.admin.test.store'), [
            '_step' => $second, '_direction' => 'back',
        ])->assertRedirect(route('registration.admin.test'));

        $this->assertSame($first, $this->currentWizardStep());
        $this->assertSame(0, Draft::count());
    }

    #[TestDox('back on the first step stays on the first step')]
    public function test_back_on_the_first_step_stays_on_the_first_step(): void
    {
        $this->seedForm();
        $this->actingAs($this->makeUser());

        $first = $this->currentWizardStep();

        $this->post(route('registration.admin.test.store'), [
            '_step' => $first, '_direction' => 'back',
        ])->assertRedirect(route('registration.admin.test'));

        $this->assertSame($first, $this->currentWizardStep());
    }

    #[TestDox('a step is validated server side like the real flow')]
    public function test_a_step_is_validated_server_side_like_the_real_flow(): void
    {
        $this->seedForm();
        $this->actingAs($this->makeUser());

        $this->post(route('registration.admin.test.store'), [
            '_step' => $this->currentWizardStep(),
            '_direction' => 'next',
            'name' => '',
        ])->assertSessionHasErrors('name');
    }

    #[TestDox('tampered step id is rejected')]
    public function test_tampered_section_id_is_rejected(): void
    {
        $this->seedForm();
        $this->actingAs($this->makeUser());

        $this->post(route('registration.admin.test.store'), ['_step' => 99999, '_direction' => 'next'])
            ->assertStatus(422);
    }

    #[TestDox('an unconfigured form shows the not configured notice')]
    public function test_an_unconfigured_form_shows_the_not_configured_notice(): void
    {
        $this->defaultCurrency();
        // The migration always seeds the live guest/group system-questions
        // section — disable it too, so the form is genuinely unconfigured.
        Section::where('key', SystemQuestionsSeeder::SECTION_KEY)->update(['enabled' => false]);
        $this->actingAs($this->makeUser());

        $this->get(route('registration.admin.test'))
            ->assertOk()
            ->assertSee(__('registration::admin.test_mode_notice'))
            ->assertSee(__('Registration is not configured yet.'));
    }
}
