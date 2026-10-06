<?php

namespace ConferenceTools\Registration\Database\Seeders;

use ConferenceTools\Registration\Enums\BooleanOperator;
use ConferenceTools\Registration\Enums\ConditionOperator;
use ConferenceTools\Registration\Enums\QuestionScope;
use ConferenceTools\Registration\Enums\ReportColumnDisplay;
use ConferenceTools\Registration\Enums\ReportField;
use ConferenceTools\Registration\Enums\ReportType;
use ConferenceTools\Registration\Models\Question;
use ConferenceTools\Registration\Models\Report;
use ConferenceTools\Registration\Models\ReportColumn;
use ConferenceTools\Registration\Models\Setting;
use ConferenceTools\Registration\Services\GuestQuestions;
use ConferenceTools\Registration\Services\ReportQuestions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;

/**
 * Recreates the six formerly hard-coded reports (Attendee List, Directory,
 * Arrivals, Photo Permission Form, Special Needs, First-Time Attendees) as
 * admin-defined Report rows, wired to whatever questions the installation's
 * nomination settings pointed at when it runs. Settings whose code constants
 * were retired with the hard-coded reports are read by their literal setting
 * strings; a column or rule whose nominated question is unset is skipped.
 * Special Needs is an Individual report (each registrant and guest on their
 * own); the rest are Registrant reports.
 * Names and descriptions are plain English literals held on the rows — admin
 * content, editable afterward, NOT lang-file entries.
 *
 * Idempotent by report name: re-running rebuilds each report's columns and
 * rules but keeps the row (and any admin edits to name-matched fields are
 * overwritten). Known approximations of the old pages, all editable now:
 * the Directory always renders its Email column heading (it used to hide the
 * whole column until an answer was designated to mean "show my email"), the
 * Arrivals list is one flat table with a day column instead of one table per
 * day, and the Photo Permission Form shows the consent answer instead of
 * opt-out stars and a signature layout.
 */
class DefaultReportsSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedAttendeeList();
        $this->seedDirectory();
        $this->seedArrivals();
        $this->seedPhotoPermissionForm();
        $this->seedSpecialNeeds();
        $this->seedFirstTimeAttendees();
    }

    /** Every attendee, their guests beneath them, with an entry-type column. */
    private function seedAttendeeList(): void
    {
        $report = $this->rebuild('Attendee List', 'Every attendee and guest.', adults: true, minors: true, position: 10);

        $this->questionColumn($report, $this->participantQuestion(ReportQuestions::FIRSTNAME_KEY), header: 'First Name');
        $this->questionColumn($report, $this->participantQuestion(ReportQuestions::LASTNAME_KEY), header: 'Last Name');
        $this->builtinColumn($report, ReportField::EntryType, header: 'Type');
    }

    /**
     * The printable directory, honoring each registrant's directory-preference
     * answer: the omit answers keep the whole row out, and the show-name/
     * organization/email answers gate each cell.
     */
    private function seedDirectory(): void
    {
        $report = $this->rebuild('Directory', 'View, print, and export the directory.',
            adults: (bool) Setting::get('guest_adults_in_directory'), minors: false, position: 20);

        $directory = $this->participantQuestion('report_directory_key');

        $this->rule($report, $directory, ConditionOperator::In, $this->values('report_directory_omit_values', 'ShowBadgeName'));

        $name = $this->builtinColumn($report, ReportField::BadgeName, header: 'Name');
        $this->rule($name, $directory, ConditionOperator::In, $this->values('report_directory_show_name_values', 'ShowBadgeName'));

        $organization = $this->builtinColumn($report, ReportField::Organization);
        $this->rule($organization, $directory, ConditionOperator::In, $this->values('report_directory_show_org_values', 'ShowOrg'));

        $email = $this->builtinColumn($report, ReportField::Email);
        $this->rule($email, $directory, ConditionOperator::In, $this->values('report_directory_show_email_values', 'ShowEmail'));
    }

    /** Who arrives on what day, so rooms are ready in time. */
    private function seedArrivals(): void
    {
        $report = $this->rebuild('Arrivals', 'Who arrives on what day, so rooms are ready in time.',
            adults: false, minors: false, position: 30);

        $this->builtinColumn($report, ReportField::BadgeName, header: 'Name');
        $this->builtinColumn($report, ReportField::Organization);
        $this->questionColumn($report, $this->participantQuestion(ReportQuestions::ARRIVAL_KEY), display: ReportColumnDisplay::Label, header: 'Arrival Day');
    }

    /**
     * Everyone's photo-permission answer — adult guests included, minors
     * excluded (they need no consent) — one column reading the registrant's
     * own consent question on registrant rows and the guest-scope equivalent
     * on guest rows, mapped to the consent wording the printed form used and
     * naming the row's own registrant/guest, plus the blank Yes/No/Initials
     * columns the old opt-out-star layout offered for a hand-signed override.
     */
    private function seedPhotoPermissionForm(): void
    {
        $report = $this->rebuild('Photo Permission Form', 'Everyone\'s photo permission answer.',
            adults: true, minors: false, position: 40);

        $this->questionColumn(
            $report,
            $this->participantQuestion('report_photo_key'),
            display: ReportColumnDisplay::Mapped,
            header: 'Name',
            mapping: $this->photoPermissionMapping(),
            guestQuestion: $this->guestQuestion('guest_photo_key'),
        );
        $this->blankColumn($report, 'Yes');
        $this->blankColumn($report, 'No');
        $this->blankColumn($report, 'Initials');
    }

    /**
     * The Photo Permission Form's mapping entries: the registrant's own
     * first/last name on registrant rows, the guest's name on guest rows,
     * each starred for a "Yes" answer — the printed form's opt-out-star
     * convention.
     */
    private function photoPermissionMapping(): array
    {
        $registrant = $this->nameTemplate(
            $this->participantQuestion(ReportQuestions::FIRSTNAME_KEY),
            $this->participantQuestion(ReportQuestions::LASTNAME_KEY),
        );
        $guest = $this->nameTemplate($this->guestQuestion(GuestQuestions::NAME_KEY));

        return [
            ['value' => 'Yes', 'guest' => 'non_guest', 'text' => $registrant.' *'],
            ['value' => 'No', 'guest' => 'non_guest', 'text' => $registrant],
            ['value' => 'Yes', 'guest' => 'guest', 'text' => $guest.' *'],
            ['value' => 'No', 'guest' => 'guest', 'text' => $guest],
        ];
    }

    /** A "{q:key.value}" interpolation template naming the given question(s), space-joined. */
    private function nameTemplate(?Question ...$questions): string
    {
        $tokens = collect($questions)
            ->filter()
            ->map(fn (Question $question): string => "q:{$question->key}.value")
            ->implode(' ');

        return $tokens === '' ? '' : "{{$tokens}}";
    }

    /**
     * Registrants who answered the special-needs question, with their answer —
     * an Individual report, so a guest-question condition added later lists
     * those guests on their own.
     */
    private function seedSpecialNeeds(): void
    {
        $report = $this->rebuild('Special Needs', 'The list of special needs.', adults: false, minors: false, position: 50, type: ReportType::Individual);

        $specialNeeds = $this->participantQuestion('report_special_needs_key');

        $this->rule($report, $specialNeeds, ConditionOperator::IsAnswered, null);
        $this->questionColumn($report, $this->participantQuestion(ReportQuestions::FIRSTNAME_KEY), header: 'First Name');
        $this->questionColumn($report, $this->participantQuestion(ReportQuestions::LASTNAME_KEY), header: 'Last Name');
        $this->questionColumn($report, $specialNeeds, header: 'Special Needs');
    }

    /** Registrants whose first-time-attendee answer means "yes". */
    private function seedFirstTimeAttendees(): void
    {
        $report = $this->rebuild('First-Time Attendees', 'Everyone attending for the first time.',
            adults: false, minors: false, position: 60);

        $this->rule($report, $this->participantQuestion('report_first_time_key'),
            ConditionOperator::In, $this->values('report_first_time_yes_values', 'yes'));
        $this->questionColumn($report, $this->participantQuestion(ReportQuestions::FIRSTNAME_KEY), header: 'First Name');
        $this->questionColumn($report, $this->participantQuestion(ReportQuestions::LASTNAME_KEY), header: 'Last Name');
    }

    /** Upsert the report row by name and wipe its columns and rules for rebuilding. */
    private function rebuild(string $name, string $description, bool $adults, bool $minors, int $position, ReportType $type = ReportType::Registrant): Report
    {
        $report = Report::updateOrCreate(['name' => $name], [
            'description' => $description,
            'type' => $type,
            'include_adult_guests' => $adults,
            'include_minor_guests' => $minors,
            'position' => $position,
        ]);

        // Deleted as models so their own rule trees are cleaned up with them.
        $report->columns()->get()->each->delete();
        $report->conditionGroups()->get()->each->delete();

        return $report;
    }

    /**
     * Append a question column; a no-op while the question is unnominated.
     * $guestQuestion nominates a second question the cell reads from on guest
     * rows instead — a no-op of its own while that one is unnominated, the
     * column then falling back to reading $question on every row.
     */
    private function questionColumn(Report $report, ?Question $question, ReportColumnDisplay $display = ReportColumnDisplay::Value, ?string $header = null, ?array $mapping = null, ?Question $guestQuestion = null): ?ReportColumn
    {
        if ($question === null) {
            return null;
        }

        return $report->columns()->create([
            'question_id' => $question->id,
            'guest_question_id' => $guestQuestion?->id,
            'display' => $display->value,
            'header' => $header,
            'mapping' => $mapping,
            'position' => ((int) $report->columns()->max('position')) + 1,
        ]);
    }

    /** Append a built-in column. */
    private function builtinColumn(Report $report, ReportField $field, ?string $header = null): ReportColumn
    {
        return $report->columns()->create([
            'field' => $field->value,
            'header' => $header,
            'position' => ((int) $report->columns()->max('position')) + 1,
        ]);
    }

    /** Append a blank custom column: just a header, for the admin to fill in by hand. */
    private function blankColumn(Report $report, string $header): ReportColumn
    {
        return $report->columns()->create([
            'header' => $header,
            'position' => ((int) $report->columns()->max('position')) + 1,
        ]);
    }

    /**
     * Attach a one-condition rule tree to a report (row rule) or column (cell
     * rule); a no-op while the tested question is unnominated.
     */
    private function rule(?Model $node, ?Question $question, ConditionOperator $operator, ?string $value): void
    {
        if ($node === null || $question === null) {
            return;
        }

        $group = $node->conditionGroups()->create(['operator' => BooleanOperator::And->value]);
        $group->conditions()->create([
            'question_id' => $question->id,
            'operator' => $operator->value,
            'value' => $operator->needsValue() ? $value : null,
        ]);
    }

    /** The Participant-scope question a nomination setting points at, or null while unset. */
    private function participantQuestion(?string $setting): ?Question
    {
        $key = $setting !== null ? Setting::get($setting) : null;
        if (blank($key)) {
            return null;
        }

        return Question::where('key', $key)
            ->whereHas('section', fn ($query) => $query->where('scope', QuestionScope::Participant->value))
            ->first();
    }

    /** The Guest-scope question a nomination setting points at, or null while unset. */
    private function guestQuestion(?string $setting): ?Question
    {
        $key = $setting !== null ? Setting::get($setting) : null;
        if (blank($key)) {
            return null;
        }

        return Question::where('key', $key)
            ->whereHas('section', fn ($query) => $query->where('scope', QuestionScope::Guest->value))
            ->first();
    }

    /**
     * A matching-answer value list as the rule engine's comma-separated form,
     * case preserved — the stored setting, or the retired code default while
     * the setting row is absent (mirroring the old valueList() fallback).
     */
    private function values(string $setting, string $default): string
    {
        return collect(explode(',', Setting::get($setting) ?? $default))
            ->map(fn (string $value): string => trim($value))
            ->filter(fn (string $value): bool => $value !== '')
            ->implode(',');
    }
}
