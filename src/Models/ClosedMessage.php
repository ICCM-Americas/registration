<?php

namespace ConferenceTools\Registration\Models;

use ConferenceTools\Registration\Models\Concerns\HasRegistrationTable;
use ConferenceTools\Registration\Models\Concerns\TranslatesFields;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One of the fixed texts shown on the "registration is closed" page, keyed by
 * the window state that selects it (see RegistrationStatus::closedMessageKey()).
 * The set is fixed ({@see KEYS}); admins edit the texts on the "Closed Page"
 * console and translate them per locale ({@see TranslatesFields}). Texts may
 * contain "{name}" variable tokens (see VariableInterpolator), including the
 * built-ins for the scheduled open date/time/timezone, the conference site URL
 * (from branding), and the closed conference's name and year.
 *
 * The defaults are plain English literals — NOT lang-file entries: the
 * messages are admin content, and admins provide other languages through the
 * translations editor, like the landing-page steps (see ClosedMessageSeeder).
 */
class ClosedMessage extends Model
{
    use HasFactory, HasRegistrationTable, TranslatesFields;

    /** A scheduled open date has not arrived yet. */
    public const BEFORE_OPEN = 'before_open';

    /** The open date has arrived but the email guard holds registration closed. */
    public const OPENING_SOON = 'opening_soon';

    /** The close date has passed: registration for this conference has ended. */
    public const AFTER_CLOSE = 'after_close';

    /** No window is scheduled at all. */
    public const CLOSED = 'closed';

    public const KEYS = [self::BEFORE_OPEN, self::OPENING_SOON, self::AFTER_CLOSE, self::CLOSED];

    /** @var array<string, string> the default (English) body per key */
    public const DEFAULTS = [
        self::BEFORE_OPEN => 'Registration will open {opens_date} at {opens_time} ({opens_timezone}).',
        self::OPENING_SOON => 'Registration will open soon. Please check back.',
        self::AFTER_CLOSE => 'Registration for {closed_conference_name} {closed_conference_year} has ended. Please visit the conference site at {conference_site_url}, or watch the mailing list for the announcement of next year\'s conference.',
        self::CLOSED => 'Registration is currently closed.',
    ];

    protected $fillable = ['key', 'body'];

    /** The fields admins may translate. */
    public function translatableFields(): array
    {
        return ['body'];
    }

    /** The message for a key, created with its default text on first access. */
    public static function forKey(string $key): self
    {
        return self::firstOrCreate(['key' => $key], ['body' => self::DEFAULTS[$key]]);
    }
}
