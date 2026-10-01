<?php

namespace ConferenceTools\Registration\Tests\Feature;

use ConferenceTools\Registration\Enums\ReportColumnDisplay;
use ConferenceTools\Registration\Enums\ReportField;
use ConferenceTools\Registration\Models\Question;
use ConferenceTools\Registration\Models\Report;
use ConferenceTools\Registration\Models\ReportColumn;
use ConferenceTools\Registration\Models\Variable;
use ConferenceTools\Registration\Tests\Concerns\BuildsReportData;
use ConferenceTools\Registration\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * The admin-defined reports CRUD: the Reports list, the definition form, the
 * column editor, and the report/CSV pages. Row building itself is covered by
 * ReportRunnerTest.
 */
#[TestDox('Report Controller')]
class ReportControllerTest extends TestCase
{
    use BuildsReportData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->allowRegistrationManagement();
        $this->seedReportQuestions();
    }

    /** A saved report with no columns. */
    private function report(array $attributes = []): Report
    {
        return Report::factory()->create($attributes);
    }

    #[DataProvider('gatedRoutes')]
    #[TestDox('report pages require the gate')]
    public function test_report_pages_require_the_gate(string $route, bool $withReport): void
    {
        $this->denyRegistrationManagement();
        $parameters = $withReport ? [$this->report()] : [];

        $this->actingAs($this->makeUser())
            ->get(route($route, $parameters))
            ->assertForbidden();
    }

    /** The gated routes for the data provider. */
    public static function gatedRoutes(): array
    {
        return [
            'list' => ['registration.admin.reports', false],
            'create' => ['registration.admin.reports.create', false],
            'show' => ['registration.admin.reports.show', true],
            'edit' => ['registration.admin.reports.edit', true],
            'csv' => ['registration.admin.reports.csv', true],
        ];
    }

    #[TestDox('the list shows every report with its actions and an empty state')]
    public function test_the_list_shows_every_report_with_its_actions_and_an_empty_state(): void
    {
        $this->actingAs($this->makeUser())
            ->get(route('registration.admin.reports'))
            ->assertOk()
            ->assertSee(__('registration::admin.reports_empty'));

        $report = $this->report(['name' => 'Kitchen Crew', 'description' => 'Who volunteers in the kitchen.']);

        $this->actingAs($this->makeUser())
            ->get(route('registration.admin.reports'))
            ->assertOk()
            ->assertSee('Kitchen Crew')
            ->assertSee('Who volunteers in the kitchen.')
            ->assertSee(route('registration.admin.reports.show', $report))
            ->assertSee(route('registration.admin.reports.edit', $report));
    }

    #[TestDox('the list is sorted alphabetically by name')]
    public function test_the_list_is_sorted_alphabetically_by_name(): void
    {
        $this->report(['name' => 'Special Needs', 'position' => 10]);
        $this->report(['name' => 'Attendee List', 'position' => 30]);
        $this->report(['name' => 'Kitchen Crew', 'position' => 0]);

        $this->actingAs($this->makeUser())
            ->get(route('registration.admin.reports'))
            ->assertOk()
            ->assertSeeInOrder(['Attendee List', 'Kitchen Crew', 'Special Needs']);
    }

    #[TestDox('the numeric report routes never capture the console literals')]
    public function test_the_numeric_report_routes_never_capture_the_console_literals(): void
    {
        // reports/create is a literal segment declared alongside
        // reports/{report} — it must keep resolving to its own page, never
        // to a report id lookup.
        $this->actingAs($this->makeUser())->get(route('registration.admin.reports.create'))->assertOk();
    }

    #[TestDox('storing a report validates the name and continues to the editor')]
    public function test_storing_a_report_validates_the_name_and_continues_to_the_editor(): void
    {
        $this->actingAs($this->makeUser())
            ->post(route('registration.admin.reports.store'), ['name' => ''])
            ->assertSessionHasErrors('name');

        $this->actingAs($this->makeUser())
            ->post(route('registration.admin.reports.store'), [
                'name' => 'Kitchen Crew',
                'description' => 'Who volunteers in the kitchen.',
                'header' => 'Kitchen Crew Roster',
                'footer' => 'Report a spill to the duty manager.',
                'include_adult_guests' => '1',
            ]);

        $report = Report::sole();
        $this->assertSame('Kitchen Crew', $report->name);
        $this->assertSame('Kitchen Crew Roster', $report->header);
        $this->assertSame('Report a spill to the duty manager.', $report->footer);
        $this->assertTrue($report->include_adult_guests);
        $this->assertFalse($report->include_minor_guests);
    }

    #[TestDox('updating a report saves the definition fields')]
    public function test_updating_a_report_saves_the_definition_fields(): void
    {
        $report = $this->report(['include_adult_guests' => true, 'header' => 'Old Header']);

        $this->actingAs($this->makeUser())
            ->put(route('registration.admin.reports.update', $report), [
                'name' => 'Renamed',
                'description' => '',
                'header' => 'New Header',
                'footer' => 'New Footer',
                'include_adult_guests' => '0',
                'include_minor_guests' => '1',
            ])->assertRedirect(route('registration.admin.reports.edit', $report));

        $report->refresh();
        $this->assertSame('Renamed', $report->name);
        $this->assertSame('New Header', $report->header);
        $this->assertSame('New Footer', $report->footer);
        $this->assertFalse($report->include_adult_guests);
        $this->assertTrue($report->include_minor_guests);
    }

    #[TestDox('deleting a report removes its columns and rule trees')]
    public function test_deleting_a_report_removes_its_columns_and_rule_trees(): void
    {
        $report = $this->report();
        $column = ReportColumn::factory()->builtin(ReportField::Email)->create(['report_id' => $report->id]);
        $rowGroup = $report->conditionGroups()->create(['operator' => 'and']);
        $cellGroup = $column->conditionGroups()->create(['operator' => 'and']);

        $this->actingAs($this->makeUser())
            ->delete(route('registration.admin.reports.destroy', $report))
            ->assertRedirect(route('registration.admin.reports'));

        $this->assertNull(Report::find($report->id));
        $this->assertNull(ReportColumn::find($column->id));
        $this->assertDatabaseMissing($rowGroup->getTable(), ['id' => $rowGroup->id]);
        $this->assertDatabaseMissing($cellGroup->getTable(), ['id' => $cellGroup->id]);
    }

    #[DataProvider('columnSources')]
    #[TestDox('columns are appended from a question or built-in source')]
    public function test_columns_are_appended_from_a_question_or_built_in_source(string $sourceKind, string $display): void
    {
        $report = $this->report();
        $question = Question::firstWhere('key', 'photopermission');
        $source = $sourceKind === 'question' ? 'question:'.$question->id : 'field:email';

        $this->actingAs($this->makeUser())
            ->post(route('registration.admin.reports.columns.store', $report), [
                'source' => $source,
                'display' => $display,
                'header' => 'Custom Heading',
            ])->assertRedirect(route('registration.admin.reports.edit', $report));

        $column = $report->columns()->sole();
        $this->assertSame($sourceKind === 'question' ? $question->id : null, $column->question_id);
        $this->assertSame($sourceKind === 'question' ? null : ReportField::Email, $column->field);
        $this->assertSame($display, $column->display->value);
        $this->assertSame('Custom Heading', $column->header);
    }

    /** The column sources for the data provider. */
    public static function columnSources(): array
    {
        return [
            'question shown by value' => ['question', 'value'],
            'question shown by label' => ['question', 'label'],
            'question shown by mapping' => ['question', 'mapped'],
            'built-in email' => ['field', 'value'],
        ];
    }

    #[TestDox('a blank custom column is appended with neither a question nor a field')]
    public function test_a_blank_custom_column_is_appended_with_neither_a_question_nor_a_field(): void
    {
        $report = $this->report();

        $this->actingAs($this->makeUser())
            ->post(route('registration.admin.reports.columns.store', $report), [
                'source' => 'none',
                'display' => ReportColumnDisplay::Value->value,
                'header' => 'Notes',
            ])->assertRedirect(route('registration.admin.reports.edit', $report));

        $column = $report->columns()->sole();
        $this->assertNull($column->question_id);
        $this->assertNull($column->field);
        $this->assertSame('Notes', $column->header);
    }

    #[TestDox('a blank column defaults to value display when none is submitted')]
    public function test_a_blank_column_defaults_to_value_display_when_none_is_submitted(): void
    {
        $report = $this->report();

        $this->actingAs($this->makeUser())
            ->post(route('registration.admin.reports.columns.store', $report), [
                'source' => 'none',
                'header' => 'Notes',
            ])->assertRedirect(route('registration.admin.reports.edit', $report));

        $this->assertSame(ReportColumnDisplay::Value, $report->columns()->sole()->display);
    }

    #[TestDox('adding a column over ajax returns the new rows html instead of redirecting')]
    public function test_adding_a_column_over_ajax_returns_the_new_rows_html_instead_of_redirecting(): void
    {
        $report = $this->report();
        $question = Question::firstWhere('key', 'photopermission');

        $response = $this->actingAs($this->makeUser())
            ->postJson(route('registration.admin.reports.columns.store', $report), [
                'source' => 'question:'.$question->id,
                'display' => 'value',
            ])->assertOk();

        $column = $report->columns()->sole();
        $this->assertStringContainsString('data-column-id="'.$column->id.'"', $response->json('html'));
    }

    #[TestDox('a blank custom column requires a header')]
    public function test_a_blank_custom_column_requires_a_header(): void
    {
        $report = $this->report();

        $this->actingAs($this->makeUser())
            ->post(route('registration.admin.reports.columns.store', $report), [
                'source' => 'none',
                'display' => ReportColumnDisplay::Value->value,
            ])->assertSessionHasErrors('header');

        $this->assertSame(0, $report->columns()->count());
    }

    #[DataProvider('badColumnSources')]
    #[TestDox('bad column sources are rejected')]
    public function test_bad_column_sources_are_rejected(string $source): void
    {
        $report = $this->report();

        $this->actingAs($this->makeUser())
            ->post(route('registration.admin.reports.columns.store', $report), [
                'source' => $source,
                'display' => ReportColumnDisplay::Value->value,
            ])->assertSessionHasErrors('source');

        $this->assertSame(0, $report->columns()->count());
    }

    /** The bad column sources for the data provider. */
    public static function badColumnSources(): array
    {
        return [
            'unknown built-in' => ['field:shoe_size'],
            'unknown question id' => ['question:999999'],
            'no prefix' => ['email'],
        ];
    }

    #[TestDox('updating a column saves its display and heading override')]
    public function test_updating_a_column_saves_its_display_and_heading_override(): void
    {
        $report = $this->report();
        $column = ReportColumn::factory()->create([
            'report_id' => $report->id,
            'question_id' => Question::firstWhere('key', 'photopermission')->id,
        ]);

        $this->actingAs($this->makeUser())
            ->put(route('registration.admin.reports.columns.update', [$report, $column]), [
                'display' => 'label',
                'header' => 'Permission',
            ])->assertRedirect(route('registration.admin.reports.edit', $report));

        $column->refresh();
        $this->assertSame(ReportColumnDisplay::Label, $column->display);
        $this->assertSame('Permission', $column->header);
    }

    #[TestDox('updating a column can change its source to a different question')]
    public function test_updating_a_column_can_change_its_source_to_a_different_question(): void
    {
        $report = $this->report();
        $column = ReportColumn::factory()->create([
            'report_id' => $report->id,
            'question_id' => Question::firstWhere('key', 'photopermission')->id,
        ]);
        $target = Question::firstWhere('key', 'directorypref');

        $this->actingAs($this->makeUser())
            ->put(route('registration.admin.reports.columns.update', [$report, $column]), [
                'source' => 'question:'.$target->id,
                'display' => 'value',
                'header' => '',
            ])->assertRedirect(route('registration.admin.reports.edit', $report));

        $column->refresh();
        $this->assertSame($target->id, $column->question_id);
        $this->assertNull($column->field);
    }

    #[TestDox('updating a column to a blank source requires a header and clears its question and field')]
    public function test_updating_a_column_to_a_blank_source_requires_a_header_and_clears_its_question_and_field(): void
    {
        $report = $this->report();
        $column = ReportColumn::factory()->builtin(ReportField::Email)->create(['report_id' => $report->id]);

        $this->actingAs($this->makeUser())
            ->put(route('registration.admin.reports.columns.update', [$report, $column]), [
                'source' => 'none',
            ])->assertSessionHasErrors('header');

        $this->actingAs($this->makeUser())
            ->put(route('registration.admin.reports.columns.update', [$report, $column]), [
                'source' => 'none',
                'header' => 'Notes',
            ])->assertRedirect(route('registration.admin.reports.edit', $report));

        $column->refresh();
        $this->assertNull($column->question_id);
        $this->assertNull($column->field);
        $this->assertSame('Notes', $column->header);
    }

    #[TestDox('column actions 404 for a column of another report')]
    public function test_column_actions_404_for_a_column_of_another_report(): void
    {
        $report = $this->report();
        $foreign = ReportColumn::factory()->builtin(ReportField::Email)->create();

        $this->actingAs($this->makeUser())
            ->put(route('registration.admin.reports.columns.update', [$report, $foreign]), [
                'display' => 'value',
            ])->assertNotFound();

        $this->actingAs($this->makeUser())
            ->delete(route('registration.admin.reports.columns.destroy', [$report, $foreign]))
            ->assertNotFound();

        $this->assertNotNull(ReportColumn::find($foreign->id));
    }

    #[TestDox('reorder persists the dragged arrangement')]
    public function test_reorder_persists_the_dragged_arrangement(): void
    {
        $report = $this->report();
        $first = ReportColumn::factory()->builtin(ReportField::Email)->create(['report_id' => $report->id, 'position' => 1]);
        $second = ReportColumn::factory()->builtin(ReportField::BadgeName)->create(['report_id' => $report->id, 'position' => 2]);

        $this->actingAs($this->makeUser())
            ->postJson(route('registration.admin.reports.columns.reorder', $report), [
                'columns' => [
                    ['id' => $second->id, 'position' => 0],
                    ['id' => $first->id, 'position' => 1],
                ],
            ])->assertOk()->assertJson(['status' => 'ok']);

        $this->assertSame(0, $second->fresh()->position);
        $this->assertSame(1, $first->fresh()->position);
    }

    #[TestDox('reorder rejects a malformed payload')]
    public function test_reorder_rejects_a_malformed_payload(): void
    {
        $report = $this->report();

        $this->actingAs($this->makeUser())
            ->postJson(route('registration.admin.reports.columns.reorder', $report), [
                'columns' => [['id' => 'x']],
            ])->assertUnprocessable();
    }

    #[TestDox('reorder never moves a column belonging to another report')]
    public function test_reorder_never_moves_a_column_belonging_to_another_report(): void
    {
        $report = $this->report();
        $foreign = ReportColumn::factory()->builtin(ReportField::Email)->create(['position' => 5]);

        $this->actingAs($this->makeUser())
            ->postJson(route('registration.admin.reports.columns.reorder', $report), [
                'columns' => [['id' => $foreign->id, 'position' => 0]],
            ])->assertOk();

        $this->assertSame(5, $foreign->fresh()->position);
    }

    #[TestDox('deleting a column removes it and its cell rule tree')]
    public function test_deleting_a_column_removes_it_and_its_cell_rule_tree(): void
    {
        $report = $this->report();
        $column = ReportColumn::factory()->builtin(ReportField::Email)->create(['report_id' => $report->id]);
        $group = $column->conditionGroups()->create(['operator' => 'and']);

        $this->actingAs($this->makeUser())
            ->delete(route('registration.admin.reports.columns.destroy', [$report, $column]))
            ->assertRedirect(route('registration.admin.reports.edit', $report));

        $this->assertNull(ReportColumn::find($column->id));
        $this->assertDatabaseMissing($group->getTable(), ['id' => $group->id]);
    }

    #[TestDox('the editor offers built-ins scoped question groups and the rule links')]
    public function test_the_editor_offers_built_ins_scoped_question_groups_and_the_rule_links(): void
    {
        $report = $this->report();
        $column = ReportColumn::factory()->builtin(ReportField::Email)->create(['report_id' => $report->id]);

        $this->actingAs($this->makeUser())
            ->get(route('registration.admin.reports.edit', $report))
            ->assertOk()
            ->assertSee(__('registration::admin.report_column_builtin_group'))
            ->assertSee('field:email', false)
            ->assertSee('>badgename<', false)
            ->assertSee('>guestname<', false)
            ->assertSee(route('registration.admin.reports.visibility', $report))
            ->assertSee(route('registration.admin.report_columns.visibility', $column));
    }

    #[TestDox('the editor offers the blank custom column and a question columns mapping link')]
    public function test_the_editor_offers_the_blank_custom_column_and_a_question_columns_mapping_link(): void
    {
        $report = $this->report();
        $question = Question::firstWhere('key', 'photopermission');
        $column = ReportColumn::factory()->create(['report_id' => $report->id, 'question_id' => $question->id]);
        $builtin = ReportColumn::factory()->builtin(ReportField::Email)->create(['report_id' => $report->id, 'position' => 1]);

        $this->actingAs($this->makeUser())
            ->get(route('registration.admin.reports.edit', $report))
            ->assertOk()
            ->assertSee(__('registration::admin.report_column_custom_blank'))
            ->assertSee('value="none"', false)
            ->assertSee(route('registration.admin.report_columns.mapping', $column))
            ->assertDontSee(route('registration.admin.report_columns.mapping', $builtin));
    }

    #[TestDox('the report page renders the table the count and the pdf payload')]
    public function test_the_report_page_renders_the_table_the_count_and_the_pdf_payload(): void
    {
        $this->makeRegistrant('Ada', 'Lovelace');
        $report = $this->report(['name' => 'Roster']);
        ReportColumn::factory()->builtin(ReportField::BadgeName)->create(['report_id' => $report->id, 'header' => 'Name']);

        $response = $this->actingAs($this->makeUser())
            ->get(route('registration.admin.reports.show', $report))
            ->assertOk()
            ->assertSee('Roster')
            ->assertSee('Ada Lovelace')
            ->assertSee(__('registration::admin.export_pdf'))
            ->assertSee(route('registration.admin.reports.csv', $report));

        preg_match('/<script type="application\/json" id="report-pdf-data">(.*?)<\/script>/s', $response->getContent(), $match);
        $payload = json_decode($match[1], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['Name'], $payload['head']);
        $this->assertSame('portrait', $payload['orientation']);
        $this->assertStringContainsString('Roster', $payload['title']);
        // The final payload row is the count footer.
        $this->assertSame(__('registration::admin.report_count_label').': 1', end($payload['rows'])[0]);
    }

    #[TestDox('the report page and pdf payload interpolate the header and footer, but only when set')]
    public function test_the_report_page_and_pdf_payload_interpolate_the_header_and_footer(): void
    {
        Variable::factory()->create(['name' => 'conf', 'value' => 'ICCM 2026']);
        $report = $this->report(['name' => 'Roster', 'header' => 'Welcome to {conf}', 'footer' => 'Printed for {conf}.']);
        ReportColumn::factory()->builtin(ReportField::Email)->create(['report_id' => $report->id]);

        $response = $this->actingAs($this->makeUser())
            ->get(route('registration.admin.reports.show', $report))
            ->assertOk()
            ->assertSee('Welcome to ICCM 2026')
            ->assertSee('Printed for ICCM 2026.');

        preg_match('/<script type="application\/json" id="report-pdf-data">(.*?)<\/script>/s', $response->getContent(), $match);
        $payload = json_decode($match[1], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('Welcome to ICCM 2026', $payload['header']);
        $this->assertSame('Printed for ICCM 2026.', $payload['footer']);

        $blank = $this->report(['name' => 'Blank Roster']);
        ReportColumn::factory()->builtin(ReportField::Email)->create(['report_id' => $blank->id]);

        $blankResponse = $this->actingAs($this->makeUser())
            ->get(route('registration.admin.reports.show', $blank))
            ->assertOk();

        preg_match('/<script type="application\/json" id="report-pdf-data">(.*?)<\/script>/s', $blankResponse->getContent(), $blankMatch);
        $blankPayload = json_decode($blankMatch[1], true, 512, JSON_THROW_ON_ERROR);
        $this->assertNull($blankPayload['header']);
        $this->assertNull($blankPayload['footer']);
    }

    #[TestDox('a report with four or more columns exports landscape')]
    public function test_a_report_with_four_or_more_columns_exports_landscape(): void
    {
        $report = $this->report();
        foreach (ReportField::cases() as $position => $field) {
            ReportColumn::factory()->builtin($field)->create(['report_id' => $report->id, 'position' => $position]);
        }

        $content = $this->actingAs($this->makeUser())
            ->get(route('registration.admin.reports.show', $report))
            ->assertOk()
            ->getContent();

        preg_match('/<script type="application\/json" id="report-pdf-data">(.*?)<\/script>/s', $content, $match);
        $this->assertSame('landscape', json_decode($match[1], true)['orientation']);
    }

    #[TestDox('the report page shows the empty state with no rows')]
    public function test_the_report_page_shows_the_empty_state_with_no_rows(): void
    {
        $report = $this->report();
        ReportColumn::factory()->builtin(ReportField::Email)->create(['report_id' => $report->id]);

        $this->actingAs($this->makeUser())
            ->get(route('registration.admin.reports.show', $report))
            ->assertOk()
            ->assertSee(__('registration::admin.report_no_rows'));
    }

    #[TestDox('the csv export streams the headers rows and count footer')]
    public function test_the_csv_export_streams_the_headers_rows_and_count_footer(): void
    {
        $this->makeRegistrant('Ada', 'Lovelace');
        $report = $this->report(['name' => 'Roster']);
        ReportColumn::factory()->builtin(ReportField::BadgeName)->create(['report_id' => $report->id, 'header' => 'Name']);

        $content = $this->actingAs($this->makeUser())
            ->get(route('registration.admin.reports.csv', $report))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->streamedContent();

        $this->assertStringContainsString('Name', $content);
        $this->assertStringContainsString('Ada Lovelace', $content);
        $this->assertStringEndsWith("\n\n".__('registration::admin.report_count_label').",=ROW()-3\n", $content);
    }
}
