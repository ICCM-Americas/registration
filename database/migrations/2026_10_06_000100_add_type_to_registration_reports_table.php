<?php

use ConferenceTools\Registration\Enums\ReportType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A report's type (see ReportType); every existing report is a Registrant report. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table($this->table(), function (Blueprint $table) {
            $table->string('type')->default(ReportType::Registrant->value)->after('description');
        });
    }

    public function down(): void
    {
        Schema::table($this->table(), function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }

    private function table(): string
    {
        return config('registration.tables.prefix').'reports';
    }
};
