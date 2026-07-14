<?php

namespace ConferenceTools\Registration\Database\Factories;

use ConferenceTools\Registration\Enums\ReportColumnDisplay;
use ConferenceTools\Registration\Enums\ReportField;
use ConferenceTools\Registration\Models\Question;
use ConferenceTools\Registration\Models\Report;
use ConferenceTools\Registration\Models\ReportColumn;
use Illuminate\Database\Eloquent\Factories\Factory;

/** Model factory for ReportColumn rows (a question column by default). */
class ReportColumnFactory extends Factory
{
    protected $model = ReportColumn::class;

    public function definition(): array
    {
        return [
            'report_id' => Report::factory(),
            'question_id' => Question::factory(),
            'field' => null,
            'display' => ReportColumnDisplay::Value,
            'mapping' => null,
            'header' => null,
            'position' => 0,
        ];
    }

    public function builtin(ReportField $field): static
    {
        return $this->state(['question_id' => null, 'field' => $field]);
    }

    public function labeled(): static
    {
        return $this->state(['display' => ReportColumnDisplay::Label]);
    }

    /**
     * A mapped column from a simple {value: text} shorthand — each entry
     * applies to every row (see {@see mappedEntries()} for guest-filtered or
     * blank-value entries).
     *
     * @param  array<string, string>  $mapping
     */
    public function mapped(array $mapping = []): static
    {
        $entries = array_map(fn ($value, $text): array => ['value' => $value, 'guest' => 'any', 'text' => $text], array_keys($mapping), $mapping);

        return $this->mappedEntries($entries);
    }

    /**
     * A mapped column from raw mapping entries, each a {value, guest, text}
     * array — for tests exercising the guest filter or a blank (any-value)
     * entry directly.
     *
     * @param  array<int, array{value: ?string, guest: string, text: string}>  $entries
     */
    public function mappedEntries(array $entries): static
    {
        return $this->state(['display' => ReportColumnDisplay::Mapped, 'mapping' => $entries]);
    }

    /** A blank custom column: neither a question nor a built-in field. */
    public function blank(): static
    {
        return $this->state(['question_id' => null, 'field' => null]);
    }
}
