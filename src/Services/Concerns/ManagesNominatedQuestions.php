<?php

namespace ConferenceTools\Registration\Services\Concerns;

use ConferenceTools\Registration\Models\Question;
use ConferenceTools\Registration\Models\Setting;
use ConferenceTools\Registration\Services\GuestQuestions;
use ConferenceTools\Registration\Services\ReportQuestions;

/**
 * The settings machinery shared by the question-nomination services
 * ({@see ReportQuestions},
 * {@see GuestQuestions}): reading
 * nominated question keys, reading/normalizing value-list settings, spotting
 * stale value lists, and persisting the console's submitted settings.
 *
 * The using class defines the setting inventory as constants: QUESTION_KEYS
 * (every question-nominating setting), VALUE_DEFAULTS (each value-list
 * setting's default), and VALUE_QUESTION_KEYS (which question setting each
 * value-list setting qualifies).
 */
trait ManagesNominatedQuestions
{
    /** The nominated question key for a setting, or null while unconfigured. */
    public function questionKey(string $setting): ?string
    {
        $key = Setting::get($setting);

        return filled($key) ? $key : null;
    }

    /** A value-list setting as normalized tokens (lowercased, trimmed). */
    public function valueList(string $setting): array
    {
        $raw = Setting::get($setting) ?? self::VALUE_DEFAULTS[$setting];

        return collect(explode(',', $raw))
            ->map(fn (string $value): string => mb_strtolower(trim($value)))
            ->filter(fn (string $value): bool => $value !== '')
            ->values()
            ->all();
    }

    /** A value-list setting's configured values, case preserved — for editing. */
    public function rawValueList(string $setting): array
    {
        $raw = Setting::get($setting) ?? self::VALUE_DEFAULTS[$setting];

        return collect(explode(',', $raw))
            ->map(fn (string $value): string => trim($value))
            ->filter(fn (string $value): bool => $value !== '')
            ->values()
            ->all();
    }

    /**
     * The configured values of a value-list setting that no longer match any
     * current option of its nominated question — empty while the question is
     * unset or isn't options-based (free text can't go stale this way).
     */
    public function staleValues(string $setting): array
    {
        $question = $this->nominatedQuestion(self::VALUE_QUESTION_KEYS[$setting]);
        if ($question === null || ! $question->usesOptions()) {
            return [];
        }

        $optionValues = $question->options->map(fn ($option): string => mb_strtolower($option->value))->all();

        return collect($this->rawValueList($setting))
            ->reject(fn (string $value): bool => in_array(mb_strtolower($value), $optionValues, true))
            ->values()
            ->all();
    }

    /** Whether any value-list setting's configured answer(s) have gone stale. */
    public function hasStaleMatches(): bool
    {
        foreach (array_keys(self::VALUE_QUESTION_KEYS) as $setting) {
            if ($this->staleValues($setting) !== []) {
                return true;
            }
        }

        return false;
    }

    /** The question nominated for a setting, with its options, or null while unset/unmatched. */
    private function nominatedQuestion(string $setting): ?Question
    {
        $key = $this->questionKey($setting);

        return $key !== null ? Question::with('options')->where('key', $key)->first() : null;
    }

    /**
     * Persist the console's submitted settings. An empty question-nominating
     * entry clears it (see {@see Setting::put()}). An empty value-list entry
     * must instead be stored as an explicit empty string — storing null would
     * delete the row and make {@see valueList()}/{@see rawValueList()} fall
     * back to {@see VALUE_DEFAULTS}, silently undoing the admin's clearing of
     * it.
     */
    public function update(array $values): void
    {
        foreach (self::QUESTION_KEYS as $setting) {
            if (array_key_exists($setting, $values)) {
                Setting::put($setting, filled($values[$setting]) ? $values[$setting] : null);
            }
        }

        foreach (array_keys(self::VALUE_DEFAULTS) as $setting) {
            if (array_key_exists($setting, $values)) {
                Setting::put($setting, (string) ($values[$setting] ?? ''));
            }
        }
    }
}
