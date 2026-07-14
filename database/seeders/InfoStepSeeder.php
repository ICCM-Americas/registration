<?php

namespace ConferenceTools\Registration\Database\Seeders;

use ConferenceTools\Registration\Models\InfoStep;
use Illuminate\Database\Seeder;

/**
 * Inserts the default landing-page steps (log in, fill in the form, payment).
 * Only English exists out of the box, so the defaults are plain English
 * literals here — NOT lang-file entries: step texts are admin content, and
 * admins provide other languages on the admin translations screen, from where
 * they are stored per locale in the database (see TranslatesFields).
 *
 * Idempotent and non-destructive: steps are keyed by position, and an existing
 * step (including one an admin has edited) is left untouched.
 */
class InfoStepSeeder extends Seeder
{
    /** @var array<int, array{heading: string, body: string}> */
    public const DEFAULT_STEPS = [
        1 => [
            'heading' => 'Log in or create an account',
            'body' => 'Registering for the conference requires an account. If you already have one, please log in; otherwise create a new account first.',
        ],
        2 => [
            'heading' => 'Fill in the registration form',
            'body' => 'The registration form will ask you some details about yourself and your organization, one section at a time.',
        ],
        3 => [
            'heading' => 'Payment',
            'body' => 'After completing the form, you will need to complete your payment.',
        ],
    ];

    public function run(): void
    {
        foreach (self::DEFAULT_STEPS as $position => $texts) {
            InfoStep::firstOrCreate(['position' => $position], $texts + ['enabled' => true]);
        }
    }
}
