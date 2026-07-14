<?php

namespace ConferenceTools\Registration\Tests\Concerns;

use ConferenceTools\Registration\Enums\QuestionScope;
use ConferenceTools\Registration\Models\Currency;
use ConferenceTools\Registration\Models\Group;
use ConferenceTools\Registration\Models\Question;
use ConferenceTools\Registration\Models\Setting;
use ConferenceTools\Registration\Services\AnswerStore;
use ConferenceTools\Registration\Services\RegistrationEmails;
use ConferenceTools\Registration\Services\RegistrationStatus;
use ConferenceTools\Registration\Services\ReportQuestions;
use ConferenceTools\Registration\Tests\Fixtures\QuestionConfigSeeder;
use ConferenceTools\Registration\Tests\Fixtures\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared builders for the registration domain used across the feature tests.
 *
 * Registrant/group answers live in the EAV answer store, so the builders seed the
 * configured questions and write answers through {@see AnswerStore} rather than
 * setting (now-removed) typed columns. The seeded questions use static priced
 * options: "accommodation" → "hotel" costs 100; "products" → "dinner" costs 20.
 */
trait BuildsRegistrationData
{
    /** The default currency fixture. */
    protected function defaultCurrency(array $attributes = []): Currency
    {
        $code = $attributes['code'] ?? 'USD';

        if ($existing = Currency::find($code)) {
            if ($attributes) {
                $existing->update($attributes);
            }

            return $existing;
        }

        return Currency::factory()->default()->create(array_merge([
            'code' => $code, 'name' => 'US Dollar', 'symbol' => '$',
        ], $attributes));
    }

    /**
     * Put registration inside an open window with the admin address and the
     * required name questions configured — the state the registrant-facing
     * routes require (see RegistrationStatus). Test-set values are left alone
     * so mail/nomination expectations set up by the test still hold. The
     * fixture questionnaire (see QuestionConfigSeeder) doesn't have a
     * dedicated badge-name question, so it's nominated to the same "name"
     * (First Name) question as FIRSTNAME_KEY.
     */
    protected function openRegistration(): void
    {
        if (app(RegistrationEmails::class)->adminEmail() === null) {
            Setting::put(RegistrationEmails::ADMIN_EMAIL, 'admin@example.com');
        }

        foreach ([
            ReportQuestions::BADGE_NAME_KEY => 'name',
            ReportQuestions::FIRSTNAME_KEY => 'name',
            ReportQuestions::LASTNAME_KEY => 'lastname',
        ] as $setting => $key) {
            if (Setting::get($setting) === null) {
                Setting::put($setting, $key);
            }
        }

        app(RegistrationStatus::class)->schedule(now()->subMinute(), null);
    }

    /** A host user with only the structural registration columns set. */
    protected function makeUser(array $attributes = []): User
    {
        static $seq = 0;
        $seq++;

        return User::forceCreate(array_merge([
            'name' => 'User'.$seq,
            'email' => 'user'.$seq.'@example.com',
            'password' => 'secret',
            'mail_id' => 'mail'.$seq,
            'checked_out' => false,
            'is_group_admin' => false,
        ], $attributes));
    }

    /**
     * A full set of valid answers for the fixture questionnaire (the wizard
     * validates the relevant slice each step) — plus "No" for the seeded,
     * always-live guest/group system questions (see SystemQuestionsSeeder),
     * so any test walking the whole wizard satisfies them wherever their
     * step falls, without opting into the guest hub or a group registration.
     */
    protected function fixtureAnswers(): array
    {
        return [
            'name' => 'Ada', 'lastname' => 'Lovelace', 'passport' => 'Ada Lovelace',
            'gender' => 'f', 'residence' => 'UK',
            'accommodation' => 'hotel',
            'organization' => 'Engines', 'orgtype' => 'business',
            'address' => '1 St', 'town' => 'London', 'zipcode' => '00000',
            'country' => 'UK', 'telephone' => '12345',
            Question::GUEST_TRIGGER_KEY => 'No',
            Question::GROUP_TRIGGER_KEY => 'No',
        ];
    }

    /** Store an owner's answers for a scope through the real answer store. */
    protected function storeAnswers(Model $owner, QuestionScope $scope, array $answers): void
    {
        app(AnswerStore::class)->store($scope, $owner, $answers);
    }

    /** Seed the fixture questionnaire together with the default currency it prices in. */
    protected function seedQuestionConfig(): void
    {
        $this->defaultCurrency();
        $this->seed(QuestionConfigSeeder::class);
    }

    /** A group admin to act as on the package's admin routes. */
    protected function admin(): User
    {
        return $this->makeGroupWithMembers()->admin();
    }

    /**
     * A group with an admin user and a second member, each choosing the priced
     * "hotel" accommodation (cost 100) and the "dinner" product (cost 20) recorded
     * as answers, plus a default currency. Returns the group.
     */
    protected function makeGroupWithMembers(): Group
    {
        $this->seedQuestionConfig();

        $group = Group::factory()->create(['name' => 'Analytical Engines', 'checked_out' => false]);
        $this->storeAnswers($group, QuestionScope::Group, [
            'organization' => 'Analytical Engines', 'orgtype' => 'business',
            'address' => '1 Babbage St', 'town' => 'London', 'zipcode' => '00000',
            'country' => 'UK', 'telephone' => '12345',
        ]);

        $this->registrant($group, true);
        $this->registrant($group, false);

        return $group->fresh();
    }

    /** Create a participant in the group and record their answers. */
    protected function registrant(Group $group, bool $isAdmin): User
    {
        $user = $this->makeUser(['is_group_admin' => $isAdmin, 'group_id' => $group->id]);

        $this->storeAnswers($user, QuestionScope::Participant, [
            'name' => $user->name, 'lastname' => 'Test', 'passport' => 'Passport',
            'gender' => 'm', 'residence' => 'UK',
            'accommodation' => 'hotel',
            'products' => ['dinner'],
        ]);

        return $user;
    }
}
