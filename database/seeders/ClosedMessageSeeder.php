<?php

namespace ConferenceTools\Registration\Database\Seeders;

use ConferenceTools\Registration\Models\ClosedMessage;
use Illuminate\Database\Seeder;

/**
 * Inserts the default "registration is closed" page messages (one per window
 * state — see ClosedMessage::KEYS). Like the landing-page steps, the defaults
 * are plain English literals held on the model — NOT lang-file entries: the
 * messages are admin content, translated per locale on the admin translations
 * screen (see TranslatesFields).
 *
 * Idempotent and non-destructive: an existing message (including one an admin
 * has edited) is left untouched.
 */
class ClosedMessageSeeder extends Seeder
{
    public function run(): void
    {
        foreach (ClosedMessage::KEYS as $key) {
            ClosedMessage::forKey($key);
        }
    }
}
