<?php

namespace ConferenceTools\Registration\Support\Search;

/** What the admin search looks for and where: the term, its regex/case flags, and the checked places. */
final class SearchOptions
{
    public const QUESTION_TEXT = 'question_text';

    public const OPTIONS = 'options';

    public const QUESTION_RULES = 'question_rules';

    public const REPORT_TEXT = 'report_text';

    public const REPORT_COLUMNS = 'report_columns';

    public const REPORT_RULES = 'report_rules';

    public const ANSWERS = 'answers';

    public const DRAFTS = 'drafts';

    /** The places each result category covers, in display order. */
    public const CATEGORIES = [
        'questions' => [self::QUESTION_TEXT, self::OPTIONS, self::QUESTION_RULES],
        'reports' => [self::REPORT_TEXT, self::REPORT_COLUMNS, self::REPORT_RULES],
        'answers' => [self::ANSWERS, self::DRAFTS],
    ];

    /** @param  list<string>  $places */
    public function __construct(
        public readonly string $term = '',
        public readonly bool $regex = false,
        public readonly bool $caseSensitive = false,
        public readonly bool $translations = false,
        public readonly array $places = [],
    ) {}

    /** Every searchable place, for validation. */
    public static function places(): array
    {
        return array_merge(...array_values(self::CATEGORIES));
    }

    /** Build from the search form's input, ignoring malformed values so a rejected form can still be redisplayed. */
    public static function fromInput(array $input): self
    {
        return new self(
            is_string($input['q'] ?? null) ? $input['q'] : '',
            (bool) ($input['regex'] ?? false),
            (bool) ($input['case'] ?? false),
            (bool) ($input['translations'] ?? false),
            array_values(array_intersect(self::places(), array_filter((array) ($input['in'] ?? []), 'is_string'))),
        );
    }

    /** The term compiled with these flags. */
    public function pattern(): SearchPattern
    {
        return SearchPattern::compile($this->term, $this->regex, $this->caseSensitive);
    }

    /** Whether a place is checked. */
    public function has(string $place): bool
    {
        return in_array($place, $this->places, true);
    }

    /** The form input these options came from, for links that repeat the search. */
    public function toQuery(): array
    {
        return array_filter([
            'q' => $this->term,
            'regex' => $this->regex ? 1 : null,
            'case' => $this->caseSensitive ? 1 : null,
            'translations' => $this->translations ? 1 : null,
            'in' => $this->places,
        ], fn ($value): bool => $value !== null && $value !== []);
    }
}
