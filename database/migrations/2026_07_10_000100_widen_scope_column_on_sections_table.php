<?php

use ConferenceTools\Registration\Enums\QuestionScope;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Widens sections.scope from a native ENUM('participant','group') to a plain
 * string so a third case (Guest — see the following migrations) can be added
 * without a cross-driver ENUM alteration. The allowed values are already
 * enforced in PHP (Rule::in(QuestionScope::values()) in
 * QuestionBuilderController, and the QuestionScope::class Eloquent cast), so
 * the DB-level ENUM constraint was defense-in-depth, not load-bearing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table($this->table(), function (Blueprint $table) {
            $table->string('scope', 20)->default(QuestionScope::Participant->value)->change();
        });
    }

    public function down(): void
    {
        Schema::table($this->table(), function (Blueprint $table) {
            $table->enum('scope', ['participant', 'group'])->default('participant')->change();
        });
    }

    private function table(): string
    {
        return config('registration.tables.prefix').'sections';
    }
};
