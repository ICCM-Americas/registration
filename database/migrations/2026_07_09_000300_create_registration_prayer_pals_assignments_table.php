<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which prayer-pals group each registrant belongs to: one row per grouped
 * registrant, placed and moved freely by an admin (there is no automated
 * grouping — see the Prayer Pals console). No foreign key into the host
 * users table (package convention — the provider's user-deletion handling
 * cleans up instead), but a group's assignments go with the group.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table('prayer_pals_assignments'), function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->timestamps();
            $table->foreignId('prayer_pals_group_id')->constrained($this->table('prayer_pals_groups'))->cascadeOnDelete();
            $table->unsignedBigInteger('user_id')->unique();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('prayer_pals_assignments'));
    }

    private function table(string $base): string
    {
        return config('registration.tables.prefix').$base;
    }
};
