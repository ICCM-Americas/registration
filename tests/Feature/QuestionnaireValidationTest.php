<?php

namespace ConferenceTools\Registration\Tests\Feature;

use ConferenceTools\Registration\Enums\QuestionScope;
use ConferenceTools\Registration\Enums\QuestionType;
use ConferenceTools\Registration\Models\Answer;
use ConferenceTools\Registration\Models\DiscountCode;
use ConferenceTools\Registration\Models\Group;
use ConferenceTools\Registration\Models\Question;
use ConferenceTools\Registration\Models\Section;
use ConferenceTools\Registration\Services\AnswerStore;
use ConferenceTools\Registration\Services\QuestionnaireValidator;
use ConferenceTools\Registration\Tests\Concerns\BuildsRegistrationData;
use ConferenceTools\Registration\Tests\Fixtures\QuestionConfigSeeder;
use ConferenceTools\Registration\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\TestDox;

/** Feature tests for Questionnaire Validation. */
#[TestDox('Questionnaire Validation')]
class QuestionnaireValidationTest extends TestCase
{
    use BuildsRegistrationData, RefreshDatabase;

    /** Seed the questions these tests need. */
    private function seedQuestions(): void
    {
        $this->defaultCurrency();
        $this->seed(QuestionConfigSeeder::class);
    }

    /** The validator under test, resolved from the container. */
    private function validator(): QuestionnaireValidator
    {
        return $this->app->make(QuestionnaireValidator::class);
    }

    #[TestDox('seeder reproduces the legacy participant fields')]
    public function test_seeder_reproduces_the_legacy_participant_fields(): void
    {
        $this->seedQuestions();

        // The protected system-questions (guest_registering, group_registering,
        // seeded by migration) are also Participant-scope; excluded here,
        // unrelated to this seeder's own fields.
        $keys = Question::query()
            ->where('is_system', false)
            ->whereHas('section', fn ($s) => $s->where('scope', QuestionScope::Participant->value))
            ->pluck('key')->all();

        $this->assertEqualsCanonicalizing(
            ['name', 'lastname', 'nickname', 'passport', 'gender', 'residence', 'accommodation', 'products'],
            $keys
        );
    }

    #[TestDox('participant validation requires core fields')]
    public function test_participant_validation_requires_core_fields(): void
    {
        $this->seedQuestions();

        $this->expectException(ValidationException::class);
        $this->validator()->validate(QuestionScope::Participant, ['nickname' => 'Speedy']);
    }

    #[TestDox('participant validation passes with valid answers')]
    public function test_participant_validation_passes_with_valid_answers(): void
    {
        $this->seedQuestions();

        $validated = $this->validator()->validate(QuestionScope::Participant, [
            'name' => 'Ada',
            'lastname' => 'Lovelace',
            'passport' => 'Ada Lovelace',
            'gender' => 'f',
            'residence' => 'UK',
            'accommodation' => 'hotel',
            Question::GUEST_TRIGGER_KEY => 'No',
            Question::GROUP_TRIGGER_KEY => 'No',
        ]);

        $this->assertSame('Ada', $validated['name']);
        $this->assertSame('f', $validated['gender']);
    }

    #[TestDox('gender must be a configured option')]
    public function test_gender_must_be_a_configured_option(): void
    {
        $this->seedQuestions();

        $this->expectException(ValidationException::class);
        $this->validator()->validate(QuestionScope::Participant, [
            'name' => 'Ada', 'lastname' => 'Lovelace', 'passport' => 'Ada',
            'gender' => 'x', 'residence' => 'UK', 'accommodation' => 'hotel',
        ]);
    }

    #[TestDox('orgtypeother is only required when other is selected')]
    public function test_orgtypeother_is_only_required_when_other_is_selected(): void
    {
        $this->seedQuestions();

        $base = [
            'organization' => 'Analytical Engines', 'orgtype' => 'business',
            'address' => '1 Way', 'town' => 'London', 'zipcode' => 'EC1',
            'country' => 'UK', 'telephone' => '123',
        ];

        // orgtype = business → orgtypeother hidden, not required.
        $validated = $this->validator()->validate(QuestionScope::Group, $base);
        $this->assertArrayNotHasKey('orgtypeother', $validated);

        // orgtype = other → orgtypeother now required.
        $this->expectException(ValidationException::class);
        $this->validator()->validate(QuestionScope::Group, array_merge($base, ['orgtype' => 'other']));
    }

    #[TestDox('orgtypeother accepted when other is selected')]
    public function test_orgtypeother_accepted_when_other_is_selected(): void
    {
        $this->seedQuestions();

        $validated = $this->validator()->validate(QuestionScope::Group, [
            'organization' => 'Analytical Engines', 'orgtype' => 'other', 'orgtypeother' => 'Research lab',
            'address' => '1 Way', 'town' => 'London', 'zipcode' => 'EC1', 'country' => 'UK', 'telephone' => '123',
        ]);

        $this->assertSame('Research lab', $validated['orgtypeother']);
    }

    #[TestDox('discount code answer must match a configured code')]
    public function test_discount_code_answer_must_match_a_configured_code(): void
    {
        $this->seedQuestions();

        // Attach an optional discount-code question to the participant scope.
        $section = Section::where('scope', QuestionScope::Participant->value)->first();
        Question::factory()->for($section)->create([
            'key' => 'promo', 'type' => QuestionType::DiscountCode, 'label' => 'Promo code',
            'required' => false, 'position' => 99,
        ]);
        DiscountCode::factory()->create(['code' => 'SAVE10', 'enabled' => true]);

        $base = [
            'name' => 'Ada', 'lastname' => 'Lovelace', 'passport' => 'Ada', 'gender' => 'f',
            'residence' => 'UK', 'accommodation' => 'hotel',
            Question::GUEST_TRIGGER_KEY => 'No', Question::GROUP_TRIGGER_KEY => 'No',
        ];

        // Blank is fine (the code is optional) — including whitespace-only, which
        // Laravel treats as blank and skips the rule for — and a configured code
        // passes, matched case-insensitively and ignoring surrounding whitespace.
        $this->validator()->validate(QuestionScope::Participant, $base + ['promo' => '']);
        $this->validator()->validate(QuestionScope::Participant, $base + ['promo' => ' ']);
        $validated = $this->validator()->validate(QuestionScope::Participant, $base + ['promo' => ' save10 ']);
        $this->assertSame(' save10 ', $validated['promo']);

        // An unknown code is rejected.
        $this->expectException(ValidationException::class);
        $this->validator()->validate(QuestionScope::Participant, $base + ['promo' => 'NOPE']);
    }

    #[TestDox('answers are stored as eav against the owner')]
    public function test_answers_are_stored_as_eav_against_the_owner(): void
    {
        $this->seedQuestions();
        $user = $this->makeUser();

        $this->app->make(AnswerStore::class)->store(QuestionScope::Participant, $user, [
            'name' => 'Ada',
            'lastname' => 'Lovelace',
            'passport' => 'Ada Lovelace',
            'gender' => 'f',
            'residence' => 'UK',
            'accommodation' => 'hotel',
        ]);

        $nameAnswer = Answer::whereHas('question', fn ($q) => $q->where('key', 'name'))
            ->where('owner_type', $user->getMorphClass())->where('owner_id', $user->getKey())->first();
        $this->assertSame('Ada', $nameAnswer->value);

        // The accommodation answer snapshots the chosen option's cost.
        $accAnswer = Answer::whereHas('question', fn ($q) => $q->where('key', 'accommodation'))
            ->where('owner_id', $user->getKey())->first();
        $this->assertEqualsWithDelta(100.0, (float) $accAnswer->cost, 0.001);
    }

    #[TestDox('hidden question answer is not stored')]
    public function test_hidden_question_answer_is_not_stored(): void
    {
        $this->seedQuestions();
        $group = Group::factory()->create();

        $this->app->make(AnswerStore::class)->store(QuestionScope::Group, $group, [
            'organization' => 'Engines', 'orgtype' => 'business', 'orgtypeother' => 'ignored',
            'address' => '1 Way', 'town' => 'London', 'zipcode' => 'EC1', 'country' => 'UK', 'telephone' => '123',
        ]);

        $stored = Answer::whereHas('question', fn ($q) => $q->where('key', 'orgtypeother'))
            ->where('owner_id', $group->getKey())->exists();
        $this->assertFalse($stored);
    }
}
