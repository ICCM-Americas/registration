<?php

namespace ConferenceTools\Registration\Tests\Feature;

use ConferenceTools\Registration\Enums\QuestionType;
use ConferenceTools\Registration\Models\Answer;
use ConferenceTools\Registration\Models\ClosedMessage;
use ConferenceTools\Registration\Models\InfoStep;
use ConferenceTools\Registration\Models\Question;
use ConferenceTools\Registration\Models\QuestionOption;
use ConferenceTools\Registration\Models\Section;
use ConferenceTools\Registration\Models\Translation;
use ConferenceTools\Registration\Tests\Concerns\BuildsRegistrationData;
use ConferenceTools\Registration\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;

/** Feature tests for Translation Controller. */
#[TestDox('Translation Controller')]
class TranslationControllerTest extends TestCase
{
    use BuildsRegistrationData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->allowRegistrationManagement();
    }

    /** A translatable entity of the given type. */
    private function makeEntity(string $type): Model
    {
        return match ($type) {
            'step' => InfoStep::factory()->create(['heading' => 'Payment']),
            'section' => Section::factory()->create(['title' => 'Your details']),
            'question' => Question::factory()->create(['label' => 'First name']),
        };
    }

    #[TestDox('translations page requires the gate')]
    public function test_translations_page_requires_the_gate(): void
    {
        $this->denyRegistrationManagement();

        $this->actingAs($this->makeUser())
            ->get(route('registration.admin.translations', ['step', $this->makeEntity('step')->id]))
            ->assertForbidden();
    }

    #[DataProvider('entityTypes')]
    #[TestDox('the editor renders each entity type with its base text and tag state')]
    public function test_the_editor_renders_each_entity_type_with_its_base_text_and_tag_state(string $type, string $baseText): void
    {
        $entity = $this->makeEntity($type);
        $entity->storeTranslation('fr', $entity->translatableFields()[0], 'Texte FR');

        $this->actingAs($this->makeUser())
            ->get(route('registration.admin.translations', [$type, $entity->id]))
            ->assertOk()
            ->assertSee(__('registration::admin.add_language'))
            ->assertSee($baseText)
            ->assertSee('Texte FR')
            ->assertSee('data-tags=\'{"translated":true}\'', false);
    }

    /** The entity types for the data provider. */
    public static function entityTypes(): array
    {
        return [
            'step' => ['step', 'Payment'],
            'section' => ['section', 'Your details'],
            'question' => ['question', 'First name'],
        ];
    }

    #[TestDox('mutations return the refreshed fragment with its tag state')]
    public function test_mutations_return_the_refreshed_fragment_with_its_tag_state(): void
    {
        $question = $this->makeEntity('question');
        $option = $question->options()->create(['value' => 'a', 'label' => 'A', 'position' => 0]);

        // An option-only translation marks the whole question translated.
        $this->actingAs($this->makeUser())
            ->post(route('registration.admin.translations.save', ['question', $question->id]), [
                'locale' => 'fr',
                'texts' => ['option-'.$option->id => ['label' => 'Choix A']],
            ])
            ->assertOk()
            ->assertSee('data-tags=\'{"translated":true}\'', false);

        // Removing the only locale clears the tag.
        $this->actingAs($this->makeUser())
            ->delete(route('registration.admin.translations.locale.destroy', ['question', $question->id, 'fr']))
            ->assertOk()
            ->assertSee('data-tags=\'{"translated":false}\'', false);
    }

    #[TestDox('an unknown entity type is a 404')]
    public function test_an_unknown_entity_type_is_a_404(): void
    {
        $this->actingAs($this->makeUser())
            ->get(route('registration.admin.translations', ['variable', 1]))
            ->assertNotFound();
    }

    #[TestDox('saving stores a locale for a question and its options')]
    public function test_saving_stores_a_locale_for_a_question_and_its_options(): void
    {
        $question = $this->makeEntity('question');
        $option = $question->options()->create(['value' => 'a', 'label' => 'Choice A', 'position' => 0]);

        $this->actingAs($this->makeUser())
            ->post(route('registration.admin.translations.save', ['question', $question->id]), [
                'locale' => 'fr',
                'texts' => [
                    'self' => ['label' => 'Prénom', 'help_text' => 'Aide'],
                    'option-'.$option->id => ['label' => 'Choix A'],
                ],
            ])->assertOk();

        $this->assertSame('Prénom', $question->fresh()->translate('label', 'fr'));
        $this->assertSame('Aide', $question->fresh()->translate('help_text', 'fr'));
        $this->assertSame('Choix A', $option->fresh()->translate('label', 'fr'));
    }

    #[TestDox('saving an empty text removes that translation')]
    public function test_saving_an_empty_text_removes_that_translation(): void
    {
        $step = $this->makeEntity('step');
        $step->storeTranslation('fr', 'heading', 'Paiement');
        $step->storeTranslation('fr', 'body', 'Corps FR');

        $this->actingAs($this->makeUser())
            ->post(route('registration.admin.translations.save', ['step', $step->id]), [
                'locale' => 'fr',
                'texts' => ['self' => ['heading' => '', 'body' => 'Corps FR']],
            ]);

        $this->assertSame(['body'], $step->translations()->pluck('field')->all());
    }

    #[TestDox('saving ignores texts for items that are not on the page')]
    public function test_saving_ignores_texts_for_items_that_are_not_on_the_page(): void
    {
        $question = $this->makeEntity('question');
        $foreign = $this->makeEntity('question')->options()->create(['value' => 'x', 'label' => 'X', 'position' => 0]);

        $this->actingAs($this->makeUser())
            ->post(route('registration.admin.translations.save', ['question', $question->id]), [
                'locale' => 'fr',
                'texts' => ['option-'.$foreign->id => ['label' => 'Smuggled']],
            ]);

        $this->assertSame(0, Translation::count());
    }

    #[DataProvider('badLocales')]
    #[TestDox('an invalid locale is rejected as 422 json')]
    public function test_an_invalid_locale_is_rejected_as_422_json(string $locale): void
    {
        $step = $this->makeEntity('step');

        // The modal's fetch() sends Accept: application/json, so a failed
        // validation comes back as 422 JSON for it to display.
        $this->actingAs($this->makeUser())
            ->postJson(route('registration.admin.translations.save', ['step', $step->id]), [
                'locale' => $locale,
                'texts' => ['self' => ['heading' => 'Texte']],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('locale');

        $this->assertSame(0, Translation::count());
    }

    /** The bad locales for the data provider. */
    public static function badLocales(): array
    {
        return [
            'single letter' => ['x'],
            'digits' => ['123'],
            'contains a space' => ['en us'],
            'over 12 characters' => ['abc-defghijkl'],
        ];
    }

    #[TestDox('deleting a locale removes it for the question and its options')]
    public function test_deleting_a_locale_removes_it_for_the_question_and_its_options(): void
    {
        $question = $this->makeEntity('question');
        $option = $question->options()->create(['value' => 'a', 'label' => 'A', 'position' => 0]);
        $question->storeTranslation('fr', 'label', 'FR');
        $option->storeTranslation('fr', 'label', 'FR');
        $question->storeTranslation('de', 'label', 'DE');

        $this->actingAs($this->makeUser())
            ->delete(route('registration.admin.translations.locale.destroy', ['question', $question->id, 'fr']))
            ->assertOk();

        $this->assertSame(['de'], Translation::pluck('locale')->all());
    }

    #[TestDox('saving translates an options value field')]
    public function test_saving_translates_an_options_value_field(): void
    {
        $question = $this->makeEntity('question');
        $option = $question->options()->create(['value' => 'Mr. {q:lastname.value}', 'label' => 'Mr.', 'position' => 0]);

        $this->actingAs($this->makeUser())
            ->get(route('registration.admin.translations', ['question', $question->id]))
            ->assertOk()
            ->assertSee(__('registration::admin.field_value'));

        $this->actingAs($this->makeUser())
            ->post(route('registration.admin.translations.save', ['question', $question->id]), [
                'locale' => 'fr',
                'texts' => ['option-'.$option->id => ['value' => 'M. {q:lastname.value}']],
            ])->assertOk();

        $this->assertSame('M. {q:lastname.value}', $option->fresh()->translate('value', 'fr'));
    }

    /** Any answer at all locks a question's structure, though never any entity's translations. */
    private function seedOneAnswer(): void
    {
        $question = Question::factory()->create();
        $owner = $this->makeUser();

        $question->answers()->create([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->getKey(),
            'value' => 'x',
        ]);
    }

    /**
     * A question recording its option's translated value, with one answer
     * stored from the option's French translation.
     *
     * @return array{0: Question, 1: QuestionOption, 2: Answer}
     */
    private function translatedAnswer(): array
    {
        $question = Question::factory()->create(['type' => QuestionType::Select, 'translate_value' => true]);
        $option = $question->options()->create(['value' => 'Mr.', 'label' => 'Mr.']);
        $option->storeTranslation('fr', 'value', 'M.');
        $owner = $this->makeUser();

        $answer = $question->answers()->create([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->getKey(),
            'value' => 'M.',
        ]);

        return [$question, $option, $answer];
    }

    #[DataProvider('editableTypes')]
    #[TestDox('translations stay editable while locked')]
    public function test_translations_stay_editable_while_locked(string $type): void
    {
        $entity = $type === 'closed-message'
            ? ClosedMessage::forKey(ClosedMessage::CLOSED)
            : $this->makeEntity($type);
        $this->seedOneAnswer();

        $this->actingAs($this->makeUser())
            ->post(route('registration.admin.translations.save', [$type, $entity->id]), [
                'locale' => 'fr',
                'texts' => ['self' => [$entity->translatableFields()[0] => 'Texte FR']],
            ])->assertOk();

        $this->assertSame('Texte FR', $entity->fresh()->translate($entity->translatableFields()[0], 'fr'));
    }

    /** The translatable types for the data provider. */
    public static function editableTypes(): array
    {
        return [
            'step' => ['step'],
            'section' => ['section'],
            'question' => ['question'],
            'closed-message' => ['closed-message'],
        ];
    }

    #[TestDox('saving a question translation updates the stored answers rendered from it')]
    public function test_saving_a_question_translation_updates_the_stored_answers_rendered_from_it(): void
    {
        [$question, $option, $answer] = $this->translatedAnswer();

        $this->actingAs($this->makeUser())
            ->post(route('registration.admin.translations.save', ['question', $question->id]), [
                'locale' => 'fr',
                'texts' => ['option-'.$option->id => ['value' => 'Monsieur']],
            ])->assertOk();

        $this->assertSame('Monsieur', $answer->fresh()->value);
    }

    #[TestDox('a translation preview counts the stored answers it would change without saving')]
    public function test_a_translation_preview_counts_the_stored_answers_it_would_change_without_saving(): void
    {
        [$question, $option, $answer] = $this->translatedAnswer();

        $this->actingAs($this->makeUser())
            ->post(route('registration.admin.translations.save', ['question', $question->id]), [
                'locale' => 'fr',
                'texts' => ['option-'.$option->id => ['value' => 'Monsieur']],
                'preview' => '1',
            ])->assertOk()->assertExactJson(['changes' => 1]);

        $this->assertSame('M.', $answer->fresh()->value);
        $this->assertSame('M.', $option->fresh()->translate('value', 'fr'));
    }

    #[TestDox('deleting a question\'s language returns its stored answers to the base text')]
    public function test_deleting_a_questions_language_returns_its_stored_answers_to_the_base_text(): void
    {
        [$question, , $answer] = $this->translatedAnswer();

        $this->actingAs($this->makeUser())
            ->delete(route('registration.admin.translations.locale.destroy', ['question', $question->id, 'fr']))
            ->assertOk();

        $this->assertSame('Mr.', $answer->fresh()->value);
    }
}
