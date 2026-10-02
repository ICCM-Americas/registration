<?php

namespace ConferenceTools\Registration\Support\Search;

use Illuminate\Support\HtmlString;

/** The escaped excerpt of a matched text with its matches in bold, plus how many matches fell outside it. */
final class Snippet
{
    public function __construct(
        public readonly HtmlString $html,
        public readonly int $more = 0,
    ) {}
}
