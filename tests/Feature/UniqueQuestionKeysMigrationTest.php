<?php

namespace ConferenceTools\Registration\Tests\Feature;

use ConferenceTools\Registration\Enums\QuestionScope;
use ConferenceTools\Registration\Enums\QuestionType;
use ConferenceTools\Registration\Models\Question;
use ConferenceTools\Registration\Models\Section;
use ConferenceTools\Registration\Tests\TestCase;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestDox;
use RuntimeException;

/** Question keys are unique across every scope, enforced by the database. */
#[TestDox('Unique Question Keys Migration')]
class UniqueQuestionKeysMigrationTest extends TestCase
{
    use RefreshDatabase;

    /** The migration under test. */
    private function migration(): Migration
    {
        return require __DIR__.'/../../database/migrations/2026_10_06_000200_make_registration_question_keys_unique.php';
    }

    /** A question with $key in a fresh section of $scope. */
    private function question(QuestionScope $scope, string $key): Question
    {
        $section = Section::create(['scope' => $scope->value, 'key' => 'keys-'.$scope->value, 'title' => 'Keys', 'position' => 99, 'enabled' => true]);

        return Question::create([
            'section_id' => $section->id, 'key' => $key, 'type' => QuestionType::Text->value,
            'label' => 'Shared', 'position' => 0, 'required' => false, 'enabled' => true,
        ]);
    }

    #[TestDox('the database rejects a key another scope already uses')]
    public function test_the_database_rejects_a_key_another_scope_already_uses(): void
    {
        $this->question(QuestionScope::Participant, 'shared');

        $this->expectException(UniqueConstraintViolationException::class);
        $this->question(QuestionScope::Guest, 'shared');
    }

    #[TestDox('existing duplicates stop the migration and are listed')]
    public function test_existing_duplicates_stop_the_migration_and_are_listed(): void
    {
        $migration = $this->migration();
        $migration->down();
        $this->question(QuestionScope::Participant, 'shared');
        $this->question(QuestionScope::Guest, 'shared');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(__('registration::admin.question_keys_not_unique', ['keys' => 'shared']));
        $migration->up();
    }
}
