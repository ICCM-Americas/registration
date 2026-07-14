<?php

use ConferenceTools\Registration\Services\AnswerStore;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The EAV answer store: one row per answered question per owner. The owner is
 * polymorphic — the host user model for participant-scoped questions, the
 * package Group model for group-scoped ones — so the package keeps its
 * "no hard FK into the host users table" convention (owner_id is stored by
 * convention, like the other host-user references in this package).
 *
 * "value" holds the final, report-ready text: for a choice answer this is
 * already resolved (interpolated, and — when the question's translate_value
 * flag is set — translated) at write time by
 * {@see AnswerStore}, so nothing
 * downstream needs to reach back into the question's options. There is
 * deliberately no option_id: once an answer is written it stands on its own,
 * independent of later edits to (or deletion of) the option it was matched
 * against. A choice answer snapshots the matched option's pricing onto
 * "cost"/"per_diem_days"/"per_diem_scope" at write time instead; all three
 * are null for a free-text answer. Multi-value questions (checkboxes) record
 * one row per selected option.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('question_id');
            $table->string('owner_type');
            $table->unsignedBigInteger('owner_id');
            $table->text('value')->nullable();
            $table->decimal('cost', 12, 2)->nullable();
            $table->unsignedInteger('per_diem_days')->nullable();
            $table->string('per_diem_scope')->nullable();
            $table->timestamps();

            $table->foreign('question_id')->references('id')->on($this->questionsTable())->cascadeOnDelete();
            $table->index(['owner_type', 'owner_id']);
            $table->index('question_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return config('registration.tables.prefix').'answers';
    }

    private function questionsTable(): string
    {
        return config('registration.tables.prefix').'questions';
    }
};
