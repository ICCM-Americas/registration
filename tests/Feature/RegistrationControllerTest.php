<?php

namespace ConferenceTools\Registration\Tests\Feature;

use ConferenceTools\Registration\Database\Seeders\InfoStepSeeder;
use ConferenceTools\Registration\Enums\BooleanOperator;
use ConferenceTools\Registration\Enums\ConditionOperator;
use ConferenceTools\Registration\Enums\GuestType;
use ConferenceTools\Registration\Enums\QuestionScope;
use ConferenceTools\Registration\Enums\QuestionType;
use ConferenceTools\Registration\Http\Controllers\GroupInviteController;
use ConferenceTools\Registration\Mail\TemplatedMail;
use ConferenceTools\Registration\Models\BaseCharge;
use ConferenceTools\Registration\Models\Group;
use ConferenceTools\Registration\Models\GroupInvite;
use ConferenceTools\Registration\Models\Guest;
use ConferenceTools\Registration\Models\InfoStep;
use ConferenceTools\Registration\Models\Question;
use ConferenceTools\Registration\Models\Section;
use ConferenceTools\Registration\Models\Variable;
use ConferenceTools\Registration\Services\GuestQuestions;
use ConferenceTools\Registration\Tests\Concerns\BuildsRegistrationData;
use ConferenceTools\Registration\Tests\Concerns\WalksRegistrationWizard;
use ConferenceTools\Registration\Tests\Fixtures\QuestionConfigSeeder;
use ConferenceTools\Registration\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\TestDox;

/** Feature tests for Registration Controller. */
#[TestDox('Registration Controller')]
class RegistrationControllerTest extends TestCase
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

    #[TestDox('info page renders')]
    public function test_info_page_renders(): void
    {
        $this->defaultCurrency();

        $this->get(route('registration.info'))->assertOk();
    }

    #[TestDox('info page confirms a just committed registration')]
    public function test_info_page_confirms_a_just_committed_registration(): void
    {
        $this->defaultCurrency();

        // The wizard's final step redirects here with the "registered" flash.
        $this->withSession(['registered' => true])
            ->get(route('registration.info'))
            ->assertOk()
            ->assertSee(__('registration::info.thanks_strong'));
    }

    #[TestDox('info page shows the enabled steps numbered in display order')]
    public function test_info_page_shows_the_enabled_steps_numbered_in_display_order(): void
    {
        $this->defaultCurrency();
        $this->seed(InfoStepSeeder::class);
        InfoStep::firstWhere('position', 2)->update(['enabled' => false]);

        // A hidden step is skipped and the remaining steps renumber from 1.
        $this->get(route('registration.info'))
            ->assertOk()
            ->assertSeeInOrder([
                'Step 1: '.InfoStepSeeder::DEFAULT_STEPS[1]['heading'],
                'Step 2: '.InfoStepSeeder::DEFAULT_STEPS[3]['heading'],
            ])
            ->assertDontSee(InfoStepSeeder::DEFAULT_STEPS[2]['heading']);
    }

    #[TestDox('info page renders steps in the registrants locale with fallback')]
    public function test_info_page_renders_steps_in_the_registrants_locale_with_fallback(): void
    {
        $this->defaultCurrency();
        $this->seed(InfoStepSeeder::class);
        InfoStep::firstWhere('position', 1)->storeTranslation('fr', 'heading', 'Connexion au compte');

        app()->setLocale('fr');

        // Step 1 has a French heading; step 2 has no translation and falls back
        // to its base (English) text.
        $this->get(route('registration.info'))
            ->assertOk()
            ->assertSee('Step 1: Connexion au compte')
            ->assertSee(InfoStepSeeder::DEFAULT_STEPS[2]['heading']);
    }

    #[TestDox('info page interpolates variables into the steps')]
    public function test_info_page_interpolates_variables_into_the_steps(): void
    {
        $this->defaultCurrency();
        Variable::factory()->create(['name' => 'conf_name', 'value' => 'ICCM Africa']);
        InfoStep::factory()->create([
            'heading' => 'Register for {conf_name}', 'body' => 'See you at {conf_name}.', 'position' => 1,
        ]);

        $this->get(route('registration.info'))
            ->assertOk()
            ->assertSee('Step 1: Register for ICCM Africa')
            ->assertSee('See you at ICCM Africa.');
    }

    #[TestDox('wizard interpolates variables into question and option texts')]
    public function test_wizard_interpolates_variables_into_question_and_option_texts(): void
    {
        $this->seedForm();
        Variable::factory()->create(['name' => 'conf_name', 'value' => 'ICCM Africa']);
        Question::firstWhere('key', 'name')->update([
            'label' => 'First Name for {conf_name}',
            'help_text' => 'Printed on your {conf_name} badge.',
            'placeholder' => 'As used at {conf_name}',
        ]);
        Question::firstWhere('key', 'gender')->options()->firstWhere('label', 'Male')
            ->update(['label' => 'Male ({conf_name} count)']);

        $this->actingAs($this->makeUser());

        $this->get(route('registration.register'))
            ->assertOk()
            ->assertSee('First Name for ICCM Africa')
            ->assertSee('Printed on your ICCM Africa badge.')
            ->assertSee('As used at ICCM Africa')
            ->assertSee('Male (ICCM Africa count)');
    }

    #[TestDox('wizard renders markup in help text and option texts')]
    public function test_wizard_renders_markup_in_help_text_and_option_texts(): void
    {
        $this->seedForm();
        Question::firstWhere('key', 'name')->update([
            'help_text' => '**Bold** and _italic_ help.',
        ]);
        Question::firstWhere('key', 'gender')->options()->firstWhere('label', 'Male')
            ->update(['label' => '**Male**', 'description' => '_He/him_']);

        $this->actingAs($this->makeUser());

        $this->get(route('registration.register'))
            ->assertOk()
            ->assertSee('<b>Bold</b> and <i>italic</i> help.', false)
            ->assertSee('<b>Male</b>', false)
            ->assertSee('<i>He/him</i>', false);
    }

    #[TestDox('wizard renders question and option texts in the registrants locale')]
    public function test_wizard_renders_question_and_option_texts_in_the_registrants_locale(): void
    {
        $this->seedForm();
        $name = Question::firstWhere('key', 'name');
        $name->storeTranslation('fr', 'label', 'Prénom');
        Question::firstWhere('key', 'gender')->options()->firstWhere('label', 'Male')
            ->storeTranslation('fr', 'label', 'Homme');

        app()->setLocale('fr');
        $this->actingAs($this->makeUser());

        // Translated texts show in French; an untranslated question (lastname)
        // falls back to its base text.
        $this->get(route('registration.register'))
            ->assertOk()
            ->assertSee('Prénom')
            ->assertSee('Homme')
            ->assertSee('Last Name');
    }

    #[TestDox('registration form requires authentication')]
    public function test_registration_form_requires_authentication(): void
    {
        // Account creation / login is the host's job; the form is auth-gated.
        Route::get('/login', fn () => 'login')->name('login');

        $this->get(route('registration.register'))->assertRedirect(route('login'));
    }

    #[TestDox('first step renders with its questions and server wizard controls')]
    public function test_first_step_renders_with_its_questions_and_server_wizard_controls(): void
    {
        $this->seedForm();

        $this->actingAs($this->makeUser());

        $response = $this->get(route('registration.register'))->assertOk();

        // The first step shows its own questions and the server-driven controls:
        // a hidden _step, a Next submit, and a step counter. Back is disabled.
        $response->assertSee('Your details');
        $response->assertSee('name="name"', false);
        $response->assertSee('name="_step"', false);
        $response->assertSee('value="next"', false);
        // 4, not 2: the fixture's own two steps plus the always-live guest and
        // group trigger questions, each its own reactive step (see
        // SystemQuestionsSeeder / RegistrationWizard::expandSection()).
        $response->assertSee('Step 1 / 4');

        // Later-step fields are NOT on this page (the wizard hits the backend per
        // step), and group-scope questions are not part of the flow at all.
        $response->assertDontSee('name="accommodation"', false);
        $response->assertDontSee('name="organization"', false);
    }

    #[TestDox('later steps render priced and conditional questions')]
    public function test_later_steps_render_priced_and_conditional_questions(): void
    {
        $this->seedForm();
        // A within-step conditional on the accommodation step: its rule is
        // emitted as data-visible-when so the browser can toggle it live.
        $accommodation = Question::firstWhere('key', 'accommodation');
        $roommate = Question::factory()->for($accommodation->section)
            ->create(['key' => 'roommate', 'label' => 'Preferred roommate', 'position' => 2]);
        $group = $roommate->conditionGroups()->create(['operator' => BooleanOperator::And->value]);
        $group->conditions()->create([
            'question_id' => $accommodation->id, 'operator' => ConditionOperator::Equals->value, 'value' => 'hotel',
        ]);

        $user = $this->makeUser();
        $this->actingAs($user);

        // Step 1 → step 2: the accommodation step shows the priced option and
        // the conditional field with its within-step rule for the browser.
        $first = $this->currentWizardStep();
        $this->post(route('registration.register.store'), array_merge($this->fixtureAnswers(), [
            '_step' => $first, '_direction' => 'next',
        ]))->assertRedirect(route('registration.register'));

        $this->get(route('registration.register'))
            ->assertOk()
            ->assertSee('name="accommodation"', false)
            ->assertSee('$ 100.00')
            ->assertSee('name="roommate"', false)
            ->assertSee('data-visible-when', false);
    }

    #[TestDox('free form inputs carry mobile type hints')]
    public function test_free_form_inputs_carry_mobile_type_hints(): void
    {
        $this->seedForm();
        // Typed free-form questions on the first step: the input's type and
        // inputmode/autocomplete hints get mobile browsers to offer the
        // matching keyboard and autofill suggestions.
        $details = Section::firstWhere('key', 'your-details');
        Question::factory()->for($details)->create(['key' => 'workemail', 'type' => 'email', 'position' => 20]);
        Question::factory()->for($details)->create(['key' => 'mobile', 'type' => 'tel', 'position' => 21]);
        Question::factory()->for($details)->create(['key' => 'homepage', 'type' => 'url', 'position' => 22]);

        $this->actingAs($this->makeUser());

        $this->get(route('registration.register'))
            ->assertOk()
            ->assertSee('type="email"', false)
            ->assertSee('inputmode="email"', false)
            ->assertSee('autocomplete="email"', false)
            ->assertSee('type="tel"', false)
            ->assertSee('autocomplete="tel"', false)
            ->assertSee('type="url"', false)
            ->assertSee('inputmode="url"', false);
    }

    #[TestDox('a sections rule decides whether its step is offered')]
    public function test_a_sections_rule_decides_whether_its_step_is_offered(): void
    {
        $this->seedForm();
        // The accommodation step is offered to male registrants only (an
        // arbitrary controlling answer from the earlier step).
        $accommodation = Section::firstWhere('key', 'accommodation');
        $rule = $accommodation->conditionGroups()->create(['operator' => BooleanOperator::And->value]);
        $rule->conditions()->create([
            'question_id' => Question::firstWhere('key', 'gender')->id,
            'operator' => ConditionOperator::Equals->value,
            'value' => 'm',
        ]);

        // Failing rule (the fixture answers say f): the wizard is one step —
        // its questions are skipped wholesale, whatever their own visibility —
        // and Next on it commits the registration.
        $this->completeWizard($this->makeUser(), $this->fixtureAnswers())
            ->assertRedirect(route('registration.info'));

        // Passing rule: the accommodation step follows as step 2.
        $this->actingAs($this->makeUser());
        $this->post(route('registration.register.store'), array_merge($this->fixtureAnswers(), [
            'gender' => 'm',
            '_step' => $this->currentWizardStep(),
            '_direction' => 'next',
        ]))->assertRedirect(route('registration.register'));

        $this->get(route('registration.register'))
            ->assertOk()
            ->assertSee('name="accommodation"', false)
            // 4, not 2: plus the always-live guest and group trigger steps.
            ->assertSee('Step 2 / 4');
    }

    #[TestDox('a disabled section never renders its step')]
    public function test_a_disabled_section_never_renders_its_step(): void
    {
        $this->seedForm();
        // "Never show" in the builder maps to the enabled flag; the step drops
        // out of the wizard and the counter renumbers.
        Section::firstWhere('key', 'accommodation')->update(['enabled' => false]);

        $this->actingAs($this->makeUser());

        $this->get(route('registration.register'))
            ->assertOk()
            // 3, not 1: plus the always-live guest and group trigger steps.
            ->assertSee('Step 1 / 3')
            ->assertDontSee('name="accommodation"', false);
    }

    #[TestDox('the final step shows the cost summary')]
    public function test_the_final_step_shows_the_cost_summary(): void
    {
        $this->seedForm();
        BaseCharge::factory()->create(['name' => 'Conference Fee', 'amount' => 250]);
        // A terms-style step after the priced accommodation step (and after
        // the always-live guest/group trigger steps, positioned last on
        // their own — see SystemQuestionsSeeder::LAST_POSITION), so the
        // priced answers are already in the draft when this final step
        // renders.
        $final = Section::factory()->create(['scope' => QuestionScope::Participant, 'position' => 2_000_000]);
        Question::factory()->for($final)->create(['key' => 'remarks', 'label' => 'Remarks', 'position' => 0]);

        $this->actingAs($this->makeUser());

        // Not the final step yet: no summary.
        $this->get(route('registration.register'))->assertOk()->assertDontSee('data-cost-summary', false);

        // 4, not 3: your-details, accommodation, and the guest and group
        // trigger steps precede this custom final step.
        foreach (range(1, 4) as $step) {
            $this->post(route('registration.register.store'), array_merge($this->fixtureAnswers(), [
                'product_dinner' => 'on',
                '_step' => $this->currentWizardStep(), '_direction' => 'next',
            ]))->assertRedirect(route('registration.register'));
        }

        // The final step recaps the flat charge and both priced choices, with
        // the total the registrant will actually be charged.
        $this->get(route('registration.register'))
            ->assertOk()
            ->assertSee('data-cost-summary', false)
            ->assertSee(__('registration::common.cost_summary_heading'))
            ->assertSeeInOrder(['Conference Fee', '$ 250.00', 'Hotel', '$ 100.00', 'Dinner', '$ 20.00', 'Total', '$ 370.00']);
    }

    #[TestDox('back returns to the previous step')]
    public function test_back_returns_to_the_previous_step(): void
    {
        $this->seedForm();
        $this->actingAs($this->makeUser());

        $first = $this->currentWizardStep();
        $this->post(route('registration.register.store'), array_merge($this->fixtureAnswers(), [
            '_step' => $first, '_direction' => 'next',
        ]))->assertRedirect(route('registration.register'));

        $second = $this->currentWizardStep();
        $this->assertNotSame($first, $second);

        $this->post(route('registration.register.store'), [
            '_step' => $second, '_direction' => 'back',
        ])->assertRedirect(route('registration.register'));

        $this->assertSame($first, $this->currentWizardStep());
    }

    #[TestDox('walking the wizard creates a group for the signed in user')]
    public function test_walking_the_wizard_creates_a_group_for_the_signed_in_user(): void
    {
        $this->seedForm();
        $account = $this->makeUser(['email' => 'ada@example.com', 'name' => 'Account Name']);

        $this->completeWizard($account, $this->fixtureAnswers())
            ->assertRedirect(route('registration.info'))
            ->assertSessionHas('registered', true);

        $this->assertSame(1, Group::count());

        $account->refresh();
        $this->assertTrue((bool) $account->is_group_admin);
        // The account's name is host-owned; registration never writes to it,
        // even though the questionnaire has its own "name" (first name) answer.
        $this->assertSame('Account Name', $account->name);
        $this->assertNotNull($account->group);
    }

    #[TestDox('a triggered guest is added through the hub and committed with the registration')]
    public function test_a_triggered_guest_is_added_through_the_hub_and_committed_with_the_registration(): void
    {
        $this->seedForm();
        $this->seedGuestTrigger();
        $account = $this->makeUser();

        // Submitting the guest question ("Yes") detours to the Guest List
        // hub instead of the next wizard step.
        $this->completeWizard($account, array_merge($this->fixtureAnswers(), [Question::GUEST_TRIGGER_KEY => 'Yes']))
            ->assertRedirect(route('registration.register.guests'));

        $this->get(route('registration.register.guests'))
            ->assertOk()
            ->assertSee(__('registration::common.guest_list_empty'));

        $this->post(route('registration.register.guests.store'), [
            'guest_type' => 'adult',
            'guestname' => 'My Guest',
        ])->assertRedirect(route('registration.register.guests'));

        $this->get(route('registration.register.guests'))
            ->assertOk()
            ->assertSee('My Guest');

        // Continuing the wizard from the hub resumes exactly where it left off.
        $this->completeWizard($account, array_merge($this->fixtureAnswers(), [Question::GUEST_TRIGGER_KEY => 'Yes']))
            ->assertRedirect(route('registration.info'))
            ->assertSessionHas('registered', true);

        $this->assertSame(1, Guest::count());
        $guest = Guest::first();
        $this->assertSame($account->id, $guest->user_id);
        $this->assertSame(GuestType::Adult, $guest->type);
        $this->assertSame('My Guest', $guest->registrationAnswers()->display('guestname'));
    }

    #[TestDox('answering the trigger no never offers the hub')]
    public function test_answering_the_trigger_no_never_offers_the_hub(): void
    {
        $this->seedForm();
        $this->seedGuestTrigger();
        $account = $this->makeUser();

        $this->completeWizard($account, array_merge($this->fixtureAnswers(), [Question::GUEST_TRIGGER_KEY => 'No']))
            ->assertRedirect(route('registration.info'));

        $this->assertSame(0, Guest::count());
        // The hub 404s once the registrant's (now-discarded) draft is gone —
        // the trigger was never answered "Yes", so it was never reachable.
        $this->actingAs($account)->get(route('registration.register.guests'))->assertNotFound();
    }

    #[TestDox('a triggered group member is added through the hub and invited at commit')]
    public function test_a_triggered_group_member_is_added_through_the_hub_and_invited_at_commit(): void
    {
        Mail::fake();
        $this->seedForm();
        $leader = $this->makeUser(['name' => 'Leader Name']);

        // Submitting the group question ("Yes") detours to the Group Member
        // hub instead of the next wizard step — even though (in this
        // fixture) it's the wizard's very last step.
        $this->completeWizard($leader, array_merge($this->fixtureAnswers(), [Question::GROUP_TRIGGER_KEY => 'Yes']))
            ->assertRedirect(route('registration.register.group_members'));

        $this->get(route('registration.register.group_members'))
            ->assertOk()
            ->assertSee(__('registration::common.group_member_list_empty'));

        $this->post(route('registration.register.group_members.store'), [
            'group_member_name' => 'Grace Hopper', 'group_member_email' => 'grace@example.com',
        ])->assertRedirect(route('registration.register.group_members'));

        $this->get(route('registration.register.group_members'))
            ->assertOk()
            ->assertSee('Grace Hopper');

        // Continuing the wizard from the hub resumes exactly where it left
        // off and finishes the commit.
        $this->completeWizard($leader, array_merge($this->fixtureAnswers(), [Question::GROUP_TRIGGER_KEY => 'Yes']))
            ->assertRedirect(route('registration.info'))
            ->assertSessionHas('registered', true);

        $this->assertSame(1, GroupInvite::count());
        $invite = GroupInvite::first();
        $this->assertNull($invite->consumed_at);
        $this->assertSame($leader->fresh()->group_id, $invite->group_id);
        $this->assertSame('Grace Hopper', $invite->registrationAnswers()->display('group_member_name'));

        // {leader_organization} falls back to the registrant's own name
        // (Group-scope questions, including "organization", are never part
        // of the wizard while the group flow is parked — see
        // GroupRegistrationService::createGroup()).
        Mail::assertSent(TemplatedMail::class, fn (TemplatedMail $mail) => $mail->hasTo('grace@example.com')
            && $mail->subjectLine === 'Leader Name has invited you to register'
            && str_contains($mail->bodyText, 'Leader Name of Ada Lovelace')
            && str_contains($mail->bodyText, route('registration.register.invite.accept', $invite->token))
        );
    }

    #[TestDox('answering the group trigger no never offers the hub or creates an invite')]
    public function test_answering_the_group_trigger_no_never_offers_the_hub_or_creates_an_invite(): void
    {
        Mail::fake();
        $this->seedForm();
        $account = $this->makeUser();

        $this->completeWizard($account, array_merge($this->fixtureAnswers(), [Question::GROUP_TRIGGER_KEY => 'No']))
            ->assertRedirect(route('registration.info'));

        $this->assertSame(0, GroupInvite::count());
        $this->actingAs($account)->get(route('registration.register.group_members'))->assertNotFound();
        Mail::assertNotSent(TemplatedMail::class, fn (TemplatedMail $mail) => $mail->subjectLine === __('registration::mail.group_invite_subject', ['leader_name' => '']));
    }

    #[TestDox('answering yes to guest then yes to group reaches both hubs in turn')]
    public function test_answering_yes_to_guest_then_yes_to_group_reaches_both_hubs_in_turn(): void
    {
        Mail::fake();
        $this->seedForm();
        $this->seedGuestTrigger();
        $account = $this->makeUser();

        // Guest is the earlier-positioned trigger (see seedGuestTrigger), so
        // it detours first even though group is also answered "Yes" in the
        // very same walk.
        $this->completeWizard($account, array_merge($this->fixtureAnswers(), [
            Question::GUEST_TRIGGER_KEY => 'Yes', Question::GROUP_TRIGGER_KEY => 'Yes',
        ]))->assertRedirect(route('registration.register.guests'));

        // Continuing reaches the group hub next, not a commit.
        $this->completeWizard($account, array_merge($this->fixtureAnswers(), [
            Question::GUEST_TRIGGER_KEY => 'Yes', Question::GROUP_TRIGGER_KEY => 'Yes',
        ]))->assertRedirect(route('registration.register.group_members'));

        // And finishing from there commits — both hubs were reached, in the
        // section's question order, without either one skipping the other.
        $this->completeWizard($account, array_merge($this->fixtureAnswers(), [
            Question::GUEST_TRIGGER_KEY => 'Yes', Question::GROUP_TRIGGER_KEY => 'Yes',
        ]))->assertRedirect(route('registration.info'))->assertSessionHas('registered', true);
    }

    #[TestDox('an invited member registers, joins the leaders existing group, and never sees the group question')]
    public function test_an_invited_member_registers_joins_the_leaders_existing_group_and_never_sees_the_group_question(): void
    {
        Route::get('/login', fn () => 'login')->name('login');
        $this->seedForm();
        $leader = $this->makeUser(['name' => 'Leader Name']);
        $this->completeWizard($leader, array_merge($this->fixtureAnswers(), [Question::GROUP_TRIGGER_KEY => 'Yes']))
            ->assertRedirect(route('registration.register.group_members'));
        $this->post(route('registration.register.group_members.store'), [
            'group_member_name' => 'Grace Hopper', 'group_member_email' => 'grace@example.com',
        ]);
        $this->completeWizard($leader, array_merge($this->fixtureAnswers(), [Question::GROUP_TRIGGER_KEY => 'Yes']))
            ->assertRedirect(route('registration.info'));

        $invite = GroupInvite::first();
        $leaderGroupId = $leader->fresh()->group_id;

        // The invitee signs up with a DIFFERENT email than the one the
        // leader typed for them — the token is the sole credential.
        $member = $this->makeUser(['email' => 'someone-else@example.com']);
        $this->get(route('registration.register.invite.accept', $invite->token))
            ->assertRedirect(route('login'));
        $this->assertSame($invite->token, session(GroupInviteController::SESSION_KEY));

        // The group question is excluded from the wizard entirely for the
        // member — 3 steps (your-details, accommodation, guest), not 4 —
        // not merely hidden on whichever step happens to render first.
        $this->actingAs($member)
            ->get(route('registration.register'))
            ->assertOk()
            ->assertSee('Step 1 / 3')
            ->assertDontSee('name="'.Question::GROUP_TRIGGER_KEY.'"', false);

        $this->completeWizard($member, $this->fixtureAnswers())
            ->assertRedirect(route('registration.info'))
            ->assertSessionHas('registered', true);

        $member->refresh();
        $this->assertSame($leaderGroupId, $member->group_id);
        $this->assertFalse((bool) $member->is_group_admin);
        // Still exactly one group — joining, not creating.
        $this->assertSame(1, Group::count());

        $invite->refresh();
        $this->assertNotNull($invite->consumed_at);
        $this->assertSame($member->id, $invite->user_id);

        // The tampered/never-asked group question never reaches the committed answers.
        $this->assertNull($member->registrationAnswers()->value(Question::GROUP_TRIGGER_KEY));

        $this->assertFalse(session()->has(GroupInviteController::SESSION_KEY));
    }

    #[TestDox('an unknown invite token falls back to the normal registration flow')]
    public function test_an_unknown_invite_token_falls_back_to_the_normal_registration_flow(): void
    {
        $this->seedForm();
        session([GroupInviteController::SESSION_KEY => 'not-a-real-token']);
        $account = $this->makeUser();

        $this->completeWizard($account, $this->fixtureAnswers())
            ->assertRedirect(route('registration.info'));

        $this->assertSame(1, Group::count());
        $this->assertTrue((bool) $account->fresh()->is_group_admin);
    }

    /**
     * Move the seeded guest question into an early Participant section —
     * answered before any fixture step — plus a Guest-scope name question.
     */
    private function seedGuestTrigger(): void
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

    #[TestDox('register requires authentication')]
    public function test_register_requires_authentication(): void
    {
        Route::get('/login', fn () => 'login')->name('login');

        $this->post(route('registration.register.store'), ['_step' => 1, '_direction' => 'next'])
            ->assertRedirect(route('login'));
        $this->assertSame(0, Group::count());
    }

    #[TestDox('step rejects invalid input server side')]
    public function test_step_rejects_invalid_input_server_side(): void
    {
        $this->seedForm();
        $this->actingAs($this->makeUser());

        $this->post(route('registration.register.store'), [
            '_step' => $this->currentWizardStep(),
            '_direction' => 'next',
            'name' => '',
        ])->assertSessionHasErrors('name');

        $this->assertSame(0, Group::count());
    }

    #[TestDox('tampered step id is rejected')]
    public function test_tampered_section_id_is_rejected(): void
    {
        $this->seedForm();

        $this->actingAs($this->makeUser())
            ->post(route('registration.register.store'), ['_step' => 99999, '_direction' => 'next'])
            ->assertStatus(422);
    }
}
