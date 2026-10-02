<?php

namespace ConferenceTools\Registration\Services;

use ConferenceTools\Registration\Enums\ConditionOperator;
use ConferenceTools\Registration\Enums\QuestionScope;
use ConferenceTools\Registration\Models\Answer;
use ConferenceTools\Registration\Models\Condition;
use ConferenceTools\Registration\Models\Draft;
use ConferenceTools\Registration\Models\Question;
use ConferenceTools\Registration\Models\QuestionOption;
use ConferenceTools\Registration\Models\ReportColumn;
use ConferenceTools\Registration\Models\Translation;
use ConferenceTools\Registration\Support\AnswerBag;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Keeps already-collected data in step with an admin's edit to a question's
 * texts — its label, help text, options, or their translations.
 *
 * A stored choice answer is text rendered from an option at write time (see
 * {@see AnswerStore}), possibly translated and interpolated, so it may embed
 * the option's value, another question's label ({q:key}), or another
 * question's chosen option label ({q:key.value}). Every such answer is traced
 * back to the option and locale that render it before the edit, then rendered
 * again from the same option and locale after it. An answer no option renders
 * (a free-text answer, or one whose source has since changed) is left as is.
 *
 * A renamed option value is also renamed wherever it is held as a raw value:
 * in-progress drafts, visibility and report rules, and report column mappings
 * — unless another option of the question still carries the old value. An
 * edit giving one option a value another option gives up in the same save (a
 * swap) is refused outright.
 */
class AnswerTextSync
{
    /** The draft column each scope's answers are held in. */
    private const DRAFT_COLUMNS = [
        'participant' => 'answers',
        'guest' => 'guests',
        'group_member' => 'group_members',
    ];

    public function __construct(
        private VariableInterpolator $interpolator,
        private VisibilityEvaluator $visibility,
    ) {}

    /**
     * Run $edit with its sync in one transaction, returning how many stored
     * answers and drafts it changes; a preview reports that count and rolls
     * everything back.
     */
    public function run(Question $question, callable $edit, bool $preview = false): int
    {
        DB::beginTransaction();

        try {
            $changed = $this->apply($question, $edit);
        } catch (Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        $preview ? DB::rollBack() : DB::commit();

        return $changed;
    }

    /** Run $edit and bring everything derived from the question's texts in line with it. */
    private function apply(Question $question, callable $edit): int
    {
        $questions = $this->affectedQuestions($question);
        $locales = $this->locales();
        $sources = $questions->map(fn (Question $affected): array => $this->sources($affected, $locales));
        $oldValues = $question->options()->pluck('value', 'id')->all();

        $edit();
        $this->refuseSwaps($question->options()->pluck('value', 'id')->all(), $oldValues);

        $changed = 0;
        foreach ($questions as $index => $affected) {
            $changed += $this->rerender($affected->fresh(['options.translations']), $sources[$index]);
        }

        return $changed + $this->renameValues($question->fresh(['section', 'options']), $oldValues);
    }

    /**
     * Refuse an edit that renames an option to a value another option gave up
     * in the same save — a swap, or any chain of renames.
     *
     * @param  array<int, string>  $current  option id => value after the edit
     * @param  array<int, string>  $oldValues  option id => value before the edit
     */
    private function refuseSwaps(array $current, array $oldValues): void
    {
        $renamed = array_filter(
            $oldValues,
            fn (string $old, int $id): bool => isset($current[$id]) && $current[$id] !== $old,
            ARRAY_FILTER_USE_BOTH,
        );

        if (array_intersect(array_intersect_key($current, $renamed), $renamed) !== []) {
            throw ValidationException::withMessages(['options' => __('registration::admin.options_value_swap')]);
        }
    }

    /**
     * The edited question first, then every question with an option whose
     * value (or a translation of it) references the edited one's label or
     * answer, since those render their stored answers from it.
     *
     * @return Collection<int, Question>
     */
    private function affectedQuestions(Question $question): Collection
    {
        $pattern = '/q:'.preg_quote($question->key, '/').'(?![A-Za-z0-9_])/i';

        $dependents = Question::with('options.translations')
            ->whereKeyNot($question->id)
            ->whereHas('options')
            ->get()
            ->filter(fn (Question $other): bool => $other->options->contains(
                fn (QuestionOption $option): bool => $this->references($option, $pattern),
            ));

        return collect([$question->load('options.translations')])->concat($dependents)->values();
    }

    /** Whether an option's value, in any language, matches the reference pattern. */
    private function references(QuestionOption $option, string $pattern): bool
    {
        return collect([$option->value])
            ->concat($option->translations->where('field', 'value')->pluck('value'))
            ->contains(fn (?string $text): bool => preg_match($pattern, (string) $text) === 1);
    }

    /**
     * Every locale a stored answer may have been rendered in.
     *
     * @return array<int, string>
     */
    private function locales(): array
    {
        return collect([app()->getLocale(), config('app.fallback_locale')])
            ->concat(Translation::query()->distinct()->pluck('locale'))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * The option and locale each of the question's stored answers was
     * rendered from.
     *
     * @param  array<int, string>  $locales
     * @return array<int, array{0: int, 1: string}> answer id => [option id, locale]
     */
    private function sources(Question $question, array $locales): array
    {
        if (! $question->usesOptions()) {
            return [];
        }

        $sources = [];
        foreach ($this->answersByOwner($question) as $answers) {
            $context = $this->context($answers->first());

            foreach ($answers as $answer) {
                $source = $this->source($question, (string) $answer->value, $context, $locales);
                if ($source !== null) {
                    $sources[$answer->id] = $source;
                }
            }
        }

        return $sources;
    }

    /**
     * The option and locale rendering exactly $stored. Variants sharing a
     * rendering resolve as {@see VisibilityEvaluator::optionFor()} does: the
     * first one offered to this owner, else the first at all.
     *
     * @param  array<string, mixed>  $context
     * @param  array<int, string>  $locales
     * @return array{0: int, 1: string}|null
     */
    private function source(Question $question, string $stored, array $context, array $locales): ?array
    {
        $fallback = null;

        foreach ($question->options as $option) {
            $locale = $this->renderingLocale($question, $option, $stored, $context, $locales);
            if ($locale === null) {
                continue;
            }

            if ($this->visibility->isVisible($option, $context)) {
                return [$option->id, $locale];
            }

            $fallback ??= [$option->id, $locale];
        }

        return $fallback;
    }

    /**
     * The first locale the option renders $stored in, if any.
     *
     * @param  array<string, mixed>  $context
     * @param  array<int, string>  $locales
     */
    private function renderingLocale(Question $question, QuestionOption $option, string $stored, array $context, array $locales): ?string
    {
        foreach ($locales as $locale) {
            if ($this->render($question, $option, $locale, $context) === $stored) {
                return $locale;
            }
        }

        return null;
    }

    /**
     * Render each traced answer again from its source option, returning how
     * many changed.
     *
     * @param  array<int, array{0: int, 1: string}>  $sources
     */
    private function rerender(Question $question, array $sources): int
    {
        $options = $question->options->keyBy('id');
        $changed = 0;

        foreach ($this->answersByOwner($question) as $answers) {
            $context = $this->context($answers->first());

            foreach ($answers as $answer) {
                [$optionId, $locale] = $sources[$answer->id] ?? [null, null];
                $option = $options->get($optionId);
                if ($option === null) {
                    continue;
                }

                $text = $this->render($question, $option, $locale, $context);
                if ($text !== $answer->value) {
                    $answer->update(['value' => $text]);
                    $changed++;
                }
            }
        }

        return $changed;
    }

    /**
     * The text {@see AnswerStore} records for choosing the option, rendered in
     * the locale against the owner's answers.
     *
     * @param  array<string, mixed>  $context
     */
    private function render(Question $question, QuestionOption $option, string $locale, array $context): string
    {
        $text = $question->translate_value ? (string) $option->translate('value', $locale) : (string) $option->value;

        if (! str_contains($text, '{')) {
            return $text;
        }

        $previous = app()->getLocale();
        app()->setLocale($locale);
        $this->interpolator->flush();
        $this->interpolator->setAnswers($context);

        try {
            return (string) $this->interpolator->interpolate($text);
        } finally {
            $this->interpolator->setAnswers(null);
            app()->setLocale($previous);
            $this->interpolator->flush();
        }
    }

    /** @return Collection<string, Collection<int, Answer>> the question's stored answers, grouped by owner */
    private function answersByOwner(Question $question): Collection
    {
        return $question->answers()->orderBy('id')->get()
            ->groupBy(fn (Answer $answer): string => $answer->owner_type.'|'.$answer->owner_id);
    }

    /** @return array<string, mixed> the answer owner's current answers, keyed by question key */
    private function context(Answer $answer): array
    {
        return AnswerBag::fromAnswers(
            Answer::with('question')
                ->where('owner_type', $answer->owner_type)
                ->where('owner_id', $answer->owner_id)
                ->get(),
        )->values();
    }

    /**
     * Rename each changed option value wherever it is held as a raw value,
     * returning how many drafts changed.
     *
     * @param  array<int, string>  $oldValues  option id => value before the edit
     */
    private function renameValues(Question $question, array $oldValues): int
    {
        $current = $question->options->pluck('value', 'id')->all();
        $changed = 0;

        foreach ($oldValues as $id => $old) {
            $new = $current[$id] ?? null;
            if ($new === null || $new === $old || in_array($old, $current, true)) {
                continue;
            }

            $changed += $this->renameInDrafts($question, $old, $new);
            $this->renameInConditions($question, $old, $new);
            $this->renameInMappings($question, $old, $new);
        }

        return $changed;
    }

    /** Rename the value in in-progress drafts' answers to the question, returning how many changed. */
    private function renameInDrafts(Question $question, string $old, string $new): int
    {
        $column = self::DRAFT_COLUMNS[$question->section->scope->value] ?? null;
        if ($column === null) {
            return 0;
        }

        $rename = fn (array $answers): array => $this->renameAnswer($answers, $question->key, $old, $new);
        $changed = 0;

        foreach (Draft::all() as $draft) {
            $before = $draft->{$column} ?? [];
            $after = $question->section->scope === QuestionScope::Participant
                ? $rename($before)
                : array_map(fn (array $entry): array => array_replace($entry, ['answers' => $rename($entry['answers'] ?? [])]), $before);

            if ($after !== $before) {
                $draft->{$column} = $after;
                $draft->save();
                $changed++;
            }
        }

        return $changed;
    }

    /**
     * @param  array<string, mixed>  $answers
     * @return array<string, mixed> the answers with the key's old value (or values) renamed
     */
    private function renameAnswer(array $answers, string $key, string $old, string $new): array
    {
        if (! array_key_exists($key, $answers)) {
            return $answers;
        }

        $swap = fn (mixed $value): mixed => is_scalar($value) && (string) $value === $old ? $new : $value;
        $answers[$key] = is_array($answers[$key]) ? array_map($swap, $answers[$key]) : $swap($answers[$key]);

        return $answers;
    }

    /** Rename the value in the exact-match conditions that test the question's answer. */
    private function renameInConditions(Question $question, string $old, string $new): void
    {
        Condition::where('question_id', $question->id)->get()->each(function (Condition $condition) use ($old, $new): void {
            $condition->value = match ($condition->operator) {
                ConditionOperator::Equals, ConditionOperator::NotEquals => $condition->value === $old ? $new : $condition->value,
                ConditionOperator::In, ConditionOperator::NotIn => $this->renameInList($condition->value, $old, $new),
                default => $condition->value,
            };
            $condition->save();
        });
    }

    /** The comma-separated list with the old value renamed, or unchanged when absent. */
    private function renameInList(?string $list, string $old, string $new): ?string
    {
        $items = array_map('trim', explode(',', (string) $list));

        return in_array($old, $items, true)
            ? implode(', ', array_map(fn (string $item): string => $item === $old ? $new : $item, $items))
            : $list;
    }

    /** Rename the value in the mapping entries of report columns reading the question. */
    private function renameInMappings(Question $question, string $old, string $new): void
    {
        ReportColumn::whereNotNull('mapping')
            ->where(fn ($query) => $query->where('question_id', $question->id)->orWhere('guest_question_id', $question->id))
            ->get()
            ->each(function (ReportColumn $column) use ($old, $new): void {
                $column->mapping = array_map(
                    fn (array $entry): array => ($entry['value'] ?? null) === $old ? array_replace($entry, ['value' => $new]) : $entry,
                    $column->mapping,
                );
                $column->save();
            });
    }
}
