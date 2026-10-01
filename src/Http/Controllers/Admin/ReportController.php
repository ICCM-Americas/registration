<?php

namespace ConferenceTools\Registration\Http\Controllers\Admin;

use ConferenceTools\Branding\Contracts\BrandingProvider;
use ConferenceTools\Registration\Enums\QuestionScope;
use ConferenceTools\Registration\Enums\ReportColumnDisplay;
use ConferenceTools\Registration\Enums\ReportField;
use ConferenceTools\Registration\Http\Controllers\Controller;
use ConferenceTools\Registration\Models\Question;
use ConferenceTools\Registration\Models\Report;
use ConferenceTools\Registration\Models\ReportColumn;
use ConferenceTools\Registration\Services\ConferenceEdition;
use ConferenceTools\Registration\Services\CsvExport;
use ConferenceTools\Registration\Services\ReportRunner;
use ConferenceTools\Registration\Services\VariableInterpolator;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The admin-defined reports: the Reports page lists them, and each one is
 * created and shaped here — name/description/header/footer/guest inclusion, the
 * drag-reorderable columns (question answers, built-in fields, or blank
 * custom columns, each re-sourceable after creation; value, label, or
 * per-column mapped display; an optional heading override), and
 * viewing/exporting the result (on-screen table, CSV, client-side PDF).
 * storeColumn() answers AJAX add requests (the editor's normal path) with the
 * new row's HTML rather than a redirect. Row and cell rules are edited by
 * {@see ReportVisibilityController} and {@see ReportColumnVisibilityController};
 * a mapped column's own value -> text overrides are edited by
 * {@see ReportColumnMappingController}.
 */
class ReportController extends Controller
{
    public function __construct(private VariableInterpolator $interpolator) {}

    /** The Reports page: every defined report with its actions. */
    public function index()
    {
        return view('registration::admin.reports.index', [
            'reports' => Report::orderBy('name')->get(),
        ]);
    }

    /** The blank definition form. */
    public function create()
    {
        return view('registration::admin.reports.form', ['report' => new Report]);
    }

    /** Create a report and continue to its full editor (columns and rules). */
    public function store(Request $request)
    {
        $report = Report::create($this->validated($request));

        return redirect()
            ->route($this->routeName('admin.reports.edit'), $report)
            ->with('reports_status', __('registration::admin.report_saved'));
    }

    /** The full editor: definition, columns, and rule entry points. */
    public function edit(Report $report)
    {
        $report->load(['columns.question.section', 'columns.conditionGroups', 'conditionGroups']);

        // Labels are resolved here (keys derived from the enum values) so the
        // view references only static translation keys.
        return view('registration::admin.reports.form', [
            'report' => $report,
            'builtins' => ReportField::cases(),
            'questionGroups' => $this->questionGroups(),
            'displays' => $this->displayChoices(),
        ]);
    }

    /** Save the definition fields. */
    public function update(Request $request, Report $report)
    {
        $report->update($this->validated($request));

        return redirect()
            ->route($this->routeName('admin.reports.edit'), $report)
            ->with('reports_status', __('registration::admin.report_saved'));
    }

    /** Delete a report (its columns and rules go with it). */
    public function destroy(Report $report)
    {
        $report->delete();

        return redirect()
            ->route($this->routeName('admin.reports'))
            ->with('reports_status', __('registration::admin.report_deleted'));
    }

    /** Append a column: a question's answer, a built-in field, or a blank custom column. */
    public function storeColumn(Request $request, Report $report)
    {
        $request->validate([
            'source' => ['required', 'string', Rule::in($this->sourceChoices())],
        ]);

        [$kind, $id] = $this->parseSource($request->input('source'));

        // A blank column's display select is disabled client-side (nothing to
        // show it as), so it may not be submitted at all; a blank column has
        // no other source for its heading, so that one is required instead.
        $data = $request->validate([
            'display' => [Rule::requiredIf($kind !== 'none'), 'nullable', Rule::in(ReportColumnDisplay::values())],
            'header' => [Rule::requiredIf($kind === 'none'), 'nullable', 'string', 'max:255'],
        ]);

        $column = $report->columns()->create([
            'question_id' => $kind === 'question' ? (int) $id : null,
            'field' => $kind === 'field' ? $id : null,
            'display' => $data['display'] ?? ReportColumnDisplay::Value->value,
            'header' => $data['header'] ?? null,
            'position' => ((int) $report->columns()->max('position')) + 1,
        ]);

        if ($request->wantsJson()) {
            return response()->json(['html' => $this->columnRowHtml($report, $column)]);
        }

        return $this->backToEditor($report);
    }

    /** Save a column's source, display mode, and heading override. */
    public function updateColumn(Request $request, Report $report, ReportColumn $column)
    {
        abort_unless($column->report_id === $report->id, 404);

        $request->validate([
            'source' => ['sometimes', 'string', Rule::in($this->sourceChoices())],
        ]);

        $kind = $request->has('source')
            ? $this->parseSource($request->input('source'))[0]
            : $this->currentKind($column);

        $data = $request->validate([
            'display' => [Rule::requiredIf($kind !== 'none'), 'nullable', Rule::in(ReportColumnDisplay::values())],
            'header' => [Rule::requiredIf($kind === 'none'), 'nullable', 'string', 'max:255'],
            'guest_question_id' => ['nullable', 'integer', Rule::exists((new Question)->getTable(), 'id')],
        ]);

        if ($request->has('source')) {
            [$kind, $id] = $this->parseSource($request->input('source'));
            $data['question_id'] = $kind === 'question' ? (int) $id : null;
            $data['field'] = $kind === 'field' ? $id : null;
        }

        // A guest-question override only means anything alongside a question
        // column; a disabled select submits nothing, so it's only cleared
        // here when the source itself just moved away from being one.
        if (($data['question_id'] ?? $column->question_id) === null) {
            $data['guest_question_id'] = null;
        }

        $column->update($data);

        return $this->backToEditor($report);
    }

    /** Persist a drag-and-drop reorder of the report's columns. */
    public function reorderColumns(Request $request, Report $report)
    {
        $data = $request->validate([
            'columns' => ['array'],
            'columns.*.id' => ['required', 'integer'],
            'columns.*.position' => ['required', 'integer'],
        ]);

        foreach ($data['columns'] ?? [] as $row) {
            $report->columns()->whereKey($row['id'])->update(['position' => $row['position']]);
        }

        return response()->json(['status' => 'ok']);
    }

    /** Remove a column (its cell rule goes with it). */
    public function destroyColumn(Report $report, ReportColumn $column)
    {
        abort_unless($column->report_id === $report->id, 404);

        $column->delete();

        return $this->backToEditor($report);
    }

    /** The report page itself: on-screen table plus the PDF payload. */
    public function show(Report $report, ReportRunner $runner)
    {
        $headers = $runner->headers($report);
        $rows = $runner->rows($report);

        return view('registration::admin.reports.show', [
            'report' => $report,
            'headers' => $headers,
            'rows' => $rows,
            'pdfPayload' => $this->pdfPayload($report, $headers, $rows),
        ]);
    }

    /** The report as a CSV download. */
    public function csv(Report $report, ReportRunner $runner, CsvExport $exporter): StreamedResponse
    {
        return $this->downloadCsv($exporter, Str::slug($report->name), $runner->headers($report), $runner->rows($report));
    }

    /** The validated definition fields (shared by store and update). */
    private function validated(Request $request): array
    {
        return [
            ...$request->validate([
                'name' => ['required', 'string', 'max:255'],
                'description' => ['nullable', 'string', 'max:4096'],
                'header' => ['nullable', 'string', 'max:4096'],
                'footer' => ['nullable', 'string', 'max:4096'],
            ]),
            'include_adult_guests' => $request->boolean('include_adult_guests'),
            'include_minor_guests' => $request->boolean('include_minor_guests'),
        ];
    }

    /**
     * Everything the view's client-side PDF generator needs — strings
     * localized and rows pre-formatted here, so the script stays
     * presentation-only. The report's own header/footer text is interpolated
     * here too, since the PDF script has no access to the interpolator.
     *
     * @param  array<int, string>  $headers
     * @param  Collection<int, array<int, ?string>>  $rows
     */
    private function pdfPayload(Report $report, array $headers, Collection $rows): array
    {
        $edition = app(ConferenceEdition::class);
        $pdfRows = $rows->map(fn (array $row): array => array_map(fn (?string $cell): string => (string) $cell, $row))->all();
        $pdfRows[] = $this->countPdfRow($rows->count(), count($headers));

        return [
            'filename' => Str::slug($report->name),
            'paper' => $this->pdfPaperSize(),
            // Wide tables read better sideways; short ones save paper upright.
            'orientation' => count($headers) > 3 ? 'landscape' : 'portrait',
            'title' => trim(($edition->name() ?? app(BrandingProvider::class)->siteName()).' '.$edition->year())
                .' — '.$report->name,
            'head' => $headers,
            'rows' => $pdfRows,
            'header' => $this->interpolator->interpolate($report->header),
            'footer' => $this->interpolator->interpolate($report->footer),
        ];
    }

    /**
     * Every value the add-column source select may submit: "none" (a blank
     * custom column), "field:<builtin>", or "question:<id>".
     *
     * @return array<int, string>
     */
    private function sourceChoices(): array
    {
        $fields = array_map(fn (string $value): string => 'field:'.$value, ReportField::values());
        $questions = Question::pluck('id')->map(fn ($id): string => 'question:'.$id)->all();

        return ['none', ...$fields, ...$questions];
    }

    /**
     * A validated source value split into its kind ("none", "field", or
     * "question") and id (null for "none").
     *
     * @return array{0: string, 1: ?string}
     */
    private function parseSource(string $source): array
    {
        if ($source === 'none') {
            return ['none', null];
        }

        return explode(':', $source, 2);
    }

    /** An existing column's kind, for validating an update that leaves its source untouched. */
    private function currentKind(ReportColumn $column): string
    {
        return match (true) {
            $column->question_id !== null => 'question',
            $column->field !== null => 'field',
            default => 'none',
        };
    }

    /** The display mode choices, keyed by value, for the source/display selects. */
    private function displayChoices(): array
    {
        return collect(ReportColumnDisplay::cases())
            ->mapWithKeys(fn (ReportColumnDisplay $display): array => [
                $display->value => __('registration::admin.report_column_display_'.$display->value),
            ])
            ->all();
    }

    /** A freshly created column's row, rendered for the AJAX add response. */
    private function columnRowHtml(Report $report, ReportColumn $column): string
    {
        return view('registration::admin.reports.column-row', [
            'report' => $report,
            'column' => $column,
            'displays' => $this->displayChoices(),
            'builtins' => ReportField::cases(),
            'questionGroups' => $this->questionGroups(),
        ])->render();
    }

    /**
     * The configured questions grouped for the source select's optgroups —
     * one translated-label group per scope, each ordered by key.
     *
     * @return array<int, array{label: string, questions: Collection<int, Question>}>
     */
    private function questionGroups(): array
    {
        $byScope = Question::with('section')->orderBy('key')->get()
            ->groupBy(fn (Question $q): string => $q->section?->scope?->value ?? QuestionScope::Participant->value);

        return collect(QuestionScope::cases())
            ->map(fn (QuestionScope $scope): array => [
                'label' => __('registration::admin.report_column_scope_'.$scope->value),
                'questions' => $byScope->get($scope->value, collect()),
            ])
            ->all();
    }

    /** Back to the editor's Columns card. */
    private function backToEditor(Report $report)
    {
        return redirect()->route($this->routeName('admin.reports.edit'), $report);
    }
}
