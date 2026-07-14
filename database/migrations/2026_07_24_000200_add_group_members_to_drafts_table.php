<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Holds the in-progress registrant's not-yet-committed group-member invites
 * (each an id/answers entry) — the pre-commit analog of the
 * registration_group_invites table, mirroring how "guests" already holds the
 * pre-commit analog of registration_guests. See DraftGroupMembers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table($this->table(), function (Blueprint $table) {
            $table->json('group_members')->nullable()->after('guests');
        });
    }

    public function down(): void
    {
        Schema::table($this->table(), function (Blueprint $table) {
            $table->dropColumn('group_members');
        });
    }

    private function table(): string
    {
        return config('registration.tables.prefix').'drafts';
    }
};
