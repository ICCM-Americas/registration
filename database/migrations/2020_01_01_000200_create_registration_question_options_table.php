<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The selectable options for choice questions (select / radio / checkbox).
 *
 * "cost" is what folds the priced options (accommodation, additional products)
 * into the question system: an option may carry a cost — expressed in the base
 * currency — so a choice question with priced options drives the registrant's
 * total. A null cost is a free option (an ordinary answer choice).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('question_id');
            $table->string('value');
            $table->string('label');
            $table->text('description')->nullable();
            $table->decimal('cost', 8, 2)->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->foreign('question_id')->references('id')->on($this->questionsTable())->cascadeOnDelete();
            $table->index(['question_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return config('registration.tables.prefix').'question_options';
    }

    private function questionsTable(): string
    {
        return config('registration.tables.prefix').'questions';
    }
};
