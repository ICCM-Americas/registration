<?php

namespace ConferenceTools\Registration\Models;

use ConferenceTools\Registration\Models\Concerns\HasRegistrationTable;
use ConferenceTools\Registration\Models\Concerns\TranslatesFields;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One of the fixed texts shown on the host application's home dashboard
 * registration card while registration is not open, keyed by whether the
 * scheduled open date is still in the future ({@see BEFORE_OPEN}) or not
 * ({@see AFTER_CLOSE}). Distinct from {@see ClosedMessage}, which serves the
 * package's own "registration is closed" page — this message is shorter,
 * dashboard-card copy for the host's home page. The set is fixed ({@see KEYS});
 * admins edit the texts on the "Home Card Messages" console and translate them
 * per locale ({@see TranslatesFields}). Texts may contain "{name}" variable
 * tokens (see VariableInterpolator), including the built-ins for the scheduled
 * open date/time/timezone/countdown and the conference's name/year.
 *
 * The defaults are plain English literals — NOT lang-file entries: the
 * messages are admin content, like ClosedMessage's.
 */
class HomeCardMessage extends Model
{
    use HasFactory, HasRegistrationTable, TranslatesFields;

    /** A scheduled open date has not arrived yet. */
    public const BEFORE_OPEN = 'before_open';

    /** Registration is not open and no future open date is scheduled (closed, or the close date has passed). */
    public const AFTER_CLOSE = 'after_close';

    public const KEYS = [self::BEFORE_OPEN, self::AFTER_CLOSE];

    /** @var array<string, string> the default (English) body per key */
    public const DEFAULTS = [
        self::BEFORE_OPEN => 'Registration opens {opens_countdown} ({opens_date} at {opens_time} {opens_timezone}).',
        self::AFTER_CLOSE => 'Registration for {closed_conference_name} {closed_conference_year} has closed. We hope to see you at next year\'s conference!',
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
