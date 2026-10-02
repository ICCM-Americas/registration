<?php

namespace ConferenceTools\Registration\Tests\Concerns;

use ConferenceTools\Registration\Enums\ConditionOperator;
use ConferenceTools\Registration\Enums\GuestType;
use ConferenceTools\Registration\Models\ConditionGroup;
use ConferenceTools\Registration\Models\Draft;
use ConferenceTools\Registration\Models\Question;
use ConferenceTools\Registration\Models\QuestionOption;
use ConferenceTools\Registration\Models\Report;
use ConferenceTools\Registration\Models\ReportColumn;
use ConferenceTools\Registration\Models\Section;
use Illuminate\Database\Eloquent\Model;

/**
 * A questionnaire, report, and registrants for the admin search tests, where
 * every searchable text carries its own unique token (e.g. "alphaqlabel" only
 * in a question's label), so a search for one token finds exactly one place.
 */
trait BuildsSearchData
{
    use BuildsReportData;

    /**
     * Seed the searchable data and return its models by name.
     *
     * @return array<string, Model>
     */
    protected function seedSearchData(): array
    {
        $this->defaultCurrency();
        $this->seedReportQuestions();
        $gender = Question::where('key', 'gender')->firstOrFail();

        $section = Section::factory()->create(['key' => 'sect-alphaskey', 'title' => 'Sect alphastitle', 'description' => 'alphasdesc', 'position' => 9]);
        $question = Question::factory()->create([
            'section_id' => $section->id, 'key' => 'alphaqkey', 'label' => 'Q alphaqlabel',
            'help_text' => 'alphahelp', 'placeholder' => 'alphaplace',
        ]);
        $option = QuestionOption::factory()->create(['question_id' => $question->id, 'value' => 'alphaoval', 'label' => 'alphaolabel', 'description' => 'alphaodesc']);

        $section->storeTranslation('fr', 'title', 'alphastrans');
        $question->storeTranslation('fr', 'label', 'alphaqtrans');
        $option->storeTranslation('fr', 'label', 'alphaotrans');

        $this->ruleOn($section, $gender, 'alphasrule');
        $this->ruleOn($question, $gender, 'alphaqrule');
        $this->ruleOn($option, $gender, 'alphaorule', nested: true);

        $report = Report::factory()->create(['name' => 'alpharname', 'description' => 'alpharddesc', 'header' => 'alpharhead', 'footer' => 'alpharfoot']);
        $column = ReportColumn::factory()->mapped(['alphamapval' => 'alphamaptext'])->create(['report_id' => $report->id, 'question_id' => $gender->id, 'header' => 'alphacolhead']);
        ReportColumn::factory()->create(['report_id' => $report->id, 'question_id' => $gender->id, 'position' => 1]);
        $this->ruleOn($report, $gender, 'alpharrule');
        $this->ruleOn($column, $gender, 'alphacrule');

        $registrant = $this->makeRegistrant('Ada', 'Lovelace', ['nickname' => 'alphaanswer']);
        $guest = $this->makeGuest($registrant, GuestType::Adult, ['guestname' => 'alphaguest']);

        $drafter = $this->makeUser(['email' => 'drafter@example.com']);
        $draft = Draft::factory()->create([
            'user_id' => $drafter->id,
            'answers' => ['nickname' => 'alphadraft', 'products' => ['dinner', 'alphalist'], 'retiredkey' => 'alpharetired'],
            'guests' => [['id' => 'dg-1', 'type' => GuestType::Minor->value, 'answers' => ['guestname' => 'alphadguest']]],
        ]);

        return compact('section', 'question', 'option', 'report', 'column', 'registrant', 'guest', 'drafter', 'draft', 'gender');
    }

    /** Give a model a one-condition visibility rule testing the controlling question against a value, optionally inside a nested group. */
    protected function ruleOn(Model $owner, Question $controlling, string $value, bool $nested = false): void
    {
        $group = $owner->conditionGroups()->create(['operator' => 'and']);
        if ($nested) {
            $group = ConditionGroup::create(['parent_group_id' => $group->id, 'operator' => 'or']);
        }

        $group->conditions()->create(['question_id' => $controlling->id, 'operator' => ConditionOperator::Equals->value, 'value' => $value]);
    }

    /** The search page URL for a term across the given places. */
    protected function searchUrl(string $term, array $places, array $extra = []): string
    {
        return route('registration.admin.search', ['q' => $term, 'in' => $places] + $extra);
    }
}
