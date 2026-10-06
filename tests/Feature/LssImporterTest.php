<?php

namespace ConferenceTools\Registration\Tests\Feature;

use ConferenceTools\Registration\Enums\BooleanOperator;
use ConferenceTools\Registration\Enums\ConditionOperator;
use ConferenceTools\Registration\Enums\QuestionScope;
use ConferenceTools\Registration\Enums\QuestionType;
use ConferenceTools\Registration\Models\Question;
use ConferenceTools\Registration\Models\Section;
use ConferenceTools\Registration\Models\Translation;
use ConferenceTools\Registration\Services\LssImporter;
use ConferenceTools\Registration\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestDox;

/** Feature tests for Lss Importer. */
#[TestDox('Lss Importer')]
class LssImporterTest extends TestCase
{
    use RefreshDatabase;

    #[TestDox('it imports groups questions options and conditions')]
    public function test_it_imports_groups_questions_options_and_conditions(): void
    {
        $report = app(LssImporter::class)->import($this->modernLss());

        $this->assertSame('My Conference', $report['survey']);
        $this->assertSame(2, $report['counts']['sections']);

        // Groups -> participant-scope sections, ordered by group_order. The
        // protected system-questions section (seeded by migration, unrelated
        // to this import) is excluded.
        $sections = Section::where('is_system', false)->orderBy('position')->get();
        $this->assertSame(['Personal', 'Preferences'], $sections->pluck('title')->all());
        $this->assertTrue($sections->every(fn (Section $s) => $s->scope === QuestionScope::Participant));

        // Short free text + mandatory -> required Text question, keyed by code.
        $firstname = Question::where('key', 'firstname')->firstOrFail();
        $this->assertSame(QuestionType::Text, $firstname->type);
        $this->assertTrue($firstname->required);
        $this->assertSame('First name', $firstname->label);

        // List (radio) -> Radio with options drawn from the answers table.
        $country = Question::where('key', 'country')->firstOrFail();
        $this->assertSame(QuestionType::Radio, $country->type);
        $this->assertSame(
            [['uk', 'United Kingdom'], ['us', 'United States']],
            $country->options->map(fn ($o) => [$o->value, $o->label])->all(),
        );

        // Multiple choice -> Checkbox with options from its subquestions.
        $meals = Question::where('key', 'meals')->firstOrFail();
        $this->assertSame(QuestionType::Checkbox, $meals->type);
        $this->assertSame(['Vegetarian', 'Vegan'], $meals->options->pluck('label')->all());
    }

    #[TestDox('it imports a condition as a visibility rule')]
    public function test_it_imports_a_condition_as_a_visibility_rule(): void
    {
        app(LssImporter::class)->import($this->modernLss());

        $comments = Question::where('key', 'comments')->firstOrFail();
        $country = Question::where('key', 'country')->firstOrFail();

        $group = $comments->conditionGroups()->firstOrFail();
        $this->assertSame(BooleanOperator::And, $group->operator);

        $condition = $group->conditions()->firstOrFail();
        $this->assertSame($country->id, $condition->question_id);
        $this->assertSame(ConditionOperator::Equals, $condition->operator);
        $this->assertSame('uk', $condition->value);
    }

    #[TestDox('it skips unsupported question types and reports them')]
    public function test_it_skips_unsupported_question_types_and_reports_them(): void
    {
        $report = app(LssImporter::class)->import($this->modernLss());

        $this->assertNull(Question::where('key', 'matrix')->first());
        $this->assertTrue(
            collect($report['skipped'])->contains(fn ($n) => str_contains($n, 'matrix') && str_contains($n, 'array')),
        );
    }

    #[TestDox('it skips a question whose key another section already uses and reports it')]
    public function test_it_skips_a_question_whose_key_another_section_already_uses_and_reports_it(): void
    {
        $guestSection = Section::create(['scope' => QuestionScope::Guest->value, 'key' => 'guests', 'title' => 'Guests', 'position' => 0, 'enabled' => true]);
        $existing = Question::create([
            'section_id' => $guestSection->id, 'key' => 'firstname', 'type' => QuestionType::Text->value,
            'label' => "Guest's first name", 'position' => 0, 'required' => false, 'enabled' => true,
        ]);

        $report = app(LssImporter::class)->import($this->modernLss());

        $this->assertSame($existing->id, Question::where('key', 'firstname')->sole()->id);
        $this->assertSame("Guest's first name", $existing->fresh()->label);
        $this->assertTrue(collect($report['skipped'])->contains(fn ($n) => str_contains($n, "key 'firstname' is already used")));
    }

    #[TestDox('it rewrites limesurvey answer references to q tokens')]
    public function test_it_rewrites_limesurvey_answer_references_to_q_tokens(): void
    {
        app(LssImporter::class)->import($this->tokenLss());

        // {Code.value}/{Code.shown}/{Code.NAOK} references become {q:key.value}
        // tokens (codes matched case-insensitively, keys slugged); {if(...)}
        // expressions, plain {Variable} tokens and unknown codes are left
        // untouched for the admin to resolve.
        $summary = Question::where('key', 'summary')->firstOrFail();
        $this->assertSame(
            'Hello {q:firstname.value}, gender {q:gender.value}, again {q:firstname.value}, '
                .'keep {if(Gender.value == "M", "Mr", "Ms")} and {Airport} and {unknown.value}',
            $summary->label,
        );
        $this->assertSame('You said {q:firstname.value}', $summary->help_text);

        // Option labels and stored translations are rewritten too.
        $option = Question::where('key', 'gender')->firstOrFail()->options()->firstWhere('value', 'M');
        $this->assertSame('Same as {q:firstname.value}', $option->label);
        $this->assertSame('Bonjour {q:firstname.value}', $summary->translate('label', 'fr'));
    }

    #[TestDox('it stores the other languages as translations')]
    public function test_it_stores_the_other_languages_as_translations(): void
    {
        // The survey's base language (en) lands in the entity columns; the
        // French texts land in translation rows, exactly as if an admin had
        // entered them on the translations screen.
        $report = app(LssImporter::class)->import($this->modernLss());

        $personal = Section::where('key', 'personal')->firstOrFail();
        $this->assertSame('Personal', $personal->title);
        $this->assertSame('Personnel', $personal->translate('title', 'fr'));
        $this->assertSame('Vos coordonnées', $personal->translate('description', 'fr'));

        $firstname = Question::where('key', 'firstname')->firstOrFail();
        $this->assertSame('Prénom', $firstname->translate('label', 'fr'));
        $this->assertSame('Comme sur votre passeport', $firstname->translate('help_text', 'fr'));

        // Answer-table option and subquestion option labels, per language.
        $uk = Question::where('key', 'country')->firstOrFail()->options()->firstWhere('value', 'uk');
        $this->assertSame('Royaume-Uni', $uk->translate('label', 'fr'));
        $veggie = Question::where('key', 'meals')->firstOrFail()->options()->firstWhere('value', 'SQ001');
        $this->assertSame('Végétarien', $veggie->translate('label', 'fr'));

        // A locale nothing was provided in falls back to the base language.
        $this->assertSame('United States', Question::where('key', 'country')->firstOrFail()
            ->options()->firstWhere('value', 'us')->translate('label', 'fr'));

        $this->assertSame(6, $report['counts']['translations']);
    }

    #[TestDox('it reads text from the older inline layout')]
    public function test_it_reads_text_from_the_older_inline_layout(): void
    {
        $report = app(LssImporter::class)->import($this->legacyLss());

        $this->assertSame(1, $report['counts']['sections']);
        $email = Question::where('key', 'email')->firstOrFail();
        $this->assertSame('Email address', $email->label);
        $this->assertSame(QuestionType::Text, $email->type);
    }

    #[TestDox('the older inline layout imports a multilingual survey once with translations')]
    public function test_the_older_inline_layout_imports_a_multilingual_survey_once_with_translations(): void
    {
        // LimeSurvey 2.x repeats each group/question/answer row per language;
        // the import must collapse them (no duplicated sections/questions) and
        // keep the non-base languages as translations.
        app(LssImporter::class)->import($this->legacyMultilingualLss());

        $sections = Section::where('is_system', false)->get();
        $this->assertCount(1, $sections);
        $this->assertSame('Contact', $sections->first()->title);
        $this->assertSame('Coordonnées', $sections->first()->translate('title', 'fr'));

        $this->assertSame(1, Question::where('key', 'email')->count());
        $email = Question::where('key', 'email')->firstOrFail();
        $this->assertSame('Email address', $email->label);
        $this->assertSame('Adresse électronique', $email->translate('label', 'fr'));

        $this->assertSame(1, Question::where('key', 'lang')->count());
        $yes = Question::where('key', 'lang')->firstOrFail()->options()->firstWhere('value', 'y');
        $this->assertSame('Yes', $yes->label);
        $this->assertSame('Oui', $yes->translate('label', 'fr'));
    }

    #[TestDox('re running the import is idempotent')]
    public function test_re_running_the_import_is_idempotent(): void
    {
        $importer = app(LssImporter::class);
        $importer->import($this->modernLss());
        $optionIds = Question::where('key', 'country')->firstOrFail()->options()->pluck('id', 'value')->all();
        $translations = Translation::count();

        $importer->import($this->modernLss());

        $this->assertSame(2, Section::where('is_system', false)->count());
        $this->assertSame(1, Question::where('key', 'country')->count());
        $this->assertSame(2, Question::where('key', 'country')->firstOrFail()->options()->count());

        // Options are reconciled by value, so their rows — and everything hung
        // off them, like translations — survive a re-import unchanged.
        $this->assertSame($optionIds, Question::where('key', 'country')->firstOrFail()->options()->pluck('id', 'value')->all());
        $this->assertSame($translations, Translation::count());
    }

    #[TestDox('invalid xml is rejected')]
    public function test_invalid_xml_is_rejected(): void
    {
        // Keep libxml's parse error internal so simplexml returns false (instead of
        // the warning escalating to an ErrorException under the framework's error
        // handler), exercising the importer's own "not valid XML" guard.
        $previous = libxml_use_internal_errors(true);

        try {
            $this->expectException(\InvalidArgumentException::class);
            app(LssImporter::class)->import('this is not valid xml <<<');
        } finally {
            libxml_use_internal_errors($previous);
        }
    }

    #[TestDox('it imports fixed option types other and varied conditions')]
    public function test_it_imports_fixed_option_types_other_and_varied_conditions(): void
    {
        $report = app(LssImporter::class)->import($this->edgeLss());

        $this->assertSame('Edge Survey', $report['survey']);

        // A group row without a gid is skipped; the base column takes the
        // survey's base language (en) even though the fr row comes first, and
        // the fr text is kept as a translation.
        $sections = Section::where('is_system', false)->get();
        $this->assertCount(1, $sections);
        $this->assertSame('Edge Group', $sections->first()->title);
        $this->assertSame('Groupe FR', $sections->first()->translate('title', 'fr'));

        // 5-point choice -> options 1..5; gender -> Male/Female; a list with
        // "other" enabled appends the -oth- option.
        $this->assertSame(['1', '2', '3', '4', '5'], Question::where('key', 'rating')->firstOrFail()->options->pluck('value')->all());
        $this->assertSame(
            [['M', 'Male'], ['F', 'Female']],
            Question::where('key', 'sex')->firstOrFail()->options->map(fn ($o) => [$o->value, $o->label])->all(),
        );
        $this->assertContains('-oth-', Question::where('key', 'fruit')->firstOrFail()->options->pluck('value')->all());

        // Yes/no imports as a radio with fixed Yes/No options over true/false.
        $attend = Question::where('key', 'attend')->firstOrFail();
        $this->assertSame(QuestionType::Radio, $attend->type);
        $this->assertSame(
            [['true', 'Yes'], ['false', 'No']],
            $attend->options->map(fn ($o) => [$o->value, $o->label])->all(),
        );

        // Conditions on a yes/no question map LimeSurvey's Y/N comparison value
        // onto the imported true/false values (other values pass through).
        $whynot = Question::where('key', 'whynot')->firstOrFail();
        $this->assertSame(
            ['true', ''],
            $whynot->conditionGroups()->firstOrFail()->conditions()->orderBy('position')->pluck('value')->all(),
        );

        // A condition on a multiple-choice question becomes a "contains" rule on
        // the chosen subquestion code.
        $note = Question::where('key', 'note')->firstOrFail();
        $diet = Question::where('key', 'diet')->firstOrFail();
        $group = $note->conditionGroups()->firstOrFail();
        $this->assertSame(BooleanOperator::And, $group->operator);
        $condition = $group->conditions()->firstOrFail();
        $this->assertSame(ConditionOperator::Contains, $condition->operator);
        $this->assertSame('SQ001', $condition->value);
        $this->assertSame($diet->id, $condition->question_id);

        // Two scenarios on one question become an OR root with an AND child each.
        $multi = Question::where('key', 'multi')->firstOrFail();
        $root = $multi->conditionGroups()->firstOrFail();
        $this->assertSame(BooleanOperator::Or, $root->operator);
        $this->assertCount(2, $root->children);

        // Everything that could not be represented is reported, not guessed at.
        $skipped = implode("\n", $report['skipped']);
        $this->assertStringContainsString('compares against another field', $skipped);
        $this->assertStringContainsString("comparison 'RX' is not supported", $skipped);
        $this->assertStringContainsString('was not imported', $skipped);
        $this->assertStringContainsString('multiple-choice', $skipped);
    }

    /** A LimeSurvey 5-style export: text lives in *_l10ns tables. */
    private function modernLss(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<document>
 <LimeSurveyDocType>Survey</LimeSurveyDocType>
 <surveys><fields><fieldname>sid</fieldname><fieldname>language</fieldname></fields>
  <rows><row><sid>123</sid><language>en</language></row></rows></surveys>
 <surveys_languagesettings><fields><fieldname>surveyls_survey_id</fieldname><fieldname>surveyls_language</fieldname><fieldname>surveyls_title</fieldname></fields>
  <rows><row><surveyls_survey_id>123</surveyls_survey_id><surveyls_language>en</surveyls_language><surveyls_title><![CDATA[My Conference]]></surveyls_title></row></rows></surveys_languagesettings>
 <groups><fields><fieldname>gid</fieldname><fieldname>sid</fieldname><fieldname>group_order</fieldname></fields>
  <rows>
   <row><gid>1</gid><sid>123</sid><group_order>0</group_order></row>
   <row><gid>2</gid><sid>123</sid><group_order>1</group_order></row>
  </rows></groups>
 <group_l10ns><fields><fieldname>gid</fieldname><fieldname>group_name</fieldname><fieldname>description</fieldname><fieldname>language</fieldname></fields>
  <rows>
   <row><gid>1</gid><group_name><![CDATA[Personal]]></group_name><description><![CDATA[]]></description><language>en</language></row>
   <row><gid>1</gid><group_name><![CDATA[Personnel]]></group_name><description><![CDATA[Vos coordonnées]]></description><language>fr</language></row>
   <row><gid>2</gid><group_name><![CDATA[Preferences]]></group_name><description><![CDATA[]]></description><language>en</language></row>
  </rows></group_l10ns>
 <questions><fields><fieldname>qid</fieldname><fieldname>parent_qid</fieldname><fieldname>sid</fieldname><fieldname>gid</fieldname><fieldname>type</fieldname><fieldname>title</fieldname><fieldname>mandatory</fieldname><fieldname>other</fieldname><fieldname>question_order</fieldname></fields>
  <rows>
   <row><qid>10</qid><parent_qid>0</parent_qid><sid>123</sid><gid>1</gid><type>S</type><title>firstname</title><mandatory>Y</mandatory><other>N</other><question_order>0</question_order></row>
   <row><qid>11</qid><parent_qid>0</parent_qid><sid>123</sid><gid>1</gid><type>L</type><title>country</title><mandatory>N</mandatory><other>N</other><question_order>1</question_order></row>
   <row><qid>12</qid><parent_qid>0</parent_qid><sid>123</sid><gid>2</gid><type>M</type><title>meals</title><mandatory>N</mandatory><other>N</other><question_order>0</question_order></row>
   <row><qid>13</qid><parent_qid>12</parent_qid><sid>123</sid><gid>2</gid><type>T</type><title>SQ001</title><mandatory>N</mandatory><other>N</other><question_order>0</question_order></row>
   <row><qid>14</qid><parent_qid>12</parent_qid><sid>123</sid><gid>2</gid><type>T</type><title>SQ002</title><mandatory>N</mandatory><other>N</other><question_order>1</question_order></row>
   <row><qid>15</qid><parent_qid>0</parent_qid><sid>123</sid><gid>2</gid><type>F</type><title>matrix</title><mandatory>N</mandatory><other>N</other><question_order>1</question_order></row>
   <row><qid>16</qid><parent_qid>0</parent_qid><sid>123</sid><gid>2</gid><type>T</type><title>comments</title><mandatory>N</mandatory><other>N</other><question_order>2</question_order></row>
  </rows></questions>
 <question_l10ns><fields><fieldname>qid</fieldname><fieldname>question</fieldname><fieldname>help</fieldname><fieldname>language</fieldname></fields>
  <rows>
   <row><qid>10</qid><question><![CDATA[<p>First name</p>]]></question><help><![CDATA[As on your passport]]></help><language>en</language></row>
   <row><qid>10</qid><question><![CDATA[<p>Prénom</p>]]></question><help><![CDATA[Comme sur votre passeport]]></help><language>fr</language></row>
   <row><qid>11</qid><question><![CDATA[Country]]></question><help><![CDATA[]]></help><language>en</language></row>
   <row><qid>12</qid><question><![CDATA[Meal choices]]></question><help><![CDATA[]]></help><language>en</language></row>
   <row><qid>13</qid><question><![CDATA[Vegetarian]]></question><help><![CDATA[]]></help><language>en</language></row>
   <row><qid>13</qid><question><![CDATA[Végétarien]]></question><help><![CDATA[]]></help><language>fr</language></row>
   <row><qid>14</qid><question><![CDATA[Vegan]]></question><help><![CDATA[]]></help><language>en</language></row>
   <row><qid>16</qid><question><![CDATA[Anything else?]]></question><help><![CDATA[]]></help><language>en</language></row>
  </rows></question_l10ns>
 <answers><fields><fieldname>aid</fieldname><fieldname>qid</fieldname><fieldname>code</fieldname><fieldname>sortorder</fieldname><fieldname>scale_id</fieldname></fields>
  <rows>
   <row><aid>100</aid><qid>11</qid><code>uk</code><sortorder>0</sortorder><scale_id>0</scale_id></row>
   <row><aid>101</aid><qid>11</qid><code>us</code><sortorder>1</sortorder><scale_id>0</scale_id></row>
  </rows></answers>
 <answer_l10ns><fields><fieldname>aid</fieldname><fieldname>answer</fieldname><fieldname>language</fieldname></fields>
  <rows>
   <row><aid>100</aid><answer><![CDATA[United Kingdom]]></answer><language>en</language></row>
   <row><aid>100</aid><answer><![CDATA[Royaume-Uni]]></answer><language>fr</language></row>
   <row><aid>101</aid><answer><![CDATA[United States]]></answer><language>en</language></row>
  </rows></answer_l10ns>
 <conditions><fields><fieldname>cid</fieldname><fieldname>qid</fieldname><fieldname>cqid</fieldname><fieldname>cfieldname</fieldname><fieldname>method</fieldname><fieldname>value</fieldname><fieldname>scenario</fieldname></fields>
  <rows>
   <row><cid>1</cid><qid>16</qid><cqid>11</cqid><cfieldname>123X1X11</cfieldname><method>==</method><value>uk</value><scenario>1</scenario></row>
  </rows></conditions>
</document>
XML;
    }

    /**
     * A survey whose texts carry LimeSurvey answer references ({Code.value},
     * {Code.shown}, {Code.NAOK} — in mixed case), an {if(...)} expression, a
     * plain {Variable} token and a reference to a code that is not imported.
     */
    private function tokenLss(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<document>
 <surveys><fields><fieldname>sid</fieldname><fieldname>language</fieldname></fields>
  <rows><row><sid>300</sid><language>en</language></row></rows></surveys>
 <groups><fields><fieldname>gid</fieldname><fieldname>sid</fieldname><fieldname>group_order</fieldname></fields>
  <rows><row><gid>1</gid><sid>300</sid><group_order>0</group_order></row></rows></groups>
 <group_l10ns><fields><fieldname>gid</fieldname><fieldname>group_name</fieldname><fieldname>language</fieldname></fields>
  <rows><row><gid>1</gid><group_name><![CDATA[Main]]></group_name><language>en</language></row></rows></group_l10ns>
 <questions><fields><fieldname>qid</fieldname><fieldname>parent_qid</fieldname><fieldname>sid</fieldname><fieldname>gid</fieldname><fieldname>type</fieldname><fieldname>title</fieldname><fieldname>mandatory</fieldname><fieldname>other</fieldname><fieldname>question_order</fieldname></fields>
  <rows>
   <row><qid>10</qid><parent_qid>0</parent_qid><sid>300</sid><gid>1</gid><type>S</type><title>firstname</title><mandatory>Y</mandatory><other>N</other><question_order>0</question_order></row>
   <row><qid>11</qid><parent_qid>0</parent_qid><sid>300</sid><gid>1</gid><type>L</type><title>Gender</title><mandatory>N</mandatory><other>N</other><question_order>1</question_order></row>
   <row><qid>12</qid><parent_qid>0</parent_qid><sid>300</sid><gid>1</gid><type>S</type><title>summary</title><mandatory>N</mandatory><other>N</other><question_order>2</question_order></row>
  </rows></questions>
 <question_l10ns><fields><fieldname>qid</fieldname><fieldname>question</fieldname><fieldname>help</fieldname><fieldname>language</fieldname></fields>
  <rows>
   <row><qid>10</qid><question><![CDATA[First name]]></question><help><![CDATA[]]></help><language>en</language></row>
   <row><qid>11</qid><question><![CDATA[Gender]]></question><help><![CDATA[]]></help><language>en</language></row>
   <row><qid>12</qid><question><![CDATA[Hello {firstname.value}, gender {GENDER.shown}, again {firstname.NAOK}, keep {if(Gender.value == "M", "Mr", "Ms")} and {Airport} and {unknown.value}]]></question><help><![CDATA[You said {firstname.value}]]></help><language>en</language></row>
   <row><qid>12</qid><question><![CDATA[Bonjour {FirstName.value}]]></question><help><![CDATA[]]></help><language>fr</language></row>
  </rows></question_l10ns>
 <answers><fields><fieldname>aid</fieldname><fieldname>qid</fieldname><fieldname>code</fieldname><fieldname>sortorder</fieldname><fieldname>scale_id</fieldname></fields>
  <rows><row><aid>400</aid><qid>11</qid><code>M</code><sortorder>0</sortorder><scale_id>0</scale_id></row></rows></answers>
 <answer_l10ns><fields><fieldname>aid</fieldname><fieldname>answer</fieldname><fieldname>language</fieldname></fields>
  <rows><row><aid>400</aid><answer><![CDATA[Same as {firstname.value}]]></answer><language>en</language></row></rows></answer_l10ns>
</document>
XML;
    }

    /** A LimeSurvey 2-style export: question/answer text lives inline on the rows. */
    private function legacyLss(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<document>
 <surveys><fields><fieldname>sid</fieldname><fieldname>language</fieldname></fields>
  <rows><row><sid>9</sid><language>en</language></row></rows></surveys>
 <groups><fields><fieldname>gid</fieldname><fieldname>sid</fieldname><fieldname>group_name</fieldname><fieldname>group_order</fieldname></fields>
  <rows><row><gid>1</gid><sid>9</sid><group_name><![CDATA[Contact]]></group_name><group_order>0</group_order></row></rows></groups>
 <questions><fields><fieldname>qid</fieldname><fieldname>parent_qid</fieldname><fieldname>sid</fieldname><fieldname>gid</fieldname><fieldname>type</fieldname><fieldname>title</fieldname><fieldname>question</fieldname><fieldname>help</fieldname><fieldname>mandatory</fieldname><fieldname>other</fieldname><fieldname>question_order</fieldname></fields>
  <rows>
   <row><qid>5</qid><parent_qid>0</parent_qid><sid>9</sid><gid>1</gid><type>S</type><title>email</title><question><![CDATA[Email address]]></question><help><![CDATA[]]></help><mandatory>Y</mandatory><other>N</other><question_order>0</question_order></row>
  </rows></questions>
</document>
XML;
    }

    /**
     * A LimeSurvey 2-style MULTILINGUAL export: the group/question/answer rows
     * are repeated once per language, with the text inline and a language
     * column on every row. The French group row comes first to prove the base
     * (en) row still wins the entity columns.
     */
    private function legacyMultilingualLss(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<document>
 <surveys><fields><fieldname>sid</fieldname><fieldname>language</fieldname><fieldname>additional_languages</fieldname></fields>
  <rows><row><sid>9</sid><language>en</language><additional_languages>fr</additional_languages></row></rows></surveys>
 <groups><fields><fieldname>gid</fieldname><fieldname>sid</fieldname><fieldname>group_name</fieldname><fieldname>group_order</fieldname><fieldname>language</fieldname></fields>
  <rows>
   <row><gid>1</gid><sid>9</sid><group_name><![CDATA[Coordonnées]]></group_name><group_order>0</group_order><language>fr</language></row>
   <row><gid>1</gid><sid>9</sid><group_name><![CDATA[Contact]]></group_name><group_order>0</group_order><language>en</language></row>
  </rows></groups>
 <questions><fields><fieldname>qid</fieldname><fieldname>parent_qid</fieldname><fieldname>sid</fieldname><fieldname>gid</fieldname><fieldname>type</fieldname><fieldname>title</fieldname><fieldname>question</fieldname><fieldname>help</fieldname><fieldname>mandatory</fieldname><fieldname>other</fieldname><fieldname>question_order</fieldname><fieldname>language</fieldname></fields>
  <rows>
   <row><qid>5</qid><parent_qid>0</parent_qid><sid>9</sid><gid>1</gid><type>S</type><title>email</title><question><![CDATA[Email address]]></question><help><![CDATA[]]></help><mandatory>Y</mandatory><other>N</other><question_order>0</question_order><language>en</language></row>
   <row><qid>5</qid><parent_qid>0</parent_qid><sid>9</sid><gid>1</gid><type>S</type><title>email</title><question><![CDATA[Adresse électronique]]></question><help><![CDATA[]]></help><mandatory>Y</mandatory><other>N</other><question_order>0</question_order><language>fr</language></row>
   <row><qid>6</qid><parent_qid>0</parent_qid><sid>9</sid><gid>1</gid><type>L</type><title>lang</title><question><![CDATA[Interpreting needed?]]></question><help><![CDATA[]]></help><mandatory>N</mandatory><other>N</other><question_order>1</question_order><language>en</language></row>
   <row><qid>6</qid><parent_qid>0</parent_qid><sid>9</sid><gid>1</gid><type>L</type><title>lang</title><question><![CDATA[Interprétation nécessaire ?]]></question><help><![CDATA[]]></help><mandatory>N</mandatory><other>N</other><question_order>1</question_order><language>fr</language></row>
  </rows></questions>
 <answers><fields><fieldname>qid</fieldname><fieldname>code</fieldname><fieldname>answer</fieldname><fieldname>sortorder</fieldname><fieldname>scale_id</fieldname><fieldname>language</fieldname></fields>
  <rows>
   <row><qid>6</qid><code>y</code><answer><![CDATA[Yes]]></answer><sortorder>0</sortorder><scale_id>0</scale_id><language>en</language></row>
   <row><qid>6</qid><code>y</code><answer><![CDATA[Oui]]></answer><sortorder>0</sortorder><scale_id>0</scale_id><language>fr</language></row>
   <row><qid>6</qid><code>n</code><answer><![CDATA[No]]></answer><sortorder>1</sortorder><scale_id>0</scale_id><language>en</language></row>
   <row><qid>6</qid><code>n</code><answer><![CDATA[Non]]></answer><sortorder>1</sortorder><scale_id>0</scale_id><language>fr</language></row>
  </rows></answers>
</document>
XML;
    }

    /**
     * A survey exercising the fixed-option question types (5-point, gender,
     * yes/no), an "other" option, multi-scenario and multiple-choice conditions,
     * and every kind of condition the importer skips and reports.
     */
    private function edgeLss(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<document>
 <surveys><fields><fieldname>sid</fieldname><fieldname>language</fieldname></fields>
  <rows><row><sid>200</sid><language>en</language></row></rows></surveys>
 <surveys_languagesettings><fields><fieldname>surveyls_survey_id</fieldname><fieldname>surveyls_language</fieldname><fieldname>surveyls_title</fieldname></fields>
  <rows><row><surveyls_survey_id>200</surveyls_survey_id><surveyls_language>en</surveyls_language><surveyls_title><![CDATA[Edge Survey]]></surveyls_title></row></rows></surveys_languagesettings>
 <groups><fields><fieldname>gid</fieldname><fieldname>sid</fieldname><fieldname>group_order</fieldname></fields>
  <rows>
   <row><gid>1</gid><sid>200</sid><group_order>0</group_order></row>
   <row><sid>200</sid><group_order>1</group_order></row>
  </rows></groups>
 <group_l10ns><fields><fieldname>gid</fieldname><fieldname>group_name</fieldname><fieldname>description</fieldname><fieldname>language</fieldname></fields>
  <rows>
   <row><gid>1</gid><group_name><![CDATA[Groupe FR]]></group_name><description><![CDATA[]]></description><language>fr</language></row>
   <row><gid>1</gid><group_name><![CDATA[Edge Group]]></group_name><description><![CDATA[]]></description><language>en</language></row>
   <row><group_name><![CDATA[orphan]]></group_name><language>en</language></row>
  </rows></group_l10ns>
 <questions><fields><fieldname>qid</fieldname><fieldname>parent_qid</fieldname><fieldname>sid</fieldname><fieldname>gid</fieldname><fieldname>type</fieldname><fieldname>title</fieldname><fieldname>mandatory</fieldname><fieldname>other</fieldname><fieldname>question_order</fieldname></fields>
  <rows>
   <row><qid>20</qid><parent_qid>0</parent_qid><sid>200</sid><gid>1</gid><type>5</type><title>rating</title><mandatory>N</mandatory><other>N</other><question_order>0</question_order></row>
   <row><qid>21</qid><parent_qid>0</parent_qid><sid>200</sid><gid>1</gid><type>G</type><title>sex</title><mandatory>N</mandatory><other>N</other><question_order>1</question_order></row>
   <row><qid>22</qid><parent_qid>0</parent_qid><sid>200</sid><gid>1</gid><type>L</type><title>fruit</title><mandatory>N</mandatory><other>Y</other><question_order>2</question_order></row>
   <row><qid>23</qid><parent_qid>0</parent_qid><sid>200</sid><gid>1</gid><type>M</type><title>diet</title><mandatory>N</mandatory><other>N</other><question_order>3</question_order></row>
   <row><qid>24</qid><parent_qid>23</parent_qid><sid>200</sid><gid>1</gid><type>T</type><title>SQ001</title><mandatory>N</mandatory><other>N</other><question_order>0</question_order></row>
   <row><qid>25</qid><parent_qid>23</parent_qid><sid>200</sid><gid>1</gid><type>T</type><title>SQ002</title><mandatory>N</mandatory><other>N</other><question_order>1</question_order></row>
   <row><qid>26</qid><parent_qid>0</parent_qid><sid>200</sid><gid>1</gid><type>S</type><title>note</title><mandatory>N</mandatory><other>N</other><question_order>4</question_order></row>
   <row><qid>27</qid><parent_qid>0</parent_qid><sid>200</sid><gid>1</gid><type>S</type><title>multi</title><mandatory>N</mandatory><other>N</other><question_order>5</question_order></row>
   <row><qid>28</qid><parent_qid>0</parent_qid><sid>200</sid><gid>1</gid><type>F</type><title>grid</title><mandatory>N</mandatory><other>N</other><question_order>6</question_order></row>
   <row><qid>29</qid><parent_qid>0</parent_qid><sid>200</sid><gid>1</gid><type>S</type><title>onlybad</title><mandatory>N</mandatory><other>N</other><question_order>7</question_order></row>
   <row><qid>30</qid><parent_qid>0</parent_qid><sid>200</sid><gid>1</gid><type>Y</type><title>attend</title><mandatory>N</mandatory><other>N</other><question_order>8</question_order></row>
   <row><qid>31</qid><parent_qid>0</parent_qid><sid>200</sid><gid>1</gid><type>S</type><title>whynot</title><mandatory>N</mandatory><other>N</other><question_order>9</question_order></row>
  </rows></questions>
 <answers><fields><fieldname>aid</fieldname><fieldname>qid</fieldname><fieldname>code</fieldname><fieldname>sortorder</fieldname><fieldname>scale_id</fieldname></fields>
  <rows>
   <row><aid>300</aid><qid>22</qid><code>apple</code><sortorder>0</sortorder><scale_id>0</scale_id></row>
   <row><aid>301</aid><qid>22</qid><code>pear</code><sortorder>1</sortorder><scale_id>0</scale_id></row>
  </rows></answers>
 <conditions><fields><fieldname>cid</fieldname><fieldname>qid</fieldname><fieldname>cqid</fieldname><fieldname>cfieldname</fieldname><fieldname>method</fieldname><fieldname>value</fieldname><fieldname>scenario</fieldname></fields>
  <rows>
   <row><cid>1</cid><qid>27</qid><cqid>22</cqid><cfieldname>200X1X22</cfieldname><method>==</method><value>apple</value><scenario>1</scenario></row>
   <row><cid>2</cid><qid>27</qid><cqid>22</cqid><cfieldname>200X1X22</cfieldname><method>==</method><value>pear</value><scenario>2</scenario></row>
   <row><cid>3</cid><qid>26</qid><cqid>23</cqid><cfieldname>200X1X23SQ001</cfieldname><method>==</method><value>Y</value><scenario>1</scenario></row>
   <row><cid>4</cid><qid>26</qid><cqid>22</cqid><cfieldname>200X1X22</cfieldname><method>==</method><value>@fruit</value><scenario>1</scenario></row>
   <row><cid>5</cid><qid>26</qid><cqid>22</cqid><cfieldname>200X1X22</cfieldname><method>RX</method><value>x</value><scenario>1</scenario></row>
   <row><cid>6</cid><qid>26</qid><cqid>28</cqid><cfieldname>200X1X28</cfieldname><method>==</method><value>x</value><scenario>1</scenario></row>
   <row><cid>7</cid><qid>26</qid><cqid>23</cqid><cfieldname>200X1X23SQ002</cfieldname><method>!=</method><value>Y</value><scenario>1</scenario></row>
   <row><cid>8</cid><qid>26</qid><cqid>23</cqid><cfieldname>garbage</cfieldname><method>==</method><value>Y</value><scenario>1</scenario></row>
   <row><cid>9</cid><qid>28</qid><cqid>22</cqid><cfieldname>200X1X22</cfieldname><method>==</method><value>apple</value><scenario>1</scenario></row>
   <row><cid>10</cid><qid>29</qid><cqid>28</cqid><cfieldname>200X1X28</cfieldname><method>==</method><value>x</value><scenario>1</scenario></row>
   <row><cid>11</cid><qid>31</qid><cqid>30</cqid><cfieldname>200X1X30</cfieldname><method>==</method><value>Y</value><scenario>1</scenario></row>
   <row><cid>12</cid><qid>31</qid><cqid>30</cqid><cfieldname>200X1X30</cfieldname><method>!=</method><value></value><scenario>1</scenario></row>
  </rows></conditions>
</document>
XML;
    }
}
