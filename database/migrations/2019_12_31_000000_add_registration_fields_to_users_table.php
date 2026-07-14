<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the *structural* conference-registration columns to the host application's
 * users table. The package does NOT own the users table or authentication — it
 * only extends an existing user with the few columns it needs to link a
 * registrant to their group and booking state. Each column is added only if it
 * is not already present, so this migration is safe against host tables that
 * already define some of them.
 *
 * The registrant's actual answers (last name, passport, gender, residence,
 * accommodation, products…) are NOT stored here — they live in the configurable
 * EAV answer store (registration_answers). Only group membership, the per-user
 * mail-link token, checkout state and the group-admin marker are columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table($this->table(), function (Blueprint $table) {
            if (! Schema::hasColumn($this->table(), 'mail_id')) {
                $table->string('mail_id', 16)->nullable();
            }
            if (! Schema::hasColumn($this->table(), 'checked_out')) {
                $table->boolean('checked_out')->default(false);
            }
            // "is_group_admin" marks the user who registered a group, without
            // owning the host's authorization/role system.
            if (! Schema::hasColumn($this->table(), 'is_group_admin')) {
                $table->boolean('is_group_admin')->default(false);
            }
            // group_id links the host user to a package group by convention only —
            // no hard FK into package tables, so the package stays portable across
            // the host's database/connection.
            if (! Schema::hasColumn($this->table(), 'group_id')) {
                $table->bigInteger('group_id')->unsigned()->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table($this->table(), function (Blueprint $table) {
            $columns = array_values(array_filter([
                'mail_id', 'checked_out', 'is_group_admin', 'group_id',
            ], fn ($c) => Schema::hasColumn($this->table(), $c)));

            $table->dropColumn($columns);
        });
    }

    private function table(): string
    {
        return config('registration.user_table');
    }
};
