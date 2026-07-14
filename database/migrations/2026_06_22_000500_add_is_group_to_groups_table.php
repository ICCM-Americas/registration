<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "is_group" records the explicit choice a registrant makes at the start of
 * registration: are they registering as an individual or on behalf of a group?
 * It drives the checkout experience when no online payment method is configured —
 * a group still gets a "finish adding participants" step, while an individual is
 * finalized without ever seeing a checkout. Guarded so it is safe to re-run.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn($this->table(), 'is_group')) {
            return;
        }

        Schema::table($this->table(), function (Blueprint $table) {
            $table->boolean('is_group')->default(false);
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn($this->table(), 'is_group')) {
            return;
        }

        Schema::table($this->table(), function (Blueprint $table) {
            $table->dropColumn('is_group');
        });
    }

    private function table(): string
    {
        return config('registration.tables.prefix').'groups';
    }
};
