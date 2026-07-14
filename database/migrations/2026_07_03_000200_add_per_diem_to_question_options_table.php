<?php

use ConferenceTools\Registration\Enums\PerDiemScope;
use ConferenceTools\Registration\Services\PerDiem;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-diem attributes on a choice option. An option may contribute a number of
 * per-diem days (room & board) to the registrant's stay — e.g. "Set-up team"
 * adds two early-arrival days — priced at a rate that lives once in the pricing
 * settings rather than being baked into each option's fixed "cost". "scope"
 * records whether those days are billed for the attendee only or also for an
 * accompanying non-attending adult. See {@see PerDiem}.
 *
 * A zero "per_diem_days" (the default) is an option that adds no per-diem, so
 * existing options are unchanged and no per-diem is charged until admins set it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table($this->table(), function (Blueprint $table) {
            $table->unsignedInteger('per_diem_days')->default(0)->after('cost');
            $table->string('per_diem_scope')->default(PerDiemScope::Attendee->value)->after('per_diem_days');
        });
    }

    public function down(): void
    {
        Schema::table($this->table(), function (Blueprint $table) {
            $table->dropColumn(['per_diem_days', 'per_diem_scope']);
        });
    }

    private function table(): string
    {
        return config('registration.tables.prefix').'question_options';
    }
};
