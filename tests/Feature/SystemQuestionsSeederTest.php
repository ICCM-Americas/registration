<?php

namespace ConferenceTools\Registration\Tests\Feature;

use ConferenceTools\Registration\Database\Seeders\SystemQuestionsSeeder;
use ConferenceTools\Registration\Enums\QuestionScope;
use ConferenceTools\Registration\Enums\QuestionType;
use ConferenceTools\Registration\Models\Question;
use ConferenceTools\Registration\Models\Section;
use ConferenceTools\Registration\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestDox;

/** Feature tests for System Questions Seeder. */
#[TestDox('System Questions Seeder')]
class SystemQuestionsSeederTest extends TestCase
{
    use RefreshDatabase;

    #[TestDox('migrating creates the dedicated section and both protected trigger questions, live')]
    public function test_migrating_creates_the_dedicated_section_and_both_protected_questions_live(): void
    {
        // RefreshDatabase already migrated (and therefore seeded) — nothing
        // further to call; this confirms what a plain `php artisan migrate`
        // leaves behind on its own. Both the section and its questions are
        // enabled from the start — whether they display is down to the
        // normal visibility mechanism, never a separate disabled flag.
        $section = Section::where('key', SystemQuestionsSeeder::SECTION_KEY)->first();

        $this->assertNotNull($section);
        $this->assertSame(QuestionScope::Participant, $section->scope);
        $this->assertTrue($section->is_system);
        $this->assertTrue($section->enabled);

        foreach ([Question::GUEST_TRIGGER_KEY, Question::GROUP_TRIGGER_KEY] as $key) {
            $question = Question::where('key', $key)->first();

            $this->assertNotNull($question, "expected a seeded question for {$key}");
            $this->assertSame($section->id, $question->section_id);
            $this->assertSame(QuestionType::YesNo, $question->type);
            $this->assertTrue($question->is_system);
            $this->assertTrue($question->required);
            $this->assertTrue($question->enabled);
            $this->assertSame(
                ['Yes', 'No'],
                $question->options()->orderBy('position')->pluck('value')->all(),
            );
        }
    }

    #[TestDox('migrating creates the dedicated group-member section and both protected questions, live')]
    public function test_migrating_creates_the_group_member_section_and_both_protected_questions_live(): void
    {
        $section = Section::where('key', SystemQuestionsSeeder::GROUP_MEMBER_SECTION_KEY)->first();

        $this->assertNotNull($section);
        $this->assertSame(QuestionScope::GroupMember, $section->scope);
        $this->assertTrue($section->is_system);
        $this->assertTrue($section->enabled);

        $expectedTypes = [
            Question::GROUP_MEMBER_NAME_KEY => QuestionType::Text,
            Question::GROUP_MEMBER_EMAIL_KEY => QuestionType::Email,
        ];

        foreach ($expectedTypes as $key => $type) {
            $question = Question::where('key', $key)->first();

            $this->assertNotNull($question, "expected a seeded question for {$key}");
            $this->assertSame($section->id, $question->section_id);
            $this->assertSame($type, $question->type);
            $this->assertTrue($question->is_system);
            $this->assertTrue($question->required);
            $this->assertTrue($question->enabled);
            $this->assertCount(0, $question->options);
        }
    }

    #[TestDox('reseeding adds nothing and keeps admin edits, including a visibility choice to hide it')]
    public function test_reseeding_adds_nothing_and_keeps_admin_edits(): void
    {
        // An admin's own choice, made through the section's normal Visibility
        // control ("Never show this section") — reseeding must not silently
        // reverse it.
        $section = Section::where('key', SystemQuestionsSeeder::SECTION_KEY)->first();
        $section->update(['enabled' => false]);
        Question::where('key', Question::GUEST_TRIGGER_KEY)->update([
            'label' => 'Bringing anyone who is not attending?',
        ]);

        $this->seed(SystemQuestionsSeeder::class);

        $this->assertSame(2, Section::where('is_system', true)->count());
        $this->assertSame(4, Question::where('is_system', true)->count());
        $this->assertSame(
            'Bringing anyone who is not attending?',
            Question::where('key', Question::GUEST_TRIGGER_KEY)->first()->label,
        );
        $this->assertFalse($section->fresh()->enabled);
    }
}
