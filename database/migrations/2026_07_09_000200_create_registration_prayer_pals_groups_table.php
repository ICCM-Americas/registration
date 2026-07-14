<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A small prayer-pals grouping (see PrayerPalsAssignment): always single-sex,
 * with a "position" that is the sole source of its displayed number/letter
 * (see PrayerPalsGroup::label()) — an admin creates and deletes groups and
 * places people into them, but never picks the label itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->timestamps();
            $table->string('sex', 1);
            $table->unsignedInteger('position');
            $table->unique(['sex', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return config('registration.tables.prefix').'prayer_pals_groups';
    }
};
