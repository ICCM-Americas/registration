<?php

namespace ConferenceTools\Registration\Support\Search;

use Illuminate\Support\HtmlString;

/** The admin search term compiled to a PCRE pattern, matching texts and rendering their bolded snippets. */
final class SearchPattern
{
    /** Bytes of context kept on each side of the first match in a long text's snippet. */
    public const CONTEXT = 60;

    private function __construct(private readonly string $pattern) {}

    /** Compile a term, literal or regex, case-sensitive or not. */
    public static function compile(string $term, bool $regex, bool $caseSensitive): self
    {
        // \x01 as the delimiter: no form-typed term contains it, so a regex
        // term is used verbatim without escaping its own delimiters.
        $body = $regex ? $term : preg_quote($term);
        $pattern = "\x01".$body."\x01u".($caseSensitive ? '' : 'i');

        // A compile failure is reported only as a warning, so catch its text.
        $warning = '';
        set_error_handler(function (int $level, string $message) use (&$warning): bool {
            $warning = $message;

            return true;
        });

        try {
            $compiled = preg_match($pattern, '') !== false;
        } finally {
            restore_error_handler();
        }

        if (! $compiled) {
            throw new InvalidSearchPattern(preg_replace('/^preg_match\(\): (Compilation failed: )?/', '', $warning));
        }

        return new self($pattern);
    }

    /**
     * The non-empty matches in a text, as byte offset/length pairs.
     *
     * @return list<array{0: int, 1: int}>
     */
    public function matches(?string $text): array
    {
        if ($text === null || $text === '') {
            return [];
        }

        if (@preg_match_all($this->pattern, $text, $found, PREG_OFFSET_CAPTURE) === false) {
            throw new InvalidSearchPattern(preg_last_error_msg());
        }

        return array_values(array_map(
            fn (array $match): array => [$match[1], strlen($match[0])],
            array_filter($found[0], fn (array $match): bool => $match[0] !== ''),
        ));
    }

    /** The text's snippet with every match in bold, or null when nothing matches; a long text is trimmed around its first match. */
    public function snippet(?string $text): ?Snippet
    {
        $matches = $this->matches($text);
        if ($matches === []) {
            return null;
        }

        [$start, $end] = $this->window($text, $matches);
        $inside = array_filter($matches, fn (array $m): bool => $m[0] >= $start && $end >= $m[0] + $m[1]);

        return new Snippet(
            new HtmlString($this->render($text, $start, $end, $inside)),
            count($matches) - count($inside),
        );
    }

    /**
     * The byte range a snippet keeps: the first match plus
     * CONTEXT bytes either side, widened to cover a match it would split and
     * aligned to whole UTF-8 characters.
     *
     * @param  list<array{0: int, 1: int}>  $matches
     * @return array{0: int, 1: int}
     */
    private function window(string $text, array $matches): array
    {
        [$offset, $length] = $matches[0];
        $start = strlen(mb_strcut($text, 0, max(0, $offset - self::CONTEXT)));
        $end = min(strlen($text), $offset + $length + self::CONTEXT);

        foreach ($matches as [$at, $size]) {
            if ($at < $end && $at + $size > $end) {
                $end = $at + $size;
            }
        }

        return [$start, strlen(mb_strcut($text, 0, $end))];
    }

    /**
     * Escape the text's [start, end) range with the given matches wrapped in
     * <strong>, and an ellipsis on each cut end.
     *
     * @param  array<int, array{0: int, 1: int}>  $matches
     */
    private function render(string $text, int $start, int $end, array $matches): string
    {
        $html = $start > 0 ? '…' : '';
        $cursor = $start;

        foreach ($matches as [$at, $size]) {
            $html .= e(substr($text, $cursor, $at - $cursor)).'<strong>'.e(substr($text, $at, $size)).'</strong>';
            $cursor = $at + $size;
        }

        return $html.e(substr($text, $cursor, $end - $cursor)).($end < strlen($text) ? '…' : '');
    }
}
