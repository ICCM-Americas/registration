<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The rooms available for housing registrants (see Room). A room is identified
 * by its wing, floor, and name (room number); its wing/floor combo carries a
 * designation — men only, women only, or married couples — that the room
 * assignment first pass honors.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->timestamps();
            $table->string('wing', 64);
            $table->string('floor', 64);
            $table->string('name', 64);
            $table->string('designation', 16);
            $table->unsignedTinyInteger('capacity')->default(2);
            $table->unique(['wing', 'floor', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return config('registration.tables.prefix').'rooms';
    }
};
