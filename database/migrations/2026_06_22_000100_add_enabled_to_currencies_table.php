<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "enabled" marks a currency as allowed at checkout. The admin pricing page
 * toggles it; the checkout offers only enabled currencies. Existing rows default
 * to enabled so behavior is unchanged until an admin disables one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table($this->table(), function (Blueprint $table) {
            $table->boolean('enabled')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table($this->table(), function (Blueprint $table) {
            $table->dropColumn('enabled');
        });
    }

    private function table(): string
    {
        return config('registration.tables.prefix').'currencies';
    }
};
