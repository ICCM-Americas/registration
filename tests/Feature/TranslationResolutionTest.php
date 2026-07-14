<?php

namespace ConferenceTools\Registration\Tests\Feature;

use ConferenceTools\Registration\Models\InfoStep;
use ConferenceTools\Registration\Models\Question;
use ConferenceTools\Registration\Models\Section;
use ConferenceTools\Registration\Models\Translation;
use ConferenceTools\Registration\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * The TranslatesFields locale-resolution chain: requested locale → the app
 * fallback locale → the entity's own (base-language) text. The base text is
 * whatever language the content was authored or imported in — NOT necessarily
 * English — so an entity with a single language always shows that language.
 */
#[TestDox('Translation Resolution')]
class TranslationResolutionTest extends TestCase
{
    use RefreshDatabase;

    /** An info-step fixture. */
    private function step(array $headings): InfoStep
    {
        $step = InfoStep::factory()->create(['heading' => 'Base heading']);
        foreach ($headings as $locale => $value) {
            $step->storeTranslation($locale, 'heading', $value);
        }

        return $step;
    }

    #[DataProvider('resolutionCases')]
    #[TestDox('the locale resolution chain')]
    public function test_the_locale_resolution_chain(string $appLocale, array $headings, string $expected): void
    {
        config(['app.fallback_locale' => 'en']);
        app()->setLocale($appLocale);

        $this->assertSame($expected, $this->step($headings)->translate('heading'));
    }

    /** The resolution cases for the data provider. */
    public static function resolutionCases(): array
    {
        return [
            'requested locale wins' => ['fr', ['fr' => 'Titre', 'en' => 'Title'], 'Titre'],
            'missing locale falls back to the fallback locale' => ['de', ['fr' => 'Titre', 'en' => 'Title'], 'Title'],
            'missing everywhere falls back to the base text' => ['de', ['fr' => 'Titre'], 'Base heading'],
            'a single available language is used, whatever it is' => ['de', [], 'Base heading'],
        ];
    }

    #[TestDox('an explicit locale overrides the app locale')]
    public function test_an_explicit_locale_overrides_the_app_locale(): void
    {
        app()->setLocale('en');

        $this->assertSame('Titre', $this->step(['fr' => 'Titre'])->translate('heading', 'fr'));
    }

    #[TestDox('storing an empty value removes the translation')]
    public function test_storing_an_empty_value_removes_the_translation(): void
    {
        $step = $this->step(['fr' => 'Titre']);

        $step->storeTranslation('fr', 'heading', '  ');

        $this->assertSame(0, $step->translations()->count());
    }

    #[TestDox('a translation belongs to its translatable entity')]
    public function test_a_translation_belongs_to_its_translatable_entity(): void
    {
        $step = $this->step(['fr' => 'Titre']);

        $this->assertTrue($step->is(Translation::sole()->translatable));
    }

    #[TestDox('deleting an entity removes its translations')]
    public function test_deleting_an_entity_removes_its_translations(): void
    {
        $this->step(['fr' => 'Titre', 'de' => 'Titel'])->delete();

        $this->assertSame(0, Translation::count());
    }

    #[TestDox('deleting a section cascades translations of its questions and options')]
    public function test_deleting_a_section_cascades_translations_of_its_questions_and_options(): void
    {
        $section = Section::factory()->create();
        $question = Question::factory()->create(['section_id' => $section->id]);
        $option = $question->options()->create(['value' => 'a', 'label' => 'A', 'position' => 0]);

        $section->storeTranslation('fr', 'title', 'Section FR');
        $question->storeTranslation('fr', 'label', 'Question FR');
        $option->storeTranslation('fr', 'label', 'Option FR');

        $section->delete();

        // The DB alone would cascade the rows without their translations; the
        // model-level cascade removes all three sets.
        $this->assertSame(0, Translation::count());
    }
}
