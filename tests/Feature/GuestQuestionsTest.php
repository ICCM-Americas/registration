<?php

namespace ConferenceTools\Registration\Tests\Feature;

use ConferenceTools\Registration\Enums\GuestType;
use ConferenceTools\Registration\Enums\QuestionScope;
use ConferenceTools\Registration\Enums\QuestionType;
use ConferenceTools\Registration\Models\Guest;
use ConferenceTools\Registration\Models\Question;
use ConferenceTools\Registration\Models\Section;
use ConferenceTools\Registration\Models\Setting;
use ConferenceTools\Registration\Services\AnswerStore;
use ConferenceTools\Registration\Services\GuestQuestions;
use ConferenceTools\Registration\Services\ReportQuestions;
use ConferenceTools\Registration\Tests\Concerns\BuildsRegistrationData;
use ConferenceTools\Registration\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Which nominated questions feed the non-attending guest feature: the
 * Guest-scope questions (name, Prayer Pals opt-in, …) that interpret a
 * guest's own answers. The Guest List hub's own trigger is the fixed,
 * seeded Question::GUEST_TRIGGER_KEY question, not a nomination — see
 * RegistrationControllerTest for its coverage.
 */
#[TestDox('Guest Questions')]
class GuestQuestionsTest extends TestCase
{
    use BuildsRegistrationData, RefreshDatabase;

    /** The GuestQuestions service under test. */
    private function guestQuestions(): GuestQuestions
    {
        return app(GuestQuestions::class);
    }

    /** A Guest-scope section. */
    private function guestSection(): Section
    {
        return Section::create([
            'scope' => QuestionScope::Guest->value, 'key' => 'guest-extras', 'title' => 'Guest Extras', 'position' => 0, 'enabled' => true,
        ]);
    }

    #[TestDox('display name reads the nominated guest scope question')]
    public function test_display_name_reads_the_nominated_guest_scope_question(): void
    {
        $section = $this->guestSection();
        Question::create([
            'section_id' => $section->id, 'key' => 'guestname', 'type' => QuestionType::Text->value,
            'label' => 'Guest name', 'position' => 0, 'required' => false, 'enabled' => true,
        ]);
        $this->guestQuestions()->update(['guest_name_key' => 'guestname']);

        $guest = Guest::factory()->create(['type' => GuestType::Adult]);
        app(AnswerStore::class)->store(QuestionScope::Guest, $guest, ['guestname' => 'Stay Home']);

        $this->assertSame('Stay Home', $this->guestQuestions()->displayName($guest->fresh()));
    }

    #[TestDox('display name is null while unconfigured')]
    public function test_display_name_is_null_while_unconfigured(): void
    {
        $guest = Guest::factory()->create();

        $this->assertNull($this->guestQuestions()->displayName($guest));
    }

    #[TestDox('badge name falls back to the display name while unconfigured or unanswered')]
    public function test_badge_name_falls_back_to_the_display_name_while_unconfigured_or_unanswered(): void
    {
        $section = $this->guestSection();
        Question::create([
            'section_id' => $section->id, 'key' => 'guestname', 'type' => QuestionType::Text->value,
            'label' => 'Guest name', 'position' => 0, 'required' => false, 'enabled' => true,
        ]);
        Question::create([
            'section_id' => $section->id, 'key' => 'guestbadgename', 'type' => QuestionType::Text->value,
            'label' => 'Guest badge name', 'position' => 1, 'required' => false, 'enabled' => true,
        ]);
        $this->guestQuestions()->update(['guest_name_key' => 'guestname']);

        $guest = Guest::factory()->create(['type' => GuestType::Adult]);
        app(AnswerStore::class)->store(QuestionScope::Guest, $guest, ['guestname' => 'Formal Name']);
        $guest = $guest->fresh();

        // Unnominated: falls back to the display name.
        $this->assertSame('Formal Name', $this->guestQuestions()->badgeName($guest));

        $this->guestQuestions()->update(['guest_badge_name_key' => 'guestbadgename']);

        // Nominated but unanswered: still falls back.
        $this->assertSame('Formal Name', $this->guestQuestions()->badgeName($guest->fresh()));

        app(AnswerStore::class)->store(QuestionScope::Guest, $guest, ['guestname' => 'Formal Name', 'guestbadgename' => 'Nickname']);

        // Nominated and answered: takes precedence.
        $this->assertSame('Nickname', $this->guestQuestions()->badgeName($guest->fresh()));
    }

    #[TestDox('occupant full name reads the guest display name, not the badge name')]
    public function test_occupant_full_name_reads_the_guest_display_name_not_the_badge_name(): void
    {
        $section = $this->guestSection();
        Question::create([
            'section_id' => $section->id, 'key' => 'guestname', 'type' => QuestionType::Text->value,
            'label' => 'Guest name', 'position' => 0, 'required' => false, 'enabled' => true,
        ]);
        Question::create([
            'section_id' => $section->id, 'key' => 'guestbadgename', 'type' => QuestionType::Text->value,
            'label' => 'Guest badge name', 'position' => 1, 'required' => false, 'enabled' => true,
        ]);
        $this->guestQuestions()->update(['guest_name_key' => 'guestname', 'guest_badge_name_key' => 'guestbadgename']);

        $guest = Guest::factory()->create(['type' => GuestType::Adult]);
        app(AnswerStore::class)->store(QuestionScope::Guest, $guest, ['guestname' => 'Formal Name', 'guestbadgename' => 'Nickname']);

        $this->assertSame('Formal Name', $this->guestQuestions()->occupantFullName($guest->fresh(), app(ReportQuestions::class)));
    }

    #[TestDox('the minors get badges toggle defaults off and round trips')]
    public function test_the_minors_get_badges_toggle_defaults_off_and_round_trips(): void
    {
        $guestQuestions = $this->guestQuestions();
        $this->assertFalse($guestQuestions->minorsGetBadges());

        $guestQuestions->updateMinorsGetBadges(true);
        $this->assertTrue($guestQuestions->minorsGetBadges());

        $guestQuestions->updateMinorsGetBadges(false);
        $this->assertFalse($guestQuestions->minorsGetBadges());
    }

    #[TestDox('value lists normalize and fall back to defaults while raw lists preserve case')]
    public function test_value_lists_normalize_and_fall_back_to_defaults_while_raw_lists_preserve_case(): void
    {
        $this->assertSame(['yes'], $this->guestQuestions()->valueList(GuestQuestions::PRAYER_PALS_OPT_IN_VALUES));

        Setting::put(GuestQuestions::PRAYER_PALS_OPT_IN_VALUES, ' Gladly ,, YES ');
        $this->assertSame(['gladly', 'yes'], $this->guestQuestions()->valueList(GuestQuestions::PRAYER_PALS_OPT_IN_VALUES));
        $this->assertSame(['Gladly', 'YES'], $this->guestQuestions()->rawValueList(GuestQuestions::PRAYER_PALS_OPT_IN_VALUES));
    }

    #[TestDox('stale values are answers no longer among the questions current options')]
    public function test_stale_values_are_answers_no_longer_among_the_questions_current_options(): void
    {
        $this->nominatePrayerPalsQuestion();

        $this->assertSame([], $this->guestQuestions()->staleValues(GuestQuestions::PRAYER_PALS_OPT_IN_VALUES));
        $this->assertFalse($this->guestQuestions()->hasStaleMatches());

        Setting::put(GuestQuestions::PRAYER_PALS_OPT_IN_VALUES, 'yes, maybe');
        $this->assertSame(['maybe'], $this->guestQuestions()->staleValues(GuestQuestions::PRAYER_PALS_OPT_IN_VALUES));
        $this->assertTrue($this->guestQuestions()->hasStaleMatches());
    }

    #[TestDox('stale values are empty while the question is unset or not options based')]
    public function test_stale_values_are_empty_while_the_question_is_unset_or_not_options_based(): void
    {
        Setting::put(GuestQuestions::PRAYER_PALS_OPT_IN_VALUES, 'anything at all');
        $this->assertSame([], $this->guestQuestions()->staleValues(GuestQuestions::PRAYER_PALS_OPT_IN_VALUES));

        // A free-text question's nomination can't go "stale" this way.
        $section = $this->guestSection();
        Question::create([
            'section_id' => $section->id, 'key' => 'guestnotes', 'type' => QuestionType::Text->value,
            'label' => 'Notes', 'position' => 0, 'required' => false, 'enabled' => true,
        ]);
        $this->guestQuestions()->update(['guest_prayer_pals_key' => 'guestnotes']);
        $this->assertSame([], $this->guestQuestions()->staleValues(GuestQuestions::PRAYER_PALS_OPT_IN_VALUES));
        $this->assertFalse($this->guestQuestions()->hasStaleMatches());
    }

    #[TestDox('update clears a value list setting without reverting to its default')]
    public function test_update_clears_a_value_list_setting_without_reverting_to_its_default(): void
    {
        $this->guestQuestions()->update([GuestQuestions::PRAYER_PALS_OPT_IN_VALUES => 'gladly']);
        $this->assertSame(['gladly'], $this->guestQuestions()->valueList(GuestQuestions::PRAYER_PALS_OPT_IN_VALUES));

        // Emptying it must persist as "no values", not fall back to the default.
        $this->guestQuestions()->update([GuestQuestions::PRAYER_PALS_OPT_IN_VALUES => null]);
        $this->assertSame([], $this->guestQuestions()->valueList(GuestQuestions::PRAYER_PALS_OPT_IN_VALUES));
        $this->assertSame([], $this->guestQuestions()->rawValueList(GuestQuestions::PRAYER_PALS_OPT_IN_VALUES));
    }

    /** Nominate a Radio yes/no Prayer Pals question, the staleness fixture. */
    private function nominatePrayerPalsQuestion(): void
    {
        $section = $this->guestSection();
        $pp = Question::create([
            'section_id' => $section->id, 'key' => 'guestprayerpals', 'type' => QuestionType::Radio->value,
            'label' => 'Prayer Pals', 'position' => 0, 'required' => false, 'enabled' => true,
        ]);
        $pp->options()->createMany([
            ['value' => 'yes', 'label' => 'Yes', 'position' => 0],
            ['value' => 'no', 'label' => 'No', 'position' => 1],
        ]);
        $this->guestQuestions()->update(['guest_prayer_pals_key' => 'guestprayerpals']);
    }

    #[TestDox('prayer pals opted in is always false for minors')]
    public function test_prayer_pals_opted_in_is_always_false_for_minors(): void
    {
        $section = $this->guestSection();
        $pp = Question::create([
            'section_id' => $section->id, 'key' => 'guestprayerpals', 'type' => QuestionType::Radio->value,
            'label' => 'Prayer Pals', 'position' => 0, 'required' => false, 'enabled' => true,
        ]);
        $pp->options()->createMany([
            ['value' => 'yes', 'label' => 'Yes', 'position' => 0],
            ['value' => 'no', 'label' => 'No', 'position' => 1],
        ]);
        $this->guestQuestions()->update(['guest_prayer_pals_key' => 'guestprayerpals']);

        $minor = Guest::factory()->create(['type' => GuestType::Minor]);
        app(AnswerStore::class)->store(QuestionScope::Guest, $minor, ['guestprayerpals' => 'yes']);

        $this->assertFalse($this->guestQuestions()->prayerPalsOptedIn($minor->fresh()));
    }

    #[TestDox('prayer pals opted in for adults requires an explicit yes')]
    public function test_prayer_pals_opted_in_for_adults_requires_an_explicit_yes(): void
    {
        $section = $this->guestSection();
        $pp = Question::create([
            'section_id' => $section->id, 'key' => 'guestprayerpals', 'type' => QuestionType::Radio->value,
            'label' => 'Prayer Pals', 'position' => 0, 'required' => false, 'enabled' => true,
        ]);
        $pp->options()->createMany([
            ['value' => 'yes', 'label' => 'Yes', 'position' => 0],
            ['value' => 'no', 'label' => 'No', 'position' => 1],
        ]);
        $this->guestQuestions()->update(['guest_prayer_pals_key' => 'guestprayerpals']);

        $unanswered = Guest::factory()->create(['type' => GuestType::Adult]);
        $optedIn = Guest::factory()->create(['type' => GuestType::Adult]);
        app(AnswerStore::class)->store(QuestionScope::Guest, $optedIn, ['guestprayerpals' => 'yes']);

        $this->assertFalse($this->guestQuestions()->prayerPalsOptedIn($unanswered->fresh()));
        $this->assertTrue($this->guestQuestions()->prayerPalsOptedIn($optedIn->fresh()));
    }
}
