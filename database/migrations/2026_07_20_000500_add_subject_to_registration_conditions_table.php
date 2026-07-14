<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A condition's controlling side may now be a built-in subject instead of
 * another question's answer — starting with a guest's own type (adult or
 * minor), which isn't a question/answer at all, just a column on the Guest
 * row. question_id becomes nullable (exactly one of it and the new subject
 * is set, enforced in PHP the same way ReportColumn's question_id/field
 * pair already is) and cascades on delete as before; subject needs no
 * foreign key since its values are fixed PHP enum cases, not admin data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table($this->table(), function (Blueprint $table) {
            $table->dropForeign(['question_id']);
        });

        Schema::table($this->table(), function (Blueprint $table) {
            $table->unsignedBigInteger('question_id')->nullable()->change();
            $table->string('subject')->nullable()->after('question_id');
        });

        Schema::table($this->table(), function (Blueprint $table) {
            $table->foreign('question_id')->references('id')->on($this->questionsTable())->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table($this->table(), function (Blueprint $table) {
            $table->dropForeign(['question_id']);
            $table->dropColumn('subject');
        });

        Schema::table($this->table(), function (Blueprint $table) {
            $table->unsignedBigInteger('question_id')->nullable(false)->change();
        });

        Schema::table($this->table(), function (Blueprint $table) {
            $table->foreign('question_id')->references('id')->on($this->questionsTable())->cascadeOnDelete();
        });
    }

    private function table(): string
    {
        return config('registration.tables.prefix').'conditions';
    }

    private function questionsTable(): string
    {
        return config('registration.tables.prefix').'questions';
    }
};
