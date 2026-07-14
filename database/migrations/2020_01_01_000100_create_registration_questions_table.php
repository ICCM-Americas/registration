<?php

use ConferenceTools\Registration\Enums\QuestionType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Configurable questions. Every question belongs to a section (and therefore
 * inherits that section's scope) and carries a "key" — the stable machine name
 * used as the answer key and as the bridge to the existing typed columns while
 * the legacy questions are migrated in. "position" orders questions within their
 * section; moving a question between sections is just a change of section_id +
 * position. "config" holds type-specific options (min/max, rows, step…).
 *
 * "translate_value" is meaningful only for option-using types (Select/Radio/
 * Checkbox): when set, a chosen option's own translated "value" field (see
 * QuestionOption::translatableFields()) is resolved for the registrant's
 * current locale and interpolated to produce the stored answer, instead of
 * the raw selected value — the mechanism behind e.g. a locale-specific
 * "display name" template option.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('section_id');
            $table->string('key');
            $table->enum('type', QuestionType::values())->default(QuestionType::Text->value);
            $table->string('label', 4096);
            $table->text('help_text')->nullable();
            $table->string('placeholder')->nullable();
            $table->boolean('translate_value')->default(false);
            $table->boolean('required')->default(false);
            $table->unsignedInteger('position')->default(0);
            $table->json('config')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->foreign('section_id')->references('id')->on($this->sectionsTable())->cascadeOnDelete();
            $table->index(['section_id', 'position']);
            $table->index('key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return config('registration.tables.prefix').'questions';
    }

    private function sectionsTable(): string
    {
        return config('registration.tables.prefix').'sections';
    }
};
