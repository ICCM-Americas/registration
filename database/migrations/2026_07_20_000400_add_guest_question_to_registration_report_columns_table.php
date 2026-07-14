<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A question column's optional second source: the question its cell reads
 * from on a guest row instead of its own question_id, e.g. a registrant's
 * photo-consent question for attendee rows against the guest's own
 * photo-consent question for their guest rows — one column, two sources.
 * Nullable and null-on-delete, unlike question_id's cascade: it's an
 * override, not the column's identity, so deleting the nominated guest
 * question just drops back to question_id rather than the whole column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table($this->table(), function (Blueprint $table) {
            $table->unsignedBigInteger('guest_question_id')->nullable()->after('question_id');
            $table->foreign('guest_question_id')->references('id')->on($this->questionsTable())->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table($this->table(), function (Blueprint $table) {
            $table->dropForeign(['guest_question_id']);
            $table->dropColumn('guest_question_id');
        });
    }

    private function table(): string
    {
        return config('registration.tables.prefix').'report_columns';
    }

    private function questionsTable(): string
    {
        return config('registration.tables.prefix').'questions';
    }
};
