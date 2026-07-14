<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Saved-but-unfinished registrations. The server-side wizard writes the
 * registrant's progress here after every step, so they can leave and come back
 * later (even on another device) and resume where they left off instead of
 * starting over. The row is deleted when the registration is finally committed,
 * so the mere existence of a draft means the registration is incomplete — which
 * is how the admin dashboard tells complete from incomplete registrations.
 *
 * One draft per registrant (host user). user_id is a convention-only reference,
 * with no hard FK into the host users table (matching the rest of the package);
 * the draft is removed with the user by the package's user-deletion listener.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id')->unique();
            $table->unsignedBigInteger('current_section_id')->nullable();
            $table->json('answers')->nullable();
            $table->timestamps();

            // The step pointer follows the section; if that section is deleted in
            // the builder, drop the pointer (the wizard falls back to the first
            // step) rather than the whole draft.
            $table->foreign('current_section_id')->references('id')->on($this->sectionsTable())->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return config('registration.tables.prefix').'drafts';
    }

    private function sectionsTable(): string
    {
        return config('registration.tables.prefix').'sections';
    }
};
