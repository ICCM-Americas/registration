<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks a question as admin-protected (currently just the two seeded system
 * questions — see SystemQuestionsSeeder — but generic, not "is guest/group
 * question", in case a future protected question is added). The seeder
 * itself runs later, from 2026_07_23_000400_add_is_system_to_sections_table
 * — it also creates a protected Section, so it needs that column to exist
 * too before it can run.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table($this->table(), function (Blueprint $table) {
            $table->boolean('is_system')->default(false)->after('enabled');
        });
    }

    public function down(): void
    {
        // Any surviving system questions are removed by
        // 2026_07_23_000400_add_is_system_to_sections_table's down() first
        // (deleting their section cascades to them) — this just drops the
        // column.
        Schema::table($this->table(), function (Blueprint $table) {
            $table->dropColumn('is_system');
        });
    }

    private function table(): string
    {
        return config('registration.tables.prefix').'questions';
    }
};
