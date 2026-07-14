<?php

namespace ConferenceTools\Registration\Tests\Feature;

use ConferenceTools\Registration\Enums\BooleanOperator;
use ConferenceTools\Registration\Enums\ConditionOperator;
use ConferenceTools\Registration\Enums\QuestionScope;
use ConferenceTools\Registration\Enums\QuestionType;
use ConferenceTools\Registration\Models\Question;
use ConferenceTools\Registration\Models\Section;
use ConferenceTools\Registration\Services\QuestionnaireValidator;
use ConferenceTools\Registration\Support\Step;
use ConferenceTools\Registration\Tests\Concerns\BuildsRegistrationData;
use ConferenceTools\Registration\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * The wizard's security guarantee: cross-step visibility and validation are
 * decided on the server from the accumulated answers, so a tampered client
 * cannot reveal a hidden field or skip a required one.
 */
#[TestDox('Wizard Server Validation')]
class WizardServerValidationTest extends TestCase
{
    use BuildsRegistrationData, RefreshDatabase;

    /** The validator under test, resolved from the container. */
    private function validator(): QuestionnaireValidator
    {
        return $this->app->make(QuestionnaireValidator::class);
    }

    /**
     * Build a two-section flow where a question on the SECOND section is only
     * visible when a question on the FIRST section was answered "yes".
     */
    private function crossStepFlow(): array
    {
        $step1 = Section::factory()->create(['scope' => QuestionScope::Participant, 'position' => 0]);
        $trigger = Question::factory()->for($step1)->ofType(QuestionType::Radio)->create(['key' => 'has_diet', 'position' => 0]);
        $trigger->options()->createMany([
            ['value' => 'yes', 'label' => 'Yes', 'position' => 0],
            ['value' => 'no', 'label' => 'No', 'position' => 1],
        ]);

        $step2 = Section::factory()->create(['scope' => QuestionScope::Participant, 'position' => 1]);
        $dependent = Question::factory()->for($step2)->required()->create(['key' => 'diet_detail', 'position' => 0]);

        // diet_detail is visible (and required) only when has_diet == yes.
        $group = $dependent->conditionGroups()->create(['operator' => BooleanOperator::And->value]);
        $group->conditions()->create([
            'question_id' => $trigger->id,
            'operator' => ConditionOperator::Equals->value,
            'value' => 'yes',
        ]);

        $step2 = $step2->fresh(['questions.options', 'questions.conditionGroups.conditions.question']);

        return [new Step($step2, $step2->questions), $dependent];
    }

    #[TestDox('cross step required field is enforced when visible')]
    public function test_cross_step_required_field_is_enforced_when_visible(): void
    {
        [$step2] = $this->crossStepFlow();

        // Context says has_diet=yes (from step 1) → diet_detail required this step.
        $this->expectException(ValidationException::class);
        $this->validator()->validateStep($step2, ['diet_detail' => ''], ['has_diet' => 'yes']);
    }

    #[TestDox('cross step hidden field is not required')]
    public function test_cross_step_hidden_field_is_not_required(): void
    {
        [$step2] = $this->crossStepFlow();

        // Context says has_diet=no → diet_detail hidden → submitting nothing is fine.
        $validated = $this->validator()->validateStep($step2, [], ['has_diet' => 'no']);

        $this->assertArrayNotHasKey('diet_detail', $validated);
    }

    #[TestDox('tampered value for a hidden field is ignored')]
    public function test_tampered_value_for_a_hidden_field_is_ignored(): void
    {
        [$step2] = $this->crossStepFlow();

        // A tampered client posts diet_detail even though has_diet=no hides it; the
        // server builds no rule for it, so the value is dropped from the result.
        $validated = $this->validator()->validateStep($step2, ['diet_detail' => 'sneaky'], ['has_diet' => 'no']);

        $this->assertArrayNotHasKey('diet_detail', $validated);
    }
}
