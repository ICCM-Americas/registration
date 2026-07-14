<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The wizard's step pointer moves from "which section" to "which question":
 * a section can now yield more than one step (see RegistrationWizard/Step —
 * a trigger question like guest/group registering gets its own reactive
 * step), so a step's stable identity is its first question, not its
 * section. Null-on-delete, like the column it replaces: if that question is
 * later deleted in the builder, the pointer just resets (the wizard falls
 * back to the first step) rather than the whole draft.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table($this->table(), function (Blueprint $table) {
            $table->dropForeign(['current_section_id']);
            $table->dropColumn('current_section_id');

            $table->unsignedBigInteger('current_question_id')->nullable();
            $table->foreign('current_question_id')->references('id')->on($this->questionsTable())->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table($this->table(), function (Blueprint $table) {
            $table->dropForeign(['current_question_id']);
            $table->dropColumn('current_question_id');

            $table->unsignedBigInteger('current_section_id')->nullable();
            $table->foreign('current_section_id')->references('id')->on($this->sectionsTable())->nullOnDelete();
        });
    }

    private function table(): string
    {
        return config('registration.tables.prefix').'drafts';
    }

    private function questionsTable(): string
    {
        return config('registration.tables.prefix').'questions';
    }

    private function sectionsTable(): string
    {
        return config('registration.tables.prefix').'sections';
    }
};
