<?php

namespace ConferenceTools\Registration\Tests\Fixtures;

use ConferenceTools\Registration\Enums\BooleanOperator;
use ConferenceTools\Registration\Enums\ConditionOperator;
use ConferenceTools\Registration\Enums\Gender;
use ConferenceTools\Registration\Enums\QuestionScope;
use ConferenceTools\Registration\Enums\QuestionType;
use ConferenceTools\Registration\Models\Question;
use ConferenceTools\Registration\Models\Section;
use Illuminate\Database\Seeder;

/**
 * Test-only seeder that builds a representative registration form in the
 * configurable question infrastructure. The package itself ships no default-form
 * seeder (a host builds its form through the admin form builder); the feature
 * tests use this fixture to stand up a known questionnaire to exercise.
 *
 * Each question's key matches the input name the form uses (name, lastname,
 * gender, accommodation, organization, orgtype …). Idempotent: re-running updates
 * the same sections/questions in place.
 */
class QuestionConfigSeeder extends Seeder
{
    /** Run the linter over the template. */
    public function run(): void
    {
        $this->seedParticipantDetails();
        $this->seedAccommodationAndProducts();
        $this->seedGroupDetails();
    }

    /** Participant-scope profile questions (stored against the host user). */
    private function seedParticipantDetails(): void
    {
        $section = $this->section(QuestionScope::Participant, 'your-details', 'Your details', 0);

        $this->question($section, 'name', QuestionType::Text, 'First Name', 0, true);
        $this->question($section, 'lastname', QuestionType::Text, 'Last Name', 1, true);
        $this->question($section, 'nickname', QuestionType::Text, 'Nickname', 2, false, [
            'help_text' => 'Used in place of your name on printed attendee lists.',
        ]);
        $this->question($section, 'passport', QuestionType::Text, 'Name on Passport', 3, true);

        $gender = $this->question($section, 'gender', QuestionType::Radio, 'Gender', 4, true);
        $this->options($gender, [
            [Gender::Male->value, 'Male'],
            [Gender::Female->value, 'Female'],
        ]);

        $this->question($section, 'residence', QuestionType::Text, 'Country of residence', 5, true);
    }

    /**
     * The priced questions, folded into the question system: accommodation (a
     * required single choice) and additional products (an optional multi choice),
     * both with static priced options that admins manage from the form builder.
     */
    private function seedAccommodationAndProducts(): void
    {
        $section = $this->section(QuestionScope::Participant, 'accommodation', 'Accommodation & extras', 1);

        $accommodation = $this->question($section, 'accommodation', QuestionType::Radio, 'Accommodation', 0, true);
        $this->options($accommodation, [
            ['hotel', 'Hotel', 100],
            ['none', 'No accommodation', 0],
        ]);

        // A multi-select of priced extras. The form renders one checkbox per option
        // named "product_{value}" (input_name_per_option); QuestionRepository
        // collapses those back into the single "products" answer.
        $products = $this->question($section, 'products', QuestionType::Checkbox, 'Additional products', 1, false, [
            'input_name_per_option' => true,
            'input_name_prefix' => 'product_',
        ]);
        $this->options($products, [
            ['dinner', 'Dinner', 20],
        ]);
    }

    /** Group-scope organization / billing questions (stored against the Group). */
    private function seedGroupDetails(): void
    {
        $section = $this->section(QuestionScope::Group, 'organization', 'Organization', 0);

        $this->question($section, 'organization', QuestionType::Text, 'Organization', 0, true, [
            'help_text' => "If you don't have an organization, use your last name.",
        ]);
        $this->question($section, 'website', QuestionType::Url, 'Website', 1, false);

        $orgtype = $this->question($section, 'orgtype', QuestionType::Radio, 'Organization Type', 2, true);
        $this->options($orgtype, [
            ['mission', 'Mission'],
            ['church', 'Church'],
            ['education', 'Education'],
            ['business', 'Business'],
            ['non-profit', 'Non-profit'],
            ['other', 'Other, please specify'],
        ]);

        // Shown (and required) only when "Other" is chosen — configurable
        // conditional visibility.
        $orgtypeother = $this->question($section, 'orgtypeother', QuestionType::Text, 'Other organization type', 3, true);
        $this->visibleWhen($orgtypeother, $orgtype, ConditionOperator::Equals, 'other');

        $this->question($section, 'address', QuestionType::Text, 'Billing Address', 4, true);
        $this->question($section, 'town', QuestionType::Text, 'Town/City', 5, true);
        $this->question($section, 'state', QuestionType::Text, 'Province/County/State', 6, false);
        $this->question($section, 'zipcode', QuestionType::Text, 'Postcode/Zip', 7, true);
        $this->question($section, 'country', QuestionType::Text, 'Country', 8, true);
        $this->question($section, 'telephone', QuestionType::Text, 'Telephone', 9, true);
    }

    /** A section in the given scope. */
    private function section(QuestionScope $scope, string $key, string $title, int $position): Section
    {
        return Section::updateOrCreate(
            ['scope' => $scope->value, 'key' => $key],
            ['title' => $title, 'position' => $position, 'enabled' => true],
        );
    }

    /**
     * @param  array<string, mixed>  $extra  optional help_text/placeholder; any
     *                                       other keys become the question config.
     */
    private function question(
        Section $section,
        string $key,
        QuestionType $type,
        string $label,
        int $position,
        bool $required = false,
        array $extra = [],
    ): Question {
        $helpText = $extra['help_text'] ?? null;
        $placeholder = $extra['placeholder'] ?? null;
        $config = array_diff_key($extra, array_flip(['help_text', 'placeholder']));

        return Question::updateOrCreate(
            ['section_id' => $section->id, 'key' => $key],
            [
                'type' => $type->value,
                'label' => $label,
                'position' => $position,
                'required' => $required,
                'enabled' => true,
                'help_text' => $helpText,
                'placeholder' => $placeholder,
                'config' => $config ?: null,
            ],
        );
    }

    /**
     * Replace a question's static options. Each option is [value, label] or
     * [value, label, cost] for a priced option.
     *
     * @param  array<int, array{0:string,1:string,2?:float}>  $options
     */
    private function options(Question $question, array $options): void
    {
        $question->options()->delete();

        foreach ($options as $position => $option) {
            $question->options()->create([
                'value' => $option[0],
                'label' => $option[1],
                'cost' => $option[2] ?? null,
                'position' => $position,
            ]);
        }
    }

    /** Make $question visible only when $controlling's answer matches. */
    private function visibleWhen(
        Question $question,
        Question $controlling,
        ConditionOperator $operator,
        ?string $value,
    ): void {
        $question->conditionGroups()->delete();

        $group = $question->conditionGroups()->create(['operator' => BooleanOperator::And->value]);
        $group->conditions()->create([
            'question_id' => $controlling->id,
            'operator' => $operator->value,
            'value' => $value,
        ]);
    }
}
