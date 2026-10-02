<?php

namespace ConferenceTools\Registration\Support\Search;

use InvalidArgumentException;

/** A search term that doesn't compile as a regular expression, or that PCRE gave up evaluating. */
class InvalidSearchPattern extends InvalidArgumentException {}
