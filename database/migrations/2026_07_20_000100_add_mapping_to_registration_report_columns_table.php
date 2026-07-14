<?php

use ConferenceTools\Registration\Enums\ReportColumnDisplay;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a column's own raw-answer-value -> display-text overrides (JSON,
 * {from: to}), consulted only while its display mode is "mapped" — see
 * Enums\ReportColumnDisplay::Mapped. Independent per column, so two columns
 * on the same question can show the raw value and a mapped one side by side.
 *
 * Widens "display" from a native ENUM to a plain string first, so the new
 * "mapped" case doesn't need a cross-driver ENUM alteration — the allowed
 * values are already enforced in PHP (Rule::in(ReportColumnDisplay::values())
 * in ReportController, and the ReportColumnDisplay::class Eloquent cast), the
 * same reasoning as widen_scope_column_on_sections_table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table($this->table(), function (Blueprint $table) {
            $table->string('display', 20)->default(ReportColumnDisplay::Value->value)->change();
            $table->json('mapping')->nullable()->after('display');
        });
    }

    public function down(): void
    {
        Schema::table($this->table(), function (Blueprint $table) {
            $table->dropColumn('mapping');
            $table->enum('display', ['value', 'label'])->default('value')->change();
        });
    }

    private function table(): string
    {
        return config('registration.tables.prefix').'report_columns';
    }
};
