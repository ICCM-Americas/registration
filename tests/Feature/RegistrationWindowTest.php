<?php

namespace ConferenceTools\Registration\Tests\Feature;

use ConferenceTools\Branding\Contracts\BrandingProvider;
use ConferenceTools\Registration\Models\ClosedMessage;
use ConferenceTools\Registration\Models\Setting;
use ConferenceTools\Registration\Services\ConferenceEdition;
use ConferenceTools\Registration\Services\GuestQuestions;
use ConferenceTools\Registration\Services\RegistrationEmails;
use ConferenceTools\Registration\Services\RegistrationStatus;
use ConferenceTools\Registration\Services\ReportQuestions;
use ConferenceTools\Registration\Tests\Concerns\BuildsReportData;
use ConferenceTools\Registration\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * The registration window: when registration is open to registrants (the
 * admin-set [opens_at, closes_at) dates plus the email-configuration and
 * report-answers guards), the closed page on the registrant-facing routes,
 * and the dashboard controls that schedule or manually open/close the window.
 */
#[TestDox('Registration Window')]
class RegistrationWindowTest extends TestCase
{
    // BuildsReportData pulls in BuildsRegistrationData too — this suite needs
    // the report-question fixtures for the stale-matching-answer guard tests.
    use BuildsReportData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->allowRegistrationManagement();
    }

    /** Set the registration window to the given bounds. */
    private function window(): RegistrationStatus
    {
        return app(RegistrationStatus::class);
    }

    /**
     * Window dates (as minute offsets from now) with the emails and required
     * name questions configured.
     */
    private function schedule(?int $opensOffset, ?int $closesOffset): void
    {
        Setting::put(RegistrationEmails::ADMIN_EMAIL, 'admin@example.com');
        $this->nominateRequiredNameQuestions();

        $this->window()->schedule(
            $opensOffset === null ? null : now()->addMinutes($opensOffset),
            $closesOffset === null ? null : now()->addMinutes($closesOffset),
        );
    }

    /** Nominate the name questions registration requires. */
    private function nominateRequiredNameQuestions(): void
    {
        Setting::put(ReportQuestions::BADGE_NAME_KEY, 'badgename');
        Setting::put(ReportQuestions::FIRSTNAME_KEY, 'firstname');
        Setting::put(ReportQuestions::LASTNAME_KEY, 'lastname');
    }

    /** The windows for the data provider. */
    public static function windows(): array
    {
        // [opens_at offset, closes_at offset] in minutes from now; null = unset.
        return [
            'no dates set' => [null, null, false],
            'opened, no close date' => [-60, null, true],
            'inside the window' => [-60, 60, true],
            'opens in the future' => [60, null, false],
            'window already passed' => [-60, -30, false],
            'close date without an open date' => [null, 60, false],
        ];
    }

    #[DataProvider('windows')]
    #[TestDox('the window dates decide whether registration is open')]
    public function test_the_window_dates_decide_whether_registration_is_open(
        ?int $opensOffset, ?int $closesOffset, bool $expectedOpen,
    ): void {
        $this->schedule($opensOffset, $closesOffset);

        $this->assertSame($expectedOpen, $this->window()->isOpen());
        // With the emails configured the guard never trips.
        $this->assertFalse($this->window()->misconfigured());
    }

    /** The from addresses for the data provider. */
    public static function fromAddresses(): array
    {
        // [from_email setting, mail.from.address, configured?] — the
        // configured address wins, the host mail from address is the fallback.
        return [
            'configured from address set' => ['no-reply@conf.test', null, true],
            'host mail from as fallback' => [null, 'host@conf.test', true],
            'no from address anywhere' => [null, null, false],
        ];
    }

    #[DataProvider('fromAddresses')]
    #[TestDox('the email guard requires an effective from address')]
    public function test_the_email_guard_requires_an_effective_from_address(
        ?string $fromEmail, ?string $mailFrom, bool $expected,
    ): void {
        app(RegistrationEmails::class)->update('admin@example.com', $fromEmail);
        config(['mail.from.address' => $mailFrom]);

        $this->assertSame($expected, $this->window()->emailsConfigured());
    }

    #[TestDox('the email guard requires the admin address and holds an arrived window closed')]
    public function test_the_email_guard_requires_the_admin_address_and_holds_an_arrived_window_closed(): void
    {
        $this->schedule(-60, null);
        Setting::put(RegistrationEmails::ADMIN_EMAIL, null);

        $this->assertFalse($this->window()->emailsConfigured());
        $this->assertTrue($this->window()->withinWindow());
        $this->assertFalse($this->window()->isOpen());
        $this->assertTrue($this->window()->misconfigured());
    }

    #[TestDox('opening now is refused while the emails are unconfigured')]
    public function test_opening_now_is_refused_while_the_emails_are_unconfigured(): void
    {
        // No administrator address is stored (the settings default).
        $this->assertFalse($this->window()->open());
        $this->assertFalse($this->window()->isOpen());
        $this->assertNull($this->window()->opensAt());
    }

    /** The required name settings for the data provider. */
    public static function requiredNameSettings(): array
    {
        return [
            'badge name' => [ReportQuestions::BADGE_NAME_KEY],
            'first name' => [ReportQuestions::FIRSTNAME_KEY],
            'last name' => [ReportQuestions::LASTNAME_KEY],
        ];
    }

    /** None of the three nominations has a fallback — each is independently required. */
    #[DataProvider('requiredNameSettings')]
    #[TestDox('a missing required name question holds an arrived window closed')]
    public function test_a_missing_required_name_question_holds_an_arrived_window_closed(string $setting): void
    {
        $this->schedule(-60, null);
        Setting::put($setting, null);

        $this->assertFalse($this->window()->requiredNameQuestionsConfigured());
        $this->assertTrue($this->window()->withinWindow());
        $this->assertFalse($this->window()->isOpen());
        $this->assertTrue($this->window()->requiredNameQuestionsMissing());
    }

    #[TestDox('opening now is refused while a required name question is unconfigured')]
    public function test_opening_now_is_refused_while_a_required_name_question_is_unconfigured(): void
    {
        Setting::put(RegistrationEmails::ADMIN_EMAIL, 'admin@example.com');
        // No badge/first/last-name questions are nominated (the settings default).

        $this->assertFalse($this->window()->open());
        $this->assertFalse($this->window()->isOpen());
    }

    #[TestDox('every admin page warns while a required name question is missing')]
    public function test_every_admin_page_warns_while_a_required_name_question_is_missing(): void
    {
        $this->schedule(-60, null);
        Setting::put(ReportQuestions::BADGE_NAME_KEY, null);

        $this->actingAs($this->makeUser())
            ->get(route('registration.admin.emails'))
            ->assertOk()
            ->assertSee(__('registration::admin.window_name_questions_required'));

        $this->actingAs($this->makeUser())
            ->get(route('registration.admin.dashboard'))
            ->assertOk()
            ->assertSee(__('registration::admin.window_name_questions_required'))
            ->assertSee(__('registration::admin.window_status_closed'));
    }

    #[TestDox('the open button flashes the name questions message when that guard trips')]
    public function test_the_open_button_flashes_the_name_questions_message_when_that_guard_trips(): void
    {
        Setting::put(RegistrationEmails::ADMIN_EMAIL, 'admin@example.com');
        // No badge/first/last-name questions are nominated (the settings default).

        $this->actingAs($this->makeUser())
            ->post(route('registration.admin.window.open'))
            ->assertRedirect(route('registration.admin.dashboard'))
            ->assertSessionHas('window_error', __('registration::admin.window_name_questions_required'));

        $this->assertFalse($this->window()->isOpen());
    }

    #[TestDox('opening now opens and clears a stale close date')]
    public function test_opening_now_opens_and_clears_a_stale_close_date(): void
    {
        // The window already passed; "open now" must not be undone by it.
        $this->schedule(-60, -30);

        $this->assertTrue($this->window()->open());
        $this->assertTrue($this->window()->isOpen());
        $this->assertNull($this->window()->closesAt());
    }

    #[TestDox('opening now keeps a scheduled future close date')]
    public function test_opening_now_keeps_a_scheduled_future_close_date(): void
    {
        $this->schedule(60, 120);
        $scheduledClose = $this->window()->closesAt();

        $this->assertTrue($this->window()->open());
        $this->assertTrue($this->window()->isOpen());
        $this->assertTrue($this->window()->closesAt()->equalTo($scheduledClose));
    }

    #[TestDox('closing now closes and keeps the open date')]
    public function test_closing_now_closes_and_keeps_the_open_date(): void
    {
        $this->schedule(-60, null);
        $this->assertTrue($this->window()->isOpen());

        $this->window()->close();

        $this->assertFalse($this->window()->isOpen());
        $this->assertNotNull($this->window()->opensAt());
    }

    #[TestDox('settings are plain key value rows and storing null clears one')]
    public function test_settings_are_plain_key_value_rows_and_storing_null_clears_one(): void
    {
        Setting::factory()->create(['key' => 'demo', 'value' => 'val']);

        $this->assertSame('val', Setting::get('demo'));
        $this->assertNull(Setting::get('missing'));

        Setting::put('demo', null);
        $this->assertSame(0, Setting::count());
    }

    #[TestDox('scheduling null dates clears the stored settings')]
    public function test_scheduling_null_dates_clears_the_stored_settings(): void
    {
        // schedule() also stores the admin address and name-question
        // nominations the guards need; only the two window-date rows are
        // cleared below.
        $this->schedule(-60, 60);
        $this->assertSame(6, Setting::count());

        $this->window()->schedule(null, null);

        $this->assertSame(4, Setting::count());
        $this->assertNull($this->window()->opensAt());
        $this->assertNull($this->window()->closesAt());
        $this->assertFalse($this->window()->isOpen());
    }

    #[TestDox('the registrant routes answer with the closed page while closed')]
    public function test_the_registrant_routes_answer_with_the_closed_page_while_closed(): void
    {
        $this->defaultCurrency();

        // No window scheduled at all: the generic closed message applies.
        $this->get(route('registration.info'))
            ->assertOk()
            ->assertSee(ClosedMessage::DEFAULTS[ClosedMessage::CLOSED]);

        $this->actingAs($this->makeUser())
            ->get(route('registration.register'))
            ->assertOk()
            ->assertSee(ClosedMessage::DEFAULTS[ClosedMessage::CLOSED]);
    }

    #[TestDox('a misconfigured arrived window behaves as closed for registrants')]
    public function test_a_misconfigured_arrived_window_behaves_as_closed_for_registrants(): void
    {
        $this->defaultCurrency();
        $this->schedule(-60, null);
        Setting::put(RegistrationEmails::ADMIN_EMAIL, null);

        // The window has arrived but the email guard holds it: visitors see
        // the "opening soon" message, not the generic closed one.
        $this->get(route('registration.info'))
            ->assertOk()
            ->assertSee(ClosedMessage::DEFAULTS[ClosedMessage::OPENING_SOON]);
    }

    #[TestDox('the registrant routes open up inside the window')]
    public function test_the_registrant_routes_open_up_inside_the_window(): void
    {
        $this->defaultCurrency();
        $this->schedule(-60, 60);

        $this->get(route('registration.info'))
            ->assertOk()
            // The welcome names the site through the branding seam.
            ->assertSee(__('registration::info.welcome', ['name' => app(BrandingProvider::class)->siteName()]))
            ->assertDontSee(__('registration::info.closed_title'));
    }

    #[TestDox('the dashboard shows the window state and stored dates')]
    public function test_the_dashboard_shows_the_window_state_and_stored_dates(): void
    {
        $this->schedule(-60, null);

        $this->actingAs($this->makeUser())
            ->get(route('registration.admin.dashboard'))
            ->assertOk()
            ->assertSee(__('registration::admin.window_title'))
            ->assertSee(__('registration::admin.window_status_open'))
            ->assertSee($this->window()->opensAt()->format('Y-m-d\TH:i'));

        $this->window()->close();

        $this->actingAs($this->makeUser())
            ->get(route('registration.admin.dashboard'))
            ->assertOk()
            ->assertSee(__('registration::admin.window_status_closed'));
    }

    #[TestDox('saving the window stores the dates and confirms')]
    public function test_saving_the_window_stores_the_dates_and_confirms(): void
    {
        Setting::put(RegistrationEmails::ADMIN_EMAIL, 'admin@example.com');

        $this->actingAs($this->makeUser())
            ->put(route('registration.admin.window.update'), [
                'opens_at' => '2026-07-01T09:00',
                'closes_at' => '2026-09-01T17:00',
            ])
            ->assertRedirect(route('registration.admin.dashboard'))
            ->assertSessionHas('window_status', __('registration::admin.window_saved'));

        $this->assertSame('2026-07-01 09:00:00', $this->window()->opensAt()->toDateTimeString());
        $this->assertSame('2026-09-01 17:00:00', $this->window()->closesAt()->toDateTimeString());
    }

    #[TestDox('saving the window with empty dates clears them')]
    public function test_saving_the_window_with_empty_dates_clears_them(): void
    {
        $this->schedule(-60, 60);

        $this->actingAs($this->makeUser())
            ->put(route('registration.admin.window.update'), ['opens_at' => '', 'closes_at' => ''])
            ->assertRedirect(route('registration.admin.dashboard'));

        $this->assertNull($this->window()->opensAt());
        $this->assertNull($this->window()->closesAt());
    }

    #[TestDox('the close date must fall after the open date')]
    public function test_the_close_date_must_fall_after_the_open_date(): void
    {
        $this->actingAs($this->makeUser())
            ->put(route('registration.admin.window.update'), [
                'opens_at' => '2026-09-01T09:00',
                'closes_at' => '2026-07-01T09:00',
            ])
            ->assertSessionHasErrors('closes_at');

        $this->assertNull($this->window()->opensAt());
    }

    #[TestDox('the open button opens registration when the emails are configured')]
    public function test_the_open_button_opens_registration_when_the_emails_are_configured(): void
    {
        Setting::put(RegistrationEmails::ADMIN_EMAIL, 'admin@example.com');
        $this->nominateRequiredNameQuestions();

        $this->actingAs($this->makeUser())
            ->post(route('registration.admin.window.open'))
            ->assertRedirect(route('registration.admin.dashboard'))
            ->assertSessionHas('window_status', __('registration::admin.window_opened'));

        $this->assertTrue($this->window()->isOpen());
    }

    #[TestDox('the open button flashes an error and stays closed without the addresses')]
    public function test_the_open_button_flashes_an_error_and_stays_closed_without_the_addresses(): void
    {
        $this->nominateRequiredNameQuestions();

        $this->actingAs($this->makeUser())
            ->post(route('registration.admin.window.open'))
            ->assertRedirect(route('registration.admin.dashboard'))
            ->assertSessionHas('window_error', __('registration::admin.window_email_required'));

        $this->assertFalse($this->window()->isOpen());
    }

    #[TestDox('the close button closes registration')]
    public function test_the_close_button_closes_registration(): void
    {
        $this->schedule(-60, null);

        $this->actingAs($this->makeUser())
            ->post(route('registration.admin.window.close'))
            ->assertRedirect(route('registration.admin.dashboard'))
            ->assertSessionHas('window_status', __('registration::admin.window_closed'));

        $this->assertFalse($this->window()->isOpen());
    }

    #[TestDox('every admin page warns while the arrived window is held closed')]
    public function test_every_admin_page_warns_while_the_arrived_window_is_held_closed(): void
    {
        $this->schedule(-60, null);
        Setting::put(RegistrationEmails::ADMIN_EMAIL, null);

        // The banner lives in the shared admin nav, so one page stands in for
        // all of them; the dashboard additionally shows the closed status.
        $this->actingAs($this->makeUser())
            ->get(route('registration.admin.emails'))
            ->assertOk()
            ->assertSee(__('registration::admin.window_email_required'));

        $this->actingAs($this->makeUser())
            ->get(route('registration.admin.dashboard'))
            ->assertOk()
            ->assertSee(__('registration::admin.window_email_required'))
            ->assertSee(__('registration::admin.window_status_closed'));
    }

    #[TestDox('no warning banner when the window is healthy')]
    public function test_no_warning_banner_when_the_window_is_healthy(): void
    {
        $this->schedule(-60, null);

        $this->actingAs($this->makeUser())
            ->get(route('registration.admin.dashboard'))
            ->assertOk()
            ->assertDontSee(__('registration::admin.window_email_required'));
    }

    #[TestDox('opening now is refused while a report matching answer is stale')]
    public function test_opening_now_is_refused_while_a_report_matching_answer_is_stale(): void
    {
        Setting::put(RegistrationEmails::ADMIN_EMAIL, 'admin@example.com');
        $this->seedReportQuestions();
        // photopermission is seeded as Radio with options yes/no.
        Setting::put(GuestQuestions::PRAYER_PALS_OPT_IN_VALUES, 'yes, maybe');

        $this->assertTrue($this->window()->reportAnswersStale());
        $this->assertFalse($this->window()->open());
        $this->assertFalse($this->window()->isOpen());
    }

    #[TestDox('every admin page warns while a report matching answer is stale regardless of the window')]
    public function test_every_admin_page_warns_while_a_report_matching_answer_is_stale_regardless_of_the_window(): void
    {
        $this->seedReportQuestions();
        Setting::put(GuestQuestions::PRAYER_PALS_OPT_IN_VALUES, 'yes, maybe');

        // No window is scheduled at all — the banner still shows, since a
        // stale matching answer is a configuration problem, not a scheduling one.
        $this->actingAs($this->makeUser())
            ->get(route('registration.admin.dashboard'))
            ->assertOk()
            ->assertSee(__('registration::admin.window_report_answers_stale'));
    }

    #[TestDox('the open button flashes the report answers message when that guard trips')]
    public function test_the_open_button_flashes_the_report_answers_message_when_that_guard_trips(): void
    {
        Setting::put(RegistrationEmails::ADMIN_EMAIL, 'admin@example.com');
        $this->seedReportQuestions();
        Setting::put(GuestQuestions::PRAYER_PALS_OPT_IN_VALUES, 'yes, maybe');

        $this->actingAs($this->makeUser())
            ->post(route('registration.admin.window.open'))
            ->assertRedirect(route('registration.admin.dashboard'))
            ->assertSessionHas('window_error', __('registration::admin.window_report_answers_stale'));

        $this->assertFalse($this->window()->isOpen());
    }

    #[TestDox('the window routes require the gate')]
    public function test_the_window_routes_require_the_gate(): void
    {
        $this->denyRegistrationManagement();

        $this->actingAs($this->makeUser())
            ->post(route('registration.admin.window.open'))
            ->assertForbidden();
    }

    /** The closed states for the data provider. */
    public static function closedStates(): array
    {
        // [opens_at offset, closes_at offset, emails configured?] => the
        // closed-page message key for that window state.
        return [
            'no dates scheduled' => [null, null, true, ClosedMessage::CLOSED],
            'open date still ahead' => [60, null, true, ClosedMessage::BEFORE_OPEN],
            'next window scheduled after a close' => [60, -30, true, ClosedMessage::BEFORE_OPEN],
            'window arrived but emails missing' => [-60, null, false, ClosedMessage::OPENING_SOON],
            'window passed' => [-60, -30, true, ClosedMessage::AFTER_CLOSE],
            'past close date without an open date' => [null, -30, true, ClosedMessage::AFTER_CLOSE],
            // A future close with no open date never opened and has not ended:
            // it falls through to the generic closed message.
            'future close date without an open date' => [null, 60, true, ClosedMessage::CLOSED],
        ];
    }

    #[DataProvider('closedStates')]
    #[TestDox('the window state selects the closed page message')]
    public function test_the_window_state_selects_the_closed_page_message(
        ?int $opensOffset, ?int $closesOffset, bool $emailsConfigured, string $expectedKey,
    ): void {
        $this->schedule($opensOffset, $closesOffset);
        if (! $emailsConfigured) {
            Setting::put(RegistrationEmails::ADMIN_EMAIL, null);
        }

        $this->assertSame($expectedKey, $this->window()->closedMessageKey());
    }

    #[TestDox('the before open message names the open date time and timezone')]
    public function test_the_before_open_message_names_the_open_date_time_and_timezone(): void
    {
        $this->defaultCurrency();
        Setting::put(RegistrationEmails::ADMIN_EMAIL, 'admin@example.com');
        $this->window()->schedule(now()->parse('2027-03-05 09:30:00'), null);

        // The default message's {opens_*} tokens resolve against the schedule
        // (the app timezone is UTC in the test environment).
        $this->get(route('registration.info'))
            ->assertOk()
            ->assertSee('Registration will open March 5, 2027 at 9:30 AM (UTC).');
    }

    #[TestDox('the after close message names the conference and links the site')]
    public function test_the_after_close_message_names_the_conference_and_links_the_site(): void
    {
        $this->defaultCurrency();
        app(ConferenceEdition::class)->update('ICCM Americas', '2026');
        $this->schedule(-60, -30);

        // The default message resolves the closed conference edition and the
        // branding site URL, which the closed page renders as a real link.
        $this->get(route('registration.info'))
            ->assertOk()
            ->assertSee('Registration for ICCM Americas 2026 has ended', false)
            ->assertSee('<a href="'.url('/').'" target="_blank" rel="noopener">', false);
    }

    #[TestDox('an admin edited closed message replaces the default')]
    public function test_an_admin_edited_closed_message_replaces_the_default(): void
    {
        $this->defaultCurrency();
        ClosedMessage::forKey(ClosedMessage::CLOSED)->update(['body' => 'See you next year!']);

        $this->get(route('registration.info'))
            ->assertOk()
            ->assertSee('See you next year!')
            ->assertDontSee(ClosedMessage::DEFAULTS[ClosedMessage::CLOSED]);
    }

    #[TestDox('the closed message is translated for the visitor locale')]
    public function test_the_closed_message_is_translated_for_the_visitor_locale(): void
    {
        $this->defaultCurrency();
        ClosedMessage::forKey(ClosedMessage::CLOSED)
            ->storeTranslation('fr', 'body', 'Les inscriptions sont fermées.');

        app()->setLocale('fr');

        $this->get(route('registration.info'))
            ->assertOk()
            ->assertSee('Les inscriptions sont fermées.');
    }
}
