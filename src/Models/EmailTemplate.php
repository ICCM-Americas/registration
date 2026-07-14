<?php

namespace ConferenceTools\Registration\Models;

use ConferenceTools\Registration\Models\Concerns\HasRegistrationTable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One of the fixed registration emails, admin-edited on the "Emails" console:
 * the confirmation sent to the registrant, the notification sent to the
 * configured administrator address when a registration is committed, and the
 * invite sent to each person a group leader adds to their group. The set
 * of templates is fixed ({@see KEYS}); rows exist only once an admin saves
 * one, with {@see forKey()} supplying lang-file defaults until then. Subject
 * and body accept the same "{...}" variables as the other admin-authored
 * texts (see VariableInterpolator), resolved at send time — the invite
 * template additionally resolves {leader_name}, {leader_organization} and
 * {invite_link}, set only while that email is being composed.
 */
class EmailTemplate extends Model
{
    use HasFactory, HasRegistrationTable;

    public const ADMIN = 'admin_notification';

    public const REGISTRANT = 'registrant_confirmation';

    public const GROUP_INVITE = 'group_invite';

    public const KEYS = [self::ADMIN, self::REGISTRANT, self::GROUP_INVITE];

    protected $fillable = ['key', 'subject', 'body'];

    /** The template for a key, defaulted from the lang files until saved. */
    public static function forKey(string $key): self
    {
        return self::firstOrNew(['key' => $key], [
            'subject' => __('registration::mail.'.$key.'_subject'),
            'body' => __('registration::mail.'.$key.'_body'),
        ]);
    }
}
