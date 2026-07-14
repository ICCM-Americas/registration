<?php

use ConferenceTools\Registration\Database\Seeders\SystemQuestionsSeeder;
use ConferenceTools\Registration\Models\Section;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks a section as admin-protected — currently just the dedicated section
 * SystemQuestionsSeeder creates to hold the two seeded system questions
 * (guest_registering, group_registering), kept together in a section of
 * their own rather than attached to whatever section happened to exist
 * first. Generic, not "is system questions section", in case a future
 * protected section is added.
 *
 * This is the last of today's three "is_system" migrations, so it's the one
 * that actually runs the seeder (it needs both this column and
 * questions.is_system to exist first) — load-bearing for the wizard's
 * guest/group detection, so a plain `php artisan migrate` is enough on both
 * a fresh install and an upgrade, no separate db:seed step to remember.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table($this->table(), function (Blueprint $table) {
            $table->boolean('is_system')->default(false)->after('enabled');
        });

        (new SystemQuestionsSeeder)->run();
    }

    public function down(): void
    {
        // Deleting the section cascades (model-level, see Section::booted())
        // to its questions, options and translations.
        Section::where('is_system', true)->get()->each->delete();

        Schema::table($this->table(), function (Blueprint $table) {
            $table->dropColumn('is_system');
        });
    }

    private function table(): string
    {
        return config('registration.tables.prefix').'sections';
    }
};
