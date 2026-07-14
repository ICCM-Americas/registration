<?php

namespace ConferenceTools\Registration\Tests\Feature;

use ConferenceTools\Registration\Models\Question;
use ConferenceTools\Registration\Models\Report;
use ConferenceTools\Registration\Models\ReportColumn;
use ConferenceTools\Registration\Tests\Concerns\BuildsReportData;
use ConferenceTools\Registration\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * The mapping editor on a question column: an ordered list of
 * {value, guest, text} entries, appended and removed independently of any
 * other column on the same question.
 */
#[TestDox('Report Column Mapping Controller')]
class ReportColumnMappingControllerTest extends TestCase
{
    use BuildsReportData, RefreshDatabase;

    /** A question column on a fresh report, optionally with raw mapping entries. */
    private function column(?array $entries = null): ReportColumn
    {
        $question = Question::firstWhere('key', 'photopermission');
        $factory = $entries !== null ? ReportColumn::factory()->mappedEntries($entries) : ReportColumn::factory();

        return $factory->create([
            'report_id' => Report::factory()->create()->id,
            'question_id' => $question->id,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->allowRegistrationManagement();
        $this->seedReportQuestions();
    }

    #[TestDox('requires the gate')]
    public function test_requires_the_gate(): void
    {
        $this->denyRegistrationManagement();

        $this->actingAs($this->makeUser())
            ->get(route('registration.admin.report_columns.mapping', $this->column()))
            ->assertForbidden();
    }

    #[TestDox('the editor renders the empty state with no mapping rows')]
    public function test_the_editor_renders_the_empty_state_with_no_mapping_rows(): void
    {
        $this->actingAs($this->makeUser())
            ->get(route('registration.admin.report_columns.mapping', $this->column()))
            ->assertOk()
            ->assertSee(__('registration::admin.report_column_mapping_empty'));
    }

    #[TestDox('the editor lists the existing mapping rows, showing a blank value as "any"')]
    public function test_the_editor_lists_the_existing_mapping_rows_showing_a_blank_value_as_any(): void
    {
        $column = $this->column([
            ['value' => 'yes', 'guest' => 'any', 'text' => 'Consents'],
            ['value' => null, 'guest' => 'guest', 'text' => '{q:guest_name.value}'],
        ]);

        $this->actingAs($this->makeUser())
            ->get(route('registration.admin.report_columns.mapping', $column))
            ->assertOk()
            ->assertSee('yes')
            ->assertSee('Consents')
            ->assertSee(__('registration::admin.report_column_mapping_any_value'))
            ->assertSee(__('registration::admin.report_column_mapping_guest_guest'))
            ->assertSee('{q:guest_name.value}');
    }

    #[TestDox('storing a row appends it to the mapping')]
    public function test_storing_a_row_appends_it_to_the_mapping(): void
    {
        $column = $this->column();

        $this->actingAs($this->makeUser())
            ->post(route('registration.admin.report_columns.mapping.store', $column), [
                'value' => 'yes',
                'guest' => 'any',
                'text' => 'Consents',
            ])->assertOk();

        $this->assertSame([['value' => 'yes', 'guest' => 'any', 'text' => 'Consents']], $column->fresh()->mapping);
    }

    #[TestDox('storing a row with a blank value stores it as a wildcard')]
    public function test_storing_a_row_with_a_blank_value_stores_it_as_a_wildcard(): void
    {
        $column = $this->column();

        $this->actingAs($this->makeUser())
            ->post(route('registration.admin.report_columns.mapping.store', $column), [
                'value' => '',
                'guest' => 'non_guest',
                'text' => '{q:first_name.value} {q:last_name.value}',
            ])->assertOk();

        $this->assertSame(
            [['value' => null, 'guest' => 'non_guest', 'text' => '{q:first_name.value} {q:last_name.value}']],
            $column->fresh()->mapping,
        );
    }

    #[TestDox('storing a row again for the same value appends another entry rather than replacing it')]
    public function test_storing_a_row_again_for_the_same_value_appends_another_entry(): void
    {
        $column = $this->column([['value' => 'yes', 'guest' => 'any', 'text' => 'Consents']]);

        $this->actingAs($this->makeUser())
            ->post(route('registration.admin.report_columns.mapping.store', $column), [
                'value' => 'yes',
                'guest' => 'guest',
                'text' => 'Guest consents',
            ])->assertOk();

        $this->assertSame([
            ['value' => 'yes', 'guest' => 'any', 'text' => 'Consents'],
            ['value' => 'yes', 'guest' => 'guest', 'text' => 'Guest consents'],
        ], $column->fresh()->mapping);
    }

    #[TestDox('destroying a row removes only that entry by its position')]
    public function test_destroying_a_row_removes_only_that_entry_by_its_position(): void
    {
        $column = $this->column([
            ['value' => 'yes', 'guest' => 'any', 'text' => 'Consents'],
            ['value' => 'no', 'guest' => 'any', 'text' => 'Declines'],
        ]);

        $this->actingAs($this->makeUser())
            ->delete(route('registration.admin.report_columns.mapping.destroy', $column), ['index' => 0])
            ->assertOk();

        $this->assertSame([['value' => 'no', 'guest' => 'any', 'text' => 'Declines']], $column->fresh()->mapping);
    }

    #[TestDox('a mapping is independent of another column on the same question')]
    public function test_a_mapping_is_independent_of_another_column_on_the_same_question(): void
    {
        $question = Question::firstWhere('key', 'photopermission');
        $report = Report::factory()->create();
        $raw = ReportColumn::factory()->create(['report_id' => $report->id, 'question_id' => $question->id]);
        $mapped = ReportColumn::factory()->mapped(['yes' => 'Consents'])->create(['report_id' => $report->id, 'question_id' => $question->id]);

        $this->actingAs($this->makeUser())
            ->post(route('registration.admin.report_columns.mapping.store', $mapped), [
                'value' => 'no',
                'guest' => 'any',
                'text' => 'Declines',
            ])->assertOk();

        $this->assertNull($raw->fresh()->mapping);
        $this->assertSame([
            ['value' => 'yes', 'guest' => 'any', 'text' => 'Consents'],
            ['value' => 'no', 'guest' => 'any', 'text' => 'Declines'],
        ], $mapped->fresh()->mapping);
    }
}
