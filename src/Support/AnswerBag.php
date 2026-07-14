<?php

namespace ConferenceTools\Registration\Support;

use ConferenceTools\Registration\Enums\PerDiemScope;
use ConferenceTools\Registration\Enums\QuestionType;
use ConferenceTools\Registration\Models\Answer;
use ConferenceTools\Registration\Models\DiscountCode;
use ConferenceTools\Registration\Services\AnswerStore;
use ConferenceTools\Registration\Services\PerDiem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * A read model over an owner's EAV answers: a registrant (host user) or a Group.
 * Reads everything from the configurable answer store rather than the legacy
 * typed columns, so admin screens, summaries and totals can be driven by the
 * configured questions. This is the read side that lets the legacy typed-column
 * bridge eventually be retired.
 *
 * Every value here is read straight off the stored Answer rows: a choice
 * answer's text, cost and per-diem contribution were already resolved and
 * snapshotted at write time by {@see AnswerStore},
 * so nothing here interpolates, translates, or joins back to the question's
 * options.
 */
class AnswerBag
{
    /** @param Collection<int, Answer> $answers each with question loaded */
    private function __construct(private Collection $answers) {}

    /** Load an owner's committed answers into a bag. */
    public static function forOwner(Model $owner): self
    {
        $answers = Answer::with(['question.section'])
            ->where('owner_type', $owner->getMorphClass())
            ->where('owner_id', $owner->getKey())
            ->get();

        return new self($answers);
    }

    /**
     * Build a bag from already-hydrated answers (each with its `question`
     * relation set) rather than querying the `answers` table — used to read
     * a past owner's answers from data with no live Answer row to query,
     * e.g. a decoded ConferenceArchive snapshot.
     *
     * @param  Collection<int, Answer>  $answers
     */
    public static function fromAnswers(Collection $answers): self
    {
        return new self($answers);
    }

    /** Whether the owner answered the question key at all. */
    public function has(string $key): bool
    {
        return $this->forKey($key)->isNotEmpty();
    }

    /**
     * The stored value(s) for a question: a scalar for a single answer, an array
     * for a multi-value question, or null when unanswered.
     */
    public function value(string $key): mixed
    {
        $values = $this->forKey($key)->map(fn (Answer $a) => $a->value);

        return match ($values->count()) {
            0 => null,
            1 => $values->first(),
            default => $values->all(),
        };
    }

    /**
     * A human-readable value: the stored text (joined for multi-value), which
     * is already report-ready — resolved and interpolated at write time. Null
     * when unanswered.
     */
    public function display(string $key): ?string
    {
        $answers = $this->forKey($key);
        if ($answers->isEmpty()) {
            return null;
        }

        return $answers
            ->map(fn (Answer $a) => $a->value)
            ->filter(fn ($v) => $v !== null && $v !== '')
            ->implode(', ');
    }

    /** Total cost of every chosen priced option (the EAV equivalent of cost()). */
    public function cost(): float
    {
        return (float) $this->answers
            ->map(fn (Answer $a) => $a->cost)
            ->filter(fn ($cost) => $cost !== null)
            ->sum();
    }

    /**
     * Every chosen priced option as a line — its question's label paired with
     * the snapshotted cost — the per-line breakdown behind {@see cost()}'s
     * total.
     *
     * @return array<int, array{label: string, amount: float}>
     */
    public function costLines(): array
    {
        return $this->answers
            ->filter(fn (Answer $a) => $a->cost !== null && $a->question !== null)
            ->map(fn (Answer $a) => ['label' => (string) $a->question->label, 'amount' => (float) $a->cost])
            ->values()
            ->all();
    }

    /**
     * The per-diem day contributions from this owner's chosen options — each a
     * ['label', 'days', 'scope'] entry for every answer carrying per-diem days.
     * {@see PerDiem} prices these against
     * the configured rates (and adds the conference's own days when so
     * configured); an accompanying guest is billed for guest-inclusive entries.
     *
     * @return Collection<int, array{label: string, days: int, scope: PerDiemScope}>
     */
    public function perDiemContributions(): Collection
    {
        return $this->answers
            ->filter(fn (Answer $a) => ($a->per_diem_days ?? 0) > 0)
            ->map(fn (Answer $a) => [
                'label' => $a->value,
                'days' => (int) $a->per_diem_days,
                'scope' => $a->per_diem_scope,
            ])
            ->values();
    }

    /**
     * The discount code this owner entered against a discount-code question, if
     * the entry matches a configured (enabled) code. Null when no discount-code
     * question was answered, or the entry is blank/unrecognized.
     */
    public function discount(): ?DiscountCode
    {
        $entered = $this->answers
            ->first(fn (Answer $a) => optional($a->question)->type === QuestionType::DiscountCode)
            ?->value;

        return DiscountCode::lookup($entered);
    }

    /**
     * Apply this owner's discount code (if any) to an amount. A pass-through when
     * no valid code was entered; never returns less than zero.
     */
    public function applyDiscount(float $amount): float
    {
        return $this->discount()?->apply($amount) ?? round(max(0.0, $amount), 2);
    }

    /**
     * The answered questions as an ordered list of [key, label, value], in form
     * order (section then question position) — ready to render as a summary.
     *
     * @return array<int, array{key: string, label: string, value: ?string}>
     */
    public function fields(): array
    {
        return $this->answers
            ->filter(fn (Answer $a) => $a->question !== null)
            ->groupBy(fn (Answer $a) => $a->question->id)
            ->map(fn (Collection $group) => $group->first()->question)
            ->sortBy(fn ($question) => [optional($question->section)->position ?? 0, $question->position])
            ->map(fn ($question) => [
                'key' => $question->key,
                'label' => $question->label,
                'value' => $this->display($question->key),
            ])
            ->values()
            ->all();
    }

    /**
     * Every answer as a key => value map (arrays for multi-value questions) —
     * the committed equivalent of the wizard draft's answer set, e.g. for the
     * variable interpolator's registrant context.
     *
     * @return array<string, mixed>
     */
    public function values(): array
    {
        return $this->answers
            ->filter(fn (Answer $a) => $a->question !== null)
            ->map(fn (Answer $a) => $a->question->key)
            ->unique()
            ->mapWithKeys(fn (string $key) => [$key => $this->value($key)])
            ->all();
    }

    /** @return Collection<int, Answer> answers for the given question key */
    private function forKey(string $key): Collection
    {
        return $this->answers->filter(fn (Answer $a) => optional($a->question)->key === $key)->values();
    }
}
