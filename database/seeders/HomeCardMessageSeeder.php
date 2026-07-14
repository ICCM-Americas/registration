<?php

namespace ConferenceTools\Registration\Database\Seeders;

use ConferenceTools\Registration\Models\HomeCardMessage;
use Illuminate\Database\Seeder;

/**
 * Inserts the default home-dashboard registration card messages (one per
 * key — see HomeCardMessage::KEYS). Like ClosedMessageSeeder, the defaults
 * are plain English literals held on the model — NOT lang-file entries: the
 * messages are admin content, translated per locale on the admin translations
 * screen (see TranslatesFields).
 *
 * Idempotent and non-destructive: an existing message (including one an admin
 * has edited) is left untouched.
 */
class HomeCardMessageSeeder extends Seeder
{
    public function run(): void
    {
        foreach (HomeCardMessage::KEYS as $key) {
            HomeCardMessage::forKey($key);
        }
    }
}
