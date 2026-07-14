<?php

use ConferenceTools\Registration\Enums\QuestionType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Widens questions.type from a native ENUM to a plain string, the same way
 * {@see Migration} 2026_07_10_000100 widened
 * sections.scope, so a new QuestionType case (YesNo) can be added without a
 * cross-driver ENUM alteration. The allowed values are already enforced in
 * PHP (Rule::in(QuestionType::values()) in QuestionBuilderController, and the
 * QuestionType::class Eloquent cast), so the DB-level ENUM constraint was
 * defense-in-depth, not load-bearing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table($this->table(), function (Blueprint $table) {
            $table->string('type', 20)->default(QuestionType::Text->value)->change();
        });
    }

    public function down(): void
    {
        Schema::table($this->table(), function (Blueprint $table) {
            $table->enum('type', $this->legacyValues())->default(QuestionType::Text->value)->change();
        });
    }

    /** @return array<int, string> */
    private function legacyValues(): array
    {
        return array_values(array_diff(QuestionType::values(), [QuestionType::YesNo->value]));
    }

    private function table(): string
    {
        return config('registration.tables.prefix').'questions';
    }
};
