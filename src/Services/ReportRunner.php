<?php

namespace ConferenceTools\Registration\Services;

use ConferenceTools\Registration\Enums\GuestType;
use ConferenceTools\Registration\Enums\QuestionScope;
use ConferenceTools\Registration\Enums\ReportColumnDisplay;
use ConferenceTools\Registration\Enums\ReportColumnMappingGuest;
use ConferenceTools\Registration\Enums\ReportField;
use ConferenceTools\Registration\Models\Guest;
use ConferenceTools\Registration\Models\Question;
use ConferenceTools\Registration\Models\QuestionOption;
use ConferenceTools\Registration\Models\Report;
use ConferenceTools\Registration\Models\ReportColumn;
use ConferenceTools\Registration\Support\AnswerBag;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Runs an admin-defined report: the registrant rows its rule tree keeps
 * (evaluated against each registrant's committed answers), each followed by
 * the registrant's non-attending guests when the report includes them. Every
 * cell honors its column's own per-row rule — on a guest row, rules see the
 * registrant's answers overlaid with the guest's own, so a Participant-scope
 * condition applies to the whole family while a Guest-scope one can still
 * target the guest itself.
 */
class ReportRunner
{
    public function __construct(
        private Registrants $registrants,
        private VisibilityEvaluator $evaluator,
        private ReportQuestions $questions,
        private GuestQuestions $guestQuestions,
        private VariableInterpolator $interpolator,
    ) {}

    /**
     * The report's column headings, in column order.
     *
     * @return array<int, string>
     */
    public function headers(Report $report): array
    {
        return $this->columns($report)->map(fn (ReportColumn $c): string => $c->heading())->all();
    }

    /**
     * The report's rows, in registrant order — each an array of cell strings
     * (null for a blank cell) matching {@see headers()}.
     *
     * @return Collection<int, array<int, ?string>>
     */
    public function rows(Report $report): Collection
    {
        $columns = $this->columns($report);
        $rows = collect();

        foreach ($this->registrants->all() as $user) {
            $context = $user->registrationAnswers()->values();
            if (! $this->evaluator->passes($report->conditionGroups, $context)) {
                continue;
            }

            $rows->push($this->row($columns, $user, null, $context));
            foreach ($this->includedGuests($report, $user) as $guest) {
                $rows->push($this->row($columns, $user, $guest, array_merge($context, $guest->registrationAnswers()->values())));
            }
        }

        $this->interpolator->setAnswers(null);

        return $rows;
    }

    /** @return Collection<int, ReportColumn> the columns with their questions eager-loaded */
    private function columns(Report $report): Collection
    {
        $report->loadMissing([
            'columns.question.section', 'columns.question.options',
            'columns.guestQuestion.section', 'columns.guestQuestion.options',
        ]);

        return $report->columns;
    }

    /** A registrant's guests the report includes, in their own order. */
    private function includedGuests(Report $report, Model $user): Collection
    {
        return $user->guests
            ->filter(fn (Guest $g): bool => $g->type === GuestType::Adult
                ? $report->include_adult_guests
                : $report->include_minor_guests)
            ->sortBy('position')
            ->values();
    }

    /**
     * One row's cells. $guest is null on the registrant's own row.
     *
     * @param  array<string, mixed>  $context  the row's rule-evaluation answers
     * @return array<int, ?string>
     */
    private function row(Collection $columns, Model $user, ?Guest $guest, array $context): array
    {
        // The row's own answers, for a mapped column's interpolated targets
        // ({@see mappedValue()}) — the same context {@see VisibilityEvaluator}
        // already evaluates rules against.
        $this->interpolator->setAnswers($context);

        return $columns
            ->map(fn (ReportColumn $column): ?string => $this->cell($column, $user, $guest, $context))
            ->all();
    }

    /** One cell: blank when the column's per-row rule fails, resolved otherwise. */
    private function cell(ReportColumn $column, Model $user, ?Guest $guest, array $context): ?string
    {
        if (! $this->evaluator->passes($column->conditionGroups, $context)) {
            return null;
        }

        return match (true) {
            $column->question !== null => $this->questionCell($column, $user, $guest),
            $column->field !== null => $this->builtinCell($column->field, $user, $guest),
            // A custom column with neither: always blank, for the admin to fill in by hand.
            default => null,
        };
    }

    /** A question column's cell, from the owner the driving question's scope points at. */
    private function questionCell(ReportColumn $column, Model $user, ?Guest $guest): ?string
    {
        $question = $this->drivingQuestion($column, $guest);
        $bag = $this->bagFor($question, $user, $guest);

        // Mapped display alone may cross scope: a guest-filtered wildcard
        // entry ({@see entryMatches()}) can supply text regardless of the
        // row's own answer, e.g. to show a registrant's or their guest's name
        // from the same column. Label/Value display still need a real answer.
        if ($column->display === ReportColumnDisplay::Mapped) {
            return $this->mapped($column, $bag?->value($question->key), $guest?->type);
        }

        if ($bag === null) {
            return null;
        }

        $value = $bag->value($question->key);

        return match ($column->display) {
            ReportColumnDisplay::Label => $this->labeled($question, $value),
            ReportColumnDisplay::Value => $bag->display($question->key),
        };
    }

    /**
     * The question this column reads from on this row: its own question, or
     * its guest_question_id override on a guest row when one is nominated —
     * one column, two sources, e.g. a registrant's own consent question
     * against their guest's own equivalent.
     */
    private function drivingQuestion(ReportColumn $column, ?Guest $guest): Question
    {
        return $guest !== null ? ($column->guestQuestion ?? $column->question) : $column->question;
    }

    /**
     * The answer bag a question resolves against on this row: Participant
     * scope always resolves to the registrant's own answers and Group scope
     * to their group's, on both registrant and guest rows — a guest row
     * inherits its registrant's family-wide answers the same way the merged
     * rule-evaluation context in {@see rows()} does, unless the column named
     * a guest_question_id override (see {@see drivingQuestion()}), which is
     * typically Guest scope itself. Guest scope resolves to the row's own
     * guest and stays null on a registrant row, which has no single guest to
     * point at.
     */
    private function bagFor(Question $question, Model $user, ?Guest $guest): ?AnswerBag
    {
        return match ($question->section?->scope) {
            QuestionScope::Participant => $user->registrationAnswers(),
            QuestionScope::Group => $user->group?->registrationAnswers(),
            QuestionScope::Guest => $guest?->registrationAnswers(),
            default => null,
        };
    }

    /**
     * Stored value(s) mapped to their options' labels, joined for multi-value
     * answers. A value no longer matching any option stays as stored.
     */
    private function labeled(Question $question, mixed $value): ?string
    {
        $labels = collect(is_array($value) ? $value : [$value])
            ->filter(fn ($v): bool => $v !== null && $v !== '')
            ->map(fn ($v): string => $this->optionLabel($question, (string) $v));

        return $labels->isEmpty() ? null : $labels->implode(', ');
    }

    /** The label of the option a stored value came from, or the value itself. */
    private function optionLabel(Question $question, string $value): string
    {
        $match = $question->options
            ->first(fn (QuestionOption $o): bool => mb_strtolower($o->value) === mb_strtolower($value));

        return $match?->label ?? $value;
    }

    /**
     * Stored value(s) run through the column's own mapping, joined for
     * multi-value answers. An unanswered or out-of-scope value (null) still
     * runs through the mapping — a blank-value, guest-filtered entry can
     * supply text with nothing stored at all, e.g. to fix a minor guest's
     * cell regardless of a question that only ever means something for
     * adults — and stays blank only when nothing matches.
     */
    private function mapped(ReportColumn $column, mixed $value, ?GuestType $guestType): ?string
    {
        $values = collect(is_array($value) ? $value : [$value])->filter(fn ($v): bool => $v !== null && $v !== '');

        if ($values->isEmpty()) {
            return $this->mappedValue($column, null, $guestType);
        }

        return $values->map(fn ($v): string => $this->mappedValue($column, (string) $v, $guestType))->implode(', ');
    }

    /**
     * The first mapping entry whose guest filter and stored value both match
     * this row (a blank entry value matches any stored value), interpolated
     * against the row's own answers (so an entry can read e.g. "Yes, {name}"
     * or reference any question regardless of this column's own one) — or
     * the value itself when nothing matches, same as an unmatched option
     * under Label display.
     */
    private function mappedValue(ReportColumn $column, ?string $value, ?GuestType $guestType): ?string
    {
        $entry = collect($column->mapping ?? [])->first(fn (array $e): bool => $this->entryMatches($e, $value, $guestType));

        return $entry === null ? $value : (string) $this->interpolator->interpolate($entry['text']);
    }

    /** Whether one mapping entry's guest filter and (possibly blank/wildcard) stored value both match this row. */
    private function entryMatches(array $entry, ?string $value, ?GuestType $guestType): bool
    {
        $guest = ReportColumnMappingGuest::from($entry['guest'] ?? ReportColumnMappingGuest::Any->value);
        if (! $guest->matches($guestType)) {
            return false;
        }

        $entryValue = $entry['value'] ?? null;

        return $entryValue === null || ($value !== null && mb_strtolower($entryValue) === mb_strtolower($value));
    }

    /** A built-in field's cell for this row. */
    private function builtinCell(ReportField $field, Model $user, ?Guest $guest): ?string
    {
        return match ($field) {
            ReportField::Email => $guest === null ? $user->email : null,
            ReportField::EntryType => $this->entryType($guest),
            ReportField::BadgeName => $guest === null
                ? $this->questions->badgeName($user)
                : $this->guestQuestions->badgeName($guest),
            // Always the registrant's: a guest belongs to their registrant's
            // organization (matching the old directory's guest rows).
            ReportField::Organization => $this->questions->organization($user),
        };
    }

    /** The row's translated entry type: attendee, adult guest, or minor guest. */
    private function entryType(?Guest $guest): string
    {
        return match (true) {
            $guest === null => __('registration::admin.report_entry_attendee'),
            $guest->type === GuestType::Adult => __('registration::admin.report_entry_adult_guest'),
            default => __('registration::admin.report_entry_minor_guest'),
        };
    }
}
