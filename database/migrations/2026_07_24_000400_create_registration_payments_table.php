<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A registrant's manually-recorded payment state for the admin Payments
 * console: whether they've paid, how much, and any notes about the payment.
 * One row per registrant — a group member's record is independent of their
 * leader's, and a guest's cost rolls into their attendee's own record rather
 * than being separately payable.
 *
 * "user_id" is a convention-only reference to the host users table (no hard
 * FK), matching every other host-user reference in this package (e.g.
 * guests.user_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id')->unique();
            $table->boolean('is_paid')->default(false);
            $table->decimal('amount', 10, 2)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return config('registration.tables.prefix').'payments';
    }
};
