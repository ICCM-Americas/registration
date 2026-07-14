<?php

namespace ConferenceTools\Registration\Database\Seeders;

use ConferenceTools\Registration\Enums\QuestionScope;
use ConferenceTools\Registration\Enums\QuestionType;
use ConferenceTools\Registration\Models\Question;
use ConferenceTools\Registration\Models\Section;
use Illuminate\Database\Seeder;

/**
 * Inserts the package's protected system questions — the two YesNo trigger
 * questions ("are you registering guests?" and "are you registering a
 * group?") in a Participant-scope section of their own, and the two
 * free-text questions a group leader answers per invitee (name, email) in a
 * GroupMember-scope section of their own — as ordinary questions, so every
 * other part of the package (visibility rules, {q:...} interpolation,
 * reports) treats them exactly like any admin-created question/section.
 * Every section and question seeded here is live from the moment it's
 * seeded — whether it displays is entirely down to the normal visibility
 * mechanism (a section's own "never show" / conditional-rule state, same as
 * any other section), never a separate disabled flag. The admin drags each
 * section into place from the Questions page. Idempotent by key, like
 * {@see InfoStepSeeder}: re-running never touches a row that already exists,
 * so it's safe to invoke from a migration on every deploy.
 */
class SystemQuestionsSeeder extends Seeder
{
    /** The trigger-questions section's stable key. */
    public const SECTION_KEY = 'system-questions';

    /** The group-member-details section's stable key. */
    public const GROUP_MEMBER_SECTION_KEY = 'group-member-details';

    /** @var array<string, string> key => default English prompt label */
    private const QUESTIONS = [
        Question::GUEST_TRIGGER_KEY => 'Are you registering one or more non-attending guests?',
        Question::GROUP_TRIGGER_KEY => 'Are you registering a group for your organization?',
    ];

    /** @var array<string, array{label: string, type: QuestionType}> key => default label/type */
    private const GROUP_MEMBER_QUESTIONS = [
        Question::GROUP_MEMBER_NAME_KEY => ['label' => 'Full name', 'type' => QuestionType::Text],
        Question::GROUP_MEMBER_EMAIL_KEY => ['label' => 'Email address', 'type' => QuestionType::Email],
    ];

    public function run(): void
    {
        $this->seedTriggerQuestions();
        $this->seedGroupMemberQuestions();
    }

    /** The two YesNo trigger questions, each offering a Yes/No option. */
    private function seedTriggerQuestions(): void
    {
        $section = $this->section(self::SECTION_KEY, QuestionScope::Participant, 'Guest & Group Registration');

        foreach (self::QUESTIONS as $key => $label) {
            if (Question::where('key', $key)->exists()) {
                continue;
            }

            $question = Question::create([
                'section_id' => $section->id,
                'key' => $key,
                'type' => QuestionType::YesNo,
                'label' => $label,
                'required' => true,
                'position' => (int) $section->questions()->max('position') + 1,
                'enabled' => true,
                'is_system' => true,
            ]);

            $question->options()->createMany([
                ['value' => Question::YES_VALUE, 'label' => 'Yes', 'position' => 0],
                ['value' => 'No', 'label' => 'No', 'position' => 1],
            ]);
        }
    }

    /**
     * The two free-text questions a group leader answers once per invitee
     * (name, email) — a group leader's own answers, not the eventual
     * member's, purely to compose that member's invite email. No options:
     * both are plain Text/Email fields.
     */
    private function seedGroupMemberQuestions(): void
    {
        $section = $this->section(self::GROUP_MEMBER_SECTION_KEY, QuestionScope::GroupMember, 'Group Member Details');

        foreach (self::GROUP_MEMBER_QUESTIONS as $key => $config) {
            if (Question::where('key', $key)->exists()) {
                continue;
            }

            Question::create([
                'section_id' => $section->id,
                'key' => $key,
                'type' => $config['type'],
                'label' => $config['label'],
                'required' => true,
                'position' => (int) $section->questions()->max('position') + 1,
                'enabled' => true,
                'is_system' => true,
            ]);
        }
    }

    /**
     * A position past anything a real installation (or test fixture) is
     * ever going to reach by plain incremental numbering, so the section
     * reliably sorts last on creation — see {@see Section()}.
     */
    private const LAST_POSITION = 1_000_000;

    /**
     * A dedicated, protected section a set of system questions live in —
     * created once per (scope, key), and enabled (live) immediately.
     * Positioned last unconditionally (see LAST_POSITION) rather than
     * "current max + 1": this runs from a migration, which on a fresh
     * install/test database has no other sections of that scope yet to
     * measure against, so "current max" would misplace it ahead of sections
     * a later seed step adds — a fixed high position keeps it appended
     * after those too, until the admin drags it wherever it should appear
     * (the same way any other section is repositioned), or uses its normal
     * Visibility control if they want to hide it instead.
     */
    private function section(string $key, QuestionScope $scope, string $title): Section
    {
        return Section::firstOrCreate(
            ['scope' => $scope->value, 'key' => $key],
            [
                'title' => $title,
                'position' => self::LAST_POSITION,
                'enabled' => true,
                'is_system' => true,
            ],
        );
    }
}
