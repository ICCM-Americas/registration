<?php

namespace ConferenceTools\Registration\Support;

/**
 * Renders an admin-authored question label as safe HTML. Admins write labels in
 * a small, Markdown-flavored convention so a label can span lines and carry
 * light emphasis without exposing raw HTML:
 *
 *   newlines        become <br />
 *   **bold**        becomes <b>bold</b>
 *   _italics_       becomes <i>italics</i>
 *   **_both_**      nests in the written order: <b><i>both</i></b>
 *   _**both**_      nests in the written order: <i><b>both</b></i>
 *
 * The raw text is HTML-escaped first, so anything the admin writes other than
 * these markers is shown verbatim and no arbitrary markup can slip through. We
 * deliberately do not validate the markers — an unbalanced ** or _ simply
 * renders as itself, and the admin sees (and fixes) the result.
 */
class LabelFormatter
{
    /** The label as safe display HTML, or an empty string for no text. */
    public static function format(?string $text): string
    {
        if ($text === null || $text === '') {
            return '';
        }

        $html = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        // Bold is resolved before italics so a **_…_** / _**…**_ pair nests in
        // the order the markers were written rather than colliding on the run.
        $html = preg_replace('/\*\*(.+?)\*\*/s', '<b>$1</b>', $html);
        $html = preg_replace('/_(.+?)_/s', '<i>$1</i>', $html);

        return nl2br($html);
    }
}
