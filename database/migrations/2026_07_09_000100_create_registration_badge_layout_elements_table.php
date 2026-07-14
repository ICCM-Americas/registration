<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin-placed content on a printable name badge (badge name, logo,
 * conference name, organization, static text/images), one set per
 * BadgeSheetSize so each card stock can be laid out independently. Position
 * and size are stored as percentages of the card's own width/height, so no
 * per-size scale math is needed when rendering. No rows are created here — a
 * size starts with an empty layout (the report falls back to its built-in
 * default rendering) until an admin uses the layout designer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->timestamps();
            $table->string('sheet_size', 32);
            $table->string('type', 32);
            $table->decimal('x_pct', 5, 2);
            $table->decimal('y_pct', 5, 2);
            $table->decimal('width_pct', 5, 2);
            $table->unsignedTinyInteger('font_size_pt')->nullable();
            $table->boolean('bold')->default(false);
            $table->boolean('italic')->default(false);
            $table->string('align', 8)->default('center');
            $table->text('text')->nullable();
            $table->longText('image_data')->nullable();
            $table->string('image_mime', 64)->nullable();
            $table->index(['sheet_size', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return config('registration.tables.prefix').'badge_layout_elements';
    }
};
