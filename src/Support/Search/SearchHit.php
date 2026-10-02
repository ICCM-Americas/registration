<?php

namespace ConferenceTools\Registration\Support\Search;

/** One admin search result: what matched, where it lives, and where clicking it goes. */
final class SearchHit
{
    /**
     * @param  list<string>  $badges  extra status tags (e.g. "In progress")
     * @param  list<array{label: string, url: string, modal: bool}>  $links  secondary links
     */
    public function __construct(
        public readonly string $title,
        public readonly string $field,
        public readonly Snippet $snippet,
        public readonly string $url,
        public readonly bool $modal = false,
        public readonly ?string $key = null,
        public readonly ?string $context = null,
        public readonly ?string $locale = null,
        public readonly array $badges = [],
        public readonly array $links = [],
        public readonly ?SearchTarget $target = null,
    ) {}

    /** A page link carrying the search results to return to (ahead of any #fragment); a modal link is left alone. */
    public static function returning(string $url, bool $modal, string $return): string
    {
        if ($modal) {
            return $url;
        }

        [$path, $fragment] = array_pad(explode('#', $url, 2), 2, null);
        $path .= (str_contains($path, '?') ? '&' : '?').http_build_query(['_return' => $return]);

        return $fragment === null ? $path : $path.'#'.$fragment;
    }
}
