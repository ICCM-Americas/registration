<?php

namespace ConferenceTools\Registration\Tests\Feature;

use Closure;
use ConferenceTools\Registration\Enums\BooleanOperator;
use ConferenceTools\Registration\Enums\ConditionOperator;
use ConferenceTools\Registration\Enums\QuestionScope;
use ConferenceTools\Registration\Enums\QuestionType;
use ConferenceTools\Registration\Models\Answer;
use ConferenceTools\Registration\Models\ConditionGroup;
use ConferenceTools\Registration\Models\Draft;
use ConferenceTools\Registration\Models\Group;
use ConferenceTools\Registration\Models\Question;
use ConferenceTools\Registration\Models\QuestionOption;
use ConferenceTools\Registration\Models\ReportColumn;
use ConferenceTools\Registration\Models\Section;
use ConferenceTools\Registration\Services\AnswerTextSync;
use ConferenceTools\Registration\Tests\Concerns\BuildsRegistrationData;
use ConferenceTools\Registration\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use RuntimeException;

/** Feature tests for Answer Text Sync. */
#[TestDox('Answer Text Sync')]
class AnswerTextSyncTest extends TestCase
{
    use BuildsRegistrationData, RefreshDatabase;

    private Group $group;

    protected function setUp(): void
    {
        parent::setUp();
        $this->group = $this->makeGroupWithMembers();
    }

    /** Run an edit of the question through the sync. */
    private function sync(Question $question, callable $edit, bool $preview = false): int
    {
        return app(AnswerTextSync::class)->run($question, $edit, $preview);
    }

    /** The fixture question with the given key. */
    private function question(string $key): Question
    {
        return Question::firstWhere('key', $key);
    }

    /** The fixture option of the question with the given value. */
    private function option(string $key, string $value): QuestionOption
    {
        return $this->question($key)->options()->where('value', $value)->first();
    }

    /** @return array<int, string> every stored answer to the question */
    private function stored(string $key): array
    {
        return $this->question($key)->answers()->orderBy('id')->pluck('value')->all();
    }

    #[DataProvider('renamedValues')]
    #[TestDox('renaming an option value renames every stored answer rendered from it')]
    public function test_renaming_an_option_value_renames_every_stored_answer_rendered_from_it(string $key, string $old, string $new): void
    {
        $option = $this->option($key, $old);

        $changed = $this->sync($this->question($key), fn () => $option->update(['value' => $new]));

        $this->assertSame(2, $changed);
        $this->assertSame([$new, $new], $this->stored($key));
    }

    /** Single- and multi-value questions for the data provider. */
    public static function renamedValues(): array
    {
        return [
            'single value' => ['accommodation', 'hotel', 'Hotel room'],
            'multi value' => ['products', 'dinner', 'Gala dinner'],
        ];
    }

    #[TestDox('an answer no option renders is left alone')]
    public function test_an_answer_no_option_renders_is_left_alone(): void
    {
        $answer = $this->question('accommodation')->answers()->first();
        $answer->update(['value' => 'Ritz']);
        $option = $this->option('accommodation', 'hotel');

        $changed = $this->sync($this->question('accommodation'), fn () => $option->update(['value' => 'Hotel room']));

        $this->assertSame(1, $changed);
        $this->assertSame('Ritz', $answer->fresh()->value);
    }

    #[TestDox('a free-text question\'s answers are never rewritten')]
    public function test_a_free_text_questions_answers_are_never_rewritten(): void
    {
        $before = $this->stored('lastname');

        $changed = $this->sync($this->question('lastname'), fn () => $this->question('lastname')->update(['label' => 'Surname']));

        $this->assertSame(0, $changed);
        $this->assertSame($before, $this->stored('lastname'));
    }

    #[DataProvider('referencedTexts')]
    #[TestDox('an answer embedding another question\'s text is rendered again')]
    public function test_an_answer_embedding_another_questions_text_is_rendered_again(string $template, string $before, Closure $edit, string $after): void
    {
        $gender = $this->question('gender');
        $dependent = Question::factory()->create([
            'section_id' => $gender->section_id,
            'type' => QuestionType::Select,
        ]);
        $dependent->options()->create(['value' => $template, 'label' => 'Template']);
        $registrant = $this->group->users()->first();
        $answer = $dependent->answers()->create([
            'owner_type' => $registrant->getMorphClass(),
            'owner_id' => $registrant->getKey(),
            'value' => $before,
        ]);

        $changed = $this->sync($gender, fn () => $edit($gender));

        $this->assertSame(1, $changed);
        $this->assertSame($after, $answer->fresh()->value);
    }

    /** Templates referencing the gender question's label or chosen option label, for the data provider. */
    public static function referencedTexts(): array
    {
        return [
            'question label' => [
                'Asked: {q:gender}', 'Asked: Gender',
                fn (Question $gender) => $gender->update(['label' => 'Sex']),
                'Asked: Sex',
            ],
            'answer label' => [
                'Is {q:gender.value}', 'Is Male',
                fn (Question $gender) => $gender->options()->where('value', 'm')->update(['label' => 'Man']),
                'Is Man',
            ],
        ];
    }

    #[TestDox('a translated template answer is rendered again in its own locale')]
    public function test_a_translated_template_answer_is_rendered_again_in_its_own_locale(): void
    {
        $question = Question::factory()->create([
            'section_id' => $this->question('gender')->section_id,
            'type' => QuestionType::Select,
            'translate_value' => true,
        ]);
        $option = $question->options()->create(['value' => 'Mr. {q:lastname.value}', 'label' => 'Mr.']);
        $option->storeTranslation('fr', 'value', 'M. {q:lastname.value}');
        $registrant = $this->group->users()->first();
        $answer = $question->answers()->create([
            'owner_type' => $registrant->getMorphClass(),
            'owner_id' => $registrant->getKey(),
            'value' => 'M. Test',
        ]);

        $this->sync($question, fn () => $option->storeTranslation('fr', 'value', 'Monsieur {q:lastname.value}'));

        $this->assertSame('Monsieur Test', $answer->fresh()->value);
        $this->assertSame('en', app()->getLocale());
    }

    #[TestDox('variants sharing a value resolve to the one offered to the owner')]
    public function test_variants_sharing_a_value_resolve_to_the_one_offered_to_the_owner(): void
    {
        $question = $this->question('accommodation');
        $hidden = $this->option('accommodation', 'hotel');
        $hidden->conditionGroups()
            ->create(['operator' => BooleanOperator::And->value])
            ->conditions()->create([
                'question_id' => $this->question('gender')->id,
                'operator' => ConditionOperator::Equals->value,
                'value' => 'f',
            ]);
        $offered = $question->options()->create(['value' => 'hotel', 'label' => 'Hotel (men)', 'position' => 9]);

        $this->sync($question, fn () => $offered->update(['value' => 'Men\'s hotel']));

        $this->assertSame(['Men\'s hotel', 'Men\'s hotel'], $this->stored('accommodation'));
    }

    #[TestDox('an answer only a hidden option renders still follows that option')]
    public function test_an_answer_only_a_hidden_option_renders_still_follows_that_option(): void
    {
        $hotel = $this->option('accommodation', 'hotel');
        $hotel->conditionGroups()
            ->create(['operator' => BooleanOperator::And->value])
            ->conditions()->create([
                'question_id' => $this->question('gender')->id,
                'operator' => ConditionOperator::Equals->value,
                'value' => 'f',
            ]);

        $this->sync($this->question('accommodation'), fn () => $hotel->update(['value' => 'Hotel room']));

        $this->assertSame(['Hotel room', 'Hotel room'], $this->stored('accommodation'));
    }

    #[TestDox('a preview counts the changes and rolls everything back')]
    public function test_a_preview_counts_the_changes_and_rolls_everything_back(): void
    {
        $option = $this->option('accommodation', 'hotel');

        $changed = $this->sync($this->question('accommodation'), fn () => $option->update(['value' => 'Hotel room']), true);

        $this->assertSame(2, $changed);
        $this->assertSame(['hotel', 'hotel'], $this->stored('accommodation'));
        $this->assertSame('hotel', $option->fresh()->value);
    }

    #[TestDox('a failing edit rolls everything back and rethrows')]
    public function test_a_failing_edit_rolls_everything_back_and_rethrows(): void
    {
        $option = $this->option('accommodation', 'hotel');

        try {
            $this->sync($this->question('accommodation'), function () use ($option) {
                $option->update(['value' => 'Hotel room']);

                throw new RuntimeException('boom');
            });
            $this->fail('The edit\'s exception was swallowed.');
        } catch (RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertSame('hotel', $option->fresh()->value);
    }

    #[DataProvider('exchangedValues')]
    #[TestDox('an option taking a value another option gives up in the same save is refused')]
    public function test_an_option_taking_a_value_another_option_gives_up_in_the_same_save_is_refused(string $hotelTo, string $noneTo): void
    {
        $hotel = $this->option('accommodation', 'hotel');
        $none = $this->option('accommodation', 'none');

        try {
            $this->sync($this->question('accommodation'), function () use ($hotel, $none, $hotelTo, $noneTo) {
                $hotel->update(['value' => $hotelTo]);
                $none->update(['value' => $noneTo]);
            });
            $this->fail('The exchange was not refused.');
        } catch (ValidationException $e) {
            $this->assertSame(['options'], array_keys($e->errors()));
        }

        $this->assertSame(['hotel', 'none'], [$hotel->fresh()->value, $none->fresh()->value]);
        $this->assertSame(['hotel', 'hotel'], $this->stored('accommodation'));
    }

    /** A swap and a chain of renames, for the data provider. */
    public static function exchangedValues(): array
    {
        return [
            'swap' => ['none', 'hotel'],
            'chain' => ['none', 'Nothing'],
        ];
    }

    /**
     * A draft column holding one answer to the question: the answers map
     * itself for a participant, a list of entries' answers otherwise.
     */
    private static function draftColumn(QuestionScope $scope, string $key, mixed $value): array
    {
        return $scope === QuestionScope::Participant
            ? [$key => $value, 'other' => 'hotel']
            : [['id' => 'entry-1', 'answers' => [$key => $value]]];
    }

    #[DataProvider('draftScopes')]
    #[TestDox('a renamed value is renamed in in-progress drafts')]
    public function test_a_renamed_value_is_renamed_in_in_progress_drafts(QuestionScope $scope, string $column, mixed $before, mixed $after): void
    {
        $section = Section::factory()->create(['scope' => $scope]);
        $question = Question::factory()->create(['section_id' => $section->id, 'type' => QuestionType::Checkbox]);
        $option = $question->options()->create(['value' => 'hotel', 'label' => 'Hotel']);
        $draft = Draft::factory()->create([$column => self::draftColumn($scope, $question->key, $before)]);
        $untouched = Draft::factory()->create([$column => self::draftColumn($scope, 'unrelated', $before)]);

        $changed = $this->sync($question, fn () => $option->update(['value' => 'Hotel room']));

        $this->assertSame(1, $changed);
        $this->assertSame(self::draftColumn($scope, $question->key, $after), $draft->fresh()->{$column});
        $this->assertSame(self::draftColumn($scope, 'unrelated', $before), $untouched->fresh()->{$column});
    }

    /** Draft-holding scopes, with a single and a multi-value answer, for the data provider. */
    public static function draftScopes(): array
    {
        return [
            'participant' => [QuestionScope::Participant, 'answers', 'hotel', 'Hotel room'],
            'guest' => [QuestionScope::Guest, 'guests', ['hotel', 'none'], ['Hotel room', 'none']],
            'group member' => [QuestionScope::GroupMember, 'group_members', 'hotel', 'Hotel room'],
        ];
    }

    #[TestDox('a group-scope rename leaves drafts alone')]
    public function test_a_group_scope_rename_leaves_drafts_alone(): void
    {
        $question = $this->question('orgtype');
        $option = $question->options()->first();
        $draft = Draft::factory()->create(['answers' => ['orgtype' => $option->value]]);

        $this->sync($question, fn () => $option->update(['value' => 'Renamed']));

        $this->assertSame(['orgtype' => $draft->answers['orgtype']], $draft->fresh()->answers);
    }

    #[DataProvider('conditionValues')]
    #[TestDox('a renamed value is renamed in exact-match conditions only')]
    public function test_a_renamed_value_is_renamed_in_exact_match_conditions_only(ConditionOperator $operator, string $before, string $after): void
    {
        $question = $this->question('accommodation');
        $condition = ConditionGroup::create(['operator' => BooleanOperator::And->value])
            ->conditions()->create(['question_id' => $question->id, 'operator' => $operator->value, 'value' => $before]);

        $this->sync($question, fn () => $this->option('accommodation', 'hotel')->update(['value' => 'Hotel room']));

        $this->assertSame($after, $condition->fresh()->value);
    }

    /** Operators and their condition values before and after the rename, for the data provider. */
    public static function conditionValues(): array
    {
        return [
            'equals' => [ConditionOperator::Equals, 'hotel', 'Hotel room'],
            'not equals' => [ConditionOperator::NotEquals, 'hotel', 'Hotel room'],
            'equals another value' => [ConditionOperator::Equals, 'none', 'none'],
            'in' => [ConditionOperator::In, 'none,hotel', 'none, Hotel room'],
            'not in without it' => [ConditionOperator::NotIn, 'none', 'none'],
            'contains' => [ConditionOperator::Contains, 'hotel', 'hotel'],
        ];
    }

    #[TestDox('a renamed value is renamed in report column mappings')]
    public function test_a_renamed_value_is_renamed_in_report_column_mappings(): void
    {
        $question = $this->question('accommodation');
        $column = ReportColumn::factory()->create([
            'question_id' => null,
            'guest_question_id' => $question->id,
            'mapping' => [['value' => 'hotel', 'guest' => 'any', 'text' => 'H'], ['value' => '', 'guest' => 'any', 'text' => 'Other']],
        ]);

        $this->sync($question, fn () => $this->option('accommodation', 'hotel')->update(['value' => 'Hotel room']));

        $this->assertSame(
            [['value' => 'Hotel room', 'guest' => 'any', 'text' => 'H'], ['value' => '', 'guest' => 'any', 'text' => 'Other']],
            $column->fresh()->mapping,
        );
    }

    #[DataProvider('unrenamedValues')]
    #[TestDox('a value is not renamed in rules while still in use or once gone')]
    public function test_a_value_is_not_renamed_in_rules_while_still_in_use_or_once_gone(Closure $edit): void
    {
        $question = $this->question('accommodation');
        $condition = ConditionGroup::create(['operator' => BooleanOperator::And->value])
            ->conditions()->create(['question_id' => $question->id, 'operator' => ConditionOperator::Equals->value, 'value' => 'hotel']);

        $this->sync($question, fn () => $edit($question));

        $this->assertSame('hotel', $condition->fresh()->value);
    }

    /** Edits that leave the old value meaningful or unreachable, for the data provider. */
    public static function unrenamedValues(): array
    {
        return [
            'a variant keeps the value' => [function (Question $question) {
                $original = $question->options()->where('value', 'hotel')->first();
                $question->options()->create(['value' => 'hotel', 'label' => 'Hotel (variant)', 'position' => 9]);
                $original->update(['value' => 'Hotel room']);
            }],
            'the option is deleted' => [fn (Question $question) => $question->options()->where('value', 'hotel')->get()->each->delete()],
        ];
    }
}
