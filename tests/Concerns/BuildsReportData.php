<?php

namespace ConferenceTools\Registration\Tests\Concerns;

use ConferenceTools\Registration\Database\Seeders\DefaultReportsSeeder;
use ConferenceTools\Registration\Enums\GuestType;
use ConferenceTools\Registration\Enums\QuestionScope;
use ConferenceTools\Registration\Enums\QuestionType;
use ConferenceTools\Registration\Models\Group;
use ConferenceTools\Registration\Models\Guest;
use ConferenceTools\Registration\Models\Question;
use ConferenceTools\Registration\Models\Section;
use ConferenceTools\Registration\Models\Setting;
use ConferenceTools\Registration\Services\AnswerStore;
use ConferenceTools\Registration\Services\GuestQuestions;
use ConferenceTools\Registration\Services\ReportQuestions;
use ConferenceTools\Registration\Tests\Fixtures\QuestionConfigSeeder;
use ConferenceTools\Registration\Tests\Fixtures\User;

/**
 * Builders for the report/room/shuttle features: the fixture questionnaire
 * plus the console-feeding questions (badge name, roommate, arrival day and
 * flight times), each nominated in the logistics settings under its natural
 * key — for registrants and, in parallel, for their non-attending guests
 * (see GuestQuestions). The photo/directory/special-needs/first-time fixture
 * questions remain (the admin-defined reports test against them), but only
 * the surviving nominations are set; see seedRetiredReportSettings() for the
 * retired ones.
 */
trait BuildsReportData
{
    use BuildsRegistrationData;

    /** Seed the fixture questionnaire plus the nominated report questions, for registrants and guests alike. */
    protected function seedReportQuestions(): void
    {
        $this->seed(QuestionConfigSeeder::class);

        $section = Section::updateOrCreate(
            ['scope' => QuestionScope::Participant->value, 'key' => 'travel'],
            ['title' => 'Travel & preferences', 'position' => 5, 'enabled' => true],
        );

        $position = 0;
        $question = function (string $key, QuestionType $type, array $options = []) use ($section, &$position): Question {
            $question = Question::updateOrCreate(
                ['section_id' => $section->id, 'key' => $key],
                ['type' => $type->value, 'label' => ucfirst($key), 'position' => $position++, 'required' => false, 'enabled' => true],
            );
            foreach ($options as $i => $value) {
                $question->options()->updateOrCreate(['value' => $value], ['label' => ucfirst($value), 'position' => $i]);
            }

            return $question;
        };

        $question('badgename', QuestionType::Text);
        $question('roommate', QuestionType::Text);
        $question('photopermission', QuestionType::Radio, ['yes', 'no']);
        $question('directorypref', QuestionType::Checkbox, ['NoName', 'ShowBadgeName', 'ShowOrg', 'ShowEmail']);
        $question('arrivalday', QuestionType::Select, ['monday', 'tuesday', 'wednesday']);
        $question('arrivalflight', QuestionType::Text);
        $question('departureflight', QuestionType::Text);
        $question('specialneeds', QuestionType::Text);
        $question('firsttime', QuestionType::Radio, ['yes', 'no']);

        foreach ([
            ReportQuestions::BADGE_NAME_KEY => 'badgename',
            ReportQuestions::FIRSTNAME_KEY => 'name',
            ReportQuestions::LASTNAME_KEY => 'lastname',
            ReportQuestions::ORGANIZATION_KEY => 'organization',
            ReportQuestions::GENDER_KEY => 'gender',
            ReportQuestions::ROOMMATE_KEY => 'roommate',
            ReportQuestions::ARRIVAL_KEY => 'arrivalday',
            ReportQuestions::FLIGHT_ARRIVAL_KEY => 'arrivalflight',
            ReportQuestions::FLIGHT_DEPARTURE_KEY => 'departureflight',
        ] as $setting => $key) {
            Setting::put($setting, $key);
        }

        $this->seedGuestReportQuestions();
    }

    /**
     * Nominate the retired hard-coded reports' settings by their literal
     * strings (their code constants are gone) — the state an upgraded
     * installation is in when {@see DefaultReportsSeeder}
     * runs against it.
     */
    protected function seedRetiredReportSettings(): void
    {
        foreach ([
            'report_photo_key' => 'photopermission',
            'guest_photo_key' => 'guestphoto',
            'report_directory_key' => 'directorypref',
            'report_special_needs_key' => 'specialneeds',
            'report_first_time_key' => 'firsttime',
        ] as $setting => $key) {
            Setting::put($setting, $key);
        }
    }

    /** Seed a matching Guest-scope question set and nominate it via GuestQuestions. */
    protected function seedGuestReportQuestions(): void
    {
        $guestSection = Section::updateOrCreate(
            ['scope' => QuestionScope::Guest->value, 'key' => 'guest-travel'],
            ['title' => 'Guest travel & preferences', 'position' => 0, 'enabled' => true],
        );

        $position = 0;
        $question = function (string $key, QuestionType $type, array $options = []) use ($guestSection, &$position): Question {
            $question = Question::updateOrCreate(
                ['section_id' => $guestSection->id, 'key' => $key],
                ['type' => $type->value, 'label' => ucfirst($key), 'position' => $position++, 'required' => false, 'enabled' => true],
            );
            foreach ($options as $i => $value) {
                $question->options()->updateOrCreate(['value' => $value], ['label' => ucfirst($value), 'position' => $i]);
            }

            return $question;
        };

        $question('guestname', QuestionType::Text);
        $question('guestbadgename', QuestionType::Text);
        $question('guestgender', QuestionType::Text);
        $question('guestroommate', QuestionType::Text);
        $question('guestphoto', QuestionType::Radio, ['yes', 'no']);
        $question('guestprayerpals', QuestionType::Radio, ['yes', 'no']);

        app(GuestQuestions::class)->update([
            GuestQuestions::NAME_KEY => 'guestname',
            GuestQuestions::GENDER_KEY => 'guestgender',
            GuestQuestions::ROOMMATE_KEY => 'guestroommate',
            GuestQuestions::PRAYER_PALS_OPT_IN_KEY => 'guestprayerpals',
        ]);
    }

    /** A completed registrant (host user in a group) with the given answers. */
    protected function makeRegistrant(string $first, string $last, array $answers = [], ?Group $group = null): User
    {
        $group ??= $this->reportGroup();

        $user = $this->makeUser(['name' => $first, 'group_id' => $group->id]);
        $this->storeAnswers($user, QuestionScope::Participant, array_merge([
            'name' => $first, 'lastname' => $last, 'gender' => 'm', 'badgename' => "$first $last",
        ], $answers));

        return $user;
    }

    /** A committed non-attending guest belonging to a registrant, with the given Guest-scope answers. */
    protected function makeGuest(User $user, GuestType $type, array $answers = []): Guest
    {
        $guest = Guest::factory()->create(['user_id' => $user->id, 'type' => $type]);
        app(AnswerStore::class)->store(QuestionScope::Guest, $guest, $answers);

        return $guest->fresh();
    }

    /** The shared organization the report registrants belong to. */
    protected function reportGroup(): Group
    {
        return Group::firstOrCreate(['name' => 'Analytical Engines'], ['checked_out' => true]);
    }
}
