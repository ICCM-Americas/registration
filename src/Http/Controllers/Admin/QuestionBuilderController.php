<?php

namespace ConferenceTools\Registration\Http\Controllers\Admin;

use ConferenceTools\Registration\Enums\PerDiemScope;
use ConferenceTools\Registration\Enums\QuestionScope;
use ConferenceTools\Registration\Enums\QuestionType;
use ConferenceTools\Registration\Http\Controllers\Controller;
use ConferenceTools\Registration\Models\Question;
use ConferenceTools\Registration\Models\QuestionOption;
use ConferenceTools\Registration\Models\Section;
use ConferenceTools\Registration\Services\AnswerTextSync;
use ConferenceTools\Registration\Services\RegistrationStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * The admin form builder: arrange sections (wizard steps) and their questions by
 * drag and drop, and create/edit/delete sections, questions and their options.
 *
 * Ordering is the heart of it — questions can be reordered within a section and
 * moved between sections of the same scope; {@see reorder()} persists the whole
 * arrangement in one request. Visibility-rule editing is intentionally left to a
 * later screen (the rule data model already exists and is exercised by the
 * seeded "Other organization type" question).
 *
 * Question and option mutations (including reordering questions, and a
 * section's title, but not the order of sections) refuse to save while
 * {@see RegistrationStatus::answersLocked()} —
 * the views render every such control disabled, and these guards are the
 * server-side backstop for a direct request. The exception is a question's
 * texts (label, help text, placeholder, and its options' values, labels and
 * help texts), which stay editable. Every question update runs through
 * {@see AnswerTextSync}, so stored answers follow the edited texts; a
 * "preview" submission reports how many would change without saving.
 */
class QuestionBuilderController extends Controller
{
    public function __construct(private RegistrationStatus $status, private AnswerTextSync $sync) {}

    /** The question builder console, listing every scope's sections and questions. */
    public function index()
    {
        return view('registration::admin.questions.index', [
            'scopes' => $this->sectionsByScope(),
            'types' => QuestionType::cases(),
            'locked' => $this->status->answersLocked(),
        ]);
    }

    /**
     * Persist a drag-and-drop rearrangement: section order plus each question's
     * section and position. Sent as JSON by the builder after every drop.
     * Section positions always apply (sections are never locked); question
     * positions are skipped while locked — harmless, since the UI never lets a
     * locked user generate a changed-questions payload in the first place.
     */
    public function reorder(Request $request)
    {
        $data = $request->validate([
            'sections' => ['array'],
            'sections.*.id' => ['required', 'integer'],
            'sections.*.position' => ['required', 'integer'],
            'questions' => ['array'],
            'questions.*.id' => ['required', 'integer'],
            'questions.*.section_id' => ['required', 'integer'],
            'questions.*.position' => ['required', 'integer'],
        ]);

        $locked = $this->status->answersLocked();

        DB::transaction(function () use ($data, $locked) {
            foreach ($data['sections'] ?? [] as $row) {
                Section::whereKey($row['id'])->update(['position' => $row['position']]);
            }

            if ($locked) {
                return;
            }

            foreach ($data['questions'] ?? [] as $row) {
                Question::whereKey($row['id'])->update([
                    'section_id' => $row['section_id'],
                    'position' => $row['position'],
                ]);
            }
        });

        return response()->json(['status' => 'ok']);
    }

    /** Add a section to a scope. */
    public function storeSection(Request $request)
    {
        $data = $request->validate([
            'scope' => ['required', Rule::in(QuestionScope::values())],
            'title' => ['required', 'string', 'max:255'],
        ]);

        Section::create([
            'scope' => $data['scope'],
            'key' => $this->uniqueSectionKey($data['scope'], $data['title']),
            'title' => $data['title'],
            'position' => $this->nextSectionPosition($data['scope']),
            'enabled' => true,
        ]);

        return redirect()->route($this->routeName('admin.questions'));
    }

    /**
     * Save a section's title and settings. Description and enabled are left
     * as they are unless sent — the builder's inline title field posts the
     * title alone, and enabled is the visibility editor's to set.
     */
    public function updateSection(Request $request, Section $section)
    {
        if ($this->status->answersLocked()) {
            return $this->back('editor_locked');
        }

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'enabled' => ['boolean'],
        ]);

        $section->title = $data['title'];

        if ($request->has('description')) {
            $section->description = $data['description'] ?? null;
        }

        if ($request->has('enabled')) {
            $section->enabled = $request->boolean('enabled');
        }

        $section->save();

        return redirect(route($this->routeName('admin.questions')).'#section-'.$section->id);
    }

    /** Remove a section and its questions. */
    public function destroySection(Section $section)
    {
        abort_if($section->is_system, 403);

        // Covers a system question dragged out into an ordinary section —
        // that section still can't be deleted out from under it.
        if ($section->questions->contains('is_system', true)) {
            return $this->back('questions_system_move_first');
        }

        $section->delete();

        return redirect()->route($this->routeName('admin.questions'));
    }

    /** Show the add-question form for a section. */
    public function createQuestion(Request $request)
    {
        $question = new Question(['section_id' => $request->integer('section')]);

        return view('registration::admin.questions.form', [
            'question' => $question,
            'sections' => $this->sectionsByScope(),
            'types' => QuestionType::cases(),
            'optionRows' => $this->optionRows($question),
            'locked' => $this->status->answersLocked(),
        ]);
    }

    /** Add a question with its options. */
    public function storeQuestion(Request $request)
    {
        if ($this->status->answersLocked()) {
            return $this->back('questions_locked');
        }

        $data = $this->validateQuestion($request);
        $section = Section::findOrFail($data['section_id']);

        $question = Question::create([
            'section_id' => $section->id,
            'key' => $this->uniqueQuestionKey($section->scope, ($data['key'] ?? '') ?: $data['label']),
            'type' => $data['type'],
            'label' => $data['label'],
            'help_text' => $data['help_text'] ?? null,
            'placeholder' => $data['placeholder'] ?? null,
            'translate_value' => $request->boolean('translate_value'),
            'required' => $request->boolean('required'),
            'position' => $this->nextQuestionPosition($section),
            'enabled' => true,
        ]);

        $this->syncOptions($question, $data['options'] ?? []);

        return $this->toQuestion($question);
    }

    /** Show the edit form for a question. */
    public function editQuestion(Question $question)
    {
        // Option rules ride along for the per-option "conditional" badges.
        $question->load('options.conditionGroups');

        return view('registration::admin.questions.form', [
            'question' => $question,
            'sections' => $this->sectionsByScope(),
            'types' => QuestionType::cases(),
            'optionRows' => $this->optionRows($question),
            'locked' => $this->status->answersLocked(),
            'returnTo' => $this->searchReturn(request()),
        ]);
    }

    /**
     * Save a question and reconcile its options — only its texts while
     * locked — bringing stored answers in line with the edit.
     */
    public function updateQuestion(Request $request, Question $question)
    {
        $locked = $this->status->answersLocked();
        $data = $locked ? $request->validate($this->textRules()) : $this->validateQuestion($request);

        $changes = $this->sync->run(
            $question,
            fn () => $locked ? $this->updateTexts($question, $data) : $this->updateStructure($request, $question, $data),
            $request->boolean('preview'),
        );

        if ($request->boolean('preview')) {
            return response()->json(['changes' => $changes]);
        }

        $return = $this->searchReturn($request);

        return $return ? redirect($return) : $this->toQuestion($question);
    }

    /**
     * Save every field of a question and reconcile its options.
     *
     * @param  array<string, mixed>  $data
     */
    private function updateStructure(Request $request, Question $question, array $data): void
    {
        $section = Section::findOrFail($data['section_id']);

        $question->update([
            'section_id' => $section->id,
            // A protected question's key/type are referenced by fixed string
            // literal elsewhere (RegistrationController, SystemQuestionsSeeder)
            // and must stay stable — the form doesn't offer them for editing,
            // but a submitted change is ignored here too, as a backstop.
            'key' => $question->is_system ? $question->key : $this->uniqueQuestionKey($section->scope, ($data['key'] ?? '') ?: $data['label'], $question),
            'type' => $question->is_system ? $question->type : $data['type'],
            'label' => $data['label'],
            'help_text' => $data['help_text'] ?? null,
            'placeholder' => $data['placeholder'] ?? null,
            'translate_value' => $request->boolean('translate_value'),
            'required' => $request->boolean('required'),
        ]);

        // A protected question's options (its fixed Yes/No pair) aren't
        // editable — the form doesn't render an editor for them either.
        if (! $question->is_system) {
            $this->syncOptions($question, $data['options'] ?? []);
        }
    }

    /**
     * Save only a question's texts and its existing options' texts; a row
     * with a blank value, or no matching option, is ignored.
     *
     * @param  array<string, mixed>  $data
     */
    private function updateTexts(Question $question, array $data): void
    {
        $question->update([
            'label' => $data['label'],
            'help_text' => $data['help_text'] ?? null,
            'placeholder' => $data['placeholder'] ?? null,
        ]);

        if ($question->is_system) {
            return;
        }

        $options = $question->options()->get()->keyBy('id');

        foreach ($data['options'] ?? [] as $row) {
            $option = $options->get((int) ($row['id'] ?? 0));
            $value = trim((string) ($row['value'] ?? ''));
            if ($option === null || $value === '') {
                continue;
            }

            $label = trim((string) ($row['label'] ?? ''));
            $description = trim((string) ($row['description'] ?? ''));
            $option->update([
                'value' => $value,
                'label' => $label !== '' ? $label : $value,
                'description' => $description !== '' ? $description : null,
            ]);
        }
    }

    /** Remove a question. */
    public function destroyQuestion(Question $question)
    {
        if ($this->status->answersLocked()) {
            return $this->back('questions_locked');
        }

        abort_if($question->is_system, 403);

        $question->delete();

        return redirect()->route($this->routeName('admin.questions'));
    }

    /**
     * Create one option immediately: the form's script posts a new row's line
     * as soon as it is committed, so the row has an id — and therefore a
     * working Visibility button — before the question itself is saved. The
     * eventual form save reconciles the row by this id ({@see syncOptions()}),
     * updating or deleting it like any other row.
     */
    public function storeOption(Request $request, Question $question)
    {
        abort_if($this->status->answersLocked(), 423);

        $data = $request->validate(['line' => ['required', 'string', 'max:2048']]);

        $attributes = $this->parseOptionLine($data['line']);
        abort_if($attributes === null, 422);

        $option = $question->options()->create($attributes + [
            'position' => (int) $question->options()->max('position') + 1,
        ]);

        return response()->json([
            'id' => $option->id,
            'visibility_url' => route($this->routeName('admin.options.visibility'), $option),
        ]);
    }

    /** @return array<string, Collection<int, Section>> */
    private function sectionsByScope(): array
    {
        // Translations and section rules ride along so the builder's
        // "translated"/"conditional" tags render without an extra query per row.
        $sections = Section::with(['translations', 'conditionGroups', 'questions' => fn ($q) => $q->with('options.translations', 'translations')])
            ->orderBy('position')->get();

        return [
            QuestionScope::Participant->value => $sections->where('scope', QuestionScope::Participant),
            QuestionScope::Group->value => $sections->where('scope', QuestionScope::Group),
            QuestionScope::Guest->value => $sections->where('scope', QuestionScope::Guest),
            QuestionScope::GroupMember->value => $sections->where('scope', QuestionScope::GroupMember),
        ];
    }

    /** Validate a question form submission. */
    private function validateQuestion(Request $request): array
    {
        return $request->validate([
            'section_id' => ['required', 'integer', Rule::exists((new Section)->getTable(), 'id')],
            'key' => ['nullable', 'string', 'max:255', 'regex:/^[a-z0-9_]+$/i'],
            'type' => ['required', Rule::in(QuestionType::values())],
            'label' => ['required', 'string', 'max:4096'],
            'help_text' => ['nullable', 'string', 'max:1000'],
            'placeholder' => ['nullable', 'string', 'max:255'],
            'translate_value' => ['boolean'],
            'required' => ['boolean'],
            'options' => ['nullable', 'array'],
            'options.*.id' => ['nullable', 'integer'],
            'options.*.line' => ['nullable', 'string', 'max:2048'],
        ]);
    }

    /** The rules for a locked question form, which submits only texts. */
    private function textRules(): array
    {
        return [
            'label' => ['required', 'string', 'max:4096'],
            'help_text' => ['nullable', 'string', 'max:1000'],
            'placeholder' => ['nullable', 'string', 'max:255'],
            'options' => ['nullable', 'array'],
            'options.*.id' => ['nullable', 'integer'],
            'options.*.value' => ['nullable', 'string', 'max:255'],
            'options.*.label' => ['nullable', 'string', 'max:255'],
            'options.*.description' => ['nullable', 'string', 'max:2048'],
        ];
    }

    /**
     * Sync a question's options from the form's option rows, each one a
     * "value | label | cost | per-diem days | guests | help text" line (see
     * {@see parseOptionLine()}). Rows arrive in their on-screen (drag & drop)
     * order — PHP preserves the submission order of the row indices — and each
     * row's hidden id reconciles it with its existing option, so a surviving
     * option keeps its row and therefore its stored translations and
     * visibility rule whatever else the admin edits (including its value:
     * several rows may share one value as conditionally-offered variants).
     * Rows without an id (or whose id is not one of this question's options)
     * are created; options absent from the submission — including rows whose
     * line was blanked — are model-deleted so their translations and rules are
     * removed with them.
     *
     * @param  array<int|string, array<string, mixed>>  $rows
     */
    private function syncOptions(Question $question, array $rows): void
    {
        $keep = [];

        if ($question->usesOptions()) {
            $existing = $question->options()->get()->keyBy('id');

            $position = 0;
            foreach ($rows as $row) {
                $attributes = $this->parseOptionLine((string) ($row['line'] ?? ''));
                if ($attributes === null) {
                    continue;
                }

                $option = $existing->get((int) ($row['id'] ?? 0)) ?? $question->options()->make();
                $option->fill($attributes + ['position' => $position++])->save();
                $keep[] = $option->id;
            }
        }

        $question->options()->whereNotIn('id', $keep)->get()->each->delete();
    }

    /**
     * Parse one "value | label | cost | per-diem days | guests | help text"
     * option line into option attributes, or null for a blank line (a blank
     * row is a removal). Everything after the label is optional and
     * positional: an empty cost with per-diem days is written
     * "value | label |  | 2 | guests". The guests field marks the per-diem
     * days as covering an accompanying guest ("guests"/"both") rather than the
     * attendee alone; the help text is shown under the option in the wizard.
     *
     * @return array<string, mixed>|null
     */
    private function parseOptionLine(string $line): ?array
    {
        // The help text is the last field, so a pipe inside it stays put.
        [$value, $label, $cost, $perDiemDays, $scope, $description] =
            array_pad(array_map('trim', explode('|', trim($line), 6)), 6, null);

        if ($value === '') {
            return null;
        }

        return [
            'value' => $value,
            'label' => ($label ?? '') !== '' ? $label : $value,
            'cost' => is_numeric($cost) ? (float) $cost : null,
            'per_diem_days' => is_numeric($perDiemDays) ? max(0, (int) $perDiemDays) : 0,
            'per_diem_scope' => $this->perDiemScope($scope),
            'description' => ($description ?? '') !== '' ? $description : null,
        ];
    }

    /** Map the free-text guests field to an enum: "guests"/"both" cover a guest, anything else the attendee only. */
    private function perDiemScope(?string $scope): PerDiemScope
    {
        return in_array(strtolower((string) $scope), ['guests', 'both', 'attendee_and_guests'], true)
            ? PerDiemScope::AttendeeAndGuests
            : PerDiemScope::Attendee;
    }

    /**
     * The option rows the form renders, as plain arrays of the row's edit line
     * plus the parts its read-only display shows: the failed submission's rows
     * when validation sent the admin back (matched to their option rows by id,
     * for the badge/link state), otherwise the question's saved options.
     *
     * @return array<int, array<string, mixed>>
     */
    private function optionRows(Question $question): array
    {
        $options = $question->options->keyBy('id');
        $locked = $this->status->answersLocked();

        if (($old = old('options')) !== null) {
            return collect($old)->values()->map(function (array $row) use ($options, $locked) {
                $option = $options->get((int) ($row['id'] ?? 0));
                // A locked form posts texts, not lines; its structure is the saved one.
                $line = $locked && $option !== null ? $this->optionToText($option) : (string) ($row['line'] ?? '');

                return $this->optionRow(
                    $option?->id,
                    $line,
                    $this->parseOptionLine($line),
                    $option !== null && $option->conditionGroups->isNotEmpty(),
                );
            })->all();
        }

        return $question->options->map(fn (QuestionOption $option): array => $this->optionRow(
            $option->id,
            $this->optionToText($option),
            [
                'value' => $option->value,
                'label' => $option->label,
                'cost' => $option->cost !== null ? (float) $option->cost : null,
                'per_diem_days' => $option->per_diem_days,
                'per_diem_scope' => $option->per_diem_scope,
                'description' => $option->description,
            ],
            $option->conditionGroups->isNotEmpty(),
        ))->values()->all();
    }

    /**
     * One row array for the form: $parts is null for a blank line (the row
     * renders straight in edit mode).
     *
     * @param  array<string, mixed>|null  $parts
     * @return array<string, mixed>
     */
    private function optionRow(?int $id, string $line, ?array $parts, bool $conditional): array
    {
        return [
            'id' => $id,
            'line' => $line,
            'value' => $parts['value'] ?? '',
            'label' => $parts['label'] ?? '',
            'cost' => $parts !== null && $parts['cost'] !== null ? number_format((float) $parts['cost'], 2, '.', '') : null,
            'per_diem_days' => (int) ($parts['per_diem_days'] ?? 0),
            'per_diem_guests' => ($parts['per_diem_scope'] ?? PerDiemScope::Attendee)->includesGuests(),
            'description' => (string) ($parts['description'] ?? ''),
            'conditional' => $conditional,
        ];
    }

    /** Serialize one option back to its "value | label | cost | days | guests | help" line, dropping trailing optional fields. */
    private function optionToText(QuestionOption $option): string
    {
        $hasPerDiem = $option->per_diem_days > 0;

        $parts = [
            $option->value,
            $option->label,
            $option->cost !== null ? (string) $option->cost : '',
            $hasPerDiem ? (string) $option->per_diem_days : '',
            $hasPerDiem ? ($option->per_diem_scope->includesGuests() ? 'guests' : 'attendee') : '',
            (string) $option->description,
        ];

        while (count($parts) > 2 && end($parts) === '') {
            array_pop($parts);
        }

        return implode(' | ', $parts);
    }

    /** The position for a new section at the end of its scope. */
    private function nextSectionPosition(string $scope): int
    {
        return (int) Section::where('scope', $scope)->max('position') + 1;
    }

    /** The position for a new question at the end of its section. */
    private function nextQuestionPosition(Section $section): int
    {
        return (int) $section->questions()->max('position') + 1;
    }

    /** A slug key for a section, made unique within its scope. */
    private function uniqueSectionKey(string $scope, string $title): string
    {
        return $this->makeUnique(Str::slug($title) ?: 'section', fn (string $candidate) => Section::where('scope', $scope)->where('key', $candidate)->exists());
    }

    /** A slug key for a question, made unique within its scope. */
    private function uniqueQuestionKey(QuestionScope|string $scope, string $base, ?Question $ignore = null): string
    {
        $scope = $scope instanceof QuestionScope ? $scope->value : $scope;
        $base = Str::slug($base, '_') ?: 'question';

        return $this->makeUnique($base, function (string $candidate) use ($scope, $ignore) {
            return Question::where('key', $candidate)
                ->when($ignore, fn ($q) => $q->whereKeyNot($ignore->getKey()))
                ->whereHas('section', fn ($s) => $s->where('scope', $scope))
                ->exists();
        });
    }

    /** Append -2, -3… until $taken() reports the candidate free. */
    private function makeUnique(string $base, callable $taken): string
    {
        $candidate = $base;
        $n = 1;
        while ($taken($candidate)) {
            $candidate = $base.'_'.(++$n);
        }

        return $candidate;
    }

    /** Redirect back to the builder with a status message. */
    private function back(?string $error = null)
    {
        $redirect = redirect()->route($this->routeName('admin.questions'));

        return $error ? $redirect->with('questions_error', __('registration::admin.'.$error)) : $redirect;
    }

    /**
     * Redirect to the questions list anchored at this question's own row — the
     * form's Save lands roughly back where the admin was, without any scripted
     * scrolling.
     */
    private function toQuestion(Question $question)
    {
        return redirect(route($this->routeName('admin.questions')).'#question-'.$question->id);
    }
}
