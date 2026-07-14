<?php

use ConferenceTools\Registration\Enums\ReportColumnDisplay;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One column of an admin-defined report: either a configured question's
 * answer (question_id set) or a built-in field like the registrant's email
 * (field set) — exactly one of the two. Deleting a question silently drops
 * its columns, the same way it drops conditions testing it. "display" picks
 * the stored answer value or the matching option's label; "header" overrides
 * the default column heading. A column may carry its own per-row visibility
 * rule tree (see registration_condition_groups): a failing rule blanks the
 * cell for that row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('report_id');
            $table->unsignedBigInteger('question_id')->nullable();
            $table->string('field')->nullable();
            $table->enum('display', ReportColumnDisplay::values())->default(ReportColumnDisplay::Value->value);
            $table->string('header')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->foreign('report_id')->references('id')->on($this->reportsTable())->cascadeOnDelete();
            $table->foreign('question_id')->references('id')->on($this->questionsTable())->cascadeOnDelete();
            $table->index(['report_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return config('registration.tables.prefix').'report_columns';
    }

    private function reportsTable(): string
    {
        return config('registration.tables.prefix').'reports';
    }

    private function questionsTable(): string
    {
        return config('registration.tables.prefix').'questions';
    }
};
