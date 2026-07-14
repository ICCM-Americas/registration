<?php

namespace ConferenceTools\Registration\Tests\Feature;

use ConferenceTools\Registration\Enums\BooleanOperator;
use ConferenceTools\Registration\Enums\ConditionOperator;
use ConferenceTools\Registration\Enums\QuestionScope;
use ConferenceTools\Registration\Models\Answer;
use ConferenceTools\Registration\Models\Question;
use ConferenceTools\Registration\Services\CostSummaryBuilder;
use ConferenceTools\Registration\Services\VariableInterpolator;
use ConferenceTools\Registration\Tests\Concerns\BuildsRegistrationData;
use ConferenceTools\Registration\Tests\Concerns\WalksRegistrationWizard;
use ConferenceTools\Registration\Tests\Fixtures\QuestionConfigSeeder;
use ConferenceTools\Registration\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Conditionally-offered options: individual options of a choice question carry
 * their own visibility rules, evaluated against earlier answers. A value may be
 * shared by several variants that differ only in label (and even cost); the
 * variant actually offered is what renders, validates, prices and is recorded
 * on the stored answer.
 *
 * The fixture turns "accommodation" (step 2) into gender-dependent variants
 * (gender is asked on step 1):
 *
 *   hotel | Hotel (Ladies' Wing), 100     offered when gender == f
 *   hotel | Hotel (Gentlemen's Wing), 120 offered when gender == m
 *   dorm  | Dormitory, 10                 offered when gender == m
 *   none  | No accommodation, 0           unconditional
 */
#[TestDox('Conditional Options')]
class ConditionalOptionsTest extends TestCase
{
    use BuildsRegistrationData, RefreshDatabase, WalksRegistrationWizard;

    protected function setUp(): void
    {
        parent::setUp();

        $this->openRegistration();
        $this->defaultCurrency();
        $this->seed(QuestionConfigSeeder::class);
        $this->seedConditionalAccommodation();
    }

    /** Seed the accommodation question with a conditional option. */
    private function seedConditionalAccommodation(): void
    {
        $question = Question::firstWhere('key', 'accommodation');
        $question->options()->get()->each->delete();

        $rows = [
            ['hotel', "Hotel (Ladies' Wing)", 100, 'f'],
            ['hotel', "Hotel (Gentlemen's Wing)", 120, 'm'],
            ['dorm', 'Dormitory', 10, 'm'],
            ['none', 'No accommodation', 0, null],
        ];

        foreach ($rows as $position => [$value, $label, $cost, $gender]) {
            $option = $question->options()->create([
                'value' => $value, 'label' => $label, 'cost' => $cost, 'position' => $position,
            ]);

            if ($gender !== null) {
                $option->conditionGroups()
                    ->create(['operator' => BooleanOperator::And->value])
                    ->conditions()->create([
                        'question_id' => Question::firstWhere('key', 'gender')->id,
                        'operator' => ConditionOperator::Equals->value,
                        'value' => $gender,
                    ]);
            }
        }
    }

    /** Walk the wizard through step 1 with the given gender, landing on step 2. */
    private function reachAccommodationStep(string $gender): void
    {
        $this->actingAs($this->makeUser());

        $this->post(route('registration.register.store'), array_merge($this->fixtureAnswers(), [
            'gender' => $gender,
            '_step' => $this->currentWizardStep(),
            '_direction' => 'next',
        ]))->assertRedirect(route('registration.register'));
    }

    /**
     * @return iterable<string, array{string, array<int, string>, array<int, string>}>
     */
    public static function offeredVariants(): iterable
    {
        yield 'women see the ladies wing only' => [
            'f', ['Hotel (Ladies&#039; Wing)', 'No accommodation'], ['Gentlemen', 'Dormitory'],
        ];
        yield 'men see the gentlemen wing and the dormitory' => [
            'm', ['Hotel (Gentlemen&#039;s Wing)', 'Dormitory', 'No accommodation'], ['Ladies'],
        ];
    }

    /**
     * @param  array<int, string>  $offered
     * @param  array<int, string>  $notOffered
     */
    #[DataProvider('offeredVariants')]
    #[TestDox('the wizard offers only the variants matching earlier answers')]
    public function test_the_wizard_offers_only_the_variants_matching_earlier_answers(string $gender, array $offered, array $notOffered): void
    {
        $this->reachAccommodationStep($gender);

        $response = $this->get(route('registration.register'))->assertOk();

        foreach ($offered as $label) {
            $response->assertSee($label, false);
        }
        foreach ($notOffered as $label) {
            $response->assertDontSee($label, false);
        }
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function submittedValues(): iterable
    {
        yield 'a value never offered to this registrant is rejected' => ['f', 'dorm', false];
        yield 'a shared value passes for the first audience' => ['f', 'hotel', true];
        yield 'a shared value passes for the other audience' => ['m', 'hotel', true];
    }

    #[DataProvider('submittedValues')]
    #[TestDox('only currently offered values validate')]
    public function test_only_currently_offered_values_validate(string $gender, string $value, bool $ok): void
    {
        $this->reachAccommodationStep($gender);

        $response = $this->post(route('registration.register.store'), [
            'accommodation' => $value,
            '_step' => $this->currentWizardStep(),
            '_direction' => 'next',
        ]);

        $ok
            ? $response->assertSessionDoesntHaveErrors('accommodation')
            : $response->assertSessionHasErrors('accommodation');
    }

    /**
     * @return iterable<string, array{string, float}>
     */
    public static function storedVariants(): iterable
    {
        yield 'the ladies variant prices at 100 for women' => ['f', 100.0];
        yield 'the gentlemen variant prices at 120 for men' => ['m', 120.0];
    }

    /**
     * The two variants share one value ("hotel"), so the stored answer's text
     * can no longer distinguish which was offered — that distinction is
     * accepted as lost. What must still be correct is the snapshotted cost,
     * which is resolved from whichever variant actually matched the
     * registrant's earlier answers, not just the first row sharing the value.
     */
    #[DataProvider('storedVariants')]
    #[TestDox('the stored answer snapshots the offered variants cost')]
    public function test_the_stored_answer_snapshots_the_offered_variants_cost(string $gender, float $cost): void
    {
        $user = $this->makeUser();

        $this->storeAnswers($user, QuestionScope::Participant, array_merge($this->fixtureAnswers(), [
            'gender' => $gender, 'accommodation' => 'hotel',
        ]));

        $answer = Answer::where('question_id', Question::firstWhere('key', 'accommodation')->id)
            ->where('owner_id', $user->getKey())->first();

        $this->assertSame('hotel', $answer->value);
        $this->assertEqualsWithDelta($cost, (float) $answer->cost, 0.001);
    }

    /**
     * @return iterable<string, array{string, float, string}>
     */
    public static function pricedVariants(): iterable
    {
        yield 'women pay the ladies-wing price for "hotel"' => ['f', 100.0, '100.00'];
        yield 'men pay the gentlemen-wing price for "hotel"' => ['m', 120.0, '120.00'];
    }

    #[DataProvider('pricedVariants')]
    #[TestDox('the offered variant drives pricing and interpolation')]
    public function test_the_offered_variant_drives_pricing_and_interpolation(string $gender, float $total, string $costToken): void
    {
        $answers = array_merge($this->fixtureAnswers(), ['gender' => $gender, 'accommodation' => 'hotel']);

        $this->assertSame($total, app(CostSummaryBuilder::class)->fromAnswers($answers)->total);

        $interpolator = app(VariableInterpolator::class);
        $interpolator->setAnswers($answers);
        $this->assertSame($costToken, $interpolator->interpolate('{q:accommodation.cost}'));
        $this->assertStringContainsString(
            $gender === 'f' ? 'Ladies' : 'Gentlemen',
            $interpolator->interpolate('{q:accommodation.value}'),
        );
    }

    #[TestDox('a question left with no offered options is skipped entirely')]
    public function test_a_question_left_with_no_offered_options_is_skipped_entirely(): void
    {
        // Strand every accommodation option behind gender == m, then register
        // as a woman: the (required) question must neither render nor block.
        $question = Question::firstWhere('key', 'accommodation');
        $question->options()->get()->each->delete();
        $question->options()->create(['value' => 'none', 'label' => 'No accommodation', 'cost' => 0, 'position' => 0])
            ->conditionGroups()
            ->create(['operator' => BooleanOperator::And->value])
            ->conditions()->create([
                'question_id' => Question::firstWhere('key', 'gender')->id,
                'operator' => ConditionOperator::Equals->value,
                'value' => 'm',
            ]);

        $this->reachAccommodationStep('f');

        // Hidden row: rendered collapsed, with no option offered.
        $this->get(route('registration.register'))
            ->assertOk()
            ->assertDontSee('No accommodation', false);

        // And submitting the step without it passes despite "required".
        $this->post(route('registration.register.store'), [
            '_step' => $this->currentWizardStep(),
            '_direction' => 'next',
        ])->assertSessionDoesntHaveErrors('accommodation');
    }
}
