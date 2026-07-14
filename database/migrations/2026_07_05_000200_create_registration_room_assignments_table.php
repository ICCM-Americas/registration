<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which room each registrant sleeps in (see RoomAssignment): one row per
 * housed registrant. Seeded by the first-pass assigner and then corrected by
 * an admin, so rows come and go freely. No foreign key into the host users
 * table (package convention — the provider's user-deletion handling cleans
 * up instead), but a room's assignments go with the room.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table('room_assignments'), function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->timestamps();
            $table->foreignId('room_id')->constrained($this->table('rooms'))->cascadeOnDelete();
            $table->unsignedBigInteger('user_id')->unique();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('room_assignments'));
    }

    private function table(string $base): string
    {
        return config('registration.tables.prefix').$base;
    }
};
