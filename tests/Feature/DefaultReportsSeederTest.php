<?php

namespace ConferenceTools\Registration\Tests\Feature;

use ConferenceTools\Registration\Database\Seeders\DefaultReportsSeeder;
use ConferenceTools\Registration\Enums\ConditionOperator;
use ConferenceTools\Registration\Enums\ReportColumnDisplay;
use ConferenceTools\Registration\Enums\ReportType;
use ConferenceTools\Registration\Models\Report;
use ConferenceTools\Registration\Models\ReportColumn;
use ConferenceTools\Registration\Models\Setting;
use ConferenceTools\Registration\Tests\Concerns\BuildsReportData;
use ConferenceTools\Registration\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * The seeder that recreates the six formerly hard-coded reports as
 * admin-defined Report rows from the installation's nomination settings —
 * including the retired settings it reads by their literal strings.
 */
#[TestDox('Default Reports Seeder')]
class DefaultReportsSeederTest extends TestCase
{
    use BuildsReportData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReportQuestions();
    }

    /** Run the seeder under test. */
    private function runSeeder(): void
    {
        $this->seed(DefaultReportsSeeder::class);
    }

    /** A seeded report by name. */
    private function reportNamed(string $name): Report
    {
        return Report::where('name', $name)->sole();
    }

    /**
     * The question key or built-in value identifying each of a report's
     * columns, in order — a blank column (neither) is identified by its
     * header instead.
     */
    private function columnSources(Report $report): array
    {
        return $report->columns()->with('question')->get()
            ->map(fn (ReportColumn $c): string => $c->question?->key ?? $c->field?->value ?? 'blank:'.$c->header)
            ->all();
    }

    #[TestDox('seeds the six reports with the nominated columns and rules')]
    public function test_seeds_the_six_reports_with_the_nominated_columns_and_rules(): void
    {
        $this->seedRetiredReportSettings();
        $this->runSeeder();

        $this->assertSame(6, Report::count());

        $attendees = $this->reportNamed('Attendee List');
        $this->assertTrue($attendees->include_adult_guests);
        $this->assertTrue($attendees->include_minor_guests);
        $this->assertSame(['name', 'lastname', 'entry_type'], $this->columnSources($attendees));
        $this->assertSame(['First Name', 'Last Name', 'Type'], $attendees->columns->map->heading()->all());
        $this->assertTrue($attendees->conditionGroups->isEmpty());

        $arrivals = $this->reportNamed('Arrivals');
        $this->assertSame(['badge_name', 'organization', 'arrivalday'], $this->columnSources($arrivals));

        $photos = $this->reportNamed('Photo Permission Form');
        $this->assertTrue($photos->include_adult_guests);
        $this->assertFalse($photos->include_minor_guests);
        $this->assertSame(
            ['photopermission', 'blank:Yes', 'blank:No', 'blank:Initials'],
            $this->columnSources($photos),
        );
        $consent = $photos->columns->firstWhere('question_id', '!=', null);
        $this->assertSame(ReportColumnDisplay::Mapped, $consent->display);
        $this->assertSame('Name', $consent->heading());
        $this->assertSame('guestphoto', $consent->guestQuestion->key);
        $this->assertSame([
            ['value' => 'Yes', 'guest' => 'non_guest', 'text' => '{q:name.value q:lastname.value} *'],
            ['value' => 'No', 'guest' => 'non_guest', 'text' => '{q:name.value q:lastname.value}'],
            ['value' => 'Yes', 'guest' => 'guest', 'text' => '{q:guestname.value} *'],
            ['value' => 'No', 'guest' => 'guest', 'text' => '{q:guestname.value}'],
        ], $consent->mapping);

        $specialNeeds = $this->reportNamed('Special Needs');
        $this->assertSame(['name', 'lastname', 'specialneeds'], $this->columnSources($specialNeeds));
        $condition = $specialNeeds->conditionGroups->sole()->conditions->sole();
        $this->assertSame(ConditionOperator::IsAnswered, $condition->operator);
        $this->assertSame('specialneeds', $condition->question->key);

        $firstTime = $this->reportNamed('First-Time Attendees');
        $this->assertSame(['name', 'lastname'], $this->columnSources($firstTime));
        $condition = $firstTime->conditionGroups->sole()->conditions->sole();
        $this->assertSame(ConditionOperator::In, $condition->operator);
        // No stored value list: the retired code default "yes" stands in.
        $this->assertSame('yes', $condition->value);
    }

    #[TestDox('the directory report carries the row rule and per column cell rules')]
    public function test_the_directory_report_carries_the_row_rule_and_per_column_cell_rules(): void
    {
        $this->seedRetiredReportSettings();
        Setting::put('report_directory_omit_values', 'NoName, Hermit');
        Setting::put('guest_adults_in_directory', '1');
        $this->runSeeder();

        $directory = $this->reportNamed('Directory');
        $this->assertTrue($directory->include_adult_guests);
        $this->assertFalse($directory->include_minor_guests);
        $this->assertSame(['badge_name', 'organization', 'email'], $this->columnSources($directory));

        $rowCondition = $directory->conditionGroups->sole()->conditions->sole();
        $this->assertSame(ConditionOperator::In, $rowCondition->operator);
        $this->assertSame('NoName,Hermit', $rowCondition->value);
        $this->assertSame('directorypref', $rowCondition->question->key);

        // Each column gates on its designated "show ..." answers (defaults
        // while the settings rows are absent).
        $cellRules = $directory->columns->map(
            fn (ReportColumn $c): string => $c->conditionGroups()->get()->sole()->conditions->sole()->value,
        );
        $this->assertSame(['ShowBadgeName', 'ShowOrg', 'ShowEmail'], $cellRules->all());
    }

    #[DataProvider('unnominatedOutcomes')]
    #[TestDox('unnominated questions skip their columns and rules')]
    public function test_unnominated_questions_skip_their_columns_and_rules(string $name, array $columns, int $rootGroups): void
    {
        // No retired settings at all — the upgraded-but-never-configured case.
        $this->runSeeder();

        $report = $this->reportNamed($name);
        $this->assertSame($columns, $this->columnSources($report));
        $this->assertCount($rootGroups, $report->conditionGroups);
    }

    /** The unnominated outcomes for the data provider. */
    public static function unnominatedOutcomes(): array
    {
        return [
            'photo form loses its consent column' => [
                'Photo Permission Form',
                ['blank:Yes', 'blank:No', 'blank:Initials'],
                0,
            ],
            'special needs loses its answer column and rule' => ['Special Needs', ['name', 'lastname'], 0],
            'first-time loses its rule' => ['First-Time Attendees', ['name', 'lastname'], 0],
            'directory loses its rules' => ['Directory', ['badge_name', 'organization', 'email'], 0],
        ];
    }

    #[DataProvider('reportTypes')]
    #[TestDox('each report is seeded with its type, replacing the type of an existing row')]
    public function test_each_report_is_seeded_with_its_type_replacing_the_type_of_an_existing_row(string $name, ReportType $type): void
    {
        $other = $type === ReportType::Individual ? ReportType::Registrant : ReportType::Individual;
        Report::factory()->create(['name' => $name, 'type' => $other]);

        $this->seedRetiredReportSettings();
        $this->runSeeder();

        $this->assertSame($type, $this->reportNamed($name)->type);
    }

    /** Each seeded report's name and type, for the data provider. */
    public static function reportTypes(): array
    {
        return [
            'attendee list' => ['Attendee List', ReportType::Registrant],
            'directory' => ['Directory', ReportType::Registrant],
            'arrivals' => ['Arrivals', ReportType::Registrant],
            'photo permission form' => ['Photo Permission Form', ReportType::Registrant],
            'special needs' => ['Special Needs', ReportType::Individual],
            'first-time attendees' => ['First-Time Attendees', ReportType::Registrant],
        ];
    }

    #[TestDox('reseeding rebuilds without duplicating reports columns or rules')]
    public function test_reseeding_rebuilds_without_duplicating_reports_columns_or_rules(): void
    {
        $this->seedRetiredReportSettings();
        $this->runSeeder();
        $this->runSeeder();

        $this->assertSame(6, Report::count());

        $directory = $this->reportNamed('Directory');
        $this->assertSame(3, $directory->columns()->count());
        $this->assertCount(1, $directory->conditionGroups);
        $this->assertCount(1, $directory->columns->first()->conditionGroups);
    }
}
