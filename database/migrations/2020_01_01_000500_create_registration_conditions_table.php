<?php

use ConferenceTools\Registration\Enums\ConditionOperator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A leaf comparison in a visibility rule: "the answer to question X <operator>
 * value". Conditions hang off a condition group, which decides how its
 * conditions (and any nested groups) combine. question_id is the question whose
 * answer is being tested — the controlling question — not the question being
 * shown or hidden.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('condition_group_id');
            $table->unsignedBigInteger('question_id');
            $table->enum('operator', ConditionOperator::values())->default(ConditionOperator::Equals->value);
            $table->text('value')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->foreign('condition_group_id')->references('id')->on($this->groupsTable())->cascadeOnDelete();
            $table->foreign('question_id')->references('id')->on($this->questionsTable())->cascadeOnDelete();
            $table->index('condition_group_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return config('registration.tables.prefix').'conditions';
    }

    private function groupsTable(): string
    {
        return config('registration.tables.prefix').'condition_groups';
    }

    private function questionsTable(): string
    {
        return config('registration.tables.prefix').'questions';
    }
};
