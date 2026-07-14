<?php

use ConferenceTools\Registration\Enums\BooleanOperator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A node in a visibility rule tree. A condition group combines its children
 * (nested groups and/or leaf conditions) with AND or OR. The root group of a
 * tree is attached to the thing it controls via the polymorphic "conditionable"
 * (a question or a section); nested groups instead set parent_group_id and leave
 * conditionable null. Together with the conditions table this expresses full
 * nested boolean (AND/OR) visibility rules.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('conditionable_type')->nullable();
            $table->unsignedBigInteger('conditionable_id')->nullable();
            $table->unsignedBigInteger('parent_group_id')->nullable();
            $table->enum('operator', BooleanOperator::values())->default(BooleanOperator::And->value);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->foreign('parent_group_id')->references('id')->on($this->table())->cascadeOnDelete();
            // Explicit (shorter) name: the auto-generated one — table + both
            // columns + "_index" — is 71 chars and exceeds MySQL's 64-char
            // identifier limit (SQLite has no such limit, so it only bites on MySQL).
            $table->index(['conditionable_type', 'conditionable_id'], $this->table().'_conditionable_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return config('registration.tables.prefix').'condition_groups';
    }
};
