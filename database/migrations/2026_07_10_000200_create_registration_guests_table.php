<?php

use ConferenceTools\Registration\Enums\GuestType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A non-attending guest (adult or minor) a registrant brings, captured once
 * their registration is committed (see RegistrationController::commit()).
 * Pre-commit, a guest lives only in the registrant's Draft "guests" JSON
 * column (see DraftGuests) — this table exists only for committed
 * registrations, exactly like registration_answers only exists for committed
 * answers while the wizard's own draft.answers JSON holds them beforehand.
 *
 * "user_id" is a convention-only reference to the host users table (no hard
 * FK, matching every other host-user reference in this package, e.g.
 * drafts.user_id), so the package stays portable across the host's
 * database/connection. A guest's own captured details (name, sex, …) are NOT
 * columns here — they live in the EAV answer store (registration_answers)
 * with this row as the polymorphic owner, using the Guest question scope.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id');
            $table->enum('type', GuestType::values());
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['user_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return config('registration.tables.prefix').'guests';
    }
};
