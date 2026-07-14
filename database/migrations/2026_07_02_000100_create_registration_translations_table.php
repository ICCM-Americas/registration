<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin-provided translations of registrant-facing texts: one row per
 * (entity, field, locale). The entity's own column holds its base-language
 * text (whatever language it was authored or imported in); rows here override
 * it per locale at render time (see the TranslatesFields trait). Lang files
 * cannot do this job — this content is written by admins, not shipped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->timestamps();
            $table->morphs('translatable');
            $table->string('field', 32);
            $table->string('locale', 12);
            $table->text('value');
            $table->unique(
                ['translatable_type', 'translatable_id', 'field', 'locale'],
                'registration_translations_entity_field_locale_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return config('registration.tables.prefix').'translations';
    }
};
