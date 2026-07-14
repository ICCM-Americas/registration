<?php

namespace ConferenceTools\Registration\Tests\Feature;

use ConferenceTools\Registration\Enums\QuestionScope;
use ConferenceTools\Registration\Enums\QuestionType;
use ConferenceTools\Registration\Models\Question;
use ConferenceTools\Registration\Models\Setting;
use ConferenceTools\Registration\Services\GuestQuestions;
use ConferenceTools\Registration\Services\ReportQuestions;
use ConferenceTools\Registration\Services\ShuttlePlanner;
use ConferenceTools\Registration\Tests\Concerns\BuildsReportData;
use ConferenceTools\Registration\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Feature tests for the Logistics hub: the console links and the question
 * nominations / matching-answer settings that used to live on the Reports
 * hub before the reports became admin-defined.
 */
#[TestDox('Logistics Controller')]
class LogisticsControllerTest extends TestCase
{
    use BuildsReportData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->allowRegistrationManagement();
        $this->seedReportQuestions();
    }

    #[TestDox('the logistics page requires the gate')]
    public function test_the_logistics_page_requires_the_gate(): void
    {
        $this->denyRegistrationManagement();

        $this->actingAs($this->makeUser())
            ->get(route('registration.admin.logistics'))
            ->assertForbidden();
    }

    #[TestDox('the hub links the four consoles and offers the nominations')]
    public function test_the_hub_links_the_four_consoles_and_offers_the_nominations(): void
    {
        $this->actingAs($this->makeUser())
            ->get(route('registration.admin.logistics'))
            ->assertOk()
            ->assertSee(__('registration::admin.logistics_pages'))
            ->assertSee(route('registration.admin.logistics.badges'))
            ->assertSee(route('registration.admin.rooms.assignments'))
            ->assertSee(route('registration.admin.logistics.shuttles'))
            ->assertSee(route('registration.admin.logistics.prayer_pals'))
            ->assertSee(__('registration::admin.setting_report_badge_name_key'))
            ->assertSee('badgename');
    }

    #[TestDox('the hub offers the non attending guest settings without the retired ones')]
    public function test_the_hub_offers_the_non_attending_guest_settings_without_the_retired_ones(): void
    {
        $this->actingAs($this->makeUser())
            ->get(route('registration.admin.logistics'))
            ->assertOk()
            ->assertSee(__('registration::admin.setting_guest_name_key'))
            ->assertSee(__('registration::admin.setting_guest_minors_get_badges'))
            // The photo and adults-in-directory settings retired with the
            // hard-coded photo form and directory reports; the guest trigger
            // retired into the fixed, seeded Question::GUEST_TRIGGER_KEY
            // question, no longer a nomination on this page.
            ->assertDontSee('guest_adults_in_directory')
            ->assertDontSee('guest_photo_key')
            ->assertDontSee('guest_trigger_key');
    }

    #[TestDox('question droplists are scoped to their section')]
    public function test_question_droplists_are_scoped_to_their_section(): void
    {
        // "badgename" is Participant-scope, "guestname" is Guest-scope (see
        // BuildsReportData) — each droplist must offer only its own scope's
        // questions, not the other's.
        $content = $this->actingAs($this->makeUser())
            ->get(route('registration.admin.logistics'))
            ->assertOk()
            ->getContent();

        [$participantSection, $guestSection] = explode(__('registration::admin.guest_report_settings'), $content, 2);

        $this->assertStringContainsString('>badgename<', $participantSection);
        $this->assertStringNotContainsString('>guestname<', $participantSection);

        $this->assertStringContainsString('>guestname<', $guestSection);
        $this->assertStringNotContainsString('>badgename<', $guestSection);
    }

    #[TestDox('question droplists show only the key not the label')]
    public function test_question_droplists_show_only_the_key_not_the_label(): void
    {
        // Every seeded question's label is Str::ucfirst($key) (see
        // BuildsReportData::seedReportQuestions) — the old "{label} ({key})"
        // format would render as "Badgename (badgename)".
        $this->actingAs($this->makeUser())
            ->get(route('registration.admin.logistics'))
            ->assertOk()
            ->assertDontSee('Badgename (badgename)')
            ->assertSee('>badgename<', false);
    }

    #[TestDox('a radio questions matching answer is a single select of its option values')]
    public function test_a_radio_questions_matching_answer_is_a_single_select_of_its_option_values(): void
    {
        // guestprayerpals is seeded as Radio with options yes/no, nominated
        // for PRAYER_PALS_OPT_IN_KEY.
        $response = $this->actingAs($this->makeUser())
            ->get(route('registration.admin.logistics'));

        $response->assertOk()->assertSee('<select id="guest_prayer_pals_opt_in_values"', false);
        $this->assertDoesNotMatchRegularExpression(
            '/id="guest_prayer_pals_opt_in_values"[^>]*disabled/',
            $response->getContent(),
        );
    }

    #[TestDox('a matching answer control is disabled until its question is chosen')]
    public function test_a_matching_answer_control_is_disabled_until_its_question_is_chosen(): void
    {
        Setting::put(GuestQuestions::PRAYER_PALS_OPT_IN_KEY, null);

        $response = $this->actingAs($this->makeUser())->get(route('registration.admin.logistics'));

        $response->assertOk();
        $this->assertMatchesRegularExpression(
            '/id="guest_prayer_pals_opt_in_values"[^>]*disabled/',
            $response->getContent(),
        );
    }

    #[TestDox('a select questions matching answer is a move between list')]
    public function test_a_select_questions_matching_answer_is_a_move_between_list(): void
    {
        $question = Question::factory()->ofType(QuestionType::Select)->create(['key' => 'roomtype', 'label' => 'Room type']);
        $question->options()->create(['value' => 'single', 'label' => 'Single', 'position' => 0]);
        $question->options()->create(['value' => 'double', 'label' => 'Double', 'position' => 1]);
        // roomtype must be Guest-scope: the only remaining nomination with a
        // paired value-list setting (PRAYER_PALS_OPT_IN_VALUES) is Guest-scope.
        $question->section->update(['scope' => QuestionScope::Guest]);
        Setting::put(GuestQuestions::PRAYER_PALS_OPT_IN_KEY, 'roomtype');

        $this->actingAs($this->makeUser())
            ->get(route('registration.admin.logistics'))
            ->assertOk()
            ->assertSee('js-dual-available', false)
            ->assertSee('js-dual-chosen', false)
            // Only the option *value* is ever shown, never its label.
            ->assertSee('>single<', false)
            ->assertDontSee('>Single<', false);
    }

    #[TestDox('saving rejects a matching answer no longer among the questions options')]
    public function test_saving_rejects_a_matching_answer_no_longer_among_the_questions_options(): void
    {
        $this->actingAs($this->makeUser())
            ->put(route('registration.admin.logistics.settings'), [
                GuestQuestions::PRAYER_PALS_OPT_IN_KEY => 'guestprayerpals',
                GuestQuestions::PRAYER_PALS_OPT_IN_VALUES => 'yes',
            ])
            ->assertRedirect(route('registration.admin.logistics'))
            ->assertSessionDoesntHaveErrors(GuestQuestions::PRAYER_PALS_OPT_IN_VALUES);

        $this->actingAs($this->makeUser())
            ->put(route('registration.admin.logistics.settings'), [
                GuestQuestions::PRAYER_PALS_OPT_IN_KEY => 'guestprayerpals',
                GuestQuestions::PRAYER_PALS_OPT_IN_VALUES => 'maybe',
            ])
            ->assertSessionHasErrors(GuestQuestions::PRAYER_PALS_OPT_IN_VALUES);

        // The rejected save left the last-known-good value in place.
        $this->assertSame('yes', Setting::get(GuestQuestions::PRAYER_PALS_OPT_IN_VALUES));
    }

    #[TestDox('settings are saved and bad question keys rejected')]
    public function test_settings_are_saved_and_bad_question_keys_rejected(): void
    {
        $this->actingAs($this->makeUser())
            ->put(route('registration.admin.logistics.settings'), [
                ReportQuestions::BADGE_NAME_KEY => 'nickname',
                'shuttle_seats' => 7,
                'shuttle_travel_minutes' => 40,
                'shuttle_count' => 3,
            ])->assertRedirect(route('registration.admin.logistics'));

        $this->assertSame('nickname', Setting::get(ReportQuestions::BADGE_NAME_KEY));
        $this->assertSame(7, app(ShuttlePlanner::class)->seats());
        $this->assertSame(40, app(ShuttlePlanner::class)->travelMinutes());
        $this->assertSame(3, app(ShuttlePlanner::class)->shuttleCount());

        $this->actingAs($this->makeUser())
            ->put(route('registration.admin.logistics.settings'), [
                ReportQuestions::BADGE_NAME_KEY => 'not-a-question',
            ])->assertSessionHasErrors(ReportQuestions::BADGE_NAME_KEY);
    }

    #[TestDox('saving rejects a question key from the wrong scope')]
    public function test_saving_rejects_a_question_key_from_the_wrong_scope(): void
    {
        // "guestname" is Guest-scope, "badgename" is Participant-scope (see
        // BuildsReportData) — neither may nominate a question from the other's scope.
        $this->actingAs($this->makeUser())
            ->put(route('registration.admin.logistics.settings'), [
                ReportQuestions::BADGE_NAME_KEY => 'guestname',
            ])->assertSessionHasErrors(ReportQuestions::BADGE_NAME_KEY);

        $this->actingAs($this->makeUser())
            ->put(route('registration.admin.logistics.settings'), [
                GuestQuestions::BADGE_NAME_KEY => 'badgename',
            ])->assertSessionHasErrors(GuestQuestions::BADGE_NAME_KEY);
    }

    #[TestDox('non attending guest settings are saved')]
    public function test_non_attending_guest_settings_are_saved(): void
    {
        $this->actingAs($this->makeUser())
            ->put(route('registration.admin.logistics.settings'), [
                GuestQuestions::NAME_KEY => 'guestname',
                'guest_minors_get_badges' => '1',
            ])->assertRedirect(route('registration.admin.logistics'));

        $guestQuestions = app(GuestQuestions::class);
        $this->assertSame('guestname', $guestQuestions->questionKey(GuestQuestions::NAME_KEY));
        $this->assertTrue($guestQuestions->minorsGetBadges());
    }

    #[TestDox('clearing a matching answer control persists as no values')]
    public function test_clearing_a_matching_answer_control_persists_as_no_values(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)
            ->put(route('registration.admin.logistics.settings'), [
                GuestQuestions::PRAYER_PALS_OPT_IN_VALUES => 'never',
            ])->assertRedirect(route('registration.admin.logistics'));
        $this->assertSame('never', Setting::get(GuestQuestions::PRAYER_PALS_OPT_IN_VALUES));

        // Emptying the field (the dual-list's hidden input submits '') must not
        // revert the setting to its built-in default on the next load.
        $this->actingAs($user)
            ->put(route('registration.admin.logistics.settings'), [
                GuestQuestions::PRAYER_PALS_OPT_IN_VALUES => '',
            ])->assertRedirect(route('registration.admin.logistics'));

        $this->assertSame([], app(GuestQuestions::class)->valueList(GuestQuestions::PRAYER_PALS_OPT_IN_VALUES));
    }

    #[TestDox('the stale answer banner links every admin page to logistics')]
    public function test_the_stale_answer_banner_links_every_admin_page_to_logistics(): void
    {
        // guestprayerpals has options yes/no — "maybe" no longer matches.
        Setting::put(GuestQuestions::PRAYER_PALS_OPT_IN_VALUES, 'yes, maybe');

        $this->actingAs($this->makeUser())
            ->get(route('registration.admin.dashboard'))
            ->assertOk()
            ->assertSee(__('registration::admin.window_report_answers_stale'))
            ->assertSee(route('registration.admin.logistics'));
    }
}
