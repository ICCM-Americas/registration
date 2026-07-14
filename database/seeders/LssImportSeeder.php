<?php

namespace ConferenceTools\Registration\Database\Seeders;

use ConferenceTools\Registration\Services\LssImporter;
use Illuminate\Database\Seeder;

/**
 * Imports the LimeSurvey export named by config('registration.lss_import_path')
 * into the configurable question infrastructure via {@see LssImporter}, then
 * prints a summary of what was created and what could not be brought across.
 *
 * Point REGISTRATION_LSS_IMPORT at the .lss file and run:
 *
 *     php artisan db:seed --class="ConferenceTools\\Registration\\Database\\Seeders\\LssImportSeeder"
 *
 * Idempotent: sections/questions are keyed by the survey's group/question codes,
 * so re-running updates them in place. With no path configured this is a no-op.
 */
class LssImportSeeder extends Seeder
{
    public function run(): void
    {
        $path = config('registration.lss_import_path');

        if (empty($path)) {
            $this->command?->warn('No registration.lss_import_path configured; skipping LimeSurvey import.');

            return;
        }

        if (! is_file($path)) {
            $this->command?->error("LimeSurvey import file not found: {$path}");

            return;
        }

        $report = app(LssImporter::class)->import((string) file_get_contents($path));

        $survey = $report['survey'] ?? 'survey';
        $counts = $report['counts'];
        $this->command?->info(sprintf(
            'Imported "%s": %d sections, %d questions, %d options, %d conditions, %d translations.',
            $survey,
            $counts['sections'],
            $counts['questions'],
            $counts['options'],
            $counts['conditions'],
            $counts['translations'],
        ));

        if ($report['skipped'] !== []) {
            $this->command?->warn(sprintf('%d item(s) could not be imported:', count($report['skipped'])));
            foreach ($report['skipped'] as $note) {
                $this->command?->line('  - '.$note);
            }
        }
    }
}
