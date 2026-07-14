<?php

namespace ConferenceTools\Registration\Tests\Feature;

use ConferenceTools\Registration\Enums\GuestType;
use ConferenceTools\Registration\Enums\QuestionScope;
use ConferenceTools\Registration\Enums\ReportField;
use ConferenceTools\Registration\Models\ConferenceArchive;
use ConferenceTools\Registration\Models\Group;
use ConferenceTools\Registration\Models\Guest;
use ConferenceTools\Registration\Models\Question;
use ConferenceTools\Registration\Models\Section;
use ConferenceTools\Registration\Models\Setting;
use ConferenceTools\Registration\Services\AnswerStore;
use ConferenceTools\Registration\Services\GuestQuestions;
use ConferenceTools\Registration\Services\RegistrationAnswerFlattener;
use ConferenceTools\Registration\Services\RegistrationArchiver;
use ConferenceTools\Registration\Services\ReportQuestions;
use ConferenceTools\Registration\Tests\Concerns\BuildsRegistrationData;
use ConferenceTools\Registration\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Builds the export bundle's "answers" sheet: identity columns followed by
 * one column per Participant/Group/Guest-scope question, one row per
 * registrant then their guests. Uses a small, self-built question set (not
 * the shared fixture questionnaire) so headers/rows are fully deterministic.
 */
#[TestDox('Registration Answer Flattener')]
class RegistrationAnswerFlattenerTest extends TestCase
{
    use BuildsRegistrationData, RefreshDatabase;

    /** A registrant with a badge name + a plain answer, their group's own organization answer, and one adult guest. */
    private function seedFixture(): Group
    {
        $participant = Section::factory()->create(['scope' => QuestionScope::Participant, 'position' => 0]);
        $groupSection = Section::factory()->create(['scope' => QuestionScope::Group, 'position' => 1]);
        $guestSection = Section::factory()->create(['scope' => QuestionScope::Guest, 'position' => 2]);

        Question::factory()->create(['section_id' => $participant->id, 'key' => 'badgename', 'label' => 'Badge Name', 'position' => 0]);
        Question::factory()->create(['section_id' => $participant->id, 'key' => 'diet', 'label' => 'Diet', 'position' => 1]);
        Question::factory()->create(['section_id' => $groupSection->id, 'key' => 'organization', 'label' => 'Organization', 'position' => 0]);
        Question::factory()->create(['section_id' => $guestSection->id, 'key' => 'guestname', 'label' => 'Guest Name', 'position' => 0]);

        Setting::put(ReportQuestions::BADGE_NAME_KEY, 'badgename');
        Setting::put(ReportQuestions::ORGANIZATION_KEY, 'organization');
        Setting::put(GuestQuestions::NAME_KEY, 'guestname');

        $group = Group::factory()->create(['name' => 'The Engines']);
        $host = $this->makeUser(['group_id' => $group->id, 'is_group_admin' => true]);
        $this->storeAnswers($host, QuestionScope::Participant, ['badgename' => 'Ada Lovelace', 'diet' => 'Vegetarian']);
        $this->storeAnswers($group, QuestionScope::Group, ['organization' => 'Engines Ltd']);

        $guest = Guest::factory()->create(['user_id' => $host->id, 'type' => GuestType::Adult]);
        app(AnswerStore::class)->store(QuestionScope::Guest, $guest, ['guestname' => 'Guest One']);

        return $group;
    }

    #[TestDox('headers list identity columns then every configured question, in section/question order')]
    public function test_headers_list_identity_columns_then_every_configured_question_in_order(): void
    {
        $this->seedFixture();

        $this->assertSame([
            ReportField::EntryType->label(),
            ReportField::BadgeName->label(),
            ReportField::Email->label(),
            ReportField::Organization->label(),
            // The fixture's own questions, in section/question order...
            'Badge Name', 'Diet', 'Organization', 'Guest Name',
            // ...then the two Participant-scope system questions every
            // installation seeds from a migration (see SystemQuestionsSeeder),
            // whose section always sorts last.
            'Are you registering one or more non-attending guests?',
            'Are you registering a group for your organization?',
        ], app(RegistrationAnswerFlattener::class)->headers());
    }

    #[TestDox('a live snapshot produces one row per registrant then their guests, with Participant and Group answers repeated onto guest rows and Guest answers blank on the registrants own row')]
    public function test_a_live_snapshot_produces_one_row_per_registrant_then_their_guests(): void
    {
        $group = $this->seedFixture();
        $host = $group->users()->first();

        $snapshot = app(RegistrationArchiver::class)->snapshot();
        $rows = app(RegistrationAnswerFlattener::class)->rows($snapshot)->all();

        $this->assertSame([
            [
                __('registration::admin.report_entry_attendee'), 'Ada Lovelace', $host->email, 'Engines Ltd',
                'Ada Lovelace', 'Vegetarian', 'Engines Ltd', null,
                // The two seeded system questions (see above) are unanswered here.
                null, null,
            ],
            [
                __('registration::admin.report_entry_adult_guest'), 'Guest One', null, 'Engines Ltd',
                'Ada Lovelace', 'Vegetarian', 'Engines Ltd', 'Guest One',
                null, null,
            ],
        ], $rows);
    }

    #[TestDox('an archived snapshot produces the same rows the live snapshot did before archiving')]
    public function test_an_archived_snapshot_produces_the_same_rows_the_live_snapshot_did_before_archiving(): void
    {
        $this->seedFixture();
        $flattener = app(RegistrationAnswerFlattener::class);
        $archiver = app(RegistrationArchiver::class);

        $liveRows = $flattener->rows($archiver->snapshot())->all();

        $archiver->archive();

        $this->assertSame($liveRows, $flattener->rows(ConferenceArchive::sole()->data)->all());
    }

    #[TestDox('a snapshot missing the registrants key degrades to an empty list rather than throwing')]
    public function test_a_snapshot_missing_the_registrants_key_degrades_to_an_empty_list(): void
    {
        $this->assertSame([], app(RegistrationAnswerFlattener::class)->rows(['answers' => [], 'groups' => [], 'guests' => []])->all());
    }
}
