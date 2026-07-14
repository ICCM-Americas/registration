<?php

use ConferenceTools\Registration\Enums\QuestionScope;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wizard sections: each is one step (a single view) of the registration form.
 * Sections belong to a scope (participant vs group) so the two existing forms —
 * the registrant profile and the group/billing details — are described by the
 * same configurable infrastructure. Questions are ordered within a section and
 * may be dragged between sections (see the questions table's position column).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->enum('scope', QuestionScope::values())->default(QuestionScope::Participant->value);
            $table->string('key');
            $table->string('title');
            $table->text('description')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['scope', 'key']);
            $table->index(['scope', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return config('registration.tables.prefix').'sections';
    }
};
