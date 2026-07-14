<?php

namespace ConferenceTools\Registration\Tests\Feature;

use ConferenceTools\Registration\Enums\PerDiemScope;
use ConferenceTools\Registration\Enums\QuestionScope;
use ConferenceTools\Registration\Enums\QuestionType;
use ConferenceTools\Registration\Models\Group;
use ConferenceTools\Registration\Models\Question;
use ConferenceTools\Registration\Models\Section;
use ConferenceTools\Registration\Services\AnswerStore;
use ConferenceTools\Registration\Tests\Concerns\BuildsRegistrationData;
use ConferenceTools\Registration\Tests\Fixtures\QuestionConfigSeeder;
use ConferenceTools\Registration\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * The EAV read model: a registrant's / group's configured answers read straight
 * from the answer store — every value already report-ready (resolved and
 * interpolated at write time) — including priced-option totals.
 */
#[TestDox('Answer Bag')]
class AnswerBagTest extends TestCase
{
    use BuildsRegistrationData, RefreshDatabase;

    /** Seed the questionnaire and store the given answers. */
    private function seedAndStore(): void
    {
        $this->defaultCurrency();
        $this->seed(QuestionConfigSeeder::class);
    }

    #[TestDox('reads a registrants answers with stored text and cost')]
    public function test_reads_a_registrants_answers_with_stored_text_and_cost(): void
    {
        $this->seedAndStore();
        $user = $this->makeUser();

        $this->app->make(AnswerStore::class)->store(QuestionScope::Participant, $user, [
            'name' => 'Ada', 'lastname' => 'Lovelace', 'passport' => 'Ada Lovelace',
            'gender' => 'f', 'residence' => 'UK',
            'accommodation' => 'hotel',
            'products' => ['dinner'],
        ]);

        $answers = $user->registrationAnswers();

        $this->assertSame('Ada', $answers->value('name'));
        $this->assertSame('UK', $answers->display('residence'));
        // Choice answers' display() is the stored value itself — already
        // report-ready, resolved and interpolated at write time.
        $this->assertSame('hotel', $answers->display('accommodation'));
        $this->assertSame('dinner', $answers->display('products'));
        // Priced options total up from the EAV store (hotel 100 + product 20).
        $this->assertEqualsWithDelta(120.0, $answers->cost(), 0.001);
        $this->assertFalse($answers->has('nickname'));
        // An unanswered field has no display value.
        $this->assertNull($answers->display('nickname'));
    }

    #[TestDox('cost lines pair each priced answers question label with its snapshotted cost')]
    public function test_cost_lines_pair_each_priced_answers_question_label_with_its_snapshotted_cost(): void
    {
        $this->seedAndStore();
        $user = $this->makeUser();

        $this->app->make(AnswerStore::class)->store(QuestionScope::Participant, $user, [
            'name' => 'Ada', 'lastname' => 'Lovelace', 'passport' => 'Ada Lovelace',
            'gender' => 'f', 'residence' => 'UK',
            'accommodation' => 'hotel',
            'products' => ['dinner'],
        ]);

        // Unpriced answers (name, gender, …) never appear as a line — only
        // the two priced options, each against its own question's label.
        $this->assertSame(
            [['label' => 'Accommodation', 'amount' => 100.0], ['label' => 'Additional products', 'amount' => 20.0]],
            $user->registrationAnswers()->costLines(),
        );
    }

    #[TestDox('values returns the answers as a raw key value map')]
    public function test_values_returns_the_answers_as_a_raw_key_value_map(): void
    {
        $this->seedAndStore();
        $user = $this->makeUser();

        $this->app->make(AnswerStore::class)->store(QuestionScope::Participant, $user, [
            'name' => 'Ada', 'lastname' => 'Lovelace', 'passport' => 'Ada Lovelace',
            'gender' => 'f', 'residence' => 'UK',
            'accommodation' => 'hotel',
        ]);

        // The wizard-draft-shaped map used as the interpolator's registrant
        // context: raw stored values keyed by question key.
        $values = $user->registrationAnswers()->values();

        $this->assertSame('Ada', $values['name']);
        $this->assertSame('hotel', $values['accommodation']);
        $this->assertArrayNotHasKey('nickname', $values);
    }

    #[TestDox('fields are returned in form order')]
    public function test_fields_are_returned_in_form_order(): void
    {
        $this->seedAndStore();
        $user = $this->makeUser();

        $this->app->make(AnswerStore::class)->store(QuestionScope::Participant, $user, [
            'name' => 'Ada', 'lastname' => 'Lovelace', 'passport' => 'Ada Lovelace',
            'gender' => 'f', 'residence' => 'UK',
            'accommodation' => 'hotel',
        ]);

        $keys = array_column($user->registrationAnswers()->fields(), 'key');

        // Section order (your-details before accommodation) then question order.
        $this->assertSame('name', $keys[0]);
        $this->assertContains('accommodation', $keys);
        $this->assertLessThan(
            array_search('accommodation', $keys, true),
            array_search('gender', $keys, true),
        );
    }

    #[TestDox('per diem contributions come only from options that add days')]
    public function test_per_diem_contributions_come_only_from_options_that_add_days(): void
    {
        $this->defaultCurrency();
        $section = Section::create([
            'scope' => QuestionScope::Participant->value, 'key' => 'extras', 'title' => 'Extras', 'position' => 0, 'enabled' => true,
        ]);

        $role = Question::create([
            'section_id' => $section->id, 'key' => 'role', 'type' => QuestionType::Radio->value,
            'label' => 'Role', 'position' => 0, 'required' => false, 'enabled' => true,
        ]);
        $role->options()->create(['value' => 'setup', 'label' => 'Set-up team', 'per_diem_days' => 2, 'per_diem_scope' => PerDiemScope::AttendeeAndGuests, 'position' => 0]);

        // A chosen option with no per-diem days must not appear as a contribution.
        $meal = Question::create([
            'section_id' => $section->id, 'key' => 'meal', 'type' => QuestionType::Radio->value,
            'label' => 'Meal', 'position' => 1, 'required' => false, 'enabled' => true,
        ]);
        $meal->options()->create(['value' => 'dinner', 'label' => 'Dinner', 'cost' => 20, 'position' => 0]);

        $user = $this->makeUser();
        $this->app->make(AnswerStore::class)->store(QuestionScope::Participant, $user, ['role' => 'setup', 'meal' => 'dinner']);

        $contributions = $user->registrationAnswers()->perDiemContributions();

        // The contribution's "label" is the stored answer text — the raw
        // selected value here, since this question doesn't turn on
        // translate_value — not the option's own label column.
        $this->assertCount(1, $contributions);
        $this->assertSame(
            ['label' => 'setup', 'days' => 2, 'scope' => PerDiemScope::AttendeeAndGuests],
            $contributions->first(),
        );
    }

    #[TestDox('reads group scope answers')]
    public function test_reads_group_scope_answers(): void
    {
        $this->seedAndStore();
        $group = Group::factory()->create();

        $this->app->make(AnswerStore::class)->store(QuestionScope::Group, $group, [
            'organization' => 'Engines', 'orgtype' => 'business',
            'address' => '1 St', 'town' => 'London', 'zipcode' => '00000',
            'country' => 'UK', 'telephone' => '12345',
        ]);

        $this->assertSame('Engines', $group->registrationAnswers()->value('organization'));
        // display() and value() now read the same stored text for a choice answer.
        $this->assertSame('business', $group->registrationAnswers()->display('orgtype'));
        $this->assertSame('business', $group->registrationAnswers()->value('orgtype'));
    }

    #[TestDox('interpolates an options templated value before storing it')]
    public function test_interpolates_an_options_templated_value_before_storing_it(): void
    {
        $this->seedAndStore();

        $section = Section::create([
            'scope' => QuestionScope::Participant, 'key' => 'badge', 'title' => 'Badge',
            'position' => 2, 'enabled' => true,
        ]);
        $badgeName = Question::create([
            'section_id' => $section->id, 'key' => 'badgename', 'type' => QuestionType::Radio->value,
            'label' => 'Badge name', 'position' => 0, 'required' => false, 'enabled' => true,
        ]);
        $badgeName->options()->create([
            'value' => '{q:name.value} {q:lastname.value}', 'label' => '{q:name.value} {q:lastname.value}', 'position' => 0,
        ]);

        $user = $this->makeUser();
        $this->app->make(AnswerStore::class)->store(QuestionScope::Participant, $user, [
            'name' => 'Ada', 'lastname' => 'Lovelace',
            'badgename' => '{q:name.value} {q:lastname.value}',
        ]);

        // The chosen option's raw value is a template (matched against the
        // submitted value as-is); what's stored is the interpolated text, not
        // the literal token string.
        $this->assertSame('Ada Lovelace', $user->registrationAnswers()->value('badgename'));
    }
}
