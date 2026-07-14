<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Carries the individual-vs-group choice through the multi-step wizard until the
 * registration is committed (and the choice is copied onto the group). Null until
 * the registrant has made the choice on the opening screen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table($this->table(), function (Blueprint $table) {
            $table->boolean('is_group')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table($this->table(), function (Blueprint $table) {
            $table->dropColumn('is_group');
        });
    }

    private function table(): string
    {
        return config('registration.tables.prefix').'drafts';
    }
};
