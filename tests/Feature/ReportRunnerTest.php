<?php

namespace ConferenceTools\Registration\Tests\Feature;

use ConferenceTools\Registration\Enums\BooleanOperator;
use ConferenceTools\Registration\Enums\ConditionOperator;
use ConferenceTools\Registration\Enums\GuestType;
use ConferenceTools\Registration\Enums\QuestionScope;
use ConferenceTools\Registration\Enums\ReportColumnDisplay;
use ConferenceTools\Registration\Enums\ReportField;
use ConferenceTools\Registration\Enums\ReportType;
use ConferenceTools\Registration\Models\Question;
use ConferenceTools\Registration\Models\Report;
use ConferenceTools\Registration\Models\ReportColumn;
use ConferenceTools\Registration\Services\ReportRunner;
use ConferenceTools\Registration\Tests\Concerns\BuildsReportData;
use ConferenceTools\Registration\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Row building for admin-defined reports: headings, row rules, guest
 * inclusion, scope-aware cells, value/label display, per-row cell rules, the
 * built-in fields, and Individual reports' scope-matched rules and cells.
 */
#[TestDox('Report Runner')]
class ReportRunnerTest extends TestCase
{
    use BuildsReportData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReportQuestions();
    }

    /** The runner under test (resolved fresh so Registrants isn't memoized across arrangements). */
    private function runner(): ReportRunner
    {
        return app(ReportRunner::class);
    }

    /** A report with no columns yet. */
    private function report(array $attributes = []): Report
    {
        return Report::factory()->create($attributes);
    }

    /** Append a question column to a report. */
    private function questionColumn(Report $report, string $key, ReportColumnDisplay $display = ReportColumnDisplay::Value, ?string $header = null): ReportColumn
    {
        return ReportColumn::factory()->create([
            'report_id' => $report->id,
            'question_id' => Question::firstWhere('key', $key)->id,
            'display' => $display,
            'header' => $header,
            'position' => ((int) $report->columns()->max('position')) + 1,
        ]);
    }

    /** Append a built-in column to a report. */
    private function builtinColumn(Report $report, ReportField $field, ?string $header = null): ReportColumn
    {
        return ReportColumn::factory()->builtin($field)->create([
            'report_id' => $report->id,
            'header' => $header,
            'position' => ((int) $report->columns()->max('position')) + 1,
        ]);
    }

    /** Append a question column shown through the column's own value mapping. */
    private function mappedColumn(Report $report, string $key, array $mapping, ?string $header = null): ReportColumn
    {
        return ReportColumn::factory()->mapped($mapping)->create([
            'report_id' => $report->id,
            'question_id' => Question::firstWhere('key', $key)->id,
            'header' => $header,
            'position' => ((int) $report->columns()->max('position')) + 1,
        ]);
    }

    /** Append a question column shown through raw {value, guest, text} mapping entries. */
    private function mappedEntriesColumn(Report $report, string $key, array $entries, ?string $header = null): ReportColumn
    {
        return ReportColumn::factory()->mappedEntries($entries)->create([
            'report_id' => $report->id,
            'question_id' => Question::firstWhere('key', $key)->id,
            'header' => $header,
            'position' => ((int) $report->columns()->max('position')) + 1,
        ]);
    }

    /** Append a blank custom column: no question, no built-in field. */
    private function blankColumn(Report $report, ?string $header = null): ReportColumn
    {
        return ReportColumn::factory()->blank()->create([
            'report_id' => $report->id,
            'header' => $header,
            'position' => ((int) $report->columns()->max('position')) + 1,
        ]);
    }

    /** Attach a one-condition rule to a report or column. */
    private function rule(Model $node, string $questionKey, ConditionOperator $operator, ?string $value): void
    {
        $group = $node->conditionGroups()->create(['operator' => BooleanOperator::And->value]);
        $group->conditions()->create([
            'question_id' => Question::firstWhere('key', $questionKey)->id,
            'operator' => $operator->value,
            'value' => $value,
        ]);
    }

    /**
     * Attach a one-group rule to a report or column.
     *
     * @param  list<array{string, ConditionOperator, ?string}>  $conditions  question key, operator, value
     */
    private function ruleTree(Model $node, BooleanOperator $operator, array $conditions): void
    {
        $group = $node->conditionGroups()->create(['operator' => $operator->value]);
        foreach ($conditions as [$key, $conditionOperator, $value]) {
            $group->conditions()->create([
                'question_id' => Question::firstWhere('key', $key)->id,
                'operator' => $conditionOperator->value,
                'value' => $value,
            ]);
        }
    }

    /**
     * Two families for the Individual report cases: first-timer Ada with an
     * adult guest who refused photos and a minor who allowed them, and
     * returning Bob with an adult guest who refused photos.
     */
    private function seedIndividualFamilies(): void
    {
        $ada = $this->makeRegistrant('Ada', 'Lovelace', ['firsttime' => 'yes']);
        $this->makeGuest($ada, GuestType::Adult, ['guestname' => 'Ada Guest', 'guestphoto' => 'no']);
        $this->makeGuest($ada, GuestType::Minor, ['guestname' => 'Ada Kid', 'guestphoto' => 'yes']);
        $bob = $this->makeRegistrant('Bob', 'Turing', ['firsttime' => 'no']);
        $this->makeGuest($bob, GuestType::Adult, ['guestname' => 'Bob Guest', 'guestphoto' => 'no']);
    }

    #[DataProvider('individualRules')]
    #[TestDox('an individual report keeps each registrant and guest by the conditions of their own scope')]
    public function test_an_individual_report_keeps_each_registrant_and_guest_by_the_conditions_of_their_own_scope(BooleanOperator $operator, array $conditions, array $expected): void
    {
        $this->seedIndividualFamilies();
        $report = $this->report(['type' => ReportType::Individual, 'include_adult_guests' => true, 'include_minor_guests' => true]);
        $this->builtinColumn($report, ReportField::BadgeName);
        if ($conditions !== []) {
            $this->ruleTree($report, $operator, $conditions);
        }

        $this->assertSame($expected, $this->runner()->rows($report)->flatten()->all());
    }

    /** Rule operator, conditions and the expected rows, for the data provider. */
    public static function individualRules(): array
    {
        $and = BooleanOperator::And;
        $or = BooleanOperator::Or;
        $firstTimer = ['firsttime', ConditionOperator::Equals, 'yes'];
        $noPhotos = ['guestphoto', ConditionOperator::Equals, 'no'];

        return [
            'no rule' => [$and, [], ['Ada Lovelace', 'Ada Guest', 'Ada Kid', 'Bob Turing', 'Bob Guest']],
            'registrant condition alone lists no guests' => [$and, [$firstTimer], ['Ada Lovelace']],
            'guest condition alone lists no registrants' => [$and, [$noPhotos], ['Ada Guest', 'Bob Guest']],
            'negated guest condition still lists no registrants' => [$and, [['guestphoto', ConditionOperator::NotEquals, 'no']], ['Ada Kid']],
            'OR across scopes, a guest without their registrant' => [$or, [$firstTimer, $noPhotos], ['Ada Lovelace', 'Ada Guest', 'Bob Guest']],
            'AND across scopes' => [$and, [$firstTimer, $noPhotos], ['Ada Lovelace', 'Ada Guest', 'Bob Guest']],
        ];
    }

    #[TestDox('an individual report lists only the guest types it includes')]
    public function test_an_individual_report_lists_only_the_guest_types_it_includes(): void
    {
        $this->seedIndividualFamilies();
        $report = $this->report(['type' => ReportType::Individual, 'include_minor_guests' => true]);
        $this->builtinColumn($report, ReportField::BadgeName);
        $this->rule($report, 'guestphoto', ConditionOperator::IsAnswered, null);

        $this->assertSame(['Ada Kid'], $this->runner()->rows($report)->flatten()->all());
    }

    #[DataProvider('cellsByType')]
    #[TestDox('participant cells and cell rules on guest rows follow the report type')]
    public function test_participant_cells_and_cell_rules_on_guest_rows_follow_the_report_type(ReportType $type, array $expected): void
    {
        $host = $this->makeRegistrant('Host', 'Registrant', ['photopermission' => 'yes']);
        $this->makeGuest($host, GuestType::Adult, ['guestname' => 'Adult Guest', 'guestphoto' => 'no']);
        $this->makeGuest($host, GuestType::Minor, ['guestname' => 'Minor Guest', 'guestphoto' => 'yes']);

        $report = $this->report(['type' => $type, 'include_adult_guests' => true, 'include_minor_guests' => true]);
        $this->questionColumn($report, 'photopermission');
        $this->questionColumn($report, 'photopermission')
            ->update(['guest_question_id' => Question::firstWhere('key', 'guestphoto')->id]);
        $this->rule($this->builtinColumn($report, ReportField::BadgeName), 'guestphoto', ConditionOperator::Equals, 'no');

        $this->assertSame($expected, $this->runner()->rows($report)->all());
    }

    /** The report type and its expected rows, for the data provider. */
    public static function cellsByType(): array
    {
        return [
            // The family shares the registrant's answer; the guest rule blanks
            // the registrant's own name (no guest answer there).
            'registrant' => [ReportType::Registrant, [
                ['yes', 'yes', null],
                ['yes', 'no', 'Adult Guest'],
                ['yes', 'yes', null],
            ]],
            // A guest is not their registrant, and a guest-only rule can
            // show a cell on guest rows alone.
            'individual' => [ReportType::Individual, [
                ['yes', 'yes', null],
                [null, 'no', 'Adult Guest'],
                [null, 'yes', null],
            ]],
        ];
    }

    #[TestDox('headings prefer the override then the question label then the built-in label')]
    public function test_headings_prefer_the_override_then_the_question_label_then_the_built_in_label(): void
    {
        $report = $this->report();
        $this->questionColumn($report, 'photopermission', header: 'Consent');
        $this->questionColumn($report, 'specialneeds');
        $this->builtinColumn($report, ReportField::Email);

        $this->assertSame(
            ['Consent', 'Specialneeds', __('registration::admin.report_builtin_email')],
            $this->runner()->headers($report),
        );
    }

    #[TestDox('rows list every registrant alphabetically while no row rule is set')]
    public function test_rows_list_every_registrant_alphabetically_while_no_row_rule_is_set(): void
    {
        $this->makeRegistrant('Zed', 'Zephyr');
        $this->makeRegistrant('Ada', 'Lovelace');

        $report = $this->report();
        $this->builtinColumn($report, ReportField::BadgeName);

        $this->assertSame([['Ada Lovelace'], ['Zed Zephyr']], $this->runner()->rows($report)->all());
    }

    #[TestDox('the row rule keeps only matching registrants')]
    public function test_the_row_rule_keeps_only_matching_registrants(): void
    {
        $this->makeRegistrant('Ada', 'Lovelace', ['firsttime' => 'yes']);
        $this->makeRegistrant('Charles', 'Babbage', ['firsttime' => 'no']);
        $this->makeRegistrant('Grace', 'Hopper');

        $report = $this->report();
        $this->builtinColumn($report, ReportField::BadgeName);
        $this->rule($report, 'firsttime', ConditionOperator::In, 'yes');

        $this->assertSame([['Ada Lovelace']], $this->runner()->rows($report)->all());
    }

    #[DataProvider('guestInclusions')]
    #[TestDox('guest rows follow their registrant per the adult and minor flags')]
    public function test_guest_rows_follow_their_registrant_per_the_adult_and_minor_flags(bool $adults, bool $minors, array $expected): void
    {
        $host = $this->makeRegistrant('Host', 'Registrant');
        $this->makeGuest($host, GuestType::Adult, ['guestname' => 'Adult Guest']);
        $this->makeGuest($host, GuestType::Minor, ['guestname' => 'Minor Guest']);

        $report = $this->report(['include_adult_guests' => $adults, 'include_minor_guests' => $minors]);
        $this->builtinColumn($report, ReportField::BadgeName);

        $this->assertSame($expected, $this->runner()->rows($report)->flatten()->all());
    }

    /** The guest inclusions for the data provider. */
    public static function guestInclusions(): array
    {
        return [
            'neither' => [false, false, ['Host Registrant']],
            'adults only' => [true, false, ['Host Registrant', 'Adult Guest']],
            'minors only' => [false, true, ['Host Registrant', 'Minor Guest']],
            'both' => [true, true, ['Host Registrant', 'Adult Guest', 'Minor Guest']],
        ];
    }

    #[TestDox('a filtered out registrant takes their guests with them')]
    public function test_a_filtered_out_registrant_takes_their_guests_with_them(): void
    {
        $kept = $this->makeRegistrant('Kept', 'Host', ['firsttime' => 'yes']);
        $this->makeGuest($kept, GuestType::Adult, ['guestname' => 'Kept Guest']);
        $dropped = $this->makeRegistrant('Dropped', 'Host', ['firsttime' => 'no']);
        $this->makeGuest($dropped, GuestType::Adult, ['guestname' => 'Dropped Guest']);

        $report = $this->report(['include_adult_guests' => true]);
        $this->builtinColumn($report, ReportField::BadgeName);
        $this->rule($report, 'firsttime', ConditionOperator::In, 'yes');

        $this->assertSame(['Kept Host', 'Kept Guest'], $this->runner()->rows($report)->flatten()->all());
    }

    #[TestDox('Participant and Group cells reach guest rows too; only Guest cells blank on the registrants own row')]
    public function test_participant_and_group_cells_reach_guest_rows_too_only_guest_cells_blank_on_the_registrants_own_row(): void
    {
        $host = $this->makeRegistrant('Host', 'Registrant', ['photopermission' => 'yes']);
        $this->makeGuest($host, GuestType::Adult, ['guestname' => 'Adult Guest']);
        $this->storeAnswers($host->group, QuestionScope::Group, ['organization' => 'Engines Ltd']);
        $host->group->refreshRegistrationAnswers();

        $report = $this->report(['include_adult_guests' => true]);
        $this->questionColumn($report, 'photopermission');
        $this->questionColumn($report, 'guestname');
        $this->questionColumn($report, 'organization');

        $this->assertSame([
            // The registrant's own row has no single guest to resolve the
            // Guest-scope column against.
            ['yes', null, 'Engines Ltd'],
            // The guest row inherits the registrant's Participant/Group
            // answers alongside its own Guest-scope one.
            ['yes', 'Adult Guest', 'Engines Ltd'],
        ], $this->runner()->rows($report)->all());
    }

    #[TestDox('a guest_question_id override reads a different question on guest rows, keeping the primary question on the registrants own row')]
    public function test_a_guest_question_override_reads_a_different_question_on_guest_rows(): void
    {
        $host = $this->makeRegistrant('Host', 'Registrant', ['photopermission' => 'yes']);
        $this->makeGuest($host, GuestType::Adult, ['guestname' => 'Adult Guest', 'guestphoto' => 'no']);

        $report = $this->report(['include_adult_guests' => true]);
        $column = $this->questionColumn($report, 'photopermission');
        $column->update(['guest_question_id' => Question::firstWhere('key', 'guestphoto')->id]);

        // The registrant's own row reads photopermission; the guest row reads
        // guestphoto instead — the guest's own consent, not the registrant's.
        $this->assertSame([['yes'], ['no']], $this->runner()->rows($report)->all());
    }

    #[TestDox('mapped display gates on the guest questions own answer for guest rows while the primary question still gates non-guest rows')]
    public function test_mapped_display_gates_on_the_guest_question_override(): void
    {
        $host = $this->makeRegistrant('Host', 'Registrant', ['photopermission' => 'yes']);
        $this->makeGuest($host, GuestType::Adult, ['guestname' => 'Consenting Guest', 'guestphoto' => 'yes']);
        $this->makeGuest($host, GuestType::Adult, ['guestname' => 'Declining Guest', 'guestphoto' => 'no']);

        $report = $this->report(['include_adult_guests' => true]);
        $column = $this->mappedEntriesColumn($report, 'photopermission', [
            ['value' => 'yes', 'guest' => 'non_guest', 'text' => '{q:name.value} {q:lastname.value} *'],
            ['value' => 'no', 'guest' => 'non_guest', 'text' => '{q:name.value} {q:lastname.value}'],
            ['value' => 'yes', 'guest' => 'guest', 'text' => '{q:guestname.value} *'],
            ['value' => 'no', 'guest' => 'guest', 'text' => '{q:guestname.value}'],
        ]);
        $column->update(['guest_question_id' => Question::firstWhere('key', 'guestphoto')->id]);

        $this->assertSame([
            // The registrant consented (photopermission=yes): asterisked.
            ['Host Registrant *'],
            // Each guest's own guestphoto answer decides their own row, not
            // the registrant's photopermission answer.
            ['Consenting Guest *'],
            ['Declining Guest'],
        ], $this->runner()->rows($report)->all());
    }

    #[TestDox('a minor-guest-only wildcard entry fixes the text regardless of the guest questions own answer')]
    public function test_a_minor_guest_only_wildcard_entry_fixes_the_text_regardless_of_the_guest_questions_own_answer(): void
    {
        $host = $this->makeRegistrant('Host', 'Registrant', ['photopermission' => 'yes']);
        $this->makeGuest($host, GuestType::Adult, ['guestname' => 'Adult Guest', 'guestphoto' => 'no']);
        // Minors are always answered "no" and it's never asked in earnest —
        // the minor-guest entry ignores whatever's stored and always wins.
        $this->makeGuest($host, GuestType::Minor, ['guestname' => 'Minor Guest', 'guestphoto' => 'no']);

        $report = $this->report(['include_adult_guests' => true, 'include_minor_guests' => true]);
        $column = $this->mappedEntriesColumn($report, 'photopermission', [
            ['value' => null, 'guest' => 'minor_guest', 'text' => '{q:guestname.value} *'],
            ['value' => 'yes', 'guest' => 'non_guest', 'text' => '{q:name.value} {q:lastname.value} *'],
            ['value' => 'no', 'guest' => 'non_guest', 'text' => '{q:name.value} {q:lastname.value}'],
            ['value' => 'yes', 'guest' => 'adult_guest', 'text' => '{q:guestname.value} *'],
            ['value' => 'no', 'guest' => 'adult_guest', 'text' => '{q:guestname.value}'],
        ]);
        // Each guest row gates on its own guestphoto answer, not the registrant's.
        $column->update(['guest_question_id' => Question::firstWhere('key', 'guestphoto')->id]);

        $this->assertSame([
            ['Host Registrant *'],
            // The adult guest's own "no" answer governs their row.
            ['Adult Guest'],
            // The minor's own "no" answer is bypassed by the minor-only wildcard.
            ['Minor Guest *'],
        ], $this->runner()->rows($report)->all());
    }

    #[TestDox('label display shows the matching options label and joins multi values')]
    public function test_label_display_shows_the_matching_options_label_and_joins_multi_values(): void
    {
        $directory = Question::firstWhere('key', 'directorypref');
        $directory->options()->firstWhere('value', 'ShowBadgeName')->update(['label' => 'Show Name']);
        $directory->options()->firstWhere('value', 'ShowOrg')->update(['label' => 'Show Org']);

        $this->makeRegistrant('Ada', 'Lovelace', [
            'photopermission' => 'yes',
            'directorypref' => ['ShowBadgeName', 'ShowOrg'],
        ]);

        $report = $this->report();
        $this->questionColumn($report, 'photopermission', ReportColumnDisplay::Label);
        $this->questionColumn($report, 'directorypref', ReportColumnDisplay::Label);

        $this->assertSame([['Yes', 'Show Name, Show Org']], $this->runner()->rows($report)->all());
    }

    #[TestDox('label display falls back to the stored value when no option matches')]
    public function test_label_display_falls_back_to_the_stored_value_when_no_option_matches(): void
    {
        $this->makeRegistrant('Ada', 'Lovelace', ['photopermission' => 'yes']);
        // The option is retyped after the answer was stored — the stored
        // value survives as-is instead of losing the cell.
        Question::firstWhere('key', 'photopermission')->options()->firstWhere('value', 'yes')->delete();

        $report = $this->report();
        $this->questionColumn($report, 'photopermission', ReportColumnDisplay::Label);

        $this->assertSame([['yes']], $this->runner()->rows($report)->all());
    }

    #[TestDox('mapped display shows the columns own mapping and joins multi values')]
    public function test_mapped_display_shows_the_columns_own_mapping_and_joins_multi_values(): void
    {
        $this->makeRegistrant('Ada', 'Lovelace', [
            'photopermission' => 'yes',
            'directorypref' => ['ShowBadgeName', 'ShowOrg'],
        ]);

        $report = $this->report();
        $this->mappedColumn($report, 'photopermission', ['yes' => 'Consents', 'no' => 'Declines']);
        $this->mappedColumn($report, 'directorypref', ['ShowBadgeName' => 'Name', 'ShowOrg' => 'Org']);

        $this->assertSame([['Consents', 'Name, Org']], $this->runner()->rows($report)->all());
    }

    #[TestDox('mapped display falls back to the stored value when the mapping has no row for it')]
    public function test_mapped_display_falls_back_to_the_stored_value_when_the_mapping_has_no_row_for_it(): void
    {
        $this->makeRegistrant('Ada', 'Lovelace', ['photopermission' => 'yes']);

        $report = $this->report();
        $this->mappedColumn($report, 'photopermission', ['no' => 'Declines']);

        $this->assertSame([['yes']], $this->runner()->rows($report)->all());
    }

    #[TestDox('a mapping is independent per column even on the same question')]
    public function test_a_mapping_is_independent_per_column_even_on_the_same_question(): void
    {
        $this->makeRegistrant('Ada', 'Lovelace', ['photopermission' => 'yes']);

        $report = $this->report();
        $this->questionColumn($report, 'photopermission');
        $this->mappedColumn($report, 'photopermission', ['yes' => 'Consents']);

        $this->assertSame([['yes', 'Consents']], $this->runner()->rows($report)->all());
    }

    #[TestDox('mapped display interpolates the mapping target against the rows own answers')]
    public function test_mapped_display_interpolates_the_mapping_target_against_the_rows_own_answers(): void
    {
        $this->makeRegistrant('Ada', 'Lovelace', ['photopermission' => 'yes']);

        $report = $this->report();
        $this->mappedColumn($report, 'photopermission', ['yes' => 'Approved for {q:name.value}']);

        $this->assertSame([['Approved for Ada']], $this->runner()->rows($report)->all());
    }

    #[TestDox('mapped display can switch text by guest-ness from a blank, wildcard stored value')]
    public function test_mapped_display_can_switch_text_by_guest_ness_from_a_blank_wildcard_stored_value(): void
    {
        $host = $this->makeRegistrant('Ada', 'Lovelace');
        $this->makeGuest($host, GuestType::Adult, ['guestname' => 'Adult Guest']);

        $report = $this->report(['include_adult_guests' => true]);
        // Attached to a Participant-scope question purely to activate the
        // Mapped display path — its stored value (the registrant's own,
        // reaching the guest row too) never affects which entry wins here
        // since every entry is a blank/wildcard one, matched by guest-ness alone.
        $this->mappedEntriesColumn($report, 'name', [
            ['value' => null, 'guest' => 'guest', 'text' => '{q:guestname.value}'],
            ['value' => null, 'guest' => 'non_guest', 'text' => '{q:name.value} {q:lastname.value}'],
        ]);

        $this->assertSame([['Ada Lovelace'], ['Adult Guest']], $this->runner()->rows($report)->all());
    }

    #[TestDox('a guest-filtered mapping entry is skipped on the other row type, falling through to the next entry')]
    public function test_a_guest_filtered_mapping_entry_is_skipped_on_the_other_row_type(): void
    {
        $this->makeRegistrant('Ada', 'Lovelace', ['photopermission' => 'yes']);

        $report = $this->report();
        $this->mappedEntriesColumn($report, 'photopermission', [
            ['value' => 'yes', 'guest' => 'guest', 'text' => 'Guest consents'],
            ['value' => 'yes', 'guest' => 'any', 'text' => 'Consents'],
        ]);

        $this->assertSame([['Consents']], $this->runner()->rows($report)->all());
    }

    #[TestDox('a blank custom column is always blank regardless of the row')]
    public function test_a_blank_custom_column_is_always_blank_regardless_of_the_row(): void
    {
        $this->makeRegistrant('Ada', 'Lovelace', ['photopermission' => 'yes']);

        $report = $this->report();
        $this->questionColumn($report, 'photopermission');
        $this->blankColumn($report, 'Notes');

        $this->assertSame([['yes', null]], $this->runner()->rows($report)->all());
    }

    #[TestDox('a cell rule blanks the cell for rows that fail it')]
    public function test_a_cell_rule_blanks_the_cell_for_rows_that_fail_it(): void
    {
        $this->makeRegistrant('Shows', 'Email', ['directorypref' => ['ShowEmail']]);
        $this->makeRegistrant('Hides', 'Email', ['directorypref' => ['ShowBadgeName']]);

        $report = $this->report();
        $this->builtinColumn($report, ReportField::BadgeName);
        $email = $this->builtinColumn($report, ReportField::Email);
        $this->rule($email, 'directorypref', ConditionOperator::In, 'ShowEmail');

        $rows = $this->runner()->rows($report)->all();
        // Alphabetical: Hides Email first. Its email cell is blanked; the
        // matching registrant keeps theirs.
        $this->assertSame(['Hides Email', null], $rows[0]);
        $this->assertSame('Shows Email', $rows[1][0]);
        $this->assertNotNull($rows[1][1]);
    }

    #[TestDox('guest rows evaluate cell rules against the merged registrant and guest answers')]
    public function test_guest_rows_evaluate_cell_rules_against_the_merged_registrant_and_guest_answers(): void
    {
        // A Participant-scope condition applies to the whole family: the
        // registrant's directory preference reaches their guest's row.
        $shown = $this->makeRegistrant('Shown', 'Family', ['directorypref' => ['ShowBadgeName']]);
        $this->makeGuest($shown, GuestType::Adult, ['guestname' => 'Shown Guest']);
        $hidden = $this->makeRegistrant('Zidden', 'Family');
        $this->makeGuest($hidden, GuestType::Adult, ['guestname' => 'Hidden Guest']);

        $report = $this->report(['include_adult_guests' => true]);
        $name = $this->builtinColumn($report, ReportField::BadgeName);
        $this->rule($name, 'directorypref', ConditionOperator::In, 'ShowBadgeName');

        $this->assertSame(
            [['Shown Family'], ['Shown Guest'], [null], [null]],
            $this->runner()->rows($report)->all(),
        );
    }

    #[TestDox('built-in cells cover email entry type badge name and organization')]
    public function test_built_in_cells_cover_email_entry_type_badge_name_and_organization(): void
    {
        $host = $this->makeRegistrant('Host', 'Registrant', ['badgename' => 'The Host']);
        $this->makeGuest($host, GuestType::Adult, ['guestname' => 'Adult Guest']);
        $this->makeGuest($host, GuestType::Minor, ['guestname' => 'Minor Guest']);

        $report = $this->report(['include_adult_guests' => true, 'include_minor_guests' => true]);
        $this->builtinColumn($report, ReportField::Email);
        $this->builtinColumn($report, ReportField::EntryType);
        $this->builtinColumn($report, ReportField::BadgeName);
        $this->builtinColumn($report, ReportField::Organization);

        $this->assertSame([
            // The registrant's email; guests never carry one. Organization
            // falls back to the group name and covers the whole family.
            [$host->email, __('registration::admin.report_entry_attendee'), 'The Host', 'Analytical Engines'],
            [null, __('registration::admin.report_entry_adult_guest'), 'Adult Guest', 'Analytical Engines'],
            [null, __('registration::admin.report_entry_minor_guest'), 'Minor Guest', 'Analytical Engines'],
        ], $this->runner()->rows($report)->all());
    }
}
