<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Holds the in-progress registrant's not-yet-committed non-attending guests
 * (each an id/type/answers entry) — the pre-commit analog of the
 * registration_guests table, mirroring how "answers" already holds the
 * pre-commit analog of registration_answers. See DraftGuests.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table($this->table(), function (Blueprint $table) {
            $table->json('guests')->nullable()->after('answers');
        });
    }

    public function down(): void
    {
        Schema::table($this->table(), function (Blueprint $table) {
            $table->dropColumn('guests');
        });
    }

    private function table(): string
    {
        return config('registration.tables.prefix').'drafts';
    }
};
